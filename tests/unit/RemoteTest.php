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

use Founders\Migration\Job\Secrets;
use Founders\Migration\Model\Remote\UploadStep;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\S3Client;
use Founders\Migration\Remote\SigV4;
use Founders\Migration\Remote\StorageOptions;
use Founders\Migration\Tests\TestCase;

/**
 * Cloud storage: request signing, settings, part sizes and (with a server) the S3 client.
 *
 * The client test runs against any S3-compatible server given by
 * FMWP_TEST_S3_ENDPOINT, FMWP_TEST_S3_BUCKET, FMWP_TEST_S3_KEY and
 * FMWP_TEST_S3_SECRET (for example MinIO or VersityGW); skipped otherwise.
 */
final class RemoteTest extends TestCase {

	public function test_signatures_match_the_aws_documentation_examples(): void {
		$credentials = array(
			'region'     => 'us-east-1',
			'access_key' => 'AKIAIOSFODNN7EXAMPLE',
			'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		);
		$time        = gmmktime( 0, 0, 0, 5, 24, 2013 );
		$host        = 'examplebucket.s3.amazonaws.com';

		// GET Object with a Range header.
		$headers = SigV4::sign( 'GET', $host, '/test.txt', array(), array( 'Range' => 'bytes=0-9' ), SigV4::EMPTY_HASH, $credentials, $time );
		$this->assertSame( 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $headers['authorization'] );
		$this->assertSame( '20130524T000000Z', $headers['x-amz-date'] );

		// PUT Object with a body and a storage class.
		$headers = SigV4::sign( 'PUT', $host, SigV4::encode_path( '/test$file.text' ), array(), array( 'Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY' ), hash( 'sha256', 'Welcome to Amazon S3.' ), $credentials, $time );
		$this->assertStringEndsWith( 'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $headers['authorization'] );

		// GET Bucket (list objects) with query parameters.
		$headers = SigV4::sign( 'GET', $host, '/', array( 'prefix' => 'J', 'max-keys' => '2' ), array(), SigV4::EMPTY_HASH, $credentials, $time );
		$this->assertStringEndsWith( 'Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $headers['authorization'] );

		$this->assertSame( '/site%20one/backup%2Bnew.fmw', SigV4::encode_path( '/site one/backup+new.fmw' ) );
		$this->assertSame( 'a=1&b=x%2Fy&uploads=', SigV4::canonical_query( array( 'uploads' => '', 'b' => 'x/y', 'a' => '1' ) ) );
	}

	public function test_storage_settings_are_checked_and_the_secret_sealed(): void {
		$storage = StorageOptions::build(
			array(
				'provider'   => 'aws',
				'region'     => 'ap-southeast-3',
				'bucket'     => 'founders-backups',
				'prefix'     => '/example.com/daily/',
				'access_key' => 'AKIAEXAMPLE',
				'secret_key' => 'very-secret-key-123',
			),
			null,
			1790000000
		);
		$this->assertSame( 'https://s3.ap-southeast-3.amazonaws.com', $storage['endpoint'] );
		$this->assertFalse( $storage['path_style'] );
		$this->assertSame( 'example.com/daily', $storage['prefix'] );
		$this->assertSame( 'Amazon S3 · founders-backups', $storage['name'] );
		$this->assertSame( 'very-secret-key-123', Secrets::open( $storage['secret'] ) );
		$this->assertStringNotContainsString( 'very-secret', (string) json_encode( $storage ) );
		$this->assertSame( 'example.com/daily/site.fmw', StorageOptions::key( $storage, '../site.fmw' ) );
		$this->assertArrayNotHasKey( 'secret', StorageOptions::public_view( $storage ) );
		$this->assertSame( 'founders-backups/example.com/daily', StorageOptions::public_view( $storage )['location'] );

		$kept = StorageOptions::build( array( 'secret_key' => '', 'storage_class' => 'standard_ia' ), $storage, 1790000000 );
		$this->assertSame( $storage['secret'], $kept['secret'], 'An empty secret keeps the saved one.' );
		$this->assertSame( 'STANDARD_IA', $kept['storage_class'] );

		$r2 = StorageOptions::build( array( 'provider' => 'r2', 'endpoint' => 'https://abc123.r2.cloudflarestorage.com/', 'bucket' => 'b1', 'access_key' => 'key', 'secret_key' => 'secret-123' ), null, 1 );
		$this->assertSame( 'auto', $r2['region'] );
		$this->assertTrue( $r2['path_style'] );
		$this->assertSame( 'https://abc123.r2.cloudflarestorage.com', $r2['endpoint'] );

		$base = array(
			'provider'   => 'custom',
			'endpoint'   => 'https://minio.example.com:9000',
			'bucket'     => 'backups',
			'access_key' => 'key',
			'secret_key' => 'secret-123',
		);
		$bad  = array(
			array( 'provider' => 'dropbox' ),
			array( 'endpoint' => 'ftp://example.com' ),
			array( 'endpoint' => 'https://user:pass@example.com' ),
			array( 'endpoint' => 'https://example.com/?x=1' ),
			array( 'endpoint' => '' ),
			array( 'bucket' => 'a' ),
			array( 'bucket' => 'a/b' ),
			array( 'prefix' => 'a/../b' ),
			array( 'prefix' => "a\nb" ),
			array( 'access_key' => '' ),
			array( 'secret_key' => 'has space' ),
			array( 'region' => 'EU West' ),
			array( 'storage_class' => 'DEEP_ARCHIVE' ),
		);
		foreach ( $bad as $change ) {
			try {
				StorageOptions::build( array_merge( $base, $change ), null, 1 );
				$this->fail( 'Accepted ' . json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$r2_placeholder = array(
			'provider'   => 'r2',
			'bucket'     => 'b1',
			'access_key' => 'key',
			'secret_key' => 'secret-123',
		);
		try {
			StorageOptions::build( $r2_placeholder, null, 1 );
			$this->fail( 'R2 needs the account endpoint.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'endpoint', $e->getMessage() );
		}
	}

	public function test_part_sizes_stay_within_s3_limits(): void {
		$mib = 1048576;
		$this->assertSame( 8 * $mib, UploadStep::part_size( 10 * 1024 * $mib, 1, 0.0 ), 'First part: 8 MiB.' );
		$this->assertSame( 8 * $mib, UploadStep::part_size( 10 * 1024 * $mib, 2, 100000.0 ), 'Slow link: never below 8 MiB.' );
		$this->assertSame( 11 * $mib, UploadStep::part_size( 100 * 1024 * $mib, 2, 100000.0 ), '100 GiB: big enough for 10,000 parts.' );
		$this->assertSame( 512 * $mib, UploadStep::part_size( 100 * 1024 * $mib, 2, 1e9 ), 'Fast link: at most 512 MiB.' );
		$this->assertSame( 0, UploadStep::part_size( 5 * 1024 * 1024 * $mib, 1, 0.0 ) % $mib );

		// A 5 TiB file on a slow link still fits in 10,000 parts.
		$left   = 5 * 1024 * 1024 * $mib;
		$number = 1;
		while ( $left > 0 ) {
			$left -= min( $left, UploadStep::part_size( $left, $number, 100000.0 ) );
			++$number;
		}
		$this->assertLessThanOrEqual( S3Client::MAX_PARTS + 1, $number );
	}

	public function test_xml_values_and_retryable_errors(): void {
		$xml = '<Error><Code>SlowDown</Code><Message>Reduce your &amp; rate</Message></Error>';
		$this->assertSame( 'SlowDown', S3Client::xml_value( $xml, 'Code' ) );
		$this->assertSame( 'Reduce your & rate', S3Client::xml_value( $xml, 'Message' ) );
		$this->assertSame( '', S3Client::xml_value( $xml, 'Key' ) );
		$this->assertTrue( ( new RemoteException( 'x', 503, 'ServiceUnavailable' ) )->is_transient() );
		$this->assertTrue( ( new RemoteException( 'x', 0 ) )->is_transient() );
		$this->assertTrue( ( new RemoteException( 'x', 400, 'RequestTimeout' ) )->is_transient() );
		$this->assertFalse( ( new RemoteException( 'x', 403, 'SignatureDoesNotMatch' ) )->is_transient() );
		$this->assertFalse( ( new RemoteException( 'x', 404, 'NoSuchKey' ) )->is_transient() );
	}

	public function test_the_client_round_trips_files_with_a_real_server(): void {
		$endpoint = (string) getenv( 'FMWP_TEST_S3_ENDPOINT' );
		if ( '' === $endpoint ) {
			$this->markTestSkipped( 'Set FMWP_TEST_S3_ENDPOINT, _BUCKET, _KEY and _SECRET to run against an S3-compatible server.' );
		}
		$client = new S3Client(
			array(
				'endpoint'   => $endpoint,
				'region'     => (string) ( getenv( 'FMWP_TEST_S3_REGION' ) ?: 'us-east-1' ),
				'bucket'     => (string) getenv( 'FMWP_TEST_S3_BUCKET' ),
				'access_key' => (string) getenv( 'FMWP_TEST_S3_KEY' ),
				'secret_key' => (string) getenv( 'FMWP_TEST_S3_SECRET' ),
				'path_style' => true,
			)
		);
		$prefix = 'fmw-test-' . bin2hex( random_bytes( 3 ) ) . '/sub folder/';
		$file   = $this->make_file( 'big.bin', random_bytes( 12 * 1048576 + 12345 ) );
		$size   = (int) filesize( $file );

		$client->put_string( $prefix . 'note+1.txt', 'Halo' );
		$this->assertSame( 'Halo', $client->get_string( $prefix . 'note+1.txt' ) );

		$upload = $client->create_multipart( $prefix . 'big.bin' );
		$etags  = array(
			1 => $client->upload_part( $prefix . 'big.bin', $upload, 1, $file, 0, 6 * 1048576 ),
			2 => $client->upload_part( $prefix . 'big.bin', $upload, 2, $file, 6 * 1048576, $size - 6 * 1048576 ),
		);
		$client->complete_multipart( $prefix . 'big.bin', $upload, $etags );
		$this->assertSame( $size, $client->head( $prefix . 'big.bin' )['size'] );

		$copy = fopen( $this->tmp . '/copy.bin', 'w+b' );
		for ( $from = 0; $from < $size; $from += 5000000 ) {
			$client->get_range( $prefix . 'big.bin', $from, min( $size, $from + 5000000 ) - 1, $copy );
		}
		fclose( $copy );
		$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', $this->tmp . '/copy.bin' ) );

		$keys = array_column( $client->list( $prefix ), 'key' );
		sort( $keys );
		$this->assertSame( array( $prefix . 'big.bin', $prefix . 'note+1.txt' ), $keys );

		$aborted = $client->create_multipart( $prefix . 'aborted.bin' );
		$client->abort_multipart( $prefix . 'aborted.bin', $aborted );
		$client->abort_multipart( $prefix . 'aborted.bin', $aborted ); // Twice is fine.

		$client->delete( $prefix . 'big.bin' );
		$client->delete( $prefix . 'note+1.txt' );
		$client->delete( $prefix . 'note+1.txt' ); // Already gone is fine.
		$this->assertSame( array(), $client->list( $prefix ) );
		$this->assertNull( $client->head( $prefix . 'big.bin' ) );
	}
}
