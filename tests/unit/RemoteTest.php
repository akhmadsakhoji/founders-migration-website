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
use Founders\Migration\Remote\DriveDriver;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\S3Driver;
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

	public function test_chunk_sizes_follow_speed_time_and_storage_rules(): void {
		$mib = 1048576;
		// What the job would like to send.
		$this->assertSame( 8 * $mib, UploadStep::wanted( 0.0, 0, 20.0 ), 'First chunk: 8 MiB.' );
		$this->assertSame( 32 * $mib, UploadStep::wanted( 1e9, 8 * $mib, 20.0 ), 'Grows at most fourfold per request.' );
		$this->assertSame( 512 * $mib, UploadStep::wanted( 1e9, 256 * $mib, 20.0 ), 'At most 512 MiB.' );
		$this->assertSame( (int) ( 5e6 * 2.0 * 0.8 ), UploadStep::wanted( 5e6, 64 * $mib, 2.0 ), 'Fits the time left in the slice.' );

		// S3: parts of at least 5 MiB (whole MiB), at most 10,000 of them.
		$s3    = new S3Driver( array( 'prefix' => '' ), new S3Client( array( 'endpoint' => 'https://s3.example.com', 'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's' ) ) );
		$state = array(
			'key'    => 'x',
			'id'     => 'upload',
			'parts'  => array(),
			'offset' => 0,
		);
		$this->assertSame( 5 * $mib, $s3->chunk_length( $state, 10 * 1024 * $mib, 262144 ) );
		$this->assertSame( 11 * $mib, $s3->chunk_length( $state, 100 * 1024 * $mib, 8 * $mib ), '100 GiB needs 11 MiB parts.' );
		$this->assertSame( 3 * $mib, $s3->chunk_length( array( 'offset' => 7 * $mib ) + $state, 10 * $mib, 8 * $mib ), 'The last part may be small.' );
		$this->assertSame( 7 * $mib, $s3->chunk_length( array( 'id' => '' ) + $state, 7 * $mib, $mib ), 'Small files: one PUT.' );
		$left   = 5 * 1024 * 1024 * $mib; // 5 TiB on a slow link still fits in 10,000 parts.
		$number = 0;
		while ( $left > 0 ) {
			$state['parts'] = array_fill( 1, max( 1, $number ), 'etag' );
			if ( 0 === $number ) {
				$state['parts'] = array();
			}
			$left -= $s3->chunk_length( array( 'offset' => 0 ) + $state, $left, 262144 );
			++$number;
		}
		$this->assertLessThanOrEqual( S3Client::MAX_PARTS, $number );

		// Google Drive: 256 KiB multiples except the last chunk.
		$drive = new DriveDriver( array( 'id' => 'x' ) );
		$state = array( 'offset' => 0 );
		$this->assertSame( 262144, $drive->chunk_length( $state, 10 * $mib, 1000 ) );
		$this->assertSame( 8 * $mib, $drive->chunk_length( $state, 100 * $mib, 8 * $mib + 5000 ) );
		$this->assertSame( 12345, $drive->chunk_length( array( 'offset' => 10 * $mib - 12345 ), 10 * $mib, 8 * $mib ) );
	}

	public function test_google_drive_settings_are_checked_and_sealed(): void {
		$storage = StorageOptions::build(
			array(
				'provider'      => 'gdrive',
				'client_id'     => '1234567890-abc.apps.googleusercontent.com',
				'client_secret' => 'GOCSPX-very-secret',
				'prefix'        => '/Backups/example.com/',
			),
			null,
			1
		);
		$this->assertSame( 'own', $storage['auth'] );
		$this->assertSame( 'Backups/example.com', $storage['prefix'] );
		$this->assertSame( 'GOCSPX-very-secret', Secrets::open( $storage['secret'] ) );
		$view = StorageOptions::public_view( array_merge( $storage, array( 'refresh' => Secrets::seal( 'r' ) ) ) );
		$this->assertTrue( $view['connected'] );
		$this->assertArrayNotHasKey( 'refresh', $view );
		$this->assertArrayNotHasKey( 'secret', $view );
		$this->assertSame( 'My Drive/Backups/example.com', $view['location'] );

		$connected = array(
			'refresh'   => 'sealed',
			'access'    => 'sealed',
			'account'   => 'a@example.com',
			'folder_id' => 'F1',
		) + $storage;
		$renamed   = StorageOptions::build( array( 'name' => 'Drive kantor' ), $connected, 1 );
		$this->assertSame( 'sealed', $renamed['refresh'], 'Other edits keep the sign-in.' );
		$moved = StorageOptions::build( array( 'prefix' => 'Elsewhere' ), $connected, 1 );
		$this->assertSame( '', $moved['folder_id'], 'Another folder is looked up again.' );
		$this->assertSame( 'sealed', $moved['refresh'] );
		$other = StorageOptions::build( array( 'client_id' => '999-other.apps.googleusercontent.com' ), $connected, 1 );
		$this->assertSame( '', $other['refresh'], 'Another OAuth client drops the sign-in.' );

		foreach ( array( array( 'client_id' => 'not-a-client' ), array( 'client_secret' => '' ), array( 'prefix' => '..' ) ) as $change ) {
			try {
				StorageOptions::build( array_merge( array( 'provider' => 'gdrive', 'client_id' => '1-a.apps.googleusercontent.com', 'client_secret' => 'secret-123' ), $change ), null, 1 );
				$this->fail( 'Accepted ' . json_encode( $change ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		try {
			StorageOptions::build( array( 'provider' => 'aws' ), $storage, 1 );
			$this->fail( 'A Drive storage cannot become S3.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'cannot change', $e->getMessage() );
		}
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
