<?php
/**
 * WP Rocket, as tests/Unit/WpRocketTest.php needs it: rocket_clean_files()
 * and rocket_clean_domain(), with their signatures in WP Rocket 3.24
 * (inc/functions/files.php), recording each call.
 *
 * - voyager_test_rocket_calls  every call: [ function, argument ]
 * - voyager_test_rocket_throw  'files' or 'domain', for a purge that throws
 *
 * Requiring this file is what loads WP Rocket, and a function cannot be
 * unloaded, so only a test that runs in its own process requires it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'rocket_clean_files' ) ) {
	/**
	 * @param string|array<mixed> $urls        URLs of cache files to delete.
	 * @param mixed               $filesystem  Unused.
	 * @param bool                $run_actions Unused.
	 * @return array<string, bool>
	 */
	function rocket_clean_files( $urls, $filesystem = null, $run_actions = true ): array {
		$GLOBALS['voyager_test_rocket_calls'][] = [ 'rocket_clean_files', $urls ];
		if ( 'files' === ( $GLOBALS['voyager_test_rocket_throw'] ?? null ) ) {
			throw new RuntimeException( 'Could not delete the cache files.' );
		}
		return array_fill_keys( array_map( 'strval', (array) $urls ), true );
	}
}

if ( ! function_exists( 'rocket_clean_domain' ) ) {
	/**
	 * @param string $lang       The language, or '' for every language.
	 * @param mixed  $filesystem Unused.
	 */
	function rocket_clean_domain( $lang = '', $filesystem = null ): bool {
		$GLOBALS['voyager_test_rocket_calls'][] = [ 'rocket_clean_domain', $lang ];
		if ( 'domain' === ( $GLOBALS['voyager_test_rocket_throw'] ?? null ) ) {
			throw new RuntimeException( 'Could not delete the cache directory.' );
		}
		return true;
	}
}
