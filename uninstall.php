<?php
/**
 * Eliminazione di LU3G Mail (Plugin → Elimina): via impostazioni, password cifrata, stato e registro.
 * Disattivare il plugin non cancella niente; solo l'eliminazione.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'lu3g_mail_settings', 'lu3g_mail_last_error', 'lu3g_mail_last_success', 'lu3g_mail_alert_sent', 'lu3g_mail_db' ) as $option ) {
	delete_option( $option );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lu3g_mail_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'lu3g_mail_log_cleanup' );
