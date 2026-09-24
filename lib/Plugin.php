<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

use Founders\Migration\Cli\Command;
use Founders\Migration\Controller\AdminController;
use Founders\Migration\Storage\Paths;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Registers hooks. Called once from the main plugin file.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		$errors = Requirements::errors();
		if ( $errors ) {
			$notice = static function () use ( $errors ) {
				if ( ! current_user_can( fmwp_capability() ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Founders Migration Website is inactive:', 'founders-migration-website' ) . '</strong></p><ul>';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			};
			add_action( 'admin_notices', $notice );
			add_action( 'network_admin_notices', $notice );
			return;
		}

		( new AdminController() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fmw', Command::class );
		}
	}

	/**
	 * Activation: refuse on unsupported servers, create protected data folders.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$errors = Requirements::errors();
		if ( $errors ) {
			deactivate_plugins( FMWP_BASENAME );
			wp_die(
				'<p>' . esc_html__( 'Founders Migration Website cannot be activated on this server:', 'founders-migration-website' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errors ) ) . '</li></ul>',
				esc_html__( 'Plugin activation failed', 'founders-migration-website' ),
				array( 'back_link' => true )
			);
		}

		Paths::ensure_all();
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'founders-migration-website', false, dirname( FMWP_BASENAME ) . '/languages' );
	}
}
