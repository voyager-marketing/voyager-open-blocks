<?php
/**
 * The Cloudflare plugin, as tests/Unit/CloudflareTest.php needs it: the
 * class of the $cloudflareHooks object its loader makes (4.14.5,
 * src/WordPress/Hooks.php), with its two purges, recording each call.
 *
 * - voyager_test_cloudflare_calls  every call: [ method, argument ]
 * - voyager_test_cloudflare_throw  true, for a purge that throws
 *
 * Loading the plugin is setting $GLOBALS['cloudflareHooks'] to a Hooks, as
 * cloudflare.loader.php does at file scope.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace Cloudflare\APO\WordPress;

if ( ! class_exists( Hooks::class, false ) ) {
	class Hooks {

		public function purgeCacheEverything(): void {
			$GLOBALS['voyager_test_cloudflare_calls'][] = [ 'purgeCacheEverything', null ];
			if ( true === ( $GLOBALS['voyager_test_cloudflare_throw'] ?? false ) ) {
				throw new \RuntimeException( 'Cloudflare answered 500.' );
			}
		}

		/**
		 * @param int|list<int> $postIds Posts whose URLs to purge.
		 */
		public function purgeCacheByRelevantURLs( $postIds ): void {
			$GLOBALS['voyager_test_cloudflare_calls'][] = [ 'purgeCacheByRelevantURLs', $postIds ];
		}
	}
}
