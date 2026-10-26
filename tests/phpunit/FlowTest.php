<?php
/**
 * Flow tests: admin handlers, front-end 404 handling, screen output, CLI, installer.
 *
 * @package Sentinel404
 */

use Sentinel404\Admin;
use Sentinel404\Cli;
use Sentinel404\Installer;
use Sentinel404\Logger;
use Sentinel404\Redirects;
use Sentinel404\Suggester;

require_once __DIR__ . '/cli-stubs.php';

/**
 * Thrown by the wp_redirect trap so handlers that call exit can be observed.
 */
class R404s_Redirect_Caught extends RuntimeException {
	public $status = 0;
}

/**
 * Flow tests.
 */
class FlowTest extends WP_UnitTestCase {

	/**
	 * Clean state; make redirects observable.
	 */
	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::table_404() ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . Installer::table_redirects() ); // phpcs:ignore
		delete_transient( 'r404s_rows' );
		delete_transient( 'r404s_candidates' );
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				$e         = new R404s_Redirect_Caught( $location );
				$e->status = $status;
				throw $e;
			},
			10,
			2
		);
		$_POST = array();
		$this->set_permalink_structure( '/%postname%/' ); // Plain permalinks ignore the URL path, so nothing would 404.
	}

	/**
	 * Run a callable and return the redirect it attempted.
	 *
	 * @param callable $fn Callable.
	 * @return R404s_Redirect_Caught
	 */
	private function redirect_of( $fn ) {
		try {
			$fn();
		} catch ( R404s_Redirect_Caught $e ) {
			return $e;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * Become an admin with a valid nonce for the action.
	 *
	 * @param string $action Nonce action.
	 */
	private function as_admin( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
	}

	public function test_create_handler_creates_redirect_and_reports_loop_and_invalid() {
		$this->as_admin( 'r404s_create' );
		$_POST += array(
			'source' => '/old',
			'target' => '/new',
		);
		$this->assertStringContainsString( 'r404s_msg=created', $this->redirect_of( array( Admin::class, 'handle_create' ) )->getMessage() );
		$this->assertCount( 1, Redirects::all() );

		$_POST['source'] = '/new';
		$_POST['target'] = '/old';
		$this->assertStringContainsString( 'r404s_msg=loop', $this->redirect_of( array( Admin::class, 'handle_create' ) )->getMessage() );

		$_POST['source'] = '/';
		$this->assertStringContainsString( 'r404s_msg=invalid', $this->redirect_of( array( Admin::class, 'handle_create' ) )->getMessage() );
	}

	public function test_delete_and_dismiss_handlers() {
		Redirects::add( '/a', '/b' );
		Logger::log( '/gone' );
		$this->as_admin( 'r404s_delete_redirect' );
		$_POST['id'] = Redirects::all()[0]->id;
		$this->redirect_of( array( Admin::class, 'handle_delete_redirect' ) );
		$this->assertCount( 0, Redirects::all() );

		$this->as_admin( 'r404s_dismiss' );
		$_POST['id'] = Logger::top()[0]->id;
		$this->redirect_of( array( Admin::class, 'handle_dismiss' ) );
		$this->assertCount( 0, Logger::top() );
	}

	public function test_import_handler_rejects_missing_upload() {
		$this->as_admin( 'r404s_import' );
		$this->assertStringContainsString( 'r404s_msg=invalid', $this->redirect_of( array( Admin::class, 'handle_import' ) )->getMessage() );
	}

	public function test_export_and_import_roundtrip_and_formula_safety() {
		global $wpdb;
		Redirects::add( '/a', '/b' );
		// Bypass Redirects::add (esc_url_raw would prefix a scheme) to prove export is defensive on its own.
		$wpdb->insert( Installer::table_redirects(), array( 'source' => '/evil', 'target' => '=HYPERLINK("http://x")', 'created' => '2026-01-01 00:00:00' ) ); // phpcs:ignore
		$csv = Admin::export_csv();
		$this->assertStringStartsWith( "source,target\n", $csv );
		$this->assertStringContainsString( "\"'=HYPERLINK", $csv, 'Leading formula characters are neutralised.' );

		$wpdb->query( 'DELETE FROM ' . Installer::table_redirects() ); // phpcs:ignore
		$lines = array_filter( explode( "\n", $csv ) );
		$this->assertSame( 1, Admin::import_lines( array_merge( $lines, array( '/x,/x', 'bad' ) ) ), 'Only /a is valid: the formula target, the self-redirect and the short row are all rejected.' );
	}

	public function test_screen_escapes_output_and_suggests_closest_page() {
		self::factory()->post->create(
			array(
				'post_name'   => 'about-us',
				'post_status' => 'publish',
			)
		);
		Logger::log( '/old/about-uss' );
		Logger::log( '/<script>alert(1)</script>' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'about-us', $html, 'Suggestion is pre-filled.' );
		$_GET['r404s_msg'] = 'imported-3';
		ob_start();
		Admin::render();
		$this->assertStringContainsString( 'Imported 3', ob_get_clean() );
	}

	public function test_screen_denies_non_admins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'denied' );
		} );
		$this->expectException( RuntimeException::class );
		Admin::render();
	}

	public function test_menu_and_setting_clamp() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Admin::menu();
		Admin::register_setting();
		update_option( 'r404s_retention_days', '9999' );
		$this->assertSame( 365, Logger::retention_days() );
		update_option( 'r404s_retention_days', '0' );
		$this->assertSame( 1, Logger::retention_days() );
	}

	public function test_front_end_redirects_known_404_and_logs_unknown() {
		Redirects::add( '/old', '/new' );
		$this->go_to( '/old' );
		$this->assertTrue( is_404() );
		$caught = $this->redirect_of( array( Redirects::class, 'handle_404' ) );
		$this->assertSame( 301, $caught->status );
		$this->assertStringContainsString( '/new', $caught->getMessage() );

		$this->go_to( '/nothing-here/?utm=1' );
		Redirects::handle_404();
		$this->assertSame( array( '/nothing-here' ), wp_list_pluck( Logger::top(), 'path' ) );
	}

	public function test_should_log_filter_and_non_404_are_respected() {
		add_filter( 'r404s_should_log', '__return_false' );
		$this->go_to( '/skipped' );
		Redirects::handle_404();
		$this->assertCount( 0, Logger::top() );
		remove_filter( 'r404s_should_log', '__return_false' );

		$this->go_to( '/' );
		Redirects::handle_404();
		$this->assertCount( 0, Logger::top(), 'Not a 404: nothing logged.' );
	}

	public function test_suggester_uses_filterable_threshold() {
		$id = self::factory()->post->create(
			array(
				'post_name'   => 'contact-page',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( get_permalink( $id ), Suggester::url_for( '/contact-pag' ) );
		add_filter( 'r404s_min_similarity', static fn() => 99 );
		$this->assertSame( '', Suggester::url_for( '/contact-pag' ) );
	}

	public function test_installer_upgrade_deactivate_and_new_site() {
		delete_option( 'r404s_db_version' );
		Installer::maybe_upgrade();
		$this->assertSame( Installer::DB_VERSION, get_option( 'r404s_db_version' ) );
		Installer::activate( false );
		Installer::new_site( (object) array( 'blog_id' => 1 ) );
		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( 'r404s_prune' ) );
	}

	public function test_cli_commands() {
		Logger::log( '/a' );
		Logger::log( '/b' );
		$cli = new Cli();
		$cli->list_( array(), array( 'limit' => '1' ) );
		$this->assertCount( 1, $GLOBALS['r404s_cli_items'] );
		$cli->redirect( array( '/a', '/b' ) );
		$this->assertCount( 1, Redirects::all() );
		$cli->prune();
		$this->expectException( RuntimeException::class );
		$cli->redirect( array( '/b', '/a' ) ); // Loop.
	}
}
