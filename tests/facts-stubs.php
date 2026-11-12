<?php
/**
 * Stubs for tests/Unit/FactsTest.php, on top of tests/wp-stubs.php.
 *
 * Kept apart so the shared stubs stay as they are. Each is guarded, so a stub
 * that tests/wp-stubs.php adds later wins.
 *
 * - voyager_test_wrapper_attributes  what get_block_wrapper_attributes() returns
 * - voyager_test_block_styles        register_block_style() calls: block => list of style args
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * As core's esc_html() (wp-includes/formatting.php), less the UTF-8 check
	 * and the entity normalising: _wp_specialchars() with ENT_QUOTES and
	 * $double_encode false, so an entity already in the text is kept as it is.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	/**
	 * @param array<string, string> $extra_attributes Extra attributes.
	 */
	function get_block_wrapper_attributes( array $extra_attributes = [] ): string {
		return $GLOBALS['voyager_test_wrapper_attributes'] ?? 'class="wp-block-voyager-open-facts"';
	}
}

if ( ! function_exists( 'register_block_style' ) ) {
	/**
	 * Records each call in voyager_test_block_styles.
	 *
	 * @param string|string[]      $block_name       Block type name.
	 * @param array<string, mixed> $style_properties Style properties.
	 */
	function register_block_style( string|array $block_name, array $style_properties ): bool {
		foreach ( (array) $block_name as $name ) {
			$GLOBALS['voyager_test_block_styles'][ $name ][] = $style_properties;
		}
		return true;
	}
}
