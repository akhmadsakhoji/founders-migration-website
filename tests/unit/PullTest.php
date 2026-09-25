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

use Founders\Migration\Pull\PullClient;
use Founders\Migration\Pull\PullException;
use Founders\Migration\Pull\PullKeys;
use Founders\Migration\Tests\TestCase;

/**
 * Pull keys (source side) and the pull client's address rules (target side).
 */
final class PullTest extends TestCase {

	/**
	 * Temporary key folder.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/fmw-pull-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		PullKeys::$dir = $this->dir;
	}

	protected function tearDown(): void {
		PullKeys::$dir = null;
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	public function test_a_key_is_shown_once_and_only_its_hash_is_stored(): void {
		$made = PullKeys::create( array( 'name' => 'new server' ) );
		$this->assertMatchesRegularExpression( '/^fmwpk_[0-9a-f]{8}_[A-Za-z0-9_-]{43}$/', $made['key'] );
		$stored = (string) file_get_contents( $this->dir . '/pull-keys.json' );
		$secret = substr( $made['key'], 15 );
		$this->assertStringNotContainsString( $secret, $stored );
		$this->assertStringContainsString( hash( 'sha256', $secret ), $stored );

		$record = PullKeys::authenticate( $made['key'], '203.0.113.7' );
		$this->assertSame( 'new server', $record['name'] );
		$this->assertArrayNotHasKey( 'hash', PullKeys::all()[0], 'Lists never show the hash.' );
	}

	public function test_wrong_expired_and_revoked_keys_are_refused(): void {
		$made = PullKeys::create( array() );
		$id   = $made['record']['id'];
		foreach ( array( '', 'fmwpk_' . $id . '_' . str_repeat( 'A', 43 ), 'fmwpk_00000000_' . substr( $made['key'], 15 ), 'nonsense' ) as $wrong ) {
			$this->assertPullError(
				401,
				static function () use ( $wrong ): void {
					PullKeys::authenticate( $wrong, '203.0.113.7' );
				}
			);
		}
		PullKeys::store()->change(
			$id,
			static function ( array $r ): array {
				$r['expires_at'] = time() - 1;
				return $r;
			}
		);
		$this->assertPullError(
			401,
			static function () use ( $made ): void {
				PullKeys::authenticate( $made['key'], '203.0.113.7' );
			}
		);
		$this->assertTrue( PullKeys::revoke( $id ) );
		$this->assertFalse( PullKeys::revoke( $id ) );
	}

	public function test_address_ranges(): void {
		$made = PullKeys::create( array( 'ips' => array( '203.0.113.0/24', '2001:db8::/32', '198.51.100.9' ) ) );
		foreach ( array( '203.0.113.200', '2001:db8:1::5', '198.51.100.9' ) as $ok ) {
			$this->assertSame( $made['record']['id'], PullKeys::authenticate( $made['key'], $ok )['id'], $ok );
		}
		foreach ( array( '203.0.114.1', '2001:db9::1', '198.51.100.10', 'garbage' ) as $bad ) {
			$this->assertPullError(
				403,
				static function () use ( $made, $bad ): void {
					PullKeys::authenticate( $made['key'], $bad );
				}
			);
		}
		$this->assertTrue( PullKeys::ip_allowed( '10.1.2.3', array( '10.0.0.0/9' ) ) );
		$this->assertFalse( PullKeys::ip_allowed( '10.128.0.1', array( '10.0.0.0/9' ) ) );
		$this->assertFalse( PullKeys::valid_range( '10.0.0.0/33' ) );
		$this->assertFalse( PullKeys::valid_range( 'example.com' ) );
		$this->expectException( \InvalidArgumentException::class );
		PullKeys::create( array( 'ips' => array( '10.0.0.0/40' ) ) );
	}

	public function test_durations(): void {
		$this->assertSame( 1800, PullKeys::parse_ttl( '30m' ) );
		$this->assertSame( 86400, PullKeys::parse_ttl( '24h' ) );
		$this->assertSame( 604800, PullKeys::parse_ttl( '7D' ) );
		$this->assertSame( 90, PullKeys::parse_ttl( '90' ) );
		foreach ( array( '31d', '10' ) as $bad ) {
			try {
				PullKeys::create( array( 'ttl' => PullKeys::parse_ttl( $bad ) ) );
				$this->fail( $bad . ' was accepted' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( '30 days', $e->getMessage() );
			}
		}
	}

	public function test_source_addresses_need_https_except_locally(): void {
		$this->assertSame( 'https://old.example.com', PullClient::normalize( 'old.example.com/' ) );
		$this->assertSame( 'https://old.example.com:8443/blog', PullClient::normalize( 'https://Old.Example.com:8443/blog/?x=1#y' ) );
		$this->assertSame( 'http://127.0.0.1:8090', PullClient::normalize( 'http://127.0.0.1:8090' ) );
		$this->assertSame( 'http://site.test', PullClient::normalize( 'http://site.test' ) );
		$this->assertSame( 'http://intranet.example', PullClient::normalize( 'http://intranet.example', true ) );
		foreach ( array( 'http://old.example.com', 'ftp://old.example.com', 'https://user:pw@old.example.com', 'https://' ) as $bad ) {
			$this->assertPullError(
				0,
				static function () use ( $bad ): void {
					PullClient::normalize( $bad );
				}
			);
		}
		$this->assertPullError(
			0,
			static function (): void {
				new PullClient( 'https://old.example.com', 'fmwpk_short' );
			}
		);
	}

	/**
	 * Asserts that the callback throws a PullException with this status.
	 *
	 * @param int      $status Expected status.
	 * @param callable $fn     Code.
	 * @return void
	 */
	private function assertPullError( int $status, callable $fn ): void {
		try {
			$fn();
		} catch ( PullException $e ) {
			$this->assertSame( $status, $e->status, $e->getMessage() );
			return;
		}
		$this->fail( 'No PullException thrown.' );
	}
}
