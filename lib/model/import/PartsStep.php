<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Import;

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\CbcStream;
use Founders\Migration\Archive\Extractor;
use Founders\Migration\Archive\FmwCrypto;
use Founders\Migration\Archive\PathGuard;
use Founders\Migration\Archive\TarReader;
use Founders\Migration\Archive\UnsafePathException;
use Founders\Migration\Database\SqlGuard;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB parts with native file calls, like the archive library.

/**
 * Takes the parts out of the archive one at a time and applies them.
 *
 * For each part: copy it from the container to <job>/staging while hashing,
 * refuse it on a SHA-256 mismatch, then apply it:
 *
 * - file parts are extracted into wp-content (protected paths such as this
 *   plugin and the FMW folders are skipped; symlinks pointing outside the
 *   site are skipped with a log line);
 * - table dumps are imported into fmwtmp_ tables, statement by statement
 *   through SqlGuard, exactly once (see RestoreDatabase);
 * - the views / triggers file is kept for the swap step.
 *
 * Only one part is staged at a time and it is deleted once applied.
 */
final class PartsStep implements Step {

	const STAGING       = 'staging';
	const READ_BYTES    = 1048576;
	const DECRYPT_BYTES = 8388608; // A multiple of the AES block size.

	/**
	 * Database helper, one per process.
	 *
	 * @var RestoreDatabase|null
	 */
	private $restore = null;

	/**
	 * Hash of the part being copied, kept between slices of one process.
	 *
	 * @var array{path:string,offset:int,context:\HashContext}|null
	 */
	private $hash = null;

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Restore';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException On a corrupt or missing part.
	 */
	public function run( Job $job, Context $context ): bool {
		$parts  = (array) ( $job->data['manifest']['parts'] ?? array() );
		$cursor = $job->cursor + array(
			'p'       => 0,
			'phase'   => 'copy',
			'copied'  => 0,
			'entries' => 0,
		);
		$total  = (int) array_sum( array_column( $parts, 'bytes' ) );

		$job->bytes_total = $total;
		$count            = count( $parts );

		while ( $cursor['p'] < $count && $context->should_continue() ) {
			$part      = $parts[ $cursor['p'] ];
			$staged    = $context->dir() . '/' . self::STAGING . '/' . $part['path'];
			$encrypted = ! empty( $job->data['encrypted'] );
			$relative  = self::STAGING . '/' . ( $encrypted ? (string) preg_replace( '/\.enc$/', '', $part['path'] ) : $part['path'] );
			$plain     = $context->dir() . '/' . $relative;

			if ( 'copy' === $cursor['phase'] ) {
				$cursor['copied'] = $this->copy( $job, $part, $staged, (int) $cursor['copied'], $context );
				$job->bytes_done  = (int) array_sum( array_column( array_slice( $parts, 0, $cursor['p'] ), 'bytes' ) ) + $cursor['copied'];
				$context->report_progress();
				if ( $cursor['copied'] < (int) $part['bytes'] ) {
					continue;
				}
				$cursor['phase']     = $encrypted ? 'decrypt' : 'apply';
				$cursor['entries']   = 0;
				$cursor['decrypted'] = 0;
			}

			if ( 'decrypt' === $cursor['phase'] ) {
				// The part's SHA-256 matched the authenticated manifest: its ciphertext is genuine.
				$cursor['decrypted'] = $this->decrypt( $job, $part, $staged, $plain, (int) $cursor['decrypted'], $context );
				if ( $cursor['decrypted'] < (int) $part['bytes'] - FmwCrypto::HEADER ) {
					continue;
				}
				$cursor['phase'] = 'apply'; // The staged ciphertext stays until the part is applied, so a resume can always decrypt again.
			}

			if ( 'files' === $part['type'] ) {
				$done = $this->apply_files( $job, $plain, $cursor, $context );
			} elseif ( in_array( $part['table'] ?? '', array( 'views', 'triggers' ), true ) ) {
				$job->data['deferred']   = (array) ( $job->data['deferred'] ?? array() );
				$job->data['deferred'][] = $relative;
				$job->data['deferred']   = array_values( array_unique( $job->data['deferred'] ) );
				$done                    = true;
			} else {
				$done = $this->apply_sql( $job, $part, $plain, $context );
			}

			if ( ! $done ) {
				continue;
			}
			if ( ! in_array( $relative, (array) ( $job->data['deferred'] ?? array() ), true ) && is_file( $plain ) ) {
				unlink( $plain );
			}
			if ( $encrypted && is_file( $staged ) ) {
				unlink( $staged );
			}
			++$cursor['p'];
			$cursor['phase']   = 'copy';
			$cursor['copied']  = 0;
			$cursor['entries'] = 0;
		}

		if ( $cursor['p'] < count( $parts ) ) {
			$job->cursor = $cursor;
			return false;
		}

		$job->bytes_done = $total;
		$context->log( sprintf( 'All %d parts restored.', count( $parts ) ) );
		return true;
	}

