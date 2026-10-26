<?php
/**
 * Remove all plugin data (per site on multisite).
 *
 * @package Sentinel404
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function r404s_uninstall_site(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sentinel_404, {$wpdb->prefix}sentinel_redirects" );
	delete_option( 'r404s_db_version' );
	delete_option( 'r404s_retention_days' );
	delete_transient( 'r404s_candidates' );
	delete_transient( 'r404s_rows' );
	wp_clear_scheduled_hook( 'r404s_prune' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $r404s_blog_id ) {
		switch_to_blog( (int) $r404s_blog_id );
		r404s_uninstall_site();
		restore_current_blog();
	}
} else {
	r404s_uninstall_site();
}
