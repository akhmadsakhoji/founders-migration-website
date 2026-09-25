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

defined( 'ABSPATH' ) || exit;

use Founders\Migration\Remote\GoogleAuth;
use Founders\Migration\Remote\RemoteException;

/**
 * Where Google sends the browser back after the consent page (admin-post.php?action=fmwp_gdrive_callback).
 */
final class GoogleController {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . GoogleAuth::ACTION, array( $this, 'callback' ) );
	}

	/**
	 * Finishes the sign-in, then returns to the Cloud storage page with a message.
	 *
	 * The request is authenticated by the WordPress login (admin-post.php)
	 * and by the single-use state created for this user when Connect was
	 * clicked; Google's code is only good together with that state's PKCE verifier.
	 *
	 * @return void
	 */
	public function callback(): void {
		if ( ! current_user_can( fmwp_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to connect cloud storage.', 'founders-migration-website' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The OAuth state (single use, bound to this user) is the CSRF protection.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		$args = array( 'page' => AdminController::SLUG_REMOTE );
		if ( '' !== $error || '' === $code ) {
			$args['fmw_gdrive'] = 'denied';
			if ( '' !== $state && 1 === preg_match( '/^[a-f0-9]{32}$/', $state ) ) {
				delete_transient( 'fmwp_gdrive_' . $state );
			}
		} else {
			try {
				$storage             = GoogleAuth::finish( $state, $code, get_current_user_id() );
				$args['fmw_gdrive']  = 'connected';
				$args['fmw_account'] = rawurlencode( (string) ( $storage['account'] ?? '' ) );
			} catch ( RemoteException $e ) {
				set_transient( 'fmwp_gdrive_error_' . get_current_user_id(), $e->getMessage(), 300 );
				$args['fmw_gdrive'] = 'error';
			}
		}
		wp_safe_redirect( add_query_arg( $args, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) );
		exit;
	}
}
