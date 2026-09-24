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
use Founders\Migration\Archive\Crc32;
use Founders\Migration\Archive\WpressDecoder;
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Tests\TestCase;
use Founders\Migration\Tests\WpressBuilder;

require_once dirname( __DIR__ ) . '/WpressBuilder.php';

final class WpressDecoderTest extends TestCase {

	/**
	 * Files every storage mode is tested with.
	 *
	 * @return array<string,string>
	 */
	private function files(): array {
		return array(
			'package.json'                => '{"SiteURL":"https://a.test"}',
			'uploads/empty.txt'           => '',
			'uploads/small.txt'           => 'hello',
			'uploads/exact.bin'           => random_bytes( 512000 ),
			'uploads/2026/big.bin'        => random_bytes( 1300000 ),
			'themes/t/style.css'          => str_repeat( "body { color: red; }\n", 60000 ),
			'plugins/demo/package.json'   => '{"name":"demo"}',
			'uploads/two-exact-chunks.db' => str_repeat( 'z', 1024000 ),
		);
	}

	/**
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public function modes(): array {
		return array(
			'plain'            => array( null, 'none' ),
			'encrypted'        => array( 'Rahasia123', 'none' ),
			'gzip'             => array( null, 'gzip' ),
			'gzip + encrypted' => array( 'Rahasia123', 'gzip' ),
			'bzip2'            => array( null, 'bzip2' ),
			'bzip2 + encrypt'  => array( 'Rahasia123', 'bzip2' ),
		);
	}

	/**
	 * @dataProvider modes
	 *
	 * @param string|null $password    Password.
	 * @param string      $compression Compression.
	 */
	public function test_every_storage_mode_decodes_to_the_original_bytes_one_chunk_per_call( ?string $password, string $compression ): void {
		if ( 'bzip2' === $compression && ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'PHP bz2 extension not installed.' );
		}
		$files   = $this->files();
		$builder = new WpressBuilder( $password, $compression );
		foreach ( $files as $path => $content ) {
			$builder->add( $path, $content );
		}
		$archive = $builder->save( $this->tmp . '/a.wpress' );
		$decoder = new WpressDecoder( null === $password ? null : WpressPackage::derive_key( $password ), $compression );

		$reader = WpressReader::open( $archive );
		$seen   = array();
		$entry  = $reader->next();
		while ( null !== $entry ) {
			$offset   = $entry->offset;
			$consumed = 0;
			$output   = '';
			$calls    = 0;
			do {
				// A new reader per call, as after a process restart.
				$again = WpressReader::open( $archive, $offset );
				$again->next();
				$again->skip_bytes( $consumed );
				$done = $decoder->decode(
					$again,
					$entry,
					'package.json' === $entry->name,
					$consumed,
					static function ( string $data ) use ( &$output ): void {
						$output .= $data;
					},
					static function (): bool {
						return false;
					}
				);
				$again->close();
				++$calls;
			} while ( ! $done );

			$this->assertSame( $files[ $entry->name ], $output, $entry->name );
			$this->assertSame( hash( 'crc32b', $output ), $entry->crc32, $entry->name );
			$seen[ $entry->name ] = $calls;
			$entry                = $reader->next();
		}
		$reader->close();

