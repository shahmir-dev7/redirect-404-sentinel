<?php
/**
 * Admin screen and form handlers.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Admin {

	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_r404s_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_r404s_delete_redirect', array( __CLASS__, 'handle_delete_redirect' ) );
		add_action( 'admin_post_r404s_dismiss', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_post_r404s_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_r404s_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Tools > 404 Sentinel.
	 */
	public static function menu(): void {
		add_management_page( __( '404 Sentinel', 'redirect-404-sentinel' ), __( '404 Sentinel', 'redirect-404-sentinel' ), self::CAP, 'r404s', array( __CLASS__, 'render' ) );
	}

	/**
	 * Retention setting.
	 */
	public static function register_setting(): void {
		register_setting(
			'r404s',
			'r404s_retention_days',
			array(
				'type'              => 'integer',
				'default'           => 30,
				'sanitize_callback' => static function ( $value ) {
					return max( 1, min( 365, absint( $value ) ) );
				},
			)
		);
	}

	/**
	 * Common guard for handlers: capability + nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'redirect-404-sentinel' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the screen with a notice code.
	 *
	 * @param string $code Notice code (whitelisted in render()).
	 */
	private static function back( $code ): void {
		wp_safe_redirect( add_query_arg( 'r404s_msg', $code, admin_url( 'tools.php?page=r404s' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- every handler calls guard() (check_admin_referer) first.

	/**
	 * Create a redirect from a form.
	 */
	public static function handle_create(): void {
		self::guard( 'r404s_create' );
		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$result = Redirects::add( $source, $target );
		if ( is_wp_error( $result ) ) {
			self::back( 'loop' === substr( $result->get_error_code(), 6 ) ? 'loop' : 'invalid' );
		}
		self::back( 'created' );
	}

	/**
	 * Delete a redirect.
	 */
	public static function handle_delete_redirect(): void {
		self::guard( 'r404s_delete_redirect' );
		Redirects::delete( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::back( 'deleted' );
	}

	/**
	 * Remove a 404 log row.
	 */
	public static function handle_dismiss(): void {
		self::guard( 'r404s_dismiss' );
		Logger::delete( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::back( 'deleted' );
	}

	/**
	 * CSV export of redirects.
	 */
	public static function handle_export(): void {
		self::guard( 'r404s_export' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sentinel-redirects.csv"' );
		echo self::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells neutralised by Paths::csv_safe().
		exit;
	}

	/**
	 * Redirects as CSV text (header row, then source,target).
	 *
	 * @return string
	 */
	public static function export_csv(): string {
		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'source', 'target' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( Redirects::all() as $row ) {
			fputcsv( $out, array( Paths::csv_safe( $row->source ), Paths::csv_safe( $row->target ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * Import CSV lines (first line is the header). At most 5000 rows.
	 *
	 * @param string[] $lines CSV lines.
	 * @return int Redirects created.
	 */
	public static function import_lines( array $lines ): int {
		$rows = array_map( 'str_getcsv', $lines );
		array_shift( $rows );
		$count = 0;
		foreach ( array_slice( $rows, 0, 5000 ) as $row ) {
			if ( isset( $row[0], $row[1] ) && true === Redirects::add( sanitize_text_field( ltrim( $row[0], "'" ) ), sanitize_text_field( ltrim( $row[1], "'" ) ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * CSV import (source,target per row, first row header). Max 1 MB.
	 */
	public static function handle_import(): void {
		self::guard( 'r404s_import' );
		$tmp  = isset( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path from PHP, checked by is_uploaded_file().
		$size = isset( $_FILES['csv']['size'] ) ? (int) $_FILES['csv']['size'] : 0;
		if ( ! is_string( $tmp ) || '' === $tmp || ! is_uploaded_file( $tmp ) || $size > MB_IN_BYTES ) {
			self::back( 'invalid' );
		}
		self::back( 'imported-' . self::import_lines( (array) file( $tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'redirect-404-sentinel' ), '', array( 'response' => 403 ) );
		}
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$msg      = isset( $_GET['r404s_msg'] ) ? sanitize_key( wp_unslash( $_GET['r404s_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notices  = array(
			'created' => __( 'Redirect created.', 'redirect-404-sentinel' ),
			'deleted' => __( 'Removed.', 'redirect-404-sentinel' ),
			'loop'    => __( 'That redirect would create a loop.', 'redirect-404-sentinel' ),
			'invalid' => __( 'Invalid input.', 'redirect-404-sentinel' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( '404 Sentinel', 'redirect-404-sentinel' ) . '</h1>';
		if ( 0 === strpos( $msg, 'imported-' ) ) {
			/* translators: %d: number of imported redirects. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Imported %d redirects.', 'redirect-404-sentinel' ), (int) substr( $msg, 9 ) ) ) . '</p></div>';
		} elseif ( isset( $notices[ $msg ] ) ) {
			$class = in_array( $msg, array( 'loop', 'invalid' ), true ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $notices[ $msg ] ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Recent 404s', 'redirect-404-sentinel' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Path', 'redirect-404-sentinel' ) . '</th><th>' . esc_html__( 'Hits', 'redirect-404-sentinel' ) . '</th><th>' . esc_html__( 'Redirect to', 'redirect-404-sentinel' ) . '</th><th>' . esc_html__( 'Actions', 'redirect-404-sentinel' ) . '</th></tr></thead><tbody>';
		$rows = Logger::top( 100 );
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No 404s logged yet.', 'redirect-404-sentinel' ) . '</td></tr>';
		}
		foreach ( $rows as $i => $row ) {
			$suggest = $i < 20 ? Suggester::url_for( $row->path ) : '';
			$field   = 'r404s-target-' . (int) $row->id;
			echo '<tr><td><code>' . esc_html( $row->path ) . '</code></td><td>' . (int) $row->hits . '</td><td>';
			echo '<form method="post" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			wp_nonce_field( 'r404s_create' );
			echo '<input type="hidden" name="action" value="r404s_create"><input type="hidden" name="source" value="' . esc_attr( $row->path ) . '">';
			echo '<label class="screen-reader-text" for="' . esc_attr( $field ) . '">' . esc_html__( 'Target URL', 'redirect-404-sentinel' ) . '</label>';
			echo '<input type="text" class="regular-text" id="' . esc_attr( $field ) . '" name="target" value="' . esc_attr( $suggest ) . '" placeholder="/new-url"> ';
			echo '<button class="button button-primary">' . esc_html__( 'Create 301', 'redirect-404-sentinel' ) . '</button></form></td><td>';
			echo '<form method="post" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			wp_nonce_field( 'r404s_dismiss' );
			echo '<input type="hidden" name="action" value="r404s_dismiss"><input type="hidden" name="id" value="' . (int) $row->id . '"><button class="button">' . esc_html__( 'Dismiss', 'redirect-404-sentinel' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Redirects', 'redirect-404-sentinel' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( Redirects::all() as $row ) {
			echo '<tr><td><code>' . esc_html( $row->source ) . '</code> &rarr; ' . esc_html( $row->target ) . '</td><td>';
			echo '<form method="post" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			wp_nonce_field( 'r404s_delete_redirect' );
			echo '<input type="hidden" name="action" value="r404s_delete_redirect"><input type="hidden" name="id" value="' . (int) $row->id . '"><button class="button">' . esc_html__( 'Delete', 'redirect-404-sentinel' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Import / export', 'redirect-404-sentinel' ) . '</h2>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=r404s_export' ), 'r404s_export' ) ) . '">' . esc_html__( 'Export CSV', 'redirect-404-sentinel' ) . '</a></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'r404s_import' );
		echo '<input type="hidden" name="action" value="r404s_import"><label for="r404s-csv">' . esc_html__( 'CSV file', 'redirect-404-sentinel' ) . '</label> <input type="file" id="r404s-csv" name="csv" accept=".csv"> <button class="button">' . esc_html__( 'Import', 'redirect-404-sentinel' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Settings', 'redirect-404-sentinel' ) . '</h2><form method="post" action="options.php">';
		settings_fields( 'r404s' );
		echo '<label for="r404s_retention_days">' . esc_html__( 'Keep 404 log (days)', 'redirect-404-sentinel' ) . '</label> <input type="number" min="1" max="365" id="r404s_retention_days" name="r404s_retention_days" value="' . esc_attr( (string) Logger::retention_days() ) . '"> ';
		submit_button( __( 'Save', 'redirect-404-sentinel' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
}
