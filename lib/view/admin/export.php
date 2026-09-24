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

$fmwp_options = Founders\Migration\Controller\AdminController::exclusion_labels();
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

	<fieldset class="fmw-encrypt">
		<label class="fmw-option">
			<input type="checkbox" data-fmw-encrypt />
			<?php esc_html_e( 'Protect this backup with a password', 'founders-migration-website' ); ?>
		</label>
		<div class="fmw-encrypt-fields" data-fmw-encrypt-fields hidden>
			<p>
				<label><?php esc_html_e( 'Password (at least 8 characters)', 'founders-migration-website' ); ?><br />
					<input type="password" class="regular-text" autocomplete="new-password" data-fmw-password /></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Repeat the password', 'founders-migration-website' ); ?><br />
					<input type="password" class="regular-text" autocomplete="new-password" data-fmw-password-repeat /></label>
			</p>
			<p class="description"><?php esc_html_e( 'Files, database and the list of what the backup contains are encrypted with AES-256. Keep the password safe: without it the backup cannot be restored, not even by us.', 'founders-migration-website' ); ?></p>
		</div>
	</fieldset>

	<p class="fmw-actions">
		<button type="button" class="button button-primary button-hero" data-fmw-action="export"><?php esc_html_e( 'Export to file', 'founders-migration-website' ); ?></button>
	</p>
	<p class="description"><?php esc_html_e( 'Same as: wp fmw backup', 'founders-migration-website' ); ?></p>
</div>
