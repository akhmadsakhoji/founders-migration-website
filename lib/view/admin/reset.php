<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @var array<string,mixed> $fmwp_data confirm (text to type), theme (active theme name), sites (on a network: id, address, confirm), network (text to type for the whole network, '' when it cannot be reset).
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
);
if ( is_multisite() ) {
	// One site of the network: its own tables and uploads/sites/<id>; plugins and themes are shared.
	$fmwp_parts['database'][1] = __( 'One site: all its posts, pages, comments, settings and plugin data; it keeps its address, title, language and time zone, you become its administrator and other users lose their role on it. The whole network: every site but the main one, all content, users except you (a super admin afterwards) and network settings; the network keeps its address, name and language.', 'founders-migration-website' );
	$fmwp_parts['media'][1]    = __( 'One site: its uploads folder (uploads/sites/<id>) and media library. The whole network: the whole uploads folder.', 'founders-migration-website' );
}
$fmwp_parts += array(
	'plugins' => array(
		__( 'Plugins', 'founders-migration-website' ),
		__( 'All plugins except Founders Migration Website. Must-use plugins stay.', 'founders-migration-website' ),
	),
	'themes'  => array(
		__( 'Themes', 'founders-migration-website' ),
		/* translators: %s: active theme name. */
		sprintf( __( 'All themes except the active one (%s).', 'founders-migration-website' ), (string) $fmwp_data['theme'] ),
	),
);
?>
<div class="fmw-panel" id="fmw-reset">
	<h2><?php esc_html_e( 'Reset site', 'founders-migration-website' ); ?></h2>
	<p><?php esc_html_e( 'Brings parts of this site back to a fresh WordPress install, for example before importing another site or to start over.', 'founders-migration-website' ); ?></p>

	<?php if ( is_multisite() && empty( $fmwp_data['sites'] ) && empty( $fmwp_data['network'] ) ) : ?>
		<div class="notice inline notice-info"><p><?php esc_html_e( 'Nothing here can be reset: this install has several networks (or a main site other than site 1) and no site besides the main one.', 'founders-migration-website' ); ?></p></div>
	<?php else : ?>
		<?php if ( is_multisite() ) : ?>
			<p>
				<label>
					<?php esc_html_e( 'What to reset: one site (its tables and media; the main site only with the whole network), or the whole network:', 'founders-migration-website' ); ?><br />
					<select data-fmw-reset-site>
						<?php foreach ( (array) $fmwp_data['sites'] as $fmwp_site ) : ?>
							<option value="<?php echo esc_attr( (string) $fmwp_site['id'] ); ?>" data-confirm="<?php echo esc_attr( (string) $fmwp_site['confirm'] ); ?>"><?php echo esc_html( $fmwp_site['id'] . ' · ' . $fmwp_site['address'] ); ?></option>
						<?php endforeach; ?>
						<?php if ( ! empty( $fmwp_data['network'] ) ) : ?>
							<option value="network" data-confirm="<?php echo esc_attr( (string) $fmwp_data['network'] ); ?>"><?php esc_html_e( 'The whole network: only the main site stays, fresh; every other site, user and setting is deleted', 'founders-migration-website' ); ?></option>
						<?php endif; ?>
					</select>
				</label>
			</p>
		<?php endif; ?>
		<fieldset class="fmw-reset-parts">
			<?php foreach ( $fmwp_parts as $fmwp_part => $fmwp_text ) : ?>
				<?php $fmwp_shared = is_multisite() && ! in_array( $fmwp_part, array( 'database', 'media' ), true ); // Shared by every site: only with the whole network. ?>
				<label class="fmw-reset-part">
					<input type="checkbox" value="<?php echo esc_attr( $fmwp_part ); ?>" data-fmw-reset-part <?php echo $fmwp_shared ? 'data-fmw-network-only' : ''; ?> <?php disabled( $fmwp_shared && ! empty( $fmwp_data['sites'] ) ); ?> />
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
				<?php is_multisite() ? esc_html_e( 'Make a backup of the whole network first (recommended: the reset can then be undone from the Backups page)', 'founders-migration-website' ) : esc_html_e( 'Make a backup of the whole site first (recommended: the reset can then be undone from the Backups page)', 'founders-migration-website' ); ?>
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
					'<code data-fmw-reset-word>' . esc_html( (string) $fmwp_data['confirm'] ) . '</code>'
				);
				?>
				<br />
				<input type="text" class="regular-text" autocomplete="off" spellcheck="false" data-fmw-reset-confirm="<?php echo esc_attr( (string) $fmwp_data['confirm'] ); ?>" />
			</label>
		</p>

		<p class="fmw-actions">
			<button type="button" class="button button-primary button-hero fmw-danger" data-fmw-action="reset" disabled><?php esc_html_e( 'Reset', 'founders-migration-website' ); ?></button>
		</p>
		<p class="description"><?php is_multisite() ? esc_html_e( 'Same as: wp fmw reset --site=<id> --database --media, or wp fmw reset --network --all', 'founders-migration-website' ) : esc_html_e( 'Same as: wp fmw reset --database --media --plugins --themes', 'founders-migration-website' ); ?></p>
	<?php endif; ?>
</div>
