<?php
/**
 * Pure-logic tests: paths, suggestions, loop detection, CSV safety.
 *
 * @package Sentinel404
 */

use PHPUnit\Framework\TestCase;
use Sentinel404\Paths;
use Sentinel404\Redirects;
use Sentinel404\Suggester;

/**
 * @covers \Sentinel404\Paths
 * @covers \Sentinel404\Suggester
 * @covers \Sentinel404\Redirects::creates_loop
 */
class PureLogicTest extends TestCase {

	public function test_normalize_strips_query_slash_case_and_base() {
		$this->assertSame( '/old-page', Paths::normalize( '/Old-Page/?utm=1#x' ) );
		$this->assertSame( '/old-page', Paths::normalize( '/blog/old-page/', '/blog' ) );
		$this->assertSame( '/', Paths::normalize( '/' ) );
	}

	public function test_normalize_rejects_overlong_and_strips_control_chars() {
		$this->assertSame( '', Paths::normalize( '/' . str_repeat( 'a', 200 ) ) );
		$this->assertSame( '/ab', Paths::normalize( "/a%00b" ) );
	}

	public function test_local_path() {
		$this->assertSame( '/new', Paths::local_path( '/new/', 'example.com' ) );
		$this->assertSame( '/new', Paths::local_path( 'https://example.com/new', 'example.com' ) );
		$this->assertSame( '', Paths::local_path( 'https://other.com/new', 'example.com' ) );
		$this->assertSame( '', Paths::local_path( '//other.com/new', 'example.com' ) );
	}

	public function test_csv_safe_blocks_formulas() {
		$this->assertSame( "'=cmd()", Paths::csv_safe( '=cmd()' ) );
		$this->assertSame( "'@x", Paths::csv_safe( '@x' ) );
		$this->assertSame( '/safe', Paths::csv_safe( '/safe' ) );
	}

	public function test_best_match_picks_closest_and_respects_threshold() {
		$cands = array(
			'about-us'     => 1,
			'contact-page' => 2,
		);
		$this->assertSame( 1, Suggester::best_match( '/old/about-uss', $cands ) );
		$this->assertSame( 0, Suggester::best_match( '/completely-different', $cands ) );
		$this->assertSame( 0, Suggester::best_match( '/', $cands ) );
	}

	public function test_loop_detection() {
		$map = array(
			'/b' => '/c',
			'/c' => '/a',
		);
		$this->assertTrue( Redirects::creates_loop( '/a', '/b', $map ), 'a->b->c->a' );
		$this->assertFalse( Redirects::creates_loop( '/a', '/z', $map ) );
		$this->assertFalse( Redirects::creates_loop( '/a', '', $map ), 'external target' );
		$this->assertTrue( Redirects::creates_loop( '/x', '/y', array( '/y' => '/z', '/z' => '/y' ) ), 'pre-existing cycle' );
	}
}
