<?php
/**
 * Bootstrap for pure-logic unit tests (no WordPress needed).
 *
 * @package Sentinel404
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal stand-in for the one WP function the pure helpers call.
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stand-in for wp_parse_url().
	 *
	 * @param string $url URL.
	 * @return array|false
	 */
	function wp_parse_url( $url ) {
		return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/Paths.php';
require_once dirname( __DIR__, 2 ) . '/includes/Suggester.php';
require_once dirname( __DIR__, 2 ) . '/includes/Redirects.php';
