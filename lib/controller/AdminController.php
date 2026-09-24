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

use Founders\Migration\Requirements;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;

/**
 * Admin menu and pages: Export, Import, Backups (same flow as All-in-One WP Migration).
 */
final class AdminController {

	const SLUG_EXPORT  = 'fmw-export';
	const SLUG_IMPORT  = 'fmw-import';
	const SLUG_BACKUPS = 'fmw-backups';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'menu' ) );
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

		require FMWP_PATH . '/lib/view/admin/layout.php';
	}
}
