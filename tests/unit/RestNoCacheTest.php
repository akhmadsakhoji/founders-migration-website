<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Unit;

use Founders\Migration\Controller\RestController;
use Founders\Migration\Tests\TestCase;

/**
 * Page caches (LiteSpeed Cache's "Cache REST API" with "Cache Logged-in Users") must not keep the plugin's REST answers.
 */
final class RestNoCacheTest extends TestCase {

	public function test_only_the_plugins_routes_count_as_its_own(): void {
		foreach ( array( '/fmw/v1', '/fmw/v1/storages', '/fmw/v1/jobs/01ABC', '/fmw/v1/pull/jobs/x/file' ) as $route ) {
			$this->assertTrue( RestController::is_own_route( $route ), $route );
		}
		foreach ( array( '', '/', '/fmw', '/fmw/v10/storages', '/fmw/v1x', '/wp/v2/posts', '/other/fmw/v1/storages' ) as $route ) {
			$this->assertFalse( RestController::is_own_route( $route ), $route );
		}
	}

	/**
	 * DONOTCACHEPAGE is process-wide, hence a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_its_answers_are_marked_not_cacheable(): void {
		$controller = new RestController();
		$this->assertSame( 'kept', $controller->no_cache( 'kept', null, $this->request( '/wp/v2/posts' ) ) );
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'Other routes are left to the cache.' );
		$this->assertNull( $controller->no_cache( null, null, $this->request( '/fmw/v1/storages' ) ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );

		$own = $controller->no_store( $this->response(), null, $this->request( '/fmw/v1/storages' ) );
		$this->assertSame(
			array(
				'Cache-Control'             => 'no-store, private',
				'X-LiteSpeed-Cache-Control' => 'no-cache',
			),
			$own->headers
		);
		$this->assertSame( array(), $controller->no_store( $this->response(), null, $this->request( '/wp/v2/posts' ) )->headers );
	}

	/**
	 * A REST request with only a route.
	 *
	 * @param string $route Route.
	 * @return object
	 */
	private function request( string $route ) {
		return new class( $route ) {
			/**
			 * Route.
			 *
			 * @var string
			 */
			private $route;

			public function __construct( string $route ) {
				$this->route = $route;
			}

			public function get_route(): string {
				return $this->route;
			}
		};
	}

	/**
	 * A REST response that only collects headers.
	 *
	 * @return object
	 */
	private function response() {
		return new class() {
			/**
			 * Headers set.
			 *
			 * @var array<string,string>
			 */
			public $headers = array();

			public function header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
	}
}
