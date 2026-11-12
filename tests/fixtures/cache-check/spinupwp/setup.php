<?php
/**
 * Checks that SpinupWP's page cache is on in a Playground site, by the
 * plugin's own test, and reports what it found.
 *
 * Required by ../setup.php. Written against SpinupWP 1.9.1, the version
 * scripts/lib/cache-check.mjs pins; every function named here is from that
 * version, and the harness stops if the installed version is another.
 *
 * SpinupWP's page cache is nginx's FastCGI cache, on the server. The plugin
 * takes it to be on when SPINUPWP_CACHE_PATH is a constant or an environment
 * variable (Cache::is_page_cache_enabled() in src/Cache.php), as it is on a
 * SpinupWP site with page caching on. The harness writes the constant into
 * wp-config.php before WordPress first loads (`consts` in PLUGINS), so this
 * only checks it, through the plugin.
 *
 * No nginx runs here, so there is no cache file to delete. Cache::delete()
 * then asks SpinupWP's cache daemon on 127.0.0.1:7836, which is not there
 * either, and fails at once. Cache::purge_url() and
 * Cache::purge_page_cache() fire spinupwp_url_purged and
 * spinupwp_site_purged whether the delete worked or not, and those are what
 * the harness records: SpinupWP was told.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	return [
		'facts'    => [],
		'problems' => [ 'WordPress is not loaded.' ],
	];
}

return ( static function (): array {
	$facts = [];

	if ( ! function_exists( 'spinupwp' ) || ! function_exists( 'spinupwp_purge_url' ) || ! function_exists( 'spinupwp_purge_site' ) ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'SpinupWP is not active: spinupwp(), spinupwp_purge_url() or spinupwp_purge_site() is not defined.' ],
		];
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data                      = get_plugin_data( WP_PLUGIN_DIR . '/spinupwp/spinupwp.php', false, false );
	$facts['spinupwp version'] = $data['Version'];

	$plugin = spinupwp();
	$cache  = is_object( $plugin ) && isset( $plugin->cache ) && is_object( $plugin->cache ) ? $plugin->cache : null;

	$expect = [
		'SPINUPWP_CACHE_PATH is defined'           => defined( 'SPINUPWP_CACHE_PATH' ),
		'SpinupWP says its page cache is enabled' => null !== $cache && true === (bool) $cache->is_page_cache_enabled(),
	];
	$facts['spinupwp cache path'] = defined( 'SPINUPWP_CACHE_PATH' ) ? constant( 'SPINUPWP_CACHE_PATH' ) : null;
	$facts['spinupwp checks']     = $expect;

	$problems = [];
	foreach ( $expect as $what => $true ) {
		if ( ! $true ) {
			$problems[] = "Expected {$what} after setup.";
		}
	}

	return [
		'facts'    => $facts,
		'problems' => $problems,
	];
} )();
