<?php
/**
 * The SpinupWP adapter, for contract 2 in docs/v2-contracts.md.
 *
 * SpinupWP's page cache is nginx's FastCGI cache, on the server. The
 * SpinupWP plugin purges it when a post is saved, deleted or commented on,
 * and does not hook clean_post_cache, so without this adapter a Site Data
 * change refreshes no page until the cache expires.
 *
 * Written against SpinupWP 1.9.1:
 *
 * - spinupwp_purge_url( $url ), in functions.php, calls Cache::purge_url()
 *   in src/Cache.php, which deletes the URL's cache files, with and without
 *   a trailing slash, or asks SpinupWP's cache daemon to when PHP cannot.
 * - spinupwp_purge_site(), in functions.php, calls
 *   Cache::purge_page_cache(), which deletes the whole cache directory. On a
 *   multisite network that is every site's pages: SpinupWP keeps one cache
 *   per install, and purges all of it itself when a post is published.
 * - Cache::is_page_cache_enabled() is SpinupWP's own test for its page
 *   cache: SPINUPWP_CACHE_PATH is a constant or an environment variable.
 *   spinupwp() returns the plugin, and its `cache` property the Cache.
 *
 * SpinupWP has no mode that holds its cached pages through a change, as WP
 * Super Cache's Lock Down does, so the adapter is active whenever the page
 * cache is on.
 *
 * Each call fails soft (fail-soft.php): a URL whose purge throws is
 * reported and skipped, and the purge records the adapter as failed.
 *
 * A site that does not want it drops the adapter whose id() is `spinupwp`
 * in a later `voyager_open_blocks_cache_adapters` filter.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SpinupWP implements Adapter {

	public function id(): string {
		return 'spinupwp';
	}

	public function label(): string {
		return 'SpinupWP';
	}

	/**
	 * Loaded, and its page cache is on by its own test.
	 */
	public function active(): bool {
		if ( ! function_exists( 'spinupwp' ) || ! function_exists( 'spinupwp_purge_url' ) || ! function_exists( 'spinupwp_purge_site' ) ) {
			return false;
		}

		$plugin = spinupwp();
		$cache  = is_object( $plugin ) && property_exists( $plugin, 'cache' ) ? $plugin->cache : null;

		return is_object( $cache ) && method_exists( $cache, 'is_page_cache_enabled' ) && true === (bool) $cache->is_page_cache_enabled();
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * Purge each page's cached copy.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		if ( ! function_exists( 'spinupwp_purge_url' ) ) {
			return;
		}

		purge_each(
			$this,
			$urls,
			static function ( string $url ): void {
				spinupwp_purge_url( $url );
			}
		);
	}

	/**
	 * Purge the whole page cache.
	 */
	public function purge_all(): void {
		if ( function_exists( 'spinupwp_purge_site' ) ) {
			purge_once(
				$this,
				static function (): void {
					spinupwp_purge_site();
				}
			);
		}
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the SpinupWP adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_spinupwp( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new SpinupWP();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_spinupwp' );
