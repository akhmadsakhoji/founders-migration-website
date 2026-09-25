<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Controller;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

use Founders\Migration\Job\Jobs;
use Founders\Migration\Model\Reset\ResetOptions;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Schedule\Scheduler;
use Founders\Migration\Requirements;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;

/**
 * Admin menu and pages: Export, Import, Backups, Reset (same flow as All-in-One WP Migration).
 */
final class AdminController {

	const SLUG_EXPORT    = 'fmw-export';
	const SLUG_IMPORT    = 'fmw-import';
	const SLUG_BACKUPS   = 'fmw-backups';
	const SLUG_RESET     = 'fmw-reset';
	const SLUG_SCHEDULES = 'fmw-schedules';
	const SLUG_REMOTE    = 'fmw-cloud';
	const SLUG_PULL      = 'fmw-pull';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Loads the page script and styles on the plugin's pages only.
	 *
	 * @return void
	 */
	public function assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which assets to load.
		if ( ! in_array( $page, array( self::SLUG_EXPORT, self::SLUG_IMPORT, self::SLUG_BACKUPS, self::SLUG_SCHEDULES, self::SLUG_REMOTE, self::SLUG_PULL, self::SLUG_RESET ), true ) ) {
			return;
		}
		wp_enqueue_style( 'fmw-admin', plugins_url( 'assets/admin.css', FMWP_PLUGIN_FILE ), array( 'dashicons' ), FMWP_VERSION );
		wp_enqueue_script( 'fmw-admin', plugins_url( 'assets/admin.js', FMWP_PLUGIN_FILE ), array(), FMWP_VERSION, true );
		wp_localize_script( 'fmw-admin', 'FMW', self::script_config() );
	}

	/**
	 * Settings and translated strings for admin.js.
	 *
	 * @return array<string,mixed>
	 */
	public static function script_config(): array {
		$base = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return array(
			// ?rest_route= works with and without pretty permalinks, also after a restore changed them.
			'restRoot'      => site_url( 'index.php' ), // Same host as wp-admin, even when the front end lives elsewhere.
			'restNamespace' => RestController::NAMESPACE_V1,
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'download'      => add_query_arg(
				array(
					'action'   => DownloadController::ACTION,
					'_wpnonce' => wp_create_nonce( DownloadController::ACTION ),
				),
				admin_url( 'admin-post.php' )
			),
			'chunk'         => RestController::chunk_bytes(),
			'version'       => FMWP_VERSION,
			'multisite'     => is_multisite(),
			'storages'      => self::storage_choices(),
			'loginUrl'      => wp_login_url( add_query_arg( 'page', self::SLUG_BACKUPS, $base ) ),
			'backupsUrl'    => add_query_arg( 'page', self::SLUG_BACKUPS, $base ),
			'i18n'          => array(
				'export'                => __( 'Export', 'founders-migration-website' ),
				'restore'               => __( 'Restore', 'founders-migration-website' ),
				'backup'                => __( 'Backup', 'founders-migration-website' ),
				'upload'                => __( 'Uploading', 'founders-migration-website' ),
				'preparing'             => __( 'Preparing…', 'founders-migration-website' ),
				'step'                  => /* translators: 1: step number, 2: number of steps, 3: step name. */ __( 'Step %1$d of %2$d: %3$s', 'founders-migration-website' ),
				'eta'                   => /* translators: %s: remaining time. */ __( 'about %s left', 'founders-migration-website' ),
				'cancel'                => __( 'Cancel', 'founders-migration-website' ),
				'close'                 => __( 'Close', 'founders-migration-website' ),
				'download'              => __( 'Download', 'founders-migration-website' ),
				'retry'                 => __( 'Try again', 'founders-migration-website' ),
				'logIn'                 => __( 'Log in', 'founders-migration-website' ),
				'continue'              => __( 'Continue', 'founders-migration-website' ),
				'backupDone'            => /* translators: 1: file name, 2: size. */ __( 'Backup %1$s (%2$s) is ready.', 'founders-migration-website' ),
				'restoreDone'           => is_multisite()
					? __( 'The network has been restored. Log in again with the accounts of the restored network.', 'founders-migration-website' )
					: __( 'The site has been restored. Log in again with the accounts of the restored site.', 'founders-migration-website' ),
				'cancelled'             => __( 'The job was cancelled. Nothing more will be changed.', 'founders-migration-website' ),
				'failed'                => __( 'The job stopped with an error:', 'founders-migration-website' ),
				'failedHint'            => __( 'Fix the cause, then continue the job from the Backups page (or wp fmw resume).', 'founders-migration-website' ),
				'confirmCancel'         => __( 'Cancel this job?', 'founders-migration-website' ),
				'confirmDelete'         => /* translators: %s: file name. */ __( 'Delete %s? This cannot be undone.', 'founders-migration-website' ),
				'confirmRestore'        => __( 'Restore this backup?', 'founders-migration-website' ),
				'restoreSite'           => __( 'Site to restore', 'founders-migration-website' ),
				'restoreSiteChoose'     => __( 'Choose the site to restore.', 'founders-migration-website' ),
				/* translators: 1: site address, 2: site ID. */
				'restoreIntoDone'       => __( 'The backup is now site %2$d of this network: %1$s. Its users log in with their network accounts.', 'founders-migration-website' ),
				'visitSite'             => __( 'Visit the site', 'founders-migration-website' ),
				'restoreInto'           => __( 'Site of this network it becomes: a new address (a name such as shop, or a full address) or an existing site', 'founders-migration-website' ),
				'restoreIntoOptional'   => __( 'Only for a backup of a single site: the site of this network it becomes (a new address such as shop, or an existing site)', 'founders-migration-website' ),
				'restoreIntoNote'       => __( 'This is a backup of a single site. It becomes a site of this network: a new one at a new address, or it replaces the content of an existing site (not the main site). Its users join the network; people who already have an account here keep their password and profile. Its themes and plugins join the network\'s; its mu-plugins and drop-ins are left out.', 'founders-migration-website' ),
				'restoreSiteNote'       => __( 'This is a backup of a network (or of sites picked from one). Only the chosen site is restored as this site: its content, its media and the users with a role, posts or comments on it (super admins become administrators). The other sites stay in the backup.', 'founders-migration-website' ),
				'restoreSiteOptional'   => __( 'Only for a network backup: the site to restore (its ID or address, for example example.com/shop; not needed when it holds one picked site)', 'founders-migration-website' ),
				'restoreWarning'        => is_multisite()
					? __( 'This replaces the files and database of the whole network, including sites that are not in the backup. The main site and its subsites move to this network\'s address; subsites with their own domain keep it (give them a new one with wp fmw restore --map). The database is switched in one step at the end, so the network stays as it is if the restore fails before that. You will need to log in again with the accounts of the restored network.', 'founders-migration-website' )
					: __( 'This replaces the files and database of this site. The database is switched in one step at the end, so the site stays as it is if the restore fails before that. You will need to log in again with the accounts of the restored site.', 'founders-migration-website' ),
				'source'                => __( 'Site', 'founders-migration-website' ),
				'createdBy'             => __( 'Created by', 'founders-migration-website' ),
				'created'               => __( 'Date', 'founders-migration-website' ),
				'size'                  => __( 'Size', 'founders-migration-website' ),
				'encrypted'             => __( 'Password protected', 'founders-migration-website' ),
				'yes'                   => __( 'yes', 'founders-migration-website' ),
				'no'                    => __( 'no', 'founders-migration-website' ),
				'password'              => __( 'Password of this backup', 'founders-migration-website' ),
				'keepOld'               => __( 'Keep the current database tables as fmwold_* (remove them later with wp fmw cleanup --tables)', 'founders-migration-website' ),
				'noEmailReplace'        => __( 'Do not change e-mail addresses at the old domain', 'founders-migration-website' ),
				'wrongType'             => __( 'Only .fmw and .wpress backups can be imported.', 'founders-migration-website' ),
				'passwordShort'         => __( 'The password must be at least 8 characters long.', 'founders-migration-website' ),
				'passwordMismatch'      => __( 'The passwords do not match.', 'founders-migration-website' ),
				'resuming'              => /* translators: %s: percentage. */ __( 'Continuing the earlier upload of this file at %s.', 'founders-migration-website' ),
				'checking'              => __( 'Checking the uploaded file…', 'founders-migration-website' ),
				'connectionLost'        => /* translators: %d: seconds. */ __( 'Connection problem. Trying again in %d s…', 'founders-migration-website' ),
				'leaveWarning'          => __( 'A backup, upload or restore is running. Leaving pauses it; you can continue later from the Backups page.', 'founders-migration-website' ),
				'reset'                 => __( 'Reset', 'founders-migration-website' ),
				'resetDone'             => __( 'The reset is complete.', 'founders-migration-website' ),
				'resetNothing'          => __( 'Choose what to reset.', 'founders-migration-website' ),
				'resetConfirm'          => __( 'The text does not match the site\'s domain.', 'founders-migration-website' ),
				'safetyBackup'          => __( 'Safety backup before the reset', 'founders-migration-website' ),
				'safetyBackupKept'      => /* translators: %s: file name. */ __( 'Safety backup: %s. Restore it from the Backups page to undo the reset.', 'founders-migration-website' ),
				'runNow'                => __( 'Run now', 'founders-migration-website' ),
				'edit'                  => __( 'Edit', 'founders-migration-website' ),
				'enable'                => __( 'Enable', 'founders-migration-website' ),
				'disable'               => __( 'Disable', 'founders-migration-website' ),
				'delete'                => __( 'Delete', 'founders-migration-website' ),
				'disabled'              => __( 'disabled', 'founders-migration-website' ),
				'keepAll'               => __( 'all', 'founders-migration-website' ),
				'noSchedules'           => __( 'No schedules yet. Add one below.', 'founders-migration-website' ),
				'confirmDeleteSchedule' => /* translators: %s: schedule name. */ __( 'Delete the schedule "%s"? The backups it made are kept.', 'founders-migration-website' ),
				'uploadedTo'            => /* translators: 1: storage name, 2: object key. */ __( 'Uploaded to "%1$s" as %2$s.', 'founders-migration-website' ),
				'deletedLocal'          => __( 'The copy on this server was deleted after the upload, as asked.', 'founders-migration-website' ),
				'downloaded'            => /* translators: %s: file name. */ __( '%s is now in the backups folder of this server.', 'founders-migration-website' ),
				'uploadTitle'           => __( 'Upload to cloud storage', 'founders-migration-website' ),
				'uploadButton'          => __( 'Upload', 'founders-migration-website' ),
				'downloadTitle'         => __( 'Download from cloud storage', 'founders-migration-website' ),
				'downloadToServer'      => __( 'Download to this server', 'founders-migration-website' ),
				'browse'                => __( 'Backups', 'founders-migration-website' ),
				'test'                  => __( 'Test', 'founders-migration-website' ),
				'testing'               => __( 'Checking the connection…', 'founders-migration-website' ),
				'testOk'                => /* translators: %s: what was checked. */ __( 'Connection works: %s.', 'founders-migration-website' ),
				'noStorages'            => __( 'No cloud storage yet. Add one below.', 'founders-migration-website' ),
				'noFiles'               => __( 'No backups in this storage yet.', 'founders-migration-website' ),
				'onServer'              => __( 'also on this server', 'founders-migration-website' ),
				'confirmDeleteStorage'  => /* translators: %s: storage name. */ __( 'Remove "%s" from this site? The backups in it are not deleted.', 'founders-migration-website' ),
				'confirmDeleteRemote'   => /* translators: 1: file name, 2: storage name. */ __( 'Delete %1$s from "%2$s"? This cannot be undone.', 'founders-migration-website' ),
				'secretKept'            => __( 'Saved. Leave empty to keep it.', 'founders-migration-website' ),
				'loading'               => __( 'Loading…', 'founders-migration-website' ),
				'connect'               => __( 'Connect', 'founders-migration-website' ),
				'connecting'            => __( 'Opening Google sign-in…', 'founders-migration-website' ),
				'disconnect'            => __( 'Disconnect', 'founders-migration-website' ),
				'notConnected'          => __( 'not connected', 'founders-migration-website' ),
				'confirmDisconnect'     => /* translators: %s: storage name. */ __( 'Sign "%s" out of Google? The backups in Drive stay; uploads stop until you connect again.', 'founders-migration-website' ),
				'pull'                  => __( 'Pull', 'founders-migration-website' ),
				'pullRestore'           => __( 'Pull and restore', 'founders-migration-website' ),
				'pullChecking'          => __( 'Contacting the source site…', 'founders-migration-website' ),
				'pullCheck'             => __( 'Check', 'founders-migration-website' ),
				'pullNeedBoth'          => __( 'Enter the source site\'s address and its pull key.', 'founders-migration-website' ),
				'pullConfirm'           => /* translators: 1: source address, 2: this site's address. */ __( 'Copy %1$s onto %2$s?', 'founders-migration-website' ),
				'pullDownloadConfirm'   => /* translators: %s: source address. */ __( 'Download a backup of %s into this site\'s backups folder?', 'founders-migration-website' ),
				'pullWarning'           => is_multisite()
					? __( 'This replaces the files and database of the whole network with those of the source network, including sites that the source does not have. The main site and its subsites move to this network\'s address; subsites with their own domain keep it (give them a new one with wp fmw pull --map). The database is switched in one step at the end, so this network stays as it is if the pull fails before that. You will need to log in again with the accounts of the source network.', 'founders-migration-website' )
					: __( 'This replaces the files and database of this site with those of the source. The database is switched in one step at the end, so this site stays as it is if the pull fails before that. You will need to log in again with the accounts of the source site.', 'founders-migration-website' ),
				'pullVersion'           => /* translators: 1: FMW version on the source, 2: FMW version here. */ __( 'The source runs Founders Migration Website %1$s, this site %2$s. Use the same version on both sites if the pull fails.', 'founders-migration-website' ),
				'pullDone'              => /* translators: %s: file name. */ __( '%s was pulled into the backups folder of this site.', 'founders-migration-website' ),
				'pullSite'              => __( 'Source site', 'founders-migration-website' ),
				'pullName'              => __( 'Title', 'founders-migration-website' ),
				'pullVersions'          => __( 'Versions', 'founders-migration-website' ),
				'pullKeyValid'          => __( 'Key valid until', 'founders-migration-website' ),
				'pullNetwork'           => __( 'Network', 'founders-migration-website' ),
				'pullNetworkFacts'      => /* translators: 1: number of sites (2 or more), 2: "subdomains" or "subdirectories". */ __( '%1$d sites, %2$s', 'founders-migration-website' ),
				'pullNetworkFact'       => /* translators: %s: "subdomains" or "subdirectories". */ __( '1 site, %s', 'founders-migration-website' ),
				'subdomains'            => __( 'subdomains', 'founders-migration-website' ),
				'subdirectories'        => __( 'subdirectories', 'founders-migration-website' ),
				'pullNewBackup'         => __( 'A new backup (recommended)', 'founders-migration-website' ),
				'pullKeyCreated'        => __( 'Pull key created', 'founders-migration-website' ),
				'pullKeyOnce'           => is_multisite()
					? __( 'Copy the key now: it is shown only once. On the network that should receive the copy, open Network Admin › Founders Migration › Pull and enter this network\'s address and the key, or run:', 'founders-migration-website' )
					: __( 'Copy the key now: it is shown only once. On the site that should receive the copy, open Founders Migration › Pull and enter this site\'s address and the key, or run:', 'founders-migration-website' ),
				'pullKeyShare'          => __( 'Anyone with the key can copy this site until it expires. Send it over a private channel, and revoke it when the move is done.', 'founders-migration-website' ),
				'copy'                  => __( 'Copy', 'founders-migration-website' ),
				'copied'                => __( 'Copied', 'founders-migration-website' ),
				'revoke'                => __( 'Revoke', 'founders-migration-website' ),
				'confirmRevoke'         => /* translators: %s: key name. */ __( 'Revoke the pull key "%s"? Pulls with it stop working.', 'founders-migration-website' ),
				'noPullKeys'            => __( 'No pull keys. Create one below when another site should copy this one.', 'founders-migration-website' ),
				'expired'               => __( 'expired', 'founders-migration-website' ),
				'anyAddress'            => __( 'any', 'founders-migration-website' ),
				'never'                 => __( 'never', 'founders-migration-website' ),
				'thisSite'              => __( 'Address of this site', 'founders-migration-website' ),
				'allowExisting'         => __( 'existing backups allowed', 'founders-migration-website' ),
				'jobType'               => array(
					'upload'   => __( 'Upload', 'founders-migration-website' ),
					'download' => __( 'Download', 'founders-migration-website' ),
					'pull'     => __( 'Pull', 'founders-migration-website' ),
				),
				'runStatus'             => array(
					'running'   => __( 'Running', 'founders-migration-website' ),
					'completed' => __( 'Completed', 'founders-migration-website' ),
					'failed'    => __( 'Failed', 'founders-migration-website' ),
					'cancelled' => __( 'Cancelled', 'founders-migration-website' ),
				),
				'status'                => array(
					'running'     => __( 'Running', 'founders-migration-website' ),
					'interrupted' => __( 'Interrupted', 'founders-migration-website' ),
					'paused'      => __( 'Paused', 'founders-migration-website' ),
					'pending'     => __( 'Not started', 'founders-migration-website' ),
					'failed'      => __( 'Failed', 'founders-migration-website' ),
				),
			),
		);
	}

	/**
	 * Adds the menu and sub-pages.
	 *
	 * @return void
	 */
	public function menu(): void {
		$capability = fmwp_capability();

		add_menu_page(
			__( 'Founders Migration', 'founders-migration-website' ),
			__( 'Founders Migration', 'founders-migration-website' ),
			$capability,
			self::SLUG_EXPORT,
			array( $this, 'render_export' ),
			'dashicons-migrate',
			76
		);
		add_submenu_page( self::SLUG_EXPORT, __( 'Export', 'founders-migration-website' ), __( 'Export', 'founders-migration-website' ), $capability, self::SLUG_EXPORT, array( $this, 'render_export' ) );
		add_submenu_page( self::SLUG_EXPORT, __( 'Import', 'founders-migration-website' ), __( 'Import', 'founders-migration-website' ), $capability, self::SLUG_IMPORT, array( $this, 'render_import' ) );
		add_submenu_page( self::SLUG_EXPORT, __( 'Backups', 'founders-migration-website' ), __( 'Backups', 'founders-migration-website' ), $capability, self::SLUG_BACKUPS, array( $this, 'render_backups' ) );
		add_submenu_page( self::SLUG_EXPORT, __( 'Schedules', 'founders-migration-website' ), __( 'Schedules', 'founders-migration-website' ), $capability, self::SLUG_SCHEDULES, array( $this, 'render_schedules' ) );
		add_submenu_page( self::SLUG_EXPORT, __( 'Cloud storage', 'founders-migration-website' ), __( 'Cloud storage', 'founders-migration-website' ), $capability, self::SLUG_REMOTE, array( $this, 'render_remote' ) );
		add_submenu_page( self::SLUG_EXPORT, __( 'Pull', 'founders-migration-website' ), __( 'Pull', 'founders-migration-website' ), $capability, self::SLUG_PULL, array( $this, 'render_pull' ) );
		add_submenu_page( self::SLUG_EXPORT, __( 'Reset', 'founders-migration-website' ), __( 'Reset', 'founders-migration-website' ), $capability, self::SLUG_RESET, array( $this, 'render_reset' ) );
	}

	/**
	 * Export page.
	 *
	 * @return void
	 */
	public function render_export(): void {
		$this->render( 'export' );
	}

	/**
	 * Import page.
	 *
	 * @return void
	 */
	public function render_import(): void {
		$this->render( 'import' );
	}

	/**
	 * Backups page.
	 *
	 * @return void
	 */
	public function render_backups(): void {
		$this->render( 'backups', array( 'backups' => Backups::all() ) );
	}

	/**
	 * Backup exclusion flags with their labels (Export and Schedules screens).
	 *
	 * @return array<string,string>
	 */
	public static function exclusion_labels(): array {
		return array(
			'exclude-spam-comments'    => __( 'Do not export spam comments', 'founders-migration-website' ),
			'exclude-post-revisions'   => __( 'Do not export post revisions', 'founders-migration-website' ),
			'exclude-transients'       => __( 'Do not export transients (temporary cached data)', 'founders-migration-website' ),
			'exclude-media'            => __( 'Do not export media library (files)', 'founders-migration-website' ),
			'exclude-themes'           => __( 'Do not export themes (files)', 'founders-migration-website' ),
			'exclude-inactive-themes'  => __( 'Do not export inactive themes (files)', 'founders-migration-website' ),
			'exclude-muplugins'        => __( 'Do not export must-use plugins (files)', 'founders-migration-website' ),
			'exclude-plugins'          => __( 'Do not export plugins (files)', 'founders-migration-website' ),
			'exclude-inactive-plugins' => __( 'Do not export inactive plugins (files)', 'founders-migration-website' ),
			'exclude-cache'            => __( 'Do not export cache (files)', 'founders-migration-website' ),
			'exclude-database'         => __( 'Do not export database (SQL)', 'founders-migration-website' ),
		);
	}

	/**
	 * Schedules page.
	 *
	 * @return void
	 */
	public function render_schedules(): void {
		$this->render( 'schedules', array( 'health' => Scheduler::health() ) );
	}

	/**
	 * Cloud storage page.
	 *
	 * @return void
	 */
	public function render_remote(): void {
		$this->render( 'remote' );
	}

	/**
	 * Pull page.
	 *
	 * @return void
	 */
	public function render_pull(): void {
		$this->render( 'pull', array( 'disabled' => \Founders\Migration\Pull\PullKeys::disabled() ) );
	}

	/**
	 * Cloud storages for the selects on the Export, Backups and Schedules screens.
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function storage_choices(): array {
		$choices = array();
		foreach ( Storages::store()->all() as $storage ) {
			$choices[] = array(
				'id'   => (string) $storage['id'],
				'name' => (string) $storage['name'],
			);
		}
		return $choices;
	}

	/**
	 * Reset page.
	 *
	 * @return void
	 */
	public function render_reset(): void {
		$this->render(
			'reset',
			array(
				'confirm' => ResetOptions::confirm_word(),
				'theme'   => wp_get_theme()->get( 'Name' ),
			)
		);
	}

	/**
	 * Unfinished jobs, for the notice on every page.
	 *
	 * @return int
	 */
	private static function unfinished_jobs(): int {
		$count = 0;
		foreach ( Jobs::store()->all() as $job ) {
			if ( ! $job->is_finished() ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Renders a view inside the shared page frame.
	 *
	 * @param string              $view View name under lib/view/admin/.
	 * @param array<string,mixed> $data Variables for the view.
	 * @return void
	 */
	private function render( string $view, array $data = array() ): void {
		if ( ! current_user_can( fmwp_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'founders-migration-website' ) );
		}

		Paths::ensure_all();

		$fmwp_view            = $view;
		$fmwp_data            = $data;
		$fmwp_exposure        = Paths::exposure_check();
		$fmwp_recommendations = Requirements::recommendations();
		$fmwp_unfinished      = 'backups' === $view ? 0 : self::unfinished_jobs();

		require FMWP_PATH . '/lib/view/admin/layout.php';
	}
}
