<?php
/**
 * Founders Migration Website
 *
 * PSR-4 style autoloader. Namespace segments map to lowercase folders under
 * lib/, class names map to StudlyCase file names:
 * Founders\Migration\Archive\TarWriter => lib/archive/TarWriter.php
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Founders\\Migration\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$class = array_pop( $parts );
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = __DIR__ . '/lib/' . $dir . $class . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
