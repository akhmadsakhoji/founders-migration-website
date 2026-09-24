<?php
/**
 * Founders Migration Website
 *
 * Shared admin page frame.
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @var string              $fmwp_view            Current view.
 * @var array<string,mixed> $fmwp_data            View data.
 * @var string|null         $fmwp_exposure        Result of the backups folder web exposure check.
 * @var string[]            $fmwp_recommendations Non-blocking server suggestions.
 */

defined( 'ABSPATH' ) || exit;

$fmwp_tabs = array(
	'export'  => array( Founders\Migration\Controller\AdminController::SLUG_EXPORT, __( 'Export', 'founders-migration-website' ) ),
	'import'  => array( Founders\Migration\Controller\AdminController::SLUG_IMPORT, __( 'Import', 'founders-migration-website' ) ),
	'backups' => array( Founders\Migration\Controller\AdminController::SLUG_BACKUPS, __( 'Backups', 'founders-migration-website' ) ),
);
$fmwp_base = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
?>
<div class="wrap fmw-wrap">
	<h1><?php esc_html_e( 'Founders Migration Website', 'founders-migration-website' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $fmwp_tabs as $fmwp_key => $fmwp_tab ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'page', $fmwp_tab[0], $fmwp_base ) ); ?>" class="nav-tab<?php echo $fmwp_key === $fmwp_view ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $fmwp_tab[1] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'exposed' === $fmwp_exposure ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Your backups folder can be downloaded by anyone on the internet.', 'founders-migration-website' ); ?></strong></p>
			<p><?php esc_html_e( 'This web server ignores .htaccess (common on Nginx and OpenLiteSpeed). Move the folder outside the web root with FMWP_BACKUPS_PATH in wp-config.php, or block it in the server configuration:', 'founders-migration-website' ); ?></p>
			<pre>location ~* /wp-content/fmw-(backups|storage)/ { deny all; }</pre>
		</div>
	<?php endif; ?>

	<?php if ( $fmwp_recommendations ) : ?>
		<div class="notice notice-info">
			<ul>
				<?php foreach ( $fmwp_recommendations as $fmwp_note ) : ?>
					<li><?php echo esc_html( $fmwp_note ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php require __DIR__ . '/' . $fmwp_view . '.php'; ?>
</div>
