#!/usr/bin/env node
/**
 * Section style contrast, measured on real renders.
 *
 *   npm run contrast                    boot a fresh dev site, measure, stop
 *   npm run contrast -- --plant         plant the low-contrast role map in the main run too, to watch it fail
 *   npm run contrast -- --themes twentytwentyfive
 *   npm run contrast -- --help          every option
 *
 * Boots the dev site the way npm run dev does (WordPress Playground with this
 * checkout as the plugin), then for each theme in tests/fixtures/contrast.json:
 *
 *   1. switches to it and builds a page with the CTA band once per section
 *      style, the style put on through SectionStyles\apply() (what configure
 *      calls), with one link in its body text;
 *   2. checks it is measuring the right thing: get-catalog's role map is the
 *      one the fixture names, each section carries the instance class WordPress
 *      adds when it renders a style's style_data, and Inverted is painted in the
 *      theme's own text colour with its own background colour as text;
 *   3. loads the page in headless Chrome at 1280px as a visitor and measures
 *      every target against its background, at rest and hovered where the
 *      fixture asks, from computed styles: the background is found by walking
 *      up through transparent ancestors and compositing each translucent
 *      layer, and a background image on that path counts as a failure;
 *   4. plants the fixture's low-contrast role map through the
 *      voyager_open_blocks_style_roles filter and measures again. Every target
 *      at rest in the planted style must fail, or the gate cannot fail and the
 *      run fails;
 *   5. where the fixture asks, opens the page in the block editor as admin and
 *      checks the Styles panel offers Panel, Inverted and Accent and the
 *      canvas paints Inverted as the front end does.
 *
 * The run fails when any pair measures under the fixture's floor (4.5:1),
 * when fewer pairs were measured than the fixture expects, when a positive
 * control or the planted control fails, or on a PHP fatal error.
 *
 * Needs Chrome, Chromium or Edge (set CHROME_PATH to pick one) and the Node
 * in .nvmrc. The first run downloads WordPress; later runs reuse it.
 */
import { appendFileSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

import { boot, parseFlags, phpLog, reportPhpLog, runPhp } from './dev.mjs';
import { findChrome, launch } from './lib/chrome.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { repo } from './lib/site.mjs';

const FIXTURE = join( repo, 'tests', 'fixtures', 'contrast.json' );
const PLANT_OPTION = 'voyager_open_contrast_plant';
const TIMEOUT_MINUTES = 25;

/** Loads the planted role map from an option, so a run can switch it on and off. */
const PLANT_PLUGIN = `<?php
/**
 * Written by scripts/contrast-check.mjs into a throwaway dev site.
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

const HELP = `npm run contrast [-- options]

Boots a fresh dev site (npm run dev), renders the CTA band in every section
style on each theme in tests/fixtures/contrast.json, and measures text
contrast in headless Chrome. Exits 1 if any pair is under the floor, or if
the planted low-contrast role map does not fail.

  --plant          Plant the low-contrast role map in the main run as well,
                   so the run must fail. How to watch the gate go red.
  --themes <list>  Only these themes, such as --themes twentytwentyfive.
                   The pair count is then not checked.
  --port <n>       Port to serve on. Default 9400, or a free port if 9400 is taken.
  --wp <version>   WordPress version, such as 6.9. Default latest.
  --php <version>  PHP version, such as 8.1. Default 8.3.
  --debug          Print Playground's debug output and error stacks.
  --help           Show this help.

Set CHROME_PATH to use a particular Chrome, Chromium or Edge binary.`;

const KNOWN = [ 'plant', 'themes', 'port', 'wp', 'php', 'debug', 'help' ];

/** @type {Array<{name: string, ok: boolean, observed: string}>} */
const checks = [];

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
 * In the page: an element's text colour against what is behind it.
 *
 * Every colour is turned into sRGB by Chrome itself (a 1x1 canvas), so
 * color(), color-mix() results, oklch() and named colours all read the same
 * way. The background is the first opaque one walking up from the element,
 * with every translucent layer on the way composited over it; nothing opaque
 * all the way up is the white canvas. A background image on that path makes
 * the pair unmeasurable, which counts as a failure.
 *
 * @param {string} sectionSelector The section the target sits in.
 * @param {string} targetSelector  The target, inside the section.
 * @return {{count: number, text: string, background: string, ratio: number|null, note: string}} Measurement.
 */
function measureInPage( sectionSelector, targetSelector ) {
	const sections = document.querySelectorAll( sectionSelector );
	const found =
		sections.length === 1
			? sections[ 0 ].querySelectorAll( targetSelector )
			: [];
	if ( sections.length !== 1 || found.length !== 1 ) {
		return {
			count: found.length,
			text: '?',
			background: '?',
			ratio: null,
			note: `expected 1 section and 1 target, found ${ sections.length } and ${ found.length }`,
		};
	}
	const el = found[ 0 ];

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

	const layers = [];
	let note = '';
	for (
		let node = el;
		node && node.nodeType === 1;
		node = node.parentElement
	) {
		const style = window.getComputedStyle( node );
		if ( style.backgroundImage !== 'none' ) {
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
	let background = { r: 255, g: 255, b: 255, a: 1 };
	for ( let i = layers.length - 1; i >= 0; i-- ) {
		background =
			layers[ i ].a >= 1 ? layers[ i ] : over( layers[ i ], background );
	}

	const raw = window.getComputedStyle( el ).color;
	let text = rgba( raw );
	if ( ! text ) {
		return {
			count: 1,
			text: raw,
			background: hex( background ),
			ratio: null,
			note: `unreadable color "${ raw }"`,
		};
	}
	text = text.a < 1 ? over( text, background ) : text;

	const [ hi, lo ] = [ luminance( text ), luminance( background ) ].sort(
		( a, b ) => b - a
	);
	const ratio = Math.round( ( ( hi + 0.05 ) / ( lo + 0.05 ) ) * 100 ) / 100;

	return {
		count: 1,
		text: hex( text ),
		background: hex( background ),
		ratio: note ? null : ratio,
		note,
	};
}

/**
 * In the page: what each section carries, and the theme's own colours.
 *
 * @param {string[]} styles Style names.
 * @return {Object} Per style: its classes and painted colours; and the body's colours.
 */
function paintInPage( styles ) {
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
	const out = {
		body: {
			text: hex( window.getComputedStyle( document.body ).color ),
			background: hex(
				window.getComputedStyle( document.body ).backgroundColor
			),
		},
		sections: {},
	};
	for ( const style of styles ) {
		const section = document.querySelector(
			`section.is-style-voyager-open-${ style }`
		);
		out.sections[ style ] = section
			? {
					className: section.className,
					text: hex( window.getComputedStyle( section ).color ),
					background: hex(
						window.getComputedStyle( section ).backgroundColor
					),
				}
			: null;
	}
	return out;
}

/**
 * In the page: an element's centre in the viewport, after scrolling it there.
 *
 * @param {string} sectionSelector
 * @param {string} targetSelector
 * @return {{x: number, y: number}|null} Centre, or null when it is not found.
 */
function centreInPage( sectionSelector, targetSelector ) {
	const el = document
		.querySelector( sectionSelector )
		?.querySelector( targetSelector );
	if ( ! el ) {
		return null;
	}
	el.scrollIntoView( { block: 'center', inline: 'center' } );
	const rect = el.getBoundingClientRect();
	return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
}

/**
 * In the page: whether the target is under the pointer.
 *
 * @param {string} sectionSelector
 * @param {string} targetSelector
 * @return {boolean} Whether it matches :hover.
 */
function hoveredInPage( sectionSelector, targetSelector ) {
	return !! document
		.querySelector( sectionSelector )
		?.querySelector( targetSelector )
		?.matches( ':hover' );
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
 * Measure every target of every style on a loaded page.
 *
 * @param {Object} tab     The page.
 * @param {Object} fixture tests/fixtures/contrast.json.
 * @param {string} theme   Theme slug, for the rows.
 * @return {Promise<Array<Object>>} One row per style, target and state.
 */
async function measureAll( tab, fixture, theme ) {
	const rows = [];
	for ( const style of fixture.styles ) {
		const scope = `section.is-style-voyager-open-${ style }`;
		for ( const target of fixture.targets ) {
			for ( const state of target.states ) {
				await pointer( tab, 0, 0 );
				let result;
				if ( state === 'hover' ) {
					const at = await tab.evaluate(
						js( centreInPage, scope, target.selector )
					);
					if ( at ) {
						await pointer( tab, at.x, at.y );
					}
					const hovered = at
						? await tab.evaluate(
								js( hoveredInPage, scope, target.selector )
							)
						: false;
					result = hovered
						? await tab.evaluate(
								js( measureInPage, scope, target.selector )
							)
						: {
								count: at ? 1 : 0,
								text: '?',
								background: '?',
								ratio: null,
								note: 'the pointer did not produce :hover',
							};
				} else {
					result = await tab.evaluate(
						js( measureInPage, scope, target.selector )
					);
				}
				rows.push( {
					theme,
					style,
					target: target.name,
					state,
					...result,
				} );
			}
		}
	}
	await pointer( tab, 0, 0 );
	return rows;
}

/**
 * @param {Object} row   A measurement.
 * @param {number} floor
 * @return {boolean} Whether it fails: unmeasured, or under the floor.
 */
const fails = ( row, floor ) => row.ratio === null || row.ratio < floor;

/**
 * One aligned line per row.
 *
 * @param {Array<Object>} rows
 * @param {number}        floor
 * @return {string} Table.
 */
function table( rows, floor ) {
	return rows
		.map( ( r ) =>
			[
				fails( r, floor ) ? 'FAIL' : 'ok  ',
				r.theme.padEnd( 18 ),
				r.style.padEnd( 9 ),
				r.target.padEnd( 15 ),
				r.state.padEnd( 6 ),
				`text ${ r.text }`,
				`on ${ r.background }`,
				r.ratio === null
					? `- ${ r.note }`
					: `${ r.ratio.toFixed( 2 ) }:1`,
			].join( '  ' )
		)
		.join( '\n' );
}

/**
 * The rows as a Markdown table on the job summary, when there is one.
 *
 * @param {string}        title
 * @param {Array<Object>} rows
 * @param {number}        floor
 */
function summarize( title, rows, floor ) {
	if ( ! process.env.GITHUB_STEP_SUMMARY ) {
		return;
	}
	appendFileSync(
		process.env.GITHUB_STEP_SUMMARY,
		[
			`### ${ title }`,
			'',
			'| | Theme | Style | Target | State | Text | Background | Ratio |',
			'|---|---|---|---|---|---|---|---|',
			...rows.map(
				( r ) =>
					`| ${ fails( r, floor ) ? 'FAIL' : 'ok' } | ${ r.theme } | ${ r.style } | ${ r.target } | ${ r.state } | \`${ r.text }\` | \`${ r.background }\` | ${
						r.ratio === null
							? r.note
							: `${ r.ratio.toFixed( 2 ) }:1`
					} |`
			),
			'',
		].join( '\n' ) + '\n'
	);
}

