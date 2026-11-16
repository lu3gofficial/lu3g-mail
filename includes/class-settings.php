<?php
/**
 * Impostazioni di LU3G Mail.
 *
 * - Sono salvate nell'opzione "lu3g_mail_settings" (una per sito, anche su un multisite).
 * - La password non è mai salvata in chiaro: è cifrata con AES-256 e una chiave ricavata dalle chiavi segrete di
 *   wp-config.php (AUTH_KEY e AUTH_SALT). Chi legge il database senza wp-config.php non la può usare.
 *   Se si cambiano le chiavi segrete di WordPress, la password va reinserita.
 * - Ogni campo della connessione si può scrivere invece in wp-config.php, e allora il pannello lo mostra bloccato:
 *     define( 'LU3G_MAIL_HOST', 'smtps.aruba.it' );
 *     define( 'LU3G_MAIL_PORT', 465 );
 *     define( 'LU3G_MAIL_ENCRYPTION', 'ssl' );      // ssl, tls oppure none
 *     define( 'LU3G_MAIL_USER', 'info@cliente.it' );
 *     define( 'LU3G_MAIL_PASS', '...' );
 *     define( 'LU3G_MAIL_FROM', 'info@cliente.it' );
 *     define( 'LU3G_MAIL_FROM_NAME', 'Cliente Srl' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Mail_Settings {

	const OPTION = 'lu3g_mail_settings';

	/** Campo => costante di wp-config.php che lo sostituisce. */
	const CONSTANTS = array(
		'host'       => 'LU3G_MAIL_HOST',
		'port'       => 'LU3G_MAIL_PORT',
		'encryption' => 'LU3G_MAIL_ENCRYPTION',
		'username'   => 'LU3G_MAIL_USER',
		'password'   => 'LU3G_MAIL_PASS',
		'from_email' => 'LU3G_MAIL_FROM',
		'from_name'  => 'LU3G_MAIL_FROM_NAME',
	);

	private static $cache = null;

	public static function defaults() {
		return array(
			'provider'        => 'custom',
			'host'            => '',
			'port'            => 587,
			'encryption'      => 'tls',          // ssl (465), tls (587, STARTTLS), none
			'auto_tls'        => true,           // con "none": usa comunque STARTTLS se il server lo offre
			'auth'            => true,
			'username'        => '',
			'password'        => '',             // cifrata
			'from_email'      => '',
			'from_name'       => '',
			'force_from'      => true,           // stesso mittente per tutte le email del sito
			'force_from_name' => false,
			'return_path'     => true,           // le email non consegnate tornano al mittente
			'log_enabled'     => true,
			'log_body'        => false,          // di serie solo destinatario, oggetto ed esito
			'log_days'        => 30,
			'alert_email'     => '',             // indirizzo di riserva per gli avvisi
			'fallback'        => false,          // se l'SMTP fallisce, riprova col server del sito
		);
	}

	/** Impostazioni salvate, con i predefiniti e la password già in chiaro (solo in memoria). */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		$s['password'] = self::decrypt( (string) $s['password'] );

		foreach ( self::CONSTANTS as $field => $const ) {
			if ( defined( $const ) ) {
				$s[ $field ] = constant( $const );
			}
		}
		$s['port'] = (int) $s['port'];
		if ( ! in_array( $s['encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
			$s['encryption'] = 'tls';
		}
		self::$cache = $s;
		return $s;
	}

	public static function locked( $field ) {
		return isset( self::CONSTANTS[ $field ] ) && defined( self::CONSTANTS[ $field ] );
	}

	/**
	 * Pronto per inviare: basta un server. Non c'è un interruttore: con il plugin attivo tutte le email passano
	 * dall'SMTP appena il server è inserito (prima, WordPress invia come sempre, così un sito appena installato non
	 * resta senza email). Per tornare all'invio di WordPress si disattiva il plugin.
	 */
	public static function ready() {
		$s = self::get();
		return '' !== trim( (string) $s['host'] );
	}

	/**
	 * Salva le impostazioni arrivate dal pannello. Password vuota = resta quella salvata.
	 *
	 * @param array $input Valori grezzi.
	 * @return array Impostazioni salvate.
	 */
	public static function save( array $input ) {
		$old = get_option( self::OPTION, array() );
		$old = wp_parse_args( is_array( $old ) ? $old : array(), self::defaults() );
		$new = $old;

		$bool = array( 'auto_tls', 'auth', 'force_from', 'force_from_name', 'return_path', 'log_enabled', 'log_body', 'fallback' );
		foreach ( $bool as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$new[ $key ] = filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		if ( isset( $input['provider'] ) ) {
			$new['provider'] = sanitize_key( $input['provider'] );
		}
		if ( isset( $input['host'] ) ) {
			$new['host'] = preg_replace( '/[^a-z0-9.\-:\[\]]/i', '', trim( (string) $input['host'] ) );
		}
		if ( isset( $input['port'] ) ) {
			$port        = absint( $input['port'] );
			$new['port'] = ( $port > 0 && $port < 65536 ) ? $port : 587;
		}
		if ( isset( $input['encryption'] ) && in_array( $input['encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
			$new['encryption'] = $input['encryption'];
		}
		if ( isset( $input['username'] ) ) {
			$new['username'] = trim( sanitize_text_field( $input['username'] ) );
		}
		if ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
			$new['password'] = self::encrypt( (string) $input['password'] );
		}
		if ( ! empty( $input['clear_password'] ) ) {
			$new['password'] = '';
		}
		if ( isset( $input['from_email'] ) ) {
			$new['from_email'] = sanitize_email( $input['from_email'] );
		}
		if ( isset( $input['from_name'] ) ) {
			$new['from_name'] = sanitize_text_field( $input['from_name'] );
		}
		if ( isset( $input['log_days'] ) ) {
			$new['log_days'] = max( 1, min( 365, absint( $input['log_days'] ) ) );
		}
		if ( isset( $input['alert_email'] ) ) {
			$new['alert_email'] = sanitize_email( $input['alert_email'] );
		}

		// La password salvata deve restare cifrata anche se arrivava già dal vecchio valore.
		update_option( self::OPTION, $new, false );
		self::$cache = null;
		return self::get();
	}

	/* ------------------------------------------------------------------ cifratura */

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) . 'lu3g-mail';
		return hash( 'sha256', $material, true );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- solo se manca OpenSSL
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-ctr', self::key(), OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );
		return 'lu3g1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( $stored ) {
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $stored, 'lu3g1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, self::key(), true ), $mac ) ) {
			return ''; // chiavi segrete cambiate: la password va reinserita
		}
		return (string) openssl_decrypt( $cipher, 'aes-256-ctr', self::key(), OPENSSL_RAW_DATA, $iv );
	}

	/** C'è una password salvata (o in wp-config.php)? */
	public static function has_password() {
		return '' !== self::get()['password'];
	}

	/** Password salvata ma non più leggibile (chiavi segrete di wp-config.php cambiate). */
	public static function password_unreadable() {
		$saved = get_option( self::OPTION, array() );
		return ! self::locked( 'password' ) && ! empty( $saved['password'] ) && '' === self::decrypt( (string) $saved['password'] );
	}

	/* ------------------------------------------------------------------ servizi già pronti */

	/**
	 * Impostazioni note dei servizi più usati. "user" è un suggerimento per il campo utente.
	 */
	/**
	 * Icona e colore di ogni fornitore, disegnati nel riquadro della scheda (bianco sul colore del marchio).
	 * - 'fill': loghi ufficiali da Simple Icons (https://simpleicons.org, licenza CC0) – Gmail, Brevo, Mailgun, IONOS;
	 *   i marchi appartengono ai rispettivi proprietari e servono solo a riconoscere il servizio.
	 * - 'line': icone generiche disegnate per LU3G Mail dove Simple Icons non ha il logo (Microsoft, Amazon e SendGrid
	 *   lo hanno fatto togliere; Aruba e Register.it non ci sono): server, chiocciola, busta, mondo, aeroplanino, nuvola.
	 * Formato: chiave => array( colore, tipo, tracciato SVG in un riquadro 24×24 ).
	 */
	public static function icons() {
		return array(
			'custom' => array( '#18181b', 'line', 'M4 3h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM4 13h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2zM6 7h.01M6 17h.01' ),
			'hosting' => array( '#52525b', 'line', 'M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.5 7.1' ),
			'aruba' => array( '#f39200', 'line', 'M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM22 7l-10 6L2 7' ),
			'register' => array( '#0057b8', 'line', 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20' ),
			'gmail' => array( '#ea4335', 'fill', 'M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.273H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.91 1.528-1.145C21.69 2.28 24 3.434 24 5.457z' ),
			'office365' => array( '#0078d4', 'line', 'M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM22 7l-10 6L2 7' ),
			'brevo' => array( '#0b996e', 'fill', 'M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zM7.2 4.8h5.747c2.34 0 3.895 1.406 3.895 3.516 0 1.022-.348 1.862-1.09 2.588C17.189 11.812 18 13.22 18 14.785c0 2.86-2.64 5.016-6.164 5.016H7.199v-15zm2.085 1.952v5.537h.07c.233-.432.858-.796 2.249-1.226 2.039-.659 3.037-1.52 3.037-2.655 0-.998-.766-1.656-1.924-1.656H9.285zm4.87 5.266c-.766.385-1.67.748-2.76 1.11-1.229.387-2.11 1.386-2.11 2.407v2.315h2.365c2.387 0 4.149-1.34 4.149-3.155 0-1.067-.625-2.087-1.645-2.677z' ),
			'sendgrid' => array( '#1a82e2', 'line', 'M22 2 11 13M22 2l-7 20-4-9-9-4z' ),
			'mailgun' => array( '#f06b66', 'fill', 'M11.837 0c6.602 0 11.984 5.381 11.984 11.994-.017 2.99-3.264 4.84-5.844 3.331a3.805 3.805 0 0 1-.06-.035l-.055-.033-.022.055c-2.554 4.63-9.162 4.758-11.894.232-2.732-4.527.46-10.313 5.746-10.416a6.868 6.868 0 0 1 7.002 6.866 1.265 1.265 0 0 0 2.52 0c0-5.18-4.197-9.38-9.377-9.387C4.611 2.594.081 10.41 3.683 16.673c3.238 5.632 11.08 6.351 15.289 1.402l1.997 1.686A11.95 11.95 0 0 1 11.837 24C2.6 23.72-2.87 13.543 1.992 5.684A12.006 12.006 0 0 1 11.837 0Zm0 7.745c-3.276-.163-5.5 3.281-4.003 6.2a4.26 4.26 0 0 0 4.014 2.31c3.276-.171 5.137-3.824 3.35-6.575a4.26 4.26 0 0 0-3.36-1.935Zm0 2.53c1.324 0 2.152 1.433 1.49 2.58a1.72 1.72 0 0 1-1.49.86 1.72 1.72 0 1 1 0-3.44Z' ),
			'ses' => array( '#ff9900', 'line', 'M17.5 19H7a5 5 0 1 1 .9-9.92A6 6 0 0 1 19 11a4 4 0 0 1-1.5 8z' ),
			'ionos' => array( '#003d8f', 'fill', 'M22.182 15.499a1.7634 1.7634 0 0 1-.3536-.0358 1.8319 1.8319 0 0 1-1.2086-.8632l-.0066-.012c-.1308-.2616-.0448-.5693.2-.7162a.5236.5236 0 0 1 .2689-.0743.5327.5327 0 0 1 .4632.272.558.558 0 0 0 .018.0299l.0564.0766.0757.0765a.7385.7385 0 0 0 .3453.167.7596.7596 0 0 0 .5954-.136.7206.7206 0 0 0 .299-.5386.7441.7441 0 0 0-.207-.578s-1.5994-1.6612-1.6712-1.7382a1.9604 1.9604 0 0 1-.1929-.229c-.2256-.3198-.2962-.697-.2614-1.0702a1.7361 1.7361 0 0 1 .2253-.7397s.0732-.124.0948-.1554c.0847-.1236.2515-.2834.3658-.3726a1.8813 1.8813 0 0 1 .3069-.1844 1.7494 1.7494 0 0 1 2.2815.7041.5186.5186 0 0 1 .0553.402.5298.5298 0 0 1-.2528.3263.5082.5082 0 0 1-.2593.0705.5283.5283 0 0 1-.46-.2706.6953.6953 0 0 0-.4656-.329.721.721 0 0 0-.5645.1269.688.688 0 0 0-.267.498.6998.6998 0 0 0 .1905.5398l1.6351 1.6862a1.8284 1.8284 0 0 1 .5071 1.3931c-.0465.5243-.3071.9994-.7152 1.3053a1.8425 1.8425 0 0 1-1.0984.369m-5.175-.0006a1.7608 1.7608 0 0 0 1.7585-1.7589v-3.4787a1.7587 1.7587 0 0 0-3.5173 0v3.4786a1.7608 1.7608 0 0 0 1.7587 1.7589m0-5.9342c.3777 0 .6968.319.6968.6967v3.4786a.6977.6977 0 0 1-.6968.6968.6894.6894 0 0 1-.6968-.6968v-3.4786a.6976.6976 0 0 1 .697-.6967m-7.8986.3224v5.0804a.5309.5309 0 0 0 1.0618 0V12.09Zm2.8802 2.0255V9.0328a.531.531 0 0 1 1.0619 0v5.0828zm1.053 2.9406v.1134a.533.533 0 0 1-1.0067.2408L9.1153 9.154v-.1212a.5261.5261 0 0 1 .3028-.4772.5253.5253 0 0 1 .7088.2475Zm-7.9067.6457a1.761 1.761 0 0 0 1.7586-1.7589v-3.4786a1.7587 1.7587 0 0 0-3.5173 0v3.4786a1.7609 1.7609 0 0 0 1.7587 1.7589m0-5.9342a.707.707 0 0 1 .6968.6967v3.4786a.6968.6968 0 1 1-1.3936 0v-3.4786a.6976.6976 0 0 1 .6968-.6967M0 14.9111a.5791.5791 0 1 0 1.1581 0V9.0803a.5791.5791 0 0 0-1.1582 0z' ),
		);
	}

	public static function providers() {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = preg_replace( '/^www\./', '', (string) $domain );

		return array(
			'custom'     => array(
				'name' => 'Altro server',
				'host' => '',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Inserisci i dati che ti ha dato il fornitore della posta.',
			),
			'hosting'    => array(
				'name' => 'Posta dell\'hosting',
				'host' => 'mail.' . $domain,
				'port' => 465,
				'enc'  => 'ssl',
				'note' => 'La casella creata nel pannello dell\'hosting (Plesk, cPanel). Di solito il server è mail.dominio; utente = indirizzo completo.',
			),
			'aruba'      => array(
				'name' => 'Aruba',
				'host' => 'smtps.aruba.it',
				'port' => 465,
				'enc'  => 'ssl',
				'note' => 'Utente = indirizzo email completo, password della casella.',
			),
			'register'   => array(
				'name' => 'Register.it',
				'host' => 'authsmtp.securemail.pro',
				'port' => 465,
				'enc'  => 'ssl',
				'note' => 'Utente = indirizzo email completo, password della casella.',
			),
			'gmail'      => array(
				'name' => 'Gmail / Google Workspace',
				'host' => 'smtp.gmail.com',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Serve una "password per le app" (Account Google → Sicurezza, con la verifica in due passaggi attiva): la password normale non funziona. Google limita gli invii giornalieri.',
			),
			'office365'  => array(
				'name' => 'Microsoft 365',
				'host' => 'smtp.office365.com',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Funziona solo se l\'amministratore di Microsoft 365 ha attivato "SMTP autenticato" per la casella. Microsoft sta togliendo l\'accesso con sola password: se non va, usa un servizio come Brevo con il tuo dominio.',
			),
			'brevo'      => array(
				'name' => 'Brevo',
				'host' => 'smtp-relay.brevo.com',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Utente = "Login SMTP" e password = "Chiave SMTP" (Brevo → SMTP e API). Verifica il dominio del mittente in Brevo.',
			),
			'sendgrid'   => array(
				'name' => 'SendGrid',
				'host' => 'smtp.sendgrid.net',
				'port' => 587,
				'enc'  => 'tls',
				'user' => 'apikey',
				'note' => 'Utente = la parola "apikey", password = la chiave API.',
			),
			'mailgun'    => array(
				'name' => 'Mailgun',
				'host' => 'smtp.eu.mailgun.org',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Server europeo; per un account USA usa smtp.mailgun.org. Utente e password SMTP del dominio in Mailgun.',
			),
			'ses'        => array(
				'name' => 'Amazon SES',
				'host' => 'email-smtp.eu-west-1.amazonaws.com',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Cambia la regione nel server se non è eu-west-1. Utente e password = credenziali SMTP di SES (non quelle di AWS).',
			),
			'ionos'      => array(
				'name' => 'IONOS',
				'host' => 'smtp.ionos.it',
				'port' => 587,
				'enc'  => 'tls',
				'note' => 'Utente = indirizzo email completo, password della casella.',
			),
		);
	}
}
