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

$fmwp_backups = isset( $fmwp_data['backups'] ) && is_array( $fmwp_data['backups'] ) ? $fmwp_data['backups'] : array();
?>
<div class="fmw-panel">
	<h2><?php esc_html_e( 'Backups', 'founders-migration-website' ); ?></h2>
	<p class="description">
		<?php
		/* translators: %s: backups folder path. */
		printf( esc_html__( 'Stored in %s', 'founders-migration-website' ), '<code>' . esc_html( fmwp_backups_path() ) . '</code>' );
		?>
	</p>

	<?php if ( ! $fmwp_backups ) : ?>
		<p><?php esc_html_e( 'No backups yet.', 'founders-migration-website' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Date', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Size', 'founders-migration-website' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fmwp_backups as $fmwp_backup ) : ?>
					<tr>
						<td><code><?php echo esc_html( $fmwp_backup['name'] ); ?></code></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $fmwp_backup['mtime'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $fmwp_backup['size'], 1 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
