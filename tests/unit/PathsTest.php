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

use Founders\Migration\Storage\Paths;
use Founders\Migration\Tests\TestCase;

/**
 * Protection files of the data folders.
 */
final class PathsTest extends TestCase {

	public function test_htaccess_denies_with_a_rewrite_rule_for_openlitespeed(): void {
		$htaccess = Paths::protection_files()['.htaccess'];
		$this->assertStringStartsWith( Paths::HTACCESS_MARKER . "\n", $htaccess );
		$this->assertStringContainsString( "RewriteRule .* - [F,L]", $htaccess );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
	}

	public function test_only_an_unchanged_htaccess_from_an_older_version_is_replaced(): void {
		$current = Paths::protection_files()['.htaccess'];
		$old     = implode( "\n", array( Paths::HTACCESS_MARKER, '<IfModule mod_authz_core.c>', '	Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', '	Order deny,allow', '	Deny from all', '</IfModule>', '' ) );

		$this->assertTrue( Paths::is_outdated( $this->make_file( 'a/.htaccess', $old ), $current ) );
		$this->assertTrue( Paths::is_outdated( $this->make_file( 'b/.htaccess', str_replace( "\n", "\r\n", $old ) ), $current ) );
		$this->assertFalse( Paths::is_outdated( $this->make_file( 'c/.htaccess', $current ), $current ) );
		$this->assertFalse( Paths::is_outdated( $this->make_file( 'd/.htaccess', $old . "# mine\n" ), $current ) );
		$this->assertFalse( Paths::is_outdated( $this->make_file( 'e/.htaccess', "Require all denied\n" ), $current ) );
		$this->assertFalse( Paths::is_outdated( $this->make_file( 'f/web.config', $old ), $current ) );
	}
}