		$this->assertCount( count( $files ), $seen );
		$this->assertGreaterThan( 1, $seen['uploads/2026/big.bin'] ); // Resumed between chunks.
		$this->assertSame( hash( 'crc32b', (string) substr( (string) file_get_contents( $archive ), 0, -WpressReader::HEADER_BYTES ) ), WpressReader::end_block( $archive )['crc'] );
	}

	public function test_a_wrong_password_or_damaged_chunk_is_an_error_not_garbage(): void {
		$archive = ( new WpressBuilder( 'right', 'none' ) )->add( 'uploads/a.bin', random_bytes( 700000 ) )->save( $this->tmp . '/e.wpress' );
		$reader  = WpressReader::open( $archive );
		$entry   = $reader->next();
		$in      = 0;
		try {
			( new WpressDecoder( WpressPackage::derive_key( 'wrong' ), 'none' ) )->decode(
				$reader,
				$entry,
				false,
				$in,
				static function (): void {
				},
				static function (): bool {
					return true;
				}
			);
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'wrong password or damaged archive', $e->getMessage() );
		} finally {
			$reader->close();
		}
	}

	public function test_a_crafted_chunk_length_is_refused(): void {
		$stored  = pack( 'N', 50000000 ) . str_repeat( 'x', 100 );
		$archive = $this->make_file( 'c.wpress', pack( 'a255a14a12a4088a8', 'x.bin', (string) strlen( $stored ), '1', 'uploads', '' ) . $stored . str_repeat( "\0", 4377 ) );
		$reader  = WpressReader::open( $archive );
		$entry   = $reader->next();
		$in      = 0;
		try {
			( new WpressDecoder( null, 'gzip' ) )->decode(
				$reader,
				$entry,
				false,
				$in,
				static function (): void {
				},
				static function (): bool {
					return true;
				}
			);
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'Damaged compressed data', $e->getMessage() );
		} finally {
			$reader->close();
		}
	}

	public function test_package_reads_settings_and_checks_the_signature(): void {
		$json    = (string) json_encode(
			array(
				'SiteURL'     => 'https://old.test',
				'HomeURL'     => 'https://old.test',
				'Plugin'      => array( 'Version' => '7.111' ),
				'Encrypted'   => true,
				'Compression' => array(
					'Enabled' => true,
					'Type'    => 'gzip',
				),
				'Database'    => array( 'Prefix' => 'abc_' ),
				'WordPress'   => array( 'Content' => '/srv/old/wp-content' ),
				'Replace'     => array(
					'OldValues' => array( 'Old Brand', '' ),
					'NewValues' => array( 'New Brand', 'x' ),
				),
			)
		);
		$package = WpressPackage::parse( $json );
		$this->assertSame( '7.111', $package->plugin_version() );
		$this->assertTrue( $package->encrypted() );
		$this->assertSame( 'gzip', $package->compression() );
		$this->assertSame( 'abc_', $package->table_prefix() );
		$this->assertSame( '/srv/old/wp-content', $package->wordpress( 'Content' ) );
		$this->assertSame( array( 'Old Brand' => 'New Brand' ), $package->replace_pairs() );
		$this->assertTrue( $package->accepts_key( WpressPackage::derive_key( 'any' ) ) ); // No signature: nothing to check.

		// A signature that decrypts, but not to All-in-One WP Migration's sentence, is refused.
		$iv      = random_bytes( 16 );
		$foreign = base64_encode( $iv . openssl_encrypt( 'some other text', 'AES-256-CBC', WpressPackage::derive_key( 'pw' ), OPENSSL_RAW_DATA, $iv ) );
		$signed  = WpressPackage::parse( (string) json_encode( array( 'SiteURL' => 'x', 'Encrypted' => true, 'EncryptedSignature' => $foreign ) ) );
		$this->assertFalse( $signed->accepts_key( WpressPackage::derive_key( 'pw' ) ) );
		$this->assertFalse( $signed->accepts_key( WpressPackage::derive_key( 'other' ) ) );

		try {
			WpressPackage::parse( (string) json_encode( array( 'SiteURL' => 'x', 'Compression' => array( 'Enabled' => true, 'Type' => 'zstd' ) ) ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'zstd', $e->getMessage() );
		}
	}

	public function test_crc_of_consecutive_pieces_combines_to_the_crc_of_the_whole(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$a = $i % 5 ? random_bytes( random_int( 1, 5000 ) ) : '';
			$b = $i % 7 ? random_bytes( random_int( 1, 300000 ) ) : '';
			$this->assertSame( hash( 'crc32b', $a . $b ), Crc32::combine( hash( 'crc32b', $a ), hash( 'crc32b', $b ), strlen( $b ) ) );
		}
	}
}
