<?php
/**
 * Request path normalisation (pure helpers).
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Paths.
 */
class Paths {

	const MAX_LENGTH = 190;

	/**
	 * Normalise a request URI or path to a comparable key: no query, no
	 * subdirectory base, leading slash, no trailing slash, lowercase.
	 *
	 * @param string $uri  Request URI or path.
	 * @param string $base Site base path (e.g. "/blog"), empty for root installs.
	 * @return string Empty string when unusable.
	 */
	public static function normalize( $uri, $base = '' ) {
		$path = (string) strtok( (string) $uri, '?#' );
		$path = rawurldecode( $path );
		$base = rtrim( (string) $base, '/' );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		// Control characters never belong in a stored path.
		$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		$path = '/' . trim( strtolower( $path ), '/' );
		return strlen( $path ) > self::MAX_LENGTH ? '' : $path;
	}

	/**
	 * Path of a redirect target when it stays on this host, else empty.
	 *
	 * @param string $target Target path or URL.
	 * @param string $host   This site's host.
	 * @param string $base   Site base path.
	 * @return string
	 */
	public static function local_path( $target, $host, $base = '' ) {
		$target = (string) $target;
		if ( 0 === strpos( $target, '/' ) && 0 !== strpos( $target, '//' ) ) {
			return self::normalize( $target, $base );
		}
		$parts = wp_parse_url( $target );
		if ( ! empty( $parts['host'] ) && 0 === strcasecmp( $parts['host'], $host ) ) {
			return self::normalize( isset( $parts['path'] ) ? $parts['path'] : '/', $base );
		}
		return '';
	}

	/**
	 * Neutralise spreadsheet formula injection in a CSV cell.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}
}
