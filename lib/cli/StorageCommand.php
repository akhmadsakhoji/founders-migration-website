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

use Founders\Migration\Controller\RestController;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Remote\GoogleAuth;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\StorageOptions;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;
use WP_CLI;

/**
 * Manages cloud storages (Google Drive and S3-compatible) and the backups in them.
 *
 * Works with Google Drive, Amazon S3, Cloudflare R2, Wasabi, Backblaze B2,
 * DigitalOcean Spaces, MinIO and other S3-compatible services. Secret keys
 * and Google tokens are stored encrypted with this site's keys, outside the
 * database.
 *
 * ## EXAMPLES
 *
 *     wp fmw storage add --provider=aws --region=ap-southeast-3 --bucket=my-backups --prefix=example.com --access-key=AKIA... --secret-key=...
 *     wp fmw storage add --provider=gdrive --client-id=123-abc.apps.googleusercontent.com --client-secret=GOCSPX-...
 *     wp fmw storage connect 5e6f7a8b
 *     wp fmw storage test 1a2b3c4d
 *     wp fmw backup --storage=1a2b3c4d
 *     wp fmw storage files 1a2b3c4d
 *     wp fmw storage download example.com-20260925-020000-a1b2c3.fmw --from=1a2b3c4d
 */
final class StorageCommand {

