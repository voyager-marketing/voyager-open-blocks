<?php
/**
 * Turns on Nginx Helper's purge in a Playground site as its settings page
 * does, and reports it by the plugin's own test.
 *
 * Required by ../setup.php. Written against Nginx Helper 2.4.1, the version
 * scripts/lib/cache-check.mjs pins; every function named here is from that
 * version, and the harness stops if the installed version is another.
 *
 * Nginx Helper tells nginx's FastCGI cache, on the server, to drop a page.
 * It purges nothing until Enable Purge is on: its own purges each return
 * first when the `enable_purge` setting is empty (Purger::purge_post() in
 * admin/class-purger.php). Settings > Nginx Helper saves the settings as one
 * site option, rt_wp_nginx_helper_options
 * (admin/partials/nginx-helper-general-options.php), and this saves the same
 * option with:
 *
 * - enable_purge on.
 * - cache_method enable_fastcgi, so the plugin builds a FastCGI_Purger
 *   (Nginx_Helper::define_admin_hooks() in includes/class-nginx-helper.php).
 * - purge_method unlink_files, "Delete local server cache files", which
 *   deletes nginx's cache files under RT_WP_NGINX_HELPER_CACHE_PATH. The
 *   harness writes that constant into wp-config.php before WordPress first
 *   loads (`consts` in PLUGINS), as such a host does. The default,
 *   get_request, sends a GET to /purge/<path> on the site's host without its
 *   port (FastCGI_Purger::purge_base_url()), which here would reach
 *   whatever listens on port 80 of the machine running the check.
 *
 * No nginx runs here, so there is no cache file to delete:
 * Purger::delete_cache_file_for() finds none and returns. The harness
 * records the filter rt_nginx_helper_purge_url, which every purger's
 * purge_url() runs on the URL it was given, and the action
 * rt_nginx_helper_after_fastcgi_purge_all: Nginx Helper was told.
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
	$admin = $GLOBALS['nginx_helper_admin'] ?? null;

	if ( ! is_object( $admin ) || ! method_exists( $admin, 'nginx_helper_settings' ) || ! is_object( $GLOBALS['nginx_purger'] ?? null ) ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'Nginx Helper is not active: $nginx_helper_admin or $nginx_purger is not set.' ],
		];
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data                          = get_plugin_data( WP_PLUGIN_DIR . '/nginx-helper/nginx-helper.php', false, false );
	$facts['nginx-helper version'] = $data['Version'];

	$settings = array_merge(
		(array) $admin->nginx_helper_settings(),
		[
			'enable_purge' => 1,
			'cache_method' => 'enable_fastcgi',
			'purge_method' => 'unlink_files',
		]
	);
	update_site_option( 'rt_wp_nginx_helper_options', $settings );
	if ( defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ) {
		wp_mkdir_p( (string) constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) );
	}

	// Read back what the next request will load.
	$saved  = (array) $admin->nginx_helper_settings();
	$expect = [
		'Enable Purge is on'                          => ! empty( $saved['enable_purge'] ),
		'the cache method is FastCGI'                 => 'enable_fastcgi' === ( $saved['cache_method'] ?? '' ),
		'the purge method is unlink_files'            => 'unlink_files' === ( $saved['purge_method'] ?? '' ),
		'RT_WP_NGINX_HELPER_CACHE_PATH is the harness\'s' => defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) && '/var/run/nginx-cache' !== constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' ),
		'the purger is a FastCGI_Purger'              => $GLOBALS['nginx_purger'] instanceof FastCGI_Purger,
	];
	$facts['nginx-helper cache path'] = defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ? constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) : null;
	$facts['nginx-helper checks']     = $expect;

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
