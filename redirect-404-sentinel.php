<?php
/**
 * Plugin Name:       Redirect & 404 Sentinel
 * Description:       Logs 404 errors, suggests the closest matching page and creates 301 redirects in one click, with loop detection and CSV import/export.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            shahmir-dev7
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redirect-404-sentinel
 * Domain Path:       /languages
 *
 * @package Sentinel404
 */

defined( 'ABSPATH' ) || exit;

define( 'R404S_VERSION', '1.0.0' );
define( 'R404S_FILE', __FILE__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Sentinel404\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Sentinel404\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sentinel404\\Installer', 'deactivate' ) );

Sentinel404\Plugin::init();
