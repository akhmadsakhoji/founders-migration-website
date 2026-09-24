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
use Founders\Migration\Archive\Crc32;
use Founders\Migration\Archive\PathGuard;
use Founders\Migration\Archive\UnsafePathException;
use Founders\Migration\Archive\WpressDecoder;
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB archives with native file calls.

/**
 * Checks an All-in-One WP Migration (.wpress) archive before anything changes.
 *
 * 1. scan: walks every header (seeking over the data), refusing unsafe
 *    paths, damaged headers and truncated archives, and notes where
 *    package.json and database.sql are;
 * 2. analyse: reads package.json, checks the password, the compression
 *    support, multisite and disk space, and prepares the search-replace;
 * 3. crc: when the archive carries a CRC-32 (recent All-in-One WP Migration versions),
 *    reads it all once and compares, so a damaged download is refused before
 *    the site is touched. Resumable: the partial CRC is combined across slices.
 *
 * Reads job options: archive, target, secret_wpress_key (sealed), email_replace,
 * skip_space_check. Writes job data: wpress, manifest, replace, sql_prefix,
 * has_db.
 */
final class WpressCheckStep implements Step {

	const HASH_READ_BYTES = 8388608;

	/**
	 * Top-level files that hold the backup's configuration, not site files.
	 */
	const CONFIG_FILES = array( 'package.json', 'multisite.json', 'database.sql' );

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Check';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the restore cannot go ahead.
	 */
	public function run( Job $job, Context $context ): bool {
		$path   = (string) ( $job->options['archive'] ?? '' );
		$cursor = $job->cursor + array(
			'phase'  => 'scan',
			'offset' => 0,
		);
		$size   = (int) filesize( $path );

		$job->bytes_total = $size;

		try {
			if ( 'scan' === $cursor['phase'] ) {
				if ( ! $this->scan( $job, $path, $cursor, $context ) ) {
					$job->cursor     = $cursor;
					$job->bytes_done = (int) $cursor['offset'];
					return false;
				}
				$this->analyse( $job, $context );
				$cursor = array(
					'phase'  => 'crc',
					'hashed' => 0,
					'crc'    => '',
				);
				if ( ! $context->should_continue() ) {
					$job->cursor = $cursor;
					return false;
				}
			}

			if ( ! $this->verify_crc( $job, $path, $cursor, $context ) ) {
				$job->cursor     = $cursor;
				$job->bytes_done = (int) $cursor['hashed'];
				return false;
			}
		} catch ( ArchiveException $e ) {
			throw new JobException( $e->getMessage() );
		}

		$job->bytes_done = $size;
		return true;
	}

	/**
	 * Walks the headers from the cursor on.
	 *
	 * @param Job                 $job     Job.
	 * @param string              $path    Archive.
	 * @param array<string,mixed> $cursor  Cursor (offset is updated).
	 * @param Context             $context Context.
	 * @return bool Whether the end block was reached.
	 * @throws JobException On an unsafe path or a multisite archive.
	 */
	private function scan( Job $job, string $path, array &$cursor, Context $context ): bool {
		$info   = (array) ( $job->data['wpress'] ?? array() );
		$info  += array(
			'entries'      => 0,
			'files_stored' => 0,
		);
		$reader = WpressReader::open( $path, (int) $cursor['offset'] );
		try {
			do {
				$entry = $reader->next();
				if ( null === $entry ) {
					$info['crc']         = $reader->archive_crc();
					$job->data['wpress'] = $info;
					return true;
				}
				try {
					$relative = PathGuard::relative( $entry->name );
				} catch ( UnsafePathException $e ) {
					throw new JobException( $e->getMessage() . ' The archive is unsafe; nothing was changed.' );
				}

				++$info['entries'];
				if ( in_array( $relative, self::CONFIG_FILES, true ) ) {
					if ( 'multisite.json' === $relative ) {
						throw new JobException( 'This is a multisite network backup; restoring .wpress networks arrives in phase 3.' );
					}
					$info[ $relative ] = array(
						'offset' => $entry->offset,
						'size'   => $entry->size,
					);
				} else {
					$info['files_stored'] += $entry->size;
				}
				$cursor['offset'] = $reader->position();
			} while ( $context->should_continue() );
		} finally {
			$reader->close();
		}
		$job->data['wpress'] = $info;
		return false;
	}

