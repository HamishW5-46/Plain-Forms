<?php
/** Isolated contracts, not a substitute for testing against a disposable WordPress database. */
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	const ABSPATH = __DIR__ . '/fixtures/';
	const ARRAY_A = 'ARRAY_A';
	const DAY_IN_SECONDS = 86400;
	const HOUR_IN_SECONDS = 3600;
	function wp_next_scheduled( string $hook ): bool { return false; }
	function wp_schedule_event( mixed ...$args ): bool { return true; }
	function is_admin(): bool { return false; }
	final class WP_Error {
		public function __construct( public string $code, public string $message ) {}
	}
	$GLOBALS['options'] = array( 'admin_email' => 'admin@example.org' );
	$GLOBALS['allowed'] = true;
	$GLOBALS['posts'] = array();
	$GLOBALS['meta'] = array();
	$GLOBALS['hooks'] = array();
	function __( string $text, string $domain = '' ): string { return $text; }
	function esc_html__( string $text, string $domain = '' ): string { return $text; }
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][$key] ?? $default; }
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['options'][$key] = $value; $GLOBALS['autoload'][$key] = $autoload; return true; }
	function add_option( string $key, mixed $value, string $deprecated = '', mixed $autoload = null ): bool {
		if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return false; }
		return update_option( $key, $value, $autoload );
	}
	function current_user_can( string $cap ): bool { return $GLOBALS['allowed']; }
	function sanitize_text_field( string $text ): string { return trim( strip_tags( preg_replace( '/[\r\n\t]+/', ' ', $text ) ) ); }
	function sanitize_textarea_field( string $text ): string { return trim( strip_tags( $text ) ); }
	function sanitize_email( string $email ): string { return filter_var( $email, FILTER_SANITIZE_EMAIL ); }
	function is_email( string $email ): string|false { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
	function add_settings_error( mixed ...$args ): void { $GLOBALS['errors'][] = $args; }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function register_post_type( string $type, array $args ): void { $GLOBALS['cpt'] = $args; }
	function register_post_meta( string $type, string $key, array $args ): void { $GLOBALS['meta_registration'] = $args; }
	function get_post( int $id ): ?object { return $GLOBALS['posts'][$id] ?? null; }
	function get_post_meta( int $id, string $key, bool $single ): mixed { return $GLOBALS['meta'][$id] ?? ''; }
	function wp_slash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
	function wp_insert_post( array $args, bool $error ): int {
		$id = $args['ID'] ?: count( $GLOBALS['posts'] ) + 1;
		$GLOBALS['posts'][$id] = (object) array( 'post_type' => $args['post_type'], 'post_status' => $args['post_status'], 'post_title' => stripslashes( $args['post_title'] ) );
		$GLOBALS['meta'][$id] = array_map( static fn ( mixed $v ): mixed => is_string( $v ) ? stripslashes( $v ) : $v, $args['meta_input']['_plainforms_config'] );
		return $id;
	}
	function get_role( string $role ): object {
		return new class {
			public function add_cap( string $cap ): void { $GLOBALS['caps'][$cap] = true; }
		};
	}
	function add_action( string $hook, mixed $callback ): void { $GLOBALS['hooks'][$hook][] = $callback; }
	function add_filter( string $hook, mixed $callback ): void { $GLOBALS['hooks'][$hook][] = $callback; }
	function register_setting( string $group, string $name, array $args ): void { $GLOBALS['registered_settings'] = $args; }
	function wp_clear_scheduled_hook( string $hook ): void { $GLOBALS['cleared_hooks'][] = $hook; }
	$GLOBALS['wpdb'] = new class {
		public string $prefix = 'custom_';
		public bool $complete = true;
		public function prepare( string $query, mixed ...$args ): string { return $query; }
		public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
		public function get_col( string $sql ): array { return $this->complete ? array( 'id', 'form_id', 'created_at', 'updated_at', 'status', 'email_status', 'receipt_status', 'receipt_error_message', 'ip_hash', 'user_agent', 'referer', 'data', 'error_message' ) : array( 'id' ); }
		public function get_results( string $sql, string $format ): array { return array_map( static fn ( string $name ): array => array( 'Key_name' => $name ), array( 'PRIMARY', 'form_id', 'status', 'created_at', 'email_status', 'receipt_status' ) ); }
	};
	require dirname( __DIR__ ) . '/includes/Autoloader.php';
	\PlainForms\Autoloader::register( dirname( __DIR__ ) );
	$checks = 0;
	function check( bool $condition, string $label ): void {
		++$GLOBALS['checks'];
		if ( ! $condition ) { throw new \RuntimeException( $label ); }
		echo "PASS: {$label}\n";
	}
}
namespace PlainForms {
	$settings = new Settings();
	$settings->install();
	check( false === $GLOBALS['autoload'][Settings::OPTION], 'Settings are not autoloaded' );
	check( 365 === $settings->get()['retention_days'] && false === $settings->get()['delete_on_uninstall'], 'Privacy-preserving defaults' );
	$GLOBALS['options'][Settings::OPTION]['retention_days'] = 90;
	$settings->install();
	check( 90 === $settings->get()['retention_days'], 'Reinstallation preserves settings' );
	$GLOBALS['options'][Settings::OPTION]['turnstile_secret_key'] = 'saved-secret';
	check( 'saved-secret' === $settings->sanitize( array( 'turnstile_secret_key' => '' ) )['turnstile_secret_key'], 'Blank secret preserves existing value' );
	check( '' === $settings->sanitize( array( 'clear_turnstile_secret' => '1' ) )['turnstile_secret_key'], 'Explicit secret removal' );
	check( $settings->get()['recipients'] === $settings->sanitize( array( 'recipients' => array( "bad@example.org\r\nBcc: injected@example.org" ) ) )['recipients'], 'Recipient header injection rejected' );
	check( array( 'one@example.org', 'two@example.org' ) === $settings->sanitize( array( 'recipients' => 'one@example.org, two@example.org' ) )['recipients'], 'Multiple recipients accepted' );
	check( '' === $settings->sanitize( array( 'sender_email' => 'broken' ) )['sender_email'], 'Malformed sender rejected' );
	check( $settings->get()['subject'] === $settings->sanitize( array( 'subject' => "hello\nBcc: bad" ) )['subject'], 'Subject header injection rejected' );
	$bounded = $settings->sanitize( array( 'rate_limit_count' => 0, 'rate_limit_window' => 999999, 'retention_days' => 0, 'debug_logging' => 'false' ) );
	check( 1 === $bounded['rate_limit_count'] && 86400 === $bounded['rate_limit_window'] && 0 === $bounded['retention_days'] && false === $bounded['debug_logging'], 'Numeric bounds, forever retention and strict booleans' );
	check( ! array_key_exists( 'unknown', $settings->sanitize( array( 'unknown' => 'value' ) ) ), 'Unknown settings ignored' );
	$GLOBALS['allowed'] = false;
	check( $settings->get() === $settings->sanitize( array( 'delete_on_uninstall' => true ) ), 'Settings updates require capability' );
	$GLOBALS['allowed'] = true;
	foreach ( $settings->defaults() as $key => $default ) {
		if ( ! is_bool( $default ) ) { continue; }
		foreach ( array( true, false ) as $value ) {
			$validated = $settings->validate_update( array( $key => $value ) );
			check( ! is_wp_error( $validated ) && $validated[ $key ] === $value, 'Boolean setting ' . $key . ' accepts ' . ( $value ? 'true' : 'false' ) );
		}
	}
	foreach ( array( 1, 0, 'true', 'false', null, array() ) as $invalid_boolean ) {
		check( is_wp_error( $settings->validate_update( array( 'delete_on_uninstall' => $invalid_boolean ) ) ), 'Uninstall setting rejects non-boolean input' );
	}
	$GLOBALS['allowed'] = false;
	$repo = new FormRepository();
	check( is_wp_error( $repo->save( 'Example', ( new FormSchema() )->defaults() ) ), 'Form writes require capability' );
	$GLOBALS['allowed'] = true;
	$repo->register();
	check( false === $GLOBALS['cpt']['public'] && false === $GLOBALS['cpt']['show_in_rest'] && false === $GLOBALS['cpt']['show_ui'], 'Form CPT has no public or admin exposure' );
	check( Capabilities::EDIT === $GLOBALS['cpt']['capabilities']['edit_posts'], 'CPT uses custom capabilities' );
	check( true === $GLOBALS['meta_registration']['revisions_enabled'] && false === $GLOBALS['meta_registration']['show_in_rest'], 'Versioned config supports revisions and remains private' );
	$schema = new FormSchema();
	check( is_wp_error( $schema->validate( array( 'schema_version' => 2 ) ) ), 'Unknown schema versions fail closed' );
	check( is_wp_error( $schema->validate( array_replace( $schema->defaults(), array( 'fields' => array( array( 'id' => 'unvalidated' ) ) ) ) ) ), 'Incomplete field schemas are rejected' );
	check( is_wp_error( $schema->validate( array_replace( $schema->defaults(), array( 'enabled' => 'yes' ) ) ) ), 'Malformed schema types rejected' );
	check( is_wp_error( $repo->save( '  ', $schema->defaults() ) ), 'Blank titles rejected' );
	$id = $repo->save( 'Example', $schema->defaults() );
	check( is_int( $id ) && 'Example' === $repo->find( $id )['title'], 'Form creation and lookup round trip' );
	check( $id === $repo->save( 'Renamed', $schema->defaults(), $id ) && 'Renamed' === $repo->find( $id )['title'], 'Form updates retain ID' );
	$GLOBALS['posts'][99] = (object) array( 'post_type' => 'page', 'post_status' => 'private' );
	check( is_wp_error( $repo->save( 'Wrong type', $schema->defaults(), 99 ) ), 'Repository cannot overwrite another post type' );
	check( Activator::install(), 'Installer succeeds with verified table' );
	check( str_contains( $GLOBALS['test_schema_sql'], 'custom_plainforms_submissions' ) && ! str_contains( $GLOBALS['test_schema_sql'], 'ip_address' ), 'Installer uses site prefix and stores no raw IP column' );
	check( count( $GLOBALS['caps'] ) === 5, 'Administrator receives all five capabilities' );
	$GLOBALS['wpdb']->complete = false;
	$GLOBALS['options'][Database::VERSION_OPTION] = 'old';
	check( false === Database::install() && 'old' === get_option( Database::VERSION_OPTION ), 'Failed database verification never advances version' );
	$GLOBALS['wpdb']->complete = true;
	Activator::install();
	$before = $GLOBALS['options'];
	Deactivator::deactivate();
	check( $before === $GLOBALS['options'] && in_array( Deactivator::CLEANUP_HOOK, $GLOBALS['cleared_hooks'], true ), 'Deactivation clears cron and preserves data' );
	$settings->register();
	check( false === $GLOBALS['registered_settings']['show_in_rest'], 'Settings including secrets excluded from REST' );
	check( Capabilities::SETTINGS === $GLOBALS['hooks']['option_page_capability_plainforms_settings'][0](), 'Settings API uses custom capability' );
	( new Plugin() )->boot();
	check( isset( $GLOBALS['hooks']['init'], $GLOBALS['hooks']['admin_init'] ) && isset( $GLOBALS['hooks']['rest_api_init'] ), 'Lifecycle registers infrastructure and REST controllers' );
	require __DIR__ . '/components.php';
	echo "\n{$GLOBALS['checks']} checks passed.\n";
}
