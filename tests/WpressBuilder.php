<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests;

/**
 * Writes .wpress archives the way All-in-One WP Migration stores them, for tests.
 *
 * Written from the documented layout (see WpressReader / WpressDecoder), so
 * the tests check the reader against an independent writer; real archives
 * from both plugin generations were also checked by hand.
 */
final class WpressBuilder {

	/**
	 * Archive bytes so far.
	 *
	 * @var string
	 */
	private $data = '';

	/**
	 * AES key or null.
	 *
	 * @var string|null
	 */
	private $key;

	/**
	 * none, gzip or bzip2.
	 *
	 * @var string
	 */
	private $compression;

	/**
	 * Whether to write CRCs (v2) or not (v1).
	 *
	 * @var bool
	 */
	private $v2;

	/**
	 * Constructor.
	 *
	 * @param string|null $password    Password, or null for no encryption.
	 * @param string      $compression none, gzip or bzip2.
	 * @param bool        $v2          CRC headers and end block.
	 */
	public function __construct( ?string $password = null, string $compression = 'none', bool $v2 = true ) {
		$this->key         = null === $password ? null : substr( sha1( $password, true ), 0, 16 ); // OpenSSL pads it to 32 bytes.
		$this->compression = $compression;
		$this->v2          = $v2;
	}

	/**
	 * Adds a file.
	 *
	 * @param string    $path      Path relative to wp-content.
	 * @param string    $content   Content.
	 * @param bool|null $transform Encrypt / compress it; default: all but the top-level package.json.
	 * @param int       $mtime     Modification time.
	 * @return self
	 */
	public function add( string $path, string $content, ?bool $transform = null, int $mtime = 1700000000 ): self {
		if ( null === $transform ) {
			$transform = 'package.json' !== $path;
		}
		$stored = $transform ? $this->encode( $content ) : $content;
		$folder = dirname( $path );
		$crc    = $this->v2 ? hash( 'crc32b', $content ) : '';

		$this->data .= pack( 'a255a14a12a4088a8', basename( $path ), (string) strlen( $stored ), (string) $mtime, $folder, $crc ) . $stored;
		return $this;
	}

	/**
	 * Writes the archive with its end block.
	 *
	 * @param string $path File to write.
	 * @return string $path.
	 */
	public function save( string $path ): string {
		$end = $this->v2
			? pack( 'a255a14a4100a8', '', (string) strlen( $this->data ), '', hash( 'crc32b', $this->data ) )
			: str_repeat( "\0", 4377 );
		file_put_contents( $path, $this->data . $end );
		return $path;
	}

	/**
	 * Stored form of a file: 512,000-byte chunks, compressed, then encrypted, length-prefixed when compressed.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function encode( string $content ): string {
		if ( null === $this->key && 'none' === $this->compression ) {
			return $content;
		}
		$stored = '';
		foreach ( '' === $content ? array() : str_split( $content, 512000 ) as $chunk ) {
			if ( 'gzip' === $this->compression ) {
				$chunk = gzcompress( $chunk, 9 );
			} elseif ( 'bzip2' === $this->compression ) {
				$chunk = bzcompress( $chunk, 9 );
			}
			if ( null !== $this->key ) {
				$iv    = random_bytes( 16 );
				$chunk = $iv . openssl_encrypt( $chunk, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv );
			}
			if ( 'none' !== $this->compression ) {
				$chunk = pack( 'N', strlen( $chunk ) ) . $chunk;
			}
			$stored .= $chunk;
		}
		return $stored;
	}
}
