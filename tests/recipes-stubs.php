<?php
/**
 * Stubs for tests/Unit/RecipeCatalogTest.php, on top of tests/wp-stubs.php.
 *
 * The recipe reader defaults to this plugin's recipes/ and patterns/intents/,
 * and escapes the notice it gives a skipped file. Each stub is guarded, so one
 * another stub file or tests/wp-stubs.php adds first wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'VOYAGER_OPEN_BLOCKS_DIR' ) ) {
	define( 'VOYAGER_OPEN_BLOCKS_DIR', dirname( __DIR__ ) );
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * As core's esc_html() (wp-includes/formatting.php), less the UTF-8 check
	 * and the entity normalising.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}
