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
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Database\Connection;
use Founders\Migration\Database\SqlGuard;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Job files are handled with native calls, like the archive library.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.

/**
 * Imports the database.sql of a .wpress archive into fmwtmp_ tables.
 *
 * Runs before any site file is written, so a damaged or hostile dump stops
 * the restore while the site is still untouched.
 *
 * 1. extract: decodes database.sql into the job folder (resumable, CRC-checked);
 * 2. import: runs it through SqlGuard and SqlImporter (exactly once), with
 *    SERVMASK_PREFIX_ tables going to fmwtmp_; views are kept for the swap;
 * 3. unmask: All-in-One WP Migration also writes SERVMASK_PREFIX_ instead
 *    of the table prefix at the start of option names and user meta keys
 *    (wp_user_roles, wp_2_user_roles, wp_capabilities, ...). They get the
 *    source prefix back, and the replace step moves the prefix-based keys
 *    to the target prefix. Then the plugins and themes the backup had
 *    active are put back (see WpressActivation).
 */
final class WpressDatabaseStep implements Step {

	const SQL_FILE     = 'staging/database.sql';
	const OBJECTS_FILE = 'staging/objects.sql';

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Database';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException On damaged data.
	 */
	public function run( Job $job, Context $context ): bool {
		if ( empty( $job->data['has_db'] ) ) {
			return true;
		}
		$entry  = (array) $job->data['wpress']['database.sql'];
		$cursor = $job->cursor + array( 'phase' => 'extract' );
		$sql    = $context->dir() . '/' . self::SQL_FILE;

		if ( 'extract' === $cursor['phase'] ) {
			$job->bytes_total = (int) $entry['size'];
			try {
				$done = ( new WpressEntryWriter( $job ) )->write( (int) $entry['offset'], $sql, $cursor, $context );
			} catch ( ArchiveException $e ) {
				throw new JobException( $e->getMessage() );
			}
			$job->bytes_done = (int) ( $cursor['in'] ?? 0 );
			if ( ! $done ) {
				$job->cursor = $cursor;
				return false;
			}
			$cursor = array( 'phase' => 'import' );
			if ( ! $context->should_continue() ) {
				$job->cursor = $cursor;
				return false;
			}
		}

		$restore = new RestoreDatabase();
		if ( 'import' === $cursor['phase'] ) {
			$job->bytes_total = (int) filesize( $sql );
			$guard            = new SqlGuard( WpressPackage::SQL_PREFIX, RestoreDatabase::TMP, self::rename( $job ) );
			$done             = ( new SqlImporter( $restore ) )->import( 'wpress:database.sql', $sql, $guard, $context, $context->dir() . '/' . self::OBJECTS_FILE );
			if ( ! $done ) {
				$job->cursor = $cursor;
				$restore->db()->close();
				return false;
			}
			$job->data['deferred'] = array( self::OBJECTS_FILE );
			unlink( $sql );
			$cursor = array( 'phase' => 'unmask' );
		}

		if ( ! empty( $job->data['subsite']['picked'] ) ) {
			SubsiteExtract::unpick( $restore );
			$job->data['subsite']['network_sites'] = SubsiteExtract::note_sites( $restore );
		}
		if ( ! empty( $job->data['import']['picked'] ) ) {
			SubsiteExtract::unpick( $restore );
			// The backup's network, for the links to its other sites; dropped by ReplaceStep, never switched in.
			$noted = SubsiteExtract::note_sites( $restore );
			if ( $noted ) {
				$job->data['import']['network_sites'] = $noted;
				$job->data['import']['source_ids']    = array_values( array_unique( array_merge( (array) $job->data['import']['source_ids'], array_column( $noted, 'blog_id' ) ) ) );
			}
		}
		$this->unmask( $restore, (string) ( $job->data['manifest']['site']['table_prefix'] ?? 'wp_' ) );
		if ( is_array( $job->data['activate'] ?? null ) ) {
			$https = 'https' === strtolower( (string) parse_url( (string) ( $job->options['target']['home_url'] ?? '' ), PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs outside WordPress in tests.
			WpressActivation::apply( $restore, $job->data['activate'], $https, $context );
		}
		$context->log( sprintf( 'Imported the database into %d temporary tables.', count( $restore->imported_tables() ) ) );
		$restore->db()->close();
		return true;
	}

	/**
	 * The name each table of the dump gets (after the prefix), or null for all of them as they are.
	 *
	 * @param Job $job Job.
	 * @return callable|null
	 */
	private static function rename( Job $job ): ?callable {
		if ( ! empty( $job->data['import']['picked'] ) ) {
			$sites = (array) $job->data['import']['sites'];
			return static function ( string $table ) use ( $sites ): ?string {
				return SubsiteImport::picked_table( $table, $sites );
			};
		}
		$import = is_array( $job->data['import'] ?? null ) ? (int) $job->data['import']['blog_id'] : 0;
		if ( $import > 0 ) {
			return static function ( string $table ) use ( $import ): ?string {
				return SubsiteImport::table( $table, WpressPackage::SQL_PREFIX, $import );
			};
		}
		$subsite = $job->data['subsite'] ?? null;
		if ( ! is_array( $subsite ) ) {
			return null;
		}
		$blog = (int) $subsite['blog_id'];
		if ( ! empty( $subsite['picked'] ) ) {
			return static function ( string $table ) use ( $blog ): ?string {
				return SubsiteExtract::picked_table( $table, $blog );
			};
		}
		$ids = array_map( 'intval', (array) $subsite['site_ids'] );
		return static function ( string $table ) use ( $blog, $ids ): ?string {
			return SubsiteExtract::table( $table, WpressPackage::SQL_PREFIX, $blog, $ids );
		};
	}

	/**
	 * Gives option names and user meta keys their source prefix back. Idempotent.
	 *
	 * @param RestoreDatabase $restore Database helper.
	 * @param string          $prefix  Source table prefix.
	 * @return void
	 */
	private function unmask( RestoreDatabase $restore, string $prefix ): void {
		$db     = $restore->db();
		$mask   = WpressPackage::SQL_PREFIX;
		$like   = $db->escape( addcslashes( $mask, '\\%_' ) ) . '%';
		$start  = strlen( $mask ) + 1;
		$tables = array(
			'options'  => 'option_name',
			'usermeta' => 'meta_key',
		);
		foreach ( $restore->imported_tables() as $table ) {
			foreach ( $tables as $name => $column ) {
				if ( 1 === preg_match( '/^' . RestoreDatabase::TMP . ( 'options' === $name ? '([0-9]+_)?' : '' ) . $name . '$/D', $table ) ) {
					$db->query( 'UPDATE ' . Connection::identifier( $table ) . ' SET ' . Connection::identifier( $column ) . ' = CONCAT(' . $db->quote( $prefix ) . ', SUBSTRING(' . Connection::identifier( $column ) . ", {$start})) WHERE " . Connection::identifier( $column ) . " LIKE BINARY '{$like}'" );
				}
			}
		}
	}
}
