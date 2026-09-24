<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="fmw-panel" id="fmw-import">
	<h2><?php esc_html_e( 'Import site', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'Restore a .fmw backup, or a .wpress backup from All-in-One WP Migration. The file is uploaded in chunks with no size limit; if the connection drops, choose the same file again and the upload continues where it stopped.', 'founders-migration-website' ); ?></p>

	<div class="fmw-dropzone" data-fmw-dropzone tabindex="0">
		<span class="dashicons dashicons-upload" aria-hidden="true"></span>
		<p class="fmw-dropzone-title"><?php esc_html_e( 'Drag & drop a backup here', 'founders-migration-website' ); ?></p>
		<p><?php esc_html_e( 'or', 'founders-migration-website' ); ?></p>
		<label class="button button-primary button-hero">
			<?php esc_html_e( 'Import from file', 'founders-migration-website' ); ?>
			<input type="file" accept=".fmw,.wpress" data-fmw-file hidden />
		</label>
		<p class="description"><?php esc_html_e( 'Accepted: .fmw and .wpress', 'founders-migration-website' ); ?></p>
	</div>

	<p class="description">
		<?php
		printf(
			/* translators: %s: link to the Backups page. */
			esc_html__( 'Backups already on the server (including All-in-One WP Migration\'s ai1wm-backups folder) can be restored from the %s page.', 'founders-migration-website' ),
			'<a href="' . esc_url( add_query_arg( 'page', Founders\Migration\Controller\AdminController::SLUG_BACKUPS, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Backups', 'founders-migration-website' ) . '</a>'
		);
		?>
	</p>
</div>
