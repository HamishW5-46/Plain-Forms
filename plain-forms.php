<?php
/**
 * Plugin Name: Plain Forms
 * Plugin URI: https://github.com/HamishW5-46/Plain-Forms
 * Description: Forms, plain and simple. A free, no-subscription contact and enquiry form builder with stored submissions, notifications and spam protection.
 * Version: 0.13.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Hamish Wright
 * Text Domain: plain-forms
 * Domain Path: /languages
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace PlainForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The same autoloader is used by the isolated test harness.
require_once __DIR__ . '/includes/Autoloader.php';
Autoloader::register( __DIR__ );

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
add_action( 'plugins_loaded', static function (): void {
	( new Plugin() )->boot();
} );
