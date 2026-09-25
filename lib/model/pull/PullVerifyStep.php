<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Pull;

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\TarReader;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Checks every part of a download-only pull against its SHA-256, part by
 * part and resumably, before the backup on the source may be deleted. (A
 * pull with restore needs no extra pass: the restore checks every part
 * before it uses it.) Without the password of an encrypted backup the
 * manifest cannot be read; the copy on the source is then kept.
 *
 * Reads job data: archive (path). Sets job data: pull (verified).
 */
final class PullVerifyStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Verify';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When a part is missing or damaged.
	 */
	public function run( Job $job, Context $context ): bool {
		$path = (string) ( $job->data['archive']['path'] ?? '' );
		try {
			$archive  = new FmwArchive( $path );
			$password = $archive->encrypted() ? Secrets::open( $job->options['secret_password'] ?? null ) : null;
			if ( $archive->encrypted() && null === $password ) {
				$job->data['pull']['verified'] = false;
				$context->log( 'Without the backup\'s password its parts cannot be checked here, so the copy on the source site is kept.' );
				return true;
			}
			$manifest = $archive->manifest( $password );
		} catch ( ArchiveException $e ) {
			throw new JobException( sprintf( 'The downloaded backup cannot be read: %s', $e->getMessage() ) );
		}
		$expected = array();
		$total    = 0;
		foreach ( (array) $manifest['parts'] as $part ) {
			$expected[ (string) $part['path'] ] = $part;
			$total                             += (int) $part['bytes'];
		}
		$cursor           = $job->cursor + array(
			'entry'   => 0,
			'checked' => 0,
			'bytes'   => 0,
		);
		$job->bytes_total = $total;

		$reader = TarReader::open( $path );
		try {
			for ( $index = 0; ; $index++ ) {
				$entry = $reader->next();
				if ( null === $entry ) {
					break;
				}
				if ( $index < $cursor['entry'] || ! isset( $expected[ $entry->name ] ) ) {
					continue; // Checked in an earlier slice, or fmw.json / the manifest.
				}
				$part = $expected[ $entry->name ];
				$hash = hash_init( 'sha256' );
				$size = 0;
				for ( $data = $reader->read( 1048576 ); '' !== $data; $data = $reader->read( 1048576 ) ) {
					hash_update( $hash, $data );
					$size += strlen( $data );
				}
				if ( $size !== (int) $part['bytes'] || ! hash_equals( (string) $part['sha256'], hash_final( $hash ) ) ) {
					throw new JobException( sprintf( 'Part %s of the downloaded backup is damaged. The copy on the source site was kept: pull again (with --backup=%s and a key created with --allow-existing to download only).', $entry->name, (string) ( $job->data['pull']['name'] ?? '' ) ) );
				}
				$cursor['entry']   = $index + 1;
				$cursor['checked'] = (int) $cursor['checked'] + 1;
				$cursor['bytes']   = (int) $cursor['bytes'] + $size;
				$job->cursor       = $cursor;
				$job->bytes_done   = (int) $cursor['bytes'];
				$context->report_progress();
				if ( ! $context->should_continue() ) {
					return false;
				}
			}
		} catch ( ArchiveException $e ) {
			throw new JobException( sprintf( 'The downloaded backup is damaged: %s', $e->getMessage() ) );
		} finally {
			$reader->close();
		}
		if ( count( $expected ) !== (int) $cursor['checked'] ) {
			throw new JobException( sprintf( 'The downloaded backup has %d of its %d parts.', (int) $cursor['checked'], count( $expected ) ) );
		}
		$job->data['pull']['verified'] = true;
		$context->log( sprintf( 'All %d parts of the download match their SHA-256.', count( $expected ) ) );
		return true;
	}
}