	/**
	 * Copies (part of) a part from the archive to the staging folder, verifying it at the end.
	 *
	 * @param Job                 $job     Job.
	 * @param array<string,mixed> $part    Part record.
	 * @param string              $staged  Staging path.
	 * @param int                 $copied  Bytes already copied.
	 * @param Context             $context Context.
	 * @return int Bytes copied now.
	 * @throws JobException On a missing or corrupt part.
	 */
	private function copy( Job $job, array $part, string $staged, int $copied, Context $context ): int {
		if ( ! is_dir( dirname( $staged ) ) && ! mkdir( dirname( $staged ), 0700, true ) && ! is_dir( dirname( $staged ) ) ) {
			throw new JobException( sprintf( 'Cannot create %s.', dirname( $staged ) ) );
		}

		$reader = TarReader::open( (string) $job->options['archive'] );
		try {
			$entry = $reader->next();
			while ( null !== $entry && $entry->name !== $part['path'] ) {
				$entry = $reader->next();
			}
			if ( null === $entry ) {
				throw new JobException( sprintf( 'Part %s is missing from the archive.', $part['path'] ) );
			}
			if ( $entry->size !== (int) $part['bytes'] ) {
				throw new JobException( sprintf( 'Part %s has the wrong size; the archive is damaged.', $part['path'] ) );
			}
			$reader->skip_bytes( $copied );

			$out = fopen( $staged, 'c+b' );
			if ( false === $out || ! ftruncate( $out, $copied ) || 0 !== fseek( $out, $copied ) ) {
				throw new JobException( sprintf( 'Cannot write %s.', $staged ) );
			}
			$hash = $this->hash_context( $part['path'], $staged, $copied );

			try {
				// At least one chunk per call: the outer loop already used this slice's guaranteed unit.
				do {
					$data = $reader->read( self::READ_BYTES );
					if ( '' === $data ) {
						break;
					}
					if ( false === fwrite( $out, $data ) ) {
						throw new JobException( 'Write failed. The disk may be full.' );
					}
					hash_update( $hash, $data );
					$copied += strlen( $data );
				} while ( $copied < (int) $part['bytes'] && $context->should_continue() );
			} finally {
				fclose( $out );
			}
		} finally {
			$reader->close();
		}

		$this->hash = array(
			'path'    => $part['path'],
			'offset'  => $copied,
			'context' => $hash,
		);

		if ( $copied >= (int) $part['bytes'] ) {
			$this->hash = null;
			if ( ! hash_equals( (string) $part['sha256'], hash_final( $hash ) ) ) {
				unlink( $staged );
				throw new JobException( sprintf( 'Part %s is corrupt (SHA-256 mismatch). The archive is damaged; nothing from this part was applied.', $part['path'] ) );
			}
		}
		return $copied;
	}

	/**
	 * Decrypts (part of) a staged encrypted part into its plain file. Resumable:
	 * the CBC chaining block is the previous ciphertext block, read back from
	 * the staged file, so the only state is the number of bytes done.
	 *
	 * @param Job                 $job     Job.
	 * @param array<string,mixed> $part    Part record.
	 * @param string              $staged  Staged encrypted part.
	 * @param string              $plain   Plain file to write.
	 * @param int                 $done    Ciphertext bytes (after the header) already decrypted.
	 * @param Context             $context Context.
	 * @return int Ciphertext bytes decrypted so far.
	 * @throws JobException On a read / write error or bad padding.
	 */
	private function decrypt( Job $job, array $part, string $staged, string $plain, int $done, Context $context ): int {
		$password = Secrets::open( $job->options['secret_password'] ?? null );
		if ( null === $password ) {
			throw new JobException( 'The backup password is no longer available (the site\'s salts may have changed). Start the restore again.' );
		}
		$total = (int) $part['bytes'] - FmwCrypto::HEADER;
		$in    = fopen( $staged, 'rb' );
		$out   = fopen( $plain, 'c+b' );
		try {
			if ( false === $in || false === $out ) {
				throw new JobException( sprintf( 'Cannot decrypt %s.', $part['path'] ) );
			}
			$keys = FmwCrypto::keys( $password, FmwCrypto::salt( (string) fread( $in, FmwCrypto::HEADER ) ), (int) ( $job->data['kdf_iterations'] ?? FmwCrypto::ITERATIONS ) );
			$iv   = $keys['iv'];
			if ( $done > 0 ) {
				fseek( $in, FmwCrypto::HEADER + $done - FmwCrypto::BLOCK );
				$iv = (string) fread( $in, FmwCrypto::BLOCK );
			}
			if ( ! ftruncate( $out, $done ) || 0 !== fseek( $out, $done ) || 0 !== fseek( $in, FmwCrypto::HEADER + $done ) ) {
				throw new JobException( sprintf( 'Cannot decrypt %s.', $part['path'] ) );
			}
			$cipher = new CbcStream( $keys['key'], $iv, false );

			// At least one chunk per call: the outer loop already used this slice's guaranteed unit.
			do {
				$length = min( self::DECRYPT_BYTES, $total - $done );
				$data   = (string) fread( $in, $length );
				if ( strlen( $data ) !== $length ) {
					throw new JobException( sprintf( 'Part %s ended early while decrypting.', $part['path'] ) );
				}
				$done  += $length;
				$output = $done >= $total ? $cipher->finish( $data ) : $cipher->update( $data );
				if ( false === fwrite( $out, $output ) ) {
					throw new JobException( 'Write failed. The disk may be full.' );
				}
			} while ( $done < $total && $context->should_continue() );
		} catch ( ArchiveException $e ) {
			throw new JobException( sprintf( 'Part %s could not be decrypted: %s', $part['path'], $e->getMessage() ) );
		} finally {
			if ( false !== $in ) {
				fclose( $in );
			}
			if ( false !== $out ) {
				fclose( $out );
			}
		}
		return $done;
	}

