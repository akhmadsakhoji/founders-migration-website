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

use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
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
 * Reads job options: archive_dir, archive_name, site, exclude,
 * include_root_files, part_size, generator. Writes job data:
 * archive = { path, name, bytes }.
 */
final class PackageStep implements Step {

	const COPY_BYTES = 67108864;

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Package';
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

		$total = (int) array_sum( array_column( $parts, 'bytes' ) );
		$count = count( $parts );
		$sink  = new PlainFileSink( $partial, (int) $cursor['at'] );
		$tar   = new TarWriter( $sink );
		$done  = false;

		try {
			if ( 0 === (int) $cursor['at'] ) {
				$tar->add_string( 'fmw.json', self::json( $this->header( $job ) ), 0644, time() );
			}

			while ( $cursor['i'] < $count && $context->should_continue() ) {
				$part   = $parts[ $cursor['i'] ];
				$source = $context->dir() . '/' . $part['path'];
				if ( 0 === (int) $cursor['offset'] && ! is_file( $source ) ) {
					throw new JobException( sprintf( 'Part %s is missing from the job folder.', $part['path'] ) );
				}

				$before           = (int) $cursor['offset'];
				$cursor['offset'] = $tar->write_file_slice( $source, TarEntry::file( $part['path'], (int) $part['bytes'], 0644, time() ), $before, self::COPY_BYTES );
				$job->bytes_done  = (int) array_sum( array_column( array_slice( $parts, 0, $cursor['i'] ), 'bytes' ) ) + (int) $cursor['offset'];
				$job->bytes_total = $total;
				$context->report_progress();

				if ( $cursor['offset'] >= (int) $part['bytes'] ) {
					$cursor['copied'][] = $part['path'];
					++$cursor['i'];
					$cursor['offset'] = 0;
				}
			}

			if ( $cursor['i'] >= $count ) {
				$tar->add_string( 'manifest.json', self::json( $this->manifest( $job, $parts ) ), 0644, time() );
				$tar->finish();
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
		return array(
			'format'     => 'fmw',
			'version'    => 1,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $job->created_at ),
			'generator'  => (string) ( $job->options['generator'] ?? 'fmw' ),
			'encrypted'  => false,
		);
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
				'encrypted'          => false,
			),
			'totals'     => array(
				'files'          => (int) ( $job->data['scan']['files'] ?? 0 ),
				'tables'         => (int) ( $job->data['database']['tables'] ?? 0 ),
				'rows'           => (int) ( $job->data['database']['rows'] ?? 0 ),
				'bytes_raw'      => (int) array_sum( array_column( $parts, 'bytes_raw' ) ),
				'bytes_archived' => (int) array_sum( array_column( $parts, 'bytes' ) ),
			),
			'parts'      => $parts,
		);
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
