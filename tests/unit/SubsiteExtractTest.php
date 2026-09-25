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
use Founders\Migration\Model\Import\SubsiteExtract;
use Founders\Migration\Tests\TestCase;

/**
 * One site of a network backup as a single site: which tables, files and URLs.
 */
final class SubsiteExtractTest extends TestCase {

	/**
	 * Manifest site of a subdirectory network.
	 *
	 * @return array<string,mixed>
	 */
	private function site(): array {
		return array(
			'multisite'   => true,
			'abspath'     => '/srv/net/',
			'uploads_dir' => 'wp-content/uploads',
			'sites'       => array(
				array( 'blog_id' => 1, 'domain' => 'net.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				array( 'blog_id' => 2, 'domain' => 'net.example', 'path' => '/shop/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				array( 'blog_id' => 12, 'domain' => 'brand.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			),
			'network'     => array(
				'domain' => 'net.example',
				'path'   => '/',
			),
		);
	}

	public function test_a_site_is_found_by_id_address_or_url(): void {
		foreach ( array( '2', 'net.example/shop', 'https://net.example/shop/', ' NET.example/shop/ ' ) as $choice ) {
			$this->assertSame( 2, SubsiteExtract::resolve( $this->site(), $choice )['blog_id'], $choice );
		}
		$this->assertSame( 12, SubsiteExtract::resolve( $this->site(), 'brand.example' )['blog_id'] );
		$this->assertSame( 1, SubsiteExtract::resolve( $this->site(), 'http://net.example' )['blog_id'] );
		try {
			SubsiteExtract::resolve( $this->site(), 'net.example/nope' );
			$this->fail( 'An unknown site was accepted.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'Its sites: 1 net.example/, 2 net.example/shop/, 12 brand.example/', $e->getMessage() );
		}
	}

	public function test_tables_of_the_chosen_site_users_and_sitemeta_come_along(): void {
		$map = static function ( int $blog ): array {
			$out = array();
			foreach ( array( 'wp_posts', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_sitemeta', 'wp_blogs', 'wp_site', 'wp_2_posts', 'wp_2_options', 'wp_12_posts', 'wp_2fa_codes', 'wp_404_to_301', 'other_posts' ) as $table ) {
				$out[ $table ] = SubsiteExtract::table( $table, 'wp_', $blog, array( 1, 2, 12 ) );
			}
			return array_filter( $out );
		};
		$this->assertSame(
			array( 'wp_users' => 'users', 'wp_usermeta' => 'usermeta', 'wp_sitemeta' => 'sitemeta', 'wp_2_posts' => 'posts', 'wp_2_options' => 'options' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			$map( 2 )
		);
		$this->assertSame(
			array( 'wp_posts' => 'posts', 'wp_options' => 'options', 'wp_users' => 'users', 'wp_usermeta' => 'usermeta', 'wp_sitemeta' => 'sitemeta', 'wp_2fa_codes' => '2fa_codes', 'wp_404_to_301' => '404_to_301' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data. No site 404.
			$map( 1 )
		);
	}

	public function test_media_of_the_chosen_site_moves_to_the_uploads_folder(): void {
		$this->assertSame( 'uploads/2026/09/a.jpg', SubsiteExtract::file( 'uploads/sites/2/2026/09/a.jpg', 'uploads', 2, 1 ) );
		$this->assertSame( 'uploads', SubsiteExtract::file( 'uploads/sites/2', 'uploads', 2, 1 ) );
		$this->assertNull( SubsiteExtract::file( 'uploads/sites/12/x.jpg', 'uploads', 2, 1 ) );
		$this->assertNull( SubsiteExtract::file( 'uploads/sites/2/x.jpg', 'uploads', 1, 1 ) );
		$this->assertNull( SubsiteExtract::file( 'uploads/2026/09/main.jpg', 'uploads', 2, 1 ) ); // The main site's media.
		$this->assertSame( 'uploads/2026/09/main.jpg', SubsiteExtract::file( 'uploads/2026/09/main.jpg', 'uploads', 1, 1 ) );
		// Main site 5: its media is in uploads/, site 1's in uploads/sites/1.
		$this->assertSame( 'uploads/2026/x.jpg', SubsiteExtract::file( 'uploads/2026/x.jpg', 'uploads', 5, 5 ) );
		$this->assertSame( 'uploads/2026/x.jpg', SubsiteExtract::file( 'uploads/sites/1/2026/x.jpg', 'uploads', 1, 5 ) );
		$this->assertSame( 'uploads/2026/old.jpg', SubsiteExtract::file( 'blogs.dir/2/files/2026/old.jpg', 'uploads', 2, 1 ) );
		$this->assertSame( 'themes/astra/style.css', SubsiteExtract::file( 'themes/astra/style.css', 'uploads', 2, 1 ) );
		$this->assertNull( SubsiteExtract::file( 'blogs.dir/12/files/x.jpg', 'uploads', 2, 1 ) );
		$this->assertSame( 'uploads-old/x', SubsiteExtract::file( 'uploads-old/x', 'uploads', 2, 1 ) ); // Not the uploads folder.
	}

	public function test_urls_of_the_site_move_and_the_other_sites_stay(): void {
		$target = array(
			'home_url'    => 'https://shop.test',
			'abspath'     => '/home/shop/',
			'uploads_dir' => '/home/shop/wp-content/uploads',
			'uploads_url' => 'https://shop.test/wp-content/uploads',
		);
		$pairs  = SubsiteExtract::pairs( SubsiteExtract::resolve( $this->site(), '2' ), $this->site(), $target );
		$this->assertSame(
			array(
				'http://net.example/shop'                                 => 'https://shop.test',
				'http://net.example/shop/wp-content/uploads/sites/2'      => 'https://shop.test/wp-content/uploads',
				'http://net.example/wp-content/uploads/sites/2'           => 'https://shop.test/wp-content/uploads',
				'http://net.example/shop/files'                           => 'https://shop.test/wp-content/uploads',
				'http://net.example/wp-content/blogs.dir/2/files'         => 'https://shop.test/wp-content/uploads',
			),
			$pairs['urls']
		);
		$this->assertSame(
			array(
				'/srv/net/wp-content/uploads/sites/2'   => '/home/shop/wp-content/uploads',
				'/srv/net/wp-content/blogs.dir/2/files' => '/home/shop/wp-content/uploads',
				'/srv/net'                              => '/home/shop',
			),
			$pairs['paths']
		);
		$this->assertSame( array( '//net.example/', '//net.example/wp-content/uploads/sites/1', '//brand.example/', '//net.example/wp-content/uploads/sites/12' ), $pairs['keep'] );
		$this->assertSame( array( 'net.example' ), $pairs['keep_email'] );
	}
}
