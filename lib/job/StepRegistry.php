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
 * Maps job types to their ordered steps.
 *
 * Job state on disk stores only the type name, never class names, so a
 * tampered state.json cannot make the Runner instantiate arbitrary classes.
 */
final class StepRegistry {

	/**
	 * Type => ordered Step class names.
	 *
	 * @var array<string,string[]>
	 */
	private $types = array();

	/**
	 * Registers a job type.
	 *
	 * @param string   $type    Type name, for example "backup".
	 * @param string[] $classes Step class names, in order.
	 * @return void
	 * @throws \InvalidArgumentException When a class does not implement Step.
	 */
	public function register( string $type, array $classes ): void {
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) || ! is_subclass_of( $class, Step::class ) ) {
				throw new \InvalidArgumentException( sprintf( 'Step class for job type "%s" must implement %s.', $type, Step::class ) );
			}
		}
		$this->types[ $type ] = array_values( $classes );
	}

	/**
	 * Whether a type is registered.
	 *
	 * @param string $type Type name.
	 * @return bool
	 */
	public function has( string $type ): bool {
		return isset( $this->types[ $type ] );
	}

	/**
	 * Fresh step instances for a type.
	 *
	 * @param string $type Type name.
	 * @return Step[]
	 * @throws JobException For an unknown type.
	 */
	public function steps( string $type ): array {
		if ( ! $this->has( $type ) ) {
			throw new JobException( sprintf( 'Unknown job type "%s".', $type ) );
		}
		return array_map(
			static function ( string $class_name ): Step {
				return new $class_name();
			},
			$this->types[ $type ]
		);
	}
}
