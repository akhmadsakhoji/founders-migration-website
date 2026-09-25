<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Reset;

use Founders\Migration\Database\Connection;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.

/**
 * Deletes plugins (except this one), themes (except the active ones) and
 * media files, resumably.
 *
 * Without a database reset the database is kept consistent with the files:
 * plugins are deactivated before their files go, and media library entries
 * (attachments) are removed with the media files.
 *
 * Reads job options: reset, target, protect_paths, keep_paths, keep_active_plugin, keep_themes.
 */
final class ResetFilesStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Files';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When a folder looks unsafe to empty or a file cannot be deleted.
	 */
	public function run( Job $job, Context $context ): bool {
		$parts  = (array) ( $job->options['reset'] ?? array() );
		$db     = in_array( 'database', $parts, true );
		$queue  = array_values( array_intersect( array( 'plugins', 'themes', 'media' ), $parts ) );
		$cursor = $job->cursor + array(
			'part'    => 0,
			'deleted' => 0,
			'db'      => false,
		);

		while ( isset( $queue[ $cursor['part'] ] ) ) {
			$part = $queue[ $cursor['part'] ];
			if ( ! $db && ! $cursor['db'] ) {
				$this->update_database( $part, $job, $context );
				$cursor['db'] = true;
				$job->cursor  = $cursor;
			}

			$deleted = (int) $cursor['deleted'];
			$eraser  = new TreeEraser( self::root( $part, $job ), self::keep( $part, $job ) );
			try {
				$done = $eraser->erase( array( $context, 'should_continue' ), $deleted );
			} catch ( \RuntimeException $e ) {
				throw new JobException( $e->getMessage() );
			}
			$job->bytes_done = $deleted;

			if ( ! $done ) {
				$cursor['deleted'] = $deleted;
				$job->cursor       = $cursor;
				return false;
			}
			$context->log( sprintf( 'Reset %s: deleted %d files and folders.', $part, $deleted ) );
			$cursor      = array(
				'part'    => $cursor['part'] + 1,
				'deleted' => 0,
				'db'      => false,
			);
			$job->cursor = $cursor;
			if ( ! $context->should_continue() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Folder a part empties.
	 *
	 * @param string $part Part.
	 * @param Job    $job  Job.
	 * @return string
	 * @throws JobException When the folder is missing or contains WordPress itself.
	 */
	public static function root( string $part, Job $job ): string {
		$target = (array) ( $job->options['target'] ?? array() );
		$keys   = array(
			'plugins' => 'plugins_dir',
			'themes'  => 'themes_dir',
			'media'   => 'uploads_dir',
		);
		$root   = rtrim( str_replace( '\\', '/', (string) ( $target[ $keys[ $part ] ] ?? '' ) ), '/' );
		$site   = (int) ( $job->options['reset_site'] ?? 0 );
		if ( $site > 0 && ( 'media' !== $part || ! ResetOptions::own_uploads( $root, $site ) ) ) {
			throw new JobException( sprintf( 'Only the media folder of site %d (uploads/sites/%d) is reset on a network; %s is not it.', $site, $site, '' === $root ? '?' : $root ) );
		}
		foreach ( array( 'abspath', 'content_dir' ) as $key ) {
			$other = rtrim( str_replace( '\\', '/', (string) ( $target[ $key ] ?? '' ) ), '/' );
			if ( '' === $root || '' === $other || $root === $other || 0 === strpos( $other . '/', $root . '/' ) ) {
				throw new JobException( sprintf( 'The %s folder (%s) contains WordPress itself; it will not be emptied.', $part, '' === $root ? '?' : $root ) );
			}
		}
		return $root;
	}

	/**
	 * Absolute paths a part keeps.
	 *
	 * @param string $part Part.
	 * @param Job    $job  Job.
	 * @return string[]
	 */
	public static function keep( string $part, Job $job ): array {
		$root    = self::root( $part, $job );
		$content = rtrim( (string) ( $job->options['target']['content_dir'] ?? '' ), '/' );
		$keep    = array( $root . '/index.php' );
		foreach ( (array) ( $job->options['keep_paths'] ?? array() ) as $path ) {
			if ( '' !== (string) $path ) {
				$keep[] = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
			}
		}
		foreach ( (array) ( $job->options['protect_paths'] ?? array() ) as $path ) {
			$path = trim( (string) $path, '/' );
			if ( '' !== $path ) {
				$keep[] = $content . '/' . $path;
			}
		}
		if ( 'plugins' === $part ) {
			$plugin = (string) ( $job->options['keep_active_plugin'] ?? '' );
			if ( '' !== $plugin ) {
				$keep[] = $root . '/' . ( '.' === dirname( $plugin ) ? $plugin : dirname( $plugin ) );
			}
		}
		if ( 'themes' === $part ) {
			foreach ( (array) ( $job->options['keep_themes'] ?? array() ) as $theme ) {
				if ( '' !== (string) $theme && false === strpos( (string) $theme, '..' ) ) {
					$keep[] = $root . '/' . $theme;
				}
			}
		}
		return $keep;
	}

	/**
	 * Keeps the (not reset) database in line with the files about to be deleted.
	 *
	 * @param string  $part    Part.
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @return void
	 */
	private function update_database( string $part, Job $job, Context $context ): void {
		if ( 'themes' === $part ) {
			return; // The active theme stays; nothing refers to the others.
		}
		$db     = Connection::open();
		$site   = (int) ( $job->options['reset_site'] ?? 0 );
		$prefix = (string) ( $job->options['target']['table_prefix'] ?? 'wp_' ) . ( $site > 0 ? $site . '_' : '' ); // A site of a network: its own tables.
		try {
			if ( 'plugins' === $part ) {
				$plugin = (string) ( $job->options['keep_active_plugin'] ?? '' );
				$value  = $db->quote( serialize( '' === $plugin ? array() : array( $plugin ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores the option serialized.
				$db->query( 'UPDATE ' . Connection::identifier( $prefix . 'options' ) . " SET `option_value` = {$value} WHERE `option_name` = 'active_plugins'" );
				$context->log( 'Deactivated all plugins except this one.' );
			} else {
				$removed = self::delete_attachments( $db, $prefix );
				$context->log( sprintf( 'Removed %d media library entries.', $removed ) );
			}
		} finally {
			$db->close();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Deletes all attachments with their meta, term links and featured-image references.
	 *
	 * @param Connection $db     Connection.
	 * @param string     $prefix Table prefix.
	 * @return int Attachments deleted.
	 */
	public static function delete_attachments( Connection $db, string $prefix ): int {
		$posts = Connection::identifier( $prefix . 'posts' );
		$meta  = Connection::identifier( $prefix . 'postmeta' );
		$terms = Connection::identifier( $prefix . 'term_relationships' );
		$count = 0;
		do {
			$ids = array_map( 'intval', $db->column( "SELECT `ID` FROM {$posts} WHERE `post_type` = 'attachment' ORDER BY `ID` LIMIT 1000" ) );
			if ( ! $ids ) {
				break;
			}
			$in      = implode( ', ', $ids );
			$strings = "'" . implode( "', '", $ids ) . "'"; // meta_value is text: compare as text (no strict-mode cast errors).
			$db->query( "DELETE FROM {$terms} WHERE `object_id` IN ({$in})" );
			$db->query( "DELETE FROM {$meta} WHERE `meta_key` = '_thumbnail_id' AND `meta_value` IN ({$strings})" );
			$db->query( "DELETE FROM {$meta} WHERE `post_id` IN ({$in})" );
			$db->query( "DELETE FROM {$posts} WHERE `ID` IN ({$in})" );
			$count += count( $ids );
		} while ( true );
		return $count;
	}
}
