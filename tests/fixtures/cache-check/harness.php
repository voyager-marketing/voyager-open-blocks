<?php
/**
 * Plugin Name: Voyager Open Blocks cache check
 * Description: Test fixture for npm run cache-check. Never install it on a real site.
 *
 * scripts/cache-check.mjs copies this into wp-content/mu-plugins of a
 * throwaway Playground site, through setup.php. It does four things:
 *
 * 1. Prints a marker unique to each render at the end of every front-end
 *    page, so the harness can tell a copy a cache served from a page
 *    WordPress rendered again.
 * 2. With no_purge set (--no-purge), empties the pack's purge through the two
 *    filters of contract 2 in docs/v2-contracts.md: no cache adapters, and no
 *    URLs to purge.
 * 3. Records every purge it can see, in the option
 *    voyager_open_cache_check_records: the pack's voyager_open_blocks_purge
 *    action, the cache plugin's own purge hooks (the `hooks` option, from
 *    PLUGINS in scripts/lib/cache-check.mjs), and every outgoing HTTP request
 *    through pre_http_request. Requests to other hosts are answered with a
 *    fake 200 and never leave the site. GET and DELETE
 *    /wp-json/voyager-open-cache-check/v1/records read and clear them. GET
 *    also names the pack's cache adapters, and whether each judges its
 *    cache on.
 * 4. With stand_in set (--stand-in), runs a purge of its own beside the
 *    pack's (inc/freshness/purge.php): when a voyager_site_* option changes,
 *    it finds the published posts whose blocks bind that key, and on shutdown
 *    runs them through contract 2 by hand: the purge_urls filter, the
 *    voyager_open_blocks_purge action, then every active adapter from
 *    voyager_open_blocks_cache_adapters. It is the positive control for
 *    --spy, not a model of the real purge: its URL lookup is a LIKE query.
 *
 * @package VoyagerOpenBlocks
 */

namespace VoyagerOpenCacheCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OPTION  = 'voyager_open_cache_check';
const RECORDS = 'voyager_open_cache_check_records';

/**
 * What setup.php stored for this run.
 *
 * @return array{no_purge: bool, stand_in: bool, hooks: array<string, array{all?: bool}>}
 */