	/**
	 * Hash context positioned at $offset: reused within a process, rebuilt from the staged bytes otherwise.
	 *
	 * @param string $path   Part path.
	 * @param string $staged Staging file.
	 * @param int    $offset Bytes staged so far.
	 * @return \HashContext
	 */
	private function hash_context( string $path, string $staged, int $offset ) {
		if ( null !== $this->hash && $this->hash['path'] === $path && $this->hash['offset'] === $offset ) {
			return $this->hash['context'];
		}
		$context = hash_init( 'sha256' );
		if ( $offset > 0 ) {
			$in = fopen( $staged, 'rb' );
			if ( false !== $in ) {
				hash_update_stream( $context, $in, $offset );
				fclose( $in );
			}
		}
		return $context;
	}

	/**
	 * Extracts a file part into wp-content, resuming after the entries already done.
	 *
	 * @param Job                 $job     Job.
	 * @param string              $staged  Staged part.
	 * @param array<string,mixed> $cursor  Cursor (entries is updated).
	 * @param Context             $context Context.
	 * @return bool Whether the part is fully extracted.
	 */
	private function apply_files( Job $job, string $staged, array &$cursor, Context $context ): bool {
		$root      = rtrim( (string) ( $job->options['target']['content_dir'] ?? '' ), '/' );
		$protected = array_map(
			static function ( $path ): string {
				return trim( (string) $path, '/' );
			},
			(array) ( $job->options['protect_paths'] ?? array() )
		);

		$reader    = TarReader::open( $staged );
		$extractor = new Extractor( $root );
		try {
			for ( $i = 0; $i < (int) $cursor['entries']; $i++ ) {
				if ( null === $reader->next() ) {
					return true;
				}
			}

			do {
				$entry = $reader->next();
				if ( null === $entry ) {
					return true;
				}
				++$cursor['entries'];

				$relative = PathGuard::relative( $entry->name ); // Unsafe names fail the restore.
				foreach ( $protected as $path ) {
					if ( '' !== $path && ( $relative === $path || 0 === strpos( $relative, $path . '/' ) ) ) {
						continue 2;
					}
				}

				if ( $entry->is_symlink() ) {
					try {
						$extractor->extract_entry( $reader, $entry );
					} catch ( UnsafePathException $e ) {
						$context->log( sprintf( 'Skipped symlink %s -> %s: it points outside the site.', $relative, $entry->linkname ) );
					}
					continue;
				}
				$extractor->extract_entry( $reader, $entry );
			} while ( $context->should_continue() );
		} finally {
			$reader->close();
			foreach ( $extractor->warnings() as $warning ) {
				$context->log( $warning );
			}
		}
		return false;
	}

	/**
	 * Imports a table dump into fmwtmp_ tables, exactly once per statement.
	 *
	 * @param Job                 $job     Job.
	 * @param array<string,mixed> $part    Part record.
	 * @param string              $staged  Staged part.
	 * @param Context             $context Context.
	 * @return bool Whether the file is fully imported.
	 */
	private function apply_sql( Job $job, array $part, string $staged, Context $context ): bool {
		if ( null === $this->restore ) {
			$this->restore = new RestoreDatabase();
		}
		$guard = new SqlGuard( (string) ( $job->data['manifest']['site']['table_prefix'] ?? 'wp_' ), RestoreDatabase::TMP );
		return ( new SqlImporter( $this->restore ) )->import( 'sql:' . $part['path'], $staged, $guard, $context );
	}
}
