<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Job;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * WordPress wiring for the job engine.
 */
final class Jobs {

	/**
	 * Shared registry.
	 *
	 * @var StepRegistry|null
	 */
	private static $registry = null;

	/**
	 * Job storage under the plugin's storage folder.
	 *
	 * @return JobStore
	 */
	public static function store(): JobStore {
		return new JobStore( fmwp_storage_path() . '/jobs' );
	}

	/**
	 * Whether a job type restores a site.
	 *
	 * @param string $type Job type.
	 * @return bool
	 */
	public static function is_restore( string $type ): bool {
		return 'restore' === $type || 0 === strpos( $type, 'restore-' );
	}

	/**
	 * Registered job types.
	 *
	 * Built-in types are added here as they are implemented. Other code can
	 * register types with the `fmwp_register_job_types` action.
	 *
	 * @return StepRegistry
	 */
	public static function registry(): StepRegistry {
		if ( null === self::$registry ) {
			self::$registry = new StepRegistry();
			self::$registry->register(
				'backup',
				array(
					\Founders\Migration\Model\Export\ScanStep::class,
					\Founders\Migration\Model\Export\DatabaseStep::class,
					\Founders\Migration\Model\Export\FilesStep::class,
					\Founders\Migration\Model\Export\PackageStep::class,
				)
			);
			self::$registry->register(
				'restore',
				array(
					\Founders\Migration\Model\Import\CheckStep::class,
					\Founders\Migration\Model\Import\PartsStep::class,
					\Founders\Migration\Model\Import\ReplaceStep::class,
					\Founders\Migration\Model\Import\SwapStep::class,
					\Founders\Migration\Model\Import\FinalizeStep::class,
				)
			);
			self::$registry->register(
				'restore-wpress',
				array(
					\Founders\Migration\Model\Import\WpressCheckStep::class,
					\Founders\Migration\Model\Import\WpressDatabaseStep::class,
					\Founders\Migration\Model\Import\WpressFilesStep::class,
					\Founders\Migration\Model\Import\ReplaceStep::class,
					\Founders\Migration\Model\Import\SwapStep::class,
					\Founders\Migration\Model\Import\FinalizeStep::class,
				)
			);

			/**
			 * Fires once to let code register job types.
			 *
			 * @param StepRegistry $registry Registry.
			 */
			do_action( 'fmwp_register_job_types', self::$registry );
		}
		return self::$registry;
	}
}
