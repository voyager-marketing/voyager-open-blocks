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

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Records the handle in voyager_test_enqueued_styles.
	 */
	function wp_enqueue_style( string $handle ): void {
		$GLOBALS['voyager_test_enqueued_styles'][] = $handle;
	}
}
