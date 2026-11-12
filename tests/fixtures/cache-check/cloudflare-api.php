<?php
/**
 * Plugin Name: Cache check: Cloudflare API lookups
 * Description: Answers the lookups the Cloudflare plugin makes before it purges, for npm run cache-check -- --plugin cloudflare --spy. Test fixture; never install it on a real site.
 *
 * The Cloudflare plugin 4.14.5 reaches Cloudflare through its HTTP API,
 * with wp_remote_request() (WordPressClientAPI::sendRequest() in
 * src/WordPress/WordPressClientAPI.php), so every call goes through
 * pre_http_request. Before it sends a purge it looks up the zone, and this
 * answers that lookup, and the two a page publish makes, the way
 * Cloudflare's API would, with the fields the plugin reads:
 *
 * - GET zones/?name=<domain>&status=active. WordPressClientAPI::getZoneTag()
 *   takes the `id` of the zone in `result` whose `name` is the domain.
 *   Answered with one zone, ZONE, named after the domain cloudflare/setup.php
 *   stored. `result_info.total_pages` is 1, so getPaginatedResults() asks
 *   for no second page.
 * - GET zones/<zone>/pagerules?status=active, which
 *   Hooks::purgeCacheByRelevantURLs() in src/WordPress/Hooks.php reads when
 *   a post is published. Answered with no page rules, as a site whose
 *   owner turned the plugin's caching on has: 4.14.5 never makes one.
 *   WordPressClientAPI::createPageRule() has no caller, and the
 *   recommended settings (PluginActions::applyDefaultSettings() in
 *   src/WordPress/PluginActions.php) set the zone's cache_level to
 *   aggressive, a zone setting, not a cache-everything page rule.
 * - GET zones/<zone>/settings/always_use_https, read in the same place
 *   (Hooks::zoneSettingAlwaysUseHTTPSEnabled()). Answered `off`.
 *
 * Every answer has `success` true, which WordPressClientAPI::responseOk()
 * checks. Every other request to api.cloudflare.com, the purge among them,
 * is left to harness.php, which records it and answers it with a 200 and
 * {"success":true} before it leaves the site. External HTTP is blocked too
 * (WP_HTTP_BLOCK_EXTERNAL), so nothing reaches Cloudflare.
 *
 * This filter runs before harness.php's, which still records each lookup it
 * answered.
 *
 * @package VoyagerOpenBlocks
 */

namespace VoyagerOpenCacheCheck\CloudflareApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The zone the lookups answer with. */
const ZONE = 'voyager-open-cache-check-zone';

/** Where the plugin keeps the domain it matched to its zone (src/WordPress/DataStore.php). */
const DOMAIN_OPTION = 'cloudflare_cached_domain_name';

/**
 * A response as wp_remote_request() returns one, with Cloudflare's envelope.
 *
 * @param array<string, mixed> $fields `result` and anything else.
 * @return array<string, mixed>
 */
function answer( array $fields ): array {
	return [
		'headers'  => [ 'content-type' => 'application/json' ],
		'body'     => (string) wp_json_encode(
			array_merge(
				[
					'success'  => true,
					'errors'   => [],
					'messages' => [],
				],
				$fields
			)
		),
		'response' => [
			'code'    => 200,
			'message' => 'OK',
		],
		'cookies'  => [],
		'filename' => null,
	];
}

/**
 * The answer to a lookup, or null for a request this does not answer.
 *
 * @param string $method The request method.
 * @param string $url    The request URL.
 * @return array<string, mixed>|null
 */
function lookup( string $method, string $url ): ?array {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || 'api.cloudflare.com' !== ( $parts['host'] ?? '' ) || 'GET' !== strtoupper( $method ) ) {
		return null;
	}

	$path = (string) ( $parts['path'] ?? '' );
	if ( '/client/v4/zones/' === $path || '/client/v4/zones' === $path ) {
		$domain = (string) get_option( DOMAIN_OPTION, '' );
		return answer(
			[
				'result'      => '' === $domain ? [] : [
					[
						'id'     => ZONE,
						'name'   => $domain,
						'status' => 'active',
					],
				],
				'result_info' => [
					'page'        => 1,
					'per_page'    => 20,
					'count'       => '' === $domain ? 0 : 1,
					'total_count' => '' === $domain ? 0 : 1,
					'total_pages' => 1,
				],
			]
		);
	}
	if ( '/client/v4/zones/' . ZONE . '/pagerules' === $path ) {
		return answer( [ 'result' => [] ] );
	}
	if ( '/client/v4/zones/' . ZONE . '/settings/always_use_https' === $path ) {
		return answer(
			[
				'result' => [
					'id'       => 'always_use_https',
					'value'    => 'off',
					'editable' => true,
				],
			]
		);
	}

	return null;
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$method = is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : 'GET';

		return lookup( $method, (string) $url ) ?? $pre;
	},
	10,
	3
);
