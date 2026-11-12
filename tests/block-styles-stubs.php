<?php
/**
 * Stubs for tests/Unit/MarqueeTest.php, on top of tests/wp-stubs.php and
 * tests/validate-stubs.php.
 *
 * Kept apart so the shared stubs stay as they are. Each is guarded, so a stub
 * that tests/wp-stubs.php adds later wins, and so does core's own when
 * WordPress is loaded.
 *
 * - WP_Block                       the two properties the Marquee's row filter reads
 * - voyager_test_enqueued_styles   wp_enqueue_style() calls, by handle
 * - voyager_test_context           where blocks render: ['rest' => true] for a
 *                                  REST response (wp_is_rest_endpoint()),
 *                                  ['feed' => true] for a feed (is_feed()),
 *                                  ['admin' => true] for an admin request
 *                                  (is_admin()); empty for a page
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Block' ) ) {
	/**
	 * A block instance: its parsed block, and its inner blocks as instances,
	 * which core's WP_Block_List makes on first read and keeps.
	 */
	class WP_Block {

		/**
		 * @var array<mixed>
		 */
		public array $parsed_block;

		/**
		 * @var list<WP_Block>
		 */
		public array $inner_blocks = [];

		/**
		 * @param array<mixed> $parsed_block A parsed block.
		 */
		public function __construct( array $parsed_block ) {
			$this->parsed_block = $parsed_block;
			$inner              = $parsed_block['innerBlocks'] ?? [];
			foreach ( is_array( $inner ) ? $inner : [] as $block ) {
				$this->inner_blocks[] = new WP_Block( is_array( $block ) ? $block : [] );
			}
		}
	}
}

if ( ! function_exists( 'wp_is_rest_endpoint' ) ) {
	/**
	 * Whether voyager_test_context says a REST request is being handled.
	 */
	function wp_is_rest_endpoint(): bool {
		return ! empty( $GLOBALS['voyager_test_context']['rest'] );
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	/**
	 * Whether voyager_test_context says the query is for a feed.
	 *
	 * @param string|string[] $feeds Feed types, ignored.
	 */
	function is_feed( string|array $feeds = '' ): bool {
		return ! empty( $GLOBALS['voyager_test_context']['feed'] );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Whether voyager_test_context says the request is for the admin.
	 */
	function is_admin(): bool {
		return ! empty( $GLOBALS['voyager_test_context']['admin'] );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Records the handle in voyager_test_enqueued_styles.
	 */
	function wp_enqueue_style( string $handle ): void {
		$GLOBALS['voyager_test_enqueued_styles'][] = $handle;
	}
}
