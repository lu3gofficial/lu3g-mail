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
			'enabled'         => false,          // finché non è acceso, WordPress invia come prima
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

	/** Pronto per inviare: acceso e con un server. */
	public static function ready() {
		$s = self::get();
		return $s['enabled'] && '' !== trim( (string) $s['host'] );
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

		$bool = array( 'enabled', 'auto_tls', 'auth', 'force_from', 'force_from_name', 'return_path', 'log_enabled', 'log_body', 'fallback' );
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
