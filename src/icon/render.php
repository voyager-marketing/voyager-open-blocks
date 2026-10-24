<?php
/**
 * Front-end render for voyager-open/icon.
 *
 * Dynamic block: the saved markup is the comment delimiter only, so the
 * output can change here without invalidating saved content.
 *
 * @package VoyagerOpenBlocks
 *
 * @var array<string, mixed> $attributes Block attributes (bindings already applied).
 * @var string               $content    Saved inner content (empty).
 * @var WP_Block             $block      Block instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$voyager_open_icon_name  = (string) ( $attributes['icon'] ?? 'star' );
$voyager_open_icon_url   = esc_url( (string) ( $attributes['linkUrl'] ?? '' ) );
$voyager_open_icon_label = trim( (string) ( $attributes['label'] ?? '' ) );

// A link needs an accessible name. Fall back to the icon's name rather than
// render an empty link when no label was set.
if ( '' !== $voyager_open_icon_url && '' === $voyager_open_icon_label ) {
	$voyager_open_icon_label = ucfirst( str_replace( '-', ' ', $voyager_open_icon_name ) );
}

$voyager_open_icon_svg = \VoyagerOpenBlocks\Icons\svg(
	$voyager_open_icon_name,
	(int) ( $attributes['size'] ?? 48 ),
	$voyager_open_icon_label
);

if ( '' === $voyager_open_icon_svg ) {
	return;
}

$voyager_open_icon_animation = (string) ( $attributes['animation'] ?? '' );
$voyager_open_icon_classes   = [];

if ( '' !== $voyager_open_icon_animation ) {
	$voyager_open_icon_classes[] = 'has-animation-' . sanitize_html_class( $voyager_open_icon_animation );
	$voyager_open_icon_classes[] = 'is-trigger-' . sanitize_html_class( (string) ( $attributes['animationTrigger'] ?? 'hover' ) );
}

if ( '' !== $voyager_open_icon_url ) {
	$voyager_open_icon_svg = sprintf(
		'<a class="wp-block-voyager-open-icon__link" href="%1$s"%2$s>%3$s</a>',
		$voyager_open_icon_url,
		empty( $attributes['linkTarget'] ) ? '' : ' target="_blank" rel="noopener noreferrer"',
		$voyager_open_icon_svg
	);
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( [ 'class' => implode( ' ', $voyager_open_icon_classes ) ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes wrapper attributes.
	$voyager_open_icon_svg // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in Icons\svg().
);
