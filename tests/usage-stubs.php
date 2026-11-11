<?php
/**
 * Stubs for tests/Unit/UsageTest.php, on top of tests/wp-stubs.php.
 *
 * inc/freshness/usage.php registers its activation and deactivation hooks
 * when it is loaded. Each stub is guarded, so one tests/wp-stubs.php adds
 * later wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'VOYAGER_OPEN_BLOCKS_DIR' ) ) {
	define( 'VOYAGER_OPEN_BLOCKS_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'VOYAGER_OPEN_BLOCKS_FILE' ) ) {
	define( 'VOYAGER_OPEN_BLOCKS_FILE', dirname( __DIR__ ) . '/voyager-open-blocks.php' );
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, mixed $callback ): void {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, mixed $callback ): void {}
}
