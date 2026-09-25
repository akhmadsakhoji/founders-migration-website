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
use Founders\Migration\Model\Import\NetworkMove;
use Founders\Migration\Model\Import\SwapStep;
use Founders\Migration\Tests\TestCase;

/**
 * Where the sites of a multisite network go when it is restored at another address.
 */
final class NetworkMoveTest extends TestCase {

	/**
	 * A manifest site of a network.
	 *
	 * @param bool                                                    $subdomain Subdomain install.
	 * @param array<int,array{blog_id:int,domain:string,path:string}> $sites   Sites.
	 * @param bool                                                    $recorded  Whether the manifest records the network (0.1.0 and later).
	 * @return array<string,mixed>
	 */
	private function source( bool $subdomain, array $sites, bool $recorded = true ): array {
		return array(
			'home_url'  => 'https://' . $sites[0]['domain'] . rtrim( $sites[0]['path'], '/' ),
			'multisite' => true,
			'sites'     => $sites,
			'network'   => $recorded ? array(
				'domain'    => $sites[0]['domain'],
				'path'      => $sites[0]['path'],
				'subdomain' => $subdomain,
				'main_site' => 1,
			) : null,
		);
	}

	/**
	 * A restore target that is a network.
	 *
	 * @param string $domain    Domain.
	 * @param string $path      Path.
	 * @param bool   $subdomain Subdomain install.
	 * @param string $scheme    Scheme.
	 * @return array<string,mixed>
	 */
	private function target( string $domain, string $path, bool $subdomain, string $scheme = 'https' ): array {
		return array(
			'home_url'  => $scheme . '://' . $domain . rtrim( $path, '/' ),
			'multisite' => true,
			'network'   => array(
				'domain'    => $domain,
				'path'      => $path,
				'subdomain' => $subdomain,
				'main_site' => 1,
			),
		);
	}

	/**
	 * Old => new "domain+path" of every site in a plan.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return array<string,string>
	 */
	private function places( array $plan ): array {
		$places = array();
		foreach ( $plan['sites'] as $blog ) {
			$places[ $blog['from']['domain'] . $blog['from']['path'] ] = $blog['to']['domain'] . $blog['to']['path'];
		}
		return $places;
	}

