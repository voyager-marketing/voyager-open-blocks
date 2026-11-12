<?php
/**
 * The Cloudflare adapter, for contract 2 in docs/v2-contracts.md.
 *
 * The Cloudflare plugin purges Cloudflare's edge cache, through Cloudflare's
 * API, when a post is published, updated or deleted, and purges everything
 * when the theme changes. It does not hook clean_post_cache, so without this
 * adapter a Site Data change refreshes no page until the edge expires it.
 *
 * Written against Cloudflare 4.14.5:
 *
 * - cloudflare.loader.php makes the plugin's one Hooks object at file scope,
 *   $cloudflareHooks, which WordPress loads as a global: wp-settings.php
 *   includes each active plugin from a loop at the top level.
 * - Hooks::purgeCacheEverything() in src/WordPress/Hooks.php asks Cloudflare
 *   to purge the whole zone (WordPressClientAPI::zonePurgeCache() in
 *   src/WordPress/WordPressClientAPI.php). It is the plugin's own
 *   purge-everything, which it hooks to switch_theme and
 *   customize_save_after. It sends the purge only when the plugin's page
 *   cache or APO is on, and the plugin is matched to a zone: the adapter
 *   leaves that test to it.
 * - The plugin has no public purge by URL. Hooks::purgeCacheByRelevantURLs()
 *   takes post IDs and works out each post's own URLs, so it cannot purge a
 *   page that shows Site Data from somewhere else. supports_urls() is false,
 *   and the purge calls purge_all() every time. A Site Data change is rare,
 *   and Cloudflare refills from the origin.
 *
 * A site that does not want it drops the adapter whose id() is `cloudflare`
 * in a later `voyager_open_blocks_cache_adapters` filter.
 *
 * The call fails soft (fail-soft.php): a purge that throws is reported, and
 * the purge records the adapter as failed.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cloudflare implements Adapter {

	/** The class of the Hooks object the plugin's loader makes. */
	private const HOOKS_CLASS = 'Cloudflare\APO\WordPress\Hooks';

	public function id(): string {
		return 'cloudflare';
	}

	public function label(): string {
		return 'Cloudflare';
	}

	/**
	 * Loaded: the plugin's Hooks object exists.
	 */
	public function active(): bool {
		return null !== self::hooks();
	}

	/**
	 * The plugin's Hooks object, or null when the plugin is not loaded.
	 */
	private static function hooks(): ?object {
		$hooks = $GLOBALS['cloudflareHooks'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- The Cloudflare plugin's own name.

		return is_object( $hooks ) && is_a( $hooks, self::HOOKS_CLASS ) && method_exists( $hooks, 'purgeCacheEverything' ) ? $hooks : null;
	}

	/**
	 * No: the plugin purges single pages only by post ID.
	 */
	public function supports_urls(): bool {
		return false;
	}

	/**
	 * Purge everything, since the plugin cannot purge these pages alone. The
	 * purge never calls this, because supports_urls() is false.
	 *
	 * @param list<string> $urls Absolute URLs, without duplicates.
	 */
	public function purge_urls( array $urls ): void {
		if ( [] !== $urls ) {
			$this->purge_all();
		}
	}

	/**
	 * Ask Cloudflare to purge the zone, through the plugin.
	 */
	public function purge_all(): void {
		$hooks = self::hooks();

		if ( null !== $hooks && method_exists( $hooks, 'purgeCacheEverything' ) ) {
			purge_once(
				$this,
				static function () use ( $hooks ): void {
					$hooks->purgeCacheEverything();
				}
			);
		}
	}
}

/**
 * voyager_open_blocks_cache_adapters: offer the Cloudflare adapter.
 *
 * @param mixed $adapters What earlier filters returned.
 * @return array<mixed>
 */
function add_cloudflare( mixed $adapters ): array {
	$adapters   = is_array( $adapters ) ? $adapters : [];
	$adapters[] = new Cloudflare();

	return $adapters;
}

add_filter( 'voyager_open_blocks_cache_adapters', __NAMESPACE__ . '\add_cloudflare' );
