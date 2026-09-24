<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @var array<string,mixed> $fmwp_data confirm (text to type), theme (active theme name).
 */

defined( 'ABSPATH' ) || exit;

$fmwp_parts = array(
	'database' => array(
		__( 'Database', 'founders-migration-website' ),
		__( 'All posts, pages, comments, settings, plugin data and users, except you. The site keeps its address, title, language and time zone; you stay logged in as administrator.', 'founders-migration-website' ),
	),
	'media'    => array(
		__( 'Media', 'founders-migration-website' ),
		__( 'Everything in the uploads folder, and the media library.', 'founders-migration-website' ),
	),
	'plugins'  => array(
		__( 'Plugins', 'founders-migration-website' ),
		__( 'All plugins except Founders Migration Website. Must-use plugins stay.', 'founders-migration-website' ),
	),
	'themes'   => array(
		__( 'Themes', 'founders-migration-website' ),
		/* translators: %s: active theme name. */
		sprintf( __( 'All themes except the active one (%s).', 'founders-migration-website' ), (string) $fmwp_data['theme'] ),
	),
);
?>
<div class="fmw-panel" id="fmw-reset">
	<h2><?php esc_html_e( 'Reset site', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'Brings parts of this site back to a fresh WordPress install, for example before importing another site or to start over.', 'founders-migration-website' ); ?></p>

	<?php if ( is_multisite() ) : ?>
		<div class="notice inline notice-info"><p><?php esc_html_e( 'Reset is not available on multisite networks yet.', 'founders-migration-website' ); ?></p></div>
	<?php else : ?>
		<fieldset class="fmw-reset-parts">
			<?php foreach ( $fmwp_parts as $fmwp_part => $fmwp_text ) : ?>
				<label class="fmw-reset-part">
					<input type="checkbox" value="<?php echo esc_attr( $fmwp_part ); ?>" data-fmw-reset-part />
					<span>
						<strong><?php echo esc_html( $fmwp_text[0] ); ?></strong><br />
						<span class="description"><?php echo esc_html( $fmwp_text[1] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<p>
			<label class="fmw-option">
				<input type="checkbox" checked data-fmw-reset-backup />
				<?php esc_html_e( 'Make a backup of the whole site first (recommended: the reset can then be undone from the Backups page)', 'founders-migration-website' ); ?>
			</label>
		</p>

		<div class="notice inline notice-warning">
			<p><?php esc_html_e( 'What is reset is deleted. The database is switched in one step, so it stays as it is if the reset fails before that.', 'founders-migration-website' ); ?></p>
		</div>

		<p>
			<label>
				<?php
				printf(
					/* translators: %s: site domain. */
					esc_html__( 'Type %s to confirm:', 'founders-migration-website' ),
					'<code>' . esc_html( (string) $fmwp_data['confirm'] ) . '</code>'
				);
				?>
				<br />
				<input type="text" class="regular-text" autocomplete="off" spellcheck="false" data-fmw-reset-confirm="<?php echo esc_attr( (string) $fmwp_data['confirm'] ); ?>" />
			</label>
		</p>

		<p class="fmw-actions">
			<button type="button" class="button button-primary button-hero fmw-danger" data-fmw-action="reset" disabled><?php esc_html_e( 'Reset', 'founders-migration-website' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'Same as: wp fmw reset --database --media --plugins --themes', 'founders-migration-website' ); ?></p>
	<?php endif; ?>
</div>
