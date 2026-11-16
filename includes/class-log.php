<?php
/**
 * Registro degli invii.
 *
 * - Tabella propria ({prefix}lu3g_mail_log), creata all'attivazione e controllata a ogni versione nuova.
 * - Di serie salva solo data, destinatari, oggetto ed esito (con il motivo dell'errore). Con "Salva anche il testo"
 *   salva anche intestazioni e messaggio, e allora si può rimandare un'email: utile per i form, ma il testo contiene
 *   dati personali, quindi si cancella insieme alle righe vecchie.
 * - Le righe più vecchie dei giorni impostati si cancellano una volta al giorno (cron).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Mail_Log {

	const DB_VERSION = '1';
	const CRON       = 'lu3g_mail_log_cleanup';

	/** Id dell'ultima riga scritta: l'invio di riserva la completa. */
	private static $last_id = 0;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lu3g_mail_log';
	}

	public static function init() {
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'on_sent' ), 20 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_failed' ), 1 );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function activate() {
		self::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lu3g_mail_db' ) !== self::DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	private static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				recipients text NOT NULL,
				subject text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'sent',
				error text NULL,
				via varchar(20) NOT NULL DEFAULT 'smtp',
				headers longtext NULL,
				message longtext NULL,
				attachments smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created (created),
				KEY status (status)
			) {$charset};"
		);
		update_option( 'lu3g_mail_db', self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ scrittura */

	private static function write( $data, $status, $error = '' ) {
		$s = LU3G_Mail_Settings::get();
		if ( ! $s['log_enabled'] ) {
			return;
		}
		global $wpdb;
		$to      = is_array( $data['to'] ?? '' ) ? implode( ', ', $data['to'] ) : (string) ( $data['to'] ?? '' );
		$headers = $data['headers'] ?? array();
		$headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
		$via     = ( LU3G_Mail_Settings::ready() && ! LU3G_Mail_Mailer::bypassing() ) ? 'smtp' : 'server';

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'created'     => current_time( 'mysql', true ),
				'recipients'  => mb_substr( $to, 0, 2000 ),
				'subject'     => mb_substr( (string) ( $data['subject'] ?? '' ), 0, 500 ),
				'status'      => $status,
				'error'       => '' !== $error ? mb_substr( $error, 0, 2000 ) : null,
				'via'         => $via,
				'headers'     => $s['log_body'] ? $headers : null,
				'message'     => $s['log_body'] ? (string) ( $data['message'] ?? '' ) : null,
				'attachments' => is_array( $data['attachments'] ?? null ) ? count( $data['attachments'] ) : 0,
			)
		);
		self::$last_id = (int) $wpdb->insert_id;
	}

	public static function on_sent( $mail_data ) {
		self::write( (array) $mail_data, 'sent' );
	}

	public static function on_failed( $error ) {
		if ( $error instanceof WP_Error ) {
			self::write( (array) $error->get_error_data(), 'failed', LU3G_Mail_Mailer::error_text( $error->get_error_message() ) );
		}
	}

	/** Dopo l'invio di riserva: la riga fallita dice com'è andata. La riga dell'invio di riserva la scrive on_sent. */
	public static function last_id() {
		return self::$last_id;
	}

	public static function note_fallback( $id, $sent ) {
		if ( ! $id ) {
			return;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array( 'status' => $sent ? 'rescued' : 'failed' ),
			array( 'id' => (int) $id )
		);
	}

	/* ------------------------------------------------------------------ lettura */

	/**
	 * Righe del registro, dalla più recente.
	 *
	 * @param array $args status (sent|failed|''), search, page, per_page.
	 * @return array{rows:array,total:int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'status' => '', 'search' => '', 'page' => 1, 'per_page' => 25 ) );
		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();

		if ( 'failed' === $args['status'] ) {
			$where[] = "status IN ('failed','rescued')";
		} elseif ( 'sent' === $args['status'] ) {
			$where[] = "status = 'sent'";
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(recipients LIKE %s OR subject LIKE %s)';
			$vals[]  = $like;
			$vals[]  = $like;
		}
		$sql_where = implode( ' AND ', $where );
		$per       = max( 1, min( 100, (int) $args['per_page'] ) );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$rows_sql  = "SELECT id, created, recipients, subject, status, error, via, attachments, (message IS NOT NULL) AS has_body FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/** Conteggi degli ultimi giorni per l'intestazione. */
	public static function stats( $days = 7 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE created >= %s GROUP BY status", $since ), ARRAY_A );
		$last = $wpdb->get_var( "SELECT created FROM {$table} WHERE status = 'sent' ORDER BY id DESC LIMIT 1" );
		// phpcs:enable
		$out = array(
			'sent'   => 0,
			'failed' => 0,
			'last'   => $last ? strtotime( $last . ' UTC' ) : 0,
		);
		foreach ( (array) $rows as $r ) {
			if ( 'sent' === $r['status'] ) {
				$out['sent'] += (int) $r['n'];
			} else {
				$out['failed'] += (int) $r['n'];
			}
		}
		return $out;
	}

	/** Rimanda un'email salvata col testo. */
	public static function resend( $id ) {
		$row = self::get( $id );
		if ( ! $row || null === $row['message'] ) {
			return new WP_Error( 'lu3g_mail_nobody', 'Di questa email non è salvato il testo: non si può rimandare.' );
		}
		$headers = array_filter( array_map( 'trim', explode( "\n", (string) $row['headers'] ) ) );
		$to      = array_map( 'trim', explode( ',', $row['recipients'] ) );
		return wp_mail( $to, $row['subject'], $row['message'], $headers ) ? true : new WP_Error( 'lu3g_mail_resend', 'Rinvio non riuscito: guarda la riga nuova nel registro.' );
	}

	public static function cleanup() {
		global $wpdb;
		$days  = (int) LU3G_Mail_Settings::get()['log_days'];
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
