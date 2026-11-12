<?php
/**
 * Front-end render for voyager-open/facts.
 *
 * Dynamic block: the saved markup is the comment delimiter only. A list with
 * one item per line of `items`, or nothing when there are none. A bound block
 * whose field is empty shows its own `items`: the source returns null, and
 * core then keeps the stored value.
 *
 * @package VoyagerOpenBlocks
 *
 * @var array{items?: string} $attributes Block attributes (bindings already applied), typed by block.json.
 * @var string                $content    Saved inner content (empty).
 * @var WP_Block              $block      Block instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$voyager_open_facts_items = \VoyagerOpenBlocks\Facts\items( \VoyagerOpenBlocks\Cast\to_string( $attributes['items'] ?? '' ) );

if ( [] === $voyager_open_facts_items ) {
	return;
}

echo \VoyagerOpenBlocks\Facts\markup( $voyager_open_facts_items, get_block_wrapper_attributes() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Facts\markup() escapes each item, and core escapes the wrapper attributes.
