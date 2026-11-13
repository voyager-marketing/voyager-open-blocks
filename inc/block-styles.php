<?php
/**
 * Block styles the patterns use, registered in PHP so theme.json can style them,
 * and the scroll reveal a few of the patterns' rows carry.
 *
 * - core/list and voyager-open/facts "Ticks": a wrapping row of checkmarked
 *   items (proof strip). In a flex group, the group becomes a size
 *   container, and the ticks stack as a column when it is narrower than
 *   32em. A section can swap a Ticks core/list for a Ticks facts block and
 *   look the same.
 * - core/group "Marquee": its logo row loops sideways at a steady speed when
 *   it is wider than the marquee. At render the row is followed by a copy of
 *   itself, hidden from screen readers and inert, so the loop has no visible
 *   join; the stored page holds each logo once. Still under reduced motion,
 *   and paused on hover and focus.
 * - core/details "Question": one question of an FAQ. Its summary is a tap
 *   target at least 44px tall, and a 1px rule sits above each question and
 *   below the last.
 * - The `voyager-open-reveal` class: a block fades and lifts into place as it
 *   enters the viewport, in CSS alone, and only with motion on in a browser
 *   with scroll-driven animations. Its stylesheet loads only on a page that
 *   renders a block carrying the class.
 *
 * Colours come from currentColor, so every style follows the theme.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\BlockStyles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The class the Marquee style puts on its core/group.
 */
const MARQUEE_CLASS = 'is-style-voyager-open-marquee';

/**
 * The class on the copy of a Marquee group's logo row.
 */
const MARQUEE_COPY_CLASS = 'voyager-open-marquee-copy';

/**
 * The key mark_marquee_row() puts on the parsed block of a Marquee group's
 * logo row, for copy_marquee_row() to find.
 */
const MARQUEE_ROW_KEY = 'voyager_open_marquee_row';

/**
 * The class that gives a block the scroll reveal.
 */
const REVEAL_CLASS = 'voyager-open-reveal';

/**
 * The handle of the reveal's stylesheet: inline CSS with no file.
 */
const REVEAL_HANDLE = 'voyager-open-reveal';

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
 * The Marquee rules.
 *
 * The marquee lays its row and the row's copy side by side, and each row ends
 * in an empty ::after, which the row's flex gap puts one gap before, so the
 * copy starts one gap after the row's last logo. Both rows move left by their
 * own width, linearly, then start again, where the copy has taken the row's
 * place: the loop has no visible join.
 *
 * How far a row moves is clamp(0px, (100% - 100cqw) * 10000000, 100%): in a
 * transform, 100% is the row's own width and 100cqw the marquee's, so it is
 * the row's width when the row is wider than the marquee and 0 when it fits.
 * The copy's clip-path uses the same sum the other way round, so a row that
 * fits stays still and its copy is clipped away. In a right-to-left page the
 * rows move right instead.
 *
 * With reduced motion the copy is not displayed, and the row wraps and stays
 * still. So does a row with no copy beside it, which is how the block editor
 * shows it and how a REST response or a feed carries it (copy_marquee_row()),
 * so a reader sees and reaches every logo and never a gap in a loop.
 */
function marquee_css(): string {
	$group = '.wp-block-group.' . MARQUEE_CLASS;
	$rows  = $group . '>.wp-block-group';
	$copy  = $group . '>.' . MARQUEE_COPY_CLASS;
	$still = static fn ( string $marquee ): string => $marquee . '{display:block}'
		. $marquee . '>.wp-block-group{animation:none;flex-wrap:wrap;width:auto}'
		. $marquee . '>.wp-block-group::after{content:none}'
		. $marquee . '>.' . MARQUEE_COPY_CLASS . '{display:none}';

	return $group . '{display:flex;overflow:hidden;container-type:inline-size}'
		. $rows . '{flex:none;flex-wrap:nowrap;width:max-content;margin-block:0;animation:voyager-open-marquee var(--voyager-open-marquee-duration,24s) linear infinite}'
		. $rows . '::after{content:""}'
		. $copy . '{clip-path:inset(0 0 0 clamp(0px,(100cqw - 100%) * 10000000,100%))}'
		. $group . ':hover>.wp-block-group,' . $group . ':focus-within>.wp-block-group{animation-play-state:paused}'
		. $group . ':dir(rtl)>.wp-block-group{--voyager-open-marquee-way:1}'
		. '@keyframes voyager-open-marquee{from{transform:translateX(0)}to{transform:translateX(calc(var(--voyager-open-marquee-way,-1) * clamp(0px,(100% - 100cqw) * 10000000,100%)))}}'
		. '@media (prefers-reduced-motion:reduce){' . $still( $group ) . '}'
		. $still( $group . ':not(:has(>.' . MARQUEE_COPY_CLASS . '))' );
}

