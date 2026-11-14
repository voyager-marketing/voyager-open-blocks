<?php
/**
 * LocalBusiness structured data on the front page, from Site Data.
 *
 * On the front page, wp_head prints one `<script type="application/ld+json">`
 * holding a schema.org LocalBusiness node: the business's name, home URL,
 * phone, email, postal address, social profiles, service area and founding
 * year, each from SiteData\values(). A property whose value is empty is left
 * out, and nothing is printed unless the name is set and at least one of the
 * phone, the email or the street address is.
 *
 * What Site Data cannot state safely is left out. The hours are free text
 * ("Monday to Friday, 8am to 5pm") and schema.org wants "Mo-Fr 08:00-17:00",
 * so a guessed parse would publish wrong hours. The rating is typed by hand
 * and calls no review service, so it is no aggregateRating. The credentials
 * and the licence are plain lines, and hasCredential wants a typed object.
 *
 * The filter `voyager_open_blocks_structured_data` gets the node and the Site
 * Data values. It returns the node, changed (a subtype such as Plumber in
 * `@type`), or [] to print nothing.
 *
 * The pack stands aside when an SEO plugin prints a LocalBusiness or an
 * Organization node for the site on the same request. It reads what each
 * plugin is about to print from the plugin's own schema filter, at the last
 * priority, rather than whether the plugin is active: an SEO plugin that is
 * installed and prints no such node (Rank Math before its setup wizard is
 * connected or skipped, Yoast SEO with no company logo) leaves the pack's
 * node in place.
 *
 * - Rank Math 1.0.282: JsonLD::json_ld() (includes/modules/schema/class-jsonld.php)
 *   runs on `rank_math/head`, which Head::head() fires on wp_head at priority
 *   1 (includes/frontend/class-head.php). It collects `rank_math/json_ld`, and
 *   prints what `rank_math/schema/validated_data` returns, keyed by entity.
 *   The site's entity is `publisher`: an Organization, a Person, or with the
 *   Local SEO module the business type chosen there, such as Plumber, with
 *   the @id home_url( '/#organization' ) (Local_Seo::organization() in
 *   includes/modules/local-seo/class-local-seo.php, Publisher::process() in
 *   includes/modules/schema/snippets/class-publisher.php).
 * - Yoast SEO 28.8: Schema_Generator::generate_graph()
 *   (src/generators/schema-generator.php) passes every piece through
 *   `wpseo_schema_graph`, and Schema_Presenter::present()
 *   (src/presenters/schema-presenter.php) prints it from the `wpseo_head`
 *   action, on wp_head at priority 1 (src/integrations/front-end-integration.php).
 *   The site is an Organization with the @id `#organization`
 *   (src/generators/schema/organization.php) when it represents a company with
 *   a name and a logo, or a Person typed [ 'Person', 'Organization' ]
 *   (src/generators/schema/person.php). Yoast Local SEO is not on
 *   WordPress.org. Its developer documentation says it merges the primary
 *   location into that Organization piece, as [ 'Organization', 'Place',
 *   'Dentist' ], so it reaches the same filter.
 * - All in One SEO 5.0.5.1: Helpers::getOutput() (app/Common/Schema/Helpers.php)
 *   passes the graph through `aioseo_schema_output`, and Head::wpHead()
 *   (app/Common/Main/Head.php) prints it on wp_head at priority 1. The site is
 *   KgOrganization, an Organization with the @id `#organization`, by default,
 *   or KgPerson (app/Common/Schema/Graphs/KnowledgeGraph/).
 *
 * The pack prints at wp_head priority PRIORITY, after all three. A Site Data
 * change refreshes the front page in page caches already: the purge adds it
 * to every Site Data purge (site_data_plan() in inc/freshness/purge.php).
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\StructuredData;

use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\SiteData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wp_head priority the node prints at: after the SEO plugins, which print at 1.
 */
const PRIORITY = 99;

/**
 * The class on the pack's script tag, so a reader can tell it from another plugin's.
 */
