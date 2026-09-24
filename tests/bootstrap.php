<?php
/**
 * Founders Migration Website
 *
 * Bootstrap for unit tests of the WordPress-independent library code.
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

define( 'FMWP_TESTS', true );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/loader.php';
require __DIR__ . '/TestCase.php';
