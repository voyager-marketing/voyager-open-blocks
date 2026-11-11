<?php
/**
 * The WP Super Cache adapter, for contract 2 in docs/v2-contracts.md.
 *
 * WP Super Cache already refreshes a page when the purge calls
 * clean_post_cache() for it, which covers the first 50 pages that show a
 * changed field. This adapter covers the rest: when the purge sets purge_all
 * (more than 50 pages show the field, or a template or template part shows
 * it), it clears this site's cached pages.
 *
 * Written against WP Super Cache 3.1.5, wp-cache-phase2.php:
 *
 * - wpsc_delete_url_cache( $url ) deletes the cached copy of one URL.
 * - wp_cache_clear_cache( $blog_id ) deletes the cached pages of the site
 *   with that ID, or of every site in the network when given 0. Its cache
 *   directories are named after each site's home URL, so on a subdirectory
 *   network the main site's holds the other sites', and clearing it clears
 *   them too.
 * - $cache_enabled, set from wp-cache-config.php, is the plugin's "Caching
 *   On" setting. While it is false, wp_cache_postload() returns before any
 *   page is served or stored.
 *
 * Each call checks that the function exists, so the adapter does nothing
 * when WP Super Cache is missing. A site that does not want it drops the
 * adapter whose id() is `wp-super-cache` in a later
 * `voyager_open_blocks_cache_adapters` filter, or removes add_wp_super_cache()
 * from that filter.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Super_Cache implements Adapter {

	public function id(): string {
		return 'wp-super-cache';
	}

	public function label(): string {
		return 'WP Super Cache';
	}

	/**
	 * Loaded, and its caching is on.
	 */
	public function active(): bool {
		return function_exists( 'wpsc_delete_url_cache' )
			&& function_exists( 'wp_cache_clear_cache' )
			&& ! empty( $GLOBALS['cache_enabled'] );
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * Delete each page's cached copy.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
			return;
		}

		foreach ( $urls as $url ) {
			wpsc_delete_url_cache( $url );
		}
	}

	/**
	 * Delete this site's cached pages. On a multisite network that is this
	 * site's only, so one site's change does not clear the others.
	 */
	public function purge_all(): void {
		if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
			return;
		}

		wp_cache_clear_cache( is_multisite() ? get_current_blog_id() : 0 );
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the WP Super Cache adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_wp_super_cache( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new WP_Super_Cache();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_wp_super_cache' );