	/**
	 * Lists cloud storages.
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
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$format = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$rows   = array();
		foreach ( Storages::store()->all() as $storage ) {
			$view   = StorageOptions::public_view( $storage );
			$drive  = 'gdrive' === $view['provider'];
			$rows[] = array(
				'id'            => $view['id'],
				'name'          => $view['name'],
				'provider'      => $view['provider_label'],
				'endpoint'      => $drive ? '' : (string) $view['endpoint'],
				'region'        => $drive ? '' : (string) $view['region'],
				'location'      => $view['location'],
				'account'       => $drive ? ( $view['connected'] ? (string) $view['account'] : 'not connected' ) : (string) $view['access_key'],
				'path_style'    => $drive ? '' : ( $view['path_style'] ? 'yes' : 'no' ),
				'storage_class' => $drive ? '' : (string) $view['storage_class'],
			);
		}
		if ( ! $rows && 'table' === $format ) {
			WP_CLI::log( 'No cloud storage yet. Add one with `wp fmw storage add`.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'provider', 'endpoint', 'region', 'location', 'account', 'path_style', 'storage_class' ) );
	}

	/**
	 * Adds a cloud storage, after checking that it works.
	 *
	 * ## OPTIONS
	 *
	 * [--bucket=<bucket>]
	 * : Bucket name (S3-compatible storages).
	 *
	 * [--access-key=<key>]
	 * : Access key ID (S3-compatible storages).
	 *
	 * [--secret-key=<secret>]
	 * : Secret access key. Asked for without echo when missing.
	 *
	 * [--provider=<provider>]
	 * : Service; sets the endpoint and addressing style.
	 * ---
	 * default: custom
	 * options:
	 *   - aws
	 *   - r2
	 *   - wasabi
	 *   - b2
	 *   - spaces
	 *   - gdrive
	 *   - custom
	 * ---
	 *
	 * [--endpoint=<url>]
	 * : Endpoint URL. Needed for r2 (https://<account-id>.r2.cloudflarestorage.com) and custom (MinIO, ...).
	 *
	 * [--region=<region>]
	 * : Region, such as ap-southeast-3 (AWS Jakarta), auto (R2) or sgp1 (Spaces).
	 *
	 * [--prefix=<folder>]
	 * : Folder inside the bucket, for example the site's domain.
	 *
	 * [--name=<name>]
	 * : Name shown in lists.
	 *
	 * [--path-style]
	 * : Address the bucket in the path (https://endpoint/bucket/...) instead of the host name.
	 *
	 * [--storage-class=<class>]
	 * : Amazon S3 storage class: STANDARD, STANDARD_IA, ONEZONE_IA, INTELLIGENT_TIERING or GLACIER_IR.
	 *
	 * [--client-id=<id>]
	 * : Google Drive: client ID of your OAuth client (Google Cloud Console, type "Web application").
	 *
	 * [--client-secret=<secret>]
	 * : Google Drive: its client secret. Asked for without echo when missing.
	 *
	 * [--skip-test]
	 * : Save without the write/read/delete check.
	 *
	 * [--porcelain]
	 * : Print only the new storage's ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function add( $args, $assoc_args ) {
		$input = $this->input( $assoc_args );
		$drive = 'gdrive' === ( $input['provider'] ?? '' );
		if ( $drive && ! isset( $input['client_secret'] ) ) {
			$input['client_secret'] = $this->ask_secret( 'Client secret' );
		} elseif ( ! $drive && ! isset( $input['secret_key'] ) ) {
			$input['secret_key'] = $this->ask_secret( 'Secret key' );
		}
		$storage = $this->build( $input, null );
		if ( ! $drive ) {
			$this->check( $storage, $assoc_args );
		}
		Storages::store()->save( $storage );
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $storage['id'] );
			return;
		}
		if ( $drive ) {
			WP_CLI::success( sprintf( 'Storage %s added. Now sign in to Google: run `wp fmw storage connect %s` and open the link while logged in to wp-admin. The OAuth client must list this redirect URI: %s', $storage['id'], $storage['id'], GoogleAuth::redirect_uri() ) );
			return;
		}
		WP_CLI::success( sprintf( 'Storage %s added: %s (%s). Use it with `wp fmw backup --storage=%s`.', $storage['id'], $storage['name'], StorageOptions::public_view( $storage )['location'], $storage['id'] ) );
	}

	/**
	 * Changes a cloud storage. Only the options given change.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage ID.
	 *
	 * [--name=<name>]
	 * : Name.
	 *
	 * [--endpoint=<url>]
	 * : Endpoint URL.
	 *
	 * [--region=<region>]
	 * : Region.
	 *
	 * [--bucket=<bucket>]
	 * : Bucket.
	 *
	 * [--prefix=<folder>]
	 * : Folder inside the bucket ("" for the root).
	 *
	 * [--access-key=<key>]
	 * : Access key ID.
	 *
	 * [--secret-key[=<secret>]]
	 * : New secret key (asked for when given without a value).
	 *
	 * [--[no-]path-style]
	 * : Path-style addressing.
	 *
	 * [--storage-class=<class>]
	 * : Storage class ("" for the default).
	 *
	 * [--client-id=<id>]
	 * : Google Drive: client ID (a new one needs `wp fmw storage connect` again).
	 *
	 * [--client-secret=<secret>]
	 * : Google Drive: client secret.
	 *
	 * [--skip-test]
	 * : Save without the check.
	 *
	 * @param string[]                  $args       Positional arguments.
	 * @param array<string,string|bool> $assoc_args Flags.
	 * @return void
	 */
	public function update( $args, $assoc_args ) {
		$existing = $this->get( $args[0] );
		$input    = $this->input( $assoc_args );
		if ( isset( $assoc_args['secret-key'] ) && true === $assoc_args['secret-key'] ) {
			$input['secret_key'] = $this->ask_secret( 'Secret key' );
		}
		$storage = $this->build( $input, $existing );
		if ( 'gdrive' !== $storage['provider'] || ! empty( $storage['refresh'] ) ) {
			$this->check( $storage, $assoc_args );
		}
		Storages::save_settings( $storage );
		WP_CLI::success( sprintf( 'Storage %s updated.', $storage['id'] ) );
	}

