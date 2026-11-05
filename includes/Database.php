<?php
namespace PlainForms;

final class Database {
	public const VERSION = '2';
	public const VERSION_OPTION = 'plainforms_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'plainforms_submissions';
	}

	public static function receipts_ready(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) >= 2;
	}

	/** Record the version only after verifying that dbDelta produced the expected columns/indexes. */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$collation = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			email_status varchar(20) NOT NULL DEFAULT 'pending',
			receipt_status varchar(20) NOT NULL DEFAULT 'disabled',
			receipt_error_message text DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			referer text DEFAULT NULL,
			data longtext NOT NULL,
			error_message text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY email_status (email_status),
			KEY receipt_status (receipt_status)
		) {$collation};" );
		// Identifier is derived exclusively from WordPress's trusted table prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Installer inspects actual schema after dbDelta; cached schema metadata could falsely confirm an upgrade.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Installer inspects actual schema after dbDelta; cached schema metadata could falsely confirm an upgrade.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$required = array( 'id', 'form_id', 'created_at', 'updated_at', 'status', 'email_status', 'receipt_status', 'receipt_error_message', 'ip_hash', 'user_agent', 'referer', 'data', 'error_message' );
		if ( array_diff( $required, $columns ?: array() ) || array_diff( array( 'PRIMARY', 'form_id', 'status', 'created_at', 'email_status', 'receipt_status' ), array_column( $indexes ?: array(), 'Key_name' ) ) ) {
			return false;
		}
		update_option( self::VERSION_OPTION, self::VERSION, false );
		return true;
	}
}
