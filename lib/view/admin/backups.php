<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @var array<string,mixed> $fmwp_data View data with a 'backups' list.
 */

defined( 'ABSPATH' ) || exit;

use Founders\Migration\Controller\DownloadController;

$fmwp_backups = isset( $fmwp_data['backups'] ) && is_array( $fmwp_data['backups'] ) ? $fmwp_data['backups'] : array();
?>
<div class="fmw-panel" id="fmw-jobs" data-fmw-jobs hidden>
	<h2><?php esc_html_e( 'Unfinished jobs', 'founders-migration-website' ); ?></h2>
	<p class="description"><?php esc_html_e( 'These stopped before the end (closed page, timeout or error). Continue them where they stopped, or cancel them.', 'founders-migration-website' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Job', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Status', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Progress', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'founders-migration-website' ); ?></th>
			</tr>
		</thead>
		<tbody data-fmw-jobs-body></tbody>
	</table>
</div>

<div class="fmw-panel" id="fmw-backups">
	<h2><?php esc_html_e( 'Backups', 'founders-migration-website' ); ?></h2>
	<p class="description">
		<?php
		/* translators: %s: backups folder path. */
		printf( esc_html__( 'Stored in %s', 'founders-migration-website' ), '<code>' . esc_html( fmwp_backups_path() ) . '</code>' );
		?>
	</p>

	<?php $fmwp_storages = Founders\Migration\Controller\AdminController::storage_choices(); ?>
	<?php if ( ! $fmwp_backups ) : ?>
		<p data-fmw-empty><?php esc_html_e( 'No backups yet. Create one on the Export page, or upload one on the Import page.', 'founders-migration-website' ); ?></p>
	<?php else : ?>
		<table class="widefat striped fmw-backups">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Date', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Size', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'founders-migration-website' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fmwp_backups as $fmwp_backup ) : ?>
					<tr data-fmw-backup="<?php echo esc_attr( $fmwp_backup['name'] ); ?>">
						<td>
							<code><?php echo esc_html( $fmwp_backup['name'] ); ?></code>
							<?php if ( 'ai1wm' === ( $fmwp_backup['source'] ?? 'fmw' ) ) : ?>
								<span class="fmw-badge"><?php esc_html_e( 'All-in-One WP Migration', 'founders-migration-website' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $fmwp_backup['mtime'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $fmwp_backup['size'], 1 ) ); ?></td>
						<td class="fmw-row-actions">
							<a class="button" href="<?php echo esc_url( DownloadController::url( $fmwp_backup['name'] ) ); ?>"><?php esc_html_e( 'Download', 'founders-migration-website' ); ?></a>
							<button type="button" class="button" data-fmw-action="restore"><?php esc_html_e( 'Restore', 'founders-migration-website' ); ?></button>
							<?php if ( $fmwp_storages ) : ?>
								<button type="button" class="button" data-fmw-action="upload"><?php esc_html_e( 'Upload', 'founders-migration-website' ); ?></button>
							<?php endif; ?>
							<?php if ( 'fmw' === ( $fmwp_backup['source'] ?? 'fmw' ) ) : ?>
								<button type="button" class="button button-link-delete" data-fmw-action="delete"><?php esc_html_e( 'Delete', 'founders-migration-website' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
