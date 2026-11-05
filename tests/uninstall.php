<?php
/** Isolated uninstall boundary test. Never loads a real WordPress database. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'WP_UNINSTALL_PLUGIN', true );
$delete = 'delete' === ( $argv[1] ?? '' );
$events = array();
function get_option( mixed ...$args ): array { return array( 'delete_on_uninstall' => $GLOBALS['delete'] ); }
function wp_clear_scheduled_hook( string $hook ): void { $GLOBALS['events'][] = 'cron'; }
function wp_delete_post( int $id, bool $force ): object { $GLOBALS['events'][] = 'post'; return (object) array( 'ID' => $id ); }
function delete_option( string $name ): void { $GLOBALS['events'][] = 'option'; }
function is_multisite(): bool { return false; }
function wp_roles(): object { return (object) array( 'role_objects' => array( new class { public function remove_cap( string $cap ): void { $GLOBALS['events'][] = 'cap'; } } ) ); }
function get_users( array $args ): array { return array(); }
$wpdb = new class {
	public string $prefix = 'custom_'; public string $posts = 'custom_posts'; public string $options = 'custom_options';
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) { $query = (string) preg_replace( '/%[isd]/', (string) $arg, $query, 1 ); }
		return $query;
	}
	public function esc_like( string $value ): string { return $value; }
	public function get_col( string $sql ): array { return str_contains( $sql, 'SELECT ID' ) ? array( 123, 124 ) : array(); }
	public function query( string $sql ): int { if ( ! str_contains( $sql, 'custom_plainforms_submissions' ) ) { throw new RuntimeException( 'Unexpected SQL.' ); } $GLOBALS['events'][] = 'drop'; return 1; }
};
require dirname( __DIR__ ) . '/uninstall.php';
if ( 2 !== count( array_filter( $events, static fn( $v ) => 'cron' === $v ) ) ) { throw new RuntimeException( 'Cron not cleared.' ); }
if ( $delete ) {
	if ( ! in_array( 'drop', $events, true ) || ! in_array( 'post', $events, true ) || ! in_array( 'cap', $events, true ) || ! in_array( 'option', $events, true ) ) { throw new RuntimeException( 'Incomplete cleanup.' ); }
} elseif ( count( $events ) !== 2 ) { throw new RuntimeException( 'Default uninstall deleted data.' ); }
echo 'PASS: ', $delete ? 'Explicit uninstall removes plugin data/capabilities' : 'Default uninstall preserves data', PHP_EOL;
