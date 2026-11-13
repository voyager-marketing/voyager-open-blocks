<?php
/**
 * Icon set and animation stylesheets for voyager-open/icon.
 *
 * assets/icons.json is the one source: the editor bundles it, this file reads
 * it for render.php, and block.json carries its keys as the `icon` enum.
 * Heroicons outline set, MIT licensed (assets/LICENSE-heroicons.txt).
 *
 * The block's `style` holds the rules every icon needs. Each animation's
 * rules are a stylesheet of their own, which loads only where an icon uses it
 * (enqueue_animation()), and in the editor.
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
	/** @var array<string, string>|null $icons */
	static $icons = null;

	if ( null === $icons ) {
		$json  = json_decode( (string) file_get_contents( VOYAGER_OPEN_BLOCKS_DIR . '/assets/icons.json' ), true );
		$list  = is_array( $json ) ? ( $json['icons'] ?? null ) : null;
		$icons = [];

		foreach ( is_array( $list ) ? $list : [] as $name => $path ) {
			if ( is_string( $path ) ) {
				$icons[ (string) $name ] = $path;
			}
		}
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
 * The animations: name => the duration and timing the `animation` shorthand
 * plays it with, and its keyframes.
 *
 * Each animation's rules are a stylesheet of their own (animation_css()), so
 * a page loads only the animations its icons use. The keys are the
 * `animation` enum in src/icon/block.json, less the empty "none".
 */
const ANIMATIONS = [
	'bounce'  => [
		'timing'    => '1s ease-in-out',
		'keyframes' => '0%,to{transform:translateY(0)}50%{transform:translateY(-20%)}',
	],
	'pulse'   => [
		'timing'    => '1.5s ease-in-out',
		'keyframes' => '0%,to{transform:scale(1)}50%{transform:scale(1.12)}',
	],
	'spin'    => [
		'timing'    => '1.2s linear',
		'keyframes' => 'to{transform:rotate(1turn)}',
	],
	'float'   => [
		'timing'    => '2.4s ease-in-out',
		'keyframes' => '0%,to{transform:translateY(0)}50%{transform:translateY(-8%)}',
	],
	'wiggle'  => [
		'timing'    => '.8s ease-in-out',
		'keyframes' => '0%,to{transform:rotate(0)}25%{transform:rotate(-10deg)}75%{transform:rotate(10deg)}',
	],
	'fade-in' => [
		'timing'    => '.8s ease-out',
		'keyframes' => '0%{opacity:0}to{opacity:1}',
	],
	'zoom-in' => [
		'timing'    => '.6s ease-out',
		'keyframes' => '0%{opacity:0;transform:scale(.6)}to{opacity:1;transform:scale(1)}',
	],
];

/**
 * The handle of an animation's stylesheet: inline CSS with no file.
 *
 * block.json's `editorStyle` names the same handles, so the editor loads
 * every animation.
 *
 * @param string $name Animation name.
 */
function animation_handle( string $name ): string {
	return 'voyager-open-icon-animation-' . $name;
}

/**
 * One animation's rules, or '' for an unknown name.
 *
 * Its three trigger rules, the rule that ties the scroll trigger to the
 * icon's view timeline where the browser has one, the reduced-motion rule
 * that stops it, and its keyframes: what src/icon/style.scss built for each
 * animation when one stylesheet held all seven, in the same order and with
 * the same selectors, so an animated icon renders and moves as it did. The
 * view timeline and reduced-motion rules name no animation, so a page with
 * two animations holds them twice, which changes nothing.
 *
 * @param string $name Animation name.
 */
function animation_css( string $name ): string {
	$animation = ANIMATIONS[ $name ] ?? null;
	if ( null === $animation ) {
		return '';
	}

	$icon = '.wp-block-voyager-open-icon.has-animation-' . $name;
	$svg  = ' .wp-block-voyager-open-icon__svg';
	$play = 'animation:voyager-open-icon-' . $name . ' ' . $animation['timing'];

	return $icon . '.is-trigger-always' . $svg . '{' . $play . ' infinite}'
		. $icon . '.is-trigger-hover:hover' . $svg . ',' . $icon . '.is-trigger-hover:focus-within' . $svg . '{' . $play . ' infinite}'
		. $icon . '.is-trigger-scroll' . $svg . '{' . $play . ' both}'
		. '@supports (animation-timeline:view()){.wp-block-voyager-open-icon.is-trigger-scroll' . $svg . '{animation-duration:auto;animation-timeline:view();animation-range:entry 0% cover 40%}}'
		. '@media (prefers-reduced-motion:reduce){.wp-block-voyager-open-icon' . $svg . '{animation:none!important}}'
		. '@keyframes voyager-open-icon-' . $name . '{' . $animation['keyframes'] . '}';
}

/**
 * Load an animation's stylesheet. render.php calls it for an icon with an
 * animation, so a page loads the rules of the animations it shows and no
 * others.
 *
 * WP_Block::render() enqueues a block's `style` and `viewStyle` handles for
 * every icon it renders, whatever its attributes, and a site that does not
 * load block assets on demand enqueues every block's `style` on every page
 * (wp-includes/class-wp-block.php and wp_enqueue_registered_block_scripts_and_styles()
 * in wp-includes/script-loader.php, WordPress 7.1.4). So the animations
 * cannot be a `style` or a `viewStyle`: only render.php knows the attribute.
 * A block theme renders its template before wp_head(), so the style prints in
 * the head; a classic theme renders the content after it, so it prints at
 * the footer (print_late_styles()), as the scroll reveal's does, and
 * wp_hoist_late_printed_styles() moves it into the head when the site loads
 * block styles on demand.
 *
 * @param string $name Animation name; '' or an unknown name loads nothing.
 */
function enqueue_animation( string $name ): void {
	if ( isset( ANIMATIONS[ $name ] ) ) {
		wp_enqueue_style( animation_handle( $name ) );
	}
}

/**
 * Register each animation's stylesheet, which enqueue_animation() loads on a
 * page and block.json's `editorStyle` loads in the editor.
 */
function register_animation_styles(): void {
	foreach ( array_keys( ANIMATIONS ) as $name ) {
		wp_register_style( animation_handle( $name ), false, [], VOYAGER_OPEN_BLOCKS_VERSION );
		wp_add_inline_style( animation_handle( $name ), animation_css( $name ) );
	}
}
add_action( 'init', __NAMESPACE__ . '\register_animation_styles' );

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
