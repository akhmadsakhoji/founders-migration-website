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
use Founders\Migration\Job\Context;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores these options serialized.

/**
 * Puts back the plugins and themes a .wpress backup had active.
 *
 * All-in-One WP Migration blanks active_plugins, template and stylesheet in
 * the dump and records them in package.json (multisite.json for networks,
 * per site, plus the network-activated plugins), then activates them after
 * the import. This does the same in the imported fmwtmp_ tables, before the
 * swap, so the restored site comes up with its own theme and plugins.
 * Values that are not blank are left alone. Plugins that lock people out
 * after a move (login hiders, firewalls, forced HTTPS on an http:// site)
 * stay off, as All-in-One WP Migration does.
 */
final class WpressActivation {

	/**
	 * Plugins that stay off after a move.
	 */
	const DISRUPTIVE = array(
		'invisible-recaptcha',
		'wps-hide-login',
		'hide-my-wp',
		'hide-my-wordpress',
		'mycustomwidget',
		'lockdown-wp-admin',
		'rename-wp-login',
		'wp-simple-firewall',
		'join-my-multisite',
		'multisite-clone-duplicator',
		'wordpress-mu-domain-mapping',
		'wordpress-starter',
		'pro-sites',
		'wpide',
		'page-optimize',
		'update-services',
	);

	/**
	 * Plugins that force HTTPS: off when the site is restored on http://.
	 */
	const FORCE_HTTPS = array( 'really-simple-ssl', 'wordpress-https', 'wp-force-ssl', 'force-https-littlebizzy' );

	/**
	 * Writes the plan into the imported tables. Idempotent: only blank values are filled.
	 *
	 * @param RestoreDatabase     $restore Database helper.
	 * @param array<string,mixed> $plan    From WpressNetwork::activation() or single_activation().
	 * @param bool                $https   Whether the target site is on https://.
	 * @param Context             $context Context.
	 * @return void
	 */
	public static function apply( RestoreDatabase $restore, array $plan, bool $https, Context $context ): void {
		$db     = $restore->db();
		$tables = array_flip( $restore->imported_tables() );
		$off    = array();
		foreach ( (array) ( $plan['sites'] ?? array() ) as $blog_id => $site ) {
			$table = RestoreDatabase::TMP . ( 1 === (int) $blog_id ? '' : (int) $blog_id . '_' ) . 'options'; // Site 1 has the bare prefix, whichever site is the main one.
			if ( ! isset( $tables[ $table ] ) ) {
				continue;
			}
			$plugins = self::allowed( (array) $site['plugins'], $https, $off );
			self::fill( $db, $table, 'active_plugins', 'a:0:{}', serialize( $plugins ) );
			foreach ( array( 'template', 'stylesheet' ) as $name ) {
				if ( '' !== (string) $site[ $name ] ) {
					self::fill( $db, $table, $name, '', (string) $site[ $name ] );
				}
			}
		}

		$meta = RestoreDatabase::TMP . 'sitemeta';
		if ( is_array( $plan['sitewide'] ?? null ) && isset( $tables[ $meta ] ) ) {
			$exists = $db->column( 'SELECT COUNT(*) FROM ' . Connection::identifier( $meta ) . " WHERE `meta_key` = 'active_sitewide_plugins'" );
			if ( 0 === (int) ( $exists[0] ?? 0 ) ) {
				$sitewide = array();
				foreach ( self::allowed( (array) $plan['sitewide'], $https, $off ) as $plugin ) {
					$sitewide[ $plugin ] = time();
				}
				$network = isset( $tables[ RestoreDatabase::TMP . 'site' ] ) ? $db->column( 'SELECT MIN(`id`) FROM ' . Connection::identifier( RestoreDatabase::TMP . 'site' ) ) : array();
				$db->query( 'INSERT INTO ' . Connection::identifier( $meta ) . ' (`site_id`, `meta_key`, `meta_value`) VALUES (' . (int) ( $network[0] ?? 1 ) . ", 'active_sitewide_plugins', " . $db->quote( serialize( $sitewide ) ) . ')' );
			}
		}

		if ( $off ) {
			$context->log( sprintf( 'Left off after the move, as All-in-One WP Migration does: %s.', implode( ', ', array_unique( $off ) ) ) );
		}
	}

	/**
	 * Plugins without the disruptive ones.
	 *
	 * @param string[] $plugins Plugin files (folder/file.php).
	 * @param bool     $https   Whether the target is on https://.
	 * @param string[] $off     Collects what was left off.
	 * @return string[]
	 */
	public static function allowed( array $plugins, bool $https, array &$off ): array {
		$kept = array();
		foreach ( $plugins as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );
			if ( in_array( $folder, self::DISRUPTIVE, true ) || ( ! $https && in_array( $folder, self::FORCE_HTTPS, true ) ) ) {
				$off[] = (string) $plugin;
				continue;
			}
			if ( '' !== (string) $plugin && false === strpos( (string) $plugin, '..' ) ) {
				$kept[] = (string) $plugin;
			}
		}
		return array_values( array_unique( $kept ) );
	}

	/**
	 * Sets an option when it holds the blank value (or is missing).
	 *
	 * @param Connection $db    Connection.
	 * @param string     $table Options table.
	 * @param string     $name  Option name.
	 * @param string     $blank Value the export leaves.
	 * @param string     $value New value.
	 * @return void
	 */
	private static function fill( Connection $db, string $table, string $name, string $blank, string $value ): void {
		$current = $db->column( 'SELECT `option_value` FROM ' . Connection::identifier( $table ) . ' WHERE `option_name` = ' . $db->quote( $name ) );
		if ( ! $current ) {
			$db->query( 'INSERT INTO ' . Connection::identifier( $table ) . ' (`option_name`, `option_value`) VALUES (' . $db->quote( $name ) . ', ' . $db->quote( $value ) . ')' );
		} elseif ( $blank === (string) $current[0] || ( 'a:0:{}' === $blank && '' === (string) $current[0] ) ) {
			$db->query( 'UPDATE ' . Connection::identifier( $table ) . ' SET `option_value` = ' . $db->quote( $value ) . ' WHERE `option_name` = ' . $db->quote( $name ) );
		}
	}
}
