<?php
/**
 * Front-end render for voyager-open/contact-details.
 *
 * Dynamic block: the saved markup is the comment delimiter only. One
 * `address` with the street, the city line, the phone as a tel: link and the
 * email as a mailto: link, then the hours, each only when it has text, or
 * nothing when none does. A bound attribute whose field is empty shows the
 * block's own value: the source returns null, and core then keeps it.
 *
 * @package VoyagerOpenBlocks
 *
 * @var array{phone?: string, phoneLink?: string, email?: string, emailLink?: string, address?: string, addressLocality?: string, hours?: string} $attributes Block attributes (bindings already applied), typed by block.json.
 * @var string                                                                                                                              $content    Saved inner content (empty).
 * @var WP_Block                                                                                                                            $block      Block instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \VoyagerOpenBlocks\ContactDetails\markup( $attributes, get_block_wrapper_attributes() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ContactDetails\markup() escapes each value and href, and core escapes the wrapper attributes.
