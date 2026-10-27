<?php
/**
 * Block styles the patterns use, registered in PHP so theme.json can style them.
 *
 * - core/list "Ticks": a wrapping row of checkmarked items (proof strip).
 *   In a flex group, the group becomes a size container, and the ticks
 *   stack as a column when it is narrower than 32em.
 * - core/group "Marquee": its first inner group drifts sideways when it is
 *   wider than the screen. Still under reduced motion, and paused on hover
 *   and focus.
 *
 * Colours come from currentColor, so both follow the theme.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\BlockStyles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the styles.
 */
function register(): void {
	$tick = rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.75 6 6 9-13.5"/></svg>' );

	register_block_style(
		'core/list',
		[
			'name'         => 'voyager-open-ticks',
			'label'        => __( 'Ticks', 'voyager-open-blocks' ),
			'inline_style' => '.wp-block-list.is-style-voyager-open-ticks{list-style:none;padding-inline-start:0;display:flex;flex-wrap:wrap;gap:.75em 2em}'
				. '.wp-block-list.is-style-voyager-open-ticks>li{position:relative;margin:0;padding-inline-start:1.6em}'
				. '.wp-block-list.is-style-voyager-open-ticks>li::before{content:"";position:absolute;inset-inline-start:0;top:.2em;width:1.1em;height:1.1em;background:currentColor;'
				. '-webkit-mask:url("data:image/svg+xml,' . $tick . '") center/contain no-repeat;mask:url("data:image/svg+xml,' . $tick . '") center/contain no-repeat}'
				. '.wp-block-group.is-layout-flex:has(>.wp-block-list.is-style-voyager-open-ticks){container:voyager-open-ticks/inline-size}'
				. '@container voyager-open-ticks (max-width:32em){.wp-block-list.is-style-voyager-open-ticks{flex-direction:column;width:100%}}',
		]
	);

	register_block_style(
		'core/group',
		[
			'name'         => 'voyager-open-marquee',
			'label'        => __( 'Marquee', 'voyager-open-blocks' ),
			'inline_style' => '.wp-block-group.is-style-voyager-open-marquee{overflow:hidden;container-type:inline-size}'
				. '.wp-block-group.is-style-voyager-open-marquee>.wp-block-group{flex-wrap:nowrap;width:max-content;animation:voyager-open-marquee var(--voyager-open-marquee-duration,24s) ease-in-out infinite alternate}'
				. '.wp-block-group.is-style-voyager-open-marquee:hover>.wp-block-group,.wp-block-group.is-style-voyager-open-marquee:focus-within>.wp-block-group{animation-play-state:paused}'
				. '@keyframes voyager-open-marquee{from{transform:translateX(0)}to{transform:translateX(min(0px,calc(100cqw - 100%)))}}'
				. '@media (prefers-reduced-motion:reduce){.wp-block-group.is-style-voyager-open-marquee>.wp-block-group{animation:none;flex-wrap:wrap;width:auto}}',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\register' );
