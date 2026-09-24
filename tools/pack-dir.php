<?php
/**
 * Founders Migration Website
 *
 * Packs a directory into .tar parts with the FMW archive library and reports
 * throughput. Mirrors the part rules of format v1: compressible files go to
 * .tar.gz parts, already-compressed media to .tar parts.
 *
 * Usage:
 *     php tools/pack-dir.php --src=/tmp/site/wp-content --out=/tmp/parts [--part-size=1G] [--level=6]
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable -- Developer tool, not shipped with the plugin.

use Founders\Migration\Archive\GzipFileSink;
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\TarWriter;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'FMWP_TESTS', true );
require dirname( __DIR__ ) . '/loader.php';

const STORE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'webm', 'mkv', 'mp3', 'm4a', 'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'woff', 'woff2', 'pdf' );
const COMMIT_EVERY     = 67108864; // 64 MiB.

$options = getopt( '', array( 'src:', 'out:', 'part-size::', 'level::' ) );
if ( empty( $options['src'] ) || empty( $options['out'] ) ) {
	fwrite( STDERR, "Usage: php tools/pack-dir.php --src=<dir> --out=<dir> [--part-size=1G] [--level=6]\n" );
	exit( 1 );
}

$units     = array( 'M' => 1 << 20, 'G' => 1 << 30 );
$part_size = isset( $options['part-size'] ) ? (int) ( (float) $options['part-size'] * ( $units[ strtoupper( substr( $options['part-size'], -1 ) ) ] ?? 1 ) ) : 1 << 30;
$level     = isset( $options['level'] ) ? (int) $options['level'] : 6;
$src       = rtrim( realpath( $options['src'] ), '/' );
$out       = rtrim( $options['out'], '/' );
@mkdir( $out, 0755, true );

/**
 * Opens part writers lazily and rotates them at the part size.
 */
final class Parts {
	private $writers = array();
	private $bytes   = array();
	private $number  = 0;
	private $out;
	private $part_size;
	private $level;
	public $warnings = array();
	public $created  = array();

	public function __construct( string $out, int $part_size, int $level ) {
		$this->out       = $out;
		$this->part_size = $part_size;
		$this->level     = $level;
	}

	public function writer( string $kind, int $incoming ): TarWriter {
		if ( isset( $this->writers[ $kind ] ) && $this->bytes[ $kind ] > 0 && $this->bytes[ $kind ] + $incoming > $this->part_size ) {
			$this->close( $kind );
		}
		if ( ! isset( $this->writers[ $kind ] ) ) {
			$path                   = sprintf( '%s/part-%04d.%s', $this->out, ++$this->number, 'gz' === $kind ? 'tar.gz' : 'tar' );
			$sink                   = 'gz' === $kind ? new GzipFileSink( $path, 0, $this->level ) : new PlainFileSink( $path );
			$this->writers[ $kind ] = new TarWriter( $sink );
			$this->bytes[ $kind ]   = 0;
			$this->created[]        = $path;
		}
		$this->bytes[ $kind ] += $incoming;
		return $this->writers[ $kind ];
	}

	public function close( string $kind ): void {
		$this->writers[ $kind ]->finish();
		$this->warnings = array_merge( $this->warnings, $this->writers[ $kind ]->warnings() );
		unset( $this->writers[ $kind ] );
	}

	public function close_all(): void {
		foreach ( array_keys( $this->writers ) as $kind ) {
			$this->close( $kind );
		}
	}
}

$parts    = new Parts( $out, $part_size, $level );
$started  = microtime( true );
$raw      = 0;
$files    = 0;
$last     = 0;
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $path => $info ) {
	$name = substr( $path, strlen( $src ) + 1 );
	if ( $info->isLink() ) {
		$parts->writer( 'gz', 0 )->add_symlink( $name, (string) readlink( $path ), (int) $info->getMTime() );
		continue;
	}
	if ( $info->isDir() ) {
		$parts->writer( 'gz', 0 )->add_directory( $name, $info->getPerms() & 0777, (int) $info->getMTime() );
		continue;
	}

	$size   = (int) $info->getSize();
	$kind   = in_array( strtolower( $info->getExtension() ), STORE_EXTENSIONS, true ) ? 'tar' : 'gz';
	$writer = $parts->writer( $kind, $size );
	$entry  = TarEntry::file( $name, $size, $info->getPerms() & 0777, (int) $info->getMTime() );
	$offset = 0;
	do {
		$before = $offset;
		$offset = $writer->write_file_slice( $path, $entry, $offset, COMMIT_EVERY );
		$writer->commit();
		$raw += $offset - $before;
	} while ( $offset < $size );
	++$files;

	if ( microtime( true ) - $last > 2 ) {
		$last    = microtime( true );
		$elapsed = $last - $started;
		fprintf( STDERR, "\r%d files · %.2f GiB · %.0f MB/s   ", $files, $raw / ( 1 << 30 ), $raw / 1e6 / max( $elapsed, 0.001 ) );
	}
}
$parts->close_all();

$elapsed  = microtime( true ) - $started;
$archived = array_sum( array_map( 'filesize', $parts->created ) );
fprintf( STDERR, "\r%s\r", str_repeat( ' ', 60 ) );
printf( "Packed %d files, %.2f GiB -> %.2f GiB in %d parts, %.1f s (%.0f MB/s)\n", $files, $raw / ( 1 << 30 ), $archived / ( 1 << 30 ), count( $parts->created ), $elapsed, $raw / 1e6 / max( $elapsed, 0.001 ) );
foreach ( $parts->warnings as $warning ) {
	echo "Warning: $warning\n";
}
