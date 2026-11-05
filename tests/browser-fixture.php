<?php
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'PLAINFORMS_RUN_TESTS' ) ) { exit; }
define( 'DISABLE_WP_CRON', true );
require '/var/www/html/wp-load.php';
$mode = $argv[1] ?? 'frontend';
if ( in_array( $mode, array( 'admin', 'new', 'settings', 'dashboard', 'forms-list', 'forms-empty' ), true ) ) { require_once ABSPATH . 'wp-admin/includes/admin.php'; }
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );
$settings = ( new \PlainForms\Settings() )->get();
$settings['turnstile_enabled'] = 'turnstile' === $mode;
$settings['turnstile_site_key'] = 'test-public-key';
$settings['turnstile_secret_key'] = 'test-secret-must-never-appear';
add_filter( 'pre_option_plainforms_settings', static fn () => $settings );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	$schema = ( new \PlainForms\FormSchema() )->defaults();
	$schema['description'] = 'Please send us an enquiry.';
	$schema['fields'] = array(
		array( 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'autocomplete' => 'name' ),
		array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'autocomplete' => 'email' ),
		array( 'id' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'autocomplete' => 'tel' ),
		array( 'id' => 'topic', 'type' => 'select', 'label' => 'Topic', 'options' => array( array( 'value' => 'contact', 'label' => 'Contact' ) ) ),
		array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
		array( 'id' => 'choices', 'type' => 'checkbox_group', 'label' => 'Interests', 'required' => true, 'options' => array( array( 'value' => 'one', 'label' => 'First interest' ), array( 'value' => 'two', 'label' => 'Second interest' ) ) ),
		array( 'id' => 'radio', 'type' => 'radio', 'label' => 'Contact preference', 'required' => true, 'options' => array( array( 'value' => 'email', 'label' => 'By email' ), array( 'value' => 'phone', 'label' => 'By phone' ) ) ),
		array( 'id' => 'consent', 'type' => 'consent', 'label' => 'I agree', 'required' => true ),
	);
	$id = ( new \PlainForms\FormRepository() )->save( 'Test enquiry', $schema );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture failed.' ); }
	$is_admin = in_array( $mode, array( 'admin', 'new', 'settings', 'dashboard', 'forms-list', 'forms-empty' ), true );
	if ( $is_admin ) {
		$_GET['page'] = array( 'admin' => 'plain-forms-edit', 'new' => 'plain-forms-edit', 'settings' => 'plain-forms-settings', 'dashboard' => 'plain-forms', 'forms-list' => 'plain-forms-list', 'forms-empty' => 'plain-forms-list' )[ $mode ];
		( new \PlainForms\Admin\Admin() )->assets();
	}
	if ( in_array( $mode, array( 'forms-list', 'forms-empty' ), true ) ) {
		( new \PlainForms\FormRepository() )->save( str_repeat( 'LongFormName', 12 ), $schema, $id );
		add_action( 'pre_get_posts', static function ( $query ) use ( $mode, $id ): void {
			if ( \PlainForms\FormRepository::POST_TYPE === $query->get( 'post_type' ) ) { $query->set( 'post__in', 'forms-empty' === $mode ? array( 0 ) : array( $id ) ); }
		} );
	}
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Plain Forms browser test</title>';
	if ( $is_admin ) { wp_print_styles( array( 'common', 'forms', 'buttons', 'list-tables', 'dashicons', 'plain-forms-admin' ) ); }
	else { echo '<link rel="stylesheet" href="/frontend.css">'; }
	echo '<style>body{margin:0;background:#f0f0f1;font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{padding:24px}</style></head><body class="wp-core-ui"><main>';
	if ( 'admin' === $mode || 'new' === $mode ) { ( new \PlainForms\Admin\FormEditPage() )->render( 'new' === $mode ? 0 : $id ); }
	elseif ( 'settings' === $mode ) { ( new \PlainForms\Admin\SettingsPage() )->render(); }
	elseif ( 'dashboard' === $mode ) { ( new \PlainForms\Admin\DashboardPage() )->render(); }
	elseif ( in_array( $mode, array( 'forms-list', 'forms-empty' ), true ) ) { ( new \PlainForms\Admin\FormsPage() )->render(); }
	else { echo '<h1>Contact test</h1>'; echo ( new \PlainForms\Renderer() )->render( $id ); }
	echo '</main>';
	if ( $is_admin ) { wp_print_scripts( array( in_array( $mode, array( 'forms-list', 'forms-empty' ), true ) ? 'plain-forms-admin' : 'plain-forms-admin-app' ) ); }
	else { echo '<script src="/frontend.js"></script>'; }
	echo '</body></html>';

} finally { $wpdb->query( 'ROLLBACK' ); if ( isset( $id ) && is_int( $id ) ) { clean_post_cache( $id ); } }
