#!/usr/bin/env node
/**
 * The rendered design checks, `contrast`, `overflow`, `motion`, `layout`,
 * `a11y` and `impeccable` as DESIGN.md names them, measured on real renders
 * of every pattern in every section style.
 *
 *   npm run design-check                boot a fresh dev site, render, measure, stop
 *   npm run design-check -- --plant     every planted fault in the main run too: exit 1 when each check fails on its own plants only, else 3
 *   npm run contrast                    the contrast part alone (--checks contrast)
 *   npm run design-check -- --themes twentytwentyfive --no-variations
 *   npm run design-check -- --help      every option
 *
 * Boots the dev site the way npm run dev does (WordPress Playground with this
 * checkout as the plugin), once for each WordPress version the themes in
 * tests/fixtures/contrast.json name, with a theme the fixture pins to a
 * commit installed from GitHub. Then, for each theme:
 *
 *   1. switches to it and builds one page that holds every pattern in
 *      patterns/ once per section style (plain, panel, inverted, accent), the
 *      style put on through SectionStyles\apply() (what configure calls), and
 *      a second page that holds the planted faults. A pattern added later is
 *      picked up with no fixture change. Then it builds one page per recipe in
 *      recipes/ with compose, the sections in order, each in its recipe style
 *      with the patterns' own copy, and recipe:plant, the skipped-heading
 *      plant between two shipped sections. A recipe added later is picked up
 *      with no fixture change too;
 *   2. checks it is measuring the right thing: every pattern file is
 *      registered, get-catalog's role map is the one the fixture names, each
 *      styled section carries the instance class WordPress adds when it renders
 *      a style's style_data, and Inverted is painted in the theme's own text
 *      colour with its own background colour as text;
 *   3. at each width loads the page, then each recipe page, in headless
 *      Chrome as a visitor and, per section:
 *      - contrast: measures every element that holds text against its
 *        background, at rest, and links and buttons hovered too, from computed
 *        styles. The background is found by walking up through transparent
 *        ancestors and compositing each translucent layer, and a background
 *        image on that path counts as a failure. Text in a core/cover that
 *        shows an image is measured against the cover's overlay at its
 *        opacity (its dimRatio), composited once over black and once over
 *        white, the two extremes any image can reach, and the lower ratio
 *        counts; a gradient overlay counts as a failure;
 *      - overflow: fails when anything in the section reaches past the right
 *        edge of the viewport with no clipping ancestor inside the section.
 *        The page as a whole fails when the document is wider than the
 *        viewport, so it scrolls sideways;
 *      - a11y: runs axe-core in the page on the sections only, with the WCAG
 *        2.0 and 2.1 A and AA rules and heading-order, and fails on any
 *        violation;
 *      - impeccable: runs impeccable detect --json on the page at that width
 *        and places each finding in the section its selector matches. A
 *        finding of a rule the fixture gates fails that section; the rest are
 *        printed as advisories. Impeccable has no tap-target rule, so this
 *        check measures every link, button and form control in the section
 *        too, and fails one under 44px unless the fixture lists it as the
 *        theme's known gap;
 *      then loads it again with prefers-reduced-motion: reduce emulated and,
 *      per section:
 *      - motion: fails when an animation or transition is running, or when
 *        any element's box moves between two samples taken apart. Overflow is
 *        measured again, since a reduced-motion rule can change the layout;
 *      - layout: fails when a row of items (core/columns, or a group with the
 *        grid layout) renders other than the columns the fixture names for
 *        its pattern at the wide width, or more than one at a stacked width,
 *        or when a word in a heading breaks across lines or spills out of
 *        its box;
 *   4. runs thirteen controls, each of which must fail its own check or the run
 *      fails: the fixture's low-contrast role map, planted through the
 *      voyager_open_blocks_style_roles filter (contrast); white text on a
 *      cover with a white overlay at 50% (contrast, measured against the
 *      overlay over black and over white); a section with an element wider
 *      than 320px (overflow); a CSS animation with no reduced-motion rule
 *      (motion); an h2 followed by an h4 (a11y, on heading-order), alone
 *      and again on recipe:plant, where the shipped sections must pass; and
 *      gradient text, a card inside a card and a 30px tall button
 *      (impeccable, each on its own rule); and a grid whose cards are each
 *      wider than half the row, columns not stacked on a phone, and a heading
 *      word in a box narrower than itself, free to break and not (layout,
 *      each on its own reason). One more must pass: white text on
 *      a cover with a black overlay at 80%, which reads over any image, so
 *      the cover measurement is seen to read the overlay and not the page;
 *   5. where the fixture asks, opens the page in the block editor as admin and
 *      checks the Styles panel offers Panel, Inverted and Accent and the canvas
 *      paints Inverted as the front end does;
 *   6. on a theme the fixture gives site_editor_colours, saves them in the
 *      Site Editor's global styles and checks every Panel, Inverted and
 *      Accent section paints from them, as the fallback roles would;
 *   7. where the fixture lists style variations, switches each colour-bearing
 *      variation the theme ships on the way the Site Editor saves one, and
 *      measures contrast on the pattern page at the fixture's variation
 *      widths. The recipe pages are left out there: each of their sections is
 *      a pattern in a style the pattern page holds, with its own copy. A pair
 *      under the floor fails unless the fixture lists it as a known failure,
 *      and a listed failure that no longer fails fails too.
 *
 * The run fails when any check fails outside the known failures, when fewer
 * renders or pairs were measured or scanned than the fixture expects, when a
 * scan did not run, when a positive control or a planted control fails, or on
 * a PHP fatal error. --themes runs some of the themes, held to their share of
 * the fixture's floors, and every plant still goes on each theme it runs, so
 * one --plant run per theme proves each check fails its plants on that theme.
 *
 * Needs Chrome, Chromium or Edge (set CHROME_PATH to pick one) and the Node
 * in .nvmrc. The first run downloads WordPress; later runs reuse it.
 */
