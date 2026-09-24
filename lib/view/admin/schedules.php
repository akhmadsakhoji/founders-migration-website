<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @var array<string,mixed> $fmwp_data health (last_tick, source, wp_cron_disabled, late).
 */

defined( 'ABSPATH' ) || exit;

global $wp_locale;
$fmwp_health = (array) $fmwp_data['health'];
$fmwp_cron   = '0-59/5 * * * * wp fmw schedule run --path=' . untrailingslashit( ABSPATH ) . ' --quiet';
?>
<div class="fmw-panel" id="fmw-schedules">
	<h2><?php esc_html_e( 'Scheduled backups', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'Backs up this site automatically and keeps only the newest backups of each schedule. Scheduled backups run in the background in short steps, like an export, and continue by themselves if they are interrupted.', 'founders-migration-website' ); ?></p>

	<?php if ( '' !== (string) ( $fmwp_health['waiting_for'] ?? '' ) ) : ?>
		<div class="notice inline notice-warning">
			<p><?php esc_html_e( 'Scheduled backups are waiting: a restore or reset stopped before the end. Continue or cancel it on the Backups page; a backup of a half-restored site could otherwise replace your good backups.', 'founders-migration-website' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $fmwp_health['late'] ) ) : ?>
		<div class="notice inline notice-warning">
			<p><strong><?php esc_html_e( 'The scheduler has not run for over 30 minutes.', 'founders-migration-website' ); ?></strong>
			<?php
			echo esc_html(
				! empty( $fmwp_health['wp_cron_disabled'] )
					? __( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Add this line to the server\'s crontab:', 'founders-migration-website' )
					: __( 'WP-Cron only runs when the site has visitors. For reliable schedules, add this line to the server\'s crontab:', 'founders-migration-website' )
			);
			?>
			</p>
			<pre class="fmw-code"><?php echo esc_html( $fmwp_cron ); ?></pre>
		</div>
	<?php endif; ?>

	<table class="widefat striped fmw-schedules">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'When', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Next run', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Last run', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Keep', 'founders-migration-website' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'founders-migration-website' ); ?></th>
			</tr>
		</thead>
		<tbody data-fmw-schedule-rows>
			<tr><td colspan="6"><?php esc_html_e( 'Loading…', 'founders-migration-website' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description" data-fmw-schedule-health>
		<?php
		printf(
			/* translators: 1: time zone, 2: time of the last check, 3: WP-Cron or a system cron. */
			esc_html__( 'Times are in the site time zone (%1$s). Last scheduler check: %2$s %3$s', 'founders-migration-website' ),
			esc_html( wp_timezone_string() ),
			esc_html( $fmwp_health['last_tick'] ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $fmwp_health['last_tick'] ) : __( 'never', 'founders-migration-website' ) ),
			esc_html( '' !== (string) $fmwp_health['source'] ? '(' . $fmwp_health['source'] . ')' : '' )
		);
		?>
	</p>
</div>

