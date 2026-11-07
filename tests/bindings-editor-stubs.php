<?php
/**
 * Stubs for tests/Unit/BindingsEditorTest.php, on top of tests/wp-stubs.php.
 *
 * Kept apart so the shared stubs stay as they are. Each is guarded, so a stub
 * that tests/wp-stubs.php adds later wins. The tests build their expectations
 * with these same functions, so they hold whatever URLs the stubs return.
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

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://example.com/wp-content/plugins/' . basename( dirname( $plugin ) ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}
