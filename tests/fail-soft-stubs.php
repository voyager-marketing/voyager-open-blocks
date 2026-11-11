<?php
/**
 * wp_trigger_error(), as inc/cache/fail-soft.php calls it, for the cache
 * adapter tests.
 *
 * - voyager_test_triggered  every call: [ message, error level ]
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_trigger_error' ) ) {
	function wp_trigger_error( string $function_name, string $message, int $error_level = E_USER_NOTICE ): void {
		$GLOBALS['voyager_test_triggered'][] = [ $message, $error_level ];
	}
}
