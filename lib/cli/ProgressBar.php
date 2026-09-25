<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Cli;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Writes to the terminal stream.

/**
 * Terminal progress: bar, percentage, bytes, speed and ETA.
 *
 *     Files     ███████████░░░░░░░░░  56%  51.2 / 91.5 GB · 142 MB/s · ETA 4m 50s
 *
 * On a terminal the line is redrawn in place (at most 5 times per second).
 * Otherwise (cron, log file) one plain line is printed per 5% or every 30 seconds.
 */
final class ProgressBar {

	const WIDTH          = 20;
	const SPEED_WINDOW   = 30.0;
	const REDRAW_EVERY   = 0.2;
	const LOG_EVERY      = 30.0;
	const LOG_PERCENTAGE = 5;

	/**
	 * Output stream.
	 *
	 * @var resource
	 */
	private $stream;

	/**
	 * Whether the stream is an interactive terminal.
	 *
	 * @var bool
	 */
	private $tty;

	/**
	 * Returns the current time in seconds.
	 *
	 * @var callable(): float
	 */
	private $clock;

	/**
	 * Recent [time, bytes] samples for the moving average.
	 *
	 * @var array<int,array{0:float,1:int}>
	 */
	private $samples = array();

	/**
	 * Last output time.
	 *
	 * @var float
	 */
	private $last_output = 0.0;

	/**
	 * Last logged percentage bucket (non-TTY mode).
	 *
	 * @var int
	 */
	private $last_bucket = -1;

	/**
	 * Last phase shown.
	 *
	 * @var string
	 */
	private $last_phase = '';

	/**
	 * Whether a line is currently drawn without a trailing newline.
	 *
	 * @var bool
	 */
	private $open_line = false;

	/**
	 * Constructor.
	 *
	 * @param resource|null          $stream Output stream, STDERR by default.
	 * @param bool|null              $tty    Force TTY mode; detected when null.
	 * @param callable(): float|null $clock  Time source (tests).
	 */
	public function __construct( $stream = null, ?bool $tty = null, ?callable $clock = null ) {
		$this->stream = null === $stream ? STDERR : $stream;
		$this->tty    = null === $tty ? ( function_exists( 'stream_isatty' ) && stream_isatty( $this->stream ) ) : $tty;
		$this->clock  = null === $clock ? static function (): float {
			return microtime( true );
		} : $clock;
	}

	/**
	 * Reports progress.
	 *
	 * @param string $phase Step label.
	 * @param int    $done  Bytes done.
	 * @param int    $total Bytes total, 0 when unknown.
	 * @return void
	 */
	public function update( string $phase, int $done, int $total ): void {
		$now = ( $this->clock )();

		if ( $phase !== $this->last_phase ) {
			$this->samples = array();
		}
		$this->samples[] = array( $now, $done );
		$count           = count( $this->samples );
		while ( $count > 2 && $now - $this->samples[0][0] > self::SPEED_WINDOW ) {
			array_shift( $this->samples );
			--$count;
		}

		$speed = $this->speed();
		$eta   = ( null !== $speed && $speed > 0 && $total > $done ) ? (int) ceil( ( $total - $done ) / $speed ) : null;
		$line  = self::render( $phase, $done, $total, $speed, $eta );

		if ( $this->tty ) {
			if ( $phase !== $this->last_phase && $this->open_line ) {
				fwrite( $this->stream, "\n" );
			} elseif ( $now - $this->last_output < self::REDRAW_EVERY && $phase === $this->last_phase ) {
				return;
			}
			fwrite( $this->stream, "\r\033[2K" . $line );
			$this->open_line = true;
		} else {
			$bucket = $total > 0 ? (int) floor( 100 * $done / $total / self::LOG_PERCENTAGE ) : 0;
			if ( $phase === $this->last_phase && $bucket === $this->last_bucket && $now - $this->last_output < self::LOG_EVERY ) {
				return;
			}
			fwrite( $this->stream, $line . "\n" );
			$this->last_bucket = $bucket;
		}

		$this->last_output = $now;
		$this->last_phase  = $phase;
	}

	/**
	 * Ends the progress line.
	 *
	 * @return void
	 */
	public function finish(): void {
		if ( $this->tty && $this->open_line ) {
			fwrite( $this->stream, "\n" );
		}
		$this->open_line = false;
	}

	/**
	 * One progress line.
	 *
	 * @param string     $phase Step label.
	 * @param int        $done  Bytes done.
	 * @param int        $total Bytes total, 0 when unknown.
	 * @param float|null $speed Bytes per second.
	 * @param int|null   $eta   Seconds left.
	 * @return string
	 */
	public static function render( string $phase, int $done, int $total, ?float $speed, ?int $eta ): string {
		$label = str_pad( substr( $phase, 0, 9 ), 10 );

		if ( $total <= 0 ) {
			$parts = array( self::bytes( $done ) );
		} else {
			$ratio  = min( 1.0, $done / $total );
			$filled = (int) floor( $ratio * self::WIDTH );
			$bar    = str_repeat( '█', $filled ) . str_repeat( '░', self::WIDTH - $filled );
			$label .= $bar . ' ' . str_pad( (string) (int) floor( $ratio * 100 ), 3, ' ', STR_PAD_LEFT ) . '%  ';
			$parts  = array( self::bytes( $done, $total ) . ' / ' . self::bytes( $total ) );
		}

		if ( null !== $speed && $speed > 0 ) {
			$parts[] = self::bytes( (int) $speed ) . '/s';
		}
		if ( null !== $eta ) {
			$parts[] = 'ETA ' . self::duration( $eta );
		}

		return $label . implode( ' · ', $parts );
	}

	/**
	 * Human-readable size (1 KB = 1024 bytes).
	 *
	 * @param int      $bytes Size.
	 * @param int|null $scale Pick the unit from this size instead (keeps "done / total" in one unit).
	 * @return string
	 */
	public static function bytes( int $bytes, ?int $scale = null ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$base  = null === $scale ? $bytes : $scale;
		$power = 0;
		$max   = count( $units ) - 1;
		while ( $base >= 1024 && $power < $max ) {
			$base /= 1024;
			++$power;
		}
		$value = $bytes / ( 1024 ** $power );
		return ( 0 === $power ? (string) $bytes : number_format( $value, $value < 10 ? 2 : 1 ) ) . ' ' . $units[ $power ];
	}

	/**
	 * Compact duration: 45s, 4m 50s, 2h 05m.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return intdiv( $seconds, 60 ) . 'm ' . ( $seconds % 60 ) . 's';
		}
		return intdiv( $seconds, 3600 ) . 'h ' . str_pad( (string) intdiv( $seconds % 3600, 60 ), 2, '0', STR_PAD_LEFT ) . 'm';
	}

	/**
	 * Bytes per second over the sample window.
	 *
	 * @return float|null
	 */
	private function speed(): ?float {
		$count = count( $this->samples );
		if ( $count < 2 ) {
			return null;
		}
		$first   = $this->samples[0];
		$last    = $this->samples[ $count - 1 ];
		$elapsed = $last[0] - $first[0];
		return $elapsed > 0 ? ( $last[1] - $first[1] ) / $elapsed : null;
	}
}