	/**
	 * Deletes a cloud storage from this site. The backups in it are not touched.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$this->get( $args[0] );
		Storages::store()->delete( $args[0] );
		WP_CLI::success( sprintf( 'Storage %s removed from this site (the backups in it were kept). Schedules that upload to it will report an error until you change them.', $args[0] ) );
	}

	/**
	 * Checks a storage: writes, reads, lists and deletes a small test file.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		$storage = $this->get( $args[0] );
		try {
			WP_CLI::success( sprintf( '"%s": %s.', $storage['name'], Storages::test( $storage ) ) );
		} catch ( RemoteException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Signs a Google Drive storage in to a Google account.
	 *
	 * Prints Google's consent link. Open it in a browser where you are logged
	 * in to this site's wp-admin as an administrator; Google then sends you
	 * back to the site, which stores the sign-in. The OAuth client must list
	 * the redirect URI printed here.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function connect( $args, $assoc_args ) {
		$storage = $this->get( $args[0] );
		if ( 'gdrive' !== $storage['provider'] ) {
			WP_CLI::error( 'Only Google Drive storages sign in; S3 storages use their keys.' );
		}
		try {
			$url = GoogleAuth::authorize_url( $storage, 0 );
		} catch ( RemoteException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		WP_CLI::log( 'Redirect URI (must be listed in the OAuth client): ' . GoogleAuth::redirect_uri() );
		WP_CLI::log( 'Open this link within 20 minutes, logged in to wp-admin:' );
		WP_CLI::line( $url );
		WP_CLI::log( sprintf( 'Then check it with `wp fmw storage test %s`.', $storage['id'] ) );
	}

	/**
	 * Signs a Google Drive storage out (the backups in Drive stay).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function disconnect( $args, $assoc_args ) {
		$storage = $this->get( $args[0] );
		if ( 'gdrive' !== $storage['provider'] ) {
			WP_CLI::error( 'Only Google Drive storages sign in.' );
		}
		GoogleAuth::disconnect( $storage );
		WP_CLI::success( sprintf( '"%s" is signed out of Google.', $storage['name'] ) );
	}

	/**
	 * Lists the backups (.fmw, .wpress) in a storage's folder, newest first.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function files( $args, $assoc_args ) {
		$storage = $this->get( $args[0] );
		try {
			$items = Storages::backups( $storage );
		} catch ( RemoteException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$format = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$rows   = array_map(
			static function ( array $item ) use ( $format ): array {
				return array(
					'name' => $item['name'],
					'size' => 'table' === $format ? ProgressBar::bytes( $item['size'] ) : $item['size'],
					'date' => wp_date( 'Y-m-d H:i', $item['mtime'] ),
				);
			},
			$items
		);
		if ( ! $rows && 'table' === $format ) {
			WP_CLI::log( sprintf( 'No backups in "%s" yet.', $storage['name'] ) );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'name', 'size', 'date' ) );
	}

	/**
	 * Uploads a backup from the backups folder to a storage. Resumable.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the backups folder) or path.
	 *
	 * --to=<id>
	 * : Storage ID.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function upload( $args, $assoc_args ) {
		$storage = $this->get( (string) $assoc_args['to'] );
		$path    = Backups::find( $args[0] ) ?? ( is_file( $args[0] ) ? (string) realpath( $args[0] ) : '' );
		if ( '' === $path ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found.', $args[0] ) );
		}
		$this->ensure_idle();
		Paths::ensure_all();
		$job = Jobs::store()->create( 'upload', Storages::upload_job( $storage, $path ) );
		JobRunner::run( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Downloads a backup from a storage into the backups folder. Resumable.
	 *
	 * Restore it afterwards with `wp fmw restore <file>`.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name in the storage (see `wp fmw storage files <id>`).
	 *
	 * --from=<id>
	 * : Storage ID.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function download( $args, $assoc_args ) {
		$storage = $this->get( (string) $assoc_args['from'] );
		$this->ensure_idle();
		Paths::ensure_all();
		try {
			$options = Storages::download_job( $storage, $args[0] );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$job = Jobs::store()->create( 'download', $options );
		JobRunner::run( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Deletes a backup from a storage.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name in the storage.
	 *
	 * --from=<id>
	 * : Storage ID.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function remove( $args, $assoc_args ) {
		$storage = $this->get( (string) $assoc_args['from'] );
		if ( basename( $args[0] ) !== $args[0] ) {
			WP_CLI::error( 'Give the backup file name, without a folder.' );
		}
		WP_CLI::confirm( sprintf( 'Delete %s from "%s"? This cannot be undone.', $args[0], $storage['name'] ), $assoc_args );
		try {
			if ( ! Storages::delete_backup( $storage, $args[0] ) ) {
				WP_CLI::error( sprintf( '%s is not in "%s".', $args[0], $storage['name'] ) );
			}
		} catch ( RemoteException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( 'Deleted %s from "%s".', $args[0], $storage['name'] ) );
	}

	/**
	 * Stops when another job is running (the same rule as the admin screens).
	 *
	 * @return void
	 */
	private function ensure_idle(): void {
		$active = RestController::active_job( '' );
		if ( null !== $active ) {
			WP_CLI::error( sprintf( 'Job %s (%s) is running. Try again when it has finished.', $active->id, $active->type ) );
		}
	}

