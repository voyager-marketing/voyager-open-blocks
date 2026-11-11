<?php
/**
 * LiteSpeed Cache, as tests/Unit/LiteSpeedCacheTest.php needs it.
 *
 * do_action() records every call, as tests/purge-stubs.php does, and runs
 * the listeners in voyager_test_listeners, so LiteSpeed Cache can hear its
 * own actions: voyager_test_load_litespeed_cache() hooks litespeed_purge_url
 * and litespeed_purge_all, as API::init() in src/api.cls.php (7.9.3) does,
 * and defines LSCWP_V, and LITESPEED_ON when its page cache is on.
 *
 * - voyager_test_litespeed_calls  every purge LiteSpeed heard: [ action, argument ]
 * - voyager_test_litespeed_throw  a URL whose purge throws, or 'all'
 *
 * Require it before tests/purge-stubs.php, whose do_action() records only.
 * Constants cannot be undefined, so only a test that runs in its own process
 * requires it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['voyager_test_actions'][] = [ $hook, ...$args ];
		foreach ( $GLOBALS['voyager_test_listeners'][ $hook ] ?? [] as $listener ) {
			$listener( ...$args );
		}
	}
}

/**
 * Load LiteSpeed Cache, as its plugin file does.
 *
 * @param bool $cache_on Whether its page cache is on: LITESPEED_ON.
 */
function voyager_test_load_litespeed_cache( bool $cache_on = true ): void {
	define( 'LSCWP_V', '7.9.3' );
	if ( $cache_on ) {
		define( 'LITESPEED_ON', true );
	}

	$GLOBALS['voyager_test_listeners']['litespeed_purge_url'][] = static function ( $url ): void {
		$GLOBALS['voyager_test_litespeed_calls'][] = [ 'litespeed_purge_url', $url ];
		if ( ( $GLOBALS['voyager_test_litespeed_throw'] ?? null ) === $url ) {
			throw new RuntimeException( "Could not purge {$url}." );
		}
	};
	$GLOBALS['voyager_test_listeners']['litespeed_purge_all'][] = static function ( $reason = false ): void {
		$GLOBALS['voyager_test_litespeed_calls'][] = [ 'litespeed_purge_all', $reason ];
		if ( 'all' === ( $GLOBALS['voyager_test_litespeed_throw'] ?? null ) ) {
			throw new RuntimeException( 'Could not purge everything.' );
		}
	};
}
