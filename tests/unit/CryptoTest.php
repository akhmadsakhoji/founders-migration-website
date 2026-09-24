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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\CbcStream;
use Founders\Migration\Archive\FmwCrypto;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Tests\TestCase;

final class CryptoTest extends TestCase {

	public function test_streamed_encryption_equals_one_shot_openssl_and_decrypts_back(): void {
		$plain = random_bytes( 100003 );
		$keys  = FmwCrypto::keys( 'pw', '12345678', 1000 );

		$stream = new CbcStream( $keys['key'], $keys['iv'], true );
		$out    = $stream->update( substr( $plain, 0, 32768 ) );
		// Resume in a "new process" from the saved chaining block.
		$again = new CbcStream( $keys['key'], $stream->iv(), true );
		$out  .= $again->update( substr( $plain, 32768, 65536 ) ) . $again->finish( substr( $plain, 98304 ) );

		$this->assertSame( openssl_encrypt( $plain, 'aes-256-cbc', $keys['key'], OPENSSL_RAW_DATA, $keys['iv'] ), $out );
		$this->assertSame( FmwCrypto::stored_size( strlen( $plain ) ), FmwCrypto::HEADER + strlen( $out ) );
		$this->assertSame( 48, FmwCrypto::stored_size( 16 ) ); // A full block of padding.
		$this->assertSame( 32, FmwCrypto::stored_size( 0 ) );

		$decrypt = new CbcStream( $keys['key'], $keys['iv'], false );
		$back    = $decrypt->update( substr( $out, 0, 4096 ) ) . $decrypt->finish( substr( $out, 4096 ) );
		$this->assertSame( $plain, $back );
	}

	public function test_a_wrong_key_or_tampered_data_never_decrypts_silently(): void {
		$sealed = FmwCrypto::encrypt_string( '{"site":"https://example.com"}', 'right', 1000 );
		$this->assertSame( '{"site":"https://example.com"}', FmwCrypto::decrypt_string( $sealed['data'], 'right', 1000, $sealed['hmac'] ) );
		$this->assertSame( 'Salted__', substr( $sealed['data'], 0, 8 ) );

		$tampered = $sealed['data'];
		$tampered[20] = chr( ord( $tampered[20] ) ^ 1 );
		foreach ( array( array( $sealed['data'], 'wrong' ), array( $tampered, 'right' ) ) as $case ) {
			try {
				FmwCrypto::decrypt_string( $case[0], $case[1], 1000, $sealed['hmac'] );
				$this->fail( 'Expected a failure.' );
			} catch ( ArchiveException $e ) {
				$this->assertStringContainsString( 'Wrong password', $e->getMessage() );
			}
		}

		$keys   = FmwCrypto::keys( 'other', '87654321', 1000 );
		$stream = new CbcStream( $keys['key'], $keys['iv'], false );
		$this->expectException( ArchiveException::class );
		$stream->finish( substr( $sealed['data'], FmwCrypto::HEADER ) ); // Bad padding with the wrong key.
	}

	public function test_job_secrets_are_sealed_and_forgotten(): void {
		$sealed = Secrets::seal( 'my password' );
		$this->assertStringNotContainsString( 'my password', $sealed );
		$this->assertSame( 'my password', Secrets::open( $sealed ) );
		$this->assertNull( Secrets::open( 'v1:' . base64_encode( random_bytes( 40 ) ) ) );
		$this->assertNull( Secrets::open( null ) );
		$this->assertNull( Secrets::open( 'plain text' ) );

		$job = new Job( '01J0000000000000000000000A', 'backup', array( 'secret_password' => $sealed, 'secret_wpress_key' => 'x', 'part_size' => 5 ) );
		Secrets::forget( $job );
		$this->assertSame( array( 'part_size' => 5 ), $job->options );
	}
}
