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

use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\ByteRange;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Multi-GB files are streamed with native file calls.

/**
 * Streams a backup to the browser: admin-post.php?action=fmwp_download&name=…&_wpnonce=….
 *
 * The backups folder is not reachable from the web (it must not be), so
 * downloads go through PHP. Byte ranges are supported, so browsers and
 * download managers can resume a multi-GB download.
 */
final class DownloadController {

	const ACTION = 'fmwp_download';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	/**
	 * Download URL for a backup (for the current user).
	 *
	 * @param string $name Backup name.
	 * @return string
	 */
	public static function url( string $name ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'name'     => rawurlencode( $name ), // add_query_arg() does not encode; PHP decodes it once.
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Handles the request.
	 *
	 * @return void
	 */
	public function download(): void {
		if ( ! current_user_can( fmwp_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to download backups.', 'founders-migration-website' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION ); // Stops the request when the link is not valid.
		$name   = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$backup = Backups::get( $name );
		if ( null === $backup ) {
			wp_die( esc_html__( 'Backup not found.', 'founders-migration-website' ), '', array( 'response' => 404 ) );
		}

		$size  = $backup['size'];
		$range = ByteRange::parse( isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '', $size );
		if ( false === $range ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		list( $start, $end ) = null === $range ? array( 0, $size - 1 ) : $range;

		$handle = fopen( $backup['path'], 'rb' );
		if ( false === $handle || ( $start > 0 && 0 !== fseek( $handle, $start ) ) ) {
			wp_die( esc_html__( 'The backup could not be read.', 'founders-migration-website' ), '', array( 'response' => 500 ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		ignore_user_abort( false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts.
		}
		nocache_headers();
		status_header( null === $range ? 200 : 206 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '_', $backup['name'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $backup['name'] ) );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( null !== $range ) {
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}

		$left = $end - $start + 1;
		while ( $left > 0 && ! connection_aborted() ) {
			$data = fread( $handle, (int) min( 1048576, $left ) );
			if ( false === $data || '' === $data ) {
				break;
			}
			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download.
			flush();
			$left -= strlen( $data );
		}
		fclose( $handle );
		exit;
	}
}
