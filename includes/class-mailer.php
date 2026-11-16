<?php
/**
 * Invio delle email tramite SMTP.
 *
 * - WordPress manda tutte le email con wp_mail(), che usa PHPMailer: all'evento "phpmailer_init" qui si dice a
 *   PHPMailer di usare il server SMTP impostato. Plugin di form, WooCommerce, recupero password: passa tutto da qui.
 * - Mittente: con "Usa sempre questo mittente" ogni email parte dall'indirizzo impostato (i server SMTP rifiutano o
 *   mandano in spam le email con un mittente di un altro dominio). Altrimenti si sostituisce solo il mittente
 *   predefinito di WordPress (wordpress@dominio).
 * - Errori: il messaggio di PHPMailer viene tradotto in una frase che dice cosa controllare (error_text()).
 * - Invio di riserva (facoltativo): se l'SMTP fallisce, la stessa email riparte col server del sito (funzione mail()
 *   di PHP). Può finire in spam, ma è meglio di un form che non arriva.
 * - Avvisi: l'ultimo errore viene salvato; la bacheca lo mostra e, se impostato, parte un'email all'indirizzo di
 *   riserva (al massimo una ogni 6 ore), inviata col server del sito perché l'SMTP in quel momento non funziona.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Mail_Mailer {

	const LAST_ERROR   = 'lu3g_mail_last_error';    // [ 'time' => int, 'message' => string, 'raw' => string ]
	const LAST_SUCCESS = 'lu3g_mail_last_success';  // timestamp
	const ALERT_SENT   = 'lu3g_mail_alert_sent';    // timestamp dell'ultimo avviso via email

	/** Mittente impostato da chi ha chiamato wp_mail() (es. il visitatore di un form), prima della sostituzione. */
	private static $original_from = '';

	/** true mentre si invia senza SMTP (invio di riserva, avvisi). */
	private static $bypass = false;

	/** Raccoglie il dialogo col server durante l'email di prova. */
	private static $debug = null;

	/** Si sta inviando senza SMTP (riserva o avviso)? Serve al registro. */
	public static function bypassing() {
		return self::$bypass;
	}

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 999 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ), 999 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 999 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ), 5 );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'succeeded' ), 5 );
	}

	/**
	 * Configura PHPMailer per l'SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Istanza usata da wp_mail().
	 */
	public static function configure( $phpmailer ) {
		if ( self::$bypass || ! LU3G_Mail_Settings::ready() ) {
			return;
		}
		$s = LU3G_Mail_Settings::get();

		$phpmailer->isSMTP();
		$phpmailer->Host        = $s['host'];
		$phpmailer->Port        = $s['port'];
		$phpmailer->SMTPSecure  = 'none' === $s['encryption'] ? '' : $s['encryption'];
		$phpmailer->SMTPAutoTLS = 'none' === $s['encryption'] ? (bool) $s['auto_tls'] : true;
		$phpmailer->SMTPAuth    = (bool) $s['auth'];
		$phpmailer->Timeout     = 20;
		if ( $s['auth'] ) {
			$phpmailer->Username = $s['username'];
			$phpmailer->Password = $s['password'];
		}
		if ( $s['return_path'] && is_email( $s['from_email'] ) ) {
			$phpmailer->Sender = $s['from_email'];
		}
		// Mittente sostituito (es. un form con l'email del visitatore): "Rispondi" deve arrivare al visitatore.
		if ( '' !== self::$original_from && ! $phpmailer->getReplyToAddresses() ) {
			$phpmailer->addReplyTo( self::$original_from );
		}
		self::$original_from = '';

		// Nome con cui il sito si presenta al server (EHLO): il dominio del mittente, che ha un record SPF,
		// invece del nome interno del server dell'hosting (SpamAssassin: SPF_HELO_NONE).
		if ( '' === (string) $phpmailer->Helo && is_email( $s['from_email'] ) ) {
			$phpmailer->Helo = substr( strrchr( $s['from_email'], '@' ), 1 );
		}

		self::tidy_html( $phpmailer );

		if ( null !== self::$debug ) {
			$phpmailer->SMTPDebug   = 3;
			$phpmailer->Debugoutput = function ( $line ) {
				self::$debug[] = rtrim( (string) $line );
			};
		}
	}

	/**
	 * Email HTML ben formate per i filtri antispam (1.0.2), per tutte le email del sito:
	 * - se manca la cornice <html><body> la aggiunge (SpamAssassin: HTML_MIME_NO_HTML_TAG);
	 * - se manca la versione in testo semplice la ricava dall'HTML (MIME_HTML_ONLY): il messaggio diventa
	 *   multipart/alternative, come con WP Mail SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Istanza usata da wp_mail().
	 */
	private static function tidy_html( $phpmailer ) {
		if ( 'text/html' !== $phpmailer->ContentType || '' === trim( (string) $phpmailer->Body ) ) {
			return;
		}
		if ( ! preg_match( '/<html[\s>]/i', $phpmailer->Body ) ) {
			$charset         = $phpmailer->CharSet ? $phpmailer->CharSet : 'UTF-8';
			$phpmailer->Body = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=' . esc_attr( $charset ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>'
				. $phpmailer->Body . '</body></html>';
		}
		if ( '' === trim( (string) $phpmailer->AltBody ) ) {
			$phpmailer->AltBody = self::html_to_text( $phpmailer->Body );
		}
	}

	/** Versione in testo semplice di un'email HTML: righe e paragrafi mantenuti, link come "testo (indirizzo)". */
	public static function html_to_text( $html ) {
		$t = preg_replace( '#<(head|style|script|title)[^>]*>.*?</\1>#is', '', (string) $html );
		$t = preg_replace_callback(
			'#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			function ( $m ) {
				$label = trim( wp_strip_all_tags( $m[3] ) );
				$url   = trim( $m[2] );
				return ( '' === $label || $label === $url || 0 === strpos( $url, 'mailto:' ) ) ? ( '' !== $label ? $label : $url ) : $label . ' (' . $url . ')';
			},
			$t
		);
		$t = preg_replace( '#<br\s*/?>#i', "\n", $t );
		$t = preg_replace( '#</(p|div|h[1-6]|tr|table|ul|ol|blockquote)>#i', "\n\n", $t );
		$t = preg_replace( '#<li[^>]*>#i', "\n- ", $t );
		$t = preg_replace( '#</t[dh]>#i', "  ", $t );
		$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( "/[ \t]+/", ' ', $t );
		$t = preg_replace( "/ *\n */", "\n", $t );
		$t = preg_replace( "/\n{3,}/", "\n\n", $t );
		return trim( $t );
	}

	public static function from_email( $email ) {
		if ( self::$bypass || ! LU3G_Mail_Settings::ready() ) {
			return $email;
		}
		$s = LU3G_Mail_Settings::get();
		if ( ! is_email( $s['from_email'] ) ) {
			return $email;
		}
		// Senza "sempre": solo al posto del mittente predefinito di WordPress (wordpress@...).
		if ( $s['force_from'] || 0 === strpos( (string) $email, 'wordpress@' ) ) {
			self::$original_from = ( is_email( $email ) && strtolower( $email ) !== strtolower( $s['from_email'] ) && 0 !== strpos( (string) $email, 'wordpress@' ) ) ? $email : '';
			return $s['from_email'];
		}
		return $email;
	}

	public static function from_name( $name ) {
		if ( self::$bypass || ! LU3G_Mail_Settings::ready() ) {
			return $name;
		}
		$s = LU3G_Mail_Settings::get();
		if ( '' === $s['from_name'] ) {
			return $name;
		}
		if ( $s['force_from_name'] || 'WordPress' === $name ) {
			return $s['from_name'];
		}
		return $name;
	}

	/* ------------------------------------------------------------------ esito degli invii */

	public static function succeeded( $mail_data ) {
		if ( ! self::$bypass ) {
			update_option( self::LAST_SUCCESS, microtime( true ), false ); // millisecondi: errore e successo possono cadere nello stesso secondo
		}
	}

	/**
	 * Invio fallito: salva l'errore, manda l'avviso e, se attivo, riprova col server del sito.
	 *
	 * @param WP_Error $error Errore di wp_mail() (dati: to, subject, message, headers, attachments).
	 */
	public static function failed( $error ) {
		if ( self::$bypass || ! LU3G_Mail_Settings::ready() || ! $error instanceof WP_Error ) {
			return;
		}
		$raw = $error->get_error_message();
		update_option(
			self::LAST_ERROR,
			array(
				'time'    => microtime( true ),
				'message' => self::error_text( $raw ),
				'raw'     => $raw,
			),
			false
		);

		$s    = LU3G_Mail_Settings::get();
		$data = (array) $error->get_error_data();

		if ( $s['fallback'] && ! empty( $data['to'] ) ) {
			$row  = LU3G_Mail_Log::last_id(); // la riga dell'invio fallito, prima che l'invio di riserva ne scriva un'altra
			$sent = self::send_without_smtp( $data['to'], $data['subject'] ?? '', $data['message'] ?? '', $data['headers'] ?? array(), $data['attachments'] ?? array() );
			LU3G_Mail_Log::note_fallback( $row, $sent );
		}

		self::maybe_alert( $s, self::error_text( $raw ) );
	}

	/** Invia con il server del sito (mail() di PHP), senza SMTP. */
	public static function send_without_smtp( $to, $subject, $message, $headers = array(), $attachments = array() ) {
		self::$bypass = true;
		$ok           = wp_mail( $to, $subject, $message, $headers, $attachments );
		self::$bypass = false;
		return $ok;
	}

	private static function maybe_alert( $s, $message ) {
		$to = is_email( $s['alert_email'] ) ? $s['alert_email'] : '';
		if ( '' === $to || time() - (int) get_option( self::ALERT_SENT, 0 ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::ALERT_SENT, time(), false );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = "Le email di {$site} (" . home_url() . ") non partono dal server SMTP.\n\n"
			. "Motivo: {$message}\n\n"
			. 'Controlla le impostazioni in ' . admin_url( 'admin.php?page=lu3g-mail' ) . "\n\n"
			. "Questo avviso parte al massimo una volta ogni 6 ore.\n— LU3G Mail";
		self::send_without_smtp( $to, "[{$site}] Le email del sito non partono", $body );
	}

	/** Errore recente da mostrare: più nuovo dell'ultimo invio riuscito e delle ultime 48 ore. */
	public static function current_error() {
		$err = get_option( self::LAST_ERROR );
		if ( ! is_array( $err ) || empty( $err['time'] ) ) {
			return null;
		}
		if ( (float) $err['time'] < (float) get_option( self::LAST_SUCCESS, 0 ) || time() - (int) $err['time'] > 2 * DAY_IN_SECONDS ) {
			return null;
		}
		return $err;
	}

	/* ------------------------------------------------------------------ email di prova */

	/**
	 * Manda un'email di prova con le impostazioni salvate e restituisce esito, messaggio chiaro e dialogo col server.
	 *
	 * @return array{ok:bool,message:string,raw:string,log:string[]}
	 */
	public static function test( $to ) {
		self::$debug = array();
		$failed      = '';
		$catch       = function ( $error ) use ( &$failed ) {
			$failed = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch, 1 );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$s    = LU3G_Mail_Settings::get();
		$body = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:28px;border-radius:14px;background:#09090b;color:#fafafa">'
			. '<p style="margin:0 0 6px;color:#a1a1aa;font-size:12px;letter-spacing:.1em;text-transform:uppercase">LU3G Mail</p>'
			. '<h1 style="margin:0 0 12px;font-size:22px;color:#fff">Funziona!</h1>'
			. '<p style="margin:0 0 16px;color:#d4d4d8;line-height:1.5">Questa è l\'email di prova di <strong style="color:#fff">' . esc_html( $site ) . '</strong>. Le email del sito partono dal server SMTP.</p>'
			. '<p style="margin:0;color:#71717a;font-size:12px">Server ' . esc_html( $s['host'] ) . ', porta ' . (int) $s['port'] . ' · ' . esc_html( strtoupper( $s['encryption'] ) ) . ' · ' . esc_html( wp_date( 'd/m/Y H:i' ) ) . '</p>'
			. '</div>';

		$ok = wp_mail( $to, '[' . $site . '] Email di prova di LU3G Mail', $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_action( 'wp_mail_failed', $catch, 1 );
		$log         = self::$debug;
		self::$debug = null;

		// Mai la password nel dialogo mostrato: AUTH PLAIN/LOGIN la manda in base64.
		$secret = $s['password'];
		$log    = array_map(
			function ( $line ) use ( $secret ) {
				$line = preg_replace( '/(AUTH (PLAIN|LOGIN)\s+)\S+/i', '$1••••', $line );
				if ( '' !== $secret ) {
					$line = str_replace( array( $secret, base64_encode( $secret ) ), '••••', $line ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
				return preg_replace( '/^(\S+\s+CLIENT -> SERVER:\s+)[A-Za-z0-9+\/=]{12,}$/', '$1••••', $line );
			},
			$log
		);

		return array(
			'ok'      => (bool) $ok,
			// Nella prova c'è anche il dialogo col server: dice il motivo vero (porta chiusa, nome inesistente...).
			'message' => $ok ? 'Email di prova inviata a ' . $to . '. Controlla la posta (anche lo spam).' : self::error_text( implode( "\n", $log ) . "\n" . $failed ),
			'raw'     => $failed,
			'log'     => $log,
		);
	}

	/* ------------------------------------------------------------------ errori comprensibili */

	/**
	 * Traduce gli errori di PHPMailer/SMTP in una frase che dice cosa controllare.
	 */
	public static function error_text( $raw ) {
		$r = strtolower( (string) $raw );
		$map = array(
			'could not authenticate'          => 'Utente o password non accettati dal server. Controlla l\'indirizzo completo e la password (per Gmail serve una "password per le app").',
			'authentication failed'           => 'Utente o password non accettati dal server. Controlla l\'indirizzo completo e la password.',
			'535'                             => 'Utente o password non accettati dal server. Controlla l\'indirizzo completo e la password.',
			'smtpclientauthentication is disabled' => 'Microsoft 365 ha l\'invio SMTP disattivato per questa casella: va attivato dall\'amministratore di Microsoft 365, oppure usa un altro servizio.',
			'basic authentication is disabled' => 'Microsoft non accetta più l\'accesso con sola password per questa casella. Usa un servizio come Brevo con il tuo dominio.',
			'certificate'                     => 'Il certificato di sicurezza del server non è valido per questo nome. Controlla di aver scritto il server esatto indicato dal fornitore.',
			'ssl'                             => 'Problema nella connessione cifrata. Prova la combinazione giusta: porta 465 con SSL oppure porta 587 con TLS.',
			'connection refused'              => 'Il server ha rifiutato la connessione su questa porta. Controlla porta e cifratura (465 SSL o 587 TLS).',
			'connection timed out'            => 'Il server non risponde: l\'hosting del sito potrebbe bloccare le connessioni verso l\'esterno su questa porta. Prova la 587 o la 465, oppure chiedi all\'hosting di sbloccarla.',
			'timed out'                       => 'Il server non risponde: l\'hosting del sito potrebbe bloccare le connessioni verso l\'esterno su questa porta. Prova la 587 o la 465, oppure chiedi all\'hosting di sbloccarla.',
			'getaddrinfo'                     => 'Il nome del server non esiste. Controlla di averlo scritto giusto.',
			'name or service not known'       => 'Il nome del server non esiste. Controlla di averlo scritto giusto.',
			'php_network_getaddresses'        => 'Il nome del server non esiste. Controlla di averlo scritto giusto.',
			'smtp connect() failed'           => 'Impossibile collegarsi al server SMTP. Controlla server, porta e cifratura; se sono giusti, l\'hosting potrebbe bloccare la porta.',
			'could not connect'               => 'Impossibile collegarsi al server SMTP. Controlla server, porta e cifratura; se sono giusti, l\'hosting potrebbe bloccare la porta.',
			'from address failed'             => 'Il server non accetta questo mittente. Usa come mittente lo stesso indirizzo con cui accedi (o un indirizzo verificato nel servizio).',
			'sender address rejected'         => 'Il server non accetta questo mittente. Usa come mittente lo stesso indirizzo con cui accedi (o un indirizzo verificato nel servizio).',
			'recipient'                       => 'Il server ha rifiutato il destinatario. Controlla l\'indirizzo a cui stai scrivendo.',
			'invalid address'                 => 'Uno degli indirizzi (mittente o destinatario) non è valido.',
			'data not accepted'               => 'Il server ha rifiutato il messaggio (contenuto, dimensione o limite di invii raggiunto). Riprova più tardi o controlla i limiti del servizio.',
			'quota'                           => 'Hai raggiunto il limite di invii del servizio. Riprova più tardi o aumenta il piano.',
			'too many'                        => 'Troppi invii o tentativi in poco tempo: il server ti ha bloccato temporaneamente. Riprova tra qualche minuto.',
		);
		foreach ( $map as $needle => $text ) {
			if ( false !== strpos( $r, $needle ) ) {
				return $text;
			}
		}
		return '' !== trim( (string) $raw ) ? 'Invio non riuscito: ' . wp_strip_all_tags( (string) $raw ) : 'Invio non riuscito.';
	}
}
