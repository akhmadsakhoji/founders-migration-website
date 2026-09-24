<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Export;

use Founders\Migration\Archive\CbcStream;
use Founders\Migration\Archive\FmwCrypto;
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\TarHeader;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Discardable;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Multi-GB archives are streamed with native file calls, like the archive library.

/**
 * Wraps the finished parts into the .fmw container (format v1, section 1).
 *
 * Order inside the TAR: fmw.json, database parts, file parts, manifest.json.
 * The archive is written as <name>.fmw.partial in the backups folder and
 * renamed when complete, so a half-written backup is never listed.
 *
 * To keep disk usage near one copy of the site, each part is deleted once
 * it is inside the container and that progress has been checkpointed (at
 * the start of the next slice). Parts copied in the final slice are left
 * for the caller to purge with the rest of the job folder.
 *
 * With a password (job option secret_password, sealed), every part and the
 * manifest are encrypted on the way into the container (format v1, section
 * 7): parts become <path>.enc, manifest.json becomes manifest.json.enc, and
 * fmw.json carries the key derivation settings and the manifest's HMAC,
 * written into a placeholder once the manifest exists. The stored bytes of
 * each encrypted part are read back once to compute its SHA-256 and HMAC.
 *
 * Reads job options: archive_dir, archive_name, site, exclude,
 * include_root_files, part_size, generator, encrypt, kdf_iterations,
 * secret_password. Writes job data: archive = { path, name, bytes },
 * encrypted_parts.
 */
final class PackageStep implements Step, Discardable {