const SCRIPT_CLASS = 'voyager-open-blocks-structured-data';

/**
 * The Site Data keys of the social profiles, in the order sameAs lists them.
 */
const SOCIAL = [ 'social_facebook', 'social_instagram', 'social_linkedin', 'social_x' ];

/**
 * Text as JSON-LD carries it: entities decoded once, trimmed.
 *
 * The Site Title is stored through esc_html() ("Joe&#039;s &amp; Co"), and the
 * voyager-blocks plugin may store Site Data the same way, while this plugin
 * stores it raw. Decoding once reads both the same, as ContactDetails\text() does.
 *
 * @param string $value A value from SiteData\values().
 */
function text( string $value ): string {
	return trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * The LocalBusiness node for these Site Data values, or [] when they are too thin.
 *
 * @param array<string, string> $values Site Data, as SiteData\values() returns it.
 * @param string                $url    The home URL, with its trailing slash.
 * @return array<string, mixed>
 */
function node( array $values, string $url ): array {
	$get = static fn ( string $key ): string => text( $values[ $key ] ?? '' );

	$address = array_filter(
		[
			'@type'           => 'PostalAddress',
			'streetAddress'   => $get( 'address' ),
			'addressLocality' => $get( 'address_city' ),
			'addressRegion'   => $get( 'address_state' ),
			'postalCode'      => $get( 'address_zip' ),
		],
		static fn ( string $part ): bool => '' !== $part
	);

	$same_as = [];
	foreach ( SOCIAL as $key ) {
		$link = trim( $values[ $key ] ?? '' );
		if ( 1 === preg_match( '#^https?://#i', $link ) ) {
			$same_as[] = $link;
		}
	}

	$areas = array_values(
		array_filter(
			array_map( 'trim', explode( "\n", $get( 'service_area' ) ) ),
			static fn ( string $area ): bool => '' !== $area
		)
	);

	$node = [
		'@context'     => 'https://schema.org',
		'@type'        => 'LocalBusiness',
		'@id'          => $url . '#localbusiness',
		'name'         => $get( 'company' ),
		'url'          => $url,
		'telephone'    => $get( 'phone' ),
		// email_link is set only for an address is_email() accepts.
		'email'        => '' !== ( $values['email_link'] ?? '' ) ? $get( 'email' ) : '',
		'address'      => count( $address ) > 1 ? $address : [],
		'sameAs'       => $same_as,
		'areaServed'   => $areas,
		'foundingDate' => $get( 'founded' ),
	];

	$node = array_filter( $node, static fn ( mixed $value ): bool => '' !== $value && [] !== $value );

	$reachable = isset( $node['telephone'] ) || isset( $node['email'] ) || isset( $address['streetAddress'] );

	return isset( $node['name'] ) && $reachable ? $node : [];
}

/**
 * The SEO plugins seen printing a node for the site on this request.
 *
 * @param string $plugin Record this plugin, by name. '' only reads.
 * @param bool   $reset  Forget every plugin recorded, for the tests.
 * @return list<string>
 */
function printed_by( string $plugin = '', bool $reset = false ): array {
	/** @var list<string> $seen */
	static $seen = [];

	if ( $reset ) {
		$seen = [];
	}

	if ( '' !== $plugin && ! in_array( $plugin, $seen, true ) ) {
		$seen[] = $plugin;
	}

	return $seen;
}

/**
 * The type names of a node, read from `@type` (a string or a list).
 *
 * @param array<mixed> $node A node.
 * @return list<string>
 */
function types( array $node ): array {
	$types = [];

	foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
		$type = Cast\to_string( $type );
		// A full IRI or a prefixed name is the same type: https://schema.org/LocalBusiness, schema:LocalBusiness.
		$types[] = (string) preg_replace( '#^(?:https?://schema\.org/|schema:)#', '', $type );
	}

	return $types;
}

/**
 * Whether a node stands for the site's business: typed Organization or
 * LocalBusiness, or carrying the `#organization` @id the three plugins give
 * the site's entity, whatever business type they print it as.
 *
 * @param mixed $node A top-level node of a plugin's graph.
 */
