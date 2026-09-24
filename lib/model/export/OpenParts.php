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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FileSink;
use Founders\Migration\Archive\GzipFileSink;
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarWriter;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * The currently open .tar.gz and .tar parts of a FilesStep slice.
 *
 * Part state lives in the FilesStep cursor, so a new process reopens each
 * part at its last committed offset.
 */
final class OpenParts {

	/**
	 * Part folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Target raw bytes per part.
	 *
	 * @var int
	 */
	private $part_size;

	/**
	 * Compression level for gzip parts (1-9).
	 *
	 * @var int
	 */
	private $level;

	/**
	 * Open part per kind: { n: part number, at: committed offset, entries, raw }.
	 *
	 * @var array<string,array{n:int,at:int,entries:int,raw:int}>
	 */
	private $state;

	/**
	 * Next part number.
	 *
	 * @var int
	 */
	private $next;

	/**
	 * Finished part records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $parts;

	/**
	 * Kind => open sink.
	 *
	 * @var array<string,FileSink>
	 */
	private $sinks = array();

	/**
	 * Kind => open writer.
	 *
	 * @var array<string,TarWriter>
	 */
	private $writers = array();

	/**
	 * Constructor.
	 *
	 * @param string                         $dir       Part folder.
	 * @param int                            $part_size Target raw bytes per part.
	 * @param int                            $level     gzip level.
	 * @param array<string,mixed>            $cursor    FilesStep cursor.
	 * @param array<int,array<string,mixed>> $parts     Finished parts so far.
	 */
	public function __construct( string $dir, int $part_size, int $level, array $cursor, array $parts ) {
		$this->dir       = $dir;
		$this->part_size = $part_size;
		$this->level     = $level;
		$this->state     = (array) $cursor['open'];
		$this->next      = (int) $cursor['next_part'];
		$this->parts     = $parts;
	}

	/**
	 * Writer for a kind, rotating first when $incoming raw bytes would overflow the part.
	 *
	 * @param string $kind     "gz" or "tar".
	 * @param int    $incoming Raw bytes about to be added (0 = never rotate).
	 * @return TarWriter
	 */
	public function writer( string $kind, int $incoming ): TarWriter {
		if ( $incoming > 0 && isset( $this->state[ $kind ] ) && $this->state[ $kind ]['raw'] > 0 && $this->state[ $kind ]['raw'] + $incoming > $this->part_size ) {
			$this->finish( $kind );
		}

		if ( ! isset( $this->state[ $kind ] ) ) {
			$this->state[ $kind ] = array(
				'n'       => $this->next++,
				'at'      => 0,
				'entries' => 0,
				'raw'     => 0,
			);
		}

		if ( ! isset( $this->writers[ $kind ] ) ) {
			$path                   = $this->path( $kind, $this->state[ $kind ]['n'] );
			$at                     = $this->state[ $kind ]['at'];
			$this->sinks[ $kind ]   = 'gz' === $kind ? new GzipFileSink( $path, $at, $this->level ) : new PlainFileSink( $path, $at );
			$this->writers[ $kind ] = new TarWriter( $this->sinks[ $kind ] );
		}

		$this->state[ $kind ]['raw'] += $incoming;
		return $this->writers[ $kind ];
	}

	/**
	 * Counts a finished entry in a part.
	 *
	 * @param string $kind Kind.
	 * @return void
	 */
	public function count_entry( string $kind ): void {
		++$this->state[ $kind ]['entries'];
	}

	/**
	 * Commits every open part and records the offsets.
	 *
	 * @return void
	 */
	public function commit_all(): void {
		foreach ( $this->writers as $kind => $writer ) {
			$this->state[ $kind ]['at'] = $writer->commit();
		}
	}

	/**
	 * Closes every open part for good.
	 *
	 * @return void
	 */
	public function finish_all(): void {
		foreach ( array_keys( $this->state ) as $kind ) {
			$this->finish( $kind );
		}
	}

	/**
	 * Releases file handles (after commit_all() or finish_all()).
	 *
	 * @return void
	 */
	public function close_all(): void {
		foreach ( $this->sinks as $sink ) {
			$sink->close();
		}
		$this->sinks   = array();
		$this->writers = array();
	}

	/**
	 * Open part state for the cursor.
	 *
	 * @return array<string,array{n:int,at:int,entries:int,raw:int}>
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Next part number for the cursor.
	 *
	 * @return int
	 */
	public function next_number(): int {
		return $this->next;
	}

	/**
	 * Finished part records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function parts(): array {
		return $this->parts;
	}

	/**
	 * Writes the end marker, hashes the part and records it.
	 *
	 * @param string $kind Kind.
	 * @return void
	 * @throws ArchiveException When the part cannot be hashed.
	 */
	private function finish( string $kind ): void {
		$state = $this->state[ $kind ];
		$this->writer( $kind, 0 )->finish();
		$this->sinks[ $kind ]->close();
		unset( $this->sinks[ $kind ], $this->writers[ $kind ], $this->state[ $kind ] );

		$path = $this->path( $kind, $state['n'] );
		$hash = hash_file( 'sha256', $path );
		if ( false === $hash ) {
			throw new ArchiveException( sprintf( 'Cannot hash %s.', $path ) );
		}

		$this->parts[] = array(
			'path'        => self::relative( $kind, $state['n'] ),
			'type'        => 'files',
			'compression' => 'gz' === $kind ? 'gzip' : 'none',
			'entries'     => $state['entries'],
			'bytes_raw'   => $state['raw'],
			'bytes'       => (int) filesize( $path ),
			'sha256'      => $hash,
		);
	}

	/**
	 * Absolute part path.
	 *
	 * @param string $kind   Kind.
	 * @param int    $number Part number.
	 * @return string
	 */
	private function path( string $kind, int $number ): string {
		return dirname( $this->dir ) . '/' . self::relative( $kind, $number );
	}

	/**
	 * Part path relative to the job folder, as used in the manifest.
	 *
	 * @param string $kind   Kind.
	 * @param int    $number Part number.
	 * @return string
	 */
	private static function relative( string $kind, int $number ): string {
		return sprintf( '%s/part-%04d.%s', FilesStep::PART_DIR, $number, 'gz' === $kind ? 'tar.gz' : 'tar' );
	}
}
