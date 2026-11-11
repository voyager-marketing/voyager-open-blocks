<?php
/**
 * WP Super Cache, as tests/Unit/WpSuperCacheTest.php needs it: the two purge
 * functions of wp-cache-phase2.php (3.1.5), and the two multisite functions
 * of core the adapter calls.
 *
 * - voyager_test_wpsc_calls  every call: [ function, argument ]
 * - voyager_test_multisite   what is_multisite() returns, default false
 * - voyager_test_blog_id     what get_current_blog_id() returns, default 1
 *
 * Requiring this file is what loads WP Super Cache, and a function cannot be
 * unloaded, so only a test that runs in its own process requires it, and
 * never at the top of a test file.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
	function wpsc_delete_url_cache( string $url ): bool {
		$GLOBALS['voyager_test_wpsc_calls'][] = [ 'wpsc_delete_url_cache', $url ];
		return true;
	}
}

if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
	function wp_cache_clear_cache( int $blog_id = 0 ): void {
		$GLOBALS['voyager_test_wpsc_calls'][] = [ 'wp_cache_clear_cache', $blog_id ];
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['voyager_test_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['voyager_test_blog_id'] ?? 1 );
	}
}
