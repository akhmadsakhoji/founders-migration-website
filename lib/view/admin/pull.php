<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @var array<string,mixed> $fmwp_data disabled (pulls switched off with FMWP_DISABLE_PULL).
 */

defined( 'ABSPATH' ) || exit;

$fmwp_options = Founders\Migration\Controller\AdminController::exclusion_labels();
$fmwp_ttls    = array(
	3600    => __( '1 hour', 'founders-migration-website' ),
	21600   => __( '6 hours', 'founders-migration-website' ),
	86400   => __( '24 hours', 'founders-migration-website' ),
	259200  => __( '3 days', 'founders-migration-website' ),
	604800  => __( '7 days', 'founders-migration-website' ),
	2592000 => __( '30 days', 'founders-migration-website' ),
);
?>
<div class="fmw-panel" id="fmw-pull">
	<h2><?php esc_html_e( 'Copy another site onto this one', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'The other site makes a backup, this site downloads it and restores it: no downloading and uploading by hand. Create a pull key on the other site first (on its Pull page, the section below). Large sites are fine: the pull runs in short steps and continues where it stopped.', 'founders-migration-website' ); ?></p>

	<?php if ( is_multisite() ) : ?>
		<p class="description"><?php esc_html_e( 'This is a network: the source must be a network of the same kind (subdomains or subdirectories), and it replaces this whole network. Give the source network\'s main address.', 'founders-migration-website' ); ?></p>
	<?php endif; ?>
	<form data-fmw-pull-form autocomplete="off">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fmw-pull-url"><?php esc_html_e( 'Source site address', 'founders-migration-website' ); ?></label></th>
				<td><input type="text" inputmode="url" id="fmw-pull-url" name="url" class="regular-text code" placeholder="https://old.example.com" spellcheck="false" autocapitalize="off" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-pull-key"><?php esc_html_e( 'Pull key', 'founders-migration-website' ); ?></label></th>
				<td>
					<input type="password" id="fmw-pull-key" name="key" class="large-text code" autocomplete="one-time-code" spellcheck="false" placeholder="fmwpk_…" />
					<p class="description"><?php esc_html_e( 'From the source site\'s Pull page (or wp fmw pull-key create there). It is kept encrypted while the pull runs and removed afterwards.', 'founders-migration-website' ); ?></p>
				</td>
			</tr>
		</table>

		<details class="fmw-advanced">
			<summary><?php esc_html_e( 'Options', 'founders-migration-website' ); ?> <span class="description"><?php esc_html_e( '(click to expand)', 'founders-migration-website' ); ?></span></summary>
			<fieldset data-fmw-pull-exclusions>
				<?php foreach ( $fmwp_options as $fmwp_flag => $fmwp_label ) : ?>
					<label class="fmw-option">
						<input type="checkbox" name="<?php echo esc_attr( $fmwp_flag ); ?>" value="1" data-fmw-pull-flag />
						<?php echo esc_html( $fmwp_label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<fieldset>
				<label class="fmw-option"><input type="checkbox" name="download_only" value="1" /> <?php esc_html_e( 'Only download the backup into the backups folder (restore it later from the Backups page)', 'founders-migration-website' ); ?></label>
				<label class="fmw-option"><input type="checkbox" name="keep_source" value="1" /> <?php esc_html_e( 'Keep the backup on the source site afterwards', 'founders-migration-website' ); ?></label>
				<label class="fmw-option"><input type="checkbox" name="keep-old-tables" value="1" data-fmw-pull-flag /> <?php esc_html_e( 'Keep the current database tables as fmwold_*', 'founders-migration-website' ); ?></label>
				<label class="fmw-option"><input type="checkbox" name="exclude-email-replace" value="1" data-fmw-pull-flag /> <?php esc_html_e( 'Do not change e-mail addresses at the old domain', 'founders-migration-website' ); ?></label>
				<label class="fmw-option"><input type="checkbox" name="allow_http" value="1" /> <?php esc_html_e( 'Allow a plain http:// address (only on a trusted network: the key and the backup would travel unencrypted)', 'founders-migration-website' ); ?></label>
			</fieldset>
			<fieldset>
				<p>
					<label><?php esc_html_e( 'Password (optional, at least 8 characters)', 'founders-migration-website' ); ?><br />
						<input type="password" name="password" class="regular-text" autocomplete="new-password" /></label>
				</p>
				<p class="description"><?php esc_html_e( 'Encrypts the backup while it waits on the source site. For an existing encrypted backup, its password.', 'founders-migration-website' ); ?></p>
			</fieldset>
		</details>

		<div data-fmw-pull-source hidden></div>
		<p class="fmw-error" role="alert" data-fmw-pull-error></p>
		<p class="fmw-actions">
			<button type="button" class="button button-hero" data-fmw-pull-check><?php esc_html_e( 'Check', 'founders-migration-website' ); ?></button>
			<button type="submit" class="button button-primary button-hero" data-fmw-pull-submit><?php esc_html_e( 'Pull and restore', 'founders-migration-website' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'Same as: wp fmw pull <address> --key=<key>', 'founders-migration-website' ); ?></p>
	</form>
</div>

<div class="fmw-panel" id="fmw-pull-keys">
	<h2><?php esc_html_e( 'Let another site copy this one', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'A pull key lets another site with Founders Migration Website make a backup of this site and download it, and nothing else: no login, no other access. It expires, can be limited to the other server\'s address, and can be revoked at any time. Only a fingerprint of it is stored here.', 'founders-migration-website' ); ?></p>

	<?php if ( ! empty( $fmwp_data['disabled'] ) ) : ?>
		<div class="notice inline notice-info"><p><?php esc_html_e( 'Pulls from this site are switched off (FMWP_DISABLE_PULL in wp-config.php).', 'founders-migration-website' ); ?></p></div>
	<?php else : ?>
		<?php if ( is_multisite() ) : ?>
			<p class="description"><?php esc_html_e( 'On a network, a pull key lets another network copy this whole network, with all its sites.', 'founders-migration-website' ); ?></p>
		<?php endif; ?>
		<table class="widefat striped fmw-keys">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Valid until', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Addresses', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'founders-migration-website' ); ?></th>
					<th><?php esc_html_e( 'Sent', 'founders-migration-website' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody data-fmw-key-rows></tbody>
		</table>

		<form data-fmw-key-form autocomplete="off">
			<h3><?php esc_html_e( 'Create a pull key', 'founders-migration-website' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fmw-key-name"><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></label></th>
					<td><input type="text" id="fmw-key-name" name="name" class="regular-text" maxlength="100" placeholder="<?php esc_attr_e( 'for example: new server', 'founders-migration-website' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="fmw-key-expires"><?php esc_html_e( 'Valid for', 'founders-migration-website' ); ?></label></th>
					<td>
						<select id="fmw-key-expires" name="expires">
							<?php foreach ( $fmwp_ttls as $fmwp_seconds => $fmwp_label ) : ?>
								<option value="<?php echo esc_attr( (string) $fmwp_seconds ); ?>"<?php selected( 86400, $fmwp_seconds ); ?>><?php echo esc_html( $fmwp_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Long enough for the whole copy: very large sites on slow connections may need a few days.', 'founders-migration-website' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fmw-key-ips"><?php esc_html_e( 'Only from these addresses', 'founders-migration-website' ); ?></label></th>
					<td>
						<input type="text" id="fmw-key-ips" name="ips" class="regular-text code" spellcheck="false" placeholder="203.0.113.7, 2001:db8::/32" />
						<p class="description"><?php esc_html_e( 'Optional: the other server\'s outgoing IP addresses or ranges. Behind Cloudflare or another proxy this site sees the proxy\'s address; leave it empty there.', 'founders-migration-website' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Existing backups', 'founders-migration-website' ); ?></th>
					<td><label class="fmw-option"><input type="checkbox" name="allow_existing" value="1" /> <?php esc_html_e( 'Also allow downloading the .fmw backups already on this site', 'founders-migration-website' ); ?></label></td>
				</tr>
			</table>
			<p class="fmw-error" role="alert" data-fmw-key-error></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create pull key', 'founders-migration-website' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Same as: wp fmw pull-key create', 'founders-migration-website' ); ?></p>
		</form>
	<?php endif; ?>
</div>
