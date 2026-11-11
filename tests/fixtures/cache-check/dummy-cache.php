<?php
/**
 * Plugin Name: Cache check: dummy cache
 * Description: A pretend page cache with a CDN in front of it, for npm run cache-check -- --plugin dummy --spy. Test fixture; never install it on a real site.
 *
 * Shaped like the caches --spy is for, the ones whose purge cannot be watched
 * from inside Playground: it has its own purge entry points (two actions a
 * real plugin would fire as it purges), and it tells its CDN through an HTTP
 * API (api.cdn.example, which the harness answers itself).
 *
 * It offers the pack an adapter through voyager_open_blocks_cache_adapters,
 * the methods of contract 2 in docs/v2-contracts.md. The adapter implements
 * VoyagerOpenBlocks\Cache\Adapter once the pack defines it, and the same
 * methods without the interface until then. Parameter types are left off on
 * purpose: an untyped parameter is compatible with whatever the interface
 * declares.
 *
 * @package VoyagerOpenBlocks
 */

namespace VoyagerOpenCacheCheck\DummyCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const API = 'https://api.cdn.example/v1/purge';

/**
 * The dummy cache's own entry point: purge these URLs.
 *
 * @param list<string> $urls Absolute URLs.
 */
function send_purge( array $urls ): void {
	do_action( 'dummy_cache_purge_urls', $urls );
	wp_remote_post(
		API,
		[
			'headers' => [ 'content-type' => 'application/json' ],
			'body'    => (string) wp_json_encode( [ 'files' => array_values( $urls ) ] ),
		]
	);
}

/**
 * The dummy cache's own entry point: purge everything.
 */
function send_purge_all(): void {
	do_action( 'dummy_cache_purge_all' );
	wp_remote_post(
		API,
		[
			'headers' => [ 'content-type' => 'application/json' ],
			'body'    => (string) wp_json_encode( [ 'purge_everything' => true ] ),
		]
	);
}

/**
 * Contract 2's adapter methods, shared by both forms of the adapter below.
 */
trait Adapter_Methods {
	public function id(): string {
		return 'dummy';
	}

	public function label(): string {
		return 'Dummy cache';
	}

	public function active(): bool {
		return true;
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * @param list<string> $urls Absolute URLs.
	 */
	public function purge_urls( $urls ): void {
		send_purge( array_values( array_filter( (array) $urls, 'is_string' ) ) );
	}

	public function purge_all(): void {
		send_purge_all();
	}
}

/**
 * The adapter, made once, after the pack has loaded (mu-plugins load first).
 *
 * @return object
 */
function adapter() {
	static $adapter = null;

	if ( null === $adapter ) {
		$adapter = interface_exists( '\VoyagerOpenBlocks\Cache\Adapter' )
			? new class() implements \VoyagerOpenBlocks\Cache\Adapter {
				use Adapter_Methods;
			}
			: new class() {
				use Adapter_Methods;
			};
	}

	return $adapter;
}

add_filter(
	'voyager_open_blocks_cache_adapters',
	static function ( $adapters ) {
		$adapters   = is_array( $adapters ) ? $adapters : [];
		$adapters[] = adapter();
		return $adapters;
	}
);
