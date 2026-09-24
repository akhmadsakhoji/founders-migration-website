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

use Founders\Migration\Database\Connection;
use Founders\Migration\Database\SqlGuard;
use Founders\Migration\Database\SqlReader;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.

/**
 * Puts the imported tables live with one atomic RENAME TABLE, then recreates views and triggers.
 *
 * Every live table that is about to be replaced becomes fmwold_<name> in
 * the same statement, so the site switches from the old database to the new
 * one in a single step. Live tables that the backup does not contain are
 * left alone (a shared database may hold other sites' tables).
 *
 * Reads job options: target.table_prefix, keep_active_plugin.
 * Reads job data: manifest.site.table_prefix, deferred.
 */
final class SwapStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Swap';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When a table name would be too long.
	 */
	public function run( Job $job, Context $context ): bool {
		if ( empty( $job->data['has_db'] ) ) {
			return true;
		}

		$restore = new RestoreDatabase();
		$db      = $restore->db();
		$from    = (string) ( $job->data['manifest']['site']['table_prefix'] ?? 'wp_' );
		$to      = (string) ( $job->options['target']['table_prefix'] ?? 'wp_' );

		if ( 'done' !== $restore->progress( 'swap' ) ) {
			$imported = $restore->imported_tables();
			if ( $imported ) {
				$live    = array_flip( $restore->tables( $to ) );
				$renames = array();
				$old     = array();
				foreach ( $imported as $table ) {
					$base = substr( $table, strlen( RestoreDatabase::TMP ) );
					$dest = $to . $base;
					if ( strlen( $dest ) > 64 || strlen( RestoreDatabase::OLD . $base ) > 64 ) {
						throw new JobException( sprintf( 'Table name for %s would be longer than 64 characters.', $base ) );
					}
					if ( isset( $live[ $dest ] ) ) {
						$old[]     = RestoreDatabase::OLD . $base;
						$renames[] = Connection::identifier( $dest ) . ' TO ' . Connection::identifier( RestoreDatabase::OLD . $base );
					}
					$renames[] = Connection::identifier( $table ) . ' TO ' . Connection::identifier( $dest );
				}
				$restore->drop( $old ); // Leftovers from an earlier restore that kept its old tables.
				$db->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
				$context->log( sprintf( 'Switched %d tables to the restored database (%d previous tables kept as %s*).', count( $imported ), count( $old ), RestoreDatabase::OLD ) );
			}
			$restore->set_progress( 'swap', 'done' );
		}

		$this->create_objects( $job, $db, new SqlGuard( $from, $to ), $context );
		$this->keep_plugin_active( $job, $db, $to );
		$db->close();
		return true;
	}

	/**
	 * Recreates views and triggers with the target prefix.
	 *
	 * @param Job        $job     Job.
	 * @param Connection $db      Connection.
	 * @param SqlGuard   $guard   Guard mapping source to target prefix.
	 * @param Context    $context Context.
	 * @return void
	 */
	private function create_objects( Job $job, Connection $db, SqlGuard $guard, Context $context ): void {
		$count = 0;
		foreach ( (array) ( $job->data['deferred'] ?? array() ) as $relative ) {
			$path = $context->dir() . '/' . $relative;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$reader = new SqlReader( $path );
			try {
				$statement = $reader->next();
				while ( null !== $statement ) {
					$db->query( $guard->object_statement( $statement['sql'] ) );
					++$count;
					$statement = $reader->next();
				}
			} finally {
				$reader->close();
			}
		}
		if ( $count > 0 ) {
			$context->log( sprintf( 'Recreated views and triggers (%d statements).', $count ) );
		}
	}

	/**
	 * Keeps this plugin active in the restored site, so the admin can use it right away.
	 *
	 * @param Job        $job    Job.
	 * @param Connection $db     Connection.
	 * @param string     $prefix Target prefix.
	 * @return void
	 */
	private function keep_plugin_active( Job $job, Connection $db, string $prefix ): void {
		$plugin = (string) ( $job->options['keep_active_plugin'] ?? '' );
		$table  = Connection::identifier( $prefix . 'options' );
		if ( '' === $plugin || ! $db->column( 'SHOW TABLES LIKE ' . $db->quote( addcslashes( $prefix . 'options', '\\%_' ) ) ) ) {
			return;
		}

		$raw     = $db->column( "SELECT `option_value` FROM {$table} WHERE `option_name` = 'active_plugins'" );
		$plugins = isset( $raw[0] ) ? unserialize( (string) $raw[0], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes=false: no objects are created.
		$plugins = is_array( $plugins ) ? array_values( array_filter( $plugins, 'is_string' ) ) : array();
		if ( in_array( $plugin, $plugins, true ) ) {
			return;
		}
		$plugins[] = $plugin;
		sort( $plugins );
		$value = $db->quote( serialize( $plugins ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores the option serialized.
		if ( isset( $raw[0] ) ) {
			$db->query( "UPDATE {$table} SET `option_value` = {$value} WHERE `option_name` = 'active_plugins'" );
		} else {
			$db->query( "INSERT INTO {$table} (`option_name`, `option_value`) VALUES ('active_plugins', {$value})" );
		}
	}
}
