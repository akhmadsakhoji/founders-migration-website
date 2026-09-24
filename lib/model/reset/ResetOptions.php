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

use Founders\Migration\Model\Import\RestoreOptions;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Builds reset job options for the current WordPress site.
 *
 * A reset can empty the database (a fresh WordPress install that keeps the
 * site address, language, time zone and the chosen administrators), the
 * media library, the plugins (except this one) and the themes (except the
 * active one). Must-use plugins, drop-ins and wp-config.php are never touched.
 */
final class ResetOptions {

	const PARTS = array( 'database', 'media', 'plugins', 'themes' );

	/**
	 * Job options.
	 *
	 * @param string[] $parts      Parts to reset (see PARTS).
	 * @param int[]    $keep_users IDs of the users to keep as administrators (database reset).
	 * @param bool     $keep_old   Keep the replaced tables as fmwold_*.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On an unknown part, nothing to reset, multisite, or no user to keep.
	 */
	public static function build( array $parts, array $keep_users, bool $keep_old = false ): array {
		if ( is_multisite() ) {
			throw new \InvalidArgumentException( 'Reset is not available on multisite networks yet (planned for phase 3).' );
		}
		$unknown = array_diff( $parts, self::PARTS );
		if ( $unknown ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown reset part: %s.', implode( ', ', $unknown ) ) );
		}
		$parts = array_values( array_intersect( self::PARTS, $parts ) );
		if ( ! $parts ) {
			throw new \InvalidArgumentException( 'Choose what to reset: database, media, plugins and/or themes.' );
		}

		$keep_users = array_values( array_unique( array_filter( array_map( 'intval', $keep_users ) ) ) );
		if ( in_array( 'database', $parts, true ) ) {
			$keep_users = array_values(
				array_filter(
					$keep_users,
					static function ( int $id ): bool {
						return false !== get_userdata( $id );
					}
				)
			);
			if ( defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ) ) {
				throw new \InvalidArgumentException( 'This site shares its users table with other sites (CUSTOM_USER_TABLE); its database cannot be reset.' );
			}
			if ( ! $keep_users ) {
				throw new \InvalidArgumentException( 'At least one existing user must be kept, or nobody could log in after the reset.' );
			}
		}

		$restore = RestoreOptions::build( '', array() );
		unset( $restore['archive'], $restore['email_replace'], $restore['skip_space_check'] );

		return array_merge(
			$restore,
			array(
				'reset'              => $parts,
				'keep_users'         => $keep_users,
				'keep_themes'        => array_values( array_unique( array( get_template(), get_stylesheet() ) ) ),
				'keep_paths'         => array_values( array_unique( array( wp_normalize_path( FMWP_PATH ), fmwp_backups_path(), fmwp_storage_path() ) ) ), // Wherever they live.
				'keep_old_tables'    => $keep_old,
				'replace_all_tables' => true, // The fresh database replaces every table of this site, plugin tables included.
			)
		);
	}

	/**
	 * What must be typed to confirm a reset: the site's host name (with the port, if any).
	 *
	 * @return string
	 */
	public static function confirm_word(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		return strtolower( $host . ( $port ? ':' . $port : '' ) );
	}

	/**
	 * Whether a typed confirmation matches.
	 *
	 * @param string $typed Typed text.
	 * @return bool
	 */
	public static function confirmed( string $typed ): bool {
		return '' !== self::confirm_word() && hash_equals( self::confirm_word(), strtolower( trim( $typed, " \t\n\r\0\x0B" ) ) );
	}

	/**
	 * IDs of all administrators, the default users kept by `wp fmw reset`.
	 *
	 * @return int[]
	 */
	public static function administrators(): array {
		return array_map(
			'intval',
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			)
		);
	}
}
