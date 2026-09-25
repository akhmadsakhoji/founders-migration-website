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
	 * Whether a job type replaces the site's database or files (restore or reset).
	 *
	 * @param string $type Job type.
	 * @return bool
	 */
	public static function changes_site( string $type ): bool {
		return self::is_restore( $type ) || 'reset' === $type;
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
					\Founders\Migration\Model\Remote\UploadStep::class, // Only with a cloud storage.
				)
			);
			self::$registry->register( 'upload', array( \Founders\Migration\Model\Remote\UploadStep::class ) );
			self::$registry->register( 'download', array( \Founders\Migration\Model\Remote\DownloadStep::class ) );
			self::$registry->register(
				'pull',
				array(
					\Founders\Migration\Model\Pull\RemoteBackupStep::class,
					\Founders\Migration\Model\Pull\PullDownloadStep::class,
					\Founders\Migration\Model\Pull\PullVerifyStep::class,
					\Founders\Migration\Model\Pull\PullCleanupStep::class, // Only once the copy is proven intact.
				)
			);
			self::$registry->register(
				'restore-pull',
				array(
					\Founders\Migration\Model\Pull\RemoteBackupStep::class,
					\Founders\Migration\Model\Pull\PullDownloadStep::class,
					\Founders\Migration\Model\Import\CheckStep::class,
					\Founders\Migration\Model\Import\PartsStep::class,
					\Founders\Migration\Model\Import\ReplaceStep::class,
					\Founders\Migration\Model\Import\SwapStep::class,
					\Founders\Migration\Model\Import\FinalizeStep::class,
					\Founders\Migration\Model\Pull\PullCleanupStep::class, // The restore checked every part.
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
					\Founders\Migration\Model\Import\ReplaceStep::class, // Before the files: the site's files and database disagree for as short a time as possible.
					\Founders\Migration\Model\Import\WpressFilesStep::class,
					\Founders\Migration\Model\Import\SwapStep::class,
					\Founders\Migration\Model\Import\FinalizeStep::class,
				)
			);
			self::$registry->register(
				'reset',
				array(
					\Founders\Migration\Model\Reset\ResetDatabaseStep::class,
					\Founders\Migration\Model\Import\SwapStep::class,
					\Founders\Migration\Model\Reset\ResetFilesStep::class,
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
