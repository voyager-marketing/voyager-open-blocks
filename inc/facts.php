<?php
/**
 * voyager-open/facts: one list field, shown as a list.
 *
 * A block binding fills an attribute with one string, so a Site Data list
 * (services, service area, credentials) has no way onto a page as a list
 * through a core block. This block takes that string in its `items`
 * attribute, one item per line, and src/facts/render.php prints a list.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Facts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The items to show: one per line, each line trimmed, empty lines dropped.
 *
 * Splits on \r\n, \r and \n, as SiteData\resolve_list() does, so items typed
 * in the editor read the same as a list from Settings > Site Data.
 *
 * @param string $value The `items` attribute, bindings already applied.
 * @return list<string>
 */
function items( string $value ): array {
	$lines = array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ?: [] );

	return array_values( array_filter( $lines, static fn ( string $line ): bool => '' !== $line ) );
}

/**
 * The list: a `ul` with one escaped `li` per item, or '' when there are none.
 *
 * Each item is decoded once and escaped once. This plugin's voyager/site-data
 * returns a value raw ("Heating & cooling"), and the voyager-blocks plugin,
 * when it owns the source, returns it through esc_html() ("Heating &amp;
 * cooling"). Decoding first means both print the same `Heating &amp; cooling`,
 * whatever escaped the value before it arrived.
 *
 * @param list<string> $items              Items, as items() returns them.
 * @param string       $wrapper_attributes The block's wrapper attributes, from get_block_wrapper_attributes().
 */
function markup( array $items, string $wrapper_attributes ): string {
	if ( [] === $items ) {
		return '';
	}

	$html = '';

	foreach ( $items as $item ) {
		$html .= '<li>' . esc_html( html_entity_decode( $item, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '</li>';
	}

	return '<ul ' . $wrapper_attributes . '>' . $html . '</ul>';
}

/**
 * Let `items` take a block binding (WordPress 6.9+).
 *
 * WP_Block::render() applies the binding before it calls the render file,
 * so render.php reads the bound value from $attributes.
 *
 * @param string[] $attributes Already-supported attributes.
 * @return string[]
 */
function bindable_attributes( array $attributes ): array {
	return array_values( array_unique( array_merge( $attributes, [ 'items' ] ) ) );
}
add_filter( 'block_bindings_supported_attributes_voyager-open/facts', __NAMESPACE__ . '\bindable_attributes' );
