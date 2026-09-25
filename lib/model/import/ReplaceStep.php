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
use Founders\Migration\Database\Replacer;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal progress records; runs outside WordPress in tests.

/**
 * Rewrites the old site's URLs and paths in the imported fmwtmp_ tables, then fixes prefix-based keys.
 *
 * Runs on the temporary tables, so the live site is untouched until the
 * swap. Each batch of row updates commits together with its progress
 * record, so no row is ever replaced twice, even when the new URL contains
 * the old one. posts.guid is left alone, as WordPress recommends.
 *
 * Reads job options: target { home_url, site_url, abspath, table_prefix },
 * email_replace. Reads job data: manifest.site, network (see NetworkMove).
 */
final class ReplaceStep implements Step {

	const BATCH      = 500;
	const TEXT_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json' );
	const NUMERIC    = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric' );

	/**
	 * Database helper, one per process.
	 *
	 * @var RestoreDatabase|null
	 */
	private $restore = null;

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Replace';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 */
	public function run( Job $job, Context $context ): bool {
		if ( empty( $job->data['has_db'] ) ) {
			return true;
		}
		if ( null === $this->restore ) {
			$this->restore = new RestoreDatabase();
		}

		$site     = (array) ( $job->data['manifest']['site'] ?? array() );
		$target   = (array) ( $job->options['target'] ?? array() );
		$replacer = new Replacer( self::pairs( $job ) );

		$tables = $this->restore->imported_tables();
		$count  = count( $tables );
		$cursor = $job->cursor + array( 't' => 0 );

		if ( $replacer->is_empty() ) {
			$cursor['t'] = $count;
		}

		while ( $cursor['t'] < $count && $context->should_continue() ) {
			if ( $this->replace_batch( $tables[ $cursor['t'] ], $replacer ) ) {
				++$cursor['t'];
			}
			$context->report_progress(); // Byte counters keep the previous step's totals: tables are not bytes.
		}

		if ( $cursor['t'] < $count ) {
			$job->cursor = $cursor;
			return false;
		}

		$this->fix_prefix_keys( (string) ( $site['table_prefix'] ?? 'wp_' ), (string) ( $target['table_prefix'] ?? 'wp_' ), $tables, $context );
		if ( is_array( $job->data['network'] ?? null ) ) {
			$this->fix_network( $job->data['network'], $tables, $context );
		}
		$context->log(
			$replacer->is_empty()
				? 'Same URL and path: nothing to replace.'
				: sprintf( 'Replaced %s with %s in %d tables.', (string) ( $site['home_url'] ?? '' ), (string) ( $target['home_url'] ?? '' ), count( $tables ) )
		);
		return true;
	}

	/**
	 * Moves the network's sites in the blogs and site tables, whose domains and paths are no URLs. Runs once.
	 *
	 * @param array<string,mixed> $network Plan from NetworkMove::plan().
	 * @param string[]            $tables  Imported tables.
	 * @param Context             $context Context.
	 * @return void
	 * @throws JobException When the backup's site table does not hold the network.
	 * @throws \Throwable After rolling back, when an update fails.
	 */
	private function fix_network( array $network, array $tables, Context $context ): void {
		if ( empty( $network['moved'] ) || 'done' === $this->restore->progress( 'network' ) ) {
			return;
		}
		$blogs = RestoreDatabase::TMP . 'blogs';
		$site  = RestoreDatabase::TMP . 'site';
		$db    = $this->restore->db();
		$moved = 0;
		$db->query( 'START TRANSACTION' );
		try {
			if ( in_array( $blogs, $tables, true ) ) {
				foreach ( (array) $network['sites'] as $blog ) {
					if ( $blog['from'] !== $blog['to'] ) {
						$db->query( 'UPDATE ' . Connection::identifier( $blogs ) . ' SET `domain` = ' . $db->quote( (string) $blog['to']['domain'] ) . ', `path` = ' . $db->quote( (string) $blog['to']['path'] ) . ' WHERE `blog_id` = ' . (int) $blog['blog_id'] );
						++$moved;
					}
				}
			}
			if ( in_array( $site, $tables, true ) ) {
				$found = $db->column( 'SELECT COUNT(*) FROM ' . Connection::identifier( $site ) . ' WHERE `domain` = ' . $db->quote( (string) $network['network']['from']['domain'] ) . ' AND `path` = ' . $db->quote( (string) $network['network']['from']['path'] ) );
				if ( 1 !== (int) ( $found[0] ?? 0 ) ) {
					throw new JobException( sprintf( 'The backup\'s site table has no single network at %s%s; the network cannot be moved.', (string) $network['network']['from']['domain'], (string) $network['network']['from']['path'] ) );
				}
				$db->query(
					'UPDATE ' . Connection::identifier( $site )
					. ' SET `domain` = ' . $db->quote( (string) $network['network']['to']['domain'] ) . ', `path` = ' . $db->quote( (string) $network['network']['to']['path'] )
					. ' WHERE `domain` = ' . $db->quote( (string) $network['network']['from']['domain'] ) . ' AND `path` = ' . $db->quote( (string) $network['network']['from']['path'] )
				);
			}
			$this->restore->set_progress( 'network', 'done' );
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}
		$context->log( sprintf( 'Moved the network to %s%s (%d sites).', (string) $network['network']['to']['domain'], (string) $network['network']['to']['path'], $moved ) );
	}