	const COPY_BYTES = 67108864;
	const READ_BYTES = 1048560; // A multiple of the AES block size.
	const NO_HMAC    = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Package';
	}

	/**
	 * Deletes the unfinished archive of a cancelled backup.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		$dir  = rtrim( (string) ( $job->options['archive_dir'] ?? '' ), '/' );
		$name = (string) ( $job->options['archive_name'] ?? '' );
		if ( '' !== $dir && '' !== $name && basename( $name ) === $name && is_file( $dir . '/' . $name . '.partial' ) ) {
			unlink( $dir . '/' . $name . '.partial' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plain PHP in jobs.
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When options are missing or the archive cannot be finished.
	 */
	public function run( Job $job, Context $context ): bool {
		$dir  = rtrim( (string) ( $job->options['archive_dir'] ?? '' ), '/' );
		$name = (string) ( $job->options['archive_name'] ?? '' );
		if ( '' === $dir || '' === $name || basename( $name ) !== $name ) {
			throw new JobException( 'The archive folder and name are not set.' );
		}
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new JobException( sprintf( 'Cannot create %s.', $dir ) );
		}

		$final   = $dir . '/' . $name;
		$partial = $final . '.partial';
		$parts   = self::ordered_parts( (array) ( $job->data['parts'] ?? array() ) );
		$cursor  = $job->cursor + array(
			'at'     => 0,
			'i'      => 0,
			'offset' => 0,
			'copied' => array(),
		);

		// Parts copied in earlier, checkpointed slices are safe to delete now.
		foreach ( (array) $cursor['copied'] as $copied ) {
			$path = $context->dir() . '/' . $copied;
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$cursor['copied'] = array();

		if ( $cursor['i'] >= count( $parts ) && is_file( $final ) && ! is_file( $partial ) ) {
			return $this->done( $job, $final, $name, $context ); // Renamed just before a crash.
		}

		$total    = (int) array_sum( array_column( $parts, 'bytes' ) );
		$count    = count( $parts );
		$password = self::password( $job );
		$sink     = new PlainFileSink( $partial, (int) $cursor['at'] );
		$tar      = new TarWriter( $sink );
		$done     = false;

		try {
			if ( 0 === (int) $cursor['at'] ) {
				$header = self::json( $this->header( $job ) );
				$tar->add_string( 'fmw.json', $header, 0644, time() );
				if ( null !== $password ) {
					// fmw.json is the first entry: one 512-byte header, then its data.
					$job->data['manifest_hmac_at'] = TarHeader::BLOCK_SIZE + (int) strpos( $header, self::NO_HMAC );
				}
			}

			while ( $cursor['i'] < $count && $context->should_continue() ) {
				$part   = $parts[ $cursor['i'] ];
				$source = $context->dir() . '/' . $part['path'];
				if ( 0 === (int) $cursor['offset'] && ! is_file( $source ) && ! isset( $cursor['salt'] ) ) {
					throw new JobException( sprintf( 'Part %s is missing from the job folder.', $part['path'] ) );
				}

				if ( null === $password ) {
					$cursor['offset'] = $tar->write_file_slice( $source, TarEntry::file( $part['path'], (int) $part['bytes'], 0644, time() ), (int) $cursor['offset'], self::COPY_BYTES );
					$complete         = $cursor['offset'] >= (int) $part['bytes'];
				} else {
					$complete = $this->encrypt_slice( $job, $tar, $sink, $source, $part, $cursor, $password );
				}
				$job->bytes_done  = (int) array_sum( array_column( array_slice( $parts, 0, $cursor['i'] ), 'bytes' ) ) + (int) $cursor['offset'];
				$job->bytes_total = $total;
				$context->report_progress();

				if ( $complete ) {
					$cursor['copied'][] = $part['path'];
					++$cursor['i'];
					$cursor['offset'] = 0;
					unset( $cursor['salt'], $cursor['iv'], $cursor['data_at'] );
				}
			}

			if ( $cursor['i'] >= $count ) {
				$manifest = self::json( $this->manifest( $job, $parts ) );
				if ( null === $password ) {
					$tar->add_string( 'manifest.json', $manifest, 0644, time() );
				} else {
					$sealed = FmwCrypto::encrypt_string( $manifest, $password, self::iterations( $job ) );
					$tar->add_string( 'manifest.json.enc', $sealed['data'], 0644, time() );
				}
				$tar->finish();
				if ( isset( $sealed ) ) {
					$this->write_manifest_hmac( $partial, (int) $job->data['manifest_hmac_at'], $sealed['hmac'] );
				}
				$done = true;
			} else {
				$cursor['at'] = $tar->commit();
			}
		} finally {
			$sink->close();
		}

		if ( ! $done ) {
			$job->cursor = $cursor;
			return false;
		}

		if ( ! rename( $partial, $final ) ) {
			throw new JobException( sprintf( 'Cannot rename %s to %s.', $partial, $final ) );
		}
		return $this->done( $job, $final, $name, $context );
	}

	/**
	 * Encrypts (part of) a part into the container. Resumable: the cursor keeps
	 * the plain-text offset, the salt, the CBC chaining block and where the
	 * entry's data starts in the container.
	 *
	 * @param Job                 $job      Job.
	 * @param TarWriter           $tar      Container writer.
	 * @param PlainFileSink       $sink     Container file.
	 * @param string              $source   Plain part in the job folder.
	 * @param array<string,mixed> $part     Part record.
	 * @param array<string,mixed> $cursor   Cursor (updated).
	 * @param string              $password Password.
	 * @return bool Whether the part is complete.
	 * @throws JobException When the part cannot be read.
	 */
	private function encrypt_slice( Job $job, TarWriter $tar, PlainFileSink $sink, string $source, array $part, array &$cursor, string $password ): bool {
		$plain      = (int) $part['bytes'];
		$iterations = self::iterations( $job );
		$entry      = TarEntry::file( self::stored_name( (string) $part['path'] ), FmwCrypto::stored_size( $plain ), 0644, time() );

		if ( ! isset( $cursor['salt'] ) ) {
			$tar->begin_file( $entry );
			$cursor['data_at'] = $sink->position();
			$new               = FmwCrypto::new_header();
			$tar->write_data( $new['header'] );
			$cursor['salt'] = bin2hex( $new['salt'] );
			$cursor['iv']   = bin2hex( FmwCrypto::keys( $password, $new['salt'], $iterations )['iv'] );
		}
		$keys   = FmwCrypto::keys( $password, (string) hex2bin( (string) $cursor['salt'] ), $iterations );
		$cipher = new CbcStream( $keys['key'], (string) hex2bin( (string) $cursor['iv'] ), true );

		$handle = fopen( $source, 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, (int) $cursor['offset'] ) ) {
			throw new JobException( sprintf( 'Cannot read part %s.', $part['path'] ) );
		}
		try {
			$position = (int) $cursor['offset'];
			$budget   = $position + self::COPY_BYTES;
			do {
				$length = min( self::READ_BYTES, $plain - $position );
				$data   = $length > 0 ? fread( $handle, $length ) : '';
				if ( false === $data || strlen( $data ) !== $length ) {
					throw new JobException( sprintf( 'Part %s changed while it was being packed.', $part['path'] ) );
				}
				$position += $length;
				if ( $position >= $plain ) {
					$tar->write_data( $cipher->finish( $data ) );
					break;
				}
				$tar->write_data( $cipher->update( $data ) );
			} while ( $position < $budget );
		} finally {
			fclose( $handle );
		}

		$cursor['offset'] = $position;
		$cursor['iv']     = bin2hex( $cipher->iv() );
		if ( $position < $plain ) {
			return false;
		}

		$tar->end_file( $entry );
		$tar->commit();
		$job->data['encrypted_parts'][ $part['path'] ] = self::stored_hashes( $sink, (int) $cursor['data_at'], $entry->size, $keys['mac'] ) + array( 'bytes' => $entry->size );
		return true;
	}

	/**
	 * Name of an encrypted part in the container: ".enc" added, and table names
	 * dropped from database parts (database/0004.0001.sql.gz.enc), since the
	 * table list is only in the encrypted manifest.
	 *
	 * @param string $path Plain part path.
	 * @return string
	 */
	public static function stored_name( string $path ): string {
		return (string) preg_replace( '#^database/(\d{4})-.+\.(\d{4})\.sql\.gz$#', 'database/$1.$2.sql.gz', $path ) . '.enc';
	}

	/**
	 * SHA-256 and HMAC of an encrypted entry, read back from the container.
	 *
	 * @param PlainFileSink $sink   Container (committed).
	 * @param int           $at     Offset of the entry's data.
	 * @param int           $size   Data size.
	 * @param string        $mac    HMAC key.
	 * @return array{sha256:string,hmac:string}
	 * @throws JobException When the container cannot be read.
	 */
	private static function stored_hashes( PlainFileSink $sink, int $at, int $size, string $mac ): array {
		$handle = fopen( $sink->path(), 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, $at ) ) {
			throw new JobException( 'Cannot read the archive back.' );
		}
		$sha  = hash_init( 'sha256' );
		$hmac = hash_init( 'sha256', HASH_HMAC, $mac );
		try {
			$left = $size;
			while ( $left > 0 ) {
				$data = fread( $handle, (int) min( 8388608, $left ) );
				if ( false === $data || '' === $data ) {
					throw new JobException( 'The archive ended while reading it back.' );
				}
				hash_update( $sha, $data );
				hash_update( $hmac, $data );
				$left -= strlen( $data );
			}
		} finally {
			fclose( $handle );
		}
		return array(
			'sha256' => hash_final( $sha ),
			'hmac'   => hash_final( $hmac ),
		);
	}

	/**
	 * Replaces the HMAC placeholder in fmw.json.
	 *
	 * @param string $archive Container file.
	 * @param int    $at      Offset of the placeholder.
	 * @param string $hmac    Manifest HMAC (hex).
	 * @return void
	 * @throws JobException When the placeholder is not where expected.
	 */
	private function write_manifest_hmac( string $archive, int $at, string $hmac ): void {
		$handle = fopen( $archive, 'r+b' );
		// The placeholder, or the HMAC of a manifest written before a crash (the manifest is rewritten on resume).
		if ( false === $handle || 0 !== fseek( $handle, $at ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) fread( $handle, 64 ) ) || 0 !== fseek( $handle, $at ) || 64 !== fwrite( $handle, $hmac ) ) {
			throw new JobException( 'Cannot record the manifest HMAC in fmw.json.' );
		}
		fflush( $handle );
		fclose( $handle );
	}

	/**
	 * The backup password, or null for an unencrypted backup.
	 *
	 * @param Job $job Job.
	 * @return string|null
	 * @throws JobException When the job should be encrypted but the password cannot be opened.
	 */
	private static function password( Job $job ): ?string {
		if ( empty( $job->options['encrypt'] ) ) {
			return null;
		}
		$password = Secrets::open( $job->options['secret_password'] ?? null );
		if ( null === $password || '' === $password ) {
			throw new JobException( 'The backup password is no longer available (the site\'s salts may have changed). Start the backup again.' );
		}
		return $password;
	}

	/**
	 * PBKDF2 iterations for this backup.
	 *
	 * @param Job $job Job.
	 * @return int
	 */
	private static function iterations( Job $job ): int {
		return max( 100000, (int) ( $job->options['kdf_iterations'] ?? FmwCrypto::ITERATIONS ) );
	}

	/**
	 * Records the finished archive.
	 *
	 * @param Job     $job     Job.
	 * @param string  $archive Archive path.
	 * @param string  $name    Archive file name.
	 * @param Context $context Context.
	 * @return bool
	 */
	private function done( Job $job, string $archive, string $name, Context $context ): bool {
		$size                 = (int) filesize( $archive );
		$job->data['archive'] = array(
			'path'  => $archive,
			'name'  => $name,
			'bytes' => $size,
		);
		$context->log( sprintf( 'Archive written: %s (%d bytes).', $archive, $size ) );
		return true;
	}

	/**
	 * Parts in container order: database first, then files, each sorted by path.
	 *
	 * @param array<int,array<string,mixed>> $parts Part records.
	 * @return array<int,array<string,mixed>>
	 */
	public static function ordered_parts( array $parts ): array {
		usort(
			$parts,
			static function ( array $a, array $b ): int {
				$rank = array(
					'database'   => 0,
					'files'      => 1,
					'root-files' => 2,
				);
				return array( $rank[ $a['type'] ] ?? 9, $a['path'] ) <=> array( $rank[ $b['type'] ] ?? 9, $b['path'] );
			}
		);
		return $parts;
	}

	/**
	 * Contents of fmw.json.
	 *
	 * @param Job $job Job.
	 * @return array<string,mixed>
	 */
	private function header( Job $job ): array {
		$header = array(
			'format'     => 'fmw',
			'version'    => 1,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $job->created_at ),
			'generator'  => (string) ( $job->options['generator'] ?? 'fmw' ),
			'encrypted'  => ! empty( $job->options['encrypt'] ),
		);
		if ( $header['encrypted'] ) {
			$header['kdf']           = array(
				'algorithm'  => 'pbkdf2-sha256',
				'iterations' => self::iterations( $job ),
			);
			$header['cipher']        = FmwCrypto::CIPHER;
			$header['manifest_hmac'] = self::NO_HMAC; // Replaced once the manifest is written.
		}
		return $header;
	}

	/**
	 * Contents of manifest.json (format v1, section 4).
	 *
	 * @param Job                            $job   Job.
	 * @param array<int,array<string,mixed>> $parts Part records in container order.
	 * @return array<string,mixed>
	 */
	private function manifest( Job $job, array $parts ): array {
		$site = (array) ( $job->options['site'] ?? array() );
		if ( isset( $job->data['db_server'] ) && ! isset( $site['db']['version'] ) ) {
			$site['db'] = (array) ( $site['db'] ?? array() ) + (array) $job->data['db_server'];
		}

		$exclude = array_values( array_map( 'strval', (array) ( $job->options['exclude'] ?? array() ) ) );
		if ( ! empty( $job->options['exclude_database'] ) ) {
			$exclude[] = 'database';
		}

		return array(
			'format'     => 'fmw',
			'version'    => 1,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $job->created_at ),
			'generator'  => (string) ( $job->options['generator'] ?? 'fmw' ),
			'site'       => $site,
			'options'    => array(
				'exclude'            => $exclude,
				'exclude_tables'     => array_values( (array) ( $job->options['exclude_tables'] ?? array() ) ),
				'exclude_paths'      => array_values( (array) ( $job->options['exclude_paths'] ?? array() ) ),
				'include_root_files' => false,
				'part_size'          => (int) ( $job->options['part_size'] ?? FilesStep::DEFAULT_PART_SIZE ),
				'encrypted'          => ! empty( $job->options['encrypt'] ),
			),
			'totals'     => array(
				'files'          => (int) ( $job->data['scan']['files'] ?? 0 ),
				'tables'         => (int) ( $job->data['database']['tables'] ?? 0 ),
				'rows'           => (int) ( $job->data['database']['rows'] ?? 0 ),
				'bytes_raw'      => (int) array_sum( array_column( $parts, 'bytes_raw' ) ),
				'bytes_archived' => (int) array_sum( array_column( self::stored_parts( $job, $parts ), 'bytes' ) ),
			),
			'parts'      => self::stored_parts( $job, $parts ),
		);
	}

	/**
	 * Part records as stored: for encrypted backups the .enc name, stored size, SHA-256 and HMAC of the ciphertext.
	 *
	 * @param Job                            $job   Job.
	 * @param array<int,array<string,mixed>> $parts Part records.
	 * @return array<int,array<string,mixed>>
	 */
	private static function stored_parts( Job $job, array $parts ): array {
		if ( empty( $job->options['encrypt'] ) ) {
			return $parts;
		}
		foreach ( $parts as $i => $part ) {
			$stored      = (array) ( $job->data['encrypted_parts'][ $part['path'] ] ?? array() );
			$parts[ $i ] = array(
				'path'        => self::stored_name( (string) $part['path'] ),
				'bytes'       => (int) ( $stored['bytes'] ?? 0 ),
				'bytes_plain' => (int) $part['bytes'],
				'sha256'      => (string) ( $stored['sha256'] ?? '' ),
				'hmac'        => (string) ( $stored['hmac'] ?? '' ),
			) + $part;
		}
		return $parts;
	}

	/**
	 * Pretty JSON.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 * @throws JobException When encoding fails.
	 */
	private static function json( array $data ): string {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			throw new JobException( 'Cannot encode the manifest.' );
		}
		return $json . "\n";
	}
}
