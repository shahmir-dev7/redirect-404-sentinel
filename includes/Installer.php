<?php
/**
 * Tables, versioned migrations, cron.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
class Installer {

	const DB_VERSION = '1';

	/**
	 * 404 log table name.
	 *
	 * @return string
	 */
	public static function table_404() {
		global $wpdb;
		return $wpdb->prefix . 'sentinel_404';
	}

	/**
	 * Redirects table name.
	 *
	 * @return string
	 */
	public static function table_redirects() {
		global $wpdb;
		return $wpdb->prefix . 'sentinel_redirects';
	}

	/**
	 * Activation hook, multisite aware.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Create/upgrade tables and schedule pruning for the current site.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table_404() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(191) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			last_seen datetime NOT NULL,
			referrer varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY path (path),
			KEY last_seen (last_seen)
		) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table_redirects() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(191) NOT NULL,
			target text NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source)
		) $charset;"
		);

		// Autoloaded on purpose: maybe_upgrade() reads it on every request, and a non-autoloaded read costs one query per page.
		update_option( 'r404s_db_version', self::DB_VERSION, true );
		if ( ! wp_next_scheduled( 'r404s_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'r404s_prune' );
		}
	}

	/**
	 * Run migrations when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'r404s_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Install tables for sites created after network activation.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function new_site( $site ): void {
		if ( is_plugin_active_for_network( plugin_basename( R404S_FILE ) ) ) {
			switch_to_blog( (int) $site->blog_id );
			self::install();
			restore_current_blog();
		}
	}

	/**
	 * Deactivation: stop cron (data is kept until uninstall).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'r404s_prune' );
	}
}
