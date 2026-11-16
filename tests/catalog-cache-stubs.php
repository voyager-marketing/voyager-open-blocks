<?php
/**
 * Stubs for tests/Unit/CatalogCacheTest.php, on top of tests/wp-stubs.php.
 *
 * get-catalog keeps its parts in a transient, under a version that reads an
 * option, the active theme and the request's locale. Each stub is guarded, so
 * one another stub file adds first wins; tests/usage-stubs.php adds
 * update_option() and get_stylesheet() the same way.
 *
 * - voyager_test_transients  transient name => serialized value and expiration,
 *                            as the options table would hold them
 * - voyager_test_locale      what determine_locale() returns, default en_US
 * - voyager_test_stylesheet  what get_stylesheet() returns, default theme
 * - voyager_test_stylesheet_dir, voyager_test_template_dir  the active theme's
 *                            folder and its parent's, default a folder that is not there
 * - voyager_test_multisite   what is_multisite() returns, default false
 * - voyager_test_site_options  network option name => value
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'VOYAGER_OPEN_BLOCKS_VERSION' ) ) {
	define( 'VOYAGER_OPEN_BLOCKS_VERSION', '0.0.0-test' );
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		$kept = $GLOBALS['voyager_test_transients'][ $transient ] ?? null;

		return is_array( $kept ) ? unserialize( $kept['value'] ) : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['voyager_test_transients'][ $transient ] = [
			'value'      => serialize( $value ),
			'expiration' => $expiration,
		];

		return true;
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale(): string {
		return $GLOBALS['voyager_test_locale'] ?? 'en_US';
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		return $GLOBALS['voyager_test_stylesheet_dir'] ?? '/tmp/no-such-theme';
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['voyager_test_template_dir'] ?? '/tmp/no-such-theme';
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['voyager_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['voyager_test_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['voyager_test_site_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( string $name, mixed $value ): bool {
		$GLOBALS['voyager_test_site_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string {
		return $GLOBALS['voyager_test_stylesheet'] ?? 'theme';
	}
}
