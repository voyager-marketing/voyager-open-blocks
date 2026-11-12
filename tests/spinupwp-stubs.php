<?php
/**
 * SpinupWP, as tests/Unit/SpinupWpTest.php needs it: spinupwp(),
 * spinupwp_purge_url() and spinupwp_purge_site() from functions.php (1.9.1),
 * and the page cache test of Cache::is_page_cache_enabled() in src/Cache.php,
 * copied: SPINUPWP_CACHE_PATH is a constant or an environment variable.
 *
 * - voyager_test_spinupwp_calls  every purge: [ function, argument ]
 * - voyager_test_spinupwp_throw  a URL whose purge throws, or 'site'
 *
 * Requiring this file is what loads SpinupWP, and a function cannot be
 * unloaded, so only a test that runs in its own process requires it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'spinupwp' ) ) {
	function spinupwp(): object {
		$GLOBALS['voyager_test_spinupwp'] ??= (object) [
			'cache' => new class() {
				public function is_page_cache_enabled(): bool {
					return defined( 'SPINUPWP_CACHE_PATH' ) || getenv( 'SPINUPWP_CACHE_PATH' );
				}
			},
		];

		return $GLOBALS['voyager_test_spinupwp'];
	}
}

if ( ! function_exists( 'spinupwp_purge_url' ) ) {
	function spinupwp_purge_url( string $url ): bool {
		$GLOBALS['voyager_test_spinupwp_calls'][] = [ 'spinupwp_purge_url', $url ];
		if ( ( $GLOBALS['voyager_test_spinupwp_throw'] ?? null ) === $url ) {
			throw new RuntimeException( "Could not delete the cache files of {$url}." );
		}
		return true;
	}
}

if ( ! function_exists( 'spinupwp_purge_site' ) ) {
	function spinupwp_purge_site(): bool {
		$GLOBALS['voyager_test_spinupwp_calls'][] = [ 'spinupwp_purge_site', null ];
		if ( 'site' === ( $GLOBALS['voyager_test_spinupwp_throw'] ?? null ) ) {
			throw new RuntimeException( 'Could not delete the cache directory.' );
		}
		return true;
	}
}