	/**
	 * Search / replace pairs: the plan prepared by the check step (.wpress), or the manifest's site against the target.
	 *
	 * @param Job $job Job.
	 * @return array<string,string>
	 */
	private static function pairs( Job $job ): array {
		$plan = $job->data['replace'] ?? null;
		if ( is_array( $plan ) ) {
			return Replacer::site_pairs( (array) $plan['urls'], (array) $plan['paths'], ! empty( $plan['email'] ) )
				+ Replacer::value_pairs( (array) ( $plan['raw'] ?? array() ) );
		}
		$site   = (array) ( $job->data['manifest']['site'] ?? array() );
		$target = (array) ( $job->options['target'] ?? array() );
		$urls   = array(
			(string) ( $site['home_url'] ?? '' ) => (string) ( $target['home_url'] ?? '' ),
			(string) ( $site['site_url'] ?? $site['home_url'] ?? '' ) => (string) ( $target['site_url'] ?? $target['home_url'] ?? '' ),
		);
		$keep   = array();
		if ( is_array( $job->data['network'] ?? null ) ) {
			$urls = self::network_urls( $job->data['network'], $urls );
			foreach ( (array) $job->data['network']['sites'] as $blog ) {
				if ( $blog['from'] === $blog['to'] ) {
					$keep[] = '//' . $blog['from']['domain'] . $blog['from']['path'];
				}
			}
		}
		$pairs = Replacer::site_pairs(
			$urls,
			array( (string) ( $site['abspath'] ?? '' ) => (string) ( $target['abspath'] ?? '' ) ),
			! empty( $job->options['email_replace'] )
		);
		return $pairs ? $pairs + Replacer::keep_pairs( $keep, ! empty( $job->options['email_replace'] ) ) : $pairs;
	}

	/**
	 * URLs of a network: every site that moves, besides the main site's home and site URL.
	 *
	 * @param array<string,mixed>  $network Plan from NetworkMove::plan().
	 * @param array<string,string> $main    Main site's old => new URLs.
	 * @return array<string,string>
	 */
	private static function network_urls( array $network, array $main ): array {
		$urls = array();
		foreach ( (array) ( $network['urls'] ?? array() ) as $old => $new ) {
			$urls[ (string) $old ] = (string) $new;
		}
		foreach ( $main as $old => $new ) {
			$urls += array( (string) $old => (string) $new );
		}
		return $urls;
	}

