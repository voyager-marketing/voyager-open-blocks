<?php
/**
 * The LiteSpeed Cache adapter, for contract 2 in docs/v2-contracts.md.
 *
 * LiteSpeed Cache's pages live in the LiteSpeed web server, which the plugin
 * tells what to purge in a response header. It purges a post's page when the
 * post changes, and does not hook clean_post_cache, so without this adapter
 * a Site Data change refreshes no page until the cache expires.
 *
 * Written against LiteSpeed Cache 7.9.3, through its documented actions,
 * which API::init() in src/api.cls.php hooks as the plugin loads. The class
 * docblock names each call it makes. The ABSPATH guard stays within the
 * first 50 lines: Plugin Check reads only those for it in a namespaced file.
 *
 * Disable All Features is LiteSpeed's nearest mode to WP Super Cache's Lock
 * Down, and the opposite of it: Lock Down keeps the cached pages, Disable
 * All Features stops caching. LiteSpeed has no mode that holds its cached
 * pages through a change.
 *
 * Each call fails soft (fail-soft.php): a URL whose purge throws is
 * reported and skipped, and the purge records the adapter as failed.
 *
 * A site that does not want it drops the adapter whose id() is
 * `litespeed-cache` in a later `voyager_open_blocks_cache_adapters` filter.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the adapter calls, in that version:
 *
 * - litespeed_purge_url, with a URL, runs Purge::purge_url() in
 *   src/purge.cls.php, which queues the purge of that URL's page. LiteSpeed
 *   keys pages by path, so only the path counts. It also adds a "Purge url"
 *   notice to LiteSpeed's next admin screen.
 * - litespeed_purge_all, with a reason for LiteSpeed's debug log, runs
 *   Purge::purge_all(): every page, and with them LiteSpeed's own CSS and
 *   JS files, local resources, object cache and OPcache. It is the purge
 *   LiteSpeed runs itself when a menu changes (its Purge All Hooks setting),
 *   the other change every page may show. On a multisite network LiteSpeed
 *   decides which sites' pages go.
 * - LITESPEED_ON is LiteSpeed's own test for its page cache, used before it
 *   sends any cache header: the server allows caching (LITESPEED_ALLOWED,
 *   from the X-LSCACHE server variable LiteSpeed's web server sets) and its
 *   Enable Cache setting is on (Conf::define_cache() in src/conf.cls.php).
 * - LITESPEED_DISABLE_ALL is set by its Disable All Features setting, the
 *   timed version of it, or the litespeed_disable_all action. While it is
 *   set, Core::init() in src/core.cls.php returns before LiteSpeed sends
 *   any cache header, so no page is cached; turning the setting on purges
 *   everything. The adapter is inactive then.
 */
final class LiteSpeed_Cache implements Adapter {

	/** The reason LiteSpeed's debug log gives for a purge_all(). */
	public const REASON = 'Voyager Open Blocks: a field every page may show changed';

	public function id(): string {
		return 'litespeed-cache';
	}

	public function label(): string {
		return 'LiteSpeed Cache';
	}

	/**
	 * Loaded, its page cache is on, and Disable All Features is off.
	 */
	public function active(): bool {
		return defined( 'LSCWP_V' )
			&& defined( 'LITESPEED_ON' )
			&& ! ( defined( 'LITESPEED_DISABLE_ALL' ) && constant( 'LITESPEED_DISABLE_ALL' ) );
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * Purge each page.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		purge_each(
			$this,
			$urls,
			static function ( string $url ): void {
				do_action( 'litespeed_purge_url', $url );
			}
		);
	}

	/**
	 * Purge every page.
	 */
	public function purge_all(): void {
		purge_once(
			$this,
			static function (): void {
				do_action( 'litespeed_purge_all', self::REASON );
			}
		);
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the LiteSpeed Cache adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_litespeed_cache( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new LiteSpeed_Cache();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_litespeed_cache' );
