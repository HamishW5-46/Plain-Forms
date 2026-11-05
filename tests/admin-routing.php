<?php
/** Read-only WordPress admin routing regression; never creates forms or changes capabilities. */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'PLAINFORMS_RUN_TESTS' ) ) { exit; }
define( 'DISABLE_WP_CRON', true );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
use PlainForms\Admin\Admin;
use PlainForms\Capabilities;

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $administrators ) { throw new RuntimeException( 'An administrator is required for this check.' ); }
wp_set_current_user( (int) $administrators[0] );
$checks = 0;
foreach ( array( true, false ) as $allowed ) {
	$capabilities = static function ( array $caps ) use ( $allowed ): array {
		$caps[ Capabilities::EDIT ] = $allowed;
		$caps[ Capabilities::VIEW ] = $allowed;
		return $caps;
	};
	add_filter( 'user_has_cap', $capabilities );
	foreach ( array( 'plain-forms-edit', 'plain-forms-view' ) as $page ) {
		// WordPress builds these tables anew for each user/request before checking access.
		foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_submenu_nopriv', '_wp_menu_nopriv', '_wp_real_parent_file' ) as $name ) { $GLOBALS[ $name ] = array(); }
		$GLOBALS['pagenow'] = 'admin.php'; $GLOBALS['plugin_page'] = $page;
		$GLOBALS['parent_file'] = ''; $GLOBALS['typenow'] = '';
		$_GET = array( 'page' => $page );
		$admin = new Admin(); $admin->menu();
		$accessible = user_can_access_admin_page();
		if ( $accessible !== $allowed ) { throw new RuntimeException( $page . ': incorrect access for ' . ( $allowed ? 'authorised' : 'unauthorised' ) . ' user.' ); }
		++$checks; echo 'PASS: ', $page, $allowed ? ' authorises capable user' : ' denies missing capability', PHP_EOL;
		if ( $allowed ) {
			if ( ! get_plugin_page_hook( $page, 'admin.php' ) ) { throw new RuntimeException( 'Page callback does not resolve.' ); }
			++$checks; echo 'PASS: ', $page, ' callback resolves through admin.php', PHP_EOL;
			$visible = array_column( $GLOBALS['submenu']['plain-forms'] ?? array(), 2 );
			if ( in_array( $page, $visible, true ) ) { throw new RuntimeException( 'Detail page appeared in main submenu.' ); }
			++$checks; echo 'PASS: ', $page, ' stays out of main submenu', PHP_EOL;
			if ( 'plain-forms-edit' === $page ) {
				ob_start(); $admin->edit(); $html = ob_get_clean();
				if ( ! str_contains( $html, 'id="plainforms-admin-app"' ) ) { throw new RuntimeException( 'Add Form editor did not render.' ); }
				++$checks; echo 'PASS: Add Form editor renders through authorised page callback', PHP_EOL;
			}
		}
	}
	remove_filter( 'user_has_cap', $capabilities );
}
echo $checks, ' admin routing checks passed.', PHP_EOL;
