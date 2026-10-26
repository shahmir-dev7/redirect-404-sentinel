<?php
/**
 * Integration tests: DB layer, capability/nonce enforcement, retention, uninstall.
 *
 * @package Sentinel404
 */

use Sentinel404\Admin;
use Sentinel404\Installer;
use Sentinel404\Logger;
use Sentinel404\Redirects;

/**
 * Integration tests.
 */
class IntegrationTest extends WP_UnitTestCase {

	/**
	 * Fresh tables for each test.
	 */
	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::table_404() ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . Installer::table_redirects() ); // phpcs:ignore
		delete_transient( 'r404s_rows' );
	}

	public function test_install_creates_tables_and_schedules_prune() {
		global $wpdb;
		$this->assertSame( Installer::table_404(), $wpdb->get_var( "SHOW TABLES LIKE '" . Installer::table_404() . "'" ) ); // phpcs:ignore
		$this->assertSame( Installer::table_redirects(), $wpdb->get_var( "SHOW TABLES LIKE '" . Installer::table_redirects() . "'" ) ); // phpcs:ignore
		$this->assertNotFalse( wp_next_scheduled( 'r404s_prune' ) );
	}

	public function test_log_counts_hits_per_path() {
		Logger::log( '/gone', 'https://example.org/ref' );
		Logger::log( '/gone' );
		$rows = Logger::top();
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, (int) $rows[0]->hits );
	}

	public function test_log_is_capped_but_existing_paths_still_count() {
		add_filter( 'r404s_max_rows', static fn() => 2 );
		Logger::log( '/a' );
		Logger::log( '/b' );
		delete_transient( 'r404s_rows' );
		Logger::log( '/c' ); // Over cap: not stored.
		Logger::log( '/a' ); // Existing: counted.
		$paths = wp_list_pluck( Logger::top(), 'hits', 'path' );
		$this->assertArrayNotHasKey( '/c', $paths );
		$this->assertSame( 2, (int) $paths['/a'] );
	}

	public function test_prune_deletes_only_old_rows() {
		global $wpdb;
		Logger::log( '/old' );
		Logger::log( '/new' );
		$wpdb->update( Installer::table_404(), array( 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'path' => '/old' ) );
		$this->assertSame( 1, Logger::prune() );
		$this->assertSame( array( '/new' ), wp_list_pluck( Logger::top(), 'path' ) );
	}

	public function test_add_redirect_rejects_loops_and_self_redirects() {
		$this->assertTrue( Redirects::add( '/a', '/b' ) );
		$this->assertTrue( Redirects::add( '/b', '/c' ) );
		$this->assertWPError( Redirects::add( '/c', '/a' ), 'a->b->c->a is a loop' );
		$this->assertWPError( Redirects::add( '/x', '/x' ) );
		$this->assertWPError( Redirects::add( '/', '/x' ) );
		$this->assertTrue( Redirects::add( '/c', 'https://external.example/a' ), 'external target cannot loop' );
	}

	public function test_add_redirect_upserts_same_source() {
		Redirects::add( '/a', '/b' );
		Redirects::add( '/a', '/z' );
		$all = Redirects::all();
		$this->assertCount( 1, $all );
		$this->assertStringContainsString( '/z', $all[0]->target );
	}

	/**
	 * Run an admin-post handler and return the wp_die message (or '' when it did not die).
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	private function die_message( $handler ) {
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( is_string( $message ) ? $message : 'die' );
		} );
		try {
			$handler();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_handlers_reject_users_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['_wpnonce'] = wp_create_nonce( 'r404s_create' );
		$_POST['source']   = '/a';
		$_POST['target']   = '/b';
		$msg               = $this->die_message( array( Admin::class, 'handle_create' ) );
		$this->assertStringContainsString( 'permission', $msg );
		$this->assertCount( 0, Redirects::all(), 'No redirect may be created.' );
	}

	public function test_handlers_reject_missing_or_bad_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['source'] = '/a';
		$_POST['target'] = '/b';
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		$this->assertNotSame( '', $this->die_message( array( Admin::class, 'handle_create' ) ) );
		$_REQUEST['_wpnonce'] = 'bad';
		$this->assertNotSame( '', $this->die_message( array( Admin::class, 'handle_create' ) ) );
		$this->assertCount( 0, Redirects::all() );
	}

	public function test_uninstall_removes_tables_and_options() {
		global $wpdb;
		update_option( 'r404s_retention_days', 10 );
		// The suite turns DROP TABLE into DROP TEMPORARY TABLE; uninstall must drop the real tables.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		define( 'WP_UNINSTALL_PLUGIN', 'redirect-404-sentinel/redirect-404-sentinel.php' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->assertNull( $wpdb->get_var( "SHOW TABLES LIKE '" . Installer::table_404() . "'" ) ); // phpcs:ignore
		$this->assertNull( $wpdb->get_var( "SHOW TABLES LIKE '" . Installer::table_redirects() . "'" ) ); // phpcs:ignore
		$this->assertFalse( get_option( 'r404s_retention_days' ) );
		$this->assertFalse( wp_next_scheduled( 'r404s_prune' ) );
	}
}
