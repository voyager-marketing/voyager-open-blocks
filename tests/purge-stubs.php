<?php
/**
 * Stubs for tests/Unit/PurgeTest.php, on top of tests/wp-stubs.php and
 * tests/usage-stubs.php.
 *
 * - voyager_test_actions     every do_action() call: [ hook, ...args ]
 * - voyager_test_cleaned     post IDs passed to clean_post_cache()
 * - voyager_test_permalinks  post ID => permalink, read by get_permalink()
 * - voyager_test_blog_id     what get_current_blog_id() returns, default 1.
 *                            switch_to_blog() and restore_current_blog() set it.
 * - voyager_test_switches    each switch_to_blog() as `switch <id>`, and each
 *                            restore_current_blog() as `restore`
 * - voyager_test_recurrence  WP-Cron hook => recurrence, from wp_schedule_event()
 * - voyager_test_timezone    what wp_timezone() names, default UTC
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
	 * Minutes only, as core (wp-includes/formatting.php) words them: "1 minute", "2 minutes".
	 */
	function human_time_diff( int $from, int $to = 0 ): string {
		$mins = max( 1, (int) round( abs( $to - $from ) / 60 ) );
		return $mins . ( 1 === $mins ? ' minute' : ' minutes' );
	}
}

if ( ! function_exists( '_prime_post_caches' ) ) {
	function _prime_post_caches( array $ids, bool $update_term_cache = true, bool $update_meta_cache = true ): void {}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['voyager_test_blog_id'] ?? 1 );
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	/**
	 * As core's (wp-includes/ms-blogs.php): remembers the site it left, so
	 * restore_current_blog() goes back to it.
	 */
	function switch_to_blog( int $new_blog_id, mixed $deprecated = null ): bool {
		$GLOBALS['voyager_test_switch_stack'][] = get_current_blog_id();
		$GLOBALS['voyager_test_switches'][]     = "switch {$new_blog_id}";
		$GLOBALS['voyager_test_blog_id']        = $new_blog_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		$stack = $GLOBALS['voyager_test_switch_stack'] ?? [];
		if ( [] === $stack ) {
			return false;
		}
		$GLOBALS['voyager_test_blog_id']      = array_pop( $stack );
		$GLOBALS['voyager_test_switch_stack'] = $stack;
		$GLOBALS['voyager_test_switches'][]   = 'restore';
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ): bool {
		$GLOBALS['voyager_test_events'][ $hook ]     = $timestamp;
		$GLOBALS['voyager_test_recurrence'][ $hook ] = $recurrence;
		return true;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( $GLOBALS['voyager_test_timezone'] ?? 'UTC' );
	}
}
