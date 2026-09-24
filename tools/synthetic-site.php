<?php
/**
 * Founders Migration Website
 *
 * Generates a fake wp-content tree for load and compatibility tests.
 *
 * Usage:
 *     php tools/synthetic-site.php --out=/tmp/site --size=10G [--files=20000] [--seed=1]
 *
 * About 70% of the bytes are incompressible "media" (.jpg/.mp4 with random
 * bytes), the rest is compressible text (.php/.css/.js). The tree also
 * contains edge cases: deep paths over 100 bytes, UTF-8 names, empty files,
 * a symlink, and one file above 8 GiB when --size allows it.
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable -- Developer tool, not shipped with the plugin.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$options = getopt( '', array( 'out:', 'size:', 'files::', 'seed::' ) );
if ( empty( $options['out'] ) || empty( $options['size'] ) ) {
	fwrite( STDERR, "Usage: php tools/synthetic-site.php --out=<dir> --size=<10G|500M> [--files=N] [--seed=N]\n" );
	exit( 1 );
}

/**
 * Parses 10G / 500M / 2048K / 1234 into bytes.
 */
function parse_bytes( string $value ): int {
	$units = array( 'K' => 1 << 10, 'M' => 1 << 20, 'G' => 1 << 30, 'T' => 1 << 40 );
	$unit  = strtoupper( substr( $value, -1 ) );
	return isset( $units[ $unit ] ) ? (int) ( (float) substr( $value, 0, -1 ) * $units[ $unit ] ) : (int) $value;
}

/**
 * Writes $size bytes, random for media and repeated text otherwise.
 */
function write_file( string $path, int $size, bool $media ): void {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0755, true );
	}
	$handle = fopen( $path, 'wb' );
	$line   = "<?php // Founders Migration Website synthetic fixture.\n\$value = 'lorem ipsum dolor sit amet';\n";
	$text   = substr( str_repeat( $line, (int) ceil( 1048576 / strlen( $line ) ) ), 0, 1048576 );
	$left   = $size;
	while ( $left > 0 ) {
		$step = min( $left, 1048576 );
		fwrite( $handle, $media ? random_bytes( $step ) : substr( $text, 0, $step ) );
		$left -= $step;
	}
	fclose( $handle );
}

$out   = rtrim( $options['out'], '/' ) . '/wp-content';
$total = parse_bytes( $options['size'] );
$count = isset( $options['files'] ) ? max( 10, (int) $options['files'] ) : 2000;
mt_srand( isset( $options['seed'] ) ? (int) $options['seed'] : 1 );

$written = 0;

// Edge cases first.
write_file( $out . '/uploads/empty.txt', 0, false );
write_file( $out . '/uploads/2026/09/foto-pernikahan-ñ-日本.jpg', 4096, true );
write_file( $out . '/plugins/' . str_repeat( 'very-long-directory-name/', 8 ) . 'deep.php', 2048, false );
@symlink( '2026/09/foto-pernikahan-ñ-日本.jpg', $out . '/uploads/latest.jpg' );
$written += 4096 + 2048;

if ( $total > 9 * ( 1 << 30 ) ) {
	// One file above the 8 GiB ustar limit. Created sparse to save time; it still reads as zeros.
	$big = $out . '/uploads/video/over-8gib.mp4';
	mkdir( dirname( $big ), 0755, true );
	$handle = fopen( $big, 'wb' );
	ftruncate( $handle, 9 * ( 1 << 30 ) );
	fclose( $handle );
	$written += 9 * ( 1 << 30 );
}

$average = max( 1, (int) ( ( $total - $written ) / $count ) );
for ( $i = 0; $i < $count && $written < $total; $i++ ) {
	$media = mt_rand( 1, 100 ) <= 70;
	$size  = min( $total - $written, (int) ( $average * mt_rand( 10, 190 ) / 100 ) );
	$path  = $media
		? sprintf( '%s/uploads/%d/%02d/img-%06d.%s', $out, mt_rand( 2019, 2026 ), mt_rand( 1, 12 ), $i, mt_rand( 0, 9 ) ? 'jpg' : 'mp4' )
		: sprintf( '%s/%s/component-%03d/file-%06d.%s', $out, mt_rand( 0, 1 ) ? 'plugins' : 'themes', $i % 150, $i, array( 'php', 'css', 'js' )[ $i % 3 ] );
	write_file( $path, $size, $media );
	$written += $size;
}

printf( "Created %s (%d files, %.2f GiB)\n", $out, $i + 4, $written / ( 1 << 30 ) );
