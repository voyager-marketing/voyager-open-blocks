<?php
/**
 * Icon set for voyager-open/icon.
 *
 * assets/icons.json is the one source: the editor bundles it, this file reads
 * it for render.php, and block.json carries its keys as the `icon` enum.
 * Heroicons outline set, MIT licensed (assets/LICENSE-heroicons.txt).
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Icon name => SVG path data.
 *
 * @return array<string, string>
 */
function all(): array {
	static $icons = null;

	if ( null === $icons ) {
		$json  = json_decode( (string) file_get_contents( VOYAGER_OPEN_BLOCKS_DIR . '/assets/icons.json' ), true );
		$icons = is_array( $json['icons'] ?? null ) ? $json['icons'] : [];
	}

	return $icons;
}

/**
 * The SVG for one icon, or '' for an unknown name.
 *
 * Decorative by default (aria-hidden). With a label it becomes an image with
 * that accessible name.
 *
 * @param string $name  Icon name.
 * @param int    $size  Width and height in pixels.
 * @param string $label Accessible label, '' when decorative.
 */
function svg( string $name, int $size, string $label = '' ): string {
	$path = all()[ $name ] ?? '';

	if ( '' === $path ) {
		return '';
	}

	$size = max( 8, min( 512, $size ) );
	$a11y = '' === $label
		? 'aria-hidden="true" focusable="false"'
		: sprintf( 'role="img" aria-label="%s"', esc_attr( $label ) );

	return sprintf(
		'<svg class="wp-block-voyager-open-icon__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.5" %2$s><path stroke-linecap="round" stroke-linejoin="round" d="%3$s"/></svg>',
		$size,
		$a11y,
		esc_attr( $path )
	);
}

/**
 * Let `label` and `linkUrl` take a block binding (WordPress 6.9+).
 *
 * @param string[] $attributes Already-supported attributes.
 * @return string[]
 */
function bindable_attributes( array $attributes ): array {
	return array_values( array_unique( array_merge( $attributes, [ 'label', 'linkUrl' ] ) ) );
}
add_filter( 'block_bindings_supported_attributes_voyager-open/icon', __NAMESPACE__ . '\bindable_attributes' );
