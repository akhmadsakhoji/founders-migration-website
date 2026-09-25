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
 * With the replace_all_tables option (a reset) every other table of the site
 * (see RestoreDatabase::site_tables()) is moved aside to fmwold_* as well,
 * in the same statement, and the site's views are removed. On a multisite
 * network, the tables of this network's sites that the backup does not have
 * are moved aside too (see orphan_site_tables()).
 *
 * Reads job options: target.table_prefix, keep_active_plugin, keep_active_network,
 * replace_all_tables.
 * Reads job data: manifest.site.table_prefix, sql_prefix (prefix used in the
 * dump, if different), deferred, network.
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
			$import   = is_array( $job->data['import'] ?? null ) ? $job->data['import'] : null;
			$network  = (array) ( $job->data['network_target'] ?? $job->options['target'] );
			$imported = $restore->imported_tables();
			if ( null !== $import ) {
				SubsiteImport::check_site( $restore, $import, $network );
				// Merged into the network's users, never switched in (nor the backup network's blogs table).
				$imported = array_values( array_diff( $imported, array( RestoreDatabase::TMP . 'users', RestoreDatabase::TMP . 'usermeta', RestoreDatabase::TMP . 'blogs' ) ) );
			}
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
				if ( ! empty( $job->options['replace_all_tables'] ) ) {
					$fresh = array();
					foreach ( $imported as $table ) {
						$fresh[ $to . substr( $table, strlen( RestoreDatabase::TMP ) ) ] = true;
					}
					$site = (int) ( $job->options['reset_site'] ?? 0 );
					if ( $site > 0 && ( 1 === $site || (int) ( $job->options['target']['network']['main_site'] ?? 1 ) === $site || ! $db->column( 'SELECT `blog_id` FROM ' . Connection::identifier( $to . 'blogs' ) . " WHERE `blog_id` = {$site}" ) ) ) {
						throw new JobException( sprintf( 'Site %d is gone from the network (or became its main site) since the reset started; nothing was switched. Cancel the job.', $site ) );
					}
					// A site of a network: its own tables (wp_<id>_*) only; else every table of the site.
					foreach ( $site > 0 ? SubsiteImport::leftovers( array_keys( $live ), array_keys( $fresh ), $to, $site ) : $restore->site_tables( $to ) as $table ) {
						$base = substr( $table, strlen( $to ) );
						if ( isset( $fresh[ $table ] ) || '' === $base ) {
							continue;
						}
						if ( strlen( RestoreDatabase::OLD . $base ) > 64 ) {
							throw new JobException( sprintf( 'Table name for %s would be longer than 64 characters.', $base ) );
						}
						$old[]     = RestoreDatabase::OLD . $base;
						$renames[] = Connection::identifier( $table ) . ' TO ' . Connection::identifier( RestoreDatabase::OLD . $base );
					}
				}
				if ( is_array( $job->data['network'] ?? null ) ) {
					$replaced = array();
					foreach ( $imported as $table ) {
						$replaced[] = $to . substr( $table, strlen( RestoreDatabase::TMP ) );
					}
					$ids = isset( $live[ $to . 'blogs' ] ) ? array_map( 'intval', $db->column( 'SELECT `blog_id` FROM ' . Connection::identifier( $to . 'blogs' ) ) ) : array();
					foreach ( array_diff( self::orphan_site_tables( $job->data['network'], array_keys( $live ), $to, $ids ), $replaced ) as $table ) {
						$base = substr( $table, strlen( $to ) );
						if ( strlen( RestoreDatabase::OLD . $base ) > 64 ) {
							throw new JobException( sprintf( 'Table name for %s would be longer than 64 characters.', $base ) );
						}
						$old[]     = RestoreDatabase::OLD . $base;
						$renames[] = Connection::identifier( $table ) . ' TO ' . Connection::identifier( RestoreDatabase::OLD . $base );
					}
				}
				if ( is_array( $job->data['import'] ?? null ) ) {
					$replaced = array();
					foreach ( $imported as $table ) {
						$replaced[] = $to . substr( $table, strlen( RestoreDatabase::TMP ) );
					}
					// The site's tables that the backup does not have (an overwritten site's plugin tables) go aside too.
					$aside = array();
					foreach ( SubsiteImport::sites( $job->data['import'] ) as $into ) {
						$aside = array_merge( $aside, SubsiteImport::leftovers( array_keys( $live ), $replaced, $to, (int) $into['blog_id'] ) );
					}
					foreach ( $aside as $table ) {
						$base = substr( $table, strlen( $to ) );
						if ( strlen( RestoreDatabase::OLD . $base ) > 64 ) {
							throw new JobException( sprintf( 'Table name for %s would be longer than 64 characters.', $base ) );
						}
						$old[]     = RestoreDatabase::OLD . $base;
						$renames[] = Connection::identifier( $table ) . ' TO ' . Connection::identifier( RestoreDatabase::OLD . $base );
					}
				}
				// Every name is checked: only now does the live network change.
				if ( null !== $import && ! SubsiteImport::merge_users( $restore, $import, $network, $context ) ) {
					$db->close();
					return false;
				}
				$restore->drop( $old ); // Leftovers from an earlier restore that kept its old tables.
				$db->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
				$job->data['old_tables'] = $old; // FinalizeStep removes these, not other jobs' kept fmwold_* tables.
				if ( ! empty( $job->data['import_left'] ) ) {
					$context->log( sprintf( 'Left out %d files outside uploads, plugins, themes and languages (mu-plugins, drop-ins and other wp-content folders would change every site of the network).', (int) $job->data['import_left'] ) );
				}
				$context->log( sprintf( 'Switched %d tables to the %s database (%d previous tables kept as %s*).', count( $imported ), 'reset' === $job->type ? 'fresh' : 'restored', count( $old ), RestoreDatabase::OLD ) );
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush(); // A persistent object cache still holds the previous database's options.
				}
			} elseif ( null !== $import && ! SubsiteImport::merge_users( $restore, $import, $network, $context ) ) {
				$db->close();
				return false;
			}
			if ( null !== $import ) {
				SubsiteImport::register( $restore, $import, $network, $context );
				if ( ! $imported && function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush(); // Resumed after the switch: the flush above did not run.
				}
				if ( array_filter( array_column( SubsiteImport::sites( $import ), 'new' ) ) && function_exists( 'wp_update_network_site_counts' ) ) {
					wp_update_network_site_counts();
				}
			}
			if ( (int) ( $job->options['reset_site'] ?? 0 ) > 0 ) {
				self::site_roles( $db, $to, (int) $job->options['reset_site'], array_map( 'intval', (array) ( $job->options['keep_users'] ?? array() ) ), $context );
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush(); // Its roles changed by SQL; on a resume after the switch nothing above flushed.
				}
			}
			if ( ! empty( $job->options['replace_all_tables'] ) ) {
				$views = $restore->site_tables( $to . ( (int) ( $job->options['reset_site'] ?? 0 ) > 0 ? (int) $job->options['reset_site'] . '_' : '' ), 'VIEW' ); // They would point at tables that are gone.
				foreach ( $views as $view ) {
					$db->query( 'DROP VIEW IF EXISTS ' . Connection::identifier( $view ) );
				}
				if ( $views ) {
					$context->log( sprintf( 'Removed %d views of the previous database.', count( $views ) ) );
				}
			}
			$restore->set_progress( 'swap', 'done' );
		}

		if ( empty( $job->data['import'] ) && empty( $job->data['subsite'] ) ) {
			$this->create_objects( $job, $db, new SqlGuard( (string) ( $job->data['sql_prefix'] ?? $from ), $to ), $context );
			if ( 0 === (int) ( $job->options['reset_site'] ?? 0 ) ) {
				$this->keep_plugin_active( $job, $db, $to ); // A reset site's own list was set when it was built: {$to}options is the main site's.
			}
		} else {
			// Their table names follow another layout: a single site's, or the network's (several sites' tables).
			foreach ( (array) ( $job->data['deferred'] ?? array() ) as $relative ) {
				if ( is_file( $context->dir() . '/' . $relative ) && filesize( $context->dir() . '/' . $relative ) > 0 ) {
					$context->log( empty( $job->data['import'] ) ? 'Views and triggers of the network backup were left out: they name the tables of several sites.' : 'Views and triggers of the backup were left out: they name the tables of a single site.' );
					break;
				}
			}
		}
		if ( ! empty( $job->data['subsite'] ) ) {
			$this->keep_plugin_active( $job, $db, $to );
		} elseif ( ! empty( $job->data['import'] ) ) {
			// The sites' rewrite rules name their old address: WordPress builds them again on the next visit.
			foreach ( SubsiteImport::sites( $job->data['import'] ) as $into ) {
				$options = $to . (int) $into['blog_id'] . '_options';
				if ( $db->column( 'SHOW TABLES LIKE ' . $db->quote( addcslashes( $options, '\\%_' ) ) ) ) {
					$db->query( 'DELETE FROM ' . Connection::identifier( $options ) . " WHERE `option_name` = 'rewrite_rules'" );
				}
			}
		}
		if ( ! empty( $job->options['keep_active_network'] ) ) {
			$this->keep_plugin_network_active( $job, $db, $to );
		}
		$db->close();
		return true;
	}

	/**
	 * After a site of a network is reset: the kept users are its administrators, nobody else has a role on it. Idempotent.
	 *
	 * @param Connection $db      Connection.
	 * @param string     $prefix  Network prefix.
	 * @param int        $site    Site ID.
	 * @param int[]      $keep    Users kept as administrators.
	 * @param Context    $context Context.
	 * @return void
	 * @throws \Throwable After rolling back, when a query fails.
	 */
	private static function site_roles( Connection $db, string $prefix, int $site, array $keep, Context $context ): void {
		$meta = Connection::identifier( $prefix . 'usermeta' );
		$caps = $db->quote( $prefix . $site . '_capabilities' );
		$lvl  = $db->quote( $prefix . $site . '_user_level' );
		$keep = array_values( array_filter( $keep ) );
		$db->query( 'START TRANSACTION' );
		try {
			$gone = $db->column( "SELECT COUNT(DISTINCT `user_id`) FROM {$meta} WHERE `meta_key` = {$caps}" . ( $keep ? ' AND `user_id` NOT IN (' . implode( ', ', $keep ) . ')' : '' ) );
			$db->query( "DELETE FROM {$meta} WHERE `meta_key` IN ({$caps}, {$lvl})" );
			foreach ( $keep as $id ) {
				$db->query( "INSERT INTO {$meta} (`user_id`, `meta_key`, `meta_value`) VALUES ({$id}, {$caps}, 'a:1:{s:13:\"administrator\";b:1;}'), ({$id}, {$lvl}, '10')" );
			}
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}
		$context->log( sprintf( 'Site %d: %d user(s) are its administrators; %d other user(s) no longer have a role on it (their accounts stay on the network).', $site, count( $keep ), (int) ( $gone[0] ?? 0 ) ) );
	}

	/**
	 * Keeps this plugin network-active in a restored network, when it was network-active before.
	 *
	 * @param Job        $job    Job.
	 * @param Connection $db     Connection.
	 * @param string     $prefix Target prefix.
	 * @return void
	 */
	private function keep_plugin_network_active( Job $job, Connection $db, string $prefix ): void {
		$plugin = (string) ( $job->options['keep_active_plugin'] ?? '' );
		if ( '' === $plugin || ! $db->column( 'SHOW TABLES LIKE ' . $db->quote( addcslashes( $prefix . 'sitemeta', '\\%_' ) ) ) ) {
			return;
		}
		$table   = Connection::identifier( $prefix . 'sitemeta' );
		$network = (int) ( $job->options['target']['network']['id'] ?? 0 ); // Only this network's plugins, on installs with several networks.
		$known   = $network > 0 && $db->column( 'SHOW TABLES LIKE ' . $db->quote( addcslashes( $prefix . 'site', '\\%_' ) ) );
		if ( $network > 0 && ( ! $known || ! $db->column( 'SELECT `id` FROM ' . Connection::identifier( $prefix . 'site' ) . " WHERE `id` = {$network}" ) ) ) {
			$network = 0; // A restored network with another ID: its own row, as before.
		}
		$rows = $db->rows( "SELECT `meta_id`, `meta_value` FROM {$table} WHERE `meta_key` = 'active_sitewide_plugins'" . ( $network > 0 ? " AND `site_id` = {$network}" : '' ) );
		foreach ( $rows as $row ) {
			$plugins = unserialize( (string) $row['meta_value'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes=false: no objects are created.
			$plugins = is_array( $plugins ) ? $plugins : array();
			if ( isset( $plugins[ $plugin ] ) ) {
				continue;
			}
			$plugins[ $plugin ] = time();
			$db->query( "UPDATE {$table} SET `meta_value` = " . $db->quote( serialize( $plugins ) ) . ' WHERE `meta_id` = ' . (int) $row['meta_id'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores the option serialized.
		}
		if ( ! $rows ) {
			$site = $network > 0 ? array( $network ) : $db->column( 'SELECT MIN(`id`) FROM ' . Connection::identifier( $prefix . 'site' ) );
			$db->query( "INSERT INTO {$table} (`site_id`, `meta_key`, `meta_value`) VALUES (" . (int) ( $site[0] ?? 1 ) . ", 'active_sitewide_plugins', " . $db->quote( serialize( array( $plugin => time() ) ) ) . ')' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores the option serialized.
		}
	}

	/**
	 * Tables of this network's sites that are not in the backup (wp_7_posts when the backup has no site 7).
	 *
	 * They go aside with the replaced tables: left in place, a new site that
	 * gets the same ID later would find the old site's content. A table
	 * counts only when its site is in this network's blogs table (read before
	 * the switch) and has an options table, so another install in the same
	 * database with a prefix like wp_2_ is never touched.
	 *
	 * @param array<string,mixed> $network Plan from NetworkMove::plan().
	 * @param string[]            $live    Live tables with the target prefix.
	 * @param string              $prefix  Target prefix.
	 * @param int[]               $ids     Site IDs of this network before the restore.
	 * @return string[]
	 */
	public static function orphan_site_tables( array $network, array $live, string $prefix, array $ids ): array {
		$kept = array();
		foreach ( (array) ( $network['sites'] ?? array() ) as $blog ) {
			$kept[ (int) $blog['blog_id'] ] = true;
		}
		$all     = array_flip( $live );
		$ours    = array_flip( $ids );
		$orphans = array();
		foreach ( $live as $table ) {
			if ( 0 !== strpos( $table, $prefix ) || 1 !== preg_match( '/^([1-9][0-9]*)_/', substr( $table, strlen( $prefix ) ), $m ) ) {
				continue;
			}
			if ( ! isset( $kept[ (int) $m[1] ] ) && isset( $ours[ (int) $m[1] ] ) && isset( $all[ $prefix . $m[1] . '_options' ] ) ) {
				$orphans[] = $table;
			}
		}
		return $orphans;
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
