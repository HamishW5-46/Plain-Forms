<?php
namespace PlainForms\Admin;
use PlainForms\{Database, FormRepository};

final class DashboardPage {
	public function render(): void {
		global $wpdb; $table = Database::table();
		$tz = wp_timezone(); $utc = new \DateTimeZone( 'UTC' ); $now = new \DateTimeImmutable( 'now', $tz );
		$today = $now->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$month = $now->modify( 'first day of this month' )->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live aggregate over the dedicated submissions table for an authorised admin dashboard.
		$stats = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(created_at >= %s) AS today, SUM(created_at >= %s) AS month, SUM(status = 'new') AS unread, SUM(status = 'spam') AS spam, SUM(email_status = 'failed' OR receipt_status = 'failed') AS failures FROM %i", $today, $month, $table ), ARRAY_A ) ?: array();
		AdminApp::render( 'dashboard', array(
			'stats' => array( 'forms' => count( ( new FormRepository() )->all() ), 'today' => (int) ( $stats['today'] ?? 0 ), 'month' => (int) ( $stats['month'] ?? 0 ), 'unread' => (int) ( $stats['unread'] ?? 0 ), 'spam' => (int) ( $stats['spam'] ?? 0 ), 'failures' => (int) ( $stats['failures'] ?? 0 ) ),
			'defaults' => AdminApp::defaults(),
		) );
	}
}
