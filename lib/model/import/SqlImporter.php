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

use Founders\Migration\Database\SqlGuard;
use Founders\Migration\Database\SqlReader;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\JobException;

defined( 'ABSPATH' ) || exit;

/**
 * Imports an SQL file statement by statement through SqlGuard, exactly once across resumes.
 *
 * The reader's byte offset is stored in the progress table inside the same
 * transaction as the rows it covers. DDL commits implicitly, so the position
 * is recorded before and after each DROP / CREATE. Used for .fmw table dumps
 * and for the database.sql of .wpress archives.
 *
 * With a $defer file, view and trigger statements are appended there instead
 * of being run (they are recreated after the swap). The file's length is part
 * of the progress record, so a resumed import truncates it back and never
 * appends a statement twice.
 */
final class SqlImporter {

	/**
	 * Database helper.
	 *
	 * @var RestoreDatabase
	 */
	private $restore;

	/**
	 * Constructor.
	 *
	 * @param RestoreDatabase $restore Database helper.
	 */
	public function __construct( RestoreDatabase $restore ) {
		$this->restore = $restore;
	}

	/**
	 * Imports (the rest of) a file.
	 *
	 * @param string      $key     Progress key, unique per file.
	 * @param string      $path    SQL file (plain or gzip).
	 * @param SqlGuard    $guard   Guard mapping the source prefix to fmwtmp_.
	 * @param Context     $context Context.
	 * @param string|null $defer   File collecting view and trigger statements, or null to refuse them.
	 * @return bool Whether the file is fully imported.
	 * @throws JobException When the deferred-statements file cannot be written. Other errors are rethrown after rolling back.
	 */
	public function import( string $key, string $path, SqlGuard $guard, Context $context, ?string $defer = null ): bool {
		$restore = $this->restore;
		$db      = $restore->db();
		$state   = json_decode( (string) $restore->progress( $key ), true );
		$state   = is_array( $state ) ? $state : array(
			'offset'    => 0,
			'delimiter' => ';',
			'done'      => false,
		);
		if ( ! empty( $state['done'] ) ) {
			return true;
		}

		$deferred = null;
		if ( null !== $defer ) {
			$deferred = fopen( $defer, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Job file.
			if ( false === $deferred || ! ftruncate( $deferred, (int) ( $state['deferred'] ?? 0 ) ) || 0 !== fseek( $deferred, 0, SEEK_END ) ) {
				throw new JobException( sprintf( 'Cannot write %s.', $defer ) );
			}
		}

		$reader  = new SqlReader( $path, (int) $state['offset'], (string) $state['delimiter'] );
		$skipped = array();
		$done    = false;

		$db->query( 'START TRANSACTION' );
		try {
			do {
				$statement = $reader->next();
				if ( null === $statement ) {
					$done = true;
					break;
				}
				if ( null !== $deferred && SqlGuard::is_object_statement( $statement['sql'] ) ) {
					$guard->object_statement( $statement['sql'] ); // Refuse unsafe ones now, before anything changes.
					if ( false === fwrite( $deferred, $statement['sql'] . ";\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Job file.
						throw new JobException( 'Write failed. The disk may be full.' );
					}
					$state = self::position( $statement, $deferred );
					continue;
				}
				$checked = $guard->table_statement( $statement['sql'] );

				if ( 'drop' === $checked['kind'] || 'create' === $checked['kind'] ) {
					// DDL commits implicitly: record the position before it, run it, record after it.
					$restore->set_progress( $key, self::encode( $state ) );
					$db->query( 'COMMIT' );
					$db->query( $checked['sql'] );
					$state = self::position( $statement, $deferred );
					$restore->set_progress( $key, self::encode( $state ) );
					$db->query( 'START TRANSACTION' );
					continue;
				}

				if ( 'skip' === $checked['kind'] ) {
					if ( '' !== $checked['table'] && ! isset( $skipped[ $checked['table'] ] ) ) {
						$skipped[ $checked['table'] ] = true;
						$context->log( sprintf( 'Skipped table %s: it does not use the site prefix.', $checked['table'] ) );
					}
				} else {
					$db->query( $checked['sql'] );
				}
				$state = self::position( $statement, $deferred );
			} while ( $context->should_continue() );

			$state['done'] = $done;
			$restore->set_progress( $key, self::encode( $state ) );
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			try {
				$db->query( 'ROLLBACK' );
			} catch ( \Throwable $ignored ) {
				unset( $ignored ); // The original error matters more.
			}
			throw $e;
		} finally {
			$reader->close();
			if ( null !== $deferred ) {
				fclose( $deferred ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Job file.
			}
		}

		return $done;
	}

	/**
	 * Progress state after a statement.
	 *
	 * @param array{sql:string,offset:int,delimiter:string} $statement Statement from SqlReader.
	 * @param resource|null                                 $deferred  Deferred-statements file.
	 * @return array<string,mixed>
	 */
	private static function position( array $statement, $deferred ): array {
		$state = array(
			'offset'    => $statement['offset'],
			'delimiter' => $statement['delimiter'],
			'done'      => false,
		);
		if ( null !== $deferred ) {
			fflush( $deferred ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Job file.
			$state['deferred'] = (int) ftell( $deferred );
		}
		return $state;
	}

	/**
	 * JSON for a small progress array.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	private static function encode( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal progress record; runs outside WordPress in tests.
	}
}
