<?php
/**
 * Prints, as JSON, the abilities an agent finds over MCP on this site: what
 * the MCP Adapter's discover tool lists to the admin. Run by bench/run.mjs
 * with `wp eval-file`, never installed as a plugin.
 *
 * It runs the adapter's own mcp-adapter/discover-abilities ability, the one
 * behind the mcp-adapter-discover-abilities tool, so the list is the tool's
 * answer and not a guess at its filter. The mu-plugins load under WP-CLI as
 * they do on a request, so the plugin group's list is the one with the pack's
 * abilities removed.
 *
 * It declares no strict_types: eval-file runs it through eval(), which
 * refuses the declaration.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_set_current_user( 1 );
$voyager_open_bench_discover = wp_get_ability( 'mcp-adapter/discover-abilities' );
if ( ! $voyager_open_bench_discover ) {
	echo wp_json_encode( [ 'error' => 'mcp-adapter/discover-abilities is not registered.' ] );
	return;
}
$voyager_open_bench_found = $voyager_open_bench_discover->execute();
if ( is_wp_error( $voyager_open_bench_found ) ) {
	echo wp_json_encode( [ 'error' => $voyager_open_bench_found->get_error_message() ] );
	return;
}
echo wp_json_encode(
	[
		'abilities' => array_values(
			array_map(
				static fn ( $ability ): string => (string) ( $ability['name'] ?? '' ),
				(array) ( $voyager_open_bench_found['abilities'] ?? [] )
			)
		),
	]
);
