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
use Founders\Migration\Requirements;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;

/**
 * Admin menu and pages: Export, Import, Backups, Reset (same flow as All-in-One WP Migration).
 */
final class AdminController {

	const SLUG_EXPORT  = 'fmw-export';
	const SLUG_IMPORT  = 'fmw-import';
	const SLUG_BACKUPS = 'fmw-backups';
	const SLUG_RESET   = 'fmw-reset';

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
		if ( ! in_array( $page, array( self::SLUG_EXPORT, self::SLUG_IMPORT, self::SLUG_BACKUPS, self::SLUG_RESET ), true ) ) {
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
			'loginUrl'      => wp_login_url( add_query_arg( 'page', self::SLUG_BACKUPS, $base ) ),
			'backupsUrl'    => add_query_arg( 'page', self::SLUG_BACKUPS, $base ),
			'i18n'          => array(
				'export'           => __( 'Export', 'founders-migration-website' ),
				'restore'          => __( 'Restore', 'founders-migration-website' ),
				'backup'           => __( 'Backup', 'founders-migration-website' ),
				'upload'           => __( 'Uploading', 'founders-migration-website' ),
				'preparing'        => __( 'Preparing…', 'founders-migration-website' ),
				'step'             => /* translators: 1: step number, 2: number of steps, 3: step name. */ __( 'Step %1$d of %2$d: %3$s', 'founders-migration-website' ),
				'eta'              => /* translators: %s: remaining time. */ __( 'about %s left', 'founders-migration-website' ),
				'cancel'           => __( 'Cancel', 'founders-migration-website' ),
				'close'            => __( 'Close', 'founders-migration-website' ),
				'download'         => __( 'Download', 'founders-migration-website' ),
				'retry'            => __( 'Try again', 'founders-migration-website' ),
				'logIn'            => __( 'Log in', 'founders-migration-website' ),
				'continue'         => __( 'Continue', 'founders-migration-website' ),
				'backupDone'       => /* translators: 1: file name, 2: size. */ __( 'Backup %1$s (%2$s) is ready.', 'founders-migration-website' ),
				'restoreDone'      => __( 'The site has been restored. Log in again with the accounts of the restored site.', 'founders-migration-website' ),
				'cancelled'        => __( 'The job was cancelled. Nothing more will be changed.', 'founders-migration-website' ),
				'failed'           => __( 'The job stopped with an error:', 'founders-migration-website' ),
				'failedHint'       => __( 'Fix the cause, then continue the job from the Backups page (or wp fmw resume).', 'founders-migration-website' ),
				'confirmCancel'    => __( 'Cancel this job?', 'founders-migration-website' ),
				'confirmDelete'    => /* translators: %s: file name. */ __( 'Delete %s? This cannot be undone.', 'founders-migration-website' ),
				'confirmRestore'   => __( 'Restore this backup?', 'founders-migration-website' ),
				'restoreWarning'   => __( 'This replaces the files and database of this site. The database is switched in one step at the end, so the site stays as it is if the restore fails before that. You will need to log in again with the accounts of the restored site.', 'founders-migration-website' ),
				'source'           => __( 'Site', 'founders-migration-website' ),
				'createdBy'        => __( 'Created by', 'founders-migration-website' ),
				'created'          => __( 'Date', 'founders-migration-website' ),
				'size'             => __( 'Size', 'founders-migration-website' ),
				'encrypted'        => __( 'Password protected', 'founders-migration-website' ),
				'yes'              => __( 'yes', 'founders-migration-website' ),
				'no'               => __( 'no', 'founders-migration-website' ),
				'password'         => __( 'Password of this backup', 'founders-migration-website' ),
				'keepOld'          => __( 'Keep the current database tables as fmwold_* (remove them later with wp fmw cleanup --tables)', 'founders-migration-website' ),
				'noEmailReplace'   => __( 'Do not change e-mail addresses at the old domain', 'founders-migration-website' ),
				'wrongType'        => __( 'Only .fmw and .wpress backups can be imported.', 'founders-migration-website' ),
				'passwordShort'    => __( 'The password must be at least 8 characters long.', 'founders-migration-website' ),
				'passwordMismatch' => __( 'The passwords do not match.', 'founders-migration-website' ),
				'resuming'         => /* translators: %s: percentage. */ __( 'Continuing the earlier upload of this file at %s.', 'founders-migration-website' ),
				'checking'         => __( 'Checking the uploaded file…', 'founders-migration-website' ),
				'connectionLost'   => /* translators: %d: seconds. */ __( 'Connection problem. Trying again in %d s…', 'founders-migration-website' ),
				'leaveWarning'     => __( 'A backup, upload or restore is running. Leaving pauses it; you can continue later from the Backups page.', 'founders-migration-website' ),
				'reset'            => __( 'Reset', 'founders-migration-website' ),
				'resetDone'        => __( 'The reset is complete.', 'founders-migration-website' ),
				'resetNothing'     => __( 'Choose what to reset.', 'founders-migration-website' ),
				'resetConfirm'     => __( 'The text does not match the site\'s domain.', 'founders-migration-website' ),
				'safetyBackup'     => __( 'Safety backup before the reset', 'founders-migration-website' ),
				'safetyBackupKept' => /* translators: %s: file name. */ __( 'Safety backup: %s. Restore it from the Backups page to undo the reset.', 'founders-migration-website' ),
				'status'           => array(
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
