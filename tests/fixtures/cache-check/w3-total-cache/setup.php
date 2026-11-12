<?php
/**
 * Turns on W3 Total Cache's page cache in a Playground site as its settings
 * screen saves it, and reports it by the plugin's own test.
 *
 * Required by ../setup.php. Written against W3 Total Cache 2.11.0, the
 * version scripts/lib/cache-check.mjs pins; every name used here is from
 * that version, and the harness stops if the installed version is another.
 *
 * W3 Total Cache keeps its settings in a file, not an option: master.php in
 * W3TC_CONFIG_DIR, wp-content/w3tc-config (Config::util_config_filename() in
 * Config.php). Its General Settings screen reads the form into a new Config
 * (Generic_AdminActions_Default::_w3tc_save_options_process()) and
 * Util_Admin::config_save() saves it with Config::save(), which writes
 * master.php (ConfigCompiler::save(), ConfigUtil::save_item()). This does
 * the same with Page Cache on, and leaves the rest at W3 Total Cache's
 * defaults:
 *
 * - pgcache.engine stays file_generic, "Disk: Enhanced". With it, the
 *   screen turns Page Cache back off on a site without pretty permalinks,
 *   so this checks they are on.
 * - cluster.messagebus.enabled stays off. CacheFlush::__construct() in
 *   CacheFlush.php then flushes through CacheFlush_Locally, which fires the
 *   actions the harness records: w3tc_flush_url and w3tc_flush_posts. With
 *   it on, every flush goes to Amazon SNS instead.
 *
 * It writes no advanced-cache.php and no WP_CACHE, which the screen's
 * environment step adds next (Root_Environment::fix_on_event()), so no
 * cached page is served here: --spy wants every load rendered. Root_Loader
 * loads the page cache's hooks (PgCache_Plugin) on the next request all the
 * same, because pgcache.enabled is on.
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

	if ( ! function_exists( 'w3tc_config' ) || ! class_exists( '\W3TC\Config' ) || ! class_exists( '\W3TC\Dispatcher' ) ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'W3 Total Cache is not active: w3tc_config() or W3TC\Config is missing.' ],
		];
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data                            = get_plugin_data( WP_PLUGIN_DIR . '/w3-total-cache/w3-total-cache.php', false, false );
	$facts['w3-total-cache version'] = $data['Version'];

	try {
		$config = new \W3TC\Config();
		$config->set( 'pgcache.enabled', true );
		$config->save();
	} catch ( Throwable $error ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'Could not save W3 Total Cache\'s settings: ' . $error->getMessage() ],
		];
	}

	// Read back what the next request will load, through the plugin's own
	// w3tc_config(), which serves one Config per request.
	\W3TC\Dispatcher::reset_config();
	$saved = w3tc_config();
	$file  = \W3TC\Config::util_config_filename( 0, false );

	$expect = [
		'master.php holds the settings'                 => is_file( $file ),
		'Page Cache is on, by w3tc_config()'            => true === $saved->get_boolean( 'pgcache.enabled' ),
		'the page cache engine is Disk: Enhanced'       => 'file_generic' === $saved->get_string( 'pgcache.engine' ),
		'pretty permalinks are on'                      => '' !== (string) get_option( 'permalink_structure' ),
		'flushes run locally: the message bus is off'   => false === $saved->get_boolean( 'cluster.messagebus.enabled' ),
		'w3tc_flush_url() and w3tc_flush_posts() exist' => function_exists( 'w3tc_flush_url' ) && function_exists( 'w3tc_flush_posts' ),
	];
	$facts['w3-total-cache config file'] = $file;
	$facts['w3-total-cache checks']      = $expect;

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