	public function test_a_subdomain_network_moves_with_its_subsites_and_keeps_own_domains(): void {
		$plan = NetworkMove::plan(
			$this->source(
				true,
				array(
					array( 'blog_id' => 1, 'domain' => 'www.old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 2, 'domain' => 'shop.old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 3, 'domain' => 'brand.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				)
			),
			$this->target( 'new.test', '/', true, 'http' )
		);

		$this->assertTrue( $plan['moved'] );
		$this->assertSame(
			array(
				'www.old.example/'  => 'new.test/',
				'shop.old.example/' => 'shop.new.test/',
				'brand.example/'    => 'brand.example/',
			),
			$this->places( $plan )
		);
		$this->assertSame( array( 'brand.example' ), $plan['kept'] );
		$this->assertSame(
			array(
				'http://www.old.example'  => 'http://new.test',
				'http://shop.old.example' => 'http://shop.new.test',
			),
			$plan['urls']
		);
		$this->assertSame( array( 'domain' => 'www.old.example', 'path' => '/' ), $plan['network']['from'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$this->assertSame( array( 'domain' => 'new.test', 'path' => '/' ), $plan['network']['to'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
	}

	public function test_a_subdirectory_network_moves_into_a_folder_and_maps_own_domains(): void {
		$plan = NetworkMove::plan(
			$this->source(
				false,
				array(
					array( 'blog_id' => 1, 'domain' => 'old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 4, 'domain' => 'old.example', 'path' => '/shop/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 5, 'domain' => 'brand.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				)
			),
			$this->target( 'localhost:8093', '/net/', false ),
			NetworkMove::parse_map( 'Brand.example=brand.localhost:8093' )
		);

		$this->assertSame(
			array(
				'old.example/'      => 'localhost:8093/net/',
				'old.example/shop/' => 'localhost:8093/net/shop/',
				'brand.example/'    => 'brand.localhost:8093/',
			),
			$this->places( $plan )
		);
		$this->assertSame( array(), $plan['kept'] );
		$this->assertSame( 'https://localhost:8093/net/shop', $plan['urls']['http://old.example/shop'] );
	}

	public function test_the_same_address_changes_nothing(): void {
		$sites = array(
			array( 'blog_id' => 1, 'domain' => 'old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			array( 'blog_id' => 2, 'domain' => 'old.example', 'path' => '/two/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		);
		$plan  = NetworkMove::plan( $this->source( false, $sites ), $this->target( 'old.example', '/', false ) );
		$this->assertFalse( $plan['moved'] );
		$this->assertSame( array(), $plan['urls'] );
	}

	public function test_older_manifests_are_understood_from_their_sites(): void {
		$sites = array(
			array( 'blog_id' => 1, 'domain' => 'old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			array( 'blog_id' => 2, 'domain' => 'shop.old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		);
		$this->assertTrue( NetworkMove::source( $this->source( true, $sites, false ) )['subdomain'] );
		$sites[1] = array( 'blog_id' => 2, 'domain' => 'old.example', 'path' => '/shop/' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$this->assertFalse( NetworkMove::source( $this->source( false, $sites, false ) )['subdomain'] );
	}

	public function test_refuses_mixing_subdomains_and_subdirectories(): void {
		$sites = array(
			array( 'blog_id' => 1, 'domain' => 'old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			array( 'blog_id' => 2, 'domain' => 'shop.old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		);
		try {
			NetworkMove::plan( $this->source( true, $sites ), $this->target( 'new.test', '/', false ) );
			$this->fail( 'A subdomain network was planned onto a subdirectory network.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'SUBDOMAIN_INSTALL to true', $e->getMessage() );
		}
	}

	public function test_refuses_mapping_the_network_domain_or_two_sites_onto_one(): void {
		$sites = array(
			array( 'blog_id' => 1, 'domain' => 'old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			array( 'blog_id' => 2, 'domain' => 'brand.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		);
		try {
			NetworkMove::plan( $this->source( false, $sites ), $this->target( 'new.test', '/', false ), array( 'old.example' => 'x.test' ) );
			$this->fail( 'Mapping the network domain was accepted.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'leave it out of --map', $e->getMessage() );
		}
		try {
			NetworkMove::plan( $this->source( false, $sites ), $this->target( 'new.test', '/', false ), array( 'brand.example' => 'new.test' ) );
			$this->fail( 'Two sites were planned onto one address.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'would both end up at new.test/', $e->getMessage() );
		}
	}

	public function test_refuses_what_cannot_be_moved_safely(): void {
		$sites = array(
			array( 'blog_id' => 1, 'domain' => 'old.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			array( 'blog_id' => 2, 'domain' => 'old.example', 'path' => '/two/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		);
		$cases = array(
			'main site is site 1 and this network\'s is site 3' => array( $this->source( false, $sites ), array( 'main_site' => 3 ), array() ),
			'more than one network'  => array( $this->source( false, $sites ), array( 'networks' => 2 ), array() ),
			'No site of the backup has the domain typo.example' => array( $this->source( false, $sites ), array(), array( 'typo.example' => 'x.test' ) ),
			'invalid domain or path' => array( array( 'sites' => array( $sites[0], array( 'blog_id' => 2, 'domain' => 'old.example', 'path' => "/x\e[2J/" ) ) ) + $this->source( false, $sites ), array(), array() ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		);
		foreach ( $cases as $message => $case ) {
			$target            = $this->target( 'new.test', '/', false );
			$target['network'] = $case[1] + $target['network'];
			try {
				NetworkMove::plan( $case[0], $target, $case[2] );
				$this->fail( "Planned although: {$message}" );
			} catch ( JobException $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_parse_map_rejects_bad_entries(): void {
		$this->assertSame( array( 'a.example' => 'b.test:8080' ), NetworkMove::parse_map( ' a.example=b.test:8080 ' ) );
		$this->assertSame( array(), NetworkMove::parse_map( '' ) );
		foreach ( array( 'a.example', 'a.example=', 'a.example=b/c', 'a=b=c', 'https://a.example=b.test', "a.example=b.test\n" . 'x' ) as $bad ) {
			try {
				NetworkMove::parse_map( $bad );
				$this->fail( "Accepted --map={$bad}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'Invalid --map entry', $e->getMessage() );
			}
		}
	}

	public function test_orphan_site_tables_are_only_whole_sites_missing_from_the_backup(): void {
		$network = array(
			'sites' => array(
				array( 'blog_id' => 1 ),
				array( 'blog_id' => 2 ),
			),
		);
		$live    = array( 'wp_options', 'wp_2_options', 'wp_2_posts', 'wp_7_options', 'wp_7_posts', 'wp_9_posts', 'wp_2fa_codes', 'wpx_7_options', 'wp_07_options', 'wp_2024_options', 'wp_2024_posts' );
		// wp_2024_ is another install in the same database, not a site of this network.
		$this->assertSame( array( 'wp_7_options', 'wp_7_posts' ), SwapStep::orphan_site_tables( $network, $live, 'wp_', array( 1, 2, 7, 9 ) ) );
	}
}
