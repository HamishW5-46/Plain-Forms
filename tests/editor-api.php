<?php
/** Transactional checks for the private React admin save endpoints. */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'PLAINFORMS_RUN_TESTS' ) ) { exit; }
define( 'DISABLE_WP_CRON', true );
require '/var/www/html/wp-load.php';
use PlainForms\{Capabilities, FormRepository, FormSchema, Settings};
use PlainForms\Admin\AdminApp;

set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
	if ( str_contains( $file, '/plain-forms/' ) ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
$checks = 0;
function editor_check( bool $value, string $message ): void {
	global $checks; if ( ! $value ) { throw new RuntimeException( $message ); } ++$checks; echo 'PASS: ', $message, PHP_EOL;
}
function editor_request( string $path, array $data ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/plain-forms/v1/admin/' . $path );
	$request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $data ) );
	return rest_get_server()->dispatch( $request );
}
$users = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $users ) { throw new RuntimeException( 'An administrator is required.' ); }
wp_set_current_user( (int) $users[0] );
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
$settings = new Settings(); $settings->register();
global $wpdb; $wpdb->query( 'START TRANSACTION' ); $ids = array();
try {
	$schema = ( new FormSchema() )->defaults();
	$schema['fields'] = array( array( 'id' => 'email', 'type' => 'email', 'label' => 'Email address', 'required' => true ) );
	$result = editor_request( 'forms', array( 'title' => 'React API test', 'config' => $schema ) );
	editor_check( 200 === $result->get_status(), 'Capable user can create a form through the private endpoint' );
	$form = $result->get_data()['form']; $id = (int) $form['id']; $ids[] = $id;
	editor_check( $id > 0 && 1 === count( $form['config']['fields'] ) && 'private' === get_post_status( $id ), 'Save response returns the normalised private form' );
	$result = editor_request( 'forms/' . $id, array( 'title' => 'Edited React API test', 'config' => $schema ) );
	editor_check( 200 === $result->get_status() && $id === $result->get_data()['form']['id'], 'Editing keeps the same form ID' );
	editor_check( 'Edited React API test' === ( new FormRepository() )->find( $id )['title'], 'REST edits persist in the existing repository' );
	$bad = $schema; $bad['fields'][] = $bad['fields'][0];
	editor_check( 400 === editor_request( 'forms/' . $id, array( 'title' => 'Invalid', 'config' => $bad ) )->get_status(), 'Duplicate field IDs rejected server-side' );
	editor_check( 400 === editor_request( 'forms', array( 'title' => '', 'config' => $schema ) )->get_status(), 'Blank form title rejected' );
	$bad = $schema; $bad['fields'][0]['_key'] = 'react-only';
	editor_check( 400 === editor_request( 'forms', array( 'title' => 'Invalid', 'config' => $bad ) )->get_status(), 'Unstripped editor metadata cannot enter the stored schema' );
	$seed = $settings->get(); $seed['turnstile_secret_key'] = 'saved-secret-test-marker';
	update_option( Settings::OPTION, $seed, false );
	$safe = AdminApp::settings();
	editor_check( ! array_key_exists( 'turnstile_secret_key', $safe['values'] ) && $safe['hasSecret'], 'Settings bootstrap contains a saved-secret flag but no secret field' );
	foreach ( array( true, false ) as $delete_on_uninstall ) {
		$payload = AdminApp::settings()['values'];
		$payload['turnstile_secret_key'] = '';
		$payload['clear_turnstile_secret'] = false;
		$payload['delete_on_uninstall'] = $delete_on_uninstall;
		$result = editor_request( 'settings', $payload );
		editor_check( 200 === $result->get_status() && $delete_on_uninstall === $settings->get()['delete_on_uninstall'] && 'saved-secret-test-marker' === $settings->get()['turnstile_secret_key'], 'Full React settings payload saves uninstall toggle ' . ( $delete_on_uninstall ? 'on' : 'off' ) . ' while preserving the saved secret' );
	}
	$result = editor_request( 'settings', array( 'sender_name' => 'A new sender', 'turnstile_secret_key' => '' ) );
	editor_check( 200 === $result->get_status() && 'saved-secret-test-marker' === $settings->get()['turnstile_secret_key'], 'Blank secret preserves the stored key during settings save' );
	editor_check( ! str_contains( wp_json_encode( $result->get_data() ), 'saved-secret-test-marker' ), 'Settings response never returns the saved key' );
	$result = editor_request( 'settings', array( 'turnstile_secret_key' => 'replacement-secret-test-marker' ) );
	editor_check( 200 === $result->get_status() && 'replacement-secret-test-marker' === $settings->get()['turnstile_secret_key'], 'Replacement secret is saved' );
	editor_check( ! str_contains( wp_json_encode( $result->get_data() ), 'replacement-secret-test-marker' ), 'A newly entered secret is also omitted from the response' );
	$result = editor_request( 'settings', array( 'clear_turnstile_secret' => true ) );
	editor_check( 200 === $result->get_status() && false === $result->get_data()['hasSecret'] && '' === $settings->get()['turnstile_secret_key'], 'Explicit clear flag removes the key' );
	foreach ( array( array( 'sender_email' => 'broken' ), array( 'recipients' => array( "safe@example.invalid\r\nBcc: injected@example.invalid" ) ), array( 'turnstile_hostname' => 'https://example.org/path' ), array( 'rate_limit_count' => 0 ), array( 'rate_limit_window' => '600' ), array( 'debug_logging' => '1' ), array( 'hasSecret' => true ) ) as $bad ) {
		$before = $settings->get(); $result = editor_request( 'settings', $bad );
		editor_check( 400 === $result->get_status() && isset( $result->get_data()['data']['fields'] ) && $before === $settings->get(), 'Invalid settings produce field errors without changing saved values' );
	}
	$result = editor_request( 'settings', array( 'success_message' => "Thanks.\nWe will be in touch.", 'retention_days' => 90 ) );
	editor_check( 200 === $result->get_status() && str_contains( $result->get_data()['values']['success_message'], "\n" ), 'Multiline confirmation and numeric settings round trip' );
	$deny = static function ( array $caps ): array { $caps[Capabilities::EDIT] = false; $caps[Capabilities::SETTINGS] = false; return $caps; };
	add_filter( 'user_has_cap', $deny );
	editor_check( 403 === editor_request( 'forms', array( 'title' => 'Denied', 'config' => $schema ) )->get_status(), 'Form editing requires its custom capability even for an otherwise privileged user' );
	editor_check( 403 === editor_request( 'settings', array( 'debug_logging' => true ) )->get_status(), 'Settings saving requires its custom capability' );
	remove_filter( 'user_has_cap', $deny );
	wp_set_current_user( 0 );
	editor_check( 401 === editor_request( 'forms', array( 'title' => 'Denied', 'config' => $schema ) )->get_status() && 401 === editor_request( 'settings', array() )->get_status(), 'Anonymous users cannot access either private save endpoint' );
	echo $checks, ' editor API checks passed. All writes will roll back.', PHP_EOL;
} finally { $wpdb->query( 'ROLLBACK' ); foreach ( $ids as $id ) { clean_post_cache( $id ); } }
