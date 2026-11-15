<?php
/**
 * Stubs for tests/Unit/StructuredDataTest.php, on top of tests/wp-stubs.php.
 *
 * - voyager_test_front_page  what is_front_page() returns, default true
 * - voyager_test_scripts     each wp_print_inline_script_tag() call: [ data, attributes ]
 *
 * And each SEO plugin's path from its graph to the page, as the plugin's own
 * source runs it, minus the printing. Each returns what the plugin would print,
 * or null when it prints nothing. None of the plugins is loaded: the pack only
 * hooks their filters, so their filters are all a test needs.
 *
 * Each stub is guarded, so one tests/wp-stubs.php adds later wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool {
		return $GLOBALS['voyager_test_front_page'] ?? true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
	/**
	 * @param string                     $data       Script contents.
	 * @param array<string, string|bool> $attributes Script attributes.
	 */
	function wp_print_inline_script_tag( string $data, array $attributes = [] ): void {
		$GLOBALS['voyager_test_scripts'][] = [ $data, $attributes ];
	}
}

/**
 * Rank Math 1.0.282, JsonLD::json_ld() in includes/modules/schema/class-jsonld.php:
 * `rank_math/json_ld` collects the entities, array_filter() drops the empty
 * ones, nothing prints when none is left, and what
 * `rank_math/schema/validated_data` returns is printed.
 *
 * @param array<string, mixed> $entities What Rank Math's own `rank_math/json_ld` callbacks add, keyed by entity.
 * @return array<mixed>|null What it prints, or null for nothing.
 */
function voyager_test_rank_math_prints( array $entities ): ?array {
	$data = array_filter( (array) apply_filters( 'rank_math/json_ld', $entities, null ) );
	if ( [] === $data ) {
		return null;
	}
	$data = apply_filters( 'rank_math/schema/validated_data', $data );
	return is_array( $data ) && [] !== $data ? $data : null;
}

/**
 * Yoast SEO 28.8: Schema_Presenter::present() in src/presenters/schema-presenter.php
 * returns '' when `wpseo_json_ld_output` gives false or [], before the graph
 * is built. Otherwise Schema_Generator::generate_graph() in
 * src/generators/schema-generator.php passes every piece through
 * `wpseo_schema_graph`, and the presenter prints it.
 *
 * @param list<array<string, mixed>> $pieces The pieces Yoast generated.
 * @return array<mixed>|null What it prints, or null for nothing.
 */
function voyager_test_yoast_prints( array $pieces ): ?array {
	$output = apply_filters( 'wpseo_json_ld_output', [ '_deprecated' => 'Please use the "wpseo_schema_*" filters.' ], '' );
	if ( [] === $output || false === $output ) {
		return null;
	}
	$graph = apply_filters( 'wpseo_schema_graph', $pieces, null );
	return is_array( $graph ) ? [ '@context' => 'https://schema.org', '@graph' => $graph ] : null;
}

/**
 * All in One SEO 5.0.5.1: Schema::get() in app/Common/Schema/Schema.php
 * returns '' when `aioseo_schema_disable` turns the schema off, before the
 * graphs are built. Otherwise Helpers::getOutput() in
 * app/Common/Schema/Helpers.php passes the graph through
 * `aioseo_schema_output`, and Head::wpHead() prints it.
 *
 * @param list<array<string, mixed>> $graphs The graphs AIOSEO generated.
 * @return array<mixed>|null What it prints, or null for nothing.
 */
function voyager_test_aioseo_prints( array $graphs ): ?array {
	if ( apply_filters( 'aioseo_schema_disable', false ) ) {
		return null;
	}
	$graph = apply_filters( 'aioseo_schema_output', $graphs );
	return is_array( $graph ) ? [ '@context' => 'https://schema.org', '@graph' => $graph ] : null;
}
