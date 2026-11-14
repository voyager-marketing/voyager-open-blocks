<?php
/**
 * Turns WP Super Cache on in a bench template, the way npm run cache-check
 * does, and prints what it did as JSON. Run by bench/run.mjs with
 * `wp eval-file <this file> <cache-check setup.php>`, never installed as a
 * plugin.
 *
 * The setup is cache-check's own, tests/fixtures/cache-check/wp-super-cache/
 * setup.php, required as it is: the version check, the settings it writes and
 * the PHP_SELF plugin Playground needs. Its first argument is that file's
 * path.
 *
 * One thing differs from cache-check, where WordPress lives in Playground's
 * own filesystem: a bench site is files on disk that WP-CLI sets up and
 * Playground then serves at /wordpress, and every run copies the template to
 * a folder of its own. WP Super Cache writes WPCACHEHOME into wp-config.php
 * as an absolute path (wp_cache_create_advanced_cache() in
 * inc/lifecycle.php), which here is the template's path on the host, so
 * advanced-cache.php would find no wp-cache-phase1.php in Playground and no
 * page would ever be served from the cache. This writes it again relative to
 * wp-config.php, with WP Super Cache's own wp_cache_replace_line(), so it
 * resolves wherever the site is. Its config file already names WPCACHEHOME
 * and the cache path relative to WP_CONTENT_DIR.
 *
 * It declares no strict_types: eval-file runs it through eval(), which
 * refuses the declaration.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$voyager_open_bench_setup = isset( $args[0] ) ? (string) $args[0] : '';
if ( '' === $voyager_open_bench_setup || ! is_file( $voyager_open_bench_setup ) ) {
	echo wp_json_encode(
		[
			'facts'    => [],
			'problems' => [ "No cache-check setup file at {$voyager_open_bench_setup}." ],
		]
	);
	return;
}

ob_start();
$voyager_open_bench_result = require $voyager_open_bench_setup;
$voyager_open_bench_output = trim( (string) ob_get_clean() );

$voyager_open_bench_facts    = (array) ( $voyager_open_bench_result['facts'] ?? [] );
$voyager_open_bench_problems = (array) ( $voyager_open_bench_result['problems'] ?? [] );
if ( '' !== $voyager_open_bench_output ) {
	$voyager_open_bench_facts['output'] = substr( $voyager_open_bench_output, 0, 2000 );
}

$voyager_open_bench_config = ABSPATH . 'wp-config.php';
$voyager_open_bench_line   = "define( 'WPCACHEHOME', __DIR__ . '/wp-content/plugins/wp-super-cache/' );";
if ( ! function_exists( 'wp_cache_replace_line' ) ) {
	$voyager_open_bench_problems[] = 'wp_cache_replace_line() is not defined, so WPCACHEHOME was not rewritten.';
} elseif ( ! wp_cache_replace_line( 'define *\( *\'WPCACHEHOME\'', $voyager_open_bench_line, $voyager_open_bench_config ) ) {
	$voyager_open_bench_problems[] = 'Could not rewrite WPCACHEHOME in wp-config.php.';
}
$voyager_open_bench_text = (string) file_get_contents( $voyager_open_bench_config );
$voyager_open_bench_homes = preg_match_all( '/^\s*define\s*\(\s*\'WPCACHEHOME\'.*$/m', $voyager_open_bench_text, $voyager_open_bench_found );
$voyager_open_bench_facts['WPCACHEHOME in wp-config.php'] = $voyager_open_bench_found[0] ?? [];
if ( 1 !== $voyager_open_bench_homes || trim( (string) $voyager_open_bench_found[0][0] ) !== $voyager_open_bench_line ) {
	$voyager_open_bench_problems[] = 'wp-config.php should define WPCACHEHOME once, relative to itself, and it holds: ' . implode( ' | ', (array) ( $voyager_open_bench_found[0] ?? [] ) );
}

echo wp_json_encode(
	[
		'facts'    => $voyager_open_bench_facts,
		'problems' => array_values( $voyager_open_bench_problems ),
	]
);
