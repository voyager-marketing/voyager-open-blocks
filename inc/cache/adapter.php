<?php
/**
 * What a page cache implements so the pack can refresh it. Contract 2 in
 * docs/v2-contracts.md.
 *
 * An adapter is an object a cache plugin, a theme or a site's own code adds
 * through the filter `voyager_open_blocks_cache_adapters`. When a Site Data
 * field or a bindable post meta field changes, inc/freshness/purge.php calls
 * every active adapter once, at the end of the request: purge_urls() with the
 * pages that show the field, or purge_all() when every page may show it or
 * the cache cannot purge single pages.
 *
 * The pack ships six adapters, each active only when its plugin is loaded:
 * WP Super Cache (wp-super-cache.php), SpinupWP (spinupwp.php), Nginx Helper
 * (nginx-helper.php) and LiteSpeed Cache (litespeed-cache.php), each also
 * only while its page cache is on, WP Rocket (wp-rocket.php), and Cloudflare
 * (cloudflare.php), which can only purge everything.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Adapter {

	/**
	 * A stable ID, lowercase, such as `wp-super-cache`.
	 */
	public function id(): string;

	/**
	 * The cache's name, shown on Settings > Site Data.
	 */
	public function label(): string;

	/**
	 * Whether the cache is installed and on. An inactive adapter is skipped.
	 */
	public function active(): bool;

	/**
	 * Whether the cache can purge single pages. When it cannot, it gets
	 * purge_all() instead of purge_urls().
	 */
	public function supports_urls(): bool;

	/**
	 * Purge these pages.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void;

	/**
	 * Purge every page.
	 */
	public function purge_all(): void;
}
