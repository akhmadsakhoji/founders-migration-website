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

$fmwp_options = array(
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
?>
<div class="fmw-panel" id="fmw-export">
	<h2><?php esc_html_e( 'Export site', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'Creates a .fmw backup of this site\'s files and database in the backups folder. Large sites are fine: the export runs in short steps and continues where it stopped if the page is closed.', 'founders-migration-website' ); ?></p>

	<details class="fmw-advanced">
		<summary><?php esc_html_e( 'Advanced options', 'founders-migration-website' ); ?> <span class="description"><?php esc_html_e( '(click to expand)', 'founders-migration-website' ); ?></span></summary>
		<fieldset>
			<?php foreach ( $fmwp_options as $fmwp_flag => $fmwp_label ) : ?>
				<label class="fmw-option">
					<input type="checkbox" name="<?php echo esc_attr( $fmwp_flag ); ?>" value="1" />
					<?php echo esc_html( $fmwp_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
	</details>

	<p class="fmw-actions">
		<button type="button" class="button button-primary button-hero" data-fmw-action="export"><?php esc_html_e( 'Export to file', 'founders-migration-website' ); ?></button>
	</p>
	<p class="description"><?php esc_html_e( 'Same as: wp fmw backup', 'founders-migration-website' ); ?></p>
</div>
