<?php
/**
 * The W3 Total Cache adapter, for contract 2 in docs/v2-contracts.md.
 *
 * W3 Total Cache flushes a post's pages when the post is saved, trashed or
 * deleted, and every page when a menu, a term or the theme changes
 * (Util_AttachToActions::flush_posts_on_actions() in
 * Util_AttachToActions.php). Its page cache does not hook clean_post_cache,
 * so without this adapter a Site Data change refreshes no page until the
 * cache expires it.
 *
 * Written against W3 Total Cache 2.11.0, through its public functions in
 * w3-total-cache-api.php, which w3-total-cache.php loads with the plugin:
 *
 * - w3tc_flush_url( $w3tc_url ) (line 326) flushes one URL. CacheFlush
 *   (CacheFlush.php) flushes each URL once per request, and its executor,
 *   CacheFlush_Locally::flush_url() (CacheFlush_Locally.php:349-354),
 *   fires the action w3tc_flush_url with the URL. The page cache queues
 *   the URL there (PgCache_Plugin::run() in PgCache_Plugin.php:46,
 *   PgCache_Flush::flush_url()) and deletes its cached copies on shutdown,
 *   after the pack's purge, which runs at shutdown priority 0
 *   (CacheFlush::__construct() hooks it at 100000). W3 Total Cache's
 *   Varnish and full-site CDN purges listen to the same action when on.
 * - w3tc_flush_posts() (line 315) flushes every page: CacheFlush_Locally::
 *   flush_posts() (CacheFlush_Locally.php:274-279) fires w3tc_flush_posts,
 *   which empties the page cache (PgCache_Plugin.php:45) and, when on, its
 *   Varnish, full-site CDN and Cloudflare extension purges. It is the
 *   flush W3 Total Cache runs itself when a menu or a term changes. The
 *   adapter never calls w3tc_flush_all() (line 293), which also empties
 *   the object, database and minify caches and OPcache, none of which
 *   holds a rendered page.
 * - w3tc_config() (line 273) is the plugin's settings, from master.php in
 *   its config directory (Config::util_config_filename() in Config.php).
 *   Its get_boolean( 'pgcache.enabled' ) is Page Cache under General
 *   Settings, and the test Root_Loader::__construct() (Root_Loader.php:52)
 *   makes before it loads the page cache at all. While it is off, nothing
 *   listens to the flushes above for the page cache, and the adapter is
 *   inactive. A site that defines W3TC_CONFIG_HIDE gets settings that read
 *   false from w3tc_config(), so the adapter is inactive there too.
 *
 * With W3 Total Cache's message bus on, for a cluster of servers,
 * CacheFlush publishes each flush to Amazon SNS instead of running it
 * itself (CacheFlush::__construct(), CacheFlush.php:40-47). The adapter
 * calls the same functions either way.
 *
 * W3 Total Cache has no mode that holds its cached pages through a change,
 * as WP Super Cache's Lock Down does.
 *
 * Each call fails soft (fail-soft.php): a URL whose flush throws is
 * reported and skipped, and the purge records the adapter as failed.
 *
 * A site that does not want it drops the adapter whose id() is
 * `w3-total-cache` in a later `voyager_open_blocks_cache_adapters` filter.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class W3_Total_Cache implements Adapter {

	public function id(): string {
		return 'w3-total-cache';
	}

	public function label(): string {
		return 'W3 Total Cache';
	}

	/**
	 * Loaded, and its page cache is on by its own test.
	 */
	public function active(): bool {
		if ( ! function_exists( 'w3tc_config' ) || ! function_exists( 'w3tc_flush_url' ) || ! function_exists( 'w3tc_flush_posts' ) ) {
			return false;
		}

		$config = w3tc_config();

		return is_object( $config ) && method_exists( $config, 'get_boolean' ) && true === $config->get_boolean( 'pgcache.enabled' );
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * Flush each page.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		if ( ! function_exists( 'w3tc_flush_url' ) ) {
			return;
		}

		purge_each(
			$this,
			$urls,
			static function ( string $url ): void {
				w3tc_flush_url( $url );
			}
		);
	}

	/**
	 * Flush every page, and only pages.
	 */
	public function purge_all(): void {
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			purge_once(
				$this,
				static function (): void {
					w3tc_flush_posts();
				}
			);
		}
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the W3 Total Cache adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_w3_total_cache( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new W3_Total_Cache();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_w3_total_cache' );
