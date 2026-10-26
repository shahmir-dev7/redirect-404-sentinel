<?php
/**
 * WP-CLI commands.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Manage 404 Sentinel.
 */
class Cli {

	/**
	 * List the most-hit 404s.
	 *
	 * @subcommand list
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Rows to show. Default 20.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function list_( $args, $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		\WP_CLI\Utils\format_items( 'table', Logger::top( $limit ), array( 'path', 'hits', 'last_seen' ) );
	}

	/**
	 * Create a 301 redirect.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Path to redirect from.
	 *
	 * <target>
	 * : Path or URL to redirect to.
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function redirect( $args ): void {
		$result = Redirects::add( $args[0], $args[1] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Redirect created.' );
	}

	/**
	 * Delete log rows past the retention window.
	 */
	public function prune(): void {
		\WP_CLI::success( sprintf( 'Deleted %d rows.', Logger::prune() ) );
	}
}