	/**
	 * Replaces in the next batch of rows of a table.
	 *
	 * @param string   $table    Table.
	 * @param Replacer $replacer Replacer.
	 * @return bool Whether the table is finished.
	 * @throws \Throwable After rolling back, when an update fails.
	 */
	private function replace_batch( string $table, Replacer $replacer ): bool {
		$db    = $this->restore->db();
		$key   = 'replace:' . $table;
		$state = json_decode( (string) $this->restore->progress( $key ), true );
		if ( is_array( $state ) && ! empty( $state['done'] ) ) {
			return true;
		}
		$last = is_array( $state ) && isset( $state['last'] ) ? array_map( 'base64_decode', (array) $state['last'] ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key values may be binary.

		$types = array();
		foreach ( $db->rows( 'SELECT COLUMN_NAME AS name, LOWER(DATA_TYPE) AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $table ) . ' ORDER BY ORDINAL_POSITION' ) as $column ) {
			$types[ (string) $column['name'] ] = (string) $column['type'];
		}
		$text = array_keys(
			array_filter(
				$types,
				static function ( string $type ): bool {
					return in_array( $type, self::TEXT_TYPES, true );
				}
			)
		);
		if ( preg_match( '/^' . RestoreDatabase::TMP . '(\d+_)?posts$/', $table ) ) {
			$text = array_values( array_diff( $text, array( 'guid' ) ) );
		}
		$primary = $this->restore->primary_key( $table );

		if ( ! $text || ! $primary ) {
			$this->restore->set_progress( $key, (string) json_encode( array( 'done' => true ) ) );
			return true;
		}

		$like = array();
		foreach ( $text as $column ) {
			foreach ( $replacer->needles() as $needle ) {
				$like[] = Connection::identifier( $column ) . " LIKE '%" . $db->escape( addcslashes( $needle, '\\%_' ) ) . "%'";
			}
		}
		$where = array( '(' . implode( ' OR ', $like ) . ')' );
		if ( null !== $last ) {
			$where[] = $this->after( $db, $primary, $last, $types );
		}

		$columns = array_values( array_unique( array_merge( $primary, $text ) ) );
		$rows    = $db->rows(
			'SELECT ' . implode( ', ', array_map( array( Connection::class, 'identifier' ), $columns ) )
			. ' FROM ' . Connection::identifier( $table )
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY ' . implode( ', ', array_map( array( Connection::class, 'identifier' ), $primary ) )
			. ' LIMIT ' . self::BATCH
		);

		$db->query( 'START TRANSACTION' );
		try {
			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $text as $column ) {
					if ( null === $row[ $column ] ) {
						continue;
					}
					$new = $replacer->replace( (string) $row[ $column ] );
					if ( $new !== $row[ $column ] ) {
						$set[] = Connection::identifier( $column ) . ' = ' . $db->quote( $new );
					}
				}
				if ( $set ) {
					$match = array();
					foreach ( $primary as $column ) {
						$match[] = Connection::identifier( $column ) . ' = ' . $this->literal( $db, (string) $row[ $column ], $types[ $column ] );
					}
					$db->query( 'UPDATE ' . Connection::identifier( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $match ) );
				}
			}

			$done  = count( $rows ) < self::BATCH;
			$state = array( 'done' => $done );
			if ( $rows ) {
				$tail          = end( $rows );
				$state['last'] = array_map(
					static function ( string $column ) use ( $tail ): string {
						return base64_encode( (string) $tail[ $column ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key values may be binary.
					},
					$primary
				);
			} elseif ( null !== $last ) {
				$state['last'] = array_map( 'base64_encode', $last ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key values may be binary.
			}
			$this->restore->set_progress( $key, (string) json_encode( $state ) );
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}

		return $done;
	}

	/**
	 * Renames prefix-based keys (user roles option, user meta) when the table prefix changes. Runs once.
	 *
	 * @param string   $from    Source prefix.
	 * @param string   $to      Target prefix.
	 * @param string[] $tables  Imported tables.
	 * @param Context  $context Context.
	 * @return void
	 * @throws \Throwable After rolling back, when an update fails.
	 */
	private function fix_prefix_keys( string $from, string $to, array $tables, Context $context ): void {
		if ( $from === $to || 'done' === $this->restore->progress( 'prefix' ) ) {
			return;
		}
		$db = $this->restore->db();
		$db->query( 'START TRANSACTION' );
		try {
			foreach ( $tables as $table ) {
				if ( preg_match( '/^' . RestoreDatabase::TMP . '(\d+_)?options$/', $table, $m ) ) {
					$site = $m[1] ?? '';
					$db->query( 'UPDATE ' . Connection::identifier( $table ) . ' SET `option_name` = ' . $db->quote( $to . $site . 'user_roles' ) . ' WHERE `option_name` = ' . $db->quote( $from . $site . 'user_roles' ) );
				}
			}
			if ( in_array( RestoreDatabase::TMP . 'usermeta', $tables, true ) ) {
				$like = $db->escape( addcslashes( $from, '\\%_' ) ) . '%';
				$db->query( 'UPDATE ' . Connection::identifier( RestoreDatabase::TMP . 'usermeta' ) . ' SET `meta_key` = CONCAT(' . $db->quote( $to ) . ', SUBSTRING(`meta_key`, ' . ( strlen( $from ) + 1 ) . ")) WHERE `meta_key` LIKE '{$like}'" );
			}
			$this->restore->set_progress( 'prefix', 'done' );
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}
		$context->log( sprintf( 'Renamed prefix-based keys from "%s" to "%s".', $from, $to ) );
	}

	/**
	 * Keyset condition for rows after $values.
	 *
	 * @param Connection           $db     Connection.
	 * @param string[]             $key    Key columns.
	 * @param string[]             $values Last key values.
	 * @param array<string,string> $types  Column types.
	 * @return string
	 */
	private function after( Connection $db, array $key, array $values, array $types ): string {
		$or = array();
		foreach ( $key as $i => $column ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[] = Connection::identifier( $key[ $j ] ) . ' = ' . $this->literal( $db, $values[ $j ], $types[ $key[ $j ] ] );
			}
			$and[] = Connection::identifier( $column ) . ' > ' . $this->literal( $db, $values[ $i ], $types[ $column ] );
			$or[]  = '(' . implode( ' AND ', $and ) . ')';
		}
		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * Literal for a key value.
	 *
	 * @param Connection $db    Connection.
	 * @param string     $value Value.
	 * @param string     $type  Column type.
	 * @return string
	 */
	private function literal( Connection $db, string $value, string $type ): string {
		if ( in_array( $type, self::NUMERIC, true ) && is_numeric( $value ) ) {
			return $value;
		}
		if ( in_array( $type, array( 'binary', 'varbinary' ), true ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return $db->quote( $value );
	}
}
