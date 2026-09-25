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

use Founders\Migration\Pull\PullKeys;
use WP_CLI;

/**
 * Manages the pull keys that let another site copy this one.
 *
 * With a pull key, a site running Founders Migration Website can make a
 * backup of this site over HTTPS and restore it there (`wp fmw pull`). A key
 * can do nothing else: no login, no other API. Only its SHA-256 is stored.
 *
 * ## EXAMPLES
 *
 *     wp fmw pull-key create --expires=2h --ip=203.0.113.7
 *     wp fmw pull-key list
 *     wp fmw pull-key revoke 1a2b3c4d
 */
final class PullKeyCommand {

	/**
	 * Creates a pull key and prints it once.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Label shown in lists, for example the target site.
	 *
	 * [--expires=<duration>]
	 * : How long the key works: 30m, 24h, 7d (at most 30d).
	 * ---
	 * default: 24h
	 * ---
	 *
	 * [--ip=<addresses>]
	 * : Comma-separated IP addresses or CIDR ranges the key may be used from (the target server's outgoing address).
	 *
	 * [--allow-existing]
	 * : Also allow downloading the .fmw backups already on this site (by default the key can only download backups it makes).
	 *
	 * [--porcelain]
	 * : Print only the key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw pull-key create --name="new server" --expires=2h
	 *     wp fmw pull-key create --ip=203.0.113.7,2001:db8::/32 --allow-existing
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		if ( PullKeys::disabled() ) {
			WP_CLI::error( 'Pulls are switched off on this site (FMWP_DISABLE_PULL).' );
		}
		try {
			$made = PullKeys::create(
				array(
					'name'           => (string) ( $assoc_args['name'] ?? '' ),
					'ttl'            => PullKeys::parse_ttl( (string) ( $assoc_args['expires'] ?? '24h' ) ),
					'ips'            => explode( ',', (string) ( $assoc_args['ip'] ?? '' ) ),
					'allow_existing' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'allow-existing', false ),
					'user'           => get_current_user_id(),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $made['key'] );
			return;
		}
		$record = $made['record'];
		WP_CLI::success( sprintf( 'Pull key %s created, valid until %s.', $record['id'], wp_date( 'Y-m-d H:i T', (int) $record['expires_at'] ) ) );
		WP_CLI::line( '' );
		WP_CLI::line( '  ' . $made['key'] );
		WP_CLI::line( '' );
		WP_CLI::line( 'It is shown only now. On the site that should receive this one, run:' );
		WP_CLI::line( sprintf( '  wp fmw pull %s --key=<the key above>', home_url() ) );
		WP_CLI::line( 'Anyone with the key can copy this site until it expires: send it over a private channel, and revoke it when the move is done (`wp fmw pull-key revoke ' . $record['id'] . '`).' );
	}

	/**
	 * Lists pull keys.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		$rows = array();
		foreach ( PullKeys::all() as $key ) {
			$rows[] = array(
				'id'        => $key['id'],
				'name'      => $key['name'],
				'expires'   => (int) $key['expires_at'] <= time() ? 'expired' : wp_date( 'Y-m-d H:i', (int) $key['expires_at'] ),
				'ips'       => empty( $key['ips'] ) ? 'any' : implode( ', ', (array) $key['ips'] ),
				'existing'  => empty( $key['allow_existing'] ) ? 'no' : 'yes',
				'last_used' => empty( $key['last_used_at'] ) ? 'never' : wp_date( 'Y-m-d H:i', (int) $key['last_used_at'] ) . ' from ' . $key['last_ip'],
				'sent'      => ProgressBar::bytes( (int) ( $key['bytes_sent'] ?? 0 ) ),
			);
		}
		if ( empty( $rows ) && 'table' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( 'No pull keys. Create one with `wp fmw pull-key create`.' );
			return;
		}
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'name', 'expires', 'ips', 'existing', 'last_used', 'sent' ) );
	}

	/**
	 * Revokes pull keys; requests with them fail from now on.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Key ids (see `wp fmw pull-key list`).
	 *
	 * [--all]
	 * : Revoke every key.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$args = array_column( PullKeys::all(), 'id' );
		}
		if ( empty( $args ) ) {
			WP_CLI::error( 'Give key ids, or --all.' );
		}
		$missing = 0;
		foreach ( $args as $id ) {
			if ( PullKeys::revoke( (string) $id ) ) {
				WP_CLI::log( sprintf( 'Revoked %s.', $id ) );
			} else {
				WP_CLI::warning( sprintf( 'No pull key %s.', $id ) );
				++$missing;
			}
		}
		if ( $missing > 0 ) {
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( sprintf( '%d pull key(s) revoked.', count( $args ) ) );
	}
}
