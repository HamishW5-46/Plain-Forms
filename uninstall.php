<?php
/** Uninstall honours each site's explicit opt-in. Deactivation never invokes this file. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
require_once __DIR__ . '/includes/Autoloader.php';
\PlainForms\Autoloader::register( __DIR__ );
$plainforms_cleanup = static function (): void {
	$settings = get_option( \PlainForms\Settings::OPTION, array() );
	wp_clear_scheduled_hook( \PlainForms\Deactivator::CLEANUP_HOOK );
	wp_clear_scheduled_hook( 'plainforms_cleanup_batch' );
	if ( true !== ( $settings['delete_on_uninstall'] ?? false ) ) { return; }
	global $wpdb;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Opt-in destructive uninstall must read current records and change schema; no cache is appropriate.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 100", \PlainForms\FormRepository::POST_TYPE ) );
		foreach ( $ids as $id ) {
			// Stop rather than loop indefinitely or drop remaining data if a deletion is vetoed.
			if ( ! wp_delete_post( (int) $id, true ) ) { return; }
		}
	} while ( count( $ids ) === 100 );
	$table = \PlainForms\Database::table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opt-in destructive uninstall must read current records and change schema; no cache is appropriate.
	if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { return; }
	foreach ( array( \PlainForms\Settings::OPTION, \PlainForms\Plugin::VERSION_OPTION, \PlainForms\Database::VERSION_OPTION ) as $option ) { delete_option( $option ); }
	foreach ( wp_roles()->role_objects as $role ) { foreach ( \PlainForms\Capabilities::all() as $cap ) { $role->remove_cap( $cap ); } }
	foreach ( \PlainForms\Capabilities::all() as $cap ) {
		foreach ( get_users( array( 'capability' => $cap, 'fields' => 'ID' ) ) as $id ) { ( new WP_User( $id ) )->remove_cap( $cap ); }
	}
	foreach ( array( '_transient_plainforms_rate_', '_transient_timeout_plainforms_rate_', 'plainforms_lock_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Opt-in destructive uninstall must read current records and change schema; no cache is appropriate.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		foreach ( $names as $name ) { delete_option( $name ); }
	}
};
if ( is_multisite() ) {
	for ( $plainforms_offset = 0; ; $plainforms_offset += 100 ) {
		$plainforms_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $plainforms_offset, 'orderby' => 'id', 'order' => 'ASC' ) );
		foreach ( $plainforms_sites as $plainforms_site ) { switch_to_blog( (int) $plainforms_site ); try { $plainforms_cleanup(); } finally { restore_current_blog(); } }
		if ( count( $plainforms_sites ) < 100 ) { break; }
	}
} else { $plainforms_cleanup(); }
