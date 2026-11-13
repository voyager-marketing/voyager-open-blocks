<?php
/**
 * Stubs for tests/Unit/SkillPromptsTest.php, on top of tests/wp-stubs.php.
 *
 * The prompt ability reads this plugin's skills/ and registers itself with
 * wp_register_ability(), which records its name and arguments in
 * $GLOBALS['voyager_test_abilities'] here. Each stub is guarded, so one
 * another stub file or tests/wp-stubs.php adds first wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'VOYAGER_OPEN_BLOCKS_DIR' ) ) {
	define( 'VOYAGER_OPEN_BLOCKS_DIR', dirname( __DIR__ ) );
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Records the ability as registered, by name.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Its arguments.
	 */
	function wp_register_ability( string $name, array $args ): ?object {
		$GLOBALS['voyager_test_abilities'][ $name ] = $args;

		return null;
	}
}
