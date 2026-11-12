<?php
/**
 * Checks that LiteSpeed Cache's page cache is on in a Playground site, by
 * the plugin's own test, and reports what it found.
 *
 * Required by ../setup.php. Written against LiteSpeed Cache 7.9.3, the
 * version scripts/lib/cache-check.mjs pins; every name used here is from
 * that version, and the harness stops if the installed version is another.
 *
 * LiteSpeed's page cache lives in the LiteSpeed web server, which the plugin
 * tells what to purge in a response header. The plugin turns its cache on,
 * the constant LITESPEED_ON, when two things hold (Conf::define_cache() in
 * src/conf.cls.php):
 *
 * - The server allows it: LITESPEED_ALLOWED, set in litespeed-cache.php when
 *   the server variable X-LSCACHE is set, as LiteSpeed's web server sets it
 *   when its cache is on. ../litespeed-server.php, an mu-plugin, sets it on
 *   every request.
 * - Its Enable Cache setting is on. A single site gets it on by default;
 *   this turns it on through the plugin's own litespeed_save_conf action if
 *   it is off.
 *
 * Playground runs PHP with the SAPI name `cli`, and litespeed-cache.php
 * reads that as a WP-CLI run: it defines LITESPEED_CLI, which allows the
 * cache too, so the server variable is not what turns it on here. It also
 * means the plugin stores each purge in its queue option instead of sending
 * the header (Purge::_add() in src/purge.cls.php). The harness records the
 * actions Purge::purge_url() and Purge::purge_all() fire once the purge is
 * queued, litespeed_purged_link and litespeed_purged_all: LiteSpeed Cache
 * was told.
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

	if ( ! defined( 'LSCWP_V' ) || false === has_action( 'litespeed_purge_url' ) || false === has_action( 'litespeed_purge_all' ) ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'LiteSpeed Cache is not active: LSCWP_V is not defined, or nothing hooks litespeed_purge_url and litespeed_purge_all.' ],
		];
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data                             = get_plugin_data( WP_PLUGIN_DIR . '/litespeed-cache/litespeed-cache.php', false, false );
	$facts['litespeed-cache version'] = $data['Version'];

	if ( 1 !== (int) apply_filters( 'litespeed_conf', 'cache' ) ) {
		do_action( 'litespeed_save_conf', [ 'cache' => 1 ] );
	}

	$expect = [
		'Enable Cache is on'                   => 1 === (int) apply_filters( 'litespeed_conf', 'cache' ),
		'Disable All Features is off'          => ! apply_filters( 'litespeed_conf', 'debug-disable_all' ),
		'the X-LSCACHE mu-plugin is in place'  => is_file( WPMU_PLUGIN_DIR . '/voyager-open-cache-check-litespeed-server.php' ),
		'LITESPEED_ON is defined'              => defined( 'LITESPEED_ON' ),
		'LITESPEED_DISABLE_ALL is not defined' => ! defined( 'LITESPEED_DISABLE_ALL' ),
	];
	$facts['litespeed-cache checks'] = $expect;
	// For the report: Playground's SAPI is cli, so this is true here.
	$facts['litespeed-cache LITESPEED_CLI'] = defined( 'LITESPEED_CLI' );

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
