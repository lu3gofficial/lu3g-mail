<?php
/**
 * Plugin Name:       LU3G Mail
 * Plugin URI:        https://github.com/lu3gofficial/lu3g-mail
 * Description:       Invia le email del sito tramite un server SMTP (Aruba, Register, Google Workspace, Brevo e altri), con email di prova, errori spiegati, registro degli invii e avvisi se la posta smette di partire.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LU3G Agenzia Web
 * Author URI:        https://lu3g.it
 * License:           GPL-2.0-or-later
 * Text Domain:       lu3g-mail
 * Update URI:        https://github.com/lu3gofficial/lu3g-mail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LU3G_MAIL_VERSION', '1.0.0' );
define( 'LU3G_MAIL_FILE', __FILE__ );
define( 'LU3G_MAIL_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-log.php';
require_once __DIR__ . '/includes/class-mailer.php';
require_once __DIR__ . '/includes/class-admin.php';

// Aggiornamenti dalle release di GitHub.
LU3G_Mail_Updater::init( __FILE__ );

// Invio: deve funzionare ovunque (frontend, form, cron, REST), non solo in bacheca.
LU3G_Mail_Mailer::init();
LU3G_Mail_Log::init();

if ( is_admin() ) {
	LU3G_Mail_Admin::init();
}

register_activation_hook( __FILE__, array( 'LU3G_Mail_Log', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LU3G_Mail_Log', 'deactivate' ) );
