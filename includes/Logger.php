<?php
/**
 * 404 log storage and retention.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Logger. Stores path, hit count, last seen and a truncated referrer. No IPs or user agents.
 */
class Logger {

	/**
	 * Record one 404 hit.
	 *
	 * @param string $path     Normalised path.
	 * @param string $referrer Raw referrer.
	 */
	public static function log( $path, $referrer = '' ): void {
		global $wpdb;
		$table    = Installer::table_404();
		$referrer = substr( esc_url_raw( (string) $referrer ), 0, 191 );

		// Cap table growth: a bot requesting random URLs must not fill the database.
		$rows = get_transient( 'r404s_rows' );
		if ( false === $rows ) {
			$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			set_transient( 'r404s_rows', $rows, MINUTE_IN_SECONDS );
		}
		/**
		 * Filters the maximum number of distinct 404 paths kept. Once reached,
		 * only hits on already-logged paths are counted.
		 *
		 * @param int $max Default 10000.
		 */
		if ( (int) $rows >= (int) apply_filters( 'r404s_max_rows', 10000 ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s WHERE path = %s', $table, current_time( 'mysql', true ), $path ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (path, hits, last_seen, referrer) VALUES (%s, 1, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = VALUES(referrer)', $table, $path, current_time( 'mysql', true ), $referrer ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Most-hit 404s.
	 *
	 * @param int $limit Row limit.
	 * @return array<int,object>
	 */
	public static function top( $limit = 100 ) {
		global $wpdb;
		$table = Installer::table_404();
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, path, hits, last_seen, referrer FROM %i ORDER BY hits DESC, last_seen DESC LIMIT %d', $table, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete one log row.
	 *
	 * @param int $id Row ID.
	 */
	public static function delete( $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::table_404(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Retention window in days (1-365).
	 *
	 * @return int
	 */
	public static function retention_days() {
		return max( 1, min( 365, (int) get_option( 'r404s_retention_days', 30 ) ) );
	}

	/**
	 * Delete rows not seen within the retention window.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;
		$table  = Installer::table_404();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
