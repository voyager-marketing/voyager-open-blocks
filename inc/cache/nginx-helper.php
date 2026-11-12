<?php
/**
 * The Nginx Helper adapter, for contract 2 in docs/v2-contracts.md.
 *
 * Nginx Helper tells nginx's FastCGI cache, or a Redis-backed nginx cache,
 * to drop pages when posts and comments change. It does not hook
 * clean_post_cache, so without this adapter a Site Data change refreshes no
 * page until the cache expires.
 *
 * Written against Nginx Helper 2.4.1. The class docblock names each call it
 * makes. The ABSPATH guard stays within the first 50 lines: Plugin Check
 * reads only those for it in a namespaced file.
 *
 * Nginx Helper has no mode that holds its cached pages through a change, as
 * WP Super Cache's Lock Down does. It skips its own purge-everything during
 * a WordPress import (Nginx_Helper_Admin::is_import_request()), which the
 * pack's purge never runs in the middle of.
 *
 * Each call fails soft (fail-soft.php): a URL whose purge throws is
 * reported and skipped, and the purge records the adapter as failed.
 *
 * A site that does not want it drops the adapter whose id() is
 * `nginx-helper` in a later `voyager_open_blocks_cache_adapters` filter.
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
 * - The global $nginx_purger is the purger for the cache method chosen
 *   under Settings > Nginx Helper: a FastCGI_Purger, a PhpRedis_Purger or a
 *   Predis_Purger, all subclasses of Purger (admin/class-purger.php).
 *   Nginx_Helper::define_admin_hooks() in includes/class-nginx-helper.php
 *   creates it, with the global $nginx_helper_admin, as the plugin loads.
 * - Purger::purge_url( $url, $feed = true ) drops one URL, by the purge
 *   method set: a GET to /purge/<path>, deleting the cache file, or deleting
 *   the Redis key. With $feed true, FastCGI_Purger also drops the URL's
 *   feed, atom and rdf URLs. The adapter passes false: those are a page's
 *   comment feeds, which show no blocks, and each costs one more request or
 *   file lookup per page.
 * - Purger::purge_all() drops the whole cache: FastCGI_Purger empties
 *   RT_WP_NGINX_HELPER_CACHE_PATH, which holds every site's pages on a
 *   multisite network, and the Redis purgers delete this site's keys.
 * - Enable Purge, the `enable_purge` setting in
 *   $nginx_helper_admin->options, is Nginx Helper's own switch: while it is
 *   off, each of its own purges returns first (Purger::purge_post()), and
 *   its admin bar has no Purge link. The adapter is inactive while it is
 *   off, so the pack does not purge a cache the site has told Nginx Helper
 *   to leave alone.
 */
final class Nginx_Helper implements Adapter {

	public function id(): string {
		return 'nginx-helper';
	}

	public function label(): string {
		return 'Nginx Helper';
	}

	/**
	 * Loaded, with a purger, and Enable Purge is on.
	 */
	public function active(): bool {
		if ( null === self::purger() ) {
			return false;
		}

		$admin   = $GLOBALS['nginx_helper_admin'] ?? null;
		$options = is_object( $admin ) && property_exists( $admin, 'options' ) ? $admin->options : null;

		return is_array( $options ) && ! empty( $options['enable_purge'] );
	}

	/**
	 * The purger Nginx Helper made, or null when it is not loaded.
	 */
	private static function purger(): ?object {
		$purger = $GLOBALS['nginx_purger'] ?? null;

		return is_object( $purger ) && method_exists( $purger, 'purge_url' ) && method_exists( $purger, 'purge_all' ) ? $purger : null;
	}

	public function supports_urls(): bool {
		return true;
	}

	/**
	 * Drop each page, without its feeds.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		$purger = self::purger();

		if ( null === $purger || ! method_exists( $purger, 'purge_url' ) ) {
			return;
		}

		purge_each(
			$this,
			$urls,
			static function ( string $url ) use ( $purger ): void {
				$purger->purge_url( $url, false );
			}
		);
	}

	/**
	 * Drop the whole cache.
	 */
	public function purge_all(): void {
		$purger = self::purger();

		if ( null !== $purger && method_exists( $purger, 'purge_all' ) ) {
			purge_once(
				$this,
				static function () use ( $purger ): void {
					$purger->purge_all();
				}
			);
		}
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the Nginx Helper adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_nginx_helper( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new Nginx_Helper();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_nginx_helper' );
