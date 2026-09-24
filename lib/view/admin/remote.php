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

$fmwp_providers = Founders\Migration\Remote\StorageOptions::providers();
?>
<div class="fmw-panel" id="fmw-remote">
	<h2><?php esc_html_e( 'Cloud storage', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'Keep copies of your backups off this server: Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO or any S3-compatible storage. Uploads and downloads of any size run in short steps and continue where they stopped.', 'founders-migration-website' ); ?></p>

	<?php if ( ! function_exists( 'curl_init' ) ) : ?>
		<div class="notice inline notice-error"><p><?php esc_html_e( 'PHP\'s curl extension is not installed on this server; cloud storage needs it. Ask your host to enable it.', 'founders-migration-website' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped fmw-storages">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Service', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Bucket / folder', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'founders-migration-website' ); ?></th>
			</tr>
		</thead>
		<tbody data-fmw-storage-rows>
			<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'founders-migration-website' ); ?></td></tr>
		</tbody>
	</table>
	<p class="fmw-test-result" role="status" data-fmw-storage-status></p>
</div>

<div class="fmw-panel" id="fmw-remote-files" hidden>
	<h2 data-fmw-files-title></h2>
	<table class="widefat striped fmw-remote-files">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Date', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Size', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'founders-migration-website' ); ?></th>
			</tr>
		</thead>
		<tbody data-fmw-file-rows></tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Download a backup to this server, then restore it from the Backups page (or right away from the download dialog).', 'founders-migration-website' ); ?></p>
</div>

<div class="fmw-panel" id="fmw-storage-form">
	<h2 data-fmw-storage-title data-add="<?php esc_attr_e( 'Add cloud storage', 'founders-migration-website' ); ?>" data-edit="<?php esc_attr_e( 'Edit cloud storage', 'founders-migration-website' ); ?>"><?php esc_html_e( 'Add cloud storage', 'founders-migration-website' ); ?></h2>
	<form data-fmw-storage-form autocomplete="off">
		<input type="hidden" name="id" value="" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fmw-storage-provider"><?php esc_html_e( 'Service', 'founders-migration-website' ); ?></label></th>
				<td>
					<select id="fmw-storage-provider" name="provider">
						<?php foreach ( $fmwp_providers as $fmwp_key => $fmwp_provider ) : ?>
							<option value="<?php echo esc_attr( $fmwp_key ); ?>" data-endpoint="<?php echo esc_attr( $fmwp_provider['endpoint'] ); ?>" data-region="<?php echo esc_attr( $fmwp_provider['region'] ); ?>" data-path-style="<?php echo $fmwp_provider['path_style'] ? '1' : '0'; ?>"><?php echo esc_html( $fmwp_provider['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-region"><?php esc_html_e( 'Region', 'founders-migration-website' ); ?></label></th>
				<td>
					<input type="text" id="fmw-storage-region" name="region" class="regular-text" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'For example ap-southeast-3 (Amazon S3 Jakarta), ap-southeast-1 (Singapore), auto (Cloudflare R2), sgp1 (DigitalOcean).', 'founders-migration-website' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-endpoint"><?php esc_html_e( 'Endpoint', 'founders-migration-website' ); ?></label></th>
				<td>
					<input type="url" id="fmw-storage-endpoint" name="endpoint" class="large-text" spellcheck="false" />
					<p class="description" data-fmw-endpoint-hint></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-bucket"><?php esc_html_e( 'Bucket', 'founders-migration-website' ); ?></label></th>
				<td><input type="text" id="fmw-storage-bucket" name="bucket" class="regular-text" spellcheck="false" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-prefix"><?php esc_html_e( 'Folder', 'founders-migration-website' ); ?></label></th>
				<td>
					<input type="text" id="fmw-storage-prefix" name="prefix" class="regular-text" spellcheck="false" placeholder="<?php echo esc_attr( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional folder inside the bucket, useful when several sites share one bucket.', 'founders-migration-website' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-access"><?php esc_html_e( 'Access key', 'founders-migration-website' ); ?></label></th>
				<td><input type="text" id="fmw-storage-access" name="access_key" class="regular-text" spellcheck="false" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-secret"><?php esc_html_e( 'Secret key', 'founders-migration-website' ); ?></label></th>
				<td>
					<input type="password" id="fmw-storage-secret" name="secret_key" class="regular-text" autocomplete="new-password" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'Stored encrypted with this site\'s keys, outside the database. Use a key limited to this bucket (list, read, write, delete).', 'founders-migration-website' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-storage-name"><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></label></th>
				<td><input type="text" id="fmw-storage-name" name="name" class="regular-text" maxlength="100" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Advanced', 'founders-migration-website' ); ?></th>
				<td>
					<label class="fmw-option"><input type="checkbox" name="path_style" /> <?php esc_html_e( 'Path-style addressing (endpoint/bucket/…); needed for MinIO and most other services', 'founders-migration-website' ); ?></label>
					<p data-fmw-storage-class>
						<label><?php esc_html_e( 'Storage class', 'founders-migration-website' ); ?>
							<select name="storage_class">
								<option value=""><?php esc_html_e( 'Default (Standard)', 'founders-migration-website' ); ?></option>
								<?php foreach ( Founders\Migration\Remote\StorageOptions::STORAGE_CLASSES as $fmwp_class ) : ?>
									<option value="<?php echo esc_attr( $fmwp_class ); ?>"><?php echo esc_html( $fmwp_class ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
				</td>
			</tr>
		</table>
		<p class="fmw-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Test and save', 'founders-migration-website' ); ?></button>
			<button type="button" class="button" data-fmw-storage-cancel hidden><?php esc_html_e( 'Cancel', 'founders-migration-website' ); ?></button>
		</p>
		<p class="fmw-error" role="alert" data-fmw-storage-error></p>
	</form>
	<p class="description"><?php esc_html_e( 'Same as: wp fmw storage add --provider=aws --region=ap-southeast-3 --bucket=… --access-key=… --secret-key=…', 'founders-migration-website' ); ?></p>
</div>
