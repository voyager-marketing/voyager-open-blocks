<?php
/**
 * W3 Total Cache, as tests/Unit/W3TotalCacheTest.php needs it: w3tc_config(),
 * w3tc_flush_url(), w3tc_flush_posts() and w3tc_flush_all(), with the
 * signatures of w3-total-cache-api.php in 2.11.0. The settings object casts
 * as Config::get_boolean() in Config.php does.
 *
 * - voyager_test_w3tc_settings  the settings, key => value
 * - voyager_test_w3tc_calls     every flush: [ function, argument ]
 * - voyager_test_w3tc_throw     a URL whose flush throws, 'posts', or 'config'
 *
 * Requiring this file is what loads W3 Total Cache, and a function cannot be
 * unloaded, so only a test that runs in its own process requires it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'w3tc_config' ) ) {
	function w3tc_config(): object {
		if ( 'config' === ( $GLOBALS['voyager_test_w3tc_throw'] ?? null ) ) {
			throw new RuntimeException( 'Could not read master.php.' );
		}

		return new class() {
			public function get_boolean( string $key, bool $default_value = false ): bool {
				return (bool) ( $GLOBALS['voyager_test_w3tc_settings'][ $key ] ?? $default_value );
			}
		};
	}
}

if ( ! function_exists( 'w3tc_flush_url' ) ) {
	function w3tc_flush_url( string $w3tc_url, mixed $extras = null ): void {
		$GLOBALS['voyager_test_w3tc_calls'][] = [ 'w3tc_flush_url', $w3tc_url ];
		if ( ( $GLOBALS['voyager_test_w3tc_throw'] ?? null ) === $w3tc_url ) {
			throw new RuntimeException( "Could not flush {$w3tc_url}." );
		}
	}
}

if ( ! function_exists( 'w3tc_flush_posts' ) ) {
	function w3tc_flush_posts( mixed $extras = null ): void {
		$GLOBALS['voyager_test_w3tc_calls'][] = [ 'w3tc_flush_posts', null ];
		if ( 'posts' === ( $GLOBALS['voyager_test_w3tc_throw'] ?? null ) ) {
			throw new RuntimeException( 'Could not flush the page cache.' );
		}
	}
}

if ( ! function_exists( 'w3tc_flush_all' ) ) {
	function w3tc_flush_all( mixed $extras = null ): void {
		$GLOBALS['voyager_test_w3tc_calls'][] = [ 'w3tc_flush_all', null ];
	}
}
