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

use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\JobToken;
use Founders\Migration\Storage\ByteRange;
use Founders\Migration\Storage\UploadOffsetException;
use Founders\Migration\Storage\Uploads;
use Founders\Migration\Tests\TestCase;

/**
 * Pieces behind the admin screens: resumable uploads, job tokens, download ranges.
 */
final class WebToolsTest extends TestCase {

	public function test_an_upload_accepts_chunks_only_at_its_end_and_resumes_for_the_same_file(): void {
		$uploads = new Uploads( $this->tmp . '/uploads' );
		$data    = random_bytes( 250000 );
		$upload  = $uploads->open( 'My Site (copy).wpress', strlen( $data ), 7 );
		$this->assertSame( 'My-Site-copy.wpress', $upload['name'] );
		$this->assertSame( 0, $upload['offset'] );

		$this->assertSame( 100000, $uploads->append( $upload['id'], 0, substr( $data, 0, 100000 ) ) );

		// A retried chunk (the response was lost) must not be written twice.
		try {
			$uploads->append( $upload['id'], 0, substr( $data, 0, 100000 ) );
			$this->fail( 'Expected an offset mismatch.' );
		} catch ( UploadOffsetException $e ) {
			$this->assertSame( 100000, $e->offset );
		}
		// A chunk from the future is refused too.
		try {
			$uploads->append( $upload['id'], 200000, substr( $data, 200000 ) );
			$this->fail( 'Expected an offset mismatch.' );
		} catch ( UploadOffsetException $e ) {
			$this->assertSame( 100000, $e->offset );
		}

		// Same user, name and size later (reload, other tab): same upload, continues at 100000.
		$again = $uploads->open( 'My Site (copy).wpress', strlen( $data ), 7 );
		$this->assertSame( $upload['id'], $again['id'] );
		$this->assertSame( 100000, $again['offset'] );
		$this->assertNotSame( $upload['id'], $uploads->open( 'My Site (copy).wpress', strlen( $data ), 8 )['id'] );

		try {
			$uploads->complete( $upload['id'], $this->tmp, 'x.wpress' );
			$this->fail( 'Expected an incomplete upload error.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'incomplete', $e->getMessage() );
		}
		try {
			$uploads->append( $upload['id'], 100000, substr( $data, 100000 ) . 'extra' );
			$this->fail( 'Expected a size error.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'past the end', $e->getMessage() );
		}

		$uploads->append( $upload['id'], 100000, substr( $data, 100000 ) );
		mkdir( $this->tmp . '/backups' );
		$path = $uploads->complete( $upload['id'], $this->tmp . '/backups', 'My-Site-copy.wpress' );
		$this->assertSame( $data, file_get_contents( $path ) );
		$this->assertFileDoesNotExist( $this->tmp . '/uploads/' . $upload['id'] );
	}

	/**
	 * @dataProvider names
	 *
	 * @param string      $name     Name from the browser.
	 * @param string|null $expected Safe name, or null when refused.
	 */
	public function test_upload_names_are_made_safe( string $name, ?string $expected ): void {
		if ( null === $expected ) {
			$this->expectException( \InvalidArgumentException::class );
		}
		$this->assertSame( $expected, Uploads::sanitize_name( $name ) );
	}

	/**
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public function names(): array {
		return array(
			'plain'          => array( 'example.com-20260924-180000-a1b2c3.fmw', 'example.com-20260924-180000-a1b2c3.fmw' ),
			'windows path'   => array( 'C:\\Users\\oji\\Downloads\\site.WPRESS', 'site.wpress' ),
			'traversal'      => array( '../../wp-config.php.fmw', 'wp-config.php.fmw' ),
			'odd characters' => array( "a b\0<c>;.fmw", 'a-b-c.fmw' ),
			'only extension' => array( '.fmw', 'backup.fmw' ),
			'php file'       => array( 'shell.php', null ),
			'double'         => array( 'backup.fmw.php', null ),
		);
	}

	public function test_a_job_token_is_stored_hashed_and_replaced_on_renewal(): void {
		$store = new JobStore( $this->tmp . '/jobs' );
		$job   = $store->create( 'backup', array() );
		$first = JobToken::issue( $job, $store );

		$this->assertSame( 64, strlen( $first ) );
		$this->assertStringNotContainsString( $first, (string) file_get_contents( $store->dir( $job->id ) . '/' . JobStore::STATE_FILE ) );
		$this->assertTrue( JobToken::matches( $store->load( $job->id ), $store, $first ) );
		$this->assertFalse( JobToken::matches( $store->load( $job->id ), $store, str_repeat( '0', 64 ) ) );
		$this->assertFalse( JobToken::matches( $store->load( $job->id ), $store, '' ) );

		$second = JobToken::issue( $store->load( $job->id ), $store );
		$this->assertFalse( JobToken::matches( $store->load( $job->id ), $store, $first ) );
		$this->assertTrue( JobToken::matches( $store->load( $job->id ), $store, $second ) );
		$this->assertFalse( JobToken::matches( $store->create( 'backup', array() ), $store, $second ) ); // Another job.

		// The background chain has its own token: issuing one never cuts off the browser, and the reverse.
		$background = JobToken::issue( $store->load( $job->id ), $store, JobToken::BACKGROUND );
		$this->assertTrue( JobToken::matches( $store->load( $job->id ), $store, $second ) );
		$this->assertTrue( JobToken::matches( $store->load( $job->id ), $store, $background ) );
		$third = JobToken::issue( $store->load( $job->id ), $store );
		$this->assertTrue( JobToken::matches( $store->load( $job->id ), $store, $background ) );
		$this->assertFalse( JobToken::matches( $store->load( $job->id ), $store, $second ) );
		$this->assertTrue( JobToken::matches( $store->load( $job->id ), $store, $third ) );
	}

	public function test_download_ranges(): void {
		$this->assertNull( ByteRange::parse( '', 1000 ) );
		$this->assertSame( array( 0, 99 ), ByteRange::parse( 'bytes=0-99', 1000 ) );
		$this->assertSame( array( 500, 999 ), ByteRange::parse( 'bytes=500-', 1000 ) );
		$this->assertSame( array( 900, 999 ), ByteRange::parse( 'bytes=-100', 1000 ) );
		$this->assertSame( array( 0, 999 ), ByteRange::parse( 'bytes=-5000', 1000 ) );
		$this->assertSame( array( 990, 999 ), ByteRange::parse( 'bytes=990-5000', 1000 ) );
		$this->assertFalse( ByteRange::parse( 'bytes=1000-', 1000 ) );
		$this->assertFalse( ByteRange::parse( 'bytes=50-10', 1000 ) );
		$this->assertNull( ByteRange::parse( 'bytes=0-1,5-6', 1000 ) ); // Several ranges: the whole file.
		$this->assertNull( ByteRange::parse( 'items=0-1', 1000 ) );
	}
}