function is_site_entity( mixed $node ): bool {
	if ( ! is_array( $node ) ) {
		return false;
	}

	if ( [] !== array_intersect( [ 'Organization', 'LocalBusiness' ], types( $node ) ) ) {
		return true;
	}

	return str_ends_with( Cast\to_string( $node['@id'] ?? '' ), '#organization' );
}

/**
 * Whether a graph holds a node for the site's business at its top level.
 *
 * Rank Math's graph is keyed by entity, Yoast's and AIOSEO's are lists; a
 * graph wrapped in `@graph` is read from inside.
 *
 * @param mixed $graph What the plugin's filter gives.
 */
function holds_site_entity( mixed $graph ): bool {
	if ( ! is_array( $graph ) ) {
		return false;
	}

	if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
		$graph = $graph['@graph'];
	}

	foreach ( $graph as $node ) {
		if ( is_site_entity( $node ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Record a plugin when its graph holds the site's business, and hand the graph back unchanged.
 *
 * @param string $plugin The plugin's name.
 * @param mixed  $graph  What its filter gives.
 * @return mixed The same graph.
 */
function observe( string $plugin, mixed $graph ): mixed {
	if ( holds_site_entity( $graph ) ) {
		printed_by( $plugin );
	}

	return $graph;
}

/**
 * `rank_math/schema/validated_data`: what Rank Math prints, keyed by entity.
 *
 * @param mixed $data JSON-LD data.
 * @return mixed The same data.
 */
function observe_rank_math( mixed $data ): mixed {
	return observe( 'Rank Math', $data );
}

/**
 * `wpseo_schema_graph`: Yoast SEO's graph, Yoast Local SEO's pieces included.
 *
 * @param mixed $graph The graph.
 * @return mixed The same graph.
 */
function observe_yoast( mixed $graph ): mixed {
	return observe( 'Yoast SEO', $graph );
}

/**
 * `aioseo_schema_output`: All in One SEO's graph.
 *
 * @param mixed $graph The graph.
 * @return mixed The same graph.
 */
function observe_aioseo( mixed $graph ): mixed {
	return observe( 'All in One SEO', $graph );
}

add_filter( 'rank_math/schema/validated_data', __NAMESPACE__ . '\observe_rank_math', PHP_INT_MAX );
add_filter( 'wpseo_schema_graph', __NAMESPACE__ . '\observe_yoast', PHP_INT_MAX );
add_filter( 'aioseo_schema_output', __NAMESPACE__ . '\observe_aioseo', PHP_INT_MAX );

/**
 * The JSON to print on this request, or '' for none: not the front page, an
 * SEO plugin printed the site's node, Site Data is too thin, or the filter
 * returned [].
 */
function json(): string {
	if ( ! is_front_page() || [] !== printed_by() ) {
		return '';
	}

	$values = SiteData\values();
	$node   = node( $values, home_url( '/' ) );

	if ( [] === $node ) {
		return '';
	}

	/**
	 * Filter the LocalBusiness node printed on the front page.
	 *
	 * Return it changed, such as with `@type` set to a subtype like Plumber,
	 * or [] to print nothing.
	 *
	 * @param array<string, mixed>  $node   The node, empty properties left out.
	 * @param array<string, string> $values Site Data, as SiteData\values() returns it.
	 */
	/** @var mixed $node A callback may return anything. */
	$node = apply_filters( 'voyager_open_blocks_structured_data', $node, $values );

	if ( ! is_array( $node ) || [] === $node ) {
		return '';
	}

	// JSON_HEX_TAG writes < and > as < and >, so no value can close the script tag.
	return Cast\to_string( wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) );
}

/**
 * wp_head: print the node.
 */
function render(): void {
	$json = json();

	if ( '' !== $json ) {
		wp_print_inline_script_tag(
			$json,
			[
				'type'  => 'application/ld+json',
				'class' => SCRIPT_CLASS,
			]
		);
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\render', PRIORITY );
