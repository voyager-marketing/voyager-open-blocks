<?php
/**
 * Stubs for tests/Unit/PurgeTest.php, on top of tests/wp-stubs.php and
 * tests/usage-stubs.php.
 *
 * - voyager_test_actions     every do_action() call: [ hook, ...args ]
 * - voyager_test_cleaned     post IDs passed to clean_post_cache()
 * - voyager_test_permalinks  post ID => permalink, read by get_permalink()
 *
 * Each stub is guarded, so one tests/wp-stubs.php adds later wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['voyager_test_actions'][] = [ $hook, ...$args ];
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( mixed $post ): void {
		$GLOBALS['voyager_test_cleaned'][] = $post;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( mixed $post = 0, bool $leavename = false ): string|false {
		return $GLOBALS['voyager_test_permalinks'][ $post ] ?? false;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	/**
	 * Minutes only, in core's short form: "1 min", "2 mins".
	 */
	function human_time_diff( int $from, int $to = 0 ): string {
		$mins = max( 1, (int) round( abs( $to - $from ) / 60 ) );
		return $mins . ( 1 === $mins ? ' min' : ' mins' );
	}
}

if ( ! function_exists( '_prime_post_caches' ) ) {
	function _prime_post_caches( array $ids, bool $update_term_cache = true, bool $update_meta_cache = true ): void {}
}
