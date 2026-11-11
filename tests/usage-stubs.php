<?php
/**
 * Stubs for tests/Unit/UsageTest.php, on top of tests/wp-stubs.php.
 *
 * inc/freshness/usage.php registers its activation and deactivation hooks
 * when it is loaded, and its queue keeps post IDs in an option and runs on
 * WP-Cron. Each stub is guarded, so one tests/wp-stubs.php adds later wins.
 *
 * - voyager_test_options  option name => value, shared with get_option()
 * - voyager_test_events   WP-Cron hook => timestamp of its next single event
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

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, mixed $callback ): void {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, mixed $callback ): void {}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['voyager_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['voyager_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return $GLOBALS['voyager_test_events'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ): bool {
		$GLOBALS['voyager_test_events'][ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = [], bool $wp_error = false ): int {
		$had = isset( $GLOBALS['voyager_test_events'][ $hook ] );
		unset( $GLOBALS['voyager_test_events'][ $hook ] );
		return $had ? 1 : 0;
	}
}
