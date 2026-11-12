<?php
/**
 * Plugin Name: Cache check: WP Rocket stand-in
 * Description: Stands in for WP Rocket's purge functions, for npm run cache-check -- --plugin wp-rocket --spy. Test fixture; never install it on a real site.
 *
 * WP Rocket is not on WordPress.org, so the harness cannot install it. This
 * defines the two functions the pack's WP Rocket adapter
 * (inc/cache/wp-rocket.php) calls, with the signatures they have in WP
 * Rocket 3.24, inc/functions/files.php:
 *
 * - rocket_clean_files( $urls, $filesystem = null, $run_actions = true )
 * - rocket_clean_domain( $lang = '', $filesystem = null )
 *
 * Each records its call through an action of its own, as dummy-cache.php
 * does, and the harness records those actions (`hooks` in PLUGINS): the
 * URLs rocket_clean_files() was given, or that rocket_clean_domain() was
 * called, which WP Rocket takes as every page of the site. Neither cleans
 * anything, and neither fires WP Rocket's own before_ and after_ actions,
 * since nothing was cleaned: rocket_clean_files() reports each URL as not
 * cleared, and rocket_clean_domain() returns false.
 *
 * mu-plugins load before plugins, so on a site with WP Rocket active this
 * file would define the functions first and WP Rocket would stop with a
 * fatal error. The harness only installs it on a throwaway Playground site.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'rocket_clean_files' ) ) {
	/**
	 * WP Rocket 3.24's rocket_clean_files(), recording its call.
	 *
	 * @param string|array<mixed>       $urls        URLs of cache files to be deleted.
	 * @param WP_Filesystem_Direct|null $filesystem  Unused.
	 * @param bool                      $run_actions Unused: recorded either way.
	 * @return array<string, bool> Each URL, and false: no cache was cleared.
	 */
	function rocket_clean_files( $urls, $filesystem = null, $run_actions = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WP Rocket's own name.
		$urls = array_values( array_filter( (array) $urls, 'is_string' ) );
		do_action( 'wp_rocket_stand_in_clean_files', $urls );

		return array_fill_keys( $urls, false );
	}
}

if ( ! function_exists( 'rocket_clean_domain' ) ) {
	/**
	 * WP Rocket 3.24's rocket_clean_domain(), recording its call.
	 *
	 * @param string                    $lang       The language to clean, or '' for every language.
	 * @param WP_Filesystem_Direct|null $filesystem Unused.
	 * @return bool False: nothing was cleaned.
	 */
	function rocket_clean_domain( $lang = '', $filesystem = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WP Rocket's own name.
		do_action( 'wp_rocket_stand_in_clean_domain', is_string( $lang ) ? $lang : '' );

		return false;
	}
}