/**
 * The Question rules.
 *
 * WordPress 7.1 styles a details block's summary with `cursor: pointer` and
 * nothing else (wp-includes/blocks/details/style.css), so its height is one
 * line of the theme's body text, under 44px on the themes the design checks
 * run. A minimum height with the box sized from its border, and half a line
 * of padding above and below, make it a 44px tap target on any theme without
 * changing the theme's type. The rules take the text colour, as every rule in
 * the pack does, so they follow the section style. An open question keeps a
 * little room under its answer, above the next rule.
 */
function question_css(): string {
	$details = '.wp-block-details.is-style-voyager-open-question';

	return $details . '{border-block-start:1px solid}'
		. $details . ':last-child{border-block-end:1px solid}'
		. $details . '[open]{padding-block-end:.5em}'
		. $details . '>summary{box-sizing:border-box;min-height:44px;padding-block:.5em}';
}

/**
 * The reveal rules.
 *
 * Two animations, both bound to scroll, inside
 * `@media (prefers-reduced-motion: no-preference)` and an `@supports` for
 * view() and animation-composition, so a visitor with motion off, or a
 * browser without them, sees the block in place:
 *
 * - voyager-open-reveal, on the block's view() timeline over
 *   `entry 0% entry 20vh`: from no opacity and 2rem down to the block's own
 *   style, over the first 20vh the page scrolls once the block's top edge
 *   enters the viewport. Its fill mode is none, so outside that range the
 *   block shows as it is: a block below the first screen is at full opacity
 *   in a full-page capture, which does not scroll.
 * - voyager-open-reveal-hold, on the scroller's own scroll() timeline over
 *   its first 20vh, added on top: opacity 1 at the top of the page, falling
 *   to 0 by 20vh. A block that is half on the first screen when the page
 *   loads is held at full opacity, so it never shows faded at rest at the
 *   top of the page, and the two ranges are the same length, so it never
 *   fades as the visitor starts to scroll either: opacity is clamped to 1.
 */
function reveal_css(): string {
	return '@media (prefers-reduced-motion:no-preference){@supports (animation-timeline:view()) and (animation-composition:add){'
		. '.' . REVEAL_CLASS . '{animation:voyager-open-reveal 1ms linear,voyager-open-reveal-hold 1ms linear both;animation-timeline:view(),scroll();animation-range:entry 0% entry 20vh,0 20vh;animation-composition:replace,add}'
		. '}}'
		. '@keyframes voyager-open-reveal{from{opacity:0;translate:0 2rem}}'
		. '@keyframes voyager-open-reveal-hold{from{opacity:1}to{opacity:0}}';
}

/**
 * Whether a parsed block's className holds a class, as a whole word.
 *
 * @param mixed  $block      A parsed block.
 * @param string $class_name The class.
 */
function has_class( mixed $block, string $class_name ): bool {
	$classes = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? ( $block['attrs']['className'] ?? '' ) : '';

	return is_string( $classes ) && in_array( $class_name, preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY ) ?: [], true );
}

/**
 * Mark a Marquee group's logo row as it renders: the first inner block of a
 * group whose className holds the Marquee class, when it is a core/group.
 *
 * The Marquee group is found by its class, never by its name, which an
 * editor can change. WP_Block::render() applies render_block_data to each
 * inner block with the block it sits in (wp-includes/class-wp-block.php),
 * and its render_block filter then receives the parsed block this returns.
 * The first inner block is the one whose parsed block is the source block:
 * by the time a later inner block renders, the first has been marked.
 *
 * @param mixed $parsed_block The block about to render.
 * @param mixed $source_block The block as parsed, before any filter.
 * @param mixed $parent_block The WP_Block it sits in, or null at the top.
 * @return mixed The block, with MARQUEE_ROW_KEY set when it is the row.
 */
function mark_marquee_row( mixed $parsed_block, mixed $source_block = null, mixed $parent_block = null ): mixed {
	if ( ! is_array( $parsed_block )
		|| 'core/group' !== ( $parsed_block['blockName'] ?? null )
		|| ! $parent_block instanceof \WP_Block
		|| ! has_class( $parent_block->parsed_block, MARQUEE_CLASS ) ) {
		return $parsed_block;
	}

	$first = $parent_block->inner_blocks[0] ?? null;
	if ( ! $first instanceof \WP_Block || $first->parsed_block !== $source_block ) {
		return $parsed_block;
	}

	$parsed_block[ MARQUEE_ROW_KEY ] = true;
	return $parsed_block;
}

