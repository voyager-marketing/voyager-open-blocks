<?php
/**
 * Plugin Name: Bench page cache marker
 * Description: Prints a value unique to each render at the end of every front-end page, so bench/run.mjs can tell a page WP Super Cache served from one WordPress rendered again. Installed as an mu-plugin by bench/run.mjs in every group; never part of the pack.
 *
 * The marker is the one npm run cache-check prints
 * (tests/fixtures/cache-check/harness.php), so renderMarker() in
 * scripts/lib/cache-check.mjs reads it here too. A page the cache stored
 * keeps the marker of the render that produced it, so two loads with the
 * same marker mean the second came from the cache.
 *
 * PHP_SELF and the SAPI are there for a failed run's record: see
 * tests/fixtures/cache-check/wp-super-cache/playground-php-self.php for why
 * Playground needs them corrected before WP Super Cache serves anything.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBench\PageCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_footer',
	static function (): void {
		printf(
			"\n<!-- voyager-open-cache-check rendered %s php_self=%s sapi=%s -->\n",
			esc_html( bin2hex( random_bytes( 8 ) ) ),
			esc_html( isset( $_SERVER['PHP_SELF'] ) ? (string) $_SERVER['PHP_SELF'] : '' ),
			esc_html( PHP_SAPI )
		);
	},
	PHP_INT_MAX
);
