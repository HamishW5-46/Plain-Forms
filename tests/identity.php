<?php
/** Read-only registration checks against an activated WordPress plugin. */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'PLAINFORMS_RUN_TESTS' ) ) { exit; }
define( 'DISABLE_WP_CRON', true );
require '/var/www/html/wp-load.php';
use PlainForms\{Capabilities, Database, Deactivator, FormRepository, Plugin, Settings};
$metadata = get_file_data( dirname( __DIR__ ) . '/plain-forms.php', array( 'name' => 'Plugin Name', 'domain' => 'Text Domain' ) );
$checks = array(
 'Plugin header uses the new public identity' => $metadata['name'] === 'Plain Forms' && $metadata['domain'] === 'plain-forms',
 'Private form post type registered' => post_type_exists( 'plainforms_form' ) && ! get_post_type_object( 'plainforms_form' )->public,
 'Dynamic block registered' => WP_Block_Type_Registry::get_instance()->is_registered( 'plain-forms/form' ),
 'Shortcode registered' => shortcode_exists( 'plainforms_form' ),
 'Submission table uses the site prefix' => Database::table() === $wpdb->prefix . 'plainforms_submissions',
 'Fresh submission table installed' => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Database::table() ) ) ) === Database::table(),
 'Settings use the new identity' => Settings::OPTION === 'plainforms_settings' && Settings::GROUP === 'plainforms_settings' && is_array( get_option( Settings::OPTION ) ),
 'Version recorded' => get_option( Plugin::VERSION_OPTION ) === Plugin::VERSION,
 'Retention scheduled' => false !== wp_next_scheduled( Deactivator::CLEANUP_HOOK ),
 'Capability prefix consistent' => count( array_filter( Capabilities::all(), static fn( string $cap ): bool => str_starts_with( $cap, 'plainforms_' ) ) ) === 5,
 'REST submission route registered' => isset( rest_get_server()->get_routes()['/plain-forms/v1/forms/(?P<id>\\d+)/submit'] ),
 'Turnstile callback uses JS namespace' => str_contains( wp_scripts()->registered['plainforms-turnstile']->src, 'plainFormsTurnstileReady' ),
 'Schema structure version preserved' => Database::VERSION === '2',
 'Frontend assets stay unqueued on an unrelated request' => ! wp_script_is( 'plain-forms', 'enqueued' ) && ! wp_style_is( 'plain-forms', 'enqueued' ),
 'React admin assets stay unqueued on an unrelated request' => ! wp_script_is( 'plain-forms-admin-app', 'enqueued' ) && ! wp_script_is( 'plain-forms-admin', 'enqueued' ) && ! wp_style_is( 'plain-forms-admin', 'enqueued' ),
 'Activation entry uses the new bootstrap' => in_array( 'plain-forms/plain-forms.php', get_option( 'active_plugins', array() ), true ),
 'Administrator capabilities installed' => ! array_diff( Capabilities::all(), array_keys( array_filter( get_role( 'administrator' )->capabilities ) ) ),
 'Private verification test route registered' => isset( rest_get_server()->get_routes()['/plain-forms/v1/admin/turnstile-test'] ),
 'Form metadata uses private prefix' => FormRepository::META_KEY === '_plainforms_config',
);
foreach ( $checks as $label => $passed ) { if ( ! $passed ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
echo count( $checks ) . " identity checks passed.\n";
