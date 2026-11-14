<?php
/**
 * Stubs for tests/Unit/GeneratedArraysTest.php, on top of tests/wp-stubs.php
 * and tests/bindings-editor-stubs.php (plugins_url() and the plugin's
 * constants).
 *
 * register_block_pattern() and register_block_pattern_category() record what
 * they are given, by name and in order, in
 * $GLOBALS['voyager_test_block_patterns'] and
 * $GLOBALS['voyager_test_block_pattern_categories']. Each is guarded, so a
 * stub that tests/wp-stubs.php adds later wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

require_once __DIR__ . '/bindings-editor-stubs.php';

if ( ! function_exists( 'register_block_pattern' ) ) {
	/**
	 * @param array<string, mixed> $pattern_properties
	 */
	function register_block_pattern( string $pattern_name, array $pattern_properties ): bool {
		$GLOBALS['voyager_test_block_patterns'][ $pattern_name ] = $pattern_properties;
		return true;
	}
}

if ( ! function_exists( 'register_block_pattern_category' ) ) {
	/**
	 * @param array<string, mixed> $category_properties
	 */
	function register_block_pattern_category( string $category_name, array $category_properties ): bool {
		$GLOBALS['voyager_test_block_pattern_categories'][ $category_name ] = $category_properties;
		return true;
	}
}
