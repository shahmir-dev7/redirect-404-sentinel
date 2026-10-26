<?php
/**
 * Redirect storage, loop detection and front-end handling.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects.
 */
class Redirects {

	/**
	 * Would source -> target_path close a loop, given existing source => target_path rules?
	 *
	 * @param string               $source      Normalised source path.
	 * @param string               $target_path Normalised local target path ('' when external).
	 * @param array<string,string> $map         Existing rules as source => local target path.
	 * @return bool
	 */
	public static function creates_loop( $source, $target_path, array $map ) {
		$seen = array();
		$next = $target_path;
		while ( '' !== $next ) {
			if ( $next === $source || isset( $seen[ $next ] ) ) {
				return true;
			}
			$seen[ $next ] = true;
			$next          = isset( $map[ $next ] ) ? $map[ $next ] : '';
		}
		return false;
	}

	/**
	 * Site base path and host.
	 *
	 * @return array{0:string,1:string} Base path, host.
	 */
	private static function site() {
		$home = wp_parse_url( home_url() );
		return array(
			isset( $home['path'] ) ? $home['path'] : '',
			isset( $home['host'] ) ? $home['host'] : '',
		);
	}

	/**
	 * Add a 301 rule.
	 *
	 * @param string $source_raw Source path.
	 * @param string $target_raw Target path or URL.
	 * @return true|\WP_Error
	 */
	public static function add( $source_raw, $target_raw ) {
		global $wpdb;
		list( $base, $host ) = self::site();
		$source              = Paths::normalize( $source_raw, $base );
		$target              = esc_url_raw( trim( (string) $target_raw ) );
		if ( '' === $source || '/' === $source ) {
			return new \WP_Error( 'r404s_source', __( 'Invalid source path.', 'redirect-404-sentinel' ) );
		}
		if ( '' === $target ) {
			return new \WP_Error( 'r404s_target', __( 'Invalid target.', 'redirect-404-sentinel' ) );
		}
		$target_path = Paths::local_path( $target, $host, $base );
		if ( $target_path === $source ) {
			return new \WP_Error( 'r404s_loop', __( 'Source and target are the same.', 'redirect-404-sentinel' ) );
		}
		if ( '' !== $target_path && self::creates_loop( $source, $target_path, self::local_map() ) ) {
			return new \WP_Error( 'r404s_loop', __( 'This redirect would create a loop.', 'redirect-404-sentinel' ) );
		}
		$table = Installer::table_redirects();
		$ok    = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (source, target, created) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE target = VALUES(target)', $table, $source, $target, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) {
			return new \WP_Error( 'r404s_db', __( 'Database error.', 'redirect-404-sentinel' ) );
		}
		delete_transient( 'r404s_map' );
		return true;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule ID.
	 */
	public static function delete( $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::table_redirects(), array( 'id' => (int) $id ), array( '%d' ) );
		delete_transient( 'r404s_map' );
	}

	/**
	 * All rules.
	 *
	 * @return array<int,object>
	 */
	public static function all() {
		global $wpdb;
		$table = Installer::table_redirects();
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, target FROM %i ORDER BY id DESC LIMIT 1000', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rules as source => local target path, for loop detection.
	 *
	 * @return array<string,string>
	 */
	private static function local_map() {
		list( $base, $host ) = self::site();
		$map                 = array();
		foreach ( self::all() as $row ) {
			$map[ $row->source ] = Paths::local_path( $row->target, $host, $base );
		}
		return $map;
	}

	/**
	 * Front end: runs only on 404 responses, so normal page views cost no queries.
	 */
	public static function handle_404(): void {
		if ( ! is_404() ) {
			return;
		}
		global $wpdb;
		list( $base ) = self::site();
		$path         = Paths::normalize( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', $base ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by Paths::normalize().
		if ( '' === $path ) {
			return;
		}
		$table  = Installer::table_redirects();
		$target = $wpdb->get_var( $wpdb->prepare( 'SELECT target FROM %i WHERE source = %s', $table, $path ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $target ) {
			// Targets are admin-authored and may be external, so wp_safe_redirect is not suitable.
			wp_redirect( $target, 301, 'Redirect-404-Sentinel' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
		/**
		 * Filters whether a 404 path is logged.
		 *
		 * @param bool   $should_log Default true.
		 * @param string $path       Normalised path.
		 */
		if ( ! apply_filters( 'r404s_should_log', true, $path ) ) {
			return;
		}
		Logger::log( $path, isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in Logger::log().
	}
}