	/**
	 * Storage by ID, or an error.
	 *
	 * @param string $id ID.
	 * @return array<string,mixed>
	 */
	private function get( string $id ): array {
		$storage = Storages::get( $id );
		if ( null === $storage ) {
			WP_CLI::error( sprintf( 'Cloud storage "%s" not found; see `wp fmw storage list`.', $id ) );
		}
		return (array) $storage;
	}

	/**
	 * Input fields from flags.
	 *
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return array<string,mixed>
	 */
	private function input( array $assoc_args ): array {
		$input = array();
		$map   = array(
			'name'          => 'name',
			'provider'      => 'provider',
			'endpoint'      => 'endpoint',
			'region'        => 'region',
			'bucket'        => 'bucket',
			'prefix'        => 'prefix',
			'access-key'    => 'access_key',
			'secret-key'    => 'secret_key',
			'client-id'     => 'client_id',
			'client-secret' => 'client_secret',
			'storage-class' => 'storage_class',
		);
		foreach ( $map as $flag => $field ) {
			if ( isset( $assoc_args[ $flag ] ) && ! is_bool( $assoc_args[ $flag ] ) ) {
				$input[ $field ] = (string) $assoc_args[ $flag ];
			}
		}
		if ( array_key_exists( 'path-style', $assoc_args ) ) {
			$input['path_style'] = (bool) $assoc_args['path-style'];
		}
		return $input;
	}

	/**
	 * Builds the storage, or stops with the validation error.
	 *
	 * @param array<string,mixed>      $input    Fields.
	 * @param array<string,mixed>|null $existing Storage being changed.
	 * @return array<string,mixed>
	 */
	private function build( array $input, ?array $existing ): array {
		try {
			return StorageOptions::build( $input, $existing, time() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		exit( 1 ); // Not reached.
	}

	/**
	 * Runs the connection test unless --skip-test.
	 *
	 * @param array<string,mixed> $storage    Storage.
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	private function check( array $storage, array $assoc_args ): void {
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-test', false ) ) {
			return;
		}
		try {
			$result = Storages::test( $storage );
			if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
				WP_CLI::log( sprintf( 'Checked "%s": %s.', $storage['name'], $result ) );
			}
		} catch ( RemoteException $e ) {
			WP_CLI::error( $e->getMessage() . ' Nothing was saved (use --skip-test to save anyway).' );
		}
	}

	/**
	 * A secret typed without echo.
	 *
	 * @param string $label Prompt.
	 * @return string
	 */
	private function ask_secret( string $label ): string {
		if ( ! function_exists( 'posix_isatty' ) || ! posix_isatty( STDIN ) ) {
			WP_CLI::error( sprintf( 'Pass the %s as a flag (no terminal to ask it on).', strtolower( $label ) ) );
		}
		return (string) \cli\prompt( $label, false, ': ', true );
	}
}
