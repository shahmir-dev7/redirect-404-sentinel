<?php
/**
 * Hook wiring.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'wp_initialize_site', array( Installer::class, 'new_site' ), 20 );
		add_action( 'template_redirect', array( Redirects::class, 'handle_404' ), 1 );
		add_action(
			'r404s_prune',
			static function (): void {
				Logger::prune();
			}
		);

		if ( is_admin() ) {
			Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sentinel', Cli::class );
		}
	}
}
