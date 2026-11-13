<?php
/**
 * voyager-open/contact-details: an address with a phone and an email that tap.
 *
 * A binding fills a core paragraph's whole text, so it cannot wrap a phone
 * number in a tel: link inside it. This block takes the contact fields as
 * attributes, each bindable, and src/contact-details/render.php prints one
 * `address` holding the street, the city line, the phone as a tel: link and
 * the email as a mailto: link, then the hours after it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\ContactDetails;

use VoyagerOpenBlocks\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The block's attributes, every one a string that takes a binding.
 */
const ATTRIBUTES = [ 'phone', 'phoneLink', 'email', 'emailLink', 'address', 'addressLocality', 'hours' ];

/**
 * The class every element of the block starts with.
 */
const CLASS_NAME = 'wp-block-voyager-open-contact-details';

/**
 * A value as plain text: decoded once and trimmed, '' for anything but a scalar.
 *
 * This plugin's voyager/site-data returns a value raw ("Joe's & Co"), and the
 * voyager-blocks plugin, when it owns the source, returns it through
 * esc_html() ("Joe&#039;s &amp; Co"). Decoding here, and escaping once where it
 * prints, means both print the same.
 *
 * @param mixed $value An attribute, bindings already applied.
 */
function text( mixed $value ): string {
	return trim( html_entity_decode( Cast\to_string( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * One line of the address: a link when it has an href, else plain text, or ''.
 *
 * @param string $part The part's name, such as phone, for its class.
 * @param string $text The text, as text() returns it.
 * @param string $href The link, as text() returns it. esc_url() keeps tel: and mailto:.
 */
function line( string $part, string $text, string $href = '' ): string {
	if ( '' === $text ) {
		return '';
	}

	$class = CLASS_NAME . '__' . $part;
	$url   = '' === $href ? '' : esc_url( $href );

	if ( '' === $url ) {
		return '<span class="' . $class . '">' . esc_html( $text ) . '</span>';
	}

	return '<a class="' . $class . ' ' . CLASS_NAME . '__link" href="' . $url . '">' . esc_html( $text ) . '</a>';
}

/**
 * The block: the wrapper, one `address` with each line that has text, then
 * the hours as a paragraph. '' when no part has text.
 *
 * @param array<string, mixed> $attributes         Block attributes, bindings already applied.
 * @param string               $wrapper_attributes The block's wrapper attributes, from get_block_wrapper_attributes().
 */
function markup( array $attributes, string $wrapper_attributes ): string {
	$value = static fn ( string $name ): string => text( $attributes[ $name ] ?? '' );

	$lines = line( 'street', $value( 'address' ) )
		. line( 'locality', $value( 'addressLocality' ) )
		. line( 'phone', $value( 'phone' ), $value( 'phoneLink' ) )
		. line( 'email', $value( 'email' ), $value( 'emailLink' ) );
	$hours = $value( 'hours' );

	if ( '' === $lines && '' === $hours ) {
		return '';
	}

	$html = '' === $lines ? '' : '<address class="' . CLASS_NAME . '__address">' . $lines . '</address>';

	if ( '' !== $hours ) {
		$html .= '<p class="' . CLASS_NAME . '__hours">' . esc_html( $hours ) . '</p>';
	}

	return '<div ' . $wrapper_attributes . '>' . $html . '</div>';
}

/**
 * Let every attribute take a block binding (WordPress 6.9+).
 *
 * WP_Block::render() applies the bindings before it calls the render file,
 * so render.php reads the bound values from $attributes. A source that
 * returns null leaves the stored value, so a field with no value shows the
 * block's own.
 *
 * @param string[] $attributes Already-supported attributes.
 * @return string[]
 */
function bindable_attributes( array $attributes ): array {
	return array_values( array_unique( array_merge( $attributes, ATTRIBUTES ) ) );
}
add_filter( 'block_bindings_supported_attributes_voyager-open/contact-details', __NAMESPACE__ . '\bindable_attributes' );
