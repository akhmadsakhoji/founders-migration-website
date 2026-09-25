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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\PathGuard;
use Founders\Migration\Archive\WpressDecoder;
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Model\Export\BackupOptions;
use Founders\Migration\Model\Import\NetworkMove;
use Founders\Migration\Model\Import\RestoreDatabase;
use Founders\Migration\Model\Import\RestoreOptions;
use Founders\Migration\Model\Import\SubsiteExtract;
use Founders\Migration\Model\Import\SubsiteImport;
use Founders\Migration\Model\Import\WpressNetwork;
use Founders\Migration\Model\Reset\ResetOptions;
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
	 * Exit code of a job that stopped and can be resumed.
	 */
	const EXIT_RESUMABLE = 3;

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
		$backup = Backups::get( $args[0] );
		if ( null === $backup ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found in %s.', $args[0], fmwp_backups_path() ) );
			return;
		}
		if ( 'fmw' !== $backup['source'] ) {
			WP_CLI::error( sprintf( '%s belongs to All-in-One WP Migration (wp-content/ai1wm-backups); FMW does not delete it.', $args[0] ) );
		}

		WP_CLI::confirm( sprintf( 'Delete %s?', $args[0] ), $assoc_args );
		if ( ! Backups::delete( $args[0] ) ) {
			WP_CLI::error( sprintf( 'Could not delete %s.', $backup['path'] ) );
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
	 * Creates a .fmw backup in the backups folder.
	 *
	 * Resumable: if it stops (Ctrl+C, lost SSH session, server restart), run
	 * `wp fmw resume <job_id>`. Exit codes: 0 done, 1 failed, 3 stopped.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-spam-comments]
	 * : Leave out spam comments and their meta.
	 *
	 * [--exclude-post-revisions]
	 * : Leave out post revisions and their meta.
	 *
	 * [--exclude-transients]
	 * : Leave out transients from the options (and network meta) tables.
	 *
	 * [--exclude-media]
	 * : Leave out uploads.
	 *
	 * [--exclude-themes]
	 * : Leave out all themes.
	 *
	 * [--exclude-inactive-themes]
	 * : Leave out themes that are not active.
	 *
	 * [--exclude-muplugins]
	 * : Leave out must-use plugins.
	 *
	 * [--exclude-plugins]
	 * : Leave out all plugins.
	 *
	 * [--exclude-inactive-plugins]
	 * : Leave out plugins that are not active.
	 *
	 * [--exclude-cache]
	 * : Leave out cache folders (cache, et-cache, litespeed).
	 *
	 * [--exclude-database]
	 * : Leave out the database.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated table names to leave out.
	 *
	 * [--exclude-paths=<patterns>]
	 * : Comma-separated patterns relative to wp-content, for example "uploads/old/*".
	 *
	 * [--part-size=<size>]
	 * : Target size of each part before compression, from 128M to 4G.
	 * ---
	 * default: 1G
	 * ---
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * [--porcelain]
	 * : Print only the backup file name.
	 *
	 * [--password[=<password>]]
	 * : Encrypt the backup (AES-256, at least 8 characters). Without a value the password is asked for, twice, without echo. Keep it safe: without it the backup cannot be restored.
	 *
	 * [--storage=<id>]
	 * : Upload the backup to this cloud storage afterwards (see `wp fmw storage list`).
	 *
	 * [--delete-local]
	 * : With --storage: delete the copy on this server once the upload is complete and checked.
	 *
	 * [--sites=<ids>]
	 * : Back up selected subsites only. Planned for phase 3.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw backup
	 *     wp fmw backup --exclude-cache --exclude-post-revisions
	 *     wp fmw backup --exclude-media --porcelain
	 *     wp fmw backup --password
	 *     wp fmw backup --storage=1a2b3c4d --delete-local
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function backup( $args, $assoc_args ) {
		if ( isset( $assoc_args['password'] ) ) {
			$assoc_args['password'] = $this->password( $assoc_args, true );
		}
		if ( isset( $assoc_args['sites'] ) ) {
			$this->planned( 'backup --sites', 3 );
		}

		try {
			$options = BackupOptions::from_flags( $assoc_args );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		Paths::ensure_all();
		$porcelain = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );
		$job       = Jobs::store()->create( 'backup', $options );
		$this->run_job( $job, $porcelain || ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ), $porcelain );
	}

	/**
	 * Restores a .fmw or .wpress backup onto this site, replacing its files and database.
	 *
	 * The database is imported into temporary tables and switched in with one
	 * atomic rename at the end, so a restore that fails or is cancelled before
	 * that point leaves the site's database untouched. URLs and paths are
	 * replaced for the new location, serialized data included. This plugin's
	 * own folder is never overwritten, and files that are not in the backup are
	 * kept. Resumable: `wp fmw resume <job_id>`.
	 *
	 * All-in-One WP Migration backups (.wpress, plain, encrypted or compressed)
	 * are restored the same way. The archive is checked first (structure, and
	 * its CRC-32 when it has one) and the database is imported before any file
	 * is written, so a damaged backup stops while the site is untouched.
	 *
	 * A multisite network backup (.fmw, or .wpress from the All-in-One WP
	 * Migration Multisite Extension) restores onto a multisite network of the same
	 * kind (subdomains or subdirectories), at any address: the main site and
	 * every subsite under the network's address move with it
	 * (shop.old.example -> shop.new.example, old.example/shop/ ->
	 * new.example/shop/). The whole network is replaced, including sites that
	 * are not in the backup. On a single site, --site restores one site of a
	 * .fmw network backup as this site (its tables, media, users with a role
	 * on it, and the network-activated plugins).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the fmw-backups or ai1wm-backups folder) or path.
	 *
	 * [--password=<password>]
	 * : Password of an encrypted .wpress backup. Asked for when missing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--keep-old-tables]
	 * : Keep the replaced tables as fmwold_* (remove later with `wp fmw cleanup --tables`).
	 *
	 * [--exclude-email-replace]
	 * : Do not change e-mail addresses at the old domain.
	 *
	 * [--map=<domains>]
	 * : Multisite: new domains for subsites with their own domain, as old=new pairs separated by commas. Other subsites follow the network.
	 *
	 * [--site=<site>]
	 * : On a single site: the site of a network backup (.fmw, or .wpress of a whole network or of sites picked one by one) to restore, by its ID or address (2, shop.example.com, example.com/shop); not needed for a .wpress of one picked site. On a network, for a .wpress of sites picked one by one: the site each becomes, as old=new pairs separated by commas (2=shop,3=4; sites left out stay in the backup), or just the new site when it holds one. On a network, for a single-site backup: the site a single-site backup becomes, new (shop, shop.example.com, example.com/shop) or existing (its ID or address).
	 *
	 * [--skip-space-check]
	 * : Start even if the free disk space looks too small.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw restore example.com-20260924-180000-a1b2c3.fmw
	 *     wp fmw restore /backups/site.fmw --yes --keep-old-tables
	 *     wp fmw restore example-com-20260924-180000-abc123.wpress
	 *     wp fmw restore network.fmw --map=brand.example=brand.staging.example
	 *     wp fmw restore network.fmw --site=example.com/shop   # on a single site
	 *     wp fmw restore site.wpress --site=shop               # on a network: a new site
	 *     wp fmw restore network.wpress --site=2               # on a single site: site 2 of a .wpress network backup
	 *     wp fmw restore picked.wpress --site=2=shop,3=4       # on a network: picked site 2 becomes a new site, 3 replaces site 4
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$wpress = $this->find_wpress( $args[0] );
		if ( null !== $wpress ) {
			$this->restore_wpress( $wpress, $assoc_args );
			return;
		}

		$archive  = $this->open_archive( $args[0] );
		$password = null;
		try {
			if ( $archive->encrypted() ) {
				$password = $this->password( $assoc_args, false );
			} elseif ( isset( $assoc_args['password'] ) ) {
				WP_CLI::error( 'This backup is NOT encrypted, although a password was given. If you encrypted it, the file may have been replaced; restore refused.' );
			}
			$manifest = $archive->manifest( $password );
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$site = (array) ( $manifest['site'] ?? array() );
		$path = Backups::find( $args[0] ) ?? (string) realpath( $args[0] );
		try {
			$options = RestoreOptions::build( $path, $assoc_args );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( empty( $site['multisite'] ) && is_multisite() ) {
			$into = $this->into_network( $options );
		} elseif ( '' !== $options['subsite'] ) {
			if ( empty( $site['multisite'] ) || is_multisite() ) {
				WP_CLI::error( '--site chooses one site of a network backup (on a single site), or the site a single-site backup becomes (on a network).' );
			}
			try {
				$chosen = SubsiteExtract::resolve( $site, $options['subsite'] );
			} catch ( JobException $e ) {
				WP_CLI::error( $e->getMessage() );
				return;
			}
			WP_CLI::log( sprintf( 'Site %d (%s) of the network becomes this site, %s. Other sites and their media stay in the backup; users without a role, posts or comments on it are left out (super admins become administrators).', $chosen['blog_id'], SubsiteExtract::printable( $chosen['domain'] . $chosen['path'] ), home_url() ) );
		} elseif ( ! empty( $site['multisite'] ) && ! is_multisite() ) {
			WP_CLI::error( sprintf( 'This is a backup of a whole network and this is a single site: choose the site to restore with --site=<id or address>. Its sites: %s.', SubsiteExtract::listing( $site ) ) );
		}
		if ( ! empty( $site['multisite'] ) && is_multisite() ) {
			$this->network_preview( $site, $options );
		} elseif ( ! empty( $options['domain_map'] ) ) {
			WP_CLI::error( '--map is for restoring a multisite network onto a network.' );
		}
		WP_CLI::confirm(
			sprintf(
				'Restore %s (%s, created %s) onto %s? This replaces this %s\'s files and database.',
				basename( $args[0] ),
				isset( $chosen ) ? 'site ' . $chosen['blog_id'] . ', ' . SubsiteExtract::printable( $chosen['domain'] . $chosen['path'] ) : (string) ( $site['home_url'] ?? '?' ),
				(string) ( $manifest['created_at'] ?? '?' ),
				$into ?? home_url(),
				isset( $into ) ? 'network site' : ( is_multisite() ? 'network' : 'site' )
			),
			$assoc_args
		);

		Paths::ensure_all();
		if ( null !== $password ) {
			$options['secret_password'] = Secrets::seal( $password ); // Removed from the job when it ends.
		}
		$job = Jobs::store()->create( 'restore', $options );
		$this->run_job( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Shows which site of this network a single-site backup becomes; stops when none is chosen or the choice is not usable.
	 *
	 * @param array<string,mixed> $options Restore job options.
	 * @return string The site's address, for the confirmation.
	 */
	private function into_network( array $options ): string {
		if ( '' === $options['subsite'] ) {
			WP_CLI::error( 'This is a backup of a single site and this is a multisite network: choose the site it becomes with --site=<new address or existing site>, for example --site=shop (a new site) or --site=3 (replaces site 3).' );
		}
		try {
			$plan = SubsiteImport::resolve( (array) $options['target'], $options['subsite'] );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
			return '';
		}
		$address = SubsiteExtract::printable( $plan['domain'] . $plan['path'] );
		WP_CLI::log(
			$plan['new']
				? sprintf( 'The backup becomes a new site of this network at %s (the next free site ID). Its users join the network: people with an account here (same login or e-mail) keep their password and profile and get a role on the new site.', $address )
				: sprintf( 'The backup replaces site %d (%s) of this network. Its users join the network: people with an account here (same login or e-mail) keep their password and profile and get their role on this site.', $plan['blog_id'], $address )
		);
		WP_CLI::log( 'Themes and plugins in the backup join the network\'s; its mu-plugins and drop-ins are left out.' );
		return $address;
	}

	/**
	 * Shows which site of this network each chosen site of a backup of picked sites becomes; stops when the choice is not usable.
	 *
	 * @param array<string,mixed> $source  The backup's network (WpressNetwork::site()).
	 * @param array<string,mixed> $options Restore job options.
	 * @return string The sites' addresses, for the confirmation.
	 */
	private function picked_into_network( array $source, array $options ): string {
		try {
			$sites = SubsiteImport::picked_choices( $source, (array) $options['target'], $options['subsite'] );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
			return '';
		}
		$addresses = array();
		foreach ( $sites as $site ) {
			$address     = SubsiteExtract::printable( $site['domain'] . $site['path'] );
			$addresses[] = $address;
			WP_CLI::log(
				$site['new']
					? sprintf( 'Site %d of the backup becomes a new site of this network at %s.', $site['from'], $address )
					: sprintf( 'Site %d of the backup replaces site %d (%s) of this network.', $site['from'], $site['blog_id'], $address )
			);
		}
		WP_CLI::log( 'Their users join the network: people with an account here (same login or e-mail) keep their password and profile and get their role on these sites. Themes and plugins in the backup join the network\'s; mu-plugins and drop-ins are left out.' );
		return implode( ', ', $addresses );
	}

	/**
	 * Shows where each site of a network backup goes; stops when the network cannot be restored here.
	 *
	 * @param array<string,mixed> $site    Manifest site.
	 * @param array<string,mixed> $options Restore job options.
	 * @return void
	 */
	private function network_preview( array $site, array $options ): void {
		try {
			$plan = NetworkMove::plan( $site, (array) $options['target'], (array) $options['domain_map'] );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$rows = array();
		foreach ( $plan['sites'] as $blog ) {
			$from   = $blog['from']['domain'] . $blog['from']['path'];
			$to     = $blog['to']['domain'] . $blog['to']['path'];
			$rows[] = array(
				'Site'   => $blog['blog_id'],
				'Backup' => $from,
				'Here'   => $from === $to && $plan['moved'] ? $to . ' (own domain, kept)' : $to,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'Site', 'Backup', 'Here' ) );
		if ( $plan['kept'] && $plan['moved'] ) {
			WP_CLI::warning( sprintf( 'Sites with their own domain keep it: %s. Give them a new one with --map=<old>=<new>.', implode( ', ', $plan['kept'] ) ) );
		}
		$missing = array_diff(
			array_map(
				'intval',
				get_sites(
					array(
						'fields' => 'ids',
						'number' => 0,
					)
				)
			),
			array_column( $plan['sites'], 'blog_id' )
		);
		if ( $missing ) {
			WP_CLI::warning(
				sprintf(
					1 === count( $missing )
						? 'Site %s of this network is not in the backup: it is removed (its tables are kept as fmwold_* with --keep-old-tables).'
						: 'Sites %s of this network are not in the backup: they are removed (their tables are kept as fmwold_* with --keep-old-tables).',
					implode( ', ', $missing )
				)
			);
		}
	}

	/**
	 * A network's kind in words.
	 *
	 * @param bool|null $subdomain Subdomain install; null when unknown.
	 * @return string
	 */
	private static function kind( ?bool $subdomain ): string {
		return null === $subdomain ? 'kind unknown' : ( $subdomain ? 'subdomains' : 'subdirectories' );
	}

	/**
	 * The password from --password=<value>, or asked for without echo.
	 *
	 * @param array<string,mixed> $assoc_args Flags.
	 * @param bool                $is_new     A new password: asked twice.
	 * @return string
	 */
	private function password( array $assoc_args, bool $is_new ): string {
		$password = $assoc_args['password'] ?? '';
		if ( is_string( $password ) && '' !== $password ) {
			return $password;
		}
		if ( ! function_exists( 'posix_isatty' ) || ! posix_isatty( STDIN ) ) {
			WP_CLI::error( $is_new ? 'Pass the password as --password=<password> (no terminal to ask it on).' : 'This backup is encrypted: pass --password=<password>.' );
		}
		$password = (string) \cli\prompt( $is_new ? 'Password for this backup' : 'Password of this backup', false, ': ', true );
		if ( $is_new && (string) \cli\prompt( 'Repeat the password', false, ': ', true ) !== $password ) {
			WP_CLI::error( 'The passwords do not match.' );
		}
		return $password;
	}

	/**
	 * Path of a .wpress backup given by name or path, or null when $file is not one.
	 *
	 * @param string $file Name or path.
	 * @return string|null
	 */
	private function find_wpress( string $file ): ?string {
		$candidates = array( $file );
		if ( basename( $file ) === $file ) {
			$candidates[] = fmwp_backups_path() . '/' . $file;
			$candidates[] = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/ai1wm-backups/' . $file;
		}
		foreach ( $candidates as $path ) {
			if ( is_file( $path ) && ( '.wpress' === strtolower( substr( $path, -7 ) ) || WpressReader::looks_like_wpress( $path ) ) ) {
				return (string) realpath( $path );
			}
		}
		if ( '.wpress' === strtolower( substr( $file, -7 ) ) ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found (looked in the current folder, %s and wp-content/ai1wm-backups).', $file, fmwp_backups_path() ) );
		}
		return null;
	}

	/**
	 * Starts a .wpress restore job.
	 *
	 * @param string               $path       Archive path.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	private function restore_wpress( string $path, array $assoc_args ): void {
		try {
			$package = WpressPackage::read( $path );
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		try {
			new WpressDecoder( null, $package->compression() ); // Refuses early when a PHP extension is missing.
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$key = null;
		if ( $package->encrypted() ) {
			$password = $this->password( $assoc_args, false );
			try {
				$key = $package->key_for( $password );
			} catch ( ArchiveException $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		$data = $package->data();
		try {
			$network = WpressNetwork::read( $path, $key, $package->compression() );
			$options = RestoreOptions::build( $path, $assoc_args );
		} catch ( ArchiveException | \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( null === $network && is_multisite() ) {
			$into = $this->into_network( $options );
		} elseif ( null === $network && '' !== $options['subsite'] ) {
			WP_CLI::error( '--site chooses one site of a network backup (on a single site), or the site a single-site backup becomes (on a network).' );
		} elseif ( null !== $network && ! is_multisite() ) {
			try {
				$chosen = $network->extract( $package, $options['subsite'] );
			} catch ( JobException $e ) {
				WP_CLI::error( $e->getMessage() );
				return;
			}
			WP_CLI::log( sprintf( 'Site %d (%s) of the network becomes this site, %s. Other sites and their media stay in the backup; users without a role, posts or comments on it are left out (super admins become administrators).', $chosen['blog_id'], SubsiteExtract::printable( $chosen['domain'] . $chosen['path'] ), home_url() ) );
		} elseif ( null !== $network && ! $network->is_network() ) {
			$into = $this->picked_into_network( $network->site( $package ), $options );
		} elseif ( null !== $network && '' !== $options['subsite'] ) {
			WP_CLI::error( 'This is a backup of a whole network: on a network it restores as a whole, without --site.' );
		}
		if ( null !== $network && is_multisite() && $network->is_network() ) {
			$this->network_preview( $network->site( $package ), $options );
		} elseif ( ! empty( $options['domain_map'] ) ) {
			WP_CLI::error( '--map is for restoring a multisite network onto a network.' );
		}
		WP_CLI::confirm(
			sprintf(
				'Restore %s (All-in-One WP Migration %s backup of %s) onto %s? This replaces this %s\'s files and database.',
				basename( $path ),
				'' !== $package->plugin_version() ? $package->plugin_version() : '?',
				isset( $chosen ) ? 'site ' . $chosen['blog_id'] . ', ' . SubsiteExtract::printable( $chosen['domain'] . $chosen['path'] ) : (string) ( $data['HomeURL'] ?? '?' ),
				$into ?? home_url(),
				isset( $into ) ? 'network site' : ( is_multisite() ? 'network' : 'site' )
			),
			$assoc_args
		);

		Paths::ensure_all();
		if ( null !== $key ) {
			$options['secret_wpress_key'] = Secrets::seal( $key ); // Removed from the job when it ends.
		}
		$job = Jobs::store()->create( 'restore-wpress', $options );
		$this->run_job( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Resets parts of this site to a fresh WordPress: database, media, plugins and/or themes.
	 *
	 * Same as the Reset Hub of All-in-One WP Migration, with more safety:
	 *
	 * - A backup of the whole site is made first (skip with --skip-backup), so
	 *   a reset can be undone with `wp fmw restore <backup>`.
	 * - You confirm by typing the site's domain (or pass --yes in scripts).
	 * - The fresh database is built next to the live one and switched in with
	 *   one atomic rename, so a reset that fails before that changes nothing.
	 *
	 * Database: every table of this site (plugin tables too) is replaced by a
	 * new WordPress install. Kept: the site address, title, tagline, admin
	 * e-mail, language, time zone, date formats, permalinks, search engine
	 * visibility and the kept users (as administrators, still logged in). This
	 * plugin and the active theme stay active. Tables of other WordPress sites
	 * in the same database (another table prefix) are not touched.
	 *
	 * Media: everything in the uploads folder, and the media library entries.
	 * Plugins: all plugins except this one (must-use plugins and drop-ins stay).
	 * Themes: all themes except the active theme (and its parent).
	 *
	 * Resumable: `wp fmw resume <job_id>`. Not available on multisite yet.
	 *
	 * ## OPTIONS
	 *
	 * [--database]
	 * : Replace the database with a fresh WordPress install.
	 *
	 * [--media]
	 * : Delete the uploads folder's contents and the media library.
	 *
	 * [--plugins]
	 * : Delete all plugins except this one.
	 *
	 * [--themes]
	 * : Delete all themes except the active one.
	 *
	 * [--all]
	 * : All of the above.
	 *
	 * [--keep-user=<users>]
	 * : Comma-separated user IDs, logins or e-mails kept as administrators by a database reset. Default: all administrators.
	 *
	 * [--skip-backup]
	 * : Do not make a backup first. The reset cannot be undone then.
	 *
	 * [--keep-old-tables]
	 * : Keep the replaced tables as fmwold_* (remove later with `wp fmw cleanup --tables`).
	 *
	 * [--yes]
	 * : Do not ask to type the domain.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw reset --all
	 *     wp fmw reset --plugins --themes
	 *     wp fmw reset --database --keep-user=admin --yes
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function reset( $args, $assoc_args ) {
		$parts = array();
		foreach ( ResetOptions::PARTS as $part ) {
			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) || WP_CLI\Utils\get_flag_value( $assoc_args, $part, false ) ) {
				$parts[] = $part;
			}
		}
		if ( ! $parts ) {
			WP_CLI::error( 'Choose what to reset: --database, --media, --plugins, --themes or --all.' );
		}

		$users = ResetOptions::administrators();
		if ( isset( $assoc_args['keep-user'] ) ) {
			$users = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['keep-user'] ) ) ) as $name ) {
				$user = ctype_digit( $name ) ? get_user_by( 'id', (int) $name ) : ( get_user_by( 'login', $name ) ? get_user_by( 'login', $name ) : get_user_by( 'email', $name ) );
				if ( ! $user ) {
					WP_CLI::error( sprintf( 'User "%s" not found.', $name ) );
					return;
				}
				$users[] = (int) $user->ID;
			}
		}

		try {
			$options = ResetOptions::build( $parts, $users, (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-old-tables', false ) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$skip_backup = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-backup', false );
		WP_CLI::log( sprintf( 'Reset of %s: %s.', home_url(), implode( ', ', $parts ) ) );
		if ( in_array( 'database', $parts, true ) ) {
			$logins = array_map(
				static function ( int $id ): string {
					$user = get_userdata( $id );
					return $user ? (string) $user->user_login : (string) $id;
				},
				$options['keep_users']
			);
			WP_CLI::log( sprintf( 'Users kept as administrators: %s. All other content, settings and users are deleted.', implode( ', ', $logins ) ) );
		}
		WP_CLI::log( $skip_backup ? 'No backup is made first (--skip-backup): this cannot be undone.' : 'A backup of the whole site is made first.' );

		if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			if ( ! function_exists( 'posix_isatty' ) || ! posix_isatty( STDIN ) ) {
				WP_CLI::error( 'Pass --yes to reset without a terminal to confirm on.' );
			}
			$typed = (string) \cli\prompt( sprintf( 'Type %s to confirm', ResetOptions::confirm_word() ), false, ': ' );
			if ( ! ResetOptions::confirmed( $typed ) ) {
				WP_CLI::error( 'Not confirmed; nothing was changed.' );
			}
		}

		Paths::ensure_all();
		$no_progress = ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true );
		if ( ! $skip_backup ) {
			WP_CLI::log( 'Safety backup:' );
			$this->run_job( Jobs::store()->create( 'backup', BackupOptions::from_flags( array() ) ), $no_progress ); // Stops here unless the backup completed.
		}
		$this->run_job( Jobs::store()->create( 'reset', $options ), $no_progress );
	}

	/**
	 * Lists backup, restore and pull jobs, newest first.
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw jobs
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function jobs( $args, $assoc_args ) {
		$store  = Jobs::store();
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$jobs   = $store->all();

		if ( 'ids' === $format ) {
			$ids = array_map(
				static function ( Job $job ) {
					return $job->id;
				},
				$jobs
			);
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		$rows = array_map(
			static function ( Job $job ) use ( $store ) {
				$progress = $job->progress();
				$status   = $job->status;
				if ( Job::STATUS_RUNNING === $status && ! Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
					$status = 'interrupted';
				}
				return array(
					'id'       => $job->id,
					'type'     => $job->type,
					'status'   => $status,
					'phase'    => $job->phase,
					'progress' => null === $progress ? '' : (int) floor( $progress * 100 ) . '%',
					'updated'  => wp_date( 'Y-m-d H:i:s', $job->updated_at ),
				);
			},
			$jobs
		);

		if ( ! $rows && 'table' === $format ) {
			WP_CLI::line( 'No jobs.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'type', 'status', 'phase', 'progress', 'updated' ) );
	}

	/**
	 * Resumes an interrupted, paused or failed job from its last checkpoint.
	 *
	 * Exit codes: 0 completed, 1 failed, 3 stopped again and resumable.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID from `wp fmw jobs`.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default). Use --no-progress for cron and logs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw resume 01J8Z3K9QXAB12CD34EF56GH78
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		$job = $this->load_job( $args[0] );
		if ( $job->is_finished() ) {
			WP_CLI::error( sprintf( 'Job %s is already %s.', $job->id, $job->status ) );
		}
		$this->run_job( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Cancels a job and deletes its temporary files. A running job stops at its next checkpoint.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID from `wp fmw jobs`.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function cancel( $args, $assoc_args ) {
		$store = Jobs::store();
		$job   = $this->load_job( $args[0] );
		if ( $job->is_finished() ) {
			WP_CLI::error( sprintf( 'Job %s is already %s.', $job->id, $job->status ) );
		}

		WP_CLI::confirm( sprintf( 'Cancel job %s (%s)?', $job->id, $job->type ), $assoc_args );
		$store->request_cancel( $job->id );

		$lock = Lock::acquire( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE );
		if ( null === $lock ) {
			WP_CLI::success( sprintf( 'Cancellation requested. Job %s stops at its next checkpoint.', $job->id ) );
			return;
		}

		$store->log( $job->id, 'Cancelled.' );
		( new Runner( $store, Jobs::registry() ) )->apply_cancel( $job );
		$lock->release();
		WP_CLI::success( sprintf( 'Job %s cancelled and its temporary files deleted.', $job->id ) );
	}

	/**
	 * Shows the log of a job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID from `wp fmw jobs`.
	 *
	 * [--lines=<lines>]
	 * : Number of lines from the end; 0 for the whole log.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function log( $args, $assoc_args ) {
		$job   = $this->load_job( $args[0] );
		$lines = Jobs::store()->log_lines( $job->id, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'lines', 50 ) );
		foreach ( $lines as $line ) {
			WP_CLI::line( $line );
		}
		if ( Job::STATUS_FAILED === $job->status && null !== $job->error ) {
			WP_CLI::warning( 'Last error: ' . $job->error );
		}
	}

	/**
	 * Deletes storage of finished jobs, and of unfinished jobs untouched for a while.
	 *
	 * Completed and cancelled jobs are always removed. Failed, paused or
	 * interrupted jobs are removed when they have not been updated for
	 * --older-than days. Jobs that are running are never touched.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Age in days for unfinished jobs.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--dry-run]
	 * : Only list what would be deleted.
	 *
	 * [--tables]
	 * : Also drop leftover fmwtmp_* and fmwold_* tables from restores.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function cleanup( $args, $assoc_args ) {
		$store   = Jobs::store();
		$cutoff  = time() - DAY_IN_SECONDS * max( 0, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'older-than', 7 ) );
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$removed = 0;

		foreach ( $store->all() as $job ) {
			if ( Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
				continue;
			}
			if ( ! $job->is_finished() && $job->updated_at > $cutoff ) {
				continue;
			}
			WP_CLI::log( sprintf( '%s %s (%s, %s)', $dry_run ? 'Would delete' : 'Deleting', $job->id, $job->type, $job->status ) );
			if ( ! $dry_run ) {
				$store->delete( $job->id );
			}
			++$removed;
		}

		WP_CLI::success( sprintf( $dry_run ? '%d job(s) would be deleted.' : '%d job(s) deleted.', $removed ) );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'tables', false ) ) {
			foreach ( $store->all() as $job ) {
				if ( Jobs::changes_site( $job->type ) && Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
					WP_CLI::error( sprintf( 'Job %s (%s) is running; its tables cannot be removed now.', $job->id, $job->type ) );
				}
			}
			$restore = new RestoreDatabase();
			$tables  = array_merge( $restore->tables( RestoreDatabase::TMP ), $restore->tables( RestoreDatabase::OLD ) );
			foreach ( $tables as $table ) {
				WP_CLI::log( ( $dry_run ? 'Would drop ' : 'Dropping ' ) . $table );
			}
			if ( ! $dry_run ) {
				$restore->drop( $tables );
			}
			WP_CLI::success( sprintf( $dry_run ? '%d table(s) would be dropped.' : '%d table(s) dropped.', count( $tables ) ) );
		}
	}

	/**
	 * Checks every part of a backup against the SHA-256 checksums in its manifest.
	 *
	 * For a .wpress backup: checks every header and, when the archive has one
	 * (recent All-in-One WP Migration versions), its CRC-32. No password needed.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the backups folder) or path.
	 *
	 * [--password[=<password>]]
	 * : Password of an encrypted .fmw backup (its parts' HMACs are checked too). Asked for when needed.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw verify example.com-20260924-180000-a1b2c3.fmw
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$wpress = $this->find_wpress( $args[0] );
		if ( null !== $wpress ) {
			$this->verify_wpress( $wpress, WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
			return;
		}
		$archive  = $this->open_archive( $args[0] );
		$password = $archive->encrypted() ? $this->password( $assoc_args, false ) : null;
		$bar      = WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) ? new ProgressBar() : null;
		$problems = $archive->verify(
			static function ( int $done, int $total ) use ( $bar ) {
				if ( null !== $bar ) {
					$bar->update( 'Verify', $done, $total );
				}
			},
			$password
		);
		if ( null !== $bar ) {
			$bar->finish();
		}

		if ( $problems ) {
			foreach ( $problems as $problem ) {
				WP_CLI::warning( $problem );
			}
			WP_CLI::error( sprintf( '%s failed verification (%d problem(s)).', basename( $args[0] ), count( $problems ) ) );
		}
		WP_CLI::success( sprintf( 'All %d parts of %s are intact%s.', count( $archive->manifest( $password )['parts'] ), basename( $args[0] ), null === $password ? '' : ' (SHA-256 and HMAC)' ) );
	}

	/**
	 * Shows what a backup contains, without reading its parts.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the backups folder) or path.
	 *
	 * [--password[=<password>]]
	 * : Password of an encrypted .fmw backup, to show what it contains.
	 *
	 * [--format=<format>]
	 * : table for a summary, json for the full manifest.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw inspect example.com-20260924-180000-a1b2c3.fmw
	 *     wp fmw inspect example.com-20260924-180000-a1b2c3.fmw --format=json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function inspect( $args, $assoc_args ) {
		$wpress = $this->find_wpress( $args[0] );
		if ( null !== $wpress ) {
			$this->inspect_wpress( $wpress, (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) );
			return;
		}
		$archive = $this->open_archive( $args[0] );
		try {
			if ( $archive->encrypted() && ! isset( $assoc_args['password'] ) ) {
				$header = $archive->header();
				WP_CLI\Utils\format_items(
					'table',
					array(
						array(
							'field' => 'Created',
							'value' => (string) ( $header['created_at'] ?? '-' ),
						),
						array(
							'field' => 'Generator',
							'value' => (string) ( $header['generator'] ?? '-' ),
						),
						array(
							'field' => 'Encrypted',
							'value' => sprintf( 'yes (%s, PBKDF2 %d iterations)', (string) ( $header['cipher'] ?? '?' ), (int) ( $header['kdf']['iterations'] ?? 0 ) ),
						),
					),
					array( 'field', 'value' )
				);
				WP_CLI::log( 'What the backup contains is encrypted too; add --password to see it.' );
				return;
			}
			$manifest = $archive->manifest( $archive->encrypted() ? $this->password( $assoc_args, false ) : null );
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( 'json' === WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$site   = (array) ( $manifest['site'] ?? array() );
		$totals = (array) ( $manifest['totals'] ?? array() );
		$db     = (array) ( $site['db'] ?? array() );
		$rows   = array(
			'Created'         => (string) ( $manifest['created_at'] ?? '' ),
			'Generator'       => (string) ( $manifest['generator'] ?? '' ),
			'Site URL'        => (string) ( $site['home_url'] ?? '' ),
			'WordPress'       => (string) ( $site['wp_version'] ?? '' ),
			'PHP'             => (string) ( $site['php_version'] ?? '' ),
			'Database server' => trim( ( $db['engine'] ?? '' ) . ' ' . ( $db['version'] ?? '' ), ' ' ),
			'Table prefix'    => (string) ( $site['table_prefix'] ?? '' ),
			'Multisite'       => ! empty( $site['multisite'] ) ? sprintf( 'yes (%d sites, %s)', count( (array) ( $site['sites'] ?? array() ) ), self::kind( NetworkMove::source( $site )['subdomain'] ) ) : 'no',
			'Files'           => number_format( (int) ( $totals['files'] ?? 0 ) ),
			'Tables / rows'   => sprintf( '%d / %s', (int) ( $totals['tables'] ?? 0 ), number_format( (int) ( $totals['rows'] ?? 0 ) ) ),
			'Parts'           => (string) count( (array) $manifest['parts'] ),
			'Size'            => sprintf( '%s (%s before compression)', ProgressBar::bytes( (int) ( $totals['bytes_archived'] ?? 0 ) ), ProgressBar::bytes( (int) ( $totals['bytes_raw'] ?? 0 ) ) ),
			'Encrypted'       => ! empty( $manifest['options']['encrypted'] ) ? 'yes' : 'no',
			'Excluded'        => implode( ', ', (array) ( $manifest['options']['exclude'] ?? array() ) ),
		);

		$items = array();
		foreach ( $rows as $field => $value ) {
			$items[] = array(
				'field' => $field,
				'value' => '' === $value ? '-' : $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Walks a .wpress archive and checks its CRC-32 when it has one.
	 *
	 * @param string $path     Archive.
	 * @param bool   $progress Show a progress bar.
	 * @return void
	 */
	private function verify_wpress( string $path, bool $progress ): void {
		$size = (int) filesize( $path );
		$bar  = $progress ? new ProgressBar() : null;
		try {
			$reader = WpressReader::open( $path );
			$count  = 0;
			try {
				$entry = $reader->next();
				while ( null !== $entry ) {
					PathGuard::relative( $entry->name );
					++$count;
					$entry = $reader->next();
				}
				$end = $reader->archive_crc();
			} finally {
				$reader->close();
			}

			if ( null !== $end ) {
				$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Multi-GB archive.
				if ( false === $handle ) {
					WP_CLI::error( sprintf( 'Cannot read %s.', $path ) );
				}
				$hash = hash_init( 'crc32b' );
				$done = 0;
				while ( $done < $end['size'] ) {
					$data = fread( $handle, (int) min( 8388608, $end['size'] - $done ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Multi-GB archive.
					if ( false === $data || '' === $data ) {
						break;
					}
					hash_update( $hash, $data );
					$done += strlen( $data );
					if ( null !== $bar ) {
						$bar->update( 'Verify', $done, $size );
					}
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Multi-GB archive.
				if ( null !== $bar ) {
					$bar->finish();
				}
				if ( ! hash_equals( $end['crc'], hash_final( $hash ) ) ) {
					WP_CLI::error( sprintf( '%s is damaged (CRC-32 mismatch).', basename( $path ) ) );
				}
				WP_CLI::success( sprintf( '%s is intact: %d files, CRC-32 verified.', basename( $path ), $count ) );
				return;
			}
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		WP_CLI::success( sprintf( '%s is structurally intact: %d files. It has no checksum (older All-in-One WP Migration versions), so contents cannot be verified.', basename( $path ), $count ) );
	}

	/**
	 * Shows a .wpress backup's package.json.
	 *
	 * @param string $path   Archive.
	 * @param string $format table or json.
	 * @return void
	 */
	private function inspect_wpress( string $path, string $format ): void {
		try {
			$package = WpressPackage::read( $path );
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$data = $package->data();
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$excluded = array();
		foreach ( array(
			'NoSpamComments'    => 'spam comments',
			'NoPostRevisions'   => 'post revisions',
			'NoMedia'           => 'media',
			'NoThemes'          => 'themes',
			'NoInactiveThemes'  => 'inactive themes',
			'NoMustUsePlugins'  => 'mu-plugins',
			'NoPlugins'         => 'plugins',
			'NoInactivePlugins' => 'inactive plugins',
			'NoCache'           => 'cache',
			'NoDatabase'        => 'database',
		) as $key => $label ) {
			if ( ! empty( $data[ $key ] ) ) {
				$excluded[] = $label;
			}
		}
		try {
			$end     = WpressReader::end_block( $path );
			$present = null !== WpressPackage::read_entry( $path, 'multisite.json' );
			$network = $present && ! $package->encrypted() ? WpressNetwork::read( $path, null, $package->compression() ) : null;
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$multisite = $present ? 'network backup (details are encrypted)' : 'no';
		if ( null !== $network ) {
			$multisite = $network->is_network()
				? sprintf( 'whole network (%d sites, %s)', $network->count(), self::kind( $network->site( $package )['network']['subdomain'] ) )
				: sprintf( '%d site(s) picked from a network: %s', $network->count(), SubsiteExtract::listing( $network->site( $package ) ) );
		}

		$rows  = array(
			'Generator'    => 'All-in-One WP Migration ' . $package->plugin_version(),
			'Site URL'     => (string) ( $data['HomeURL'] ?? '' ),
			'Multisite'    => $multisite,
			'WordPress'    => $package->wordpress( 'Version' ),
			'PHP'          => (string) ( $data['PHP']['Version'] ?? '' ),
			'Database'     => (string) ( $data['Database']['Version'] ?? '' ),
			'Table prefix' => $package->table_prefix(),
			'Encrypted'    => $package->encrypted() ? 'yes' : 'no',
			'Compression'  => $package->compression(),
			'Checksum'     => null === $end ? 'none (older format)' : 'CRC-32 ' . $end['crc'],
			'Size'         => ProgressBar::bytes( (int) filesize( $path ) ),
			'Excluded'     => implode( ', ', $excluded ),
		);
		$items = array();
		foreach ( $rows as $field => $value ) {
			$items[] = array(
				'field' => $field,
				'value' => '' === $value ? '-' : $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Opens a backup by name (backups folder) or path, or stops with an error.
	 *
	 * @param string $file Name or path.
	 * @return FmwArchive
	 */
	private function open_archive( string $file ): FmwArchive {
		$path = Backups::find( $file );
		if ( null === $path && is_file( $file ) ) {
			$path = $file;
		}
		if ( null === $path ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found in %s.', $file, fmwp_backups_path() ) );
		}
		try {
			$archive = new FmwArchive( (string) $path );
			$archive->header();
			return $archive;
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		exit( 1 ); // Not reached: WP_CLI::error() exits.
	}

	/**
	 * Loads a job or stops with an error.
	 *
	 * @param string $id Job ID.
	 * @return Job
	 */
	private function load_job( string $id ): Job {
		try {
			return Jobs::store()->load( $id );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		exit( 1 ); // Not reached: WP_CLI::error() exits.
	}

	/**
	 * Runs a job in the foreground with progress output and ai1wm-style exit codes.
	 *
	 * @param Job  $job         Job.
	 * @param bool $no_progress Hide the progress bar.
	 * @param bool $porcelain   Print only the result (backup file name).
	 * @return void
	 */
	private function run_job( Job $job, bool $no_progress, bool $porcelain = false ): void {
		JobRunner::run( $job, $no_progress, $porcelain );
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
