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

use Founders\Migration\Cli\ProgressBar;
use Founders\Migration\Tests\TestCase;

final class ProgressBarTest extends TestCase {

	public function test_line_shows_bar_bytes_speed_and_eta(): void {
		$gib  = 1024 ** 3;
		$line = ProgressBar::render( 'Files', (int) ( 51.2 * $gib ), (int) ( 91.5 * $gib ), 142 * 1024 * 1024, 290 );

		$this->assertSame( 'Files     ███████████░░░░░░░░░  55%  51.2 GB / 91.5 GB · 142.0 MB/s · ETA 4m 50s', $line );
	}

	public function test_unknown_total_shows_bytes_only(): void {
		$this->assertSame( 'Scan      512.0 KB', ProgressBar::render( 'Scan', 512 * 1024, 0, null, null ) );
	}

	public function test_formatting_helpers(): void {
		$this->assertSame( '0 B', ProgressBar::bytes( 0 ) );
		$this->assertSame( '1.50 KB', ProgressBar::bytes( 1536 ) );
		$this->assertSame( '0.50 GB', ProgressBar::bytes( 512 * 1024 * 1024, 1024 ** 3 ) );
		$this->assertSame( '45s', ProgressBar::duration( 45 ) );
		$this->assertSame( '4m 50s', ProgressBar::duration( 290 ) );
		$this->assertSame( '2h 05m', ProgressBar::duration( 7500 ) );
	}

	public function test_non_terminal_output_prints_every_five_percent(): void {
		$stream = fopen( 'php://memory', 'w+' );
		$now    = 1000.0;
		$bar    = new ProgressBar(
			$stream,
			false,
			static function () use ( &$now ) {
				return $now;
			}
		);

		for ( $percent = 0; $percent <= 100; $percent++ ) {
			$bar->update( 'Files', $percent, 100 );
			$now += 0.1;
		}
		$bar->finish();

		rewind( $stream );
		$lines = explode( "\n", trim( (string) stream_get_contents( $stream ), "\n" ) );
		$this->assertCount( 21, $lines, '0%, 5%, ..., 100%' );
		$this->assertStringContainsString( '100%', end( $lines ) );
		$this->assertStringContainsString( 'B/s', $lines[1] );
	}

	public function test_terminal_output_redraws_in_place(): void {
		$stream = fopen( 'php://memory', 'w+' );
		$now    = 1000.0;
		$bar    = new ProgressBar(
			$stream,
			true,
			static function () use ( &$now ) {
				return $now;
			}
		);

		$bar->update( 'Database', 10, 100 );
		$now += 0.01;
		$bar->update( 'Database', 11, 100 ); // Too soon: skipped.
		$now += 1;
		$bar->update( 'Database', 50, 100 );
		$bar->update( 'Files', 0, 100 ); // New phase: new line.
		$bar->finish();

		rewind( $stream );
		$out = (string) stream_get_contents( $stream );
		$this->assertSame( 3, substr_count( $out, "\r\033[2K" ) );
		$this->assertSame( 2, substr_count( $out, "\n" ) );
	}
}