	/**
	 * Reads package.json and prepares the restore.
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @return void
	 * @throws JobException When the restore cannot go ahead.
	 */
	private function analyse( Job $job, Context $context ): void {
		$info   = (array) $job->data['wpress'];
		$target = (array) ( $job->options['target'] ?? array() );
		if ( ! isset( $info['package.json'] ) ) {
			throw new JobException( 'The archive has no package.json; this is not a complete All-in-One WP Migration backup.' );
		}
		if ( ! empty( $target['multisite'] ) ) {
			throw new JobException( 'Restoring a .wpress backup onto a multisite network arrives in phase 3.' );
		}

		$package = WpressPackage::read( (string) $job->options['archive'] );
		$key     = null;
		if ( $package->encrypted() ) {
			$key = (string) Secrets::open( $job->options['secret_wpress_key'] ?? null );
			if ( 32 !== strlen( $key ) || ! $package->accepts_key( $key ) ) {
				throw new JobException( 'This backup is encrypted: start the restore again with the right password.' );
			}
		}
		new WpressDecoder( $key ? $key : null, $package->compression() ); // Fails early when a PHP extension is missing.

		$has_db = isset( $info['database.sql'] ) && ! $package->no_database();
		$db     = $has_db ? (int) $info['database.sql']['size'] : 0;
		$files  = (int) $info['files_stored'];
		$factor = 'none' === $package->compression() ? 1 : 3; // Compressed data grows when restored.

		// Extracted files + the SQL file + the database twice (live and imported), plus 10%.
		$needed = (int) ( ( $files * $factor + 3 * $db * $factor ) * 1.1 );
		$free   = @disk_free_space( (string) ( $target['content_dir'] ?? '.' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host; treated as unknown.
		if ( false !== $free && $free < $needed && empty( $job->options['skip_space_check'] ) ) {
			throw new JobException( sprintf( 'Not enough disk space: the restore needs about %d MB, %d MB are free. Free up space or pass --skip-space-check.', (int) ( $needed / 1048576 ), (int) ( $free / 1048576 ) ) );
		}

		if ( $has_db ) {
			$restore = new RestoreDatabase();
			$restore->drop( $restore->tables( RestoreDatabase::TMP ) ); // Leftovers of an earlier, abandoned restore.
			$restore->create_progress();
			$restore->db()->close();
		}

		$home = (string) ( $package->data()['HomeURL'] ?? '' );
		$site = (string) ( $package->data()['SiteURL'] ?? $home );

		$job->data['manifest']    = array(
			'site'    => array(
				'home_url'     => $home,
				'site_url'     => $site,
				'abspath'      => $package->wordpress( 'Absolute' ),
				'table_prefix' => $package->table_prefix(),
			),
			'options' => array(),
			'totals'  => array( 'files' => (int) $info['entries'] ),
			'parts'   => array(),
		);
		$job->data['sql_prefix']  = WpressPackage::SQL_PREFIX;
		$job->data['has_db']      = $has_db;
		$job->data['compression'] = $package->compression();
		$job->data['encrypted']   = $package->encrypted();
		$job->data['replace']     = self::replace_plan( $package, $target, ! empty( $job->options['email_replace'] ) );

		$context->log(
			sprintf(
				'Restoring %s (All-in-One WP Migration %s backup%s%s) onto %s: %d files%s.',
				'' !== $home ? $home : '?',
				'' !== $package->plugin_version() ? $package->plugin_version() : '?',
				$package->encrypted() ? ', encrypted' : '',
				'none' !== $package->compression() ? ', ' . $package->compression() . ' compressed' : '',
				(string) ( $target['home_url'] ?? '?' ),
				(int) $info['entries'] - count( array_intersect( array_keys( $info ), self::CONFIG_FILES ) ),
				$has_db ? ' and the database' : ', no database'
			)
		);
	}

	/**
	 * URL, path and value pairs for the replace step, in the target site's terms.
	 *
	 * @param WpressPackage       $package Package.
	 * @param array<string,mixed> $target  Target site.
	 * @param bool                $email   Replace e-mail domains.
	 * @return array{urls:array<string,string>,paths:array<string,string>,raw:array<string,string>,email:bool}
	 */
	public static function replace_plan( WpressPackage $package, array $target, bool $email ): array {
		$data     = $package->data();
		$home     = (string) ( $target['home_url'] ?? '' );
		$site_url = (string) ( $target['site_url'] ?? $home );
		$urls     = array();
		foreach ( array(
			'HomeURL'         => $home,
			'SiteURL'         => $site_url,
			'InternalHomeURL' => $home,
			'InternalSiteURL' => $site_url,
		) as $key => $new ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) && ! isset( $urls[ $data[ $key ] ] ) ) {
				$urls[ $data[ $key ] ] = $new;
			}
		}
		if ( '' !== $package->wordpress( 'UploadsURL' ) && ! empty( $target['uploads_url'] ) ) {
			$urls[ $package->wordpress( 'UploadsURL' ) ] = (string) $target['uploads_url'];
		}

		$paths = array();
		foreach ( array(
			'Absolute' => 'abspath',
			'Content'  => 'content_dir',
			'Uploads'  => 'uploads_dir',
		) as $key => $name ) {
			if ( '' !== $package->wordpress( $key ) && ! empty( $target[ $name ] ) ) {
				$paths[ $package->wordpress( $key ) ] = (string) $target[ $name ];
			}
		}

		return array(
			'urls'  => $urls,
			'paths' => $paths,
			'raw'   => $package->replace_pairs(),
			'email' => $email && empty( $data['NoEmailReplace'] ),
		);
	}