<div class="fmw-panel" id="fmw-schedule-form">
	<h2 data-fmw-schedule-title data-add="<?php esc_attr_e( 'Add a schedule', 'founders-migration-website' ); ?>" data-edit="<?php esc_attr_e( 'Edit schedule', 'founders-migration-website' ); ?>"><?php esc_html_e( 'Add a schedule', 'founders-migration-website' ); ?></h2>
	<form data-fmw-schedule-form autocomplete="off">
		<input type="hidden" name="id" value="" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fmw-schedule-name"><?php esc_html_e( 'Name', 'founders-migration-website' ); ?></label></th>
				<td><input type="text" id="fmw-schedule-name" name="name" class="regular-text" maxlength="100" placeholder="<?php esc_attr_e( 'Daily backup', 'founders-migration-website' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-schedule-frequency"><?php esc_html_e( 'Frequency', 'founders-migration-website' ); ?></label></th>
				<td class="fmw-inline">
					<select id="fmw-schedule-frequency" name="frequency">
						<option value="hourly"><?php esc_html_e( 'Every hour', 'founders-migration-website' ); ?></option>
						<option value="daily" selected><?php esc_html_e( 'Every day', 'founders-migration-website' ); ?></option>
						<option value="weekly"><?php esc_html_e( 'Every week', 'founders-migration-website' ); ?></option>
						<option value="monthly"><?php esc_html_e( 'Every month', 'founders-migration-website' ); ?></option>
					</select>
					<label data-fmw-when="weekly" hidden><?php esc_html_e( 'on', 'founders-migration-website' ); ?>
						<select name="weekday">
							<?php for ( $fmwp_day = 0; $fmwp_day < 7; $fmwp_day++ ) : ?>
								<option value="<?php echo esc_attr( (string) $fmwp_day ); ?>"><?php echo esc_html( $wp_locale->get_weekday( $fmwp_day ) ); ?></option>
							<?php endfor; ?>
						</select>
					</label>
					<label data-fmw-when="monthly" hidden><?php esc_html_e( 'on day', 'founders-migration-website' ); ?>
						<input type="number" name="monthday" min="1" max="28" value="1" class="small-text" />
					</label>
					<label><?php esc_html_e( 'at', 'founders-migration-website' ); ?>
						<input type="time" name="time" value="02:00" required />
					</label>
					<p class="description"><?php esc_html_e( 'Pick a quiet hour. Hourly schedules use only the minutes.', 'founders-migration-website' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-schedule-keep"><?php esc_html_e( 'Keep', 'founders-migration-website' ); ?></label></th>
				<td>
					<input type="number" id="fmw-schedule-keep" name="keep" min="0" max="1000" value="7" class="small-text" />
					<?php esc_html_e( 'newest backups of this schedule (0 = keep all). Older ones are deleted; backups made by hand are never touched.', 'founders-migration-website' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-schedule-storage"><?php esc_html_e( 'Upload to', 'founders-migration-website' ); ?></label></th>
				<td>
					<select id="fmw-schedule-storage" name="storage">
						<option value=""><?php esc_html_e( 'Nowhere (keep on this server only)', 'founders-migration-website' ); ?></option>
						<?php foreach ( Founders\Migration\Controller\AdminController::storage_choices() as $fmwp_storage ) : ?>
							<option value="<?php echo esc_attr( $fmwp_storage['id'] ); ?>"><?php echo esc_html( $fmwp_storage['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<div data-fmw-schedule-remote hidden>
						<p>
							<?php esc_html_e( 'Keep', 'founders-migration-website' ); ?>
							<input type="number" name="remote_keep" min="0" max="1000" value="30" class="small-text" />
							<?php esc_html_e( 'newest uploads of this schedule in the storage (0 = keep all).', 'founders-migration-website' ); ?>
						</p>
						<label class="fmw-option"><input type="checkbox" name="keep_local" checked /> <?php esc_html_e( 'Also keep the copies on this server (with the limit above)', 'founders-migration-website' ); ?></label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fmw-schedule-notify"><?php esc_html_e( 'E-mail', 'founders-migration-website' ); ?></label></th>
				<td class="fmw-inline">
					<select id="fmw-schedule-notify" name="notify">
						<option value="failure" selected><?php esc_html_e( 'Only when a backup fails', 'founders-migration-website' ); ?></option>
						<option value="always"><?php esc_html_e( 'After every backup', 'founders-migration-website' ); ?></option>
						<option value="never"><?php esc_html_e( 'Never', 'founders-migration-website' ); ?></option>
					</select>
					<input type="email" name="email" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Leave out', 'founders-migration-website' ); ?></th>
				<td>
					<fieldset class="fmw-schedule-flags">
						<?php foreach ( Founders\Migration\Controller\AdminController::exclusion_labels() as $fmwp_flag => $fmwp_label ) : ?>
							<label class="fmw-option"><input type="checkbox" data-fmw-flag="<?php echo esc_attr( $fmwp_flag ); ?>" /> <?php echo esc_html( $fmwp_label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Password', 'founders-migration-website' ); ?></th>
				<td>
					<label class="fmw-option"><input type="checkbox" name="encrypt" data-fmw-schedule-encrypt /> <?php esc_html_e( 'Protect these backups with a password', 'founders-migration-website' ); ?></label>
					<div data-fmw-schedule-password hidden>
						<p><input type="password" name="password" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Password (at least 8 characters)', 'founders-migration-website' ); ?>" /></p>
						<p><input type="password" name="password_repeat" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Repeat the password', 'founders-migration-website' ); ?>" /></p>
						<p class="description" data-fmw-schedule-password-kept hidden><?php esc_html_e( 'A password is saved. Leave the fields empty to keep it.', 'founders-migration-website' ); ?></p>
						<p class="description"><?php esc_html_e( 'The password is stored encrypted with this site\'s keys so backups can run unattended. Keep a copy: without it the backups cannot be restored.', 'founders-migration-website' ); ?></p>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'founders-migration-website' ); ?></th>
				<td><label class="fmw-option"><input type="checkbox" name="enabled" checked /> <?php esc_html_e( 'Enabled', 'founders-migration-website' ); ?></label></td>
			</tr>
		</table>
		<p class="fmw-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save schedule', 'founders-migration-website' ); ?></button>
			<button type="button" class="button" data-fmw-schedule-cancel hidden><?php esc_html_e( 'Cancel', 'founders-migration-website' ); ?></button>
		</p>
		<p class="fmw-error" role="alert" data-fmw-schedule-error></p>
	</form>
	<p class="description"><?php esc_html_e( 'Same as: wp fmw schedule add --frequency=daily --time=02:00 --keep=7', 'founders-migration-website' ); ?></p>
</div>
