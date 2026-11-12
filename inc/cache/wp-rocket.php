<?php
/**
 * The WP Rocket adapter, for contract 2 in docs/v2-contracts.md.
 *
 * WP Rocket mostly refreshes a page already when the purge calls
 * clean_post_cache() for it: it hooks rocket_clean_post() there
 * (inc/common/purge.php), except on hosts whose own cache it hands purges
 * to, such as Flywheel (inc/3rd-party/hosting/). That covers the first 50
 * pages that show a changed field. This adapter tells WP Rocket the URLs
 * too, and covers the rest: when the purge sets purge_all (more than 50
 * pages show the field, or a template or template part shows it), it clears
 * the site's cache.
 *
 * Written against WP Rocket 3.24, inc/functions/files.php, which WP Rocket
 * loads with the plugin (inc/main.php):
 *
 * - rocket_clean_files( $urls, $filesystem = null, $run_actions = true )
 *   deletes the cache files of each URL in the list, and fires
 *   before_rocket_clean_files with the list first, which WP Rocket's own
 *   add-ons, such as its preload, listen to. The adapter passes the URLs in
 *   one call, as the purge gave them.
 * - rocket_clean_domain( $lang = '', $filesystem = null ) deletes the cache
 *   files under the site's home URL, every language's on a multilingual
 *   site when $lang is empty (get_rocket_i18n_uri() in
 *   inc/functions/i18n.php). On a multisite network that is this site's
 *   cache only: WP Rocket keeps one directory per host and path. It cleans
 *   once per request, and not while WordPress imports
 *   (rocket_is_importing()).
 *
 * WP Rocket has no setting that switches its page cache off while the
 * plugin is active, and no mode that keeps its cached pages through a
 * change, as WP Super Cache's Lock Down does, so the adapter is active
 * whenever both functions exist.
 *
 * Each call fails soft (fail-soft.php): a purge that throws is reported, and
 * the purge records the adapter as failed.
 *
 * A site that does not want it drops the adapter whose id() is `wp-rocket`
 * in a later `voyager_open_blocks_cache_adapters` filter.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Rocket implements Adapter {

	public function id(): string {
		return 'wp-rocket';
	}

	public function label(): string {
		return 'WP Rocket';
	}

	/**
	 * Loaded: both purge functions exist.
	 */
	public function active(): bool {
		return function_exists( 'rocket_clean_files' ) && function_exists( 'rocket_clean_domain' );
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * Delete the pages' cache files, in one call.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		if ( [] === $urls || ! function_exists( 'rocket_clean_files' ) ) {
			return;
		}

		purge_once(
			$this,
			static function () use ( $urls ): void {
				rocket_clean_files( $urls );
			},
			sprintf( '%d page%s', count( $urls ), 1 === count( $urls ) ? '' : 's' )
		);
	}

	/**
	 * Delete the site's cache files, every language's.
	 */
	public function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			purge_once(
				$this,
				static function (): void {
					rocket_clean_domain();
				}
			);
		}
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the WP Rocket adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_wp_rocket( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new WP_Rocket();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_wp_rocket' );
