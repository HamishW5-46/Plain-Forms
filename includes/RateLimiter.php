<?php
namespace PlainForms;

final class RateLimiter {
	/** Short database mutex prevents concurrent transient increments bypassing the limit. */
	public function allow( string $source_hash, int $form_id, int $count, int $window ): bool {
		global $wpdb;
		$fingerprint = hash( 'sha256', $source_hash . ':' . $form_id );
		$key = 'plainforms_rate_' . $fingerprint;
		$lock = 'plainforms_lock_' . $fingerprint;
		$now = time();
		if ( ! add_option( $lock, $now, '', false ) ) {
			$old = (int) get_option( $lock );
			if ( $old < $now - 10 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete of a stale mutex; cache invalidated immediately below.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, (string) $old ) );
				wp_cache_delete( $lock, 'options' );
			}
			return false;
		}
		try {
			$bucket = get_transient( $key );
			if ( ! is_array( $bucket ) || ! is_int( $bucket['count'] ?? null ) || ! is_int( $bucket['until'] ?? null ) || $bucket['until'] <= $now ) { $bucket = array( 'count' => 0, 'until' => $now + $window ); }
			if ( $bucket['count'] >= $count ) { return false; }
			++$bucket['count'];
			return set_transient( $key, $bucket, max( 1, $bucket['until'] - $now ) );
		} finally { delete_option( $lock ); }
	}
}
