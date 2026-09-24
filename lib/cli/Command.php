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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

use Founders\Migration\Requirements;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;
use WP_CLI;

/**
 * Backup, restore and migrate WordPress sites of any size.
 *
 * Command names and flags mirror `wp ai1wm`, so existing muscle memory works.
 *
 * ## EXAMPLES
 *
 *     wp fmw list-backups
 *     wp fmw backup --exclude-cache
 *     wp fmw restore example.com-20260924-180000-a1b2c3.fmw
 */
final class Command {

	/**
	 * Lists backups in the backups folder, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--porcelain]
	 * : Print file names only, one per line.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw list-backups
	 *     wp fmw list-backups --format=json
	 *
	 * @subcommand list-backups
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function list_backups( $args, $assoc_args ) {
		$backups = Backups::all();

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			foreach ( $backups as $backup ) {
				WP_CLI::line( $backup['name'] );
			}
			return;
		}

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! $backups && 'table' === $format ) {
			WP_CLI::line( 'No backups found in ' . fmwp_backups_path() );
			return;
		}

		$rows = array_map(
			static function ( $backup ) use ( $format ) {
				return array(
					'name' => $backup['name'],
					'date' => wp_date( 'Y-m-d H:i:s', $backup['mtime'] ),
					'size' => 'table' === $format ? size_format( $backup['size'], 1 ) : $backup['size'],
				);
			},
			$backups
		);

		WP_CLI\Utils\format_items( $format, $rows, array( 'name', 'date', 'size' ) );
	}

	/**
	 * Deletes a backup from the backups folder.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name, as shown by `wp fmw list-backups`.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$path = Backups::find( $args[0] );
		if ( null === $path ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found in %s.', $args[0], fmwp_backups_path() ) );
		}

		WP_CLI::confirm( sprintf( 'Delete %s?', $args[0] ), $assoc_args );
		wp_delete_file( $path );

		if ( file_exists( $path ) ) {
			WP_CLI::error( sprintf( 'Could not delete %s.', $path ) );
		}
		WP_CLI::success( sprintf( 'Deleted %s.', $args[0] ) );
	}

	/**
	 * Shows server requirements and data folder status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw status
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		Paths::ensure_all();

		$rows = array(
			array(
				'item'  => 'FMW version',
				'value' => FMWP_VERSION,
			),
			array(
				'item'  => 'Archive format',
				'value' => (string) FMWP_FORMAT_VERSION,
			),
			array(
				'item'  => 'PHP',
				'value' => PHP_VERSION . ' (' . ( PHP_INT_SIZE * 8 ) . '-bit)',
			),
			array(
				'item'  => 'Backups folder',
				'value' => fmwp_backups_path() . ( wp_is_writable( fmwp_backups_path() ) ? '' : ' (NOT WRITABLE)' ),
			),
			array(
				'item'  => 'Storage folder',
				'value' => fmwp_storage_path() . ( wp_is_writable( fmwp_storage_path() ) ? '' : ' (NOT WRITABLE)' ),
			),
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );

		foreach ( Requirements::errors() as $error ) {
			WP_CLI::warning( $error );
		}
		foreach ( Requirements::recommendations() as $note ) {
			WP_CLI::log( 'Note: ' . $note );
		}
	}

	/**
	 * Creates a backup. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [--<field>=<value>]
	 * : Flags follow `wp ai1wm backup` (see docs/format-v1.md and the README).
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function backup( $args, $assoc_args ) {
		$this->planned( 'backup', 1 );
	}

	/**
	 * Restores a .fmw (or .wpress) backup. Planned for phase 1 (.wpress in phase 2).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name or path.
	 *
	 * [--<field>=<value>]
	 * : Flags follow `wp ai1wm restore`.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$this->planned( 'restore', 1 );
	}

	/**
	 * Resumes an interrupted job. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [<job_id>]
	 * : Job ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		$this->planned( 'resume', 1 );
	}

	/**
	 * Verifies the checksums of every part of a backup. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name or path.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$this->planned( 'verify', 1 );
	}

	/**
	 * Shows the manifest of a backup. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name or path.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function inspect( $args, $assoc_args ) {
		$this->planned( 'inspect', 1 );
	}

	/**
	 * Stops with a clear message for commands that are not built yet.
	 *
	 * @param string $command Subcommand.
	 * @param int    $phase   Roadmap phase.
	 * @return void
	 */
	private function planned( string $command, int $phase ): void {
		WP_CLI::error( sprintf( '`wp fmw %s` is planned for phase %d and is not available in %s yet.', $command, $phase, FMWP_VERSION ) );
	}
}
