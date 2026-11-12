<?php
/**
 * Block styles the patterns use, registered in PHP so theme.json can style them.
 *
 * - core/list and voyager-open/facts "Ticks": a wrapping row of checkmarked
 *   items (proof strip). In a flex group, the group becomes a size
 *   container, and the ticks stack as a column when it is narrower than
 *   32em. A section can swap a Ticks core/list for a Ticks facts block and
 *   look the same.
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
 * The Ticks rules for one list block.
 *
 * Each block's style carries the rules for its own class. Core adds a style's
 * inline CSS to that block's stylesheet when the site loads block assets on
 * demand (enqueue_block_styles_assets()), and core/list's stylesheet loads
 * only on a page that shows a core/list, so one set of rules on core/list
 * alone would leave a page with only a facts list unstyled.
 *
 * @param string $list The list's class selector, such as `.wp-block-list`.
 */
function ticks_css( string $list ): string {
	$tick  = rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.75 6 6 9-13.5"/></svg>' );
	$ticks = $list . '.is-style-voyager-open-ticks';

	return $ticks . '{list-style:none;padding-inline-start:0;display:flex;flex-wrap:wrap;gap:.75em 2em}'
		. $ticks . '>li{position:relative;margin:0;padding-inline-start:1.6em}'
		. $ticks . '>li::before{content:"";position:absolute;inset-inline-start:0;top:.2em;width:1.1em;height:1.1em;background:currentColor;'
		. '-webkit-mask:url("data:image/svg+xml,' . $tick . '") center/contain no-repeat;mask:url("data:image/svg+xml,' . $tick . '") center/contain no-repeat}'
		. '.wp-block-group.is-layout-flex:has(>' . $ticks . '){container:voyager-open-ticks/inline-size}'
		. '@container voyager-open-ticks (max-width:32em){' . $ticks . '{flex-direction:column;width:100%}}';
}

/**
 * Register the styles.
 */
function register(): void {
	foreach ( [ 'core/list' => '.wp-block-list', 'voyager-open/facts' => '.wp-block-voyager-open-facts' ] as $block => $list ) {
		register_block_style(
			$block,
			[
				'name'         => 'voyager-open-ticks',
				'label'        => __( 'Ticks', 'voyager-open-blocks' ),
				'inline_style' => ticks_css( $list ),
			]
		);
	}

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