/**
 * Whether blocks are rendering for a page a visitor sees in a browser: not a
 * REST response, a feed or an admin request. There the copy would only show
 * each logo twice, since aria-hidden and inert mean nothing to an agent
 * reading `content.rendered` or to a feed reader.
 *
 * In WordPress 7.1.4:
 * - wp_is_rest_endpoint() (wp-includes/rest-api.php) is true for a REST
 *   request, through wp_is_serving_rest_request() and the REST_REQUEST
 *   constant rest_api_loaded() defines, and for a REST request dispatched
 *   inside a page load (WP_REST_Server::is_dispatching()). The posts
 *   controller fills `content.rendered` through the_content
 *   (class-wp-rest-posts-controller.php).
 * - is_feed() (wp-includes/query.php) is true for a feed, whose content
 *   get_the_content_feed() (wp-includes/feed.php) runs through the_content.
 *   It needs the main query, so it is asked only once that exists.
 * - is_admin() (wp-includes/load.php) is true in the admin, and for
 *   admin-ajax.php, which defines WP_ADMIN.
 *
 * wp_is_json_request() is not used: it reads the request's Accept and
 * Content-Type headers, which say what the client wants rather than where
 * the content goes, and every REST request it would catch is caught above.
 */
function is_page_render(): bool {
	return ! wp_is_rest_endpoint()
		&& ! is_admin()
		&& ! ( isset( $GLOBALS['wp_query'] ) && is_feed() );
}

/**
 * Follow a marked logo row with a copy of itself, made from the row as it
 * rendered, on a page render only (is_page_render()). The copy is hidden
 * from screen readers (aria-hidden), cannot be focused or clicked (inert),
 * carries MARQUEE_COPY_CLASS, and holds no id, so no id appears twice on
 * the page. A marquee with no copy wraps and stays still (marquee_css()).
 *
 * @param mixed $block_content The row's HTML.
 * @param mixed $block         The row's parsed block.
 * @return mixed The HTML, followed by the copy when the row is marked.
 */
function copy_marquee_row( mixed $block_content, mixed $block ): mixed {
	if ( ! is_string( $block_content ) || ! is_array( $block ) || true !== ( $block[ MARQUEE_ROW_KEY ] ?? null ) || ! is_page_render() ) {
		return $block_content;
	}

	$copy = new \WP_HTML_Tag_Processor( $block_content );
	if ( ! $copy->next_tag() ) {
		return $block_content;
	}
	$copy->add_class( MARQUEE_COPY_CLASS );
	$copy->set_attribute( 'aria-hidden', 'true' );
	$copy->set_attribute( 'inert', true );
	do {
		$copy->remove_attribute( 'id' );
	} while ( $copy->next_tag() );

	return $block_content . $copy->get_updated_html();
}

/**
 * Load the reveal's stylesheet when a block carrying the class renders.
 *
 * A block theme renders its template before wp_head(), so the styles print
 * in the head; a classic theme renders the content after it, so they print
 * in the footer (print_late_styles()).
 *
 * @param mixed $block_content The block's HTML, passed through.
 * @param mixed $block         The parsed block.
 * @return mixed The HTML, unchanged.
 */
function enqueue_reveal( mixed $block_content, mixed $block ): mixed {
	if ( has_class( $block, REVEAL_CLASS ) ) {
		wp_enqueue_style( REVEAL_HANDLE );
	}
	return $block_content;
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
			'inline_style' => marquee_css(),
		]
	);

	register_block_style(
		'core/details',
		[
			'name'         => 'voyager-open-question',
			'label'        => __( 'Question', 'voyager-open-blocks' ),
			'inline_style' => question_css(),
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\register' );

/**
 * Register the reveal's stylesheet, which enqueue_reveal() loads.
 */
function register_reveal(): void {
	wp_register_style( REVEAL_HANDLE, false, [], VOYAGER_OPEN_BLOCKS_VERSION );
	wp_add_inline_style( REVEAL_HANDLE, reveal_css() );
}
add_action( 'init', __NAMESPACE__ . '\register_reveal' );

add_filter( 'render_block_data', __NAMESPACE__ . '\mark_marquee_row', 10, 3 );
add_filter( 'render_block_core/group', __NAMESPACE__ . '\copy_marquee_row', 10, 2 );
add_filter( 'render_block', __NAMESPACE__ . '\enqueue_reveal', 10, 2 );
