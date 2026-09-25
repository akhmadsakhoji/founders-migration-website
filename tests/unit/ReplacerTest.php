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

use Founders\Migration\Database\Replacer;
use Founders\Migration\Tests\TestCase;

/**
 * Serialized-safe search-replace.
 */
final class ReplacerTest extends TestCase {

	/**
	 * Replacer for a move from example.com to toko.co.id/shop.
	 *
	 * @return Replacer
	 */
	private function move(): Replacer {
		return new Replacer(
			Replacer::site_pairs(
				array( 'https://example.com' => 'https://toko.co.id/shop' ),
				array( '/home/old/public_html' => '/home/new/public_html' ),
				true
			)
		);
	}

	public function test_plain_json_encoded_and_protocol_relative_urls(): void {
		$r = $this->move();

		$this->assertSame( 'Visit https://toko.co.id/shop/about', $r->replace( 'Visit https://example.com/about' ) );
		$this->assertSame( 'Old http link https://toko.co.id/shop/x', $r->replace( 'Old http link http://example.com/x' ) );
		$this->assertSame( '<img src="//toko.co.id/shop/a.jpg">', $r->replace( '<img src="//example.com/a.jpg">' ) );
		$this->assertSame( '{"url":"https:\/\/toko.co.id\/shop\/p"}', $r->replace( '{"url":"https:\/\/example.com\/p"}' ) );
		$this->assertSame( 'u=' . rawurlencode( 'https://toko.co.id/shop' ) . '%2Fp', $r->replace( 'u=' . rawurlencode( 'https://example.com' ) . '%2Fp' ) );
		$this->assertSame( 'admin@toko.co.id', $r->replace( 'admin@example.com' ) );
		$this->assertSame( '/home/new/public_html/wp-content/uploads', $r->replace( '/home/old/public_html/wp-content/uploads' ) );
		$this->assertSame( 'no match here', $r->replace( 'no match here' ) );
	}

	public function test_new_url_containing_the_old_one_is_not_replaced_twice(): void {
		$r = new Replacer( Replacer::site_pairs( array( 'https://example.com' => 'https://example.com/staging' ), array(), false ) );

		$this->assertSame( 'https://example.com/staging/x and //example.com/staging/y', $r->replace( 'https://example.com/x and //example.com/y' ) );
	}

	public function test_serialized_lengths_are_recomputed(): void {
		$r    = $this->move();
		$data = array(
			'home'   => 'https://example.com',
			'nested' => array( 'img' => 'https://example.com/ñ-日本.jpg', 'n' => 3, 'f' => 1.5, 'b' => true, 'null' => null ),
			'https://example.com' => 'keys are not rewritten',
		);

		$out = $r->replace( serialize( $data ) );

		$this->assertSame(
			array(
				'home'   => 'https://toko.co.id/shop',
				'nested' => array( 'img' => 'https://toko.co.id/shop/ñ-日本.jpg', 'n' => 3, 'f' => 1.5, 'b' => true, 'null' => null ),
				'https://example.com' => 'keys are not rewritten',
			),
			unserialize( $out ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test data built above.
		);
	}

	public function test_objects_are_rewritten_without_being_instantiated(): void {
		$r      = $this->move();
		$object = static function ( string $url ): string {
			$inner = serialize( array( 'u' => $url . '/x/y' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			return 'O:14:"Evil_Gadget_Zz":2:{s:3:"url";s:' . strlen( $url ) . ':"' . $url . '";s:6:"nested";s:' . strlen( $inner ) . ':"' . $inner . '";}';
		};

		$this->assertSame( $object( 'https://toko.co.id/shop' ), $r->replace( $object( 'https://example.com' ) ) );
		$this->assertFalse( class_exists( 'Evil_Gadget_Zz', false ) );
	}

	public function test_custom_serialized_objects_stay_opaque_and_broken_data_falls_back(): void {
		$r = $this->move();

		$custom = 'C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}';
		$this->assertSame( $custom, $r->replace( $custom ) );

		$broken = 's:99:"https://example.com";';
		$this->assertSame( 's:99:"https://toko.co.id/shop";', $r->replace( $broken ), 'invalid serialization: plain replace' );
	}

	public function test_same_url_produces_no_pairs(): void {
		$this->assertSame( array(), Replacer::site_pairs( array( 'https://example.com/' => 'https://example.com' ), array( '/a' => '/a/' ), true ) );
	}

	public function test_the_home_url_decides_email_domains(): void {
		// Home stays, uploads move to a CDN: addresses keep their domain.
		$pairs = Replacer::site_pairs( array( 'https://example.com' => 'https://example.com', 'https://example.com/wp-content/uploads' => 'https://cdn.example.net/uploads' ), array(), true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$this->assertArrayNotHasKey( '@example.com', $pairs );
		// Home moves, uploads URL on another host: addresses follow the home URL.
		$pairs = Replacer::site_pairs( array( 'https://example.com' => 'https://new.test', 'https://example.com/wp-content/uploads' => 'https://cdn.example.net/uploads' ), array(), true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$this->assertSame( '@new.test', $pairs['@example.com'] );
	}

	public function test_addresses_and_paths_match_whole(): void {
		$r = new Replacer(
			Replacer::site_pairs( array( 'https://example.com/shop' => 'https://shop.test' ), array( '/srv/old' => '/srv/new' ), true ),
			array( 'Brand' => 'Label' )
		);
		$this->assertSame(
			'https://shop.test/cart https://example.com/shopping https://shop.test "https://shop.test" https://example.com/shop-2 /srv/new/x /srv/old_bak Label Labels',
			$r->replace( 'https://example.com/shop/cart https://example.com/shopping https://example.com/shop "https://example.com/shop" https://example.com/shop-2 /srv/old/x /srv/old_bak Brand Brands' )
		);
		// A shorter address still matches where a longer one does not end.
		$r = new Replacer( Replacer::site_pairs( array( 'https://example.com' => 'https://new.test', 'https://example.com/shop' => 'https://shop.test' ), array(), true ) );
		$this->assertSame( 'https://new.test/shopping https://shop.test/x https://example.com.au https://new.test. Done', $r->replace( 'https://example.com/shopping https://example.com/shop/x https://example.com.au https://example.com. Done' ) );
		$this->assertSame( 's:25:"https://new.test/shopping";', $r->replace( 's:28:"https://example.com/shopping";' ) );
		$this->assertSame( 'mail@new.test and mail@example.com.au', $r->replace( 'mail@example.com and mail@example.com.au' ) );
	}
}