function config(): array {
	static $config = null;

	if ( null === $config ) {
		$stored = get_option( OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$config = [
			'no_purge' => ! empty( $stored['no_purge'] ),
			'stand_in' => ! empty( $stored['stand_in'] ),
			'hooks'    => isset( $stored['hooks'] ) && is_array( $stored['hooks'] ) ? $stored['hooks'] : [],
		];
	}

	return $config;
}

// 1. The render marker. PHP_SELF and the SAPI are there for a failed run's
// report: see wp-super-cache/playground-php-self.php.
add_action(
	'wp_footer',
	static function (): void {
		printf(
			"\n<!-- voyager-open-cache-check rendered %s php_self=%s sapi=%s -->\n",
			esc_html( bin2hex( random_bytes( 8 ) ) ),
			esc_html( isset( $_SERVER['PHP_SELF'] ) ? (string) $_SERVER['PHP_SELF'] : '' ),
			esc_html( PHP_SAPI )
		);
	},
	PHP_INT_MAX
);

// 2. --no-purge.
if ( config()['no_purge'] ) {
	add_filter( 'voyager_open_blocks_cache_adapters', '__return_empty_array', PHP_INT_MAX );
	add_filter( 'voyager_open_blocks_purge_urls', '__return_empty_array', PHP_INT_MAX );
}

/**
 * Append one record.
 *
 * @param string               $kind   action, hook or http.
 * @param string               $name   The hook, or the host for http.
 * @param array<string, mixed> $fields urls, all, and anything else worth reporting.
 */
function record( string $kind, string $name, array $fields = [] ): void {
	$records   = get_option( RECORDS, [] );
	$records   = is_array( $records ) ? $records : [];
	$records[] = array_merge(
		[
			'kind' => $kind,
			'name' => $name,
			'urls' => [],
			'all'  => false,
		],
		$fields
	);
	update_option( RECORDS, array_slice( $records, -200 ), false );
}

/**
 * Every http(s) URL in a value, however deeply nested.
 *
 * @param mixed $value Hook arguments, a decoded request body.
 * @return list<string>
 */
function urls_in( $value ): array {
	if ( is_string( $value ) ) {
		return preg_match( '#^https?://#i', $value ) ? [ $value ] : [];
	}
	if ( is_object( $value ) ) {
		$value = get_object_vars( $value );
	}
	if ( ! is_array( $value ) ) {
		return [];
	}
	$urls = [];
	foreach ( $value as $item ) {
		$urls = array_merge( $urls, urls_in( $item ) );
	}
	return array_values( array_unique( $urls ) );
}

// 3. Records: the pack's purge action.
add_action(
	'voyager_open_blocks_purge',
	static function ( $urls = [], $context = [] ): void {
		record(
			'action',
			'voyager_open_blocks_purge',
			[
				'urls'    => urls_in( $urls ),
				'all'     => is_array( $context ) && ! empty( $context['purge_all'] ),
				'context' => is_array( $context ) ? $context : [],
			]
		);
	},
	PHP_INT_MAX,
	2
);

// The cache plugin's own purge hooks.
foreach ( config()['hooks'] as $hook => $spec ) {
	add_action(
		(string) $hook,
		static function ( ...$args ) use ( $hook, $spec ) {
			record(
				'hook',
				(string) $hook,
				[
					'urls' => urls_in( $args ),
					'all'  => is_array( $spec ) && ! empty( $spec['all'] ),
				]
			);
			return $args[0] ?? null;
		},
		PHP_INT_MAX,
		10
	);
}

// Outgoing HTTP, such as a CDN's purge API. Last, so it sees what any other
// filter decided.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		$body = is_array( $args ) && isset( $args['body'] ) ? $args['body'] : '';
		$text = is_string( $body ) ? $body : (string) wp_json_encode( $body );
		record(
			'http',
			$host,
			[
				'method' => is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : 'GET',
				'url'    => (string) $url,
				'body'   => substr( $text, 0, 4000 ),
				'urls'   => urls_in( is_string( $body ) ? json_decode( $body, true ) : $body ),
			]
		);
		if ( false !== $pre || in_array( $host, [ '127.0.0.1', 'localhost' ], true ) ) {
			return $pre;
		}
		return [
			'headers'  => [],
			'body'     => '{"success":true}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	},
	PHP_INT_MAX,
	3
);

/**
 * The ids of the adapters the pack would use, for the report.
 *
 * @return list<string>
 */
function adapter_ids(): array {
	$ids = [];
	foreach ( (array) apply_filters( 'voyager_open_blocks_cache_adapters', [] ) as $adapter ) {
		$ids[] = is_object( $adapter ) && method_exists( $adapter, 'id' ) ? (string) $adapter->id() : gettype( $adapter );
	}
	return $ids;
}

/**
 * The adapters the pack would use with --no-purge's filter left out, and
 * whether each judges its cache on. The harness reads it to tell a cache the
 * setup left off from one that was never told: an adapter that judges its
 * cache off never purges, so the page going stale would prove nothing, with
 * --no-purge or without.
 *
 * @return list<array{id: string, active: bool}>
 */
function adapter_states(): array {
	$no_purge = config()['no_purge'];
	if ( $no_purge ) {
		remove_filter( 'voyager_open_blocks_cache_adapters', '__return_empty_array', PHP_INT_MAX );
	}
	try {
		$found = (array) apply_filters( 'voyager_open_blocks_cache_adapters', [] );
	} finally {
		if ( $no_purge ) {
			add_filter( 'voyager_open_blocks_cache_adapters', '__return_empty_array', PHP_INT_MAX );
		}
	}

	$states = [];
	foreach ( $found as $adapter ) {
		if ( is_object( $adapter ) && method_exists( $adapter, 'id' ) ) {
			$states[] = [
				'id'     => (string) $adapter->id(),
				'active' => method_exists( $adapter, 'active' ) && true === $adapter->active(),
			];
		}
	}
	return $states;
}

add_action(
	'rest_api_init',
	static function (): void {
		$admin = static function (): bool {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'voyager-open-cache-check/v1',
			'/records',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => static function (): array {
						$records = get_option( RECORDS, [] );
						return [
							'records'        => is_array( $records ) ? $records : [],
							'adapters'       => adapter_ids(),
							'adapter_states' => adapter_states(),
						];
					},
				],
				[
					'methods'             => 'DELETE',
					'permission_callback' => $admin,
					'callback'            => static function (): array {
						delete_option( RECORDS );
						return [ 'cleared' => true ];
					},
				],
			]
		);
	}
);

/**
 * The Site Data keys changed in this request, collected for the stand-in purge.
 *
 * @param string|null $key A key to add.
 * @return list<string>
 */
function pending( ?string $key = null ): array {
	static $keys = [];
	if ( null !== $key ) {
		$keys[ $key ] = true;
	}
	return array_keys( $keys );
}

/**
 * The stand-in purge, on shutdown: contract 2, run by hand.
 */
function stand_in_purge(): void {
	global $wpdb;

	$keys = pending();
	if ( ! $keys ) {
		return;
	}

	$post_ids = [];
	foreach ( $keys as $key ) {
		$like = '%' . $wpdb->esc_like( '"key":"' . $key . '"' ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s", $like ) ) as $id ) {
			$post_ids[] = (int) $id;
		}
	}
	$post_ids = array_values( array_unique( $post_ids ) );
	$urls     = array_values( array_filter( array_map( 'get_permalink', $post_ids ) ) );
	$context  = [
		'source'    => 'voyager/site-data',
		'keys'      => $keys,
		'post_ids'  => $post_ids,
		'templates' => [],
		'reason'    => 'site-data',
		'purge_all' => false,
	];

	$urls = (array) apply_filters( 'voyager_open_blocks_purge_urls', $urls, $context );
	if ( ! $urls ) {
		return;
	}
	do_action( 'voyager_open_blocks_purge', $urls, $context );

	foreach ( (array) apply_filters( 'voyager_open_blocks_cache_adapters', [] ) as $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'active' ) || ! $adapter->active() ) {
			continue;
		}
		if ( $adapter->supports_urls() ) {
			$adapter->purge_urls( $urls );
		} else {
			$adapter->purge_all();
		}
	}
}

// 4. --stand-in.
if ( config()['stand_in'] ) {
	add_action(
		'updated_option',
		static function ( $option, $old, $value ): void {
			if ( is_string( $option ) && str_starts_with( $option, 'voyager_site_' ) && $old !== $value ) {
				pending( substr( $option, strlen( 'voyager_site_' ) ) );
			}
		},
		10,
		3
	);
	add_action( 'shutdown', __NAMESPACE__ . '\stand_in_purge', 0 );
}