	/**
	 * Compares the archive with the CRC-32 in its end block, if it has one.
	 *
	 * @param Job                 $job     Job.
	 * @param string              $path    Archive.
	 * @param array<string,mixed> $cursor  Cursor (hashed, crc are updated).
	 * @param Context             $context Context.
	 * @return bool Whether the check is finished.
	 * @throws JobException On a mismatch.
	 */
	private function verify_crc( Job $job, string $path, array &$cursor, Context $context ): bool {
		$expected = $job->data['wpress']['crc'] ?? null;
		if ( ! is_array( $expected ) ) {
			$context->log( 'This archive has no checksum (older All-in-One WP Migration versions); its structure was checked.' );
			return true;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, (int) $cursor['hashed'] ) ) {
			throw new JobException( sprintf( 'Cannot read %s.', $path ) );
		}
		$total = (int) $expected['size'];
		$hash  = hash_init( 'crc32b' );
		$done  = 0;
		try {
			do {
				$length = min( self::HASH_READ_BYTES, $total - (int) $cursor['hashed'] - $done );
				if ( $length <= 0 ) {
					break;
				}
				$data = fread( $handle, $length );
				if ( false === $data || strlen( $data ) !== $length ) {
					throw new JobException( 'Unexpected end of the archive while checking it.' );
				}
				hash_update( $hash, $data );
				$done += $length;
			} while ( $context->should_continue() );
		} finally {
			fclose( $handle );
		}
		// One combine per slice keeps the checksum resumable across processes.
		$piece            = hash_final( $hash );
		$cursor['crc']    = '' === $cursor['crc'] ? $piece : Crc32::combine( (string) $cursor['crc'], $piece, $done );
		$cursor['hashed'] = (int) $cursor['hashed'] + $done;

		if ( (int) $cursor['hashed'] < (int) $expected['size'] ) {
			return false;
		}
		if ( ! hash_equals( (string) $expected['crc'], (string) $cursor['crc'] ) ) {
			throw new JobException( 'The archive is damaged (CRC-32 mismatch); nothing was changed. Download or copy it again.' );
		}
		$context->log( 'Archive checksum verified.' );
		return true;
	}
}
