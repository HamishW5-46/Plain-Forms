<?php
namespace PlainForms;

final class Privacy {
	public function boot(): void {
		add_action( 'plainforms_cleanup_batch', array( $this, 'cleanup' ) );
		add_action( Deactivator::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'schedule' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}
	public function schedule(): void {
		$s = ( new Settings() )->get();
		if ( $s['hash_ip'] || ( $s['auto_delete_expired'] && $s['retention_days'] > 0 ) ) {
			if ( ! wp_next_scheduled( Deactivator::CLEANUP_HOOK ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Deactivator::CLEANUP_HOOK ); }
		} else { wp_clear_scheduled_hook( Deactivator::CLEANUP_HOOK ); wp_clear_scheduled_hook( 'plainforms_cleanup_batch' ); }
	}
	public static function cutoff( int $days, ?int $now = null ): ?string {
		return $days > 0 ? gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - $days * DAY_IN_SECONDS ) : null;
	}
	public function cleanup(): void {
		global $wpdb; $s = ( new Settings() )->get();
		// A crashed request may leave its mutex behind; remove stale mutex options in bounded batches.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention/privacy queries must use current rows; deletion and erasure cannot use stale caches.
		$locks = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 1000", $wpdb->esc_like( 'plainforms_lock_' ) . '%', time() - 60 ) );
		foreach ( $locks as $lock ) { delete_option( $lock ); }
		$cutoff = self::cutoff( (int) $s['retention_days'] );
		if ( ! $s['auto_delete_expired'] || null === $cutoff ) { return; }
		$table = Database::table();
		for ( $i = 0; $i < 10; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention/privacy queries must use current rows; deletion and erasure cannot use stale caches.
			$count = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 500', $table, $cutoff ) );
			if ( false === $count ) { ( new Logger() )->event( 'cleanup_failed' ); break; }
			if ( $count < 500 ) { break; }
			if ( 9 === $i && ! wp_next_scheduled( 'plainforms_cleanup_batch' ) ) { wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'plainforms_cleanup_batch' ); }
		}
	}
	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Plain Forms', '<p>Contact and enquiry forms collect the information entered, submission time and delivery/status information. Depending on site settings, a keyed hash of the connection IP, user agent and referring URL origin/path may also be retained. Notifications are emailed to configured recipients. When enabled for a form, an additional receipt is emailed to the address entered by the submitter. Default retention is 365 days, configurable by the administrator. When enabled, Cloudflare Turnstile processes verification data under Cloudflare’s privacy terms. Email copies and backups have their own retention policies. WordPress personal data export/erasure can identify stored enquiries using an exact email address. Replace this text with your actual configuration before publication.</p>' );
		}
	}
	public function exporters( array $items ): array {
		$items['plain-forms'] = array( 'exporter_friendly_name' => 'Plain Forms enquiries', 'callback' => array( $this, 'export' ) ); return $items;
	}
	public function erasers( array $items ): array {
		$items['plain-forms'] = array( 'eraser_friendly_name' => 'Plain Forms enquiries', 'callback' => array( $this, 'erase' ) ); return $items;
	}
	private function email_rows( string $email, int $offset ): array {
		global $wpdb; $table = Database::table();
		if ( ! is_email( $email ) ) { return array(); }
		// JSON string quoting matches exact values (including email fields with custom IDs).
		$needle = wp_json_encode( $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention/privacy queries must use current rows; deletion and erasure cannot use stale caches.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE data LIKE %s ORDER BY id ASC LIMIT 100 OFFSET %d', $table, '%' . $wpdb->esc_like( $needle ) . '%', max( 0, $offset ) ), ARRAY_A ) ?: array();
	}
	public function export( string $email, int $page = 1 ): array {
		$rows = $this->email_rows( $email, ( max( 1, $page ) - 1 ) * 100 ); $out = array();
		foreach ( $rows as $raw ) {
			$row = ( new SubmissionRepository() )->decode( $raw ); $data = array();
			foreach ( array( 'created_at', 'status', 'email_status', 'receipt_status', 'ip_hash', 'user_agent', 'referer' ) as $key ) { if ( $row[ $key ] ) { $data[] = array( 'name' => $key, 'value' => $row[ $key ] ); } }
			foreach ( $row['data'] as $key => $value ) { if ( '_plainforms' !== $key ) { $data[] = array( 'name' => $row['data']['_plainforms']['labels'][ $key ] ?? $key, 'value' => Mailer::value( $value ) ); } }
			$out[] = array( 'group_id' => 'plain-forms', 'group_label' => 'Enquiries', 'item_id' => 'plainforms-enquiry-' . $row['id'], 'data' => $data );
		}
		return array( 'data' => $out, 'done' => count( $rows ) < 100 );
	}
	public function erase( string $email, int $page = 1 ): array {
		// Always start at zero: deleting rows while using offsets skips records on later calls.
		$rows = $this->email_rows( $email, 0 ); $removed = false; $retained = false;
		foreach ( $rows as $row ) {
			if ( ( new SubmissionRepository() )->delete( (int) $row['id'] ) ) { $removed = true; } else { $retained = true; }
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array( 'Some enquiries could not be removed. Ask the administrator to check database access.' ) : array(), 'done' => $retained || count( $rows ) < 100 );
	}
}
