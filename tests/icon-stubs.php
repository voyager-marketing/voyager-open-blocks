<?php
/**
 * Stubs for tests/Unit/IconTest.php, on top of tests/wp-stubs.php,
 * tests/facts-stubs.php (get_block_wrapper_attributes),
 * tests/contact-details-stubs.php (esc_url) and tests/block-styles-stubs.php
 * (wp_enqueue_style).
 *
 * Each is guarded, so a stub that tests/wp-stubs.php adds later wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

require_once __DIR__ . '/facts-stubs.php';
require_once __DIR__ . '/contact-details-stubs.php';
require_once __DIR__ . '/block-styles-stubs.php';

if ( ! defined( 'VOYAGER_OPEN_BLOCKS_DIR' ) ) {
	define( 'VOYAGER_OPEN_BLOCKS_DIR', dirname( __DIR__ ) );
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * As core's esc_attr() for these tests' text: special characters as
	 * entities, an entity already in the text kept.
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * As core's sanitize_html_class() (wp-includes/formatting.php): percent
	 * escapes dropped, then anything but A-Z, a-z, 0-9, _ and - dropped.
	 *
	 * @param string $classname The class.
	 * @param string $fallback  Returned when nothing is left.
	 */
	function sanitize_html_class( string $classname, string $fallback = '' ): string {
		$sanitized = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', $classname ) );

		return '' === $sanitized ? $fallback : $sanitized;
	}
}
