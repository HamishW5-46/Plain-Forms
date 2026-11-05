<?php
namespace PlainForms;

final class SubmissionRepository {
	public const STATUSES = array( 'new', 'read', 'archived', 'spam' );
	public const EMAIL_STATUSES = array( 'pending', 'sent', 'failed' );
	public const RECEIPT_STATUSES = array( 'disabled', 'pending', 'sent', 'failed', 'skipped' );

	public function create( int $form_id, array $data, array $meta ): int|\WP_Error {
		global $wpdb;
		$json = wp_json_encode( $data );
		if ( false === $json ) { return new \WP_Error( 'storage_failed', 'Unable to save enquiry.' ); }
		$now = gmdate( 'Y-m-d H:i:s' );
		$record = array(
			'form_id' => $form_id, 'created_at' => $now, 'updated_at' => $now, 'status' => 'new', 'email_status' => 'pending',
			'ip_hash' => $meta['ip_hash'] ?? null, 'user_agent' => $meta['user_agent'] ?? null, 'referer' => $meta['referer'] ?? null, 'data' => $json,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( Database::receipts_ready() ) {
			$record['receipt_status'] = in_array( $meta['receipt_status'] ?? '', self::RECEIPT_STATUSES, true ) ? $meta['receipt_status'] : 'disabled';
			$formats[] = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		$ok = $wpdb->insert( Database::table(), $record, $formats );
		return false === $ok ? new \WP_Error( 'storage_failed', 'Unable to save enquiry.' ) : (int) $wpdb->insert_id;
	}
	public function find( int $id ): ?array {
		global $wpdb; $table = Database::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}
	public function decode( array $row ): array {
		$row['receipt_status'] = $row['receipt_status'] ?? 'disabled';
		$row['receipt_error_message'] = $row['receipt_error_message'] ?? null;
		$values = json_decode( $row['data'], true );
		$row['data_valid'] = JSON_ERROR_NONE === json_last_error() && is_array( $values );
		$row['data'] = $row['data_valid'] ? $values : array();
		foreach ( $row['data'] as $key => $value ) {
			if ( '_plainforms' === $key ) { continue; }
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,49}$/D', $key ) ) { unset( $row['data'][ $key ] ); $row['data_valid'] = false; continue; }
			if ( ! is_string( $value ) && ! is_bool( $value ) && ! ( is_array( $value ) && array_is_list( $value ) && count( array_filter( $value, 'is_string' ) ) === count( $value ) ) ) {
				$row['data'][ $key ] = '[Unreadable value]'; $row['data_valid'] = false;
			}
		}
		if ( isset( $row['data']['_plainforms'] ) ) {
			$snapshot = $row['data']['_plainforms'];
			if ( ! is_array( $snapshot ) ) { $snapshot = array(); $row['data_valid'] = false; }
			$row['data']['_plainforms'] = array( 'title' => is_string( $snapshot['title'] ?? null ) ? $snapshot['title'] : '' );
			$field = $snapshot['receipt_email_field'] ?? '';
			$row['data']['_plainforms']['receipt_email_field'] = is_string( $field ) && preg_match( '/^[a-z][a-z0-9_]{0,49}$/D', $field ) ? $field : '';
			foreach ( array( 'labels', 'types' ) as $key ) { $row['data']['_plainforms'][ $key ] = is_array( $snapshot[ $key ] ?? null ) ? array_filter( $snapshot[ $key ], 'is_string' ) : array(); }
		}
		return $row;
	}
	public function status( int $id, string $status ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return in_array( $status, self::STATUSES, true ) && false !== $wpdb->update( Database::table(), array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}
	public function delivery( int $id, bool $sent ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return false !== $wpdb->update( Database::table(), array( 'email_status' => $sent ? 'sent' : 'failed', 'error_message' => $sent ? null : 'wp_mail did not accept the notification.', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}
	public function receipt_delivery( int $id, bool $sent ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return false !== $wpdb->update( Database::table(), array( 'receipt_status' => $sent ? 'sent' : 'failed', 'receipt_error_message' => $sent ? null : 'The submitter receipt could not be sent.', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return false !== $wpdb->delete( Database::table(), array( 'id' => $id ), array( '%d' ) );
	}
	/** Only allowlisted clauses are constructed; every visitor/admin value is prepared. */
	public function where( array $filters ): string {
		global $wpdb; $parts = array( '1=1' );
		if ( in_array( $filters['delivery_failed'] ?? '', array( '1', 1, true ), true ) ) { $parts[] = "(email_status = 'failed' OR receipt_status = 'failed')"; }
		foreach ( array( 'max_id' => '<=', 'after_id' => '>' ) as $bound => $operator ) {
			if ( isset( $filters[ $bound ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Operator comes only from the fixed map above; bound value is prepared.
				$parts[] = $wpdb->prepare( "id {$operator} %d", (int) $filters[ $bound ] );
			}
		}
		if ( ! empty( $filters['form_id'] ) ) { $parts[] = $wpdb->prepare( 'form_id = %d', absint( $filters['form_id'] ) ); }
		if ( in_array( $filters['status'] ?? '', self::STATUSES, true ) ) { $parts[] = $wpdb->prepare( 'status = %s', $filters['status'] ); }
		if ( in_array( $filters['receipt_status'] ?? '', self::RECEIPT_STATUSES, true ) ) { $parts[] = $wpdb->prepare( 'receipt_status = %s', $filters['receipt_status'] ); }
		if ( in_array( $filters['email_status'] ?? '', self::EMAIL_STATUSES, true ) ) { $parts[] = $wpdb->prepare( 'email_status = %s', $filters['email_status'] ); }
		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $key => $operator ) {
			$value = $filters[ $key ] ?? '';
			if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) && wp_checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ), $value ) ) {
				$local = new \DateTimeImmutable( $value . ( 'to' === $key ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Operator comes only from the fixed date-bound map; validated UTC value is prepared.
				$parts[] = $wpdb->prepare( "created_at {$operator} %s", $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
			}
		}
		if ( ! empty( $filters['s'] ) && is_string( $filters['s'] ) ) { $parts[] = $wpdb->prepare( 'data LIKE %s', '%' . $wpdb->esc_like( $filters['s'] ) . '%' ); }
		return implode( ' AND ', $parts );
	}
	public function listing( array $filters, int $limit = 20, int $offset = 0, string $sort = 'id', string $direction = 'DESC' ): array {
		global $wpdb; $table = Database::table(); $where = $this->where( $filters );
		$sort = in_array( $sort, array( 'id', 'created_at', 'status', 'email_status', 'receipt_status', 'form_id' ), true ) ? $sort : 'id';
		$direction = 'ASC' === $direction ? 'ASC' : 'DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- where() contains fixed clauses and individually prepared values; direction is strictly ASC/DESC; identifiers use %i. Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY %i {$direction} LIMIT %d OFFSET %d", $table, $sort, max( 1, min( 500, $limit ) ), max( 0, $offset ) ), ARRAY_A );
		return array_map( array( $this, 'decode' ), $rows ?: array() );
	}
	public function maximum_id(): int {
		global $wpdb; $table = Database::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );
	}

	public function count( array $filters = array() ): int {
		global $wpdb; $table = Database::table(); $where = $this->where( $filters );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- where() contains fixed clauses and individually prepared values; direction is strictly ASC/DESC; identifiers use %i. Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $table ) );
	}
	public function form_stats( int $id ): array {
		global $wpdb; $table = Database::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated submissions table; admin/processing reads must reflect current writes and delivery/status changes.
		return $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(status = 'new') AS unread, MAX(created_at) AS latest FROM %i WHERE form_id = %d", $table, $id ), ARRAY_A ) ?: array();
	}
}