/**
 * Switch theme, build the fixture page, and read what get-catalog reports.
 *
 * @param {Object} site    From boot().
 * @param {Object} fixture tests/fixtures/contrast.json.
 * @param {string} theme   Theme slug.
 * @return {Promise<Object>} {id, link, stylesheet, catalog} or {error}.
 */
function buildPage( site, fixture, theme ) {
	const args = phpString(
		JSON.stringify( {
			theme,
			pattern: fixture.pattern,
			styles: fixture.styles,
			link: fixture.link,
		} )
	);
	return runPhp(
		site,
		`$args = json_decode( ${ args }, true );
if ( ! wp_get_theme( $args['theme'] )->exists() ) {
	return [ 'error' => 'Theme ' . $args['theme'] . ' is not installed.' ];
}
switch_theme( $args['theme'] );
$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $args['pattern'] );
if ( ! $pattern ) {
	return [ 'error' => $args['pattern'] . ' is not registered.' ];
}
$link     = '<a href="' . esc_url( $args['link']['href'] ) . '">' . esc_html( $args['link']['text'] ) . '</a>';
$sections = [];
foreach ( $args['styles'] as $style ) {
	foreach ( parse_blocks( $pattern['content'] ) as $block ) {
		if ( 'core/group' !== $block['blockName'] ) {
			continue;
		}
		$styled = \\VoyagerOpenBlocks\\SectionStyles\\apply( $block, $style );
		if ( is_wp_error( $styled ) ) {
			return [ 'error' => $style . ': ' . $styled->get_error_message() ];
		}
		$count  = 0;
		$markup = str_replace( $args['link']['text'], $link, serialize_block( $styled ), $count );
		if ( 1 !== $count ) {
			return [ 'error' => 'The link text appears ' . $count . ' times in the ' . $style . ' section, not once.' ];
		}
		$sections[] = $markup;
	}
}
kses_remove_filters();
$id = wp_insert_post(
	wp_slash(
		[
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Contrast: ' . $args['theme'],
			'post_content' => implode( "\\n\\n", $sections ),
		]
	),
	true
);
if ( is_wp_error( $id ) ) {
	return [ 'error' => $id->get_error_message() ];
}
return [
	'id'         => $id,
	'link'       => get_permalink( $id ),
	'stylesheet' => get_stylesheet(),
	'catalog'    => \\VoyagerOpenBlocks\\SectionStyles\\catalog(),
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
 * Load a URL as a visitor, after checking it answers 200.
 *
 * @param {Object} tab
 * @param {string} url
 */
async function visit( tab, url ) {
	const response = await fetch( url );
	await response.arrayBuffer();
	if ( response.status !== 200 ) {
		throw new Error(
			`${ url } answered ${ response.status } (at ${ response.url })`
		);
	}
	await tab.goto( url );
	await tab.evaluate( 'document.fonts.ready.then( () => true )' );
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
 * In the editor: select the first section and open the Styles tab.
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
 * In the editor canvas: the Inverted section's colours.
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
 * Open the page in the editor and check the Styles panel and the canvas.
 *
 * @param {Object} admin  A logged-in page.
 * @param {string} base   Site URL.
 * @param {string} theme  Theme slug.
 * @param {number} postId Fixture page.
 * @param {Object} front  The front end's Inverted colours.
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
 * @param {Object} opts Parsed flags.
 * @return {Promise<number>} Exit code.
 */
async function run( opts ) {
	const fixture = JSON.parse( readFileSync( FIXTURE, 'utf8' ) );
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
	const chrome = findChrome();
	if ( ! chrome ) {
		annotate(
			'error',
			process.env.CHROME_PATH
				? `CHROME_PATH points to ${ process.env.CHROME_PATH }, which does not exist.`
				: 'No Chrome, Chromium or Edge found. Install one, or set CHROME_PATH to its executable.',
			'npm run contrast'
		);
		return 1;
	}

	const perTheme =
		fixture.styles.length *
		fixture.targets.reduce( ( n, t ) => n + t.states.length, 0 );
	const main = [];
	let site;
	let browser;
	try {
		group( 'Boot the dev site' );
		try {
			site = await boot( { ...opts, login: false } );
		} finally {
			endGroup();
		}
		console.log( `Site: ${ site.url }` );

		const planted = await runPhp(
			site,
			`$dir = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p( $dir );
return false !== file_put_contents( $dir . '/voyager-open-contrast-plant.php', ${ phpString( PLANT_PLUGIN ) } );`
		);
		if ( ! planted ) {
			throw new Error( 'Could not write the plant mu-plugin.' );
		}

		browser = await launch( chrome );
		const visitor = await browser.newPage( {
			width: fixture.width,
			height: fixture.height,
		} );
		let admin = null;

		for ( const entry of themes ) {
			const { theme } = entry;
			group( `${ theme }` );
			try {
				await plant( site, null );
				const page = await buildPage( site, fixture, theme );
				if (
					! expect(
						`${ theme }: the fixture page is built`,
						! page.error && page.id > 0,
						page.error || page.link
					)
				) {
					continue;
				}
				expect(
					`${ theme }: it is the active theme`,
					page.stylesheet === theme,
					page.stylesheet
				);
				expect(
					`${ theme }: get-catalog lists the three styles`,
					[ 'panel', 'inverted', 'accent' ].every( ( style ) =>
						page.catalog.styles.some(
							( s ) =>
								s.style === style &&
								s.class === `is-style-voyager-open-${ style }`
						)
					),
					page.catalog.styles
				);
				expect(
					`${ theme }: the colours come from the ${ entry.roles } role map`,
					page.catalog.roles === entry.roles,
					page.catalog.roles
				);

				if ( opts.plant ) {
					await plant( site, entry.plant.map );
				}
				await visit( visitor, page.link );
				const paint = await visitor.evaluate(
					js( paintInPage, fixture.styles )
				);
				for ( const style of fixture.styles ) {
					const section = paint.sections[ style ];
					expect(
						`${ theme }: the ${ style } section renders with the style's instance class`,
						section &&
							new RegExp(
								`\\bis-style-voyager-open-${ style }--\\d+\\b`
							).test( section.className ),
						section?.className || 'no section'
					);
				}
				const inverted = paint.sections.inverted;
				expect(
					`${ theme }: Inverted is painted in the theme's own text colour, with its background colour as text`,
					inverted &&
						inverted.background === paint.body.text &&
						inverted.text === paint.body.background,
					{ inverted, body: paint.body }
				);

				const rows = await measureAll( visitor, fixture, theme );
				main.push( ...rows );
				expect(
					`${ theme }: ${ rows.length } pairs measured`,
					rows.length === perTheme,
					`${ rows.length } of ${ perTheme }`
				);

				// The control: plant the low-contrast map and require it to fail.
				const control = await plant( site, entry.plant.map );
				await visit( visitor, page.link );
				const plantedRows = (
					await measureAll( visitor, fixture, theme )
				).filter( ( r ) => r.style === entry.plant.style );
				await plant( site, null );
				console.log(
					`\nPlanted ${ JSON.stringify( entry.plant.map ) } on ${ theme }:\n${ table(
						plantedRows,
						fixture.floor
					) }\n`
				);
				summarize(
					`Planted control on ${ theme } (must fail): ${ JSON.stringify( entry.plant.map ) }`,
					plantedRows,
					fixture.floor
				);
				expect(
					`${ theme }: the planted role map is in use for the control`,
					control.roles === 'filter',
					control.roles
				);
				// At rest every pair is painted from the role map. A hover can be the
				// theme's own (Twenty Twenty-Four fills an outline button grey on
				// hover, whatever the section), so only rest pairs must fail.
				const plantedRest = plantedRows.filter(
					( r ) => r.state === 'rest'
				);
				expect(
					`${ theme }: every pair at rest in the planted ${ entry.plant.style } style fails, so the gate can fail`,
					plantedRest.length === fixture.targets.length &&
						plantedRest.every( ( r ) => fails( r, fixture.floor ) ),
					plantedRest
						.filter( ( r ) => ! fails( r, fixture.floor ) )
						.map( ( r ) => `${ r.target } ${ r.ratio }:1` )
						.join( ', ' ) || `${ plantedRest.length } rows fail`
				);

				if ( entry.editor ) {
					if ( ! admin ) {
						admin = await browser.newPage( {
							width: 1440,
							height: 900,
						} );
						await logIn( admin, site.url );
					}
					await checkEditor(
						admin,
						site.url,
						theme,
						page.id,
						inverted
					);
				}
			} catch ( error ) {
				expect( `${ theme }: ran to the end`, false, error.message );
				if ( opts.debug ) {
					console.error( error.stack );
				}
			} finally {
				endGroup();
			}
		}

		const fatal = reportPhpLog( await phpLog( site ) );
		expect(
			'no PHP fatal errors in debug.log',
			fatal === 0,
			`${ fatal } fatal`
		);
	} catch ( error ) {
		expect( 'npm run contrast set up and ran', false, error.message );
		if ( opts.debug ) {
			console.error( error.stack );
		}
	} finally {
		await browser?.close();
		await site?.stop?.();
	}

	const heading = `Section style contrast: ${ main.length } pairs, floor ${ fixture.floor }:1${
		opts.plant ? ' (PLANTED)' : ''
	}`;
	console.log( `\n${ heading }\n${ table( main, fixture.floor ) }\n` );
	summarize( heading, main, fixture.floor );

	const under = main.filter( ( r ) => fails( r, fixture.floor ) );
	expect(
		`every pair measures ${ fixture.floor }:1 or more`,
		main.length > 0 && under.length === 0,
		under.length
			? `\n${ table( under, fixture.floor ) }`
			: `${ main.length } pairs`
	);
	if ( ! only ) {
		expect(
			`at least ${ fixture.min_pairs } pairs measured`,
			main.length >= fixture.min_pairs &&
				main.length === perTheme * fixture.themes.length,
			`${ main.length } of ${ perTheme * fixture.themes.length }`
		);
	}

	const failed = checks.filter( ( c ) => ! c.ok );
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		appendFileSync(
			process.env.GITHUB_STEP_SUMMARY,
			[
				'### npm run contrast',
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
				'',
			].join( '\n' ) + '\n'
		);
	}
	console.log(
		`${ checks.length - failed.length } of ${ checks.length } assertions passed.`
	);
	if ( failed.length ) {
		console.log(
			`Failed:\n${ failed.map( ( c ) => `  ${ c.name }` ).join( '\n' ) }`
		);
	}
	return failed.length ? 1 : 0;
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
		`Unknown option ${ unknown.length ? `--${ unknown[ 0 ] }` : opts._[ 0 ] }. Run npm run contrast -- --help for the options.`
	);
	process.exit( 2 );
}

setTimeout( () => {
	annotate(
		'error',
		`npm run contrast gave up after ${ TIMEOUT_MINUTES } minutes.`,
		'npm run contrast'
	);
	process.exit( 1 );
}, TIMEOUT_MINUTES * 60000 ).unref();

process.exit( await run( opts ) );
