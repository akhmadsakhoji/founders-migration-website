<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Unit;

use Founders\Migration\Model\Import\ServerFixStep;
use Founders\Migration\Tests\TestCase;

/**
 * Fixes after a restore: rewrite rules and recognising a web server's own 404 page.
 */
final class ServerFixTest extends TestCase {

	public function test_existing_rules_are_recognised_with_or_without_markers(): void {
		$this->assertTrue( ServerFixStep::has_rules( "# BEGIN LSCACHE\n# END LSCACHE\n# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n" ) );
		$this->assertFalse( ServerFixStep::has_rules( "# BEGIN WordPress\n# The directives (lines) between \"BEGIN WordPress\" and \"END WordPress\" are\n# dynamically generated.\n\n# END WordPress\n" ) ); // Written while permalinks were plain.
		$this->assertTrue( ServerFixStep::has_rules( "RewriteRule . /index.php [QSA,L]\n" ) );
		$this->assertTrue( ServerFixStep::has_rules( "RewriteEngine On\nRewriteBase /\nRewriteRule . index.php [L]\n" ) ); // Pasted from Network Setup.
		$this->assertTrue( ServerFixStep::has_rules( "RewriteRule . /blog/index.php [L]\n" ) );
		$this->assertFalse( ServerFixStep::has_rules( '' ) );
		$this->assertFalse( ServerFixStep::has_rules( "# BEGIN LSCACHE\nRewriteEngine on\nRewriteRule .* - [E=Cache-Control:no-autoflush]\n# END LSCACHE\n" ) );
		$this->assertFalse( ServerFixStep::has_rules( "# RewriteRule . /index.php [L] was removed\n" ) );
	}

	public function test_single_site_rules_match_wordpress(): void {
		$this->assertSame(
			array(
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
				'RewriteBase /',
				'RewriteRule ^index\.php$ - [L]',
				'RewriteCond %{REQUEST_FILENAME} !-f',
				'RewriteCond %{REQUEST_FILENAME} !-d',
				'RewriteRule . /index.php [L]',
				'</IfModule>',
			),
			ServerFixStep::site_rules( '/' )
		);
		$rules = ServerFixStep::site_rules( 'blog/' );
		$this->assertContains( 'RewriteBase /blog/', $rules );
		$this->assertContains( 'RewriteRule . /blog/index.php [L]', $rules );
	}

	public function test_network_rules_match_the_network_setup_screen(): void {
		$this->assertSame(
			array(
				'RewriteEngine On',
				'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
				'RewriteBase /',
				'RewriteRule ^index\.php$ - [L]',
				'# add a trailing slash to /wp-admin',
				'RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]',
				'RewriteCond %{REQUEST_FILENAME} -f [OR]',
				'RewriteCond %{REQUEST_FILENAME} -d',
				'RewriteRule ^ - [L]',
				'RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]',
				'RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]',
				'RewriteRule . index.php [L]',
			),
			ServerFixStep::network_rules( false, '/', false )
		);
		$sub = ServerFixStep::network_rules( true, '/net/', true );
		$this->assertContains( 'RewriteBase /net/', $sub );
		$this->assertContains( 'RewriteRule ^wp-admin$ wp-admin/ [R=301,L]', $sub );
		$this->assertContains( 'RewriteRule ^(wp-(content|admin|includes).*) $1 [L]', $sub );
		$this->assertContains( 'RewriteRule ^files/(.+) wp-includes/ms-files.php?file=$1 [L]', $sub );
		$this->assertContains( 'RewriteBase /', ServerFixStep::network_rules( true, '', false ) );
	}

	public function test_web_server_404_pages_are_told_apart_from_wordpress(): void {
		$litespeed = '<html><h1>404</h1>Not Found<br>Proudly powered by LiteSpeed Web Server. Please be advised that LiteSpeed Technologies Inc. is not a web hosting company</html>';
		$this->assertSame( 'litespeed', ServerFixStep::server_404( 404, 'LiteSpeed', $litespeed ) );
		$this->assertSame( 'apache', ServerFixStep::server_404( 404, 'Apache/2.4.58 (Ubuntu)', '<p>The requested URL was not found on this server.</p><address>Apache/2.4.58</address>' ) );
		$this->assertSame( '', ServerFixStep::server_404( 404, 'LiteSpeed', '<!doctype html><html class="wp"><body class="error404">Page not found</body></html>' ) );
		$this->assertSame( '', ServerFixStep::server_404( 200, 'LiteSpeed', $litespeed ) );
	}
}
