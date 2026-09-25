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

use Founders\Migration\Job\JobException;
use Founders\Migration\Model\Import\SubsiteImport;
use Founders\Migration\Tests\TestCase;

/**
 * A single-site backup as a site of a network: which site, tables, files and address.
 */
final class SubsiteImportTest extends TestCase {

	/**
	 * Restore target of a network.
	 *
	 * @param bool $subdomain Subdomain install.
	 * @return array<string,mixed>
	 */
	private function target( bool $subdomain ): array {
		return array(
			'home_url'    => 'https://net.example',
			'uploads_url' => 'https://net.example/wp-content/uploads',
			'uploads_dir' => '/srv/net/wp-content/uploads',
			'multisite'   => true,
			'network'     => array(
				'domain'    => 'net.example',
				'path'      => '/',
				'subdomain' => $subdomain,
				'main_site' => 1,
			),
			'sites'       => array(
				array( 'blog_id' => 1, 'domain' => 'net.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				array( 'blog_id' => 4, 'domain' => $subdomain ? 'news.net.example' : 'net.example', 'path' => $subdomain ? '/' : '/news/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			),
		);
	}

	public function test_a_name_or_address_becomes_a_new_site_and_an_existing_one_is_replaced(): void {
		$dir = $this->target( false );
		$sub = $this->target( true );
		$this->assertSame( array( 0, 'net.example', '/shop/', true ), array_values( SubsiteImport::resolve( $dir, 'shop' ) ) );
		$this->assertSame( array( 0, 'net.example', '/shop/', true ), array_values( SubsiteImport::resolve( $dir, 'https://net.example/shop/' ) ) );
		$this->assertSame( array( 0, 'shop.net.example', '/', true ), array_values( SubsiteImport::resolve( $sub, 'shop' ) ) );
		$this->assertSame( array( 0, 'brand.example', '/', true ), array_values( SubsiteImport::resolve( $sub, 'brand.example' ) ) );
		$this->assertSame( array( 4, 'net.example', '/news/', false ), array_values( SubsiteImport::resolve( $dir, 'net.example/news' ) ) );
		$this->assertSame( array( 4, 'news.net.example', '/', false ), array_values( SubsiteImport::resolve( $sub, '4' ) ) );
		$this->assertSame( array( 4, 'net.example', '/news/', false ), array_values( SubsiteImport::resolve( $dir, 'https://net.example/news/' ) ) );
		$this->assertSame( array( 4, 'news.net.example', '/', false ), array_values( SubsiteImport::resolve( $sub, 'news.net.example' ) ) );

		$main3                         = $dir;
		$main3['network']['main_site'] = 3;
		$main3['sites'][]              = array( 'blog_id' => 3, 'domain' => 'www.net.example', 'path' => '/' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$several                       = $dir;
		$several['network']['networks'] = 2;

		$refused = array(
			'main site'          => array( $dir, '1' ),
			'no site 9'          => array( $dir, '9' ),
			'does not reserve'   => array( $dir, 'wp-admin' ),
			'not a folder'       => array( $sub, 'net.example/shop' ),
			'not a site address' => array( $dir, 'bad name!' ),
			'Site 1 keeps'       => array( $main3, '1' ),
			'several networks'   => array( $several, 'shop' ),
			'already site 4'     => array( $dir, 'news' ), // A bare name never replaces a site.
			'give its ID (4)'    => array( $sub, 'news' ),
		);
		foreach ( $refused as $message => $case ) {
			try {
				SubsiteImport::resolve( $case[0], $case[1] );
				$this->fail( "Accepted {$case[1]}" );
			} catch ( JobException $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_tables_get_the_site_prefix_and_users_wait_for_the_merge(): void {
		$this->assertSame( '5_posts', SubsiteImport::table( 'wp_posts', 'wp_', 5 ) );
		$this->assertSame( '5_woocommerce_sessions', SubsiteImport::table( 'wp_woocommerce_sessions', 'wp_', 5 ) );
		$this->assertSame( 'users', SubsiteImport::table( 'wp_users', 'wp_', 5 ) );
		$this->assertSame( 'usermeta', SubsiteImport::table( 'SERVMASK_PREFIX_usermeta', 'SERVMASK_PREFIX_', 5 ) );
		$this->assertNull( SubsiteImport::table( 'other_posts', 'wp_', 5 ) );
	}

	public function test_media_moves_into_the_site_folder_and_network_wide_files_stay_out(): void {
		$this->assertSame( 'uploads/sites/5/2026/09/a.jpg', SubsiteImport::file( 'uploads/2026/09/a.jpg', 'uploads', 5 ) );
		$this->assertSame( 'plugins/shop/shop.php', SubsiteImport::file( 'plugins/shop/shop.php', 'uploads', 5 ) );
		$this->assertSame( 'themes/astra/style.css', SubsiteImport::file( 'themes/astra/style.css', 'uploads', 5 ) );
		$this->assertNull( SubsiteImport::file( 'mu-plugins/x.php', 'uploads', 5 ) );
		$this->assertNull( SubsiteImport::file( 'object-cache.php', 'uploads', 5 ) );
		$this->assertNull( SubsiteImport::file( 'cache/page.html', 'uploads', 5 ) );
	}

	public function test_address_and_leftover_tables(): void {
		$plan = array(
			'blog_id' => 7,
			'domain'  => 'net.example',
			'path'    => '/shop/',
		);
		$this->assertSame(
			array(
				'home_url'    => 'https://net.example/shop',
				'uploads_url' => 'https://net.example/shop/wp-content/uploads/sites/7',
				'uploads_dir' => '/srv/net/wp-content/uploads/sites/7',
			),
			SubsiteImport::address( $plan, $this->target( false ) )
		);
		$this->assertSame(
			array( 'wp_7_old_plugin' ),
			SubsiteImport::leftovers( array( 'wp_7_posts', 'wp_7_old_plugin', 'wp_70_posts', 'wp_posts' ), array( 'wp_7_posts' ), 'wp_', 7 )
		);
	}
}
