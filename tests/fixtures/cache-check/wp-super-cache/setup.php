<?php
/**
 * Turns WP Super Cache on in a Playground site with its own code, and reports
 * what the next request will load.
 *
 * Required by ../setup.php. Written against WP Super Cache 3.1.5, the version
 * scripts/lib/cache-check.mjs pins; every function named here is from that
 * version, and the harness stops if the installed version is another.
 *
 * Playground's installPlugin step has already activated the plugin, which
 * runs wpsupercache_activate() (inc/lifecycle.php): it creates the cache
 * directory, writes WPCACHEHOME into wp-config.php and copies
 * advanced-cache.php into wp-content, copies wp-cache-config-sample.php to
 * wp-content/wp-cache-config.php, and adds define( 'WP_CACHE', true ) to
 * wp-config.php. This runs those four checks again, which are idempotent, so
 * a failure is reported here and not guessed at later. Then it does what
 * turning caching on under Settings > WP Super Cache does:
 *
 * - wp_cache_enable() writes $cache_enabled = true into the config file.
 *   The sample ships it false.
 * - wp_super_cache_enable() makes sure $super_cache_enabled is true.
 * - The two lines wp_cache_manager() (inc/admin-ui.php) writes whenever the
 *   settings page loads: $wp_cache_slash_check, 1 when permalinks end in a
 *   slash, and $wp_cache_home_path. Without $wp_cache_slash_check,
 *   wp_cache_serve_cache_file() never serves a cached permalink that ends
 *   in a slash.
 * - $wpsc_served_header = 1, the Advanced setting that adds an
 *   X-WP-Super-Cache header to a page served from the cache: a second
 *   signal, beside the harness's own render marker.
 *
 * $wp_cache_mod_rewrite stays 0, as the sample ships it: "simple" mode, in
 * which wp-cache-phase1.php serves stored pages from PHP. Expert mode serves
 * them through Apache rewrite rules, which Playground does not run.
 *
 * With --lockdown it turns on Lock Down as the settings page does
 * (wp_update_lock_down() in inc/settings-forms.php): it writes
 * `if ( ! defined( 'WPLOCKDOWN' ) ) define( 'WPLOCKDOWN', '1' );` into the
 * config file. Lock Down keeps the cached pages through a traffic spike:
 * wpsc_register_post_hooks() (wp-cache-phase2.php) does not hook
 * clean_post_cache while it is on, and the pack's adapter stands down too.
 *
 * Last, it installs playground-php-self.php as a WP Super Cache plugin. See
 * that file for why.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	return [
		'facts'    => [],
		'problems' => [ 'WordPress is not loaded.' ],
	];
}

// ../setup.php requires this from inside its own function, where $config
// holds the run's options.
$voyager_open_cache_check_lock_down = isset( $config ) && is_array( $config ) && ! empty( $config['lock_down'] );

return ( static function ( bool $lock_down ): array {
	global $wp_cache_config_file;

	$facts    = [];
	$problems = [];

	if ( ! function_exists( 'wpsc_init' ) || ! function_exists( 'wp_cache_enable' ) ) {
		return [
			'facts'    => $facts,
			'problems' => [ 'WP Super Cache is not active: wpsc_init() or wp_cache_enable() is not defined.' ],
		];
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data                            = get_plugin_data( WP_PLUGIN_DIR . '/wp-super-cache/wp-cache.php', false, false );
	$facts['wp-super-cache version'] = $data['Version'];

	wpsc_init();

	// The activation checks print HTML notices when they fail.
	ob_start();
	$checks  = [
		'wp_cache_verify_cache_dir()'     => wp_cache_verify_cache_dir(),
		'wpsc_check_advanced_cache()'     => wpsc_check_advanced_cache(),
		'wp_cache_verify_config_file()'   => wp_cache_verify_config_file(),
		'wp_cache_check_global_config()'  => wp_cache_check_global_config(),
	];
	$notices = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
	foreach ( $checks as $check => $passed ) {
		if ( ! $passed ) {
			$problems[] = "{$check} returned false." . ( '' !== $notices ? " It said: {$notices}" : '' );
		}
	}

	wp_cache_enable();
	wp_super_cache_enable();
	$slash = '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ? 1 : 0;
	wp_cache_replace_line( '^ *\$wp_cache_slash_check', "\$wp_cache_slash_check = {$slash};", $wp_cache_config_file );
	$home      = wp_parse_url( site_url() );
	$home_path = trailingslashit( is_array( $home ) && isset( $home['path'] ) ? $home['path'] : '' );
	wp_cache_setting( 'wp_cache_home_path', $home_path );
	wp_cache_setting( 'wpsc_served_header', 1 );
	if ( $lock_down ) {
		wp_cache_replace_line( '^.*WPLOCKDOWN', "if ( ! defined( 'WPLOCKDOWN' ) ) define( 'WPLOCKDOWN', '1' );", $wp_cache_config_file );
	}
	$facts['wp-super-cache lock down'] = $lock_down;

	$shim_dir = WP_PLUGIN_DIR . '/wp-super-cache-plugins';
	wp_mkdir_p( $shim_dir );
	if ( ! copy( __DIR__ . '/playground-php-self.php', $shim_dir . '/voyager-open-cache-check-php-self.php' ) ) {
		$problems[] = "Could not copy playground-php-self.php into {$shim_dir}.";
	}

	// Read back what the next request will load.
	$config    = (string) file_get_contents( $wp_cache_config_file );
	$wp_config = (string) file_get_contents( ABSPATH . 'wp-config.php' );
	$expect    = [
		'WP_CACHE is true in wp-config.php'      => 1 === preg_match( '/^\s*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)/mi', $wp_config ),
		'WPCACHEHOME is in wp-config.php'        => str_contains( $wp_config, 'WPCACHEHOME' ),
		'advanced-cache.php is WP Super Cache\'s' => 'WPSC' === wpsc_identify_advanced_cache(),
		'$cache_enabled = true'                  => 1 === preg_match( '/^\s*\$cache_enabled\s*=\s*true\s*;/m', $config ),
		'$super_cache_enabled = true'            => 1 === preg_match( '/^\s*\$super_cache_enabled\s*=\s*true\s*;/m', $config ),
		'$wp_cache_mod_rewrite = 0'              => 1 === preg_match( '/^\s*\$wp_cache_mod_rewrite\s*=\s*0\s*;/m', $config ),
		'$wp_cache_slash_check = 1'              => 1 === preg_match( '/^\s*\$wp_cache_slash_check\s*=\s*1\s*;/m', $config ),
		'$wpsc_served_header = 1'                => 1 === preg_match( '/^\s*\$wpsc_served_header\s*=\s*1\s*;/m', $config ),
		'the PHP_SELF plugin is in place'        => is_file( $shim_dir . '/voyager-open-cache-check-php-self.php' ),
		'Lock Down is ' . ( $lock_down ? 'on' : 'off' ) => $lock_down === str_contains( $config, "define( 'WPLOCKDOWN', '1' );" ),
	];
	foreach ( $expect as $what => $true ) {
		if ( ! $true ) {
			$problems[] = "Expected {$what} after setup.";
		}
	}
	$facts['wp-super-cache checks'] = $expect;

	return [
		'facts'    => $facts,
		'problems' => $problems,
	];
} )( $voyager_open_cache_check_lock_down );
