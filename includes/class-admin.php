<?php
/**
 * Pagina di LU3G Mail in bacheca.
 *
 * - Se LU3G Hub è attivo la pagina sta nel suo menu "LU3G" (voce "Email SMTP"), altrimenti ha una voce propria
 *   col logo LU3G. Serve il permesso manage_options (amministratori).
 * - Una sola pagina con quattro schede (Connessione, Prova, Registro, Opzioni) gestite dal JS; tutte le azioni
 *   passano da admin-ajax (azione "lu3g_mail") con nonce e controllo dei permessi.
 * - La password non arriva mai al browser: il campo è vuoto e dice solo se ce n'è una salvata.
 * - Se le email non partono, un avviso compare in Bacheca, nella pagina Plugin e qui.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Mail_Admin {

	const PAGE = 'lu3g-mail';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 ); // dopo LU3G Hub (priorità 10)
		add_action( 'wp_ajax_lu3g_mail', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LU3G_MAIL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu() {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks['lu3g-hub'] ) ) {
			$hook = add_submenu_page( 'lu3g-hub', 'LU3G Mail', 'Email SMTP', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		} else {
			$svg  = (string) file_get_contents( dirname( LU3G_MAIL_FILE ) . '/assets/lu3g-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$hook = add_menu_page( 'LU3G Mail', 'LU3G Mail', self::CAP, self::PAGE, array( __CLASS__, 'render' ), 'data:image/svg+xml;base64,' . base64_encode( $svg ), 81 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Impostazioni</a>' );
		return $links;
	}

	public static function load() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter(
			'admin_body_class',
			function ( $c ) {
				return $c . ' lu3g-mail-page';
			}
		);
	}

	public static function assets() {
		wp_enqueue_style( 'lu3g-mail', LU3G_MAIL_URL . 'assets/admin.css', array(), LU3G_MAIL_VERSION );
		wp_add_inline_style( 'lu3g-mail', '@font-face{font-family:"LU3G Manrope";src:url(' . esc_url( LU3G_MAIL_URL . 'assets/fonts/manrope-latin-wght.woff2' ) . ') format("woff2");font-weight:200 800;font-display:swap}' );
		wp_enqueue_script( 'lu3g-mail', LU3G_MAIL_URL . 'assets/admin.js', array(), LU3G_MAIL_VERSION, true );

		$s = LU3G_Mail_Settings::get();
		unset( $s['password'] );
		$locked = array();
		foreach ( array_keys( LU3G_Mail_Settings::CONSTANTS ) as $field ) {
			$locked[ $field ] = LU3G_Mail_Settings::locked( $field );
		}
		wp_localize_script(
			'lu3g-mail',
			'LU3G_MAIL',
			array(
				'ajax'        => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'lu3g_mail' ),
				'settings'    => $s,
				'providers'   => LU3G_Mail_Settings::providers(),
				'locked'      => $locked,
				'hasPassword' => LU3G_Mail_Settings::has_password(),
				'adminEmail'  => get_option( 'admin_email' ),
				'error'       => 'Qualcosa è andato storto. Ricarica la pagina e riprova.',
			)
		);
	}

	/* ------------------------------------------------------------------ pagina */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s     = LU3G_Mail_Settings::get();
		$stats = LU3G_Mail_Log::stats( 7 );
		$err   = LU3G_Mail_Mailer::current_error();
		$logo  = (string) file_get_contents( dirname( LU3G_MAIL_FILE ) . '/assets/lu3g-logo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! LU3G_Mail_Settings::ready() ) {
			$status = array( 'off', 'Spento: WordPress invia come prima' );
		} elseif ( $err ) {
			$status = array( 'error', 'Le email non partono' );
		} else {
			$status = array( 'on', 'Attivo su ' . $s['host'] );
		}
		$lock = function ( $field ) {
			return LU3G_Mail_Settings::locked( $field ) ? ' disabled title="Impostato in wp-config.php"' : '';
		};
		?>
		<div class="wrap lu3g-mail">
			<h1 class="screen-reader-text">LU3G Mail</h1>
			<hr class="wp-header-end">

			<header class="lm-hero">
				<div class="lm-hero__glow" aria-hidden="true"></div>
				<div class="lm-hero__brand">
					<span class="lm-hero__logo" aria-hidden="true"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG del plugin ?></span>
					<div>
						<p class="lm-hero__eyebrow">LU3G Mail</p>
						<h2 class="lm-hero__title">Email del sito</h2>
						<p class="lm-hero__status lm-status--<?php echo esc_attr( $status[0] ); ?>" data-status><i aria-hidden="true"></i><span><?php echo esc_html( $status[1] ); ?></span></p>
					</div>
				</div>
				<button type="button" class="lm-btn lm-btn--light" data-goto="test">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>
					Invia un'email di prova
				</button>
				<dl class="lm-stats">
					<div><dt>Inviate negli ultimi 7 giorni</dt><dd data-stat="sent"><?php echo (int) $stats['sent']; ?></dd></div>
					<div><dt>Non partite</dt><dd data-stat="failed"><?php echo (int) $stats['failed']; ?></dd></div>
					<div><dt>Ultimo invio riuscito</dt><dd class="lm-stats__small"><?php echo $stats['last'] ? esc_html( human_time_diff( $stats['last'] ) ) . ' fa' : '—'; ?></dd></div>
				</dl>
			</header>

			<?php if ( $err ) : ?>
				<div class="lm-alert" role="alert">
					<strong>Le email del sito non partono</strong>
					<span><?php echo esc_html( $err['message'] ); ?></span>
					<small>Ultimo errore <?php echo esc_html( human_time_diff( (int) $err['time'] ) ); ?> fa · <?php echo esc_html( $err['raw'] ); ?></small>
				</div>
			<?php endif; ?>
			<?php if ( LU3G_Mail_Settings::password_unreadable() ) : ?>
				<div class="lm-alert" role="alert"><strong>Password da reinserire</strong><span>Le chiavi segrete di wp-config.php sono cambiate e la password salvata non si può più leggere.</span></div>
			<?php endif; ?>

			<nav class="lm-tabs" role="tablist" aria-label="Sezioni">
				<button type="button" role="tab" aria-selected="true" data-tab="connection">Connessione</button>
				<button type="button" role="tab" aria-selected="false" data-tab="test">Prova</button>
				<button type="button" role="tab" aria-selected="false" data-tab="log">Registro</button>
				<button type="button" role="tab" aria-selected="false" data-tab="options">Opzioni</button>
				<span class="lm-tabs__ink" aria-hidden="true"></span>
			</nav>

			<form class="lm-form" data-form autocomplete="off" novalidate>

				<!-- Connessione -->
				<section class="lm-panel is-current" data-panel="connection" role="tabpanel">
					<div class="lm-card lm-card--switch">
						<div>
							<h3>Invia tramite SMTP</h3>
							<p>Acceso, tutte le email del sito (form, ordini, password) partono dal server qui sotto. Spento, WordPress invia come prima.</p>
						</div>
						<?php echo self::switch_html( 'enabled', 'Attivo', $s['enabled'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>

					<div class="lm-card">
						<h3>Servizio</h3>
						<p class="lm-help">Scegli il fornitore della posta: server, porta e cifratura si compilano da soli.</p>
						<div class="lm-providers" data-providers role="radiogroup" aria-label="Servizio email"></div>
						<p class="lm-note" data-provider-note></p>
					</div>

					<div class="lm-card">
						<h3>Server</h3>
						<div class="lm-grid">
							<label class="lm-field lm-field--wide"><span>Server SMTP</span><input type="text" name="host" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="smtps.aruba.it" spellcheck="false"<?php echo $lock( 'host' ); // phpcs:ignore ?>></label>
							<label class="lm-field"><span>Porta</span><input type="number" name="port" value="<?php echo (int) $s['port']; ?>" min="1" max="65535"<?php echo $lock( 'port' ); // phpcs:ignore ?>></label>
						</div>
						<div class="lm-field"><span>Cifratura</span>
							<div class="lm-seg" data-seg="encryption" role="radiogroup" aria-label="Cifratura"<?php echo LU3G_Mail_Settings::locked( 'encryption' ) ? ' data-locked' : ''; ?>>
								<button type="button" role="radio" data-value="ssl">SSL <small>porta 465</small></button>
								<button type="button" role="radio" data-value="tls">TLS <small>porta 587</small></button>
								<button type="button" role="radio" data-value="none">Nessuna</button>
							</div>
						</div>
						<div data-show-if="encryption=none">
							<?php echo self::switch_html( 'auto_tls', 'Usa comunque la cifratura se il server la offre', $s['auto_tls'] ); // phpcs:ignore ?>
						</div>
					</div>

					<div class="lm-card">
						<div class="lm-card__head">
							<h3>Accesso</h3>
							<?php echo self::switch_html( 'auth', 'Il server chiede utente e password', $s['auth'] ); // phpcs:ignore ?>
						</div>
						<div class="lm-grid" data-show-if="auth">
							<label class="lm-field"><span>Utente</span><input type="text" name="username" value="<?php echo esc_attr( $s['username'] ); ?>" placeholder="info@cliente.it" spellcheck="false" autocomplete="off"<?php echo $lock( 'username' ); // phpcs:ignore ?>></label>
							<label class="lm-field"><span>Password</span>
								<span class="lm-pass">
									<input type="password" name="password" value="" autocomplete="new-password" placeholder="<?php echo LU3G_Mail_Settings::has_password() ? '•••••••• salvata: lascia vuoto per non cambiarla' : 'Password della casella o chiave SMTP'; ?>"<?php echo $lock( 'password' ); // phpcs:ignore ?>>
									<button type="button" class="lm-pass__eye" data-eye aria-label="Mostra la password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
								</span>
								<small>Salvata cifrata. Non viene mai mostrata.</small>
							</label>
						</div>
					</div>

					<div class="lm-card">
						<h3>Mittente</h3>
						<div class="lm-grid">
							<label class="lm-field"><span>Email del mittente</span><input type="email" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="info@cliente.it"<?php echo $lock( 'from_email' ); // phpcs:ignore ?>></label>
							<label class="lm-field"><span>Nome del mittente</span><input type="text" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"<?php echo $lock( 'from_name' ); // phpcs:ignore ?>></label>
						</div>
						<div class="lm-switches">
							<?php echo self::switch_html( 'force_from', 'Usa sempre questa email come mittente (consigliato: i server rifiutano mittenti di altri domini)', $s['force_from'] ); // phpcs:ignore ?>
							<?php echo self::switch_html( 'force_from_name', 'Usa sempre questo nome, anche quando un plugin ne imposta un altro', $s['force_from_name'] ); // phpcs:ignore ?>
							<?php echo self::switch_html( 'return_path', 'Le email non consegnate tornano al mittente', $s['return_path'] ); // phpcs:ignore ?>
						</div>
					</div>
				</section>

				<!-- Prova -->
				<section class="lm-panel" data-panel="test" role="tabpanel" hidden>
					<div class="lm-card">
						<h3>Email di prova</h3>
						<p class="lm-help">Salva prima le impostazioni, poi manda un'email di prova a un indirizzo che puoi controllare.</p>
						<div class="lm-test">
							<label class="lm-field lm-field--wide"><span>Destinatario</span><input type="email" name="test_to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></label>
							<button type="button" class="lm-btn lm-btn--primary" data-test>
								<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>
								Invia
							</button>
						</div>
						<div class="lm-result" data-test-result hidden>
							<div class="lm-result__icon" aria-hidden="true"></div>
							<div><strong data-test-title></strong><p data-test-message></p></div>
						</div>
						<details class="lm-debug" data-test-debug hidden>
							<summary>Dialogo con il server (per il supporto tecnico)</summary>
							<pre data-test-log></pre>
						</details>
					</div>
				</section>

				<!-- Registro -->
				<section class="lm-panel" data-panel="log" role="tabpanel" hidden>
					<div class="lm-card">
						<div class="lm-card__head">
							<h3>Registro degli invii</h3>
							<button type="button" class="lm-btn lm-btn--ghost" data-clear-log>Svuota</button>
						</div>
						<div class="lm-logbar">
							<div class="lm-chips" role="tablist" aria-label="Filtra">
								<button type="button" class="is-current" data-log-status="">Tutte</button>
								<button type="button" data-log-status="sent">Inviate</button>
								<button type="button" data-log-status="failed">Non partite</button>
							</div>
							<input type="search" placeholder="Cerca destinatario o oggetto…" data-log-search>
						</div>
						<div class="lm-log" data-log><p class="lm-empty">Caricamento…</p></div>
						<div class="lm-pager" data-pager></div>
						<?php if ( ! $s['log_enabled'] ) : ?>
							<p class="lm-note">Il registro è spento (scheda Opzioni): le email nuove non vengono annotate.</p>
						<?php endif; ?>
					</div>
				</section>

				<!-- Opzioni -->
				<section class="lm-panel" data-panel="options" role="tabpanel" hidden>
					<div class="lm-card">
						<h3>Registro</h3>
						<div class="lm-switches">
							<?php echo self::switch_html( 'log_enabled', 'Annota ogni email inviata (data, destinatario, oggetto, esito)', $s['log_enabled'] ); // phpcs:ignore ?>
							<?php echo self::switch_html( 'log_body', 'Salva anche il testo, così si può rimandare (contiene i dati dei form: tienilo per pochi giorni)', $s['log_body'] ); // phpcs:ignore ?>
						</div>
						<label class="lm-field lm-field--short"><span>Cancella dopo (giorni)</span><input type="number" name="log_days" min="1" max="365" value="<?php echo (int) $s['log_days']; ?>"></label>
					</div>
					<div class="lm-card">
						<h3>Se le email non partono</h3>
						<label class="lm-field"><span>Avvisa questo indirizzo</span><input type="email" name="alert_email" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="Vuoto: solo l'avviso in bacheca"><small>Al massimo un'email ogni 6 ore, inviata con il server del sito. Meglio un indirizzo di un altro dominio.</small></label>
						<?php echo self::switch_html( 'fallback', 'Riprova con il server del sito: l\'email parte comunque, ma può finire in spam', $s['fallback'] ); // phpcs:ignore ?>
					</div>
					<div class="lm-card">
						<h3>Dati in wp-config.php</h3>
						<p class="lm-help">Per non salvare la password nel database, scrivi i dati in wp-config.php: qui compariranno bloccati.</p>
						<pre class="lm-code">define( 'LU3G_MAIL_HOST', 'smtps.aruba.it' );
define( 'LU3G_MAIL_PORT', 465 );
define( 'LU3G_MAIL_ENCRYPTION', 'ssl' ); // ssl, tls, none
define( 'LU3G_MAIL_USER', 'info@cliente.it' );
define( 'LU3G_MAIL_PASS', 'la-password' );
define( 'LU3G_MAIL_FROM', 'info@cliente.it' );
define( 'LU3G_MAIL_FROM_NAME', 'Cliente Srl' );</pre>
					</div>
				</section>

				<div class="lm-savebar" data-savebar>
					<span data-dirty-label>Modifiche non salvate</span>
					<button type="submit" class="lm-btn lm-btn--primary" data-save>Salva</button>
				</div>
			</form>

			<div class="lm-modal" data-modal hidden>
				<div class="lm-modal__box" role="dialog" aria-modal="true" aria-labelledby="lm-modal-title">
					<button type="button" class="lm-modal__close" data-close aria-label="Chiudi">×</button>
					<div data-modal-body></div>
				</div>
			</div>
			<div class="lm-toasts" aria-live="polite" data-toasts></div>
			<footer class="lm-foot">LU3G Mail <?php echo esc_html( LU3G_MAIL_VERSION ); ?></footer>
		</div>
		<?php
	}

	private static function switch_html( $name, $label, $on ) {
		return sprintf(
			'<button type="button" class="lm-switch" role="switch" aria-checked="%1$s" data-name="%2$s"><span class="lm-switch__track" aria-hidden="true"><span class="lm-switch__thumb"></span></span><span class="lm-switch__label">%3$s</span></button>',
			$on ? 'true' : 'false',
			esc_attr( $name ),
			esc_html( $label )
		);
	}

	/* ------------------------------------------------------------------ azioni */

	public static function ajax() {
		check_ajax_referer( 'lu3g_mail', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Non hai i permessi per questa operazione.' ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'save':
				$input = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanificato in save()
				$s     = LU3G_Mail_Settings::save( is_array( $input ) ? $input : array() );
				$warn  = '';
				if ( $s['enabled'] && '' === $s['host'] ) {
					$warn = 'Manca il server SMTP: finché non lo inserisci WordPress invia come prima.';
				} elseif ( $s['enabled'] && $s['auth'] && '' === $s['password'] ) {
					$warn = 'Manca la password.';
				}
				unset( $s['password'] );
				wp_send_json_success(
					array(
						'message'     => 'Impostazioni salvate.',
						'warning'     => $warn,
						'settings'    => $s,
						'hasPassword' => LU3G_Mail_Settings::has_password(),
						'status'      => self::status_label(),
					)
				);
				break;

			case 'test':
				$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
				if ( ! is_email( $to ) ) {
					wp_send_json_error( array( 'message' => 'Scrivi un indirizzo email valido.' ) );
				}
				if ( ! LU3G_Mail_Settings::ready() ) {
					wp_send_json_error( array( 'message' => 'Accendi "Invia tramite SMTP", inserisci il server e salva prima di provare.' ) );
				}
				$result           = LU3G_Mail_Mailer::test( $to );
				$result['stats']  = LU3G_Mail_Log::stats( 7 );
				$result['status'] = self::status_label();
				wp_send_json_success( $result );
				break;

			case 'log':
				$res = LU3G_Mail_Log::query(
					array(
						'status' => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
						'search' => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
						'page'   => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
					)
				);
				foreach ( $res['rows'] as &$row ) {
					$t           = strtotime( $row['created'] . ' UTC' );
					$row['when'] = wp_date( 'd/m/Y H:i', $t );
					$row['ago']  = human_time_diff( $t ) . ' fa';
				}
				unset( $row );
				wp_send_json_success( $res );
				break;

			case 'view':
				$row = LU3G_Mail_Log::get( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
				if ( ! $row ) {
					wp_send_json_error( array( 'message' => 'Riga non trovata.' ) );
				}
				$row['when'] = wp_date( 'd/m/Y H:i:s', strtotime( $row['created'] . ' UTC' ) );
				$row['html'] = null !== $row['message'] && ( false !== stripos( (string) $row['headers'], 'text/html' ) || preg_match( '/<(html|body|p|div|table|br)\b/i', (string) $row['message'] ) );
				wp_send_json_success( $row );
				break;

			case 'resend':
				$result = LU3G_Mail_Log::resend( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
				wp_send_json_success( array( 'message' => 'Email rimandata.' ) );
				break;

			case 'clear':
				LU3G_Mail_Log::clear();
				wp_send_json_success( array( 'message' => 'Registro svuotato.' ) );
				break;
		}
		wp_send_json_error( array( 'message' => 'Azione sconosciuta.' ) );
	}

	private static function status_label() {
		$s = LU3G_Mail_Settings::get();
		if ( ! LU3G_Mail_Settings::ready() ) {
			return array( 'off', 'Spento: WordPress invia come prima' );
		}
		if ( LU3G_Mail_Mailer::current_error() ) {
			return array( 'error', 'Le email non partono' );
		}
		return array( 'on', 'Attivo su ' . $s['host'] );
	}

	/* ------------------------------------------------------------------ avviso in bacheca */

	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		$err = LU3G_Mail_Mailer::current_error();
		if ( ! $err ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>Le email del sito non partono.</strong> %s <a href="%s">Apri LU3G Mail</a></p></div>',
			esc_html( $err['message'] ),
			esc_url( self::page_url() )
		);
	}
}
