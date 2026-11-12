<?php
/**
 * Turns on the Cloudflare plugin's page cache in a Playground site, and
 * checks by the plugin's own code that it would send a purge.
 *
 * Required by ../setup.php. Written against Cloudflare 4.14.5, the version
 * scripts/lib/cache-check.mjs pins; every name used here is from that
 * version, and the harness stops if the installed version is another.
 *
 * Hooks::purgeCacheEverything() in src/WordPress/Hooks.php sends the purge
 * only when it gets past three things, and this sets up each the way a site
 * connected to Cloudflare has it:
 *
 * - The page cache is on: Hooks::isPluginSpecificCacheEnabled() reads the
 *   option plugin_specific_cache, through DataStore::getPluginSetting() in
 *   src/WordPress/DataStore.php. The plugin's settings page saves it as an
 *   object of Plugin::createPluginSettingObject() (src/API/Plugin.php),
 *   through AbstractPluginActions::patchPluginSettings()
 *   (src/API/AbstractPluginActions.php), and the test is that its `value`
 *   is neither false nor 'off'. Seeded with 'on'. APO, the other setting
 *   that would do, is left off.
 * - A domain: WordPressAPI::getDomainList() in src/WordPress/WordPressAPI.php
 *   returns the one in the option cloudflare_cached_domain_name
 *   (DataStore::getDomainNameCache()), which the plugin stores once it has
 *   matched the site to its zone. Seeded with this site's host.
 * - A zone: WordPressClientAPI::getZoneTag() asks Cloudflare's API for it.
 *   ../cloudflare-api.php, an mu-plugin, answers through pre_http_request.
 *
 * No API key or token is seeded. Client::beforeSend() in src/API/Client.php
 * then sends an empty bearer token, and the harness answers the request
 * before it leaves, so it makes no difference here.
 *
 * The checks call the plugin's own protected methods, through reflection,
 * on the $cloudflareHooks instance its loader made (cloudflare.loader.php).
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
	$hooks = $GLOBALS['cloudflareHooks'] ?? null;

	if ( ! is_object( $hooks ) || ! is_a( $hooks, 'Cloudflare\APO\WordPress\Hooks' ) ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'Cloudflare is not active: $cloudflareHooks is not a Cloudflare\APO\WordPress\Hooks.' ],
		];
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data                        = get_plugin_data( WP_PLUGIN_DIR . '/cloudflare/cloudflare.php', false, false );
	$facts['cloudflare version'] = $data['Version'];

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	update_option(
		'plugin_specific_cache',
		[
			'id'          => 'plugin_specific_cache',
			'value'       => 'on',
			'editable'    => true,
			'modified_on' => gmdate( 'c' ),
		]
	);
	update_option( 'cloudflare_cached_domain_name', $host );

	// The lookups' answers, which later requests load as an mu-plugin.
	require_once WPMU_PLUGIN_DIR . '/voyager-open-cache-check-cloudflare-api.php';

	$problems = [];
	try {
		$cache_on = ( new ReflectionMethod( $hooks, 'isPluginSpecificCacheEnabled' ) )->invoke( $hooks );
		$domains  = ( new ReflectionProperty( $hooks, 'integrationAPI' ) )->getValue( $hooks )->getDomainList();
		$zone     = ( new ReflectionProperty( $hooks, 'api' ) )->getValue( $hooks )->getZoneTag( $host );
	} catch ( Throwable $error ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'Could not ask the Cloudflare plugin: ' . $error->getMessage() ],
		];
	}

	$expect = [
		'the page cache is on, by the plugin\'s own test' => true === $cache_on,
		'the domain list is this site\'s host'          => [ $host ] === $domains,
		'the zone lookup finds the fixture\'s zone'     => \VoyagerOpenCacheCheck\CloudflareApi\ZONE === $zone,
	];
	$facts['cloudflare domain'] = $host;
	$facts['cloudflare zone']   = $zone;
	$facts['cloudflare checks'] = $expect;

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