import { execFile } from 'node:child_process';
import { appendFileSync, existsSync, readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';

import { boot, parseFlags, phpLog, reportPhpLog, runPhp } from './dev.mjs';
import { findChrome, launch } from './lib/chrome.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { recipePages } from './lib/recipes.mjs';
import { repo } from './lib/site.mjs';

const FIXTURE = join( repo, 'tests', 'fixtures', 'contrast.json' );
const PATTERNS = join( repo, 'patterns' );
const PLANT_OPTION = 'voyager_open_contrast_plant';
const SECTION_CLASS = 'voyager-open-check--';
const TIMEOUT_MINUTES = 25;
const CHECKS = [
	'contrast',
	'overflow',
	'motion',
	'layout',
	'a11y',
	'impeccable',
];
/** The checks that judge each section render and keep one row per render. */
const RENDER_CHECKS = [ 'overflow', 'motion', 'layout', 'a11y', 'impeccable' ];
/** The rule the impeccable check measures itself: Impeccable 4.8.2 has none for tap targets. */
const TAP_TARGET = 'tap-target';
/**
 * The planted sections contrast measures: two covers over the pack's 16:9
 * placeholder, each with an overlay of its own and white text. The others
 * are controls for the other checks, and contrast skips them.
 */
const COVER_PLANTS = {
	cover: {
		overlay: '#ffffff',
		dim: 50,
		text: '#ffffff',
		label: 'white text on a cover with a white overlay at 50%',
		words: 'Planted: white text on a white overlay at 50%.',
	},
	'cover-readable': {
		overlay: '#000000',
		dim: 80,
		text: '#ffffff',
		label: 'white text on a cover with a black overlay at 80%',
		words: 'Control: white text on a black overlay at 80%.',
	},
};
/**
 * The planted sections the layout check measures, each a fault it must fail
 * on its own reason, at the fixture's wide width or its first stacked one:
 * a grid whose cards are each wider than half the row (the shape of the
 * reviews grid that rendered one column), columns kept side by side on a
 * phone, and a heading word in a box narrower than the word, once allowed to
 * break anywhere and once not. columns is what the row would render if it
 * worked.
 */
const LAYOUT_PLANTS = {
	columns: {
		columns: 3,
		at: 'wide',
		reason: 'columns',
		label: 'grid of three cards at least 60rem wide each, which renders one column',
	},
	stack: {
		columns: 3,
		at: 'stacked',
		reason: 'stack',
		label: 'row of three columns not stacked on mobile',
	},
	break: {
		at: 'wide',
		reason: 'break',
		label: 'heading word in a box narrower than itself, free to break anywhere',
	},
	spill: {
		at: 'wide',
		reason: 'spill',
		label: 'heading word in a box narrower than itself, not free to break',
	},
};
/** What buildPages() swaps for the plugin's assets URL in the planted markup. */
const ASSETS_MARK = '{{voyager-open-assets}}/';
/** The skipped-heading plant a11y must fail on heading-order: an h2, then an h4. */
const HEADING_PLANT =
	'<h2>Planted: a section heading</h2>\n<h4>Planted: a heading two levels below it</h4>\n<p>An h2 followed by an h4, with no h3 between them.</p>';
/**
 * The planted recipe page, recipe:plant: HEADING_PLANT as section `at`,
 * between the first two sections of the first recipe as compose builds them.
 * a11y must fail it there, which shows the recipe pages go through the
 * checks and are not only rendered. Every run measures it as an a11y
 * control, and --plant puts it in the main run.
 */
const RECIPE_PLANT = { slug: 'plant', at: 2 };
const require = createRequire( import.meta.url );

/** Loads the planted role map from an option, so a run can switch it on and off. */
const PLANT_PLUGIN = `<?php
/**
 * Written by scripts/design-check.mjs into a throwaway dev site.
 * Merges the role map in the ${ PLANT_OPTION } option over the pack's.
 */
add_filter(
	'voyager_open_blocks_style_roles',
	static function ( $map ) {
		$plant = get_option( '${ PLANT_OPTION }' );
		return is_array( $plant ) ? array_merge( (array) $map, $plant ) : $map;
	}
);
`;

/**
 * A full-width section group around some markup, as a planted section is
 * built. className, when given, is the class the run finds it by.
 *
 * @param {string} inner     Block markup inside the group.
 * @param {string} className Class on the group, or none.
 * @return {string} Block markup.
 */
function sectionGroup( inner, className = '' ) {
	const attribute = className ? `"className":"${ className }",` : '';
	return `<!-- wp:group {"tagName":"section",${ attribute }"align":"full","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull${
		className ? ` ${ className }` : ''
	}">${ inner }</section>
<!-- /wp:group -->`;
}

/**
 * Raw HTML as a core/html block. Test markup only.
 *
 * @param {string} html
 * @return {string} Block markup.
 */
function rawHtml( html ) {
	return `<!-- wp:html -->\n${ html }\n<!-- /wp:html -->`;
}

/**
 * The key of a section on a recipe page, recipe--<slug>--<n>, with n counted
 * from 1 in page order. It is the section's class after SECTION_CLASS.
 *
 * @param {string} slug The recipe's slug, or RECIPE_PLANT.slug.
 * @param {number} at   The section's place on the page.
 * @return {string} Section key.
 */
function recipeKey( slug, at ) {
	return `recipe--${ slug }--${ at }`;
}

/**
 * Where a section is, for a printed row: its pattern, after its recipe page
 * and place there when it is on one.
 *
 * @param {{pattern: string, page?: string, at?: number}} where A row, or an entry of the sections map.
 * @return {string} Such as cta-band, or recipe:home 4 cta-band.
 */
function placeOf( where ) {
	return where.page
		? `${ where.page } ${ where.at } ${ where.pattern }`
		: where.pattern;
}

/**
 * The planted faults, as sections. Test markup only: patterns never use
 * core/html or an inline style, and npm run check's presets gate says so.
 *
 * The covers are core/cover markup as core saves it (the overlay span, then
 * the image, then the inner container; Twenty Twenty-Five's patterns hold
 * the same), over the pack's 16:9 placeholder at the plugin's URL, which
 * buildPages() puts in for ASSETS_MARK.
 *
 * @param {number} width How wide the overflow plant's element is, in px.
 * @return {Object<string, string>} Plant name => block markup.
 */
function plantSections( width ) {
	const wrap = ( name, inner ) =>
		sectionGroup( inner, `${ SECTION_CLASS }plant--${ name }` );
	const section = ( name, html ) => wrap( name, rawHtml( html ) );
	const cover = ( name, { overlay, dim, text, words } ) => {
		const image = `${ ASSETS_MARK }placeholders/image-16x9.svg`;
		const light = overlay === '#ffffff';
		return wrap(
			name,
			`<!-- wp:cover {"url":"${ image }","dimRatio":${ dim },"customOverlayColor":"${ overlay }","isUserOverlayColor":true,"focalPoint":{"x":0.5,"y":0.5},${
				light ? '"isDark":false,' : ''
			}"layout":{"type":"constrained"}} -->
<div class="wp-block-cover${
				light ? ' is-light' : ''
			}"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-${ dim } has-background-dim" style="background-color:${ overlay }"></span><img class="wp-block-cover__image-background" alt="" src="${ image }" style="object-position:50% 50%" data-object-fit="cover" data-object-position="50% 50%"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"style":{"color":{"text":"${ text }"}}} -->
<p class="has-text-color" style="color:${ text }">${ words }</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->`
		);
	};
	return {
		...Object.fromEntries(
			Object.entries( COVER_PLANTS ).map( ( [ name, spec ] ) => [
				name,
				cover( name, spec ),
			] )
		),
		overflow: section(
			'overflow',
			`<div style="width:${ width }px;min-width:${ width }px">Planted: an element ${ width }px wide.</div>`
		),
		motion: section(
			'motion',
			'<style>@keyframes voyager-open-check-drift{to{transform:translateY(8px)}}.voyager-open-check-drift{display:inline-block;animation:voyager-open-check-drift 1s linear infinite alternate}</style>\n<p class="voyager-open-check-drift">Planted: a CSS animation with no reduced-motion rule.</p>'
		),
		heading: section( 'heading', HEADING_PLANT ),
		gradient: section(
			'gradient',
			'<h2 style="background-image:linear-gradient(90deg,#b00020,#1a3fd6);-webkit-background-clip:text;background-clip:text;color:transparent">Planted: a heading in gradient text</h2>'
		),
		cards: section(
			'cards',
			'<div style="border:1px solid currentColor;border-radius:12px;padding:24px"><p>Planted: a card that holds a second card.</p><div style="border:1px solid currentColor;border-radius:12px;padding:24px"><p>Planted: the card inside the card.</p></div></div>'
		),
		target: section(
			'target',
			'<p><a href="#planted-target" style="display:inline-block;box-sizing:border-box;height:30px;line-height:28px;padding:0 16px;border:1px solid currentColor;text-decoration:none">Planted: a 30px tall button</a></p>'
		),
		// Core blocks, so core's layout support writes the row's CSS as it
		// does a pattern's.
		columns: wrap(
			'columns',
			`<!-- wp:group {"align":"wide","layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"60rem"}} -->
<div class="wp-block-group alignwide">${ [ 1, 2, 3 ]
				.map(
					( n ) =>
						`<!-- wp:paragraph -->\n<p>Planted: card ${ n } of a grid with three columns at least 60rem wide.</p>\n<!-- /wp:paragraph -->`
				)
				.join( '\n' ) }</div>
<!-- /wp:group -->`
		),
		stack: wrap(
			'stack',
			`<!-- wp:columns {"isStackedOnMobile":false,"align":"wide"} -->
<div class="wp-block-columns alignwide is-not-stacked-on-mobile">${ [ 1, 2, 3 ]
				.map(
					( n ) =>
						`<!-- wp:column -->\n<div class="wp-block-column"><!-- wp:paragraph -->\n<p>Planted: column ${ n }.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->`
				)
				.join( '\n' ) }</div>
<!-- /wp:columns -->`
		),
		break: section(
			'break',
			'<h3 style="width:4em;overflow-wrap:anywhere;hyphens:manual">Installation</h3>'
		),
		spill: section(
			'spill',
			'<h3 style="width:4em;overflow-wrap:normal;word-break:normal;hyphens:manual">Installation</h3>'
		),
	};
}

const HELP = `npm run design-check [-- options]

Boots a fresh dev site (npm run dev), renders every pattern in patterns/ in
every section style on each theme in tests/fixtures/contrast.json, and each
recipe in recipes/ as a page, its sections in their recipe styles. Loads each
page in headless Chrome at each width, and runs six checks on every section:
contrast (text under 4.5:1 against its background), overflow (the page
scrolls sideways), motion (anything moves with reduced motion on), layout (a
row of cards renders the wrong number of columns, or a heading word breaks
or spills), a11y (axe-core reports a violation) and impeccable (a gated
Impeccable rule fires, or a tap target is under 44px). Then it measures contrast on each
colour-bearing style variation the fixture lists. Text on a cover with an
image is measured against the overlay over black and over white, and the
lower ratio counts. Exits 1 if any check fails outside the fixture's known
failures and gaps, if a scan did not run, if a planted control does not fail
its own check, or if the readable cover control does not pass.

  --checks <list>  Only these checks, such as --checks contrast (what
                   npm run contrast runs). Default
                   contrast,overflow,motion,layout,a11y,impeccable.
  --plant          Put every planted fault in the main run as well: the
                   low-contrast role map, white text on a cover with a white
                   overlay at 50%, a section wider than 320px, an animation
                   with no reduced-motion rule, an h2 followed by an h4,
                   gradient text, a card inside a card, a 30px tall button,
                   the four layout faults, and recipe:plant, a recipe page
                   with the h2 and h4 between two shipped sections. Exits 1
                   when each check fails on its own plants and nothing else
                   fails, and 3 when the run fails any other way or a check
                   misses its plant.
  --themes <list>  Only these themes, such as --themes twentytwentyfive.
                   The render counts expected are those themes' own, and the
                   fixture's floors on renders and pairs scale to their share
                   of its themes. CI runs one job per theme this way.
  --no-variations  Skip the style variations.
  --port <n>       Port to serve on. Default 9400, or a free port if 9400 is taken.
  --wp <version>   WordPress version for every theme, such as 7.1.4. Default
                   each theme's wp in the fixture.
  --php <version>  PHP version, such as 8.1. Default 8.3.
  --debug          Print Playground's debug output and error stacks.
  --help           Show this help.

Set CHROME_PATH to use a particular Chrome, Chromium or Edge binary.`;

const KNOWN = [
	'checks',
	'plant',
	'themes',
	'no-variations',
	'port',
	'wp',
	'php',
	'debug',
	'help',
];

/** @type {Array<{name: string, ok: boolean, observed: string}>} */
const checks = [];

/**
 * In a --plant run the plants make assertions fail on purpose, so a failure
 * prints as a line and not as an annotation: the one that counts is
 * plantedProblems()'s verdict on the whole run.
 */
let plantedRun = false;

/**
 * @param {string} text
 * @param {number} length
 * @return {string} The text, cut to length.
 */
function clip( text, length ) {
	return text.length > length ? `${ text.slice( 0, length ) }...` : text;
}

/**
 * Record one assertion: print it, and annotate it when it fails.
 *
 * @param {string}  name     What is asserted.
 * @param {unknown} ok       Truthy when it holds.
 * @param {unknown} observed What was seen.
 * @return {boolean} Whether it held.
 */
function expect( name, ok, observed = '' ) {
	const detail =
		typeof observed === 'string' ? observed : JSON.stringify( observed );
	checks.push( { name, ok: !! ok, observed: detail } );
	if ( ok ) {
		console.log(
			`ok   ${ name }${ detail ? ` (${ clip( detail, 160 ) })` : '' }`
		);
	} else if ( plantedRun ) {
		console.log(
			`FAIL ${ name }. Observed: ${ clip( detail || 'nothing', 1800 ) }`
		);
	} else {
		annotate(
			'error',
			`${ name }. Observed: ${ clip( detail || 'nothing', 1800 ) }`,
			name
		);
	}
	return !! ok;
}

/**
 * @param {string} value
 * @return {string} A single-quoted PHP string literal.
 */
function phpString( value ) {
	return `'${ String( value ).replace( /[\\']/g, '\\$&' ) }'`;
}

/**
 * An expression that calls a function in the page with JSON arguments. The
 * function must not use anything from this module's scope.
 *
 * @param {(...args: unknown[]) => unknown} fn   Function to run in the page.
 * @param {...unknown}                      args Its arguments.
 * @return {string} An expression for Runtime.evaluate.
 */
function js( fn, ...args ) {
	return `(${ fn.toString() })(${ args
		.map( ( arg ) => JSON.stringify( arg ) )
		.join( ', ' ) })`;
}

/**
 * An expression that calls one of the helpers installInPage() put in the page.
 *
 * @param {string}     name Helper name.
 * @param {...unknown} args Its arguments.
 * @return {string} An expression for Runtime.evaluate.
 */
function call( name, ...args ) {
	return `window.voyagerOpenCheck.${ name }(${ args
		.map( ( arg ) => JSON.stringify( arg ) )
		.join( ', ' ) })`;
}

/**
 * In the page: put the measuring helpers on window.voyagerOpenCheck, so each
 * later call is short and the page keeps what it found between calls.
 *
 * Every colour is turned into sRGB by Chrome itself (a 1x1 canvas), so
 * color(), color-mix() results, oklch() and named colours all read the same
 * way.
 *
 * @param {string} prefix The class prefix every measured section carries.
 * @return {boolean} True once installed.
 */
function installInPage( prefix ) {
	const canvas = document.createElement( 'canvas' );
	canvas.width = 1;
	canvas.height = 1;
	const ctx = canvas.getContext( '2d', { willReadFrequently: true } );
	const rgba = ( value ) => {
		ctx.clearRect( 0, 0, 1, 1 );
		ctx.fillStyle = '#010203';
		ctx.fillStyle = value;
		if (
			ctx.fillStyle === '#010203' &&
			value.replace( /\s/g, '' ) !== 'rgb(1,2,3)'
		) {
			return null;
		}
		ctx.fillRect( 0, 0, 1, 1 );
		const [ r, g, b, a ] = ctx.getImageData( 0, 0, 1, 1 ).data;
		return { r, g, b, a: a / 255 };
	};
	const over = ( top, base ) => ( {
		r: top.r * top.a + base.r * ( 1 - top.a ),
		g: top.g * top.a + base.g * ( 1 - top.a ),
		b: top.b * top.a + base.b * ( 1 - top.a ),
		a: 1,
	} );
	const hex = ( c ) =>
		'#' +
		[ c.r, c.g, c.b ]
			.map( ( v ) => Math.round( v ).toString( 16 ).padStart( 2, '0' ) )
			.join( '' );
	const luminance = ( c ) => {
		const ch = ( v ) => {
			const s = v / 255;
			return s <= 0.04045 ? s / 12.92 : ( ( s + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * ch( c.r ) + 0.7152 * ch( c.g ) + 0.0722 * ch( c.b );
	};
	const describe = ( el ) =>
		`<${ el.tagName.toLowerCase() }${ [ ...el.classList ]
			.filter( ( name ) => ! name.startsWith( prefix ) )
			.slice( 0, 2 )
			.map( ( name ) => `.${ name }` )
			.join( '' ) }>`;

	const black = { r: 0, g: 0, b: 0, a: 1 };
	const white = { r: 255, g: 255, b: 255, a: 1 };

	/**
	 * What a core/cover paints under the text inside it, from computed
	 * styles. Core saves the overlay as a span.wp-block-cover__background (or
	 * __gradient-background) beside the image and the inner container: its
	 * colour comes from a has-<slug>-background-color class or an inline
	 * style (black when it has neither), and its opacity from
	 * has-background-dim-<n>, the dimRatio (wp-includes/blocks/cover/style.css).
	 * Older markup paints the overlay as the cover's own ::before.
	 *
	 * @param {Element} cover A .wp-block-cover.
	 * @return {{overlay: Object, opacity: number, media: string, image: string, loaded: boolean|null, note: string}} The overlay, its opacity folded into its alpha; what the cover shows under it ('img', 'video', 'embed', 'background', or '' for nothing); the image's URL and whether it loaded; and a note when the overlay cannot be measured.
	 */
	const coverOf = ( cover ) => {
		const child = ( selector ) =>
			[ ...cover.children ].find( ( c ) => c.matches( selector ) ) ||
			null;
		const span = child(
			'.wp-block-cover__background, .wp-block-cover__gradient-background'
		);
		// eslint-disable-next-line no-nested-ternary
		const paint = span
			? window.getComputedStyle( span )
			: cover.classList.contains( 'has-background-dim' )
				? window.getComputedStyle( cover, '::before' )
				: null;
		const img = child( 'img.wp-block-cover__image-background' );
		let media = '';
		if ( img ) {
			media = 'img';
		} else if ( child( 'video.wp-block-cover__video-background' ) ) {
			media = 'video';
		} else if ( child( '.wp-block-cover__embed-background' ) ) {
			media = 'embed';
		} else if (
			child( '.wp-block-cover__image-background' ) ||
			window.getComputedStyle( cover ).backgroundImage !== 'none'
		) {
			media = 'background';
		}
		const found = {
			overlay: { ...black, a: 0 },
			opacity: 0,
			media,
			image: img ? img.currentSrc || img.src : '',
			loaded: img ? img.complete && img.naturalWidth > 0 : null,
			note: '',
		};
		if ( ! paint ) {
			return found;
		}
		const colour = rgba( paint.backgroundColor );
		if ( paint.backgroundImage !== 'none' ) {
			found.note = `a gradient overlay on ${ describe(
				cover
			) } makes the pair unmeasurable`;
		} else if ( ! colour ) {
			found.note = `unreadable overlay background-color "${ paint.backgroundColor }"`;
		} else {
			const opacity = Number.parseFloat( paint.opacity );
			found.opacity = Number.isFinite( opacity ) ? opacity : 1;
			found.overlay = { ...colour, a: colour.a * found.opacity };
		}
		return found;
	};

	/**
	 * Contrast ratio of two opaque colours, to two decimals.
	 *
	 * @param {Object} a
	 * @param {Object} b
	 * @return {number} Ratio.
	 */
	const ratioOf = ( a, b ) => {
		const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort(
			( x, y ) => y - x
		);
		return Math.round( ( ( hi + 0.05 ) / ( lo + 0.05 ) ) * 100 ) / 100;
	};

	/**
	 * An element's text colour against what is behind it. The background is
	 * the first opaque one walking up from the element, with every
	 * translucent layer on the way composited over it; nothing opaque all
	 * the way up is the white canvas. A background image on that path makes
	 * the pair unmeasurable, which counts as a failure.
	 *
	 * A core/cover on the path is not a background image on an ancestor: its
	 * image and overlay are children of the cover, beside the text's
	 * container. Its overlay is a layer like any other. When the cover shows
	 * an image or a video, nothing behind the overlay can be known, so the
	 * layers are composited once over black and once over white, the two
	 * extremes any image can reach, and the lower ratio counts. A cover with
	 * no image lets the walk go on to what is behind it.
	 *
	 * @param {Element} el
	 * @return {{text: string, background: string, ratio: number|null, note: string, cover: Object|null}} Measurement. cover is set when the text is on a cover: its overlay, what it shows, and the two composites and ratios when it shows an image.
	 */
	const measure = ( el ) => {
		const layers = [];
		let note = '';
		let cover = null;
		for (
			let node = el;
			node && node.nodeType === 1;
			node = node.parentElement
		) {
			const style = window.getComputedStyle( node );
			if ( node.matches( '.wp-block-cover, .wp-block-cover-image' ) ) {
				cover = coverOf( node );
				if ( cover.note ) {
					note = cover.note;
					break;
				}
				if ( cover.overlay.a > 0 ) {
					layers.push( cover.overlay );
				}
				if ( cover.media || cover.overlay.a >= 1 ) {
					break;
				}
			} else if ( style.backgroundImage !== 'none' ) {
				note = `background-image on <${ node.tagName.toLowerCase() }> makes the pair unmeasurable`;
			}
			const bg = rgba( style.backgroundColor );
			if ( ! bg ) {
				note = `unreadable background-color "${ style.backgroundColor }"`;
				break;
			}
			if ( bg.a > 0 ) {
				layers.push( bg );
			}
			if ( bg.a >= 1 ) {
				break;
			}
		}
		const behind = ( base ) => {
			let background = base;
			for ( let i = layers.length - 1; i >= 0; i-- ) {
				background =
					layers[ i ].a >= 1
						? layers[ i ]
						: over( layers[ i ], background );
			}
			return background;
		};
		const overImage = !! cover?.media && ! note;
		const bases = overImage ? [ black, white ] : [ white ];
		const backgrounds = bases.map( behind );
		const coverRow = cover && {
			overlay: hex( cover.overlay ),
			opacity: cover.opacity,
			media: cover.media,
			image: cover.image,
			loaded: cover.loaded,
		};

		// Inside a cover, whatever is under it: the cover sets its own colours.
		const onCover = !! el.closest(
			'.wp-block-cover, .wp-block-cover-image'
		);
		const raw = window.getComputedStyle( el ).color;
		const colour = rgba( raw );
		if ( ! colour ) {
			return {
				text: raw,
				background: hex( backgrounds[ 0 ] ),
				ratio: null,
				note: `unreadable color "${ raw }"`,
				cover: coverRow,
				onCover,
			};
		}
		const pairs = backgrounds.map( ( background ) => {
			const text = colour.a < 1 ? over( colour, background ) : colour;
			return { text, background, ratio: ratioOf( text, background ) };
		} );
		const worst = pairs.reduce( ( a, b ) => ( b.ratio < a.ratio ? b : a ) );
		if ( overImage ) {
			coverRow.black = hex( pairs[ 0 ].background );
			coverRow.white = hex( pairs[ 1 ].background );
			coverRow.ratios = [ pairs[ 0 ].ratio, pairs[ 1 ].ratio ];
		}
		return {
			text: hex( worst.text ),
			background: hex( worst.background ),
			ratio: note ? null : worst.ratio,
			note,
			cover: coverRow,
			onCover,
		};
	};

	const shown = ( el ) => {
		const style = window.getComputedStyle( el );
		return (
			style.display !== 'none' &&
			style.visibility === 'visible' &&
			el.getClientRects().length > 0
		);
	};
	const ownText = ( el ) =>
		[ ...el.childNodes ]
			.filter( ( node ) => node.nodeType === 3 )
			.map( ( node ) => node.textContent )
			.join( ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
	const sectionOf = ( key ) => {
		const found = document.querySelectorAll( `.${ prefix }${ key }` );
		return found.length === 1 ? found[ 0 ] : null;
	};
	const all = ( section ) => [ section, ...section.querySelectorAll( '*' ) ];

	/** Target id => the element holding the text, and what to hover. */
	const byId = new Map();
	let next = 0;

	/**
	 * Every element in a section that holds text of its own, named by kind
	 * and text: a button (fill or outline), a link, a heading or text. nth
	 * is its place among the section's targets of its kind, from 1, which
	 * names it in the fixture's known failures, so new wording leaves the
	 * list alone.
	 *
	 * @param {Element} section
	 * @return {Array<{id: string, name: string, kind: string, nth: number, hover: boolean}>} Targets.
	 */
	const targets = ( section ) => {
		const out = [];
		const names = new Map();
		const kinds = new Map();
		for ( const el of all( section ) ) {
			if ( el.closest( 'script, style, template, noscript, svg' ) ) {
				continue;
			}
			const text = ownText( el );
			if ( ! text || ! shown( el ) ) {
				continue;
			}
			const anchor = el.closest( 'a' );
			const link = anchor && section.contains( anchor ) ? anchor : null;
			const button = el.closest( '.wp-block-button__link' );
			let kind = 'text';
			if ( button ) {
				kind = button.closest( '.wp-block-button.is-style-outline' )
					? 'outline button'
					: 'button';
			} else if ( link ) {
				kind = 'link';
			} else if ( /^H[1-6]$/.test( el.tagName ) ) {
				kind = 'heading';
			}
			const base = `${ kind } "${
				text.length > 32 ? `${ text.slice( 0, 32 ) }...` : text
			}"`;
			const seen = ( names.get( base ) || 0 ) + 1;
			names.set( base, seen );
			const nth = ( kinds.get( kind ) || 0 ) + 1;
			kinds.set( kind, nth );
			const id = String( ++next );
			byId.set( id, { el, hover: button || link } );
			out.push( {
				id,
				name: seen > 1 ? `${ base } #${ seen }` : base,
				kind,
				nth,
				hover: !! ( button || link ),
			} );
		}
		return out;
	};

	/**
	 * Whether anything in a section reaches past the viewport's right edge
	 * with no ancestor inside the section to clip it. An element with
	 * visible overflow reaches as far as its content does (scrollWidth),
	 * so a long word that spills out of its box counts too.
	 *
	 * @param {Element} section
	 * @return {{ok: boolean, note: string}} Result.
	 */
	const overflow = ( section ) => {
		const viewport = document.documentElement.clientWidth;
		let worst = null;
		for ( const el of all( section ) ) {
			const rects = el.getClientRects();
			if ( ! rects.length ) {
				continue;
			}
			const style = window.getComputedStyle( el );
			const rect = el.getBoundingClientRect();
			const left = rect.left + window.scrollX;
			const reach =
				style.overflowX === 'visible'
					? left + Math.max( rect.width, el.scrollWidth )
					: left + rect.width;
			if ( reach <= viewport + 1 ) {
				continue;
			}
			let clipped = false;
			for (
				let up = el.parentElement;
				up && section.contains( up );
				up = up.parentElement
			) {
				if ( window.getComputedStyle( up ).overflowX !== 'visible' ) {
					clipped = true;
					break;
				}
			}
			if ( ! clipped && ( ! worst || reach > worst.reach ) ) {
				worst = { reach, el };
			}
		}
		return worst
			? {
					ok: false,
					note: `${ describe( worst.el ) } reaches ${ Math.round(
						worst.reach
					) }px in a ${ viewport }px viewport`,
				}
			: { ok: true, note: '' };
	};

	/**
	 * One motion sample of a section: what animates, and where every box is.
	 *
	 * @param {Element} section
	 * @return {{running: string[], boxes: number[][]}} Sample.
	 */
	const sampleOf = ( section ) => ( {
		running: section
			.getAnimations( { subtree: true } )
			.filter( ( a ) => a.playState === 'running' )
			.map( ( a ) => {
				const name =
					a.animationName ||
					( a.transitionProperty
						? `transition of ${ a.transitionProperty }`
						: 'a script animation' );
				const target = a.effect?.target;
				return `${ name } on ${ target ? describe( target ) : 'an element' }`;
			} ),
		boxes: all( section ).map( ( el ) => {
			const r = el.getBoundingClientRect();
			return [
				r.left + window.scrollX,
				r.top + window.scrollY,
				r.width,
				r.height,
			].map( ( v ) => Math.round( v * 10 ) / 10 );
		} ),
	} );

	/**
	 * The key of the measured section an element is in, or '' when it is
	 * outside every one of them (the theme's own markup).
	 *
	 * @param {Element|null} el
	 * @return {string} Section key, such as cta-band--plain.
	 */
	const keyOf = ( el ) => {
		const section = el?.closest?.( `[class*="${ prefix }"]` );
		const name = section
			? [ ...section.classList ].find( ( c ) => c.startsWith( prefix ) )
			: '';
		return name ? name.slice( prefix.length ) : '';
	};

	/**
	 * Whether a link sits in a run of text, as a link in a sentence does.
	 * WCAG's target size criteria exempt those, since a line of text sets
	 * their height.
	 *
	 * @param {Element} el
	 * @return {boolean} Inline in text.
	 */
	const inText = ( el ) => {
		if ( window.getComputedStyle( el ).display !== 'inline' ) {
			return false;
		}
		let block = el.parentElement;
		while (
			block &&
			window.getComputedStyle( block ).display.startsWith( 'inline' )
		) {
			block = block.parentElement;
		}
		const around = ( block?.textContent || '' ).replace( /\s+/g, '' );
		const own = ( el.textContent || '' ).replace( /\s+/g, '' );
		return around.length > own.length;
	};

	/**
	 * Every tap target in a section under the minimum, tall or wide: links,
	 * buttons and form controls that are shown, apart from links in a run of
	 * text.
	 *
	 * @param {Element} section
	 * @param {number}  min     Smallest height and width, in px.
	 * @return {{measured: number, small: Array<{name: string, kind: string, height: number, width: number}>}} Result.
	 */
	const tapTargets = ( section, min ) => {
		const found = [
			...section.querySelectorAll(
				'a[href], button, input:not([type="hidden"]), select, textarea, summary, [role="button"], [role="link"]'
			),
		].filter(
			( el ) =>
				shown( el ) &&
				! el.disabled &&
				! inText( el ) &&
				! el.closest( '[inert]' )
		);
		const small = [];
		for ( const el of found ) {
			const rect = el.getBoundingClientRect();
			if ( rect.height >= min && rect.width >= min ) {
				continue;
			}
			let kind = el.tagName.toLowerCase();
			if ( el.classList.contains( 'wp-block-button__link' ) ) {
				kind = el.closest( '.wp-block-button.is-style-outline' )
					? 'outline button'
					: 'button';
			} else if ( kind === 'a' ) {
				kind = 'link';
			}
			const text = ( el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
			small.push( {
				name: `${ kind } "${
					text.length > 32 ? `${ text.slice( 0, 32 ) }...` : text
				}"`,
				kind,
				height: Math.round( rect.height * 10 ) / 10,
				width: Math.round( rect.width * 10 ) / 10,
			} );
		}
		return { measured: found.length, small };
	};

	/** What a card row is: core/columns, or a group with the grid layout. */
	const ROW = '.wp-block-columns, .is-layout-grid';

	/**
	 * The rows of items in a section, and how many columns each renders:
	 * the shown children that sit beside the highest one, sharing more than
	 * a pixel of its height. That is the first line of a row that wraps,
	 * holds a column centred beside a taller one, and in a row that stacks
	 * is the first child alone. A row inside another row is the outer row's
	 * content, not a row of its own.
	 *
	 * @param {Element} section
	 * @return {Array<{columns: number, widths: number[]}>} Rows, in document order, each with its columns and its first line's widths in px.
	 */
	const rowsOf = ( section ) =>
		[ ...section.querySelectorAll( ROW ) ]
			.filter( ( row ) => ! row.parentElement.closest( ROW ) )
			.map( ( row ) => {
				const boxes = [ ...row.children ]
					.filter( ( child ) => shown( child ) )
					.map( ( child ) => child.getBoundingClientRect() );
				const highest = boxes.reduce(
					( a, b ) => ( b.top < a.top ? b : a ),
					boxes[ 0 ]
				);
				const first = boxes.filter(
					( box ) =>
						Math.min( box.bottom, highest.bottom ) -
							Math.max( box.top, highest.top ) >
						1
				);
				return {
					columns: first.length,
					widths: first.map( ( box ) => Math.round( box.width ) ),
				};
			} );

	/**
	 * Every word in a section's headings that breaks across lines, or
	 * reaches past its heading's content box. A word is what sits between
	 * two places a line may break: a space, a hyphen or dash, a soft hyphen
	 * or a slash. One that renders on two lines broke inside itself
	 * (overflow-wrap or hyphens let it), and one wider than its heading
	 * spills out of the card it is in.
	 *
	 * @param {Element} section
	 * @return {{headings: number, words: Array<{heading: string, word: string, problem: string}>}} Headings measured, and the words that break or spill.
	 */
	const wordsOf = ( section ) => {
		const headings = [
			...section.querySelectorAll( 'h1, h2, h3, h4, h5, h6' ),
		].filter(
			( el ) => shown( el ) && ! el.closest( '[aria-hidden="true"]' )
		);
		const words = [];
		for ( const heading of headings ) {
			const style = window.getComputedStyle( heading );
			const box = heading.getBoundingClientRect();
			const right =
				box.right -
				Number.parseFloat( style.paddingRight ) -
				Number.parseFloat( style.borderRightWidth );
			const walker = document.createTreeWalker(
				heading,
				window.NodeFilter.SHOW_TEXT
			);
			for (
				let node = walker.nextNode();
				node;
				node = walker.nextNode()
			) {
				for ( const match of node.textContent.matchAll(
					/[^\s\-­‐-―/]+/g
				) ) {
					const range = document.createRange();
					range.setStart( node, match.index );
					range.setEnd( node, match.index + match[ 0 ].length );
					const rects = [ ...range.getClientRects() ].filter(
						( r ) => r.width > 0
					);
					if ( ! rects.length ) {
						continue;
					}
					const tops = rects.map( ( r ) => r.top );
					let problem = '';
					if ( Math.max( ...tops ) - Math.min( ...tops ) > 1 ) {
						problem = `breaks over ${
							new Set( tops.map( ( t ) => Math.round( t ) ) ).size
						} lines`;
					} else if (
						Math.max( ...rects.map( ( r ) => r.right ) ) >
						right + 1
					) {
						problem = `reaches ${ Math.round(
							Math.max( ...rects.map( ( r ) => r.right ) ) - right
						) }px past its heading`;
					}
					if ( problem ) {
						words.push( {
							heading: describe( heading ),
							word: match[ 0 ],
							problem,
						} );
					}
				}
			}
		}
		return { headings: headings.length, words };
	};

	window.voyagerOpenCheck = {
		/**
		 * The rows of items and the heading words in each section, for the
		 * layout check.
		 *
		 * @param {string[]} keys Section keys.
		 * @return {Array<Object|null>} One per key: {rows, headings, words}, null for one not found.
		 */
		layout( keys ) {
			return keys.map( ( key ) => {
				const section = sectionOf( key );
				return section
					? { rows: rowsOf( section ), ...wordsOf( section ) }
					: null;
			} );
		},

		/**
		 * Run axe-core, already loaded in the page, on the measured sections
		 * only, so the theme's own markup around them cannot fail the pack.
		 * Page-level rules (landmarks, the document title) do not run when
		 * the context is part of the page. heading-order compares each
		 * heading with the one before it anywhere on the page.
		 *
		 * @param {string[]}                          keys   Section keys.
		 * @param {{tags: string[], rules: string[]}} config The fixture's a11y entry.
		 * @return {Promise<Object>} Rules run, and violations and incomplete results by section.
		 */
		async axe( keys, config ) {
			const include = keys
				.filter( ( key ) => sectionOf( key ) )
				.map( ( key ) => [ `.${ prefix }${ key }` ] );
			if ( ! include.length ) {
				// No section to scan: axe would take the whole page instead.
				return {
					version: window.axe.version,
					ran: [],
					violations: [],
					incomplete: [],
				};
			}
			const results = await window.axe.run(
				{ include },
				{
					runOnly: { type: 'tag', values: config.tags },
					rules: Object.fromEntries(
						config.rules.map( ( id ) => [ id, { enabled: true } ] )
					),
					elementRef: true,
					resultTypes: [ 'violations', 'incomplete' ],
				}
			);
			const nodes = ( list ) =>
				list.flatMap( ( rule ) =>
					rule.nodes.map( ( node ) => ( {
						rule: rule.id,
						impact: node.impact || rule.impact || '',
						key: keyOf( node.element ),
						target: [ node.target ].flat( 2 ).join( ' ' ),
						summary: ( node.failureSummary || rule.help || '' )
							.replace( /\s+/g, ' ' )
							.trim(),
					} ) )
				);
			return {
				version: window.axe.version,
				ran: [
					...new Set(
						[
							...results.violations,
							...results.incomplete,
							...results.passes,
							...results.inapplicable,
						].map( ( rule ) => rule.id )
					),
				].sort(),
				violations: nodes( results.violations ),
				incomplete: nodes( results.incomplete ),
			};
		},

		/**
		 * Tap targets under the minimum, per section.
		 *
		 * @param {string[]} keys Section keys.
		 * @param {number}   min  Smallest height and width, in px.
		 * @return {Array<Object|null>} One result per key, null for one not found.
		 */
		tapTargets( keys, min ) {
			return keys.map( ( key ) => {
				const section = sectionOf( key );
				return section ? tapTargets( section, min ) : null;
			} );
		},

		/**
		 * Where each CSS selector Impeccable reported lands in this page.
		 *
		 * @param {string[]} selectors
		 * @return {Array<{count: number, keys: string[], error: string}>} One per selector: elements matched, and the section keys they are in ('' for outside them all).
		 */
		place( selectors ) {
			return selectors.map( ( selector ) => {
				try {
					const found = [ ...document.querySelectorAll( selector ) ];
					return {
						count: found.length,
						keys: [ ...new Set( found.map( keyOf ) ) ],
						error: '',
					};
				} catch ( error ) {
					return { count: 0, keys: [], error: error.message };
				}
			} );
		},

		/**
		 * Load every image now (a lazy one far down the page would load, and
		 * move what is below it, only when scrolled to), then wait for fonts
		 * and two frames.
		 *
		 * @return {Promise<boolean>} Ready.
		 */
		async ready() {
			const images = [ ...document.images ];
			for ( const img of images ) {
				if ( img.loading === 'lazy' ) {
					img.loading = 'eager';
				}
			}
			await Promise.all(
				images.map( ( img ) =>
					img.complete
						? null
						: new Promise( ( done ) => {
								img.addEventListener( 'load', done, {
									once: true,
								} );
								img.addEventListener( 'error', done, {
									once: true,
								} );
								setTimeout( done, 10000 );
							} )
				)
			);
			await document.fonts.ready;
			await new Promise( ( done ) => {
				setTimeout( done, 250 );
				window.requestAnimationFrame( () =>
					window.requestAnimationFrame( done )
				);
			} );
			return true;
		},

		/**
		 * The page as a whole.
		 *
		 * @return {Object} Width, scroll width, and the reduced-motion query.
		 */
		page() {
			return {
				clientWidth: document.documentElement.clientWidth,
				scrollWidth: document.documentElement.scrollWidth,
				reducedMotion: window.matchMedia(
					'(prefers-reduced-motion: reduce)'
				).matches,
				body: {
					text: hex(
						rgba( window.getComputedStyle( document.body ).color )
					),
					background: hex(
						rgba(
							window.getComputedStyle( document.body )
								.backgroundColor
						)
					),
				},
			};
		},

		/**
		 * Each section: whether it is there once, its classes and colours,
		 * and what the asked checks see in it, at rest.
		 *
		 * @param {string[]}                               keys Section keys.
		 * @param {{contrast: boolean, overflow: boolean}} want Checks to run.
		 * @return {Array<Object>} One entry per key.
		 */
		scan( keys, want ) {
			return keys.map( ( key ) => {
				const section = sectionOf( key );
				if ( ! section ) {
					return {
						key,
						count: document.querySelectorAll(
							`.${ prefix }${ key }`
						).length,
					};
				}
				const style = window.getComputedStyle( section );
				const found = want.contrast ? targets( section ) : [];
				return {
					key,
					count: 1,
					className: section.className,
					text: hex( rgba( style.color ) ),
					background: hex( rgba( style.backgroundColor ) ),
					targets: found.map( ( target ) => ( {
						...target,
						...measure( byId.get( target.id ).el ),
					} ) ),
					overflow: want.overflow ? overflow( section ) : null,
				};
			} );
		},

		/**
		 * Scroll a target's link or button into view, and say where its centre is.
		 *
		 * @param {string} id Target id from scan().
		 * @return {{x: number, y: number}|null} Centre in the viewport.
		 */
		centre( id ) {
			const hover = byId.get( id )?.hover;
			if ( ! hover ) {
				return null;
			}
			hover.scrollIntoView( { block: 'center', inline: 'nearest' } );
			// A link that wraps is two boxes, and the middle of the box
			// around both can be in neither: aim at its widest line.
			const rect = [ ...hover.getClientRects() ].reduce(
				( best, r ) => ( r.width > best.width ? r : best ),
				{ width: -1 }
			);
			if ( rect.width < 0 ) {
				return null;
			}
			return {
				x: rect.left + rect.width / 2,
				y: rect.top + rect.height / 2,
			};
		},

		/**
		 * @param {string} id
		 * @return {boolean} Whether the target's link or button matches :hover.
		 */
		hovered( id ) {
			return !! byId.get( id )?.hover?.matches( ':hover' );
		},

		/** @return {boolean} Whether no CSS transition is running, so a hover colour has landed. */
		settled() {
			return document
				.getAnimations()
				.every(
					( a ) =>
						a.playState !== 'running' ||
						! ( 'transitionProperty' in a )
				);
		},

		/**
		 * @param {string} id
		 * @return {Object} The target's measurement now.
		 */
		measure( id ) {
			return measure( byId.get( id ).el );
		},

		/**
		 * @param {string[]} keys Section keys.
		 * @return {Array<Object|null>} A motion sample of each, null for one not found.
		 */
		sample( keys ) {
			return keys.map( ( key ) => {
				const section = sectionOf( key );
				return section ? sampleOf( section ) : null;
			} );
		},

		/**
		 * The colours the page's palette custom properties hold, against a
		 * palette expected from a style variation.
		 *
		 * @param {Array<{slug: string, color: string}>} palette Expected.
		 * @return {string[]} One line per slug that differs.
		 */
		paletteMismatches( palette ) {
			const root = window.getComputedStyle( document.documentElement );
			const key = ( c ) =>
				c ? `${ hex( c ) }/${ c.a.toFixed( 2 ) }` : 'none';
			return palette
				.map( ( { slug, color } ) => {
					const raw = root
						.getPropertyValue( `--wp--preset--color--${ slug }` )
						.trim();
					const got = raw ? key( rgba( raw ) ) : 'unset';
					const want = key( rgba( color ) );
					return got === want
						? ''
						: `${ slug }: ${ got }, not ${ want }`;
				} )
				.filter( Boolean );
		},
	};
	return true;
}

/**
 * In the editor: whether the post's blocks are loaded.
 *
 * @return {boolean} Ready.
 */
function editorReadyInPage() {
	const data = window.wp?.data;
	const canvas = document.querySelector( 'iframe[name="editor-canvas"]' );
	return !! (
		data &&
		data.select( 'core/block-editor' ).getBlockCount() > 0 &&
		( ! canvas ||
			canvas.contentDocument?.querySelector(
				'.is-style-voyager-open-inverted'
			) )
	);
}

/**
 * In the editor: select the first section.
 *
 * @return {string} The clientId selected.
 */
function selectSectionInPage() {
	const { select, dispatch } = window.wp.data;
	const [ first ] = select( 'core/block-editor' ).getBlocks();
	dispatch( 'core/block-editor' ).selectBlock( first.clientId );
	return first.clientId;
}

/**
 * In the editor: open the inspector's Styles tab, if it has tabs.
 *
 * @return {boolean} Whether a Styles tab was clicked.
 */
function openStylesTabInPage() {
	const tab = [
		...document.querySelectorAll(
			'.block-editor-block-inspector [role="tab"]'
		),
	].find( ( el ) =>
		/styles/i.test(
			el.textContent || el.getAttribute( 'aria-label' ) || ''
		)
	);
	if ( tab && tab.getAttribute( 'aria-selected' ) !== 'true' ) {
		tab.click();
	}
	return !! tab;
}

/**
 * In the editor: the style buttons the Styles panel shows, and what the
 * blocks store holds for core/group.
 *
 * @return {{panel: string[], registered: Array<{name: string, label: string}>}} Labels.
 */
function stylesInPage() {
	const panel = [
		...document.querySelectorAll(
			'.block-editor-block-styles__variants button, .block-editor-block-styles__variants [role="radio"]'
		),
	].map( ( el ) =>
		( el.textContent || el.getAttribute( 'aria-label' ) || '' ).trim()
	);
	const registered = (
		window.wp.data.select( 'core/blocks' ).getBlockStyles( 'core/group' ) ||
		[]
	).map( ( s ) => ( { name: s.name, label: s.label } ) );
	return { panel, registered };
}

/**
 * In the editor canvas: the first Inverted section's colours.
 *
 * @return {{text: string, background: string}|null} Colours, as hex.
 */
function canvasInvertedInPage() {
	const doc =
		document.querySelector( 'iframe[name="editor-canvas"]' )
			?.contentDocument || document;
	const el = doc.querySelector( '.is-style-voyager-open-inverted' );
	if ( ! el ) {
		return null;
	}
	const canvas = document.createElement( 'canvas' );
	canvas.width = 1;
	canvas.height = 1;
	const ctx = canvas.getContext( '2d', { willReadFrequently: true } );
	const hex = ( value ) => {
		ctx.clearRect( 0, 0, 1, 1 );
		ctx.fillStyle = value;
		ctx.fillRect( 0, 0, 1, 1 );
		const [ r, g, b ] = ctx.getImageData( 0, 0, 1, 1 ).data;
		return (
			'#' +
			[ r, g, b ]
				.map( ( v ) => v.toString( 16 ).padStart( 2, '0' ) )
				.join( '' )
		);
	};
	const style = el.ownerDocument.defaultView.getComputedStyle( el );
	return {
		text: hex( style.color ),
		background: hex( style.backgroundColor ),
	};
}

/**
 * @param {Object} tab A page from launch().newPage().
 * @param {number} x
 * @param {number} y
 */
async function pointer( tab, x, y ) {
	await tab.call( 'Input.dispatchMouseEvent', {
		type: 'mouseMoved',
		x,
		y,
		button: 'none',
	} );
}

/**
 * Load a URL at a width, after checking it answers 200, and install the
 * measuring helpers.
 *
 * @param {Object} tab
 * @param {string} url
 * @param {number} width  Viewport width in CSS pixels.
 * @param {number} height Viewport height in CSS pixels.
 */
async function visit( tab, url, width, height ) {
	const response = await fetch( url );
	const body = await response.text();
	if ( response.status !== 200 ) {
		const title = /<title>([^<]*)<\/title>/i.exec( body )?.[ 1 ] || '';
		throw new Error(
			`${ url } answered ${ response.status } (at ${ response.url })${
				title ? `: ${ title }` : ''
			}`
		);
	}
	await tab.call( 'Emulation.setDeviceMetricsOverride', {
		width,
		height,
		deviceScaleFactor: 1,
		mobile: false,
	} );
	await tab.call( 'Page.bringToFront' );
	await tab.goto( url );
	await tab.evaluate( js( installInPage, SECTION_CLASS ) );
	await tab.evaluate( call( 'ready' ) );
	await pointer( tab, 0, 0 );
}

/**
 * Measure contrast in sections already scanned: the rest pairs the scan
 * measured, then each link and button hovered.
 *
 * @param {Object}        tab      The page.
 * @param {Array<Object>} scanned  From scan(), with contrast on.
 * @param {Object}        context  Fields every row carries: theme, variation, width.
 * @param {Object}        sections Key => {pattern, style}.
 * @return {Promise<Array<Object>>} One row per target and state.
 */
async function contrastRows( tab, scanned, context, sections ) {
	const rows = [];
	for ( const entry of scanned ) {
		// The planted sections are controls for the other checks, apart
		// from the covers.
		if (
			! entry.targets ||
			( entry.key.startsWith( 'plant--' ) &&
				! Object.hasOwn( COVER_PLANTS, entry.key.slice( 7 ) ) )
		) {
			continue;
		}
		const where = { ...context, ...sections[ entry.key ] };
		for ( const target of entry.targets ) {
			rows.push( {
				...where,
				target: target.name,
				kind: target.kind,
				nth: target.nth,
				state: 'rest',
				text: target.text,
				background: target.background,
				ratio: target.ratio,
				note: target.note,
				cover: target.cover,
				onCover: target.onCover,
			} );
		}
		for ( const target of entry.targets.filter( ( t ) => t.hover ) ) {
			await pointer( tab, 0, 0 );
			const at = await tab.evaluate( call( 'centre', target.id ) );
			if ( at ) {
				await pointer( tab, at.x, at.y );
			}
			const hovered = at
				? await tab.evaluate( call( 'hovered', target.id ) )
				: false;
			if ( hovered ) {
				await tab.waitFor( call( 'settled' ), {
					timeout: 1500,
					interval: 50,
				} );
			}
			const measured = hovered
				? await tab.evaluate( call( 'measure', target.id ) )
				: {
						text: '?',
						background: '?',
						ratio: null,
						note: 'the pointer did not produce :hover',
						cover: null,
					};
			rows.push( {
				...where,
				target: target.name,
				kind: target.kind,
				nth: target.nth,
				state: 'hover',
				...measured,
			} );
		}
	}
	await pointer( tab, 0, 0 );
	return rows;
}

/**
 * How many columns a section's rows must render at a width: the fixture's
 * count for its pattern at the wide width, one at a stacked width, and at
 * any other width only that the rows are there. A planted section takes its
 * plant's count.
 *
 * @param {string} key      Section key.
 * @param {Object} sections Key => {pattern, style}.
 * @param {Object} fixture  tests/fixtures/contrast.json.
 * @return {number|undefined} Columns at the wide width, or undefined for a section that holds no row.
 */
function columnsFor( key, sections, fixture ) {
	if ( key.startsWith( 'plant--' ) ) {
		return LAYOUT_PLANTS[ key.slice( 7 ) ]?.columns;
	}
	return fixture.layout.columns[ sections[ key ]?.pattern ];
}

/**
 * The layout check on one section render: its rows of items render the
 * columns they should, and no heading word breaks or spills.
 *
 * @param {Object|null}      found   From the page's layout(), null when the section was not found.
 * @param {number|undefined} columns From columnsFor().
 * @param {number}           width   Viewport width.
 * @param {Object}           fixture tests/fixtures/contrast.json.
 * @return {{ok: boolean, reasons: string[], note: string}} Result. Reasons are columns, stack, break and spill.
 */
function layoutVerdict( found, columns, width, fixture ) {
	if ( ! found ) {
		return { ok: false, reasons: [ 'missing' ], note: 'section not found' };
	}
	const reasons = [];
	const notes = [];
	const rows = found.rows.map(
		( r ) => `${ r.columns } (${ r.widths.join( ', ' ) }px)`
	);
	if ( columns === undefined && found.rows.length ) {
		reasons.push( 'columns' );
		notes.push(
			`${ found.rows.length } rows of items, and the fixture's layout.columns lists none for this pattern: columns ${ rows.join(
				'; '
			) }`
		);
	} else if ( columns !== undefined && ! found.rows.length ) {
		reasons.push( 'columns' );
		notes.push(
			`no row of items, where the fixture lists ${ columns } columns`
		);
	} else if ( width === fixture.layout.wide ) {
		if ( found.rows.some( ( r ) => r.columns !== columns ) ) {
			reasons.push( 'columns' );
			notes.push(
				`columns ${ rows.join( '; ' ) }, not ${ columns } at ${ width }px`
			);
		}
	} else if ( fixture.layout.stacked.includes( width ) ) {
		if ( found.rows.some( ( r ) => r.columns !== 1 ) ) {
			reasons.push( 'stack' );
			notes.push(
				`columns ${ rows.join( '; ' ) }, not stacked at ${ width }px`
			);
		}
	}
	const words = ( problem ) =>
		found.words.filter( ( w ) => w.problem.startsWith( problem ) );
	for ( const [ reason, problem ] of [
		[ 'break', 'breaks' ],
		[ 'spill', 'reaches' ],
	] ) {
		if ( words( problem ).length ) {
			reasons.push( reason );
			notes.push(
				words( problem )
					.map(
						( w ) =>
							`"${ w.word }" in ${ w.heading } ${ w.problem }`
					)
					.join( '; ' )
			);
		}
	}
	return {
		ok: reasons.length === 0,
		reasons,
		note:
			notes.join( '; ' ) ||
			`${
				found.rows.length ? `columns ${ rows.join( '; ' ) }, ` : ''
			}no word breaks in ${ found.headings } headings`,
	};
}

/**
 * Motion in sections: two samples gapMs apart, with reduced motion on.
 *
 * @param {Object}   tab   A page with reduced motion emulated.
 * @param {string[]} keys  Section keys.
 * @param {number}   gapMs Milliseconds between the samples.
 * @return {Promise<Object<string, {ok: boolean, note: string}>>} Key => result.
 */
async function motionResults( tab, keys, gapMs ) {
	const first = await tab.evaluate( call( 'sample', keys ) );
	await new Promise( ( done ) => setTimeout( done, gapMs ) );
	const second = await tab.evaluate( call( 'sample', keys ) );
	const out = {};
	keys.forEach( ( key, i ) => {
		const a = first[ i ];
		const b = second[ i ];
		if ( ! a || ! b ) {
			out[ key ] = { ok: false, note: 'section not found' };
			return;
		}
		const running = [ ...new Set( [ ...a.running, ...b.running ] ) ];
		const moved = a.boxes.filter(
			( box, j ) =>
				! b.boxes[ j ] ||
				box.some( ( v, k ) => Math.abs( v - b.boxes[ j ][ k ] ) > 0.5 )
		).length;
		const notes = [];
		if ( running.length ) {
			notes.push( `running: ${ running.join( '; ' ) }` );
		}
		if ( moved || a.boxes.length !== b.boxes.length ) {
			notes.push(
				`${ moved } of ${ a.boxes.length } boxes moved in ${ gapMs }ms`
			);
		}
		out[ key ] = { ok: notes.length === 0, note: notes.join( '; ' ) };
	} );
	return out;
}

/** @type {string|null} axe-core's browser build, read once. */
let axeSource = null;

/**
 * Put axe-core into the page, unless it is there already.
 *
 * @param {Object} tab A page from launch().newPage().
 * @return {Promise<string>} The version loaded in the page.
 */
async function loadAxe( tab ) {
	axeSource ??= readFileSync(
		require.resolve( 'axe-core/axe.min.js' ),
		'utf8'
	);
	const loaded = await tab.evaluate( 'window.axe?.version || ""' );
	if ( loaded ) {
		return loaded;
	}
	return tab.evaluate( `${ axeSource }\n;window.axe?.version || ''` );
}

/**
 * Run `impeccable detect --json` on one URL at one viewport, in the browser
 * the run uses. Exit 0 is a scan with no primary findings and 2 a scan with
 * some; anything else, or output that is not a findings array, is a scan
 * that did not run, never a pass.
 *
 * @param {string} url
 * @param {number} width
 * @param {number} height
 * @param {string} chrome Browser executable, from findChrome().
 * @return {Promise<{ok: boolean, findings: Array<Object>, note: string}>} Result.
 */
async function impeccableScan( url, width, height, chrome ) {
	const cli = join(
		repo,
		'node_modules',
		'impeccable',
		'cli',
		'bin',
		'cli.js'
	);
	if ( ! existsSync( cli ) ) {
		return {
			ok: false,
			findings: [],
			note: 'impeccable is not installed (npm ci)',
		};
	}
	// --no-config: no project config, no inline ignore comments and no
	// DESIGN.md context, so a comment in the markup cannot waive a finding.
	const args = [
		cli,
		'detect',
		'--json',
		'--no-config',
		'--viewport',
		`${ width }x${ height }`,
		url,
	];
	const { code, stdout, stderr } = await new Promise( ( done ) => {
		const child = execFile(
			process.execPath,
			args,
			{
				cwd: repo,
				encoding: 'utf8',
				maxBuffer: 64 * 1024 * 1024,
				timeout: 180000,
				env: {
					...process.env,
					IMPECCABLE_BROWSER: chrome,
					IMPECCABLE_NO_TELEMETRY: '1',
					IMPECCABLE_NO_UPDATE_CHECK: '1',
					IMPECCABLE_NO_STALENESS_CHECK: '1',
					DO_NOT_TRACK: '1',
				},
			},
			( error, out, err ) =>
				done( {
					code: error ? ( error.code ?? error.signal ?? 1 ) : 0,
					stdout: out,
					stderr: err,
				} )
		);
		child.stdin?.end();
	} );
	let findings = null;
	try {
		findings = JSON.parse( stdout );
	} catch {
		// Reported below.
	}
	if ( ( code === 0 || code === 2 ) && Array.isArray( findings ) ) {
		return { ok: true, findings, note: '' };
	}
	return {
		ok: false,
		findings: [],
		note: `impeccable detect exited ${ code }: ${ clip(
			( stderr || stdout || '' ).replace( /\s+/g, ' ' ).trim(),
			300
		) }`,
	};
}

/**
 * The a11y and impeccable checks on one loaded page: axe-core on its
 * sections, Impeccable on the whole page with each finding placed in the
 * section it is in, and tap targets measured here.
 *
 * @param {Object}   tab      The visitor page, loaded at width.
 * @param {string}   url      The page.
 * @param {number}   width    Viewport width.
 * @param {string[]} keys     Sections to measure.
 * @param {Object}   want     Checks to run: {a11y, impeccable}.
 * @param {string}   theme    Theme slug.
 * @param {Object}   fixture  tests/fixtures/contrast.json.
 * @param {Promise}  scanning impeccableScan() of the page at width, which the caller starts before it loads the page, so Impeccable's browser scans while this tab measures; null when impeccable is not asked for.
 * @return {Promise<Object>} {a11y, impeccable}: per check, whether it ran, a note when it did not, findings by section key, and advisories.
 */
async function judge( tab, url, width, keys, want, theme, fixture, scanning ) {
	const out = {};
	if ( want.a11y ) {
		// The hover pass leaves the page scrolled to its last link or button,
		// where a row revealed on scroll can be half faded. At the top every
		// revealed row shows in full (DESIGN.md, Motion), so axe runs there.
		await tab.evaluate(
			js( () => {
				window.scrollTo( 0, 0 );
				return new Promise( ( done ) =>
					window.requestAnimationFrame( () =>
						window.requestAnimationFrame( () => done( true ) )
					)
				);
			} )
		);
		const version = await loadAxe( tab );
		const result = version
			? await tab.evaluate( call( 'axe', keys, fixture.a11y ) )
			: null;
		const bySection = {};
		const advisories = [];
		for ( const v of result?.violations || [] ) {
			if ( v.key && keys.includes( v.key ) ) {
				( bySection[ v.key ] ??= [] ).push( v );
			} else {
				advisories.push( { ...v, why: 'outside the sections' } );
			}
		}
		for ( const v of result?.incomplete || [] ) {
			advisories.push( { ...v, why: 'needs review' } );
		}
		out.a11y = {
			ran: !! result?.ran.length,
			// eslint-disable-next-line no-nested-ternary
			note: ! result
				? 'axe-core did not load in the page'
				: result.ran.length
					? ''
					: 'no section to scan',
			version,
			rules: result?.ran || [],
			bySection,
			advisories,
		};
	}
	if ( want.impeccable ) {
		const { gate, tap_target: tap } = fixture.impeccable;
		const scan = await scanning;
		const placed = scan.ok
			? await tab.evaluate(
					call(
						'place',
						scan.findings.map( ( f ) => f.selector || '' )
					)
				)
			: [];
		const bySection = {};
		const advisories = [];
		const unplaced = [];
		scan.findings.forEach( ( f, i ) => {
			const at = placed[ i ];
			const gated =
				gate.includes( f.antipattern ) && f.severity !== 'advisory';
			const row = {
				rule: f.antipattern,
				severity: f.severity,
				selector: f.selector || '',
				summary: f.snippet || f.name || '',
			};
			const inSections = ( at?.keys || [] ).filter( ( k ) =>
				keys.includes( k )
			);
			if ( gated && ! at?.count ) {
				unplaced.push( {
					...row,
					why:
						at?.error || 'the selector matches nothing in the page',
				} );
			} else if ( gated && inSections.length ) {
				for ( const key of inSections ) {
					( bySection[ key ] ??= [] ).push( row );
				}
			} else {
				advisories.push( {
					...row,
					key: inSections.join( ', ' ),
					why: gated ? 'outside the sections' : 'not gated',
				} );
			}
		} );
		const targets = await tab.evaluate(
			call( 'tapTargets', keys, tap.min )
		);
		const gaps = tap.known_gaps?.[ theme ] || [];
		const tapped = {};
		keys.forEach( ( key, i ) => {
			const t = targets[ i ];
			tapped[ key ] = t ? t.measured : 0;
			for ( const small of t?.small || [] ) {
				const gap = gaps.find(
					( g ) =>
						g.kind === small.kind &&
						small.width >= tap.min &&
						Math.abs( small.height - g.height ) <= tap.tolerance
				);
				( bySection[ key ] ??= [] ).push( {
					rule: TAP_TARGET,
					severity: 'error',
					selector: '',
					summary: `${ small.name } is ${ small.height }px tall and ${ small.width }px wide, under ${ tap.min }px`,
					height: small.height,
					kind: small.kind,
					known: gap || null,
				} );
			}
		} );
		out.impeccable = {
			ran: scan.ok,
			note: scan.note,
			findings: scan.findings.length,
			bySection,
			advisories,
			unplaced,
			tapped,
		};
	}
	return out;
}

/**
 * @param {Object} row   A contrast measurement.
 * @param {number} floor
 * @return {boolean} Whether it fails: unmeasured, or under the floor.
 */
const fails = ( row, floor ) => row.ratio === null || row.ratio < floor;

/**
 * The key a known failure is listed under: where the pair is, and which
 * target by its kind and its place among the section's targets of that kind
 * (targets() in the page). Never its text, so new Site Data or new wording
 * in a pattern leaves the list alone.
 *
 * @param {Object} row A contrast row, or a known failure from the fixture.
 * @return {string} The key.
 */
const knownKey = ( row ) =>
	[
		row.variation,
		row.pattern,
		row.style,
		row.kind,
		row.nth,
		row.state,
	].join( '|' );

/**
 * One aligned line per contrast row.
 *
 * @param {Array<Object>} rows
 * @param {number}        floor
 * @return {string} Table.
 */
function contrastTable( rows, floor ) {
	return rows
		.map( ( r ) =>
			[
				// eslint-disable-next-line no-nested-ternary
				fails( r, floor ) ? ( r.known ? 'known' : 'FAIL ' ) : 'ok   ',
				`${ r.theme }${ r.variation ? `/${ r.variation }` : '' }`.padEnd(
					28
				),
				String( r.width ).padStart( 4 ),
				placeOf( r ).padEnd( 15 ),
				r.style.padEnd( 8 ),
				clip( r.target, 44 ).padEnd( 47 ),
				r.state.padEnd( 5 ),
				`text ${ r.text }`,
				`on ${ r.background }`,
				r.ratio === null
					? `- ${ r.note }`
					: `${ r.ratio.toFixed( 2 ) }:1`,
				coverNote( r ),
			]
				.filter( Boolean )
				.join( '  ' )
		)
		.join( '\n' );
}

/**
 * What a contrast row on a cover was measured against.
 *
 * @param {Object} row A contrast row.
 * @return {string} '' for a row on no cover.
 */
function coverNote( row ) {
	const c = row.cover;
	if ( ! c ) {
		return '';
	}
	const overlay = `cover overlay ${ c.overlay } at ${ Math.round(
		c.opacity * 100
	) }%`;
	const media = {
		img: 'an image',
		background: 'a background image',
		video: 'a video',
		embed: 'an embedded video',
	};
	return c.ratios
		? `(${ overlay } over ${ media[ c.media ] }: ${ c.black } over black ${ c.ratios[ 0 ].toFixed(
				2
			) }:1, ${ c.white } over white ${ c.ratios[ 1 ].toFixed( 2 ) }:1)`
		: `(${ overlay }, no image)`;
}

/**
 * One aligned line per overflow or motion row.
 *
 * @param {Array<Object>} rows
 * @return {string} Table.
 */
function renderTable( rows ) {
	return rows
		.map( ( r ) =>
			[
				r.ok ? 'ok   ' : 'FAIL ',
				r.check.padEnd( 8 ),
				r.theme.padEnd( 18 ),
				String( r.width ).padStart( 4 ),
				placeOf( r ).padEnd( 15 ),
				r.style.padEnd( 8 ),
				r.note,
			].join( '  ' )
		)
		.join( '\n' );
}

/**
 * Append Markdown to the job summary, when there is one.
 *
 * @param {string[]} lines
 */
function summary( lines ) {
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		appendFileSync(
			process.env.GITHUB_STEP_SUMMARY,
			`${ lines.join( '\n' ) }\n\n`
		);
	}
}

/**
 * Contrast rows as a Markdown table, at most 300 of them.
 *
 * @param {string}        title
 * @param {Array<Object>} rows
 * @param {number}        floor
 */
function summarizeContrast( title, rows, floor ) {
	summary( [
		`### ${ title }`,
		'',
		'| | Theme | Width | Pattern | Style | Target | State | Text | Background | Ratio |',
		'|---|---|---|---|---|---|---|---|---|---|',
		...rows
			.slice( 0, 300 )
			.map(
				( r ) =>
					`| ${ fails( r, floor ) ? 'FAIL' : 'ok' } | ${ r.theme }${
						r.variation ? `/${ r.variation }` : ''
					} | ${ r.width } | ${ placeOf( r ) } | ${ r.style } | ${ r.target.replace(
						/\|/g,
						'\\|'
					) } | ${ r.state } | \`${ r.text }\` | \`${ r.background }\` | ${
						r.ratio === null
							? r.note
							: `${ r.ratio.toFixed( 2 ) }:1`
					} |`
			),
		rows.length > 300
			? `\n${ rows.length - 300 } more in the job log.`
			: '',
	] );
}

/**
 * The patterns in patterns/, by file name.
 *
 * @return {string[]} Names, such as cta-band, sorted.
 */
function patternFiles() {
	return readdirSync( PATTERNS )
		.filter( ( file ) => file.endsWith( '.html' ) )
		.map( ( file ) => file.slice( 0, -5 ) )
		.sort();
}

/**
 * Switch theme and build the pages: every pattern in every style, the
 * planted faults, one page per recipe, and the planted recipe page.
 *
 * Each recipe page is what compose builds from the recipe, as an agent asks
 * for it with `recipe`: its sections in order, each in its recipe style,
 * with the patterns' own copy, less what the recipe omits. compose saves it
 * as a draft; the run marks each section with its key and publishes it, so a
 * visitor loads it as the pattern page is loaded.
 *
 * @param {Object}   site     From boot().
 * @param {Object}   fixture  tests/fixtures/contrast.json.
 * @param {string}   theme    Theme slug.
 * @param {string[]} patterns Pattern names.
 * @param {Object}   plants   Plant name => markup, from plantSections().
 * @param {boolean}  planted  Whether the main page carries the plants too.
 * @param {Object}   recipes  {pages: [{slug, keys}], plant: {host, keys, markup}|null}: each recipe's section keys, and the planted recipe page.
 * @return {Promise<Object>} {main, control, recipes, recipePlant, stylesheet, version, catalog, registered} or {error}. recipes is slug => {id, link, patterns}, patterns being each section's pattern as compose resolved it.
 */
function buildPages(
	site,
	fixture,
	theme,
	patterns,
	plants,
	planted,
	recipes
) {
	const args = phpString(
		JSON.stringify( {
			theme,
			patterns,
			styles: fixture.styles,
			link: fixture.link,
			prefix: SECTION_CLASS,
			plants: Object.values( plants ),
			planted,
			assets: ASSETS_MARK,
			recipes,
		} )
	);
	return runPhp(
		site,
		`$args = json_decode( ${ args }, true );
if ( ! wp_get_theme( $args['theme'] )->exists() ) {
	return [ 'error' => 'Theme ' . $args['theme'] . ' is not installed.' ];
}
switch_theme( $args['theme'] );
// The planted covers show the pack's placeholder at the plugin's own URL.
$args['plants'] = str_replace( $args['assets'], plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE ), $args['plants'] );
$mark = static function ( array $block, string $class ): array {
	$block['attrs']['className'] = trim( ( $block['attrs']['className'] ?? '' ) . ' ' . $class );
	foreach ( [ 'first', 'html' ] as $part ) {
		$html = 'first' === $part ? $block['innerContent'][0] : $block['innerHTML'];
		$tags = new WP_HTML_Tag_Processor( $html );
		$tags->next_tag();
		$tags->add_class( $class );
		if ( 'first' === $part ) {
			$block['innerContent'][0] = $tags->get_updated_html();
		} else {
			$block['innerHTML'] = $tags->get_updated_html();
		}
	}
	return $block;
};
$registry = WP_Block_Patterns_Registry::get_instance();
$sections = [];
foreach ( $args['patterns'] as $name ) {
	$pattern = $registry->get_registered( 'voyager-open/' . $name );
	if ( ! $pattern ) {
		return [ 'error' => 'voyager-open/' . $name . ' is not registered.' ];
	}
	$groups = array_values(
		array_filter(
			parse_blocks( $pattern['content'] ),
			static fn ( $block ) => 'core/group' === $block['blockName']
		)
	);
	if ( 1 !== count( $groups ) ) {
		return [ 'error' => 'voyager-open/' . $name . ' has ' . count( $groups ) . ' top-level groups, not one section.' ];
	}
	foreach ( $args['styles'] as $style ) {
		$styled = \\VoyagerOpenBlocks\\SectionStyles\\apply( $groups[0], $style );
		if ( is_wp_error( $styled ) ) {
			return [ 'error' => $name . ' ' . $style . ': ' . $styled->get_error_message() ];
		}
		$markup = serialize_block( $mark( $styled, $args['prefix'] . $name . '--' . $style ) );
		if ( $args['link']['pattern'] === $name ) {
			$link   = '<a href="' . esc_url( $args['link']['href'] ) . '">' . esc_html( $args['link']['text'] ) . '</a>';
			$count  = 0;
			$markup = str_replace( $args['link']['text'], $link, $markup, $count );
			if ( 1 !== $count ) {
				return [ 'error' => 'The link text appears ' . $count . ' times in the ' . $name . ' ' . $style . ' section, not once.' ];
			}
		}
		$sections[] = $markup;
	}
}
kses_remove_filters();
$pages = [];
foreach ( [
	'main'    => array_merge( $sections, $args['planted'] ? $args['plants'] : [] ),
	'control' => $args['plants'],
] as $key => $content ) {
	$id = wp_insert_post(
		wp_slash(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Design check ' . $key . ': ' . $args['theme'],
				'post_content' => implode( "\\n\\n", $content ),
			]
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return [ 'error' => $id->get_error_message() ];
	}
	$pages[ $key ] = [ 'id' => $id, 'link' => get_permalink( $id ) ];
}
// Each recipe as compose builds it from the recipe, then each section marked
// with its key and the page published.
$layouts  = \\VoyagerOpenBlocks\\Abilities\\section_layouts();
$recipes  = [];
$unmarked = [];
foreach ( $args['recipes']['pages'] as $recipe ) {
	$slug  = $recipe['slug'];
	$built = \\VoyagerOpenBlocks\\Abilities\\compose(
		[
			'title'  => 'Design check recipe:' . $slug . ': ' . $args['theme'],
			'recipe' => $slug,
		]
	);
	if ( is_wp_error( $built ) ) {
		return [ 'error' => 'compose refused recipe ' . $slug . ': ' . $built->get_error_message() ];
	}
	if ( empty( $built['saved'] ) ) {
		return [ 'error' => 'compose saved no page for recipe ' . $slug . ': ' . wp_json_encode( $built['refused'] ) ];
	}
	$blocks = array_values(
		array_filter(
			parse_blocks( (string) get_post( $built['post_id'] )->post_content ),
			static fn ( $block ) => null !== $block['blockName']
		)
	);
	if ( count( $blocks ) !== count( $recipe['keys'] ) ) {
		return [ 'error' => 'compose built ' . count( $blocks ) . ' sections for recipe ' . $slug . ', not ' . count( $recipe['keys'] ) . '.' ];
	}
	$names = [];
	foreach ( \\VoyagerOpenBlocks\\Abilities\\recipe_sections( (array) \\VoyagerOpenBlocks\\Abilities\\find_recipe( $slug ) ) as $section ) {
		$names[] = preg_replace( '#^voyager-open/#', '', (string) \\VoyagerOpenBlocks\\Abilities\\resolve_layout( $layouts, $section['pattern'], $section['layout'] ) );
	}
	$marked = [];
	foreach ( $blocks as $index => $block ) {
		$marked[] = serialize_block( $mark( $block, $args['prefix'] . $recipe['keys'][ $index ] ) );
	}
	$published = wp_update_post(
		wp_slash(
			[
				'ID'           => $built['post_id'],
				'post_status'  => 'publish',
				'post_content' => implode( "\\n\\n", $marked ),
			]
		),
		true
	);
	if ( is_wp_error( $published ) ) {
		return [ 'error' => $published->get_error_message() ];
	}
	$unmarked[ $slug ] = $blocks;
	$recipes[ $slug ]  = [
		'id'       => $built['post_id'],
		'link'     => get_permalink( $built['post_id'] ),
		'patterns' => $names,
	];
}
// recipe:plant: the skipped-heading plant between the host recipe's first
// two sections.
$plant = $args['recipes']['plant'];
$recipe_plant = null;
if ( $plant ) {
	$host    = $unmarked[ $plant['host'] ] ?? [];
	$planted = array_values(
		array_filter(
			parse_blocks( $plant['markup'] ),
			static fn ( $block ) => null !== $block['blockName']
		)
	);
	if ( count( $host ) < 2 || 1 !== count( $planted ) ) {
		return [ 'error' => 'recipe:plant needs two sections of recipe ' . $plant['host'] . ' and one planted section.' ];
	}
	$content = [];
	foreach ( [ $host[0], $planted[0], $host[1] ] as $index => $block ) {
		$content[] = serialize_block( $mark( $block, $args['prefix'] . $plant['keys'][ $index ] ) );
	}
	$id = wp_insert_post(
		wp_slash(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Design check recipe:plant: ' . $args['theme'],
				'post_content' => implode( "\\n\\n", $content ),
			]
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return [ 'error' => $id->get_error_message() ];
	}
	$recipe_plant = [ 'id' => $id, 'link' => get_permalink( $id ) ];
}
$registered = [];
foreach ( $registry->get_all_registered() as $pattern ) {
	if ( str_starts_with( $pattern['name'], 'voyager-open/' ) ) {
		$registered[] = substr( $pattern['name'], strlen( 'voyager-open/' ) );
	}
}
sort( $registered );
return [
	'main'        => $pages['main'],
	'control'     => $pages['control'],
	'recipes'     => (object) $recipes,
	'recipePlant' => $recipe_plant,
	'stylesheet'  => get_stylesheet(),
	'version'    => get_bloginfo( 'version' ),
	'catalog'    => \\VoyagerOpenBlocks\\SectionStyles\\catalog(),
	'registered' => $registered,
];`
	);
}

/**
 * Set or clear the planted role map, and read the catalog it gives.
 *
 * @param {Object}      site From boot().
 * @param {Object|null} map  Role => slug, or null to clear it.
 * @return {Promise<Object>} The catalog after the change.
 */
function plant( site, map ) {
	return runPhp(
		site,
		map
			? `update_option( '${ PLANT_OPTION }', json_decode( ${ phpString(
					JSON.stringify( map )
				) }, true ) );
return \\VoyagerOpenBlocks\\SectionStyles\\catalog();`
			: `delete_option( '${ PLANT_OPTION }' );
return \\VoyagerOpenBlocks\\SectionStyles\\catalog();`
	);
}

/**
 * The active theme's style variations, in the order the Site Editor gets
 * them (WP_Theme_JSON_Resolver::get_style_variations()), with the ones that
 * set a colour marked.
 *
 * A theme can ship a full variation and a colours-only one under the same
 * title, as Twenty Twenty-Five does (styles/ and styles/colors/), so each is
 * named by its title, and a colours-only one, which the Site Editor offers
 * under Colors, gets "(colors)" after it. Colours-only is the Site Editor's
 * test, isVariationWithProperties( variation, [ 'color' ] ): the variation
 * equals itself with everything but its color keys filtered out.
 *
 * @param {Object} site From boot().
 * @return {Promise<Array<{index: number, title: string, name: string, colour: boolean, palette: Array<{slug: string, color: string}>}>>} Variations.
 */
function listVariations( site ) {
	return runPhp(
		site,
		`$only = static function ( $data, array $keys ) use ( &$only ) {
	$out = [];
	foreach ( (array) $data as $key => $value ) {
		if ( in_array( $key, $keys, true ) ) {
			$out[ $key ] = $value;
		} elseif ( is_array( $value ) ) {
			$kept = $only( $value, $keys );
			if ( [] !== $kept ) {
				$out[ $key ] = $kept;
			}
		}
	}
	return $out;
};
$out = [];
foreach ( WP_Theme_JSON_Resolver::get_style_variations() as $index => $variation ) {
	$styles   = (array) ( $variation['styles'] ?? [] );
	$settings = (array) ( $variation['settings'] ?? [] );
	$colours  = [ $only( $styles, [ 'color' ] ), $only( $settings, [ 'color' ] ) ];
	$palette  = $settings['color']['palette'] ?? [];
	$entries  = is_array( $palette ) ? ( $palette['theme'] ?? ( isset( $palette[0] ) ? $palette : [] ) ) : [];
	$title    = (string) ( $variation['title'] ?? '' );
	$out[]    = [
		'index'   => $index,
		'title'   => $title,
		'name'    => $title . ( $colours[0] == $styles && $colours[1] == $settings ? ' (colors)' : '' ),
		'colour'  => [] !== $colours[0] || [] !== $colours[1],
		'palette' => array_map(
			static fn ( $entry ) => [ 'slug' => (string) $entry['slug'], 'color' => (string) $entry['color'] ],
			$entries
		),
	];
}
return $out;`
	);
}

/**
 * Switch a style variation on, or back to the theme's own styles, the way
 * the Site Editor saves one. Picking a variation sets the user's global
 * styles to its settings and styles: edit-site's Variation calls
 * setUserConfig( variation ), and for a colours-only one the variation is
 * first merged over the user's styles without their colours, which from the
 * theme's own styles is the variation alone. The global styles REST
 * controller then writes settings and styles to the theme's wp_global_styles
 * post with isGlobalStylesUserThemeJSON and the schema version
 * (prepare_item_for_database()).
 *
 * @param {Object}      site      From boot().
 * @param {Object|null} variation From listVariations(), or null for none.
 * @return {Promise<Object>} {post, roles} or {error}.
 */
function switchVariation( site, variation ) {
	return runPhp(
		site,
		`$want   = json_decode( ${ phpString(
			JSON.stringify(
				variation
					? { index: variation.index, title: variation.title }
					: null
			)
		) }, true );
$chosen = null;
if ( null !== $want ) {
	$chosen = WP_Theme_JSON_Resolver::get_style_variations()[ $want['index'] ] ?? null;
	if ( ! $chosen || ( $chosen['title'] ?? '' ) !== $want['title'] ) {
		return [ 'error' => 'Style variation ' . $want['index'] . ' is not ' . $want['title'] . '.' ];
	}
}
// As admin: wp_insert_post() sets the post's wp_theme term, which the
// resolver finds the post by, only for a user who can assign terms.
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
if ( empty( $post['ID'] ) ) {
	return [ 'error' => 'Could not find or create the global styles post.' ];
}
$config = [
	'styles'                      => (object) ( $chosen['styles'] ?? [] ),
	'settings'                    => (object) ( $chosen['settings'] ?? [] ),
	'isGlobalStylesUserThemeJSON' => true,
	'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
];
kses_remove_filters();
$updated = wp_update_post( wp_slash( [ 'ID' => $post['ID'], 'post_content' => wp_json_encode( $config ) ] ), true );
if ( is_wp_error( $updated ) ) {
	return [ 'error' => $updated->get_error_message() ];
}
return [
	'post'  => $post['ID'],
	'roles' => \\VoyagerOpenBlocks\\SectionStyles\\catalog()['roles'],
];`
	);
}

/**
 * Save a text and background colour in the Site Editor's global styles, as
 * Styles > Colors does (styles.color in the theme's wp_global_styles post),
 * or clear them, and read the role map get-catalog then reports.
 *
 * @param {Object}                                  site    From boot().
 * @param {{text: string, background: string}|null} colours Hex colours, or null to clear.
 * @return {Promise<Object>} {post, roles} or {error}.
 */
function setSiteColours( site, colours ) {
	return runPhp(
		site,
		`$colours = json_decode( ${ phpString(
			JSON.stringify( colours )
		) }, true );
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
if ( empty( $post['ID'] ) ) {
	return [ 'error' => 'Could not find or create the global styles post.' ];
}
$config = [
	'styles'                      => (object) ( null === $colours ? [] : [ 'color' => $colours ] ),
	'settings'                    => (object) [],
	'isGlobalStylesUserThemeJSON' => true,
	'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
];
kses_remove_filters();
$updated = wp_update_post( wp_slash( [ 'ID' => $post['ID'], 'post_content' => wp_json_encode( $config ) ] ), true );
if ( is_wp_error( $updated ) ) {
	return [ 'error' => $updated->get_error_message() ];
}
return [
	'post'  => $post['ID'],
	'roles' => \\VoyagerOpenBlocks\\SectionStyles\\catalog()['roles'],
];`
	);
}

/**
 * What a section style must paint from the site's own text and background
 * colours on a theme the pack has no role map for, worked out here as
 * values() and paint() in inc/section-styles.php work them out: Inverted is
 * the text with the background as text, Panel 5% of the text mixed into the
 * background, and Accent the text mixed into the background at the first of
 * 75%, 80% and so on to 100% where the background reads at 4.6:1 on it, with
 * the background as text.
 *
 * @param {{text: string, background: string}} colours Hex colours.
 * @return {Object<string, {text: string, background: string}>} Style => colours, as hex.
 */
function fallbackPaint( colours ) {
	const parse = ( value ) =>
		[ 1, 3, 5 ].map( ( i ) =>
			Number.parseInt( value.slice( i, i + 2 ), 16 )
		);
	const toHex = ( rgb ) =>
		'#' +
		rgb
			.map( ( v ) => Math.round( v ).toString( 16 ).padStart( 2, '0' ) )
			.join( '' );
	const luminance = ( rgb ) => {
		const [ r, g, b ] = rgb.map( ( v ) => {
			const s = v / 255;
			return s <= 0.04045 ? s / 12.92 : ( ( s + 0.055 ) / 1.055 ) ** 2.4;
		} );
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	};
	const ratio = ( a, b ) => {
		const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort(
			( x, y ) => y - x
		);
		return ( hi + 0.05 ) / ( lo + 0.05 );
	};
	const t = parse( colours.text );
	const b = parse( colours.background );
	const mix = ( share ) =>
		t.map( ( v, i ) => v * share + b[ i ] * ( 1 - share ) );
	const share =
		[ 0.75, 0.8, 0.85, 0.9, 0.95 ].find(
			( s ) => ratio( b, mix( s ) ) >= 4.6
		) ?? 1;
	return {
		panel: { text: colours.text, background: toHex( mix( 0.05 ) ) },
		inverted: { text: colours.background, background: colours.text },
		accent: { text: colours.background, background: toHex( mix( share ) ) },
	};
}

/**
 * On a theme the pack has no role map for, a text and background colour
 * saved in the Site Editor must reach Panel, Inverted and Accent: resolved()
 * in inc/section-styles.php reads them through user_styles() before the
 * theme's own (root_colors()). Saves them, loads the page and compares each
 * styled section with what fallbackPaint() works out, then clears them.
 *
 * @param {Object} ctx   The run, as checkTheme() gets it.
 * @param {Object} entry The theme's entry in the fixture.
 * @param {string} link  The page with every pattern in every style.
 */
async function checkSiteColours( ctx, entry, link ) {
	const { site, tabs, fixture, sections, keys } = ctx;
	const { theme } = entry;
	const colours = entry.site_editor_colours;
	const width = fixture.controls.contrast.width;
	const set = await setSiteColours( site, colours );
	try {
		if (
			! expect(
				`${ theme }: text ${ colours.text } and background ${ colours.background } are saved in the Site Editor, with the fallback roles still in use`,
				! set.error && set.roles === 'fallback',
				set.error || set.roles
			)
		) {
			return;
		}
		await visit( tabs.visitor, link, width, fixture.height );
		const page = await tabs.visitor.evaluate( call( 'page' ) );
		expect(
			`${ theme }: the page paints in the Site Editor's text and background`,
			page.body.text === colours.text &&
				page.body.background === colours.background,
			page.body
		);
		const want = fallbackPaint( colours );
		const near = ( a, b ) =>
			/^#[0-9a-f]{6}$/.test( a ) &&
			[ 1, 3, 5 ].every(
				( i ) =>
					Math.abs(
						Number.parseInt( a.slice( i, i + 2 ), 16 ) -
							Number.parseInt( b.slice( i, i + 2 ), 16 )
					) <= 1
			);
		for ( const style of [ 'panel', 'inverted', 'accent' ] ) {
			const styled = keys.base.filter(
				( key ) => sections[ key ].style === style
			);
			const scanned = await tabs.visitor.evaluate(
				call( 'scan', styled, { contrast: false, overflow: false } )
			);
			const off = scanned.filter(
				( s ) =>
					s.count !== 1 ||
					! near( s.text, want[ style ].text ) ||
					! near( s.background, want[ style ].background )
			);
			expect(
				`${ theme }: every ${ style } section paints from the Site Editor's colours, text ${ want[ style ].text } on ${ want[ style ].background }`,
				scanned.length === styled.length &&
					scanned.length > 0 &&
					off.length === 0,
				off.length
					? off
							.map(
								( s ) =>
									`${ s.key } text ${ s.text } on ${ s.background }`
							)
							.join( ', ' )
					: `${ scanned.length } sections`
			);
		}
	} finally {
		const reset = await setSiteColours( site, null );
		expect(
			`${ theme }: the Site Editor's colours are cleared`,
			! reset.error,
			reset.error || `global styles post ${ reset.post }`
		);
	}
}

/**
 * Log the admin page in through the login form.
 *
 * @param {Object} tab
 * @param {string} base Site URL.
 */
async function logIn( tab, base ) {
	await tab.goto( `${ base }/wp-admin/` );
	if (
		( await tab.evaluate( 'location.pathname' ) ).endsWith(
			'/wp-login.php'
		)
	) {
		await tab.navigate(
			js(
				( user, pass ) => {
					document.getElementById( 'user_login' ).value = user;
					document.getElementById( 'user_pass' ).value = pass;
					setTimeout( () =>
						document.getElementById( 'loginform' ).submit()
					);
					return true;
				},
				'admin',
				'password'
			)
		);
	}
	const inAdmin = await tab.evaluate(
		"document.body.classList.contains( 'wp-admin' )"
	);
	if ( ! inAdmin ) {
		throw new Error(
			`Could not log in as admin: landed on ${ await tab.evaluate(
				'location.pathname'
			) }`
		);
	}
}

/**
 * Open the page in the editor and check the Styles panel and the canvas.
 *
 * @param {Object} admin  A logged-in page.
 * @param {string} base   Site URL.
 * @param {string} theme  Theme slug.
 * @param {number} postId Fixture page.
 * @param {Object} front  The front end's first Inverted section's colours.
 */
async function checkEditor( admin, base, theme, postId, front ) {
	await admin.goto(
		`${ base }/wp-admin/post.php?post=${ postId }&action=edit`
	);
	const ready = await admin.waitFor( js( editorReadyInPage ), {
		timeout: 120000,
	} );
	if (
		! expect(
			`${ theme }: the block editor loads the fixture page`,
			ready.ok,
			ready.error
		)
	) {
		return;
	}

	await admin.evaluate( js( selectSectionInPage ) );
	await admin.waitFor( js( openStylesTabInPage ), { timeout: 15000 } );
	const shown = await admin.waitFor(
		`(${ stylesInPage.toString() })().panel.length > 0`,
		{ timeout: 15000 }
	);
	const styles = await admin.evaluate( js( stylesInPage ) );
	const labels = [ 'Panel', 'Inverted', 'Accent' ];
	expect(
		`${ theme }: the editor's Styles panel offers Panel, Inverted and Accent for the section`,
		shown.ok && labels.every( ( label ) => styles.panel.includes( label ) ),
		styles.panel
	);
	expect(
		`${ theme }: the blocks store holds the three styles with their names`,
		[
			'voyager-open-panel',
			'voyager-open-inverted',
			'voyager-open-accent',
		].every( ( name ) =>
			styles.registered.some( ( s ) => s.name === name )
		),
		styles.registered
	);

	const canvas = await admin.evaluate( js( canvasInvertedInPage ) );
	expect(
		`${ theme }: the editor canvas paints Inverted as the front end does`,
		canvas &&
			canvas.text === front.text &&
			canvas.background === front.background,
		{ editor: canvas, front }
	);
}

/**
 * Load a page at one width and run the asked checks on each section: as a
 * visitor for contrast and overflow, and again with reduced motion emulated
 * for motion, and for overflow once more, since a reduced-motion rule can
 * change the layout.
 *
 * @param {Object}   tabs     {visitor, still}: pages from launch().newPage(), still with reduced motion emulated.
 * @param {string}   url      Page to load.
 * @param {number}   width    Viewport width.
 * @param {string[]} keys     Sections to measure.
 * @param {Object}   want     Checks to run: {contrast, overflow, motion}.
 * @param {Object}   context  Fields every row carries: theme, variation, width.
 * @param {Object}   sections Key => {pattern, style}.
 * @param {Object}   fixture  tests/fixtures/contrast.json.
 * @return {Promise<{page: Object, still: Object|null, scanned: Array<Object>, contrast: Array<Object>, renders: Array<Object>}>} What it saw.
 */
async function renderAt(
	tabs,
	url,
	width,
	keys,
	want,
	context,
	sections,
	fixture
) {
	// Impeccable scans the page in a browser of its own, so it starts first
	// and runs while this tab measures; judge() waits for it.
	const scanning = want.impeccable
		? impeccableScan( url, width, fixture.height, tabs.chrome )
		: null;
	await visit( tabs.visitor, url, width, fixture.height );
	const page = await tabs.visitor.evaluate( call( 'page' ) );
	const scanned = await tabs.visitor.evaluate(
		call( 'scan', keys, {
			contrast: !! want.contrast,
			overflow: !! want.overflow,
		} )
	);
	const contrast = want.contrast
		? await contrastRows( tabs.visitor, scanned, context, sections )
		: [];
	const judged =
		want.a11y || want.impeccable
			? await judge(
					tabs.visitor,
					url,
					width,
					keys,
					want,
					context.theme,
					fixture,
					scanning
				)
			: {};

	let still = null;
	const calm = {};
	let motion = {};
	const laid = {};
	if ( want.overflow || want.motion || want.layout ) {
		await visit( tabs.still, url, width, fixture.height );
		still = await tabs.still.evaluate( call( 'page' ) );
		// With reduced motion, so a revealed row is measured where it rests.
		if ( want.layout ) {
			const found = await tabs.still.evaluate( call( 'layout', keys ) );
			keys.forEach( ( key, i ) => {
				laid[ key ] = found[ i ];
			} );
		}
		if ( want.overflow ) {
			for ( const s of await tabs.still.evaluate(
				call( 'scan', keys, { contrast: false, overflow: true } )
			) ) {
				calm[ s.key ] = s.overflow;
			}
		}
		if ( want.motion ) {
			motion = await motionResults(
				tabs.still,
				keys,
				fixture.motion.gap_ms
			);
		}
	}

	const renders = [];
	for ( const s of scanned ) {
		const where = {
			theme: context.theme,
			width,
			key: s.key,
			...sections[ s.key ],
		};
		if ( want.overflow ) {
			const results = [
				[ '', s.overflow ],
				[ 'with reduced motion: ', calm[ s.key ] ],
			];
			const notes = results
				.filter( ( [ , r ] ) => ! r?.ok )
				.map(
					( [ label, r ] ) =>
						`${ label }${ r?.note || 'not measured' }`
				);
			renders.push( {
				check: 'overflow',
				...where,
				ok: notes.length === 0,
				note: notes.join( '; ' ) || 'stays inside the viewport',
			} );
		}
		if ( want.motion ) {
			const result = motion[ s.key ] || {
				ok: false,
				note: 'not measured',
			};
			renders.push( {
				check: 'motion',
				...where,
				ok: result.ok,
				note: result.note || 'still',
			} );
		}
		if ( want.layout ) {
			renders.push( {
				check: 'layout',
				...where,
				...layoutVerdict(
					laid[ s.key ] ?? null,
					columnsFor( s.key, sections, fixture ),
					width,
					fixture
				),
			} );
		}
		if ( want.a11y ) {
			const a = judged.a11y;
			const found = s.count === 1 ? a.bySection[ s.key ] || [] : [];
			renders.push( {
				check: 'a11y',
				...where,
				ran: a.ran && s.count === 1,
				ok: a.ran && s.count === 1 && found.length === 0,
				rules: [ ...new Set( found.map( ( v ) => v.rule ) ) ],
				note: ( () => {
					if ( ! a.ran ) {
						return a.note;
					}
					if ( s.count !== 1 ) {
						return 'section not found';
					}
					return found.length
						? found
								.map(
									( v ) =>
										`${ v.rule } (${ v.impact }) at ${ v.target }: ${ clip( v.summary, 160 ) }`
								)
								.join( '; ' )
						: 'no violations';
				} )(),
			} );
		}
		if ( want.impeccable ) {
			const imp = judged.impeccable;
			const found = s.count === 1 ? imp.bySection[ s.key ] || [] : [];
			const failing = found.filter( ( f ) => ! f.known );
			renders.push( {
				check: 'impeccable',
				...where,
				ran: imp.ran && s.count === 1,
				ok: imp.ran && s.count === 1 && failing.length === 0,
				rules: [ ...new Set( failing.map( ( f ) => f.rule ) ) ],
				gaps: found.filter( ( f ) => f.known ),
				targets: imp.tapped[ s.key ] || 0,
				note: ( () => {
					if ( ! imp.ran ) {
						return imp.note;
					}
					if ( s.count !== 1 ) {
						return 'section not found';
					}
					if ( ! found.length ) {
						return `no gated finding, ${
							imp.tapped[ s.key ] || 0
						} tap targets measured`;
					}
					return found
						.map(
							( f ) =>
								`${ f.known ? 'known gap: ' : '' }${ f.rule }${
									f.selector ? ` at ${ f.selector }` : ''
								}: ${ clip( f.summary, 160 ) }`
						)
						.join( '; ' );
				} )(),
			} );
		}
	}
	return { page, still, scanned, contrast, renders, judged };
}

/**
 * Keep what the a11y and impeccable checks saw on one page of the main run
 * that no section's row holds: whether each scan ran, the advisories, and
 * the gated findings no section could be found for.
 *
 * @param {Object} out      The run's rows.
 * @param {Object} judged   From judge(), through renderAt().
 * @param {Object} where    {theme, width}.
 * @param {Object} sections Key => {pattern, style}.
 * @param {string} at       "<theme> at <width>px", for assertion names.
 */
function keepJudged( out, judged, where, sections, at ) {
	const name = ( key ) =>
		sections[ key ]
			? `${ placeOf( sections[ key ] ) } ${ sections[ key ].style }`
			: 'the theme';
	if ( judged?.a11y ) {
		const a = judged.a11y;
		const want = require( 'axe-core/package.json' ).version;
		expect(
			`${ at }: axe-core ${ want } ran on the sections, with heading-order among its rules`,
			a.ran &&
				a.version === want &&
				a.rules.includes( 'heading-order' ) &&
				a.rules.length > 1,
			a.ran
				? `axe-core ${ a.version }, ${ a.rules.length } rules`
				: a.note
		);
		out.a11yRules = a.rules;
		for ( const v of a.advisories ) {
			out.advisories.push( {
				check: 'a11y',
				...where,
				rule: v.rule,
				where: name( v.key ),
				note: `${ v.why }: ${ v.target }: ${ clip( v.summary, 200 ) }`,
			} );
		}
	}
	if ( judged?.impeccable ) {
		const imp = judged.impeccable;
		out.impeccableScans += imp.ran ? 1 : 0;
		expect(
			`${ at }: impeccable detect scanned the page`,
			imp.ran,
			imp.ran ? `${ imp.findings } findings` : imp.note
		);
		expect(
			`${ at }: every gated Impeccable finding is in a section or in the theme's own markup`,
			imp.unplaced.length === 0,
			imp.unplaced
				.map( ( f ) => `${ f.rule } at "${ f.selector }": ${ f.why }` )
				.join( '; ' ) || 'all placed'
		);
		for ( const f of imp.advisories ) {
			out.advisories.push( {
				check: 'impeccable',
				...where,
				rule: f.rule,
				where: f.key
					? f.key.split( ', ' ).map( name ).join( ', ' )
					: 'the theme',
				note: `${ f.why }${ f.selector ? ` at ${ f.selector }` : '' }: ${ clip(
					f.summary,
					200
				) }`,
			} );
		}
	}
}

/**
 * Print the advisories: what the a11y and impeccable checks report and do
 * not gate on, counted by rule, with the first few of each.
 *
 * @param {Array<Object>} advisories From keepJudged().
 */
function printAdvisories( advisories ) {
	for ( const check of [ 'a11y', 'impeccable' ] ) {
		const mine = advisories.filter( ( a ) => a.check === check );
		if ( ! mine.length ) {
			continue;
		}
		const byRule = new Map();
		for ( const a of mine ) {
			byRule.set( a.rule, [ ...( byRule.get( a.rule ) || [] ), a ] );
		}
		group(
			`${ check } advisories, which do not fail the run: ${ mine.length } across ${ byRule.size } rules`
		);
		for ( const [ rule, list ] of [ ...byRule ].sort(
			( a, b ) => b[ 1 ].length - a[ 1 ].length
		) ) {
			const themes = [ ...new Set( list.map( ( a ) => a.theme ) ) ];
			console.log(
				`advisory  ${ rule }: ${ list.length } on ${ themes.join(
					', '
				) }`
			);
			for ( const a of list.slice( 0, 3 ) ) {
				console.log(
					`            ${ a.theme } at ${ a.width }px, ${ a.where }: ${ a.note }`
				);
			}
		}
		endGroup();
		summary( [
			`### ${ check } advisories: ${ mine.length } across ${ byRule.size } rules, none of them failing`,
			'',
			...[ ...byRule ].map(
				( [ rule, list ] ) => `- \`${ rule }\`: ${ list.length }`
			),
		] );
	}
}

/**
 * What a planted cover must measure, worked out here from its colours with
 * the formulas installInPage() uses: the overlay at its dim ratio
 * composited over black and over white, and the text's ratio on each.
 *
 * @param {{overlay: string, dim: number, text: string}} spec From COVER_PLANTS.
 * @return {{black: string, white: string, ratios: number[], ratio: number}} Both composites as hex, both ratios, and the lower one.
 */
function expectedCover( spec ) {
	const parse = ( value ) =>
		[ 1, 3, 5 ].map( ( i ) =>
			Number.parseInt( value.slice( i, i + 2 ), 16 )
		);
	const toHex = ( rgb ) =>
		'#' +
		rgb
			.map( ( v ) => Math.round( v ).toString( 16 ).padStart( 2, '0' ) )
			.join( '' );
	const luminance = ( rgb ) => {
		const [ r, g, b ] = rgb.map( ( v ) => {
			const s = v / 255;
			return s <= 0.04045 ? s / 12.92 : ( ( s + 0.055 ) / 1.055 ) ** 2.4;
		} );
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	};
	const ratio = ( a, b ) => {
		const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort(
			( x, y ) => y - x
		);
		return Math.round( ( ( hi + 0.05 ) / ( lo + 0.05 ) ) * 100 ) / 100;
	};
	const overlay = parse( spec.overlay );
	const alpha = spec.dim / 100;
	const on = ( base ) =>
		overlay.map( ( v, i ) => v * alpha + base[ i ] * ( 1 - alpha ) );
	const text = parse( spec.text );
	const black = on( [ 0, 0, 0 ] );
	const white = on( [ 255, 255, 255 ] );
	const ratios = [ ratio( text, black ), ratio( text, white ) ];
	return {
		black: toHex( black ),
		white: toHex( white ),
		ratios,
		ratio: Math.min( ...ratios ),
	};
}

/**
 * The cover controls on one theme: each planted cover's text is measured
 * against its overlay over the pack's 16:9 placeholder, composited over
 * black and over white, with the lower ratio counted. The white overlay at
 * 50% must fail. The black overlay at 80% must pass at the ratio worked out
 * here, which a measurement against the page behind the cover, white on
 * every theme the fixture lists, could not give.
 *
 * @param {Object} ctx   The run, as checkTheme() gets it.
 * @param {string} theme Theme slug.
 * @param {string} link  The control page, which holds every plant.
 */
async function checkCovers( ctx, theme, link ) {
	const { tabs, fixture, sections } = ctx;
	const { floor } = fixture;
	const { width } = fixture.controls.contrast;
	const seen = await renderAt(
		tabs,
		link,
		width,
		Object.keys( COVER_PLANTS ).map( ( name ) => `plant--${ name }` ),
		{ contrast: true },
		{ theme, variation: '', width },
		sections,
		fixture
	);
	group( `Planted covers on ${ theme }` );
	console.log( contrastTable( seen.contrast, floor ) );
	endGroup();
	for ( const [ name, spec ] of Object.entries( COVER_PLANTS ) ) {
		const want = expectedCover( spec );
		const failing = want.ratio < floor;
		const rows = seen.contrast.filter(
			( r ) => r.pattern === `plant ${ name }`
		);
		const right = ( r ) =>
			r.cover?.media === 'img' &&
			r.cover.loaded === true &&
			r.cover.image.endsWith( '/assets/placeholders/image-16x9.svg' ) &&
			r.cover.black === want.black &&
			r.cover.white === want.white &&
			r.ratio !== null &&
			Math.abs( r.ratio - want.ratio ) <= 0.01 &&
			fails( r, floor ) === failing;
		expect(
			`contrast ${ failing ? 'control' : 'positive control' } on ${ theme }: ${
				spec.label
			} ${
				failing ? 'fails' : `passes at ${ want.ratio }:1`
			}, measured against the overlay over black (${
				want.black
			}) and over white (${ want.white }) on the pack's 16:9 placeholder`,
			rows.length > 0 && rows.every( right ),
			rows
				.map(
					( r ) =>
						`${ r.target } ${ r.state } ${
							r.ratio === null ? r.note : `${ r.ratio }:1`
						} ${ coverNote( r ) || 'measured on no cover' }${
							r.cover && r.cover.loaded !== true
								? `, image ${ r.cover.image || 'none' } did not load`
								: ''
						}`
				)
				.join( '; ' ) || 'no pair measured'
		);
	}
}

/**
 * One recipe page at one width, through every check the run makes, as the
 * pattern page goes through them. Its rows join the run's, so each check's
 * verdict covers them, and the page must render each of its sections once,
 * each in its recipe style, and not scroll sideways.
 *
 * @param {Object}                                       ctx    The run, as checkTheme() gets it.
 * @param {{slug: string, link: string, keys: string[]}} recipe The page and its section keys.
 * @param {string}                                       theme  Theme slug.
 * @param {number}                                       width  Viewport width.
 */
async function checkRecipePage( ctx, recipe, theme, width ) {
	const { tabs, fixture, want, sections, keys, out } = ctx;
	const seen = await renderAt(
		tabs,
		recipe.link,
		width,
		recipe.keys,
		want,
		{ theme, variation: '', width },
		sections,
		fixture
	);
	const at = `${ theme } at ${ width }px, recipe:${ recipe.slug }`;
	out.recipeCount += seen.scanned.filter(
		( s ) => keys.recipe.includes( s.key ) && s.count === 1
	).length;
	out.contrast.push( ...seen.contrast );
	out.renders.push( ...seen.renders );
	keepJudged( out, seen.judged, { theme, width }, sections, at );
	const problems = seen.scanned.flatMap( ( s ) => {
		const { style } = sections[ s.key ];
		if ( s.count !== 1 ) {
			return [ `${ s.key } found ${ s.count } times` ];
		}
		const found = [];
		if (
			style !== 'plain' &&
			! new RegExp( `\\bis-style-voyager-open-${ style }--\\d+\\b` ).test(
				s.className || ''
			)
		) {
			found.push( `${ s.key } carries no ${ style } instance class` );
		}
		if ( want.contrast && ! s.targets?.length ) {
			found.push( `${ s.key } has no text to measure` );
		}
		return found;
	} );
	expect(
		`${ at }: its ${ recipe.keys.length } sections render, each once and in its recipe style${
			want.contrast ? ', each with text to measure' : ''
		}`,
		seen.scanned.length === recipe.keys.length && problems.length === 0,
		problems.join( ', ' ) || `${ seen.scanned.length } sections`
	);
	if ( want.overflow ) {
		for ( const [ label, page ] of [
			[ '', seen.page ],
			[ ' with reduced motion on', seen.still ],
		] ) {
			expect(
				`${ at } does not scroll sideways${ label }`,
				page.scrollWidth <= page.clientWidth,
				`document ${ page.scrollWidth }px wide in a ${ page.clientWidth }px viewport`
			);
		}
	}
}

/**
 * Everything on one theme: the pages, every width, the controls, the editor
 * and the style variations. Adds to the run's rows as it goes.
 *
 * @param {Object} ctx   The run: site, browser, tabs, fixture, opts, want, sections, keys, plants and the row lists.
 * @param {Object} entry The theme's entry in the fixture.
 */
async function checkTheme( ctx, entry ) {
	const { site, tabs, fixture, opts, want, sections, keys, out } = ctx;
	const { theme } = entry;
	const { floor, widths } = fixture;

	await plant( site, null );
	const pages = await buildPages(
		site,
		fixture,
		theme,
		ctx.patterns,
		ctx.plants,
		!! opts.plant,
		ctx.recipes
	);
	if (
		! expect(
			`${ theme }: the pages are built`,
			! pages.error && pages.main?.id > 0,
			pages.error || pages.main.link
		)
	) {
		return;
	}
	// compose resolves each section itself, so the patterns it built must be
	// the ones the rhythm gate's reading names.
	const recipeSlugs = Object.keys( keys.recipes );
	const composed = recipeSlugs.filter( ( slug ) => {
		const built = pages.recipes?.[ slug ];
		return (
			built?.id > 0 &&
			JSON.stringify( built.patterns ) ===
				JSON.stringify(
					keys.recipes[ slug ].map(
						( key ) => sections[ key ].pattern
					)
				)
		);
	} );
	expect(
		`${ theme }: compose builds each recipe in recipes/ as a page, section by section with the patterns the rhythm gate reads, and the planted recipe page is built`,
		recipeSlugs.length > 0 &&
			composed.length === recipeSlugs.length &&
			pages.recipePlant?.id > 0,
		recipeSlugs
			.map(
				( slug ) =>
					`recipe:${ slug } ${
						composed.includes( slug )
							? keys.recipes[ slug ].length
							: `built ${ JSON.stringify(
									pages.recipes?.[ slug ]?.patterns ?? null
								) }, read ${ JSON.stringify(
									keys.recipes[ slug ].map(
										( key ) => sections[ key ].pattern
									)
								) }`
					}`
			)
			.join( ', ' ) +
			( pages.recipePlant?.id > 0
				? `, recipe:${ RECIPE_PLANT.slug } ${ keys.recipePlant.length }`
				: `, no recipe:${ RECIPE_PLANT.slug }` )
	);
	out.versions[ theme ] = pages.version;
	expect(
		`${ theme }: runs on WordPress ${ ctx.wp }`,
		! /^\d/.test( ctx.wp ) ||
			pages.version === ctx.wp ||
			pages.version.startsWith( `${ ctx.wp }-` ),
		`${ pages.version }${
			entry.install
				? `, ${ theme } from ${ entry.install.repo } at ${ entry.install.sha }`
				: ''
		}`
	);
	expect(
		`${ theme }: it is the active theme`,
		pages.stylesheet === theme,
		pages.stylesheet
	);
	expect(
		`${ theme }: every pattern in patterns/ is registered, and no other voyager-open pattern`,
		JSON.stringify( pages.registered ) === JSON.stringify( ctx.patterns ),
		{ files: ctx.patterns, registered: pages.registered }
	);
	expect(
		`${ theme }: get-catalog lists the three styles`,
		[ 'panel', 'inverted', 'accent' ].every( ( style ) =>
			pages.catalog.styles.some(
				( s ) =>
					s.style === style &&
					s.class === `is-style-voyager-open-${ style }`
			)
		),
		pages.catalog.styles
	);
	expect(
		`${ theme }: the colours come from the ${ entry.roles } role map`,
		pages.catalog.roles === entry.roles,
		pages.catalog.roles
	);

	if ( opts.plant ) {
		await plant( site, entry.plant.map );
	}
	const mainKeys = opts.plant ? [ ...keys.base, ...keys.plant ] : keys.base;
	let firstInverted = null;
	for ( const width of widths ) {
		const seen = await renderAt(
			tabs,
			pages.main.link,
			width,
			mainKeys,
			want,
			{ theme, variation: '', width },
			sections,
			fixture
		);
		const base = seen.scanned.filter( ( s ) =>
			keys.base.includes( s.key )
		);
		out.renderCount += base.filter( ( s ) => s.count === 1 ).length;
		out.contrast.push( ...seen.contrast );
		out.renders.push( ...seen.renders );
		const at = `${ theme } at ${ width }px`;
		keepJudged( out, seen.judged, { theme, width }, sections, at );
		expect(
			`${ at }: all ${ keys.base.length } sections render, each once`,
			base.every( ( s ) => s.count === 1 ),
			base
				.filter( ( s ) => s.count !== 1 )
				.map( ( s ) => `${ s.key } found ${ s.count } times` )
				.join( ', ' ) || `${ base.length } sections`
		);
		const unstyled = base.filter(
			( s ) =>
				sections[ s.key ].style !== 'plain' &&
				! new RegExp(
					`\\bis-style-voyager-open-${ sections[ s.key ].style }--\\d+\\b`
				).test( s.className || '' )
		);
		expect(
			`${ at }: each styled section carries its style's instance class`,
			unstyled.length === 0,
			unstyled.map( ( s ) => s.key ).join( ', ' ) || 'all of them'
		);
		const inverted = base.filter(
			( s ) => sections[ s.key ].style === 'inverted'
		);
		firstInverted ??= inverted[ 0 ] || null;
		expect(
			`${ at }: Inverted is painted in the theme's own text colour, with its background colour as text`,
			inverted.length > 0 &&
				inverted.every(
					( s ) =>
						s.background === seen.page.body.text &&
						s.text === seen.page.body.background
				),
			{
				body: seen.page.body,
				inverted: inverted.map( ( s ) => ( {
					key: s.key,
					text: s.text,
					background: s.background,
				} ) ),
			}
		);
		if ( want.contrast ) {
			expect(
				`${ at }: every section has text to measure`,
				base.every( ( s ) => s.targets?.length > 0 ),
				base
					.map( ( s ) => `${ s.key } ${ s.targets?.length ?? 0 }` )
					.join( ', ' )
			);
		}
		if ( seen.still ) {
			expect(
				`${ at }: the reduced-motion page matches prefers-reduced-motion: reduce, and the visitor's does not`,
				seen.still.reducedMotion && ! seen.page.reducedMotion,
				{
					still: seen.still.reducedMotion,
					visitor: seen.page.reducedMotion,
				}
			);
		}
		if ( want.overflow ) {
			for ( const [ label, page ] of [
				[ '', seen.page ],
				[ ' with reduced motion on', seen.still ],
			] ) {
				expect(
					`${ at }: the page does not scroll sideways${ label }`,
					page.scrollWidth <= page.clientWidth,
					`document ${ page.scrollWidth }px wide in a ${ page.clientWidth }px viewport`
				);
			}
		}

		// Each recipe page, through every check the run makes. --plant puts
		// recipe:plant among them.
		const recipeRuns = composed.map( ( slug ) => ( {
			slug,
			link: pages.recipes[ slug ].link,
			keys: keys.recipes[ slug ],
		} ) );
		if ( opts.plant && pages.recipePlant ) {
			recipeRuns.push( {
				slug: RECIPE_PLANT.slug,
				link: pages.recipePlant.link,
				keys: keys.recipePlant,
			} );
		}
		for ( const recipe of recipeRuns ) {
			await checkRecipePage( ctx, recipe, theme, width );
		}
	}

	if ( want.a11y && pages.recipePlant ) {
		// Every run: on recipe:plant the skipped heading between two shipped
		// sections must fail a11y on heading-order, and the shipped sections
		// pass, so a11y is seen to read a recipe page.
		const { width } = fixture.controls.a11y;
		const plantKey = recipeKey( RECIPE_PLANT.slug, RECIPE_PLANT.at );
		const seen = await renderAt(
			tabs,
			pages.recipePlant.link,
			width,
			keys.recipePlant,
			{ a11y: true },
			{ theme, variation: '', width },
			sections,
			fixture
		);
		const rows = seen.renders.filter( ( r ) => r.check === 'a11y' );
		const row = rows.find( ( r ) => r.key === plantKey );
		const shipped = rows.filter( ( r ) => r.key !== plantKey );
		expect(
			`a11y control on ${ theme }: on recipe:${ RECIPE_PLANT.slug }, the planted h2 followed by an h4 between two shipped sections fails a11y on heading-order, and the shipped sections pass`,
			row &&
				row.ran &&
				! row.ok &&
				row.rules.includes( 'heading-order' ) &&
				shipped.length === keys.recipePlant.length - 1 &&
				shipped.every( ( r ) => r.ok ),
			rows
				.map(
					( r ) =>
						`${ placeOf( r ) }: ${ r.ok ? 'ok' : 'FAIL' } ${ r.note }`
				)
				.join( '; ' ) || 'no a11y measurement of the page'
		);
	}

	if ( want.motion ) {
		// A pattern that moves with motion on must be seen moving, so a still
		// render with motion off is its reduced-motion rule at work, not a
		// check that cannot see it.
		const movers = keys.base.filter( ( key ) =>
			fixture.motion.moves_with_motion_on.includes(
				sections[ key ].pattern
			)
		);
		await visit(
			tabs.visitor,
			pages.main.link,
			widths[ 0 ],
			fixture.height
		);
		const moving = await tabs.visitor.evaluate( call( 'sample', movers ) );
		expect(
			`${ theme }: with motion on, the motion check sees ${ fixture.motion.moves_with_motion_on.join(
				', '
			) } animate in every style`,
			movers.length ===
				fixture.motion.moves_with_motion_on.length *
					fixture.styles.length &&
				moving.every( ( m ) => m?.running.length > 0 ),
			moving.map( ( m ) => m?.running.join( '; ' ) || 'nothing' )
		);
	}

	// The controls: each planted fault must fail its own check.
	if ( want.contrast ) {
		const control = await plant( site, entry.plant.map );
		const { width } = fixture.controls.contrast;
		const seen = await renderAt(
			tabs,
			pages.main.link,
			width,
			keys.base.filter(
				( key ) => sections[ key ].style === entry.plant.style
			),
			{ contrast: true },
			{ theme, variation: '', width },
			sections,
			fixture
		);
		await plant( site, null );
		group( `Planted ${ JSON.stringify( entry.plant.map ) } on ${ theme }` );
		console.log( contrastTable( seen.contrast, floor ) );
		endGroup();
		expect(
			`${ theme }: the planted role map is in use for the control`,
			control.roles === 'filter',
			control.roles
		);
		// At rest every pair is painted from the role map. A hover can be the
		// theme's own (Twenty Twenty-Four fills an outline button grey on
		// hover, whatever the section), so only rest pairs must fail. Text in
		// a cover is not painted from the map: the cover sets its own overlay
		// and text colours, and the colours of a button on it, so the planted
		// map cannot reach it, and the planted covers in checkCovers() are
		// its controls.
		const rest = seen.contrast.filter(
			( r ) => r.state === 'rest' && ! r.onCover
		);
		expect(
			`contrast control on ${ theme }: every pair at rest in the planted ${ entry.plant.style } style fails, so the gate can fail`,
			rest.length > 0 && rest.every( ( r ) => fails( r, floor ) ),
			rest
				.filter( ( r ) => ! fails( r, floor ) )
				.map( ( r ) => `${ r.pattern } ${ r.target } ${ r.ratio }:1` )
				.join( ', ' ) || `${ rest.length } rows fail`
		);
		await checkCovers( ctx, theme, pages.control.link );
	}
	for ( const check of [ 'overflow', 'motion' ] ) {
		if ( ! want[ check ] ) {
			continue;
		}
		const { width } = fixture.controls[ check ];
		const seen = await renderAt(
			tabs,
			pages.control.link,
			width,
			[ `plant--${ check }` ],
			{ [ check ]: true },
			{ theme, variation: '', width },
			sections,
			fixture
		);
		const row = seen.renders.find( ( r ) => r.check === check );
		expect(
			check === 'overflow'
				? `overflow control on ${ theme }: the planted section with an element ${ fixture.controls.overflow.element_width }px wide fails overflow at ${ width }px`
				: `motion control on ${ theme }: the planted animation with no reduced-motion rule fails motion`,
			row && ! row.ok,
			row ? row.note : `no ${ check } measurement of the planted section`
		);
	}
	// Each layout plant must fail on its own reason, at the width it is for.
	if ( want.layout ) {
		const widthOf = ( at ) =>
			at === 'wide' ? fixture.layout.wide : fixture.layout.stacked[ 0 ];
		for ( const width of new Set(
			Object.values( LAYOUT_PLANTS ).map( ( p ) => widthOf( p.at ) )
		) ) {
			const names = Object.keys( LAYOUT_PLANTS ).filter(
				( name ) => widthOf( LAYOUT_PLANTS[ name ].at ) === width
			);
			const seen = await renderAt(
				tabs,
				pages.control.link,
				width,
				names.map( ( name ) => `plant--${ name }` ),
				{ layout: true },
				{ theme, variation: '', width },
				sections,
				fixture
			);
			for ( const name of names ) {
				const { reason, label } = LAYOUT_PLANTS[ name ];
				const row = seen.renders.find(
					( r ) =>
						r.check === 'layout' && r.key === `plant--${ name }`
				);
				expect(
					`layout control on ${ theme }: the planted ${ label } fails layout on ${ reason } at ${ width }px`,
					row && ! row.ok && row.reasons.includes( reason ),
					row
						? row.note
						: 'no layout measurement of the planted section'
				);
			}
		}
	}
	// Each of these plants must fail its check on its own rule, so a plant
	// that fails for some other reason does not count.
	const ruled = {
		a11y: [ [ 'heading', 'heading-order', 'h2 followed by an h4' ] ],
		impeccable: [
			[ 'gradient', 'gradient-text', 'heading in gradient text' ],
			[ 'cards', 'nested-cards', 'card inside a card' ],
			[
				'target',
				TAP_TARGET,
				`30px tall button, under ${ fixture.impeccable.tap_target.min }px`,
			],
		],
	};
	for ( const [ check, plants ] of Object.entries( ruled ) ) {
		if ( ! want[ check ] ) {
			continue;
		}
		const { width } = fixture.controls[ check ];
		const seen = await renderAt(
			tabs,
			pages.control.link,
			width,
			plants.map( ( [ name ] ) => `plant--${ name }` ),
			{ [ check ]: true },
			{ theme, variation: '', width },
			sections,
			fixture
		);
		for ( const [ name, rule, what ] of plants ) {
			const row = seen.renders.find(
				( r ) => r.check === check && r.key === `plant--${ name }`
			);
			expect(
				`${ check } control on ${ theme }: the planted ${ what } fails ${ check } on ${ rule }`,
				row && row.ran && ! row.ok && row.rules.includes( rule ),
				row
					? row.note
					: `no ${ check } measurement of the planted section`
			);
		}
	}

	if ( entry.editor && want.contrast ) {
		if ( ! tabs.admin ) {
			tabs.admin = await ctx.browser.newPage( {
				width: 1440,
				height: 900,
			} );
			await logIn( tabs.admin, site.url );
		}
		await checkEditor(
			tabs.admin,
			site.url,
			theme,
			pages.main.id,
			firstInverted
				? {
						text: firstInverted.text,
						background: firstInverted.background,
					}
				: null
		);
	}

	if ( entry.site_editor_colours && want.contrast ) {
		await checkSiteColours( ctx, entry, pages.main.link );
	}

	if ( ! want.contrast || ! entry.variations || opts[ 'no-variations' ] ) {
		return;
	}
	const found = await listVariations( site );
	const coloured = found.filter( ( v ) => v.colour );
	const names = coloured.map( ( v ) => v.name );
	expect(
		`${ theme }: the colour-bearing style variations it ships are the ${ entry.variations.length } the fixture lists`,
		JSON.stringify( [ ...names ].sort() ) ===
			JSON.stringify( [ ...entry.variations ].sort() ),
		{ ships: names, listed: entry.variations }
	);
	for ( const variation of coloured ) {
		const used = await switchVariation( site, variation );
		if (
			! expect(
				`${ theme }/${ variation.name }: switched on, with the ${ entry.roles } role map still in use`,
				! used.error && used.roles === entry.roles,
				used.error || used.roles
			)
		) {
			continue;
		}
		for ( const width of fixture.variations.widths ) {
			const seen = await renderAt(
				tabs,
				pages.main.link,
				width,
				keys.base,
				{ contrast: true },
				{ theme, variation: variation.name, width },
				sections,
				fixture
			);
			const mismatches = await tabs.visitor.evaluate(
				call( 'paletteMismatches', variation.palette )
			);
			expect(
				`${ theme }/${ variation.name } at ${ width }px: the page paints with the variation's palette`,
				mismatches.length === 0,
				mismatches.join( ', ' ) ||
					`${ variation.palette.length } colours`
			);
			out.variations.push( ...seen.contrast );
		}
	}
	const reset = await switchVariation( site, null );
	expect(
		`${ theme }: back on the theme's own styles`,
		! reset.error,
		reset.error || `global styles post ${ reset.post }`
	);
}

/**
 * The blueprint step that installs a theme the fixture pins to a commit,
 * from GitHub's archive of that commit, into the folder named after it.
 *
 * @param {Object} entry A theme entry with install: {repo, sha}.
 * @return {Object} An installTheme step.
 */
function installStep( entry ) {
	return {
		step: 'installTheme',
		themeData: {
			resource: 'url',
			url: `https://codeload.github.com/${ entry.install.repo }/zip/${ entry.install.sha }`,
		},
		options: { activate: false, targetFolderName: entry.theme },
	};
}

/**
 * @param {Object} opts Parsed flags.
 * @return {Promise<number>} Exit code.
 */
async function run( opts ) {
	const fixture = JSON.parse( readFileSync( FIXTURE, 'utf8' ) );
	plantedRun = !! opts.plant;
	const only = opts.themes
		? new Set(
				String( opts.themes )
					.split( ',' )
					.map( ( s ) => s.trim() )
			)
		: null;
	const themes = fixture.themes.filter(
		( t ) => ! only || only.has( t.theme )
	);
	if ( ! themes.length ) {
		console.error(
			`--themes matched none of ${ fixture.themes.map( ( t ) => t.theme ).join( ', ' ) }.`
		);
		return 2;
	}
	// The fixture's floors are for every theme. A run on some of them, such
	// as one CI job per theme, is held to their share.
	const share = themes.length / fixture.themes.length;
	const chrome = findChrome();
	if ( ! chrome ) {
		annotate(
			'error',
			process.env.CHROME_PATH
				? `CHROME_PATH points to ${ process.env.CHROME_PATH }, which does not exist.`
				: 'No Chrome, Chromium or Edge found. Install one, or set CHROME_PATH to its executable.',
			'npm run design-check'
		);
		return 1;
	}

	const asked = opts.checks
		? String( opts.checks )
				.split( ',' )
				.map( ( s ) => s.trim() )
		: CHECKS;
	const want = Object.fromEntries(
		CHECKS.map( ( name ) => [ name, asked.includes( name ) ] )
	);
	const { widths } = fixture;
	const patterns = patternFiles();
	const plants = plantSections( fixture.controls.overflow.element_width );
	/** Section key => {pattern, style}. */
	const sections = {};
	for ( const pattern of patterns ) {
		for ( const style of fixture.styles ) {
			sections[ `${ pattern }--${ style }` ] = { pattern, style };
		}
	}
	const keys = { base: Object.keys( sections ), plant: [] };
	for ( const name of Object.keys( plants ) ) {
		sections[ `plant--${ name }` ] = {
			pattern: `plant ${ name }`,
			style: 'plain',
		};
		keys.plant.push( `plant--${ name }` );
	}

	// One page per recipe in recipes/, read as npm run check's rhythm gate
	// reads it: each section is its pattern in its recipe style, keyed
	// recipe--<slug>--<n>, so a check that reads a section's pattern (layout's
	// columns, contrast's known failures) reads the recipe section's.
	const recipes = recipePages( repo );
	keys.recipes = {};
	for ( const recipe of recipes ) {
		keys.recipes[ recipe.slug ] = recipe.sections.map( ( section, i ) => {
			const key = recipeKey( recipe.slug, i + 1 );
			sections[ key ] = {
				...section,
				page: `recipe:${ recipe.slug }`,
				at: i + 1,
			};
			return key;
		} );
	}
	keys.recipe = Object.values( keys.recipes ).flat();
	const recipeProblems = recipes.flatMap( ( recipe ) => recipe.problems );
	expect(
		`recipes: the ${ recipes.length } recipes in recipes/ each name a pattern for every section, with no two sections next to each other in one style but plain, so each is a page`,
		recipes.length > 0 &&
			recipeProblems.length === 0 &&
			! Object.hasOwn( keys.recipes, RECIPE_PLANT.slug ),
		recipeProblems.join( '; ' ) ||
			( Object.hasOwn( keys.recipes, RECIPE_PLANT.slug )
				? `recipes/${ RECIPE_PLANT.slug }.json takes the planted recipe page's name`
				: `${ keys.recipe.length } sections` )
	);
	// recipe:plant: the skipped-heading plant between the first two sections
	// of the first recipe that has two.
	const host = recipes.find( ( recipe ) => recipe.sections.length >= 2 );
	keys.recipePlant = [];
	if ( host ) {
		const [ first, second ] = host.sections;
		const planted = { pattern: 'plant heading', style: 'plain' };
		for ( const [ i, section ] of [ first, planted, second ].entries() ) {
			const key = recipeKey( RECIPE_PLANT.slug, i + 1 );
			sections[ key ] = {
				...section,
				page: `recipe:${ RECIPE_PLANT.slug }`,
				at: i + 1,
			};
			keys.recipePlant.push( key );
		}
	}
	const recipeArgs = {
		pages: recipes
			.filter( ( recipe ) => recipe.sections.length )
			.map( ( recipe ) => ( {
				slug: recipe.slug,
				keys: keys.recipes[ recipe.slug ],
			} ) ),
		plant: host
			? {
					host: host.slug,
					keys: keys.recipePlant,
					markup: sectionGroup( rawHtml( HEADING_PLANT ) ),
				}
			: null,
	};

	// One dev site per WordPress version: each theme names the version it
	// runs on, and --wp puts them all on one.
	const sites = new Map();
	for ( const entry of themes ) {
		const wp = opts.wp ? String( opts.wp ) : entry.wp;
		if ( ! sites.has( wp ) ) {
			sites.set( wp, [] );
		}
		sites.get( wp ).push( entry );
	}

	const out = {
		versions: {},
		renderCount: 0,
		recipeCount: 0,
		contrast: [],
		variations: [],
		renders: [],
		advisories: [],
		impeccableScans: 0,
		a11yRules: [],
	};
	let browser;
	try {
		browser = await launch( chrome );
		const tabs = {
			visitor: await browser.newPage( {
				width: widths[ 0 ],
				height: fixture.height,
			} ),
			still: await browser.newPage( {
				width: widths[ 0 ],
				height: fixture.height,
			} ),
			admin: null,
			// The executable, for impeccable detect, which starts its own.
			chrome,
		};
		await tabs.still.call( 'Emulation.setEmulatedMedia', {
			features: [ { name: 'prefers-reduced-motion', value: 'reduce' } ],
		} );

		for ( const [ wp, entries ] of sites ) {
			let site;
			try {
				group( `Boot the dev site on WordPress ${ wp }` );
				try {
					site = await boot( {
						...opts,
						wp,
						login: false,
						// A beta updates itself in the background, and the
						// site answers 503 Maintenance while it does
						// (WP_Automatic_Updater::is_disabled()).
						consts: { AUTOMATIC_UPDATER_DISABLED: true },
						steps: entries
							.filter( ( entry ) => entry.install )
							.map( installStep ),
					} );
				} finally {
					endGroup();
				}
				console.log( `Site: ${ site.url }` );

				const written = await runPhp(
					site,
					`$dir = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p( $dir );
return false !== file_put_contents( $dir . '/voyager-open-contrast-plant.php', ${ phpString( PLANT_PLUGIN ) } );`
				);
				if ( ! written ) {
					throw new Error( 'Could not write the plant mu-plugin.' );
				}
				// A new site needs a new login.
				await tabs.admin?.close();
				tabs.admin = null;

				const ctx = {
					site,
					wp,
					browser,
					tabs,
					fixture,
					opts,
					want,
					patterns,
					plants,
					recipes: recipeArgs,
					sections,
					keys,
					out,
				};
				for ( const entry of entries ) {
					group( entry.theme );
					try {
						await checkTheme( ctx, entry );
					} catch ( error ) {
						expect(
							`${ entry.theme }: ran to the end`,
							false,
							error.message
						);
						if ( opts.debug ) {
							console.error( error.stack );
						}
					} finally {
						endGroup();
					}
				}

				const fatal = reportPhpLog( await phpLog( site ) );
				expect(
					`WordPress ${ wp }: no PHP fatal errors in debug.log`,
					fatal === 0,
					`${ fatal } fatal`
				);
			} catch ( error ) {
				expect(
					`WordPress ${ wp }: the dev site booted and ran`,
					false,
					error.message
				);
				if ( opts.debug ) {
					console.error( error.stack );
				}
			} finally {
				await site?.stop?.();
			}
		}
	} catch ( error ) {
		expect( 'npm run design-check set up and ran', false, error.message );
		if ( opts.debug ) {
			console.error( error.stack );
		}
	} finally {
		await browser?.close();
	}

	const counts = `${ patterns.length } patterns x ${ fixture.styles.length } styles x ${ widths.length } widths x ${ themes.length } themes`;
	const expected =
		patterns.length * fixture.styles.length * widths.length * themes.length;
	// Read from recipes/, so a recipe added or changed needs no fixture edit.
	const recipeCounts = `${ recipes.length } recipes, ${ keys.recipe.length } sections x ${ widths.length } widths x ${ themes.length } themes`;
	const recipeExpected = keys.recipe.length * widths.length * themes.length;

	if ( want.contrast ) {
		checkContrast( out, themes, fixture, opts, share );
	}

	const verdicts = {
		overflow: 'overflow: no render scrolls sideways',
		motion: 'motion: nothing moves under reduced motion',
		layout: `layout: every row of items renders the columns the fixture names at ${
			fixture.layout.wide
		}px and one at ${ fixture.layout.stacked.join(
			' and '
		) }px, and no heading word breaks or spills`,
		a11y: 'a11y: axe-core reports no violation in any render',
		impeccable: `impeccable: no render breaks a gated Impeccable rule (${ fixture.impeccable.gate.join(
			', '
		) }) or has a tap target under ${
			fixture.impeccable.tap_target.min
		}px outside the known gaps`,
	};
	for ( const check of RENDER_CHECKS ) {
		if ( ! want[ check ] ) {
			continue;
		}
		const rows = out.renders.filter( ( r ) => r.check === check );
		const base = rows.filter( ( r ) => keys.base.includes( r.key ) );
		// a11y and impeccable count a render only once its scan ran.
		const scanned = base.filter( ( r ) => r.ran !== false );
		const recipeScanned = rows.filter(
			( r ) => keys.recipe.includes( r.key ) && r.ran !== false
		);
		const both = `${ scanned.length } section renders and ${ recipeScanned.length } recipe section renders scanned`;
		group( `${ check }: ${ both }` );
		console.log( renderTable( rows ) );
		endGroup();
		const failed = rows.filter( ( r ) => ! r.ok );
		summary( [
			`### ${ check }: ${ both }, ${ failed.length } failing`,
			'',
			...failed
				.slice( 0, 100 )
				.map(
					( r ) =>
						`- ${ r.theme } at ${ r.width }px, ${ placeOf( r ) } ${ r.style }: ${ r.note }`
				),
		] );
		expect(
			verdicts[ check ],
			base.length > 0 && failed.length === 0,
			failed.length ? `\n${ renderTable( failed ) }` : both
		);
		expect(
			`${ check }: ${ expected } section renders scanned (${ counts })`,
			scanned.length === expected,
			`${ scanned.length } scanned`
		);
		expect(
			`${ check }: ${ recipeExpected } recipe section renders scanned (${ recipeCounts })`,
			recipeExpected > 0 && recipeScanned.length === recipeExpected,
			`${ recipeScanned.length } scanned`
		);
	}
	if ( want.impeccable ) {
		checkTapGaps( out, themes, fixture, keys );
	}
	printAdvisories( out.advisories );

	const minRenders = Math.ceil( fixture.min_renders * share );
	expect(
		`at least ${ minRenders } section renders (${ counts })`,
		out.renderCount >= minRenders && out.renderCount === expected,
		`${ out.renderCount } of ${ expected }`
	);
	expect(
		`one recipe page per recipe on each theme at each width: ${ recipeExpected } recipe section renders (${ recipeCounts })`,
		recipeExpected > 0 && out.recipeCount === recipeExpected,
		`${ out.recipeCount } of ${ recipeExpected }`
	);

	const failed = checks.filter( ( c ) => ! c.ok );
	const on = Object.entries( out.versions )
		.map( ( [ theme, version ] ) => `${ theme } on WordPress ${ version }` )
		.join( ', ' );
	const tally = [
		`Design check: ${ out.renderCount } section renders (${ counts })`,
		`${ out.recipeCount } recipe section renders (${ recipeCounts })`,
		want.contrast ? `${ out.contrast.length } contrast pairs` : '',
		want.contrast && ! opts[ 'no-variations' ]
			? `${ out.variations.length } style variation contrast pairs`
			: '',
		...RENDER_CHECKS.filter( ( check ) => want[ check ] ).map(
			( check ) =>
				`${
					out.renders.filter(
						( r ) =>
							r.check === check &&
							( keys.base.includes( r.key ) ||
								keys.recipe.includes( r.key ) ) &&
							r.ran !== false
					).length
				} ${ check } renders${
					// eslint-disable-next-line no-nested-ternary
					check === 'a11y'
						? ` (${ out.a11yRules.length } axe-core rules)`
						: check === 'impeccable'
							? ` (${ out.impeccableScans } page scans)`
							: ''
				}`
		),
	]
		.filter( Boolean )
		.join( ', ' );
	summary( [
		`### npm run design-check${ opts.plant ? ' --plant' : '' }`,
		'',
		`${ tally }. ${ on || 'No theme ran' }.`,
		'',
		'| Result | Assertion | Observed |',
		'|---|---|---|',
		...checks.map(
			( c ) =>
				`| ${ c.ok ? 'ok' : 'FAIL' } | ${ c.name.replace( /\|/g, '\\|' ) } | ${ clip(
					c.observed,
					300
				)
					.replace( /\|/g, '\\|' )
					.replace( /\n/g, ' ' ) } |`
		),
	] );
	console.log( `\n${ tally }.\n${ on || 'No theme ran' }.` );
	console.log(
		`${ checks.length - failed.length } of ${ checks.length } assertions passed.`
	);
	if ( failed.length ) {
		console.log(
			`Failed:\n${ failed.map( ( c ) => `  ${ c.name }` ).join( '\n' ) }`
		);
	}
	if ( ! opts.plant ) {
		return failed.length ? 1 : 0;
	}
	const problems = plantedProblems( out, want, verdicts, failed, fixture );
	if ( problems.length ) {
		annotate(
			'error',
			`The planted run did not fail the way it must: ${ problems.join(
				'; '
			) }`,
			'npm run design-check -- --plant'
		);
		return 3;
	}
	console.log(
		'Planted run: every check failed on its planted faults, and nothing else failed.'
	);
	return 1;
}

/** The planted sections each render check must fail in a --plant run. */
const PLANTED_FAILS = {
	overflow: [ 'plant--overflow' ],
	motion: [ 'plant--motion' ],
	layout: Object.keys( LAYOUT_PLANTS ).map( ( name ) => `plant--${ name }` ),
	a11y: [ 'plant--heading', recipeKey( RECIPE_PLANT.slug, RECIPE_PLANT.at ) ],
	impeccable: [ 'plant--gradient', 'plant--cards', 'plant--target' ],
};

/**
 * What is wrong with a --plant run, which must fail every check it ran on
 * that check's own planted faults and fail nothing else: no setup step, no
 * count and no control. A run that crashed, or that failed for some other
 * reason, is not a planted run that failed.
 *
 * @param {Object}        out      The run's rows.
 * @param {Object}        want     Checks run.
 * @param {Object}        verdicts Render check => the name of its verdict.
 * @param {Array<Object>} failed   The failed assertions.
 * @param {Object}        fixture  tests/fixtures/contrast.json.
 * @return {string[]} Problems, none for a planted run that failed as it must.
 */
function plantedProblems( out, want, verdicts, failed, fixture ) {
	const problems = [];
	const names = new Set( failed.map( ( c ) => c.name ) );
	for ( const check of RENDER_CHECKS ) {
		if ( ! want[ check ] ) {
			continue;
		}
		if ( ! names.has( verdicts[ check ] ) ) {
			problems.push( `${ check } did not fail` );
		}
		const failing = new Set(
			out.renders
				.filter( ( r ) => r.check === check && ! r.ok )
				.map( ( r ) => r.key )
		);
		const missed = PLANTED_FAILS[ check ].filter(
			( key ) => ! failing.has( key )
		);
		if ( missed.length ) {
			problems.push( `${ check } did not fail ${ missed.join( ', ' ) }` );
		}
	}
	const contrastVerdict = ( name ) =>
		name.startsWith( 'contrast: every pair measures' );
	if ( want.contrast ) {
		if ( ! failed.some( ( c ) => contrastVerdict( c.name ) ) ) {
			problems.push( 'contrast did not fail' );
		}
		if (
			! out.contrast.some(
				( r ) =>
					r.pattern === 'plant cover' && fails( r, fixture.floor )
			)
		) {
			problems.push( 'contrast did not fail plant--cover' );
		}
	}
	const planted = ( name ) =>
		Object.values( verdicts ).includes( name ) ||
		contrastVerdict( name ) ||
		/: the page does not scroll sideways( with reduced motion on)?$/.test(
			name
		);
	const other = failed.filter( ( c ) => ! planted( c.name ) );
	if ( other.length ) {
		problems.push(
			`it failed more than its plants: ${ other
				.map( ( c ) => c.name )
				.join( ' | ' ) }`
		);
	}
	return problems;
}

/**
 * The known tap-target gaps: each one the fixture lists for a theme that ran
 * must still be measured under the minimum, at the height listed, so the
 * list stays true.
 *
 * @param {Object}        out     The run's rows.
 * @param {Array<Object>} themes  The fixture's theme entries this run covered.
 * @param {Object}        fixture tests/fixtures/contrast.json.
 * @param {Object}        keys    {base, plant}: section keys.
 */
function checkTapGaps( out, themes, fixture, keys ) {
	const tap = fixture.impeccable.tap_target;
	const lines = [];
	const stale = [];
	for ( const { theme } of themes ) {
		const rows = out.renders.filter(
			( r ) =>
				r.check === 'impeccable' &&
				r.theme === theme &&
				r.ran &&
				keys.base.includes( r.key )
		);
		if ( ! rows.length ) {
			continue;
		}
		for ( const gap of tap.known_gaps?.[ theme ] || [] ) {
			const seen = rows.flatMap( ( r ) =>
				r.gaps.filter( ( f ) => f.known === gap )
			);
			const heights = seen.map( ( f ) => f.height );
			if ( ! seen.length ) {
				stale.push(
					`${ theme } ${ gap.kind } at ${ gap.height }px is no longer measured under ${ tap.min }px: remove it from the fixture`
				);
				continue;
			}
			lines.push(
				`${ theme } ${ gap.kind }: listed at ${ gap.height }px, measured ${ Math.min(
					...heights
				) } to ${ Math.max( ...heights ) }px tall on ${
					new Set(
						rows
							.filter( ( r ) =>
								r.gaps.some( ( f ) => f.known === gap )
							)
							.map( ( r ) => r.key + r.width )
					).size
				} renders`
			);
		}
	}
	expect(
		'impeccable: every known tap-target gap the fixture lists is still measured',
		stale.length === 0,
		stale.join( '; ' ) ||
			lines.join( '; ' ) ||
			'none listed for these themes'
	);
}

/**
 * Contrast against the floor and the fixture's known failures, for the base
 * themes and the style variations: every pair under the floor must be
 * listed, and every listed pair that was measured must still fail.
 *
 * @param {Object}        out     The run's rows.
 * @param {Array<Object>} themes  The fixture's theme entries this run covered.
 * @param {Object}        fixture tests/fixtures/contrast.json.
 * @param {Object}        opts    Parsed flags.
 * @param {number}        share   The run's themes over the fixture's, which scales the floor on pairs.
 */
function checkContrast( out, themes, fixture, opts, share ) {
	const { floor } = fixture;
	const known = new Map();
	for ( const entry of themes ) {
		for ( const failure of entry.known_failures || [] ) {
			const listed = { variation: '', ...failure };
			known.set( `${ entry.theme }|${ knownKey( listed ) }`, {
				...listed,
				theme: entry.theme,
				seen: [],
			} );
		}
	}
	const all = [ ...out.contrast, ...out.variations ];
	for ( const row of all ) {
		const listed = known.get( `${ row.theme }|${ knownKey( row ) }` );
		if ( listed && fails( row, floor ) ) {
			row.known = true;
			listed.seen.push( row );
		}
	}
	const listedFor = ( rows ) =>
		[ ...known.values() ].filter( ( k ) =>
			rows.some(
				( r ) => r.theme === k.theme && r.variation === k.variation
			)
		).length;

	const parts = [
		{
			name: 'contrast',
			rows: out.contrast,
			heading: `Contrast: ${ out.contrast.length } pairs over ${ out.renderCount } section renders and ${ out.recipeCount } recipe section renders, floor ${ floor }:1${
				opts.plant ? ' (PLANTED)' : ''
			}`,
		},
	];
	if ( ! opts[ 'no-variations' ] ) {
		parts.push( {
			name: 'style variations',
			rows: out.variations,
			heading: `Style variations: ${ out.variations.length } contrast pairs over ${
				new Set(
					out.variations.map(
						( r ) => `${ r.theme }/${ r.variation }`
					)
				).size
			} variations at ${ fixture.variations.widths.join( ' and ' ) }px`,
		} );
	}
	const unlisted = [];
	for ( const { name, rows, heading } of parts ) {
		group( heading );
		console.log( contrastTable( rows, floor ) );
		endGroup();
		const under = rows.filter( ( r ) => fails( r, floor ) && ! r.known );
		const listedRows = rows.filter( ( r ) => r.known );
		unlisted.push( ...under );
		summarizeContrast(
			`${ heading }: ${ listedRows.length } known failures, ${ under.length } other pairs under the floor`,
			rows.filter( ( r ) => fails( r, floor ) ),
			floor
		);
		expect(
			`${ name }: every pair measures ${ floor }:1 or more, apart from the ${ listedFor(
				rows
			) } known failures the fixture lists`,
			rows.length > 0 && under.length === 0,
			under.length
				? `\n${ contrastTable( under, floor ) }`
				: `${ rows.length } pairs, ${ listedRows.length } of them known failures`
		);
	}
	const minPairs = Math.ceil( fixture.min_pairs * share );
	expect(
		`contrast: at least ${ minPairs } pairs measured`,
		out.contrast.length >= minPairs,
		`${ out.contrast.length } pairs`
	);
	if ( unlisted.length ) {
		console.log(
			`\nTo list a pair as a known failure, add it to its theme's known_failures in tests/fixtures/contrast.json ("variation": "" is the theme's own styles):\n${ [
				// Highest ratio first, so each key ends on its lowest.
				...new Map(
					[ ...unlisted ]
						.sort( ( a, b ) => ( b.ratio ?? 0 ) - ( a.ratio ?? 0 ) )
						.map( ( r ) => [
							`${ r.theme }|${ knownKey( r ) }`,
							`${ r.theme }: ${ JSON.stringify( {
								variation: r.variation,
								pattern: r.pattern,
								style: r.style,
								kind: r.kind,
								nth: r.nth,
								state: r.state,
								ratio: r.ratio,
							} ) }`,
						] )
				).values(),
			].join( '\n' ) }\n`
		);
	}

	const measured = new Set(
		all.map( ( r ) => `${ r.theme }/${ r.variation }` )
	);
	const stale = [ ...known.values() ].filter(
		( k ) =>
			measured.has( `${ k.theme }/${ k.variation }` ) && ! k.seen.length
	);
	expect(
		'contrast: every known failure the fixture lists still fails',
		stale.length === 0,
		stale
			.map(
				( k ) =>
					`${ k.theme }/${ k.variation || 'own styles' } ${ k.pattern } ${ k.style } ${ k.kind } ${ k.nth } ${ k.state } no longer fails: remove it from the fixture`
			)
			.join( '\n' ) || `${ known.size } listed`
	);
	for ( const k of known.values() ) {
		const off = k.seen.find(
			( row ) => Math.abs( row.ratio - k.ratio ) > 0.05
		);
		if ( off ) {
			annotate(
				'warning',
				`${ k.theme }/${ k.variation || 'own styles' } ${ k.pattern } ${ k.style } ${ k.kind } ${ k.nth } (${ off.target }) ${ k.state } is listed at ${ k.ratio }:1 and measured ${ off.ratio }:1 at ${ off.width }px. Update the fixture.`,
				'Known failure ratio changed'
			);
		}
	}
}

const problem = nodeVersionProblem();
if ( problem ) {
	console.error( problem );
	process.exit( 1 );
}

const opts = parseFlags( process.argv.slice( 2 ) );
if ( opts.help ) {
	console.log( HELP );
	process.exit( 0 );
}
const unknown = Object.keys( opts ).filter(
	( key ) => key !== '_' && ! KNOWN.includes( key )
);
if ( unknown.length || opts._.length ) {
	console.error(
		`Unknown option ${ unknown.length ? `--${ unknown[ 0 ] }` : opts._[ 0 ] }. Run npm run design-check -- --help for the options.`
	);
	process.exit( 2 );
}
const asked = opts.checks
	? String( opts.checks )
			.split( ',' )
			.map( ( s ) => s.trim() )
	: [];
if (
	opts.checks === true ||
	asked.some( ( name ) => ! CHECKS.includes( name ) )
) {
	console.error(
		`--checks takes a list of ${ CHECKS.join( ', ' ) }, such as --checks contrast.`
	);
	process.exit( 2 );
}

setTimeout( () => {
	annotate(
		'error',
		`npm run design-check gave up after ${ TIMEOUT_MINUTES } minutes.`,
		'npm run design-check'
	);
	process.exit( 1 );
}, TIMEOUT_MINUTES * 60000 ).unref();

const code = await run( opts );
// The tables run to thousands of lines, and when stdout is a pipe, as in CI,
// process.exit() drops whatever is still queued: wait for both streams first.
await new Promise( ( done ) => process.stdout.write( '', done ) );
await new Promise( ( done ) => process.stderr.write( '', done ) );
process.exit( code );
