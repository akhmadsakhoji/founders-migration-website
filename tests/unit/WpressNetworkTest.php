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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Job\JobException;
use Founders\Migration\Model\Import\WpressNetwork;
use Founders\Migration\Tests\TestCase;

/**
 * multisite.json of All-in-One WP Migration Multisite Extension backups.
 */
final class WpressNetworkTest extends TestCase {

	/**
	 * A network with these sites (domain, path) at old.example.
	 *
	 * @param array<int,array{0:string,1:string}> $sites BlogID => [domain, path].
	 * @param bool                                $whole A whole network (else sites picked one by one).
	 * @return WpressNetwork
	 */
	private function network( array $sites, bool $whole = true ): WpressNetwork {
		$list = array();
		foreach ( $sites as $id => $site ) {
			$list[] = array(
				'BlogID'     => $id,
				'Domain'     => $site[0],
				'Path'       => $site[1],
				'Template'   => 5 === $id ? '../../evil' : 'astra',
				'Stylesheet' => 'astra-child',
				'Plugins'    => array( 'shop/shop.php' ),
			);
		}
		return WpressNetwork::parse(
			(string) json_encode(
				array(
					'Network'  => $whole,
					'Networks' => array(
						array(
							'SiteID' => 1,
							'Domain' => 'old.example',
							'Path'   => '/',
						),
					),
					'Sites'    => $list,
					'Plugins'  => array( 'demo/demo.php' ),
				)
			)
		);
	}

	/**
	 * Kind of network as worked out from its sites.
	 *
	 * @param array<int,array{0:string,1:string}> $sites Sites.
	 * @return bool|null
	 */
	private function kind( array $sites ): ?bool {
		$package = WpressPackage::parse( '{"HomeURL":"https://old.example","SiteURL":"https://old.example","Database":{"Prefix":"wp_"}}' );
		return $this->network( $sites )->site( $package )['network']['subdomain'];
	}

	public function test_the_kind_of_network_is_worked_out_from_its_sites(): void {
		$this->assertFalse( $this->kind( array( 1 => array( 'old.example', '/' ), 2 => array( 'old.example', '/shop/' ) ) ) );
		$this->assertTrue( $this->kind( array( 1 => array( 'old.example', '/' ), 2 => array( 'shop.old.example', '/' ) ) ) );
		// A subdirectory network with a mapped site on a subdomain of its domain is still subdirectories.
		$this->assertFalse( $this->kind( array( 1 => array( 'old.example', '/' ), 2 => array( 'blog.old.example', '/' ), 3 => array( 'old.example', '/shop/' ) ) ) );
		// Only sites with their own domain: unknown, so no kind check refuses it.
		$this->assertNull( $this->kind( array( 1 => array( 'old.example', '/' ), 2 => array( 'brand.example', '/' ) ) ) );
	}

	public function test_activation_plan_is_per_site_id_and_themes_are_folder_names(): void {
		$plan = $this->network( array( 1 => array( 'old.example', '/' ), 5 => array( 'old.example', '/five/' ) ) )->activation();
		$this->assertSame( array( 1, 5 ), array_keys( $plan['sites'] ) );
		$this->assertSame( 'astra', $plan['sites'][1]['template'] );
		$this->assertSame( '', $plan['sites'][5]['template'] ); // "../../evil" is not a theme folder.
		$this->assertSame( array( 'demo/demo.php' ), $plan['sitewide'] );
	}

	public function test_one_site_is_chosen_for_a_single_site(): void {
		$package = WpressPackage::parse( '{"HomeURL":"https://old.example","SiteURL":"https://old.example","Database":{"Prefix":"wp_"}}' );
		$whole   = $this->network( array( 1 => array( 'old.example', '/' ), 2 => array( 'shop.old.example', '/' ) ) );
		$this->assertSame( 2, $whole->extract( $package, 'shop.old.example' )['blog_id'] );
		$this->assertFalse( $whole->extract( $package, '2' )['picked'] );
		try {
			$whole->extract( $package, '' );
			$this->fail( 'A whole network needs --site.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'Its sites: 1 old.example/, 2 shop.old.example/', $e->getMessage() );
		}

		// One picked site needs no choice; the main site is 1 even when it is not in the backup.
		$one  = $this->network( array( 3 => array( 'shop.old.example', '/' ) ), false );
		$plan = $one->extract( $package, '' );
		$this->assertSame( array( 3, true, 1, 'uploads' ), array( $plan['blog_id'], $plan['picked'], $plan['main_site'], $plan['uploads'] ) );
		$this->assertSame( array( 1 ), array_keys( $one->extract_activation( 3 )['sites'] ) );
		$this->assertSame( array( 'shop/shop.php', 'demo/demo.php' ), $one->extract_activation( 3 )['sites'][1]['plugins'] ); // Network-activated ones too.
		$this->assertNull( $one->extract_activation( 3 )['sitewide'] );
	}

	public function test_damaged_multisite_json_is_refused(): void {
		foreach ( array( 'not json', '{"Network":true,"Sites":[]}', '{"Sites":[{"BlogID":1,"Domain":["x"],"Path":"/"}]}', '{"Networks":[{"Domain":1}],"Sites":[{"BlogID":1,"Domain":"a.example","Path":"/"}]}' ) as $json ) {
			try {
				WpressNetwork::parse( $json );
				$this->fail( "Accepted {$json}" );
			} catch ( ArchiveException $e ) {
				$this->assertStringContainsString( 'multisite.json is damaged', $e->getMessage() );
			}
		}
	}
}
