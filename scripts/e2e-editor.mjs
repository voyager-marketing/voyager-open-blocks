#!/usr/bin/env node
/**
 * The pack in a real WordPress and a real block editor.
 *
 *   npm run e2e:editor                   boot a fresh dev site, run every check, stop
 *   npm run e2e:editor -- --only a,c     just those checks
 *   npm run e2e:editor -- --help         every option
 *
 * Boots the dev site the way npm run dev does (WordPress Playground with this
 * checkout as the plugin, blueprints/dev.json), then drives headless Chrome
 * over the DevTools protocol (scripts/lib/chrome.mjs), logged in as admin.
 * Pages and Site Data are set over REST with an application password; the
 * notice's stored state is read and reset with PHP inside the site.
 *
 *   a. First-run notice: with every Site Data option empty, Plugins shows it
 *      with its link to Settings > Site Data; dismissing it keeps it hidden;
 *      with the dismissal cleared it shows again; saving one field records the
 *      started flag and keeps it hidden, even after that field is emptied.
 *   b. Settings > Site Data shows its intro, the phone's "Used by:" line
 *      names the CTA band, and its "Shown on" line links the demo pages.
 *      After a phone change over REST, it shows the last refresh.
 *   c. Editor, empty value: on a page holding the CTA band, with the phone
 *      empty, the selected "Click to call" button warns "Not set yet" and links
 *      to Settings > Site Data. With the phone set, the value shows instead.
 *   d. Placeholders: the logo marquee's images get the placeholder hint, and
 *      so do copies whose image URLs point at another host. A cover over the
 *      16:9 image slot placeholder and a media and text block beside the 4:3
 *      one get it too, and their section counts two images to replace.
 *   e. Paste: each pattern's registered markup, and the icon card from the
 *      demo gallery, through the editor's paste handler: the top-level blocks
 *      match and every block is valid.
 *   f. The front end of the CTA band page carries no editor-only class or hint
 *      text, for a visitor or for the logged-in admin.
 *   g. Theme files: a template part that is only a file in the active theme,
 *      with the phone bound in it, is counted by get-catalog's used_on once
 *      switch_theme rebuilds the theme index, and not before; removing the
 *      file and rebuilding again takes it back out. Runs last, because it
 *      fires switch_theme.
 *
 * Each check is a named assertion. A failed one prints what it saw, and in
 * GitHub Actions becomes an error annotation titled with its name. PHP fatal
 * errors in wp-content/debug.log and uncaught errors from the pack's editor
 * scripts fail the run too.
 *
 * Needs Chrome, Chromium or Edge (set CHROME_PATH to pick one) and the Node
 * in .nvmrc. The first run downloads WordPress; later runs reuse it.
 */
import { appendFileSync } from 'node:fs';

import {
	appPassword,
	boot,
	parseFlags,
	phpLog,
	reportPhpLog,
	runPhp,
	SETTINGS_PATH,
} from './dev.mjs';
import { findChrome, launch } from './lib/chrome.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { createRestClient } from './lib/rest.mjs';

const TIMEOUT_MINUTES = 20;
const WIDTH = 1280;
const HEIGHT = 900;

/** PHP namespace of inc/onboarding.php, for its constants and option list. */
const ONBOARDING = '\\VoyagerOpenBlocks\\Onboarding\\';
const NOTICE_ID = 'voyager-open-blocks-site-data-notice';
const HOURS = 'Monday to Friday, 9am to 5pm';
const PHONE = '(555) 010-0123';

/** The icon card's markup in the demo gallery. */
const ICON_CARD =
	'<!-- wp:voyager-open/icon {"icon":"phone","metadata":{"bindings":{"linkUrl":{"source":"voyager/site-data","args":{"key":"phone_link"}}}}} /-->';

/** Where the logo marquee's images point in copies made on other sites. */
const OTHER_HOST =
	'https://demo.example.com/wp-content/plugins/voyager-open-blocks/assets/';
const OTHER_FOLDER =
	'https://demo.example.com/blog/wp-content/plugins/voyager-open-blocks-main/assets/';

/** Strings only the editor may carry: hint classes, panel text and scripts. */
const EDITOR_ONLY = [
	'voyager-open-hint',
	'voyagerOpenBlocksHints',
	'voyagerOpenBlocksBindings',
	'assets/editor/hints',
	'bindings-editor.js',
	'Not set yet',
	'Connected data',
	'Placeholder content',
	'Still to fill in',
	'Replace it with your own logo',
	'Replace it with your own image',
];

const HELP = `npm run e2e:editor [-- options]

Boots a fresh dev site (npm run dev), then checks the first-run notice,
Settings > Site Data, the editor hints, pasting each pattern, the front end and
the theme template index, in headless Chrome. Exits 1 if any assertion fails.

  --only <list>    Only these checks, such as --only a,c. Checks: a notice,
                   b Settings > Site Data, c empty bound value, d placeholders,
                   e paste, f front end, g theme files.
  --port <n>       Port to serve on. Default 9400, or a free port if 9400 is taken.
  --wp <version>   WordPress version, such as 6.9. Default latest.
  --php <version>  PHP version, such as 8.1. Default 8.3.
  --debug          Print Playground's debug output and error stacks.
  --help           Show this help.

Set CHROME_PATH to use a particular Chrome, Chromium or Edge binary.`;

const KNOWN = [ 'only', 'port', 'wp', 'php', 'debug', 'help' ];

/** @type {Array<{name: string, ok: boolean, observed: string}>} */
const results = [];

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
 * @param {string}  name     What is asserted, in plain words.
 * @param {unknown} ok       Truthy when it holds.
 * @param {unknown} observed What was seen, printed on failure (and briefly on success).
 * @return {boolean} Whether it held.
 */
function expect( name, ok, observed = '' ) {
	const detail =
		typeof observed === 'string' ? observed : JSON.stringify( observed );
	results.push( { name, ok: !! ok, observed: detail } );
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
 * The top-level block names in serialized markup, read from the comment
 * delimiters alone, so the expectation does not depend on the editor.
 *
 * @param {string} markup Block markup.
 * @return {string[]} Names, core/ added where the delimiter leaves it out.
 */
function topLevelNames( markup ) {
	const token =
		/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+\{[\s\S]*?\})?\s+(\/)?-->/g;
	const names = [];
	let depth = 0;
	for ( const [ , closer, raw, selfClosing ] of markup.matchAll( token ) ) {
		if ( closer ) {
			depth--;
			continue;
		}
		if ( depth === 0 ) {
			names.push( raw.includes( '/' ) ? raw : `core/${ raw }` );
		}
		if ( ! selfClosing ) {
			depth++;
		}
	}
	return names;
}

/* In-page functions. Each runs in the browser through js(). */

/**
 * @param {string} id Notice element id.
 * @return {Object} What the screen shows of the first-run notice.
 */
function readNotice( id ) {
	const notice = document.getElementById( id );
	const link = notice ? notice.querySelector( 'a.button-primary' ) : null;
	return {
		path: window.location.pathname + window.location.search,
		loggedIn: document.body.classList.contains( 'wp-admin' ),
		present: !! notice,
		text: notice ? notice.innerText.replace( /\s+/g, ' ' ).trim() : '',
		linkText: link ? link.textContent.trim() : '',
		linkHref: link ? link.getAttribute( 'href' ) : '',
	};
}

/**
 * @param {string} id Notice element id.
 * @return {boolean} Whether the dismiss button was there to click.
 */
function clickDismiss( id ) {
	const button = document.querySelector( `#${ id } .notice-dismiss` );
	if ( ! button ) {
		return false;
	}
	button.click();
	return true;
}

/**
 * @param {Object<string, string>} values Field id => value.
 * @return {boolean} True once the submit is scheduled.
 */
function submitSettings( values ) {
	for ( const [ id, value ] of Object.entries( values ) ) {
		document.getElementById( id ).value = value;
	}
	setTimeout( () => document.getElementById( 'submit' ).click() );
	return true;
}

/**
 * @return {Object} Settings > Site Data, as far as these checks need it.
 */
function readSettings() {
	const wrap = document.querySelector( '#wpbody-content .wrap' );
	const used = document.getElementById( 'voyager_site_phone-used' );
	const shown = document.getElementById( 'voyager_site_phone-shown' );
	const refreshed = document.getElementById(
		'voyager-open-blocks-last-purge'
	);
	return {
		path: window.location.pathname + window.location.search,
		heading: wrap?.querySelector( 'h1' )?.textContent.trim() || '',
		paragraphs: wrap
			? [ ...wrap.querySelectorAll( 'form > p' ) ].map( ( p ) =>
					p.textContent.trim()
				)
			: [],
		phoneUsedBy: used ? used.textContent.trim() : null,
		lastRefresh: refreshed ? refreshed.textContent.trim() : null,
		phoneShownOn: shown
			? {
					text: shown.textContent.trim(),
					links: [ ...shown.querySelectorAll( 'a' ) ].map(
						( a ) => a.href
					),
				}
			: null,
	};
}

/**
 * @param {number} postId Post being opened.
 * @return {Object} How far the block editor has got; `ready` once it is usable.
 */
function editorState( postId ) {
	const wp = window.wp;
	const editor = wp?.data?.select( 'core/editor' );
	const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
	const canvas = frame ? frame.contentDocument : document;
	const state = {
		path: window.location.pathname + window.location.search,
		wpData: !! wp?.data,
		postId: editor ? editor.getCurrentPostId() : null,
		editorReady: editor?.__unstableIsEditorReady
			? editor.__unstableIsEditorReady()
			: !! editor,
		blocks: wp?.data
			? wp.data.select( 'core/block-editor' ).getBlockCount()
			: 0,
		iframed: !! frame,
		canvasBlocks: canvas
			? canvas.querySelectorAll( '.is-root-container [data-block]' )
					.length
			: 0,
		hintsLoaded: !! window.voyagerOpenBlocksHints,
		bindingsLoaded: !! window.voyagerOpenBlocksBindings,
	};
	state.ready =
		state.postId === postId &&
		state.editorReady &&
		state.blocks > 0 &&
		state.canvasBlocks > 0;
	return state;
}

/**
 * Close the welcome guide and open the block inspector.
 *
 * @return {boolean} True.
 */
function prepareEditor() {
	const data = window.wp.data;
	try {
		data.dispatch( 'core/preferences' ).set(
			'core/edit-post',
			'welcomeGuide',
			false
		);
	} catch {}
	const editPost = data.dispatch( 'core/edit-post' );
	if ( editPost && editPost.openGeneralSidebar ) {
		editPost.openGeneralSidebar( 'edit-post/block' );
	} else {
		data.dispatch( 'core/interface' ).enableComplementaryArea(
			'core',
			'edit-post/block'
		);
	}
	return true;
}

/**
 * @param {Object} query
 * @param {string} [query.name]         Block name.
 * @param {string} [query.metadataName] Block metadata.name.
 * @return {Array<Object>} Matching blocks, in document order.
 */
function findBlocks( query ) {
	const out = [];
	const walk = ( blocks ) => {
		for ( const block of blocks ) {
			const label = block.attributes?.metadata?.name || '';
			if (
				( ! query.name || block.name === query.name ) &&
				( ! query.metadataName || label === query.metadataName )
			) {
				out.push( {
					clientId: block.clientId,
					name: block.name,
					label,
					url: block.attributes?.url || '',
				} );
			}
			walk( block.innerBlocks || [] );
		}
	};
	walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
	return out;
}

/**
 * @param {string} clientId Block to select.
 * @return {boolean} True.
 */
function selectBlock( clientId ) {
	window.wp.data.dispatch( 'core/block-editor' ).selectBlock( clientId );
	return true;
}

/**
 * @param {string} title Panel title to wait for.
 * @return {boolean} Whether a hints panel with that title is on screen.
 */
function hasPanel( title ) {
	return [
		...document.querySelectorAll(
			'.voyager-open-hints .components-panel__body-title'
		),
	].some( ( el ) => el.textContent.includes( title ) );
}

/**
 * @param {string} clientId Block the hints are for.
 * @return {Object} The hint panels in the inspector, and the block in the canvas.
 */
function readHints( clientId ) {
	const panels = [
		...document.querySelectorAll( '.voyager-open-hints' ),
	].map( ( element ) => ( {
		title:
			element
				.querySelector( '.components-panel__body-title' )
				?.textContent.trim() || '',
		text: element.innerText.replace( /\s+/g, ' ' ).trim(),
		links: [ ...element.querySelectorAll( 'a' ) ].map( ( a ) => ( {
			text: a.textContent.replace( /\s+/g, ' ' ).trim(),
			href: a.getAttribute( 'href' ),
		} ) ),
	} ) );
	const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
	const canvas = frame ? frame.contentDocument : document;
	const block = canvas.querySelector( `[data-block="${ clientId }"]` );
	const inspector = document.querySelector( '.block-editor-block-inspector' );
	const data = window.wp.data;
	return {
		selected:
			data.select( 'core/block-editor' ).getSelectedBlockClientId() ===
			clientId,
		sidebar:
			data
				.select( 'core/interface' )
				?.getActiveComplementaryArea?.( 'core' ) ?? null,
		panels,
		canvasClass: block ? block.className : null,
		canvasText: block ? block.innerText.trim() : null,
		inspector: inspector
			? inspector.innerText.replace( /\s+/g, ' ' ).slice( 0, 600 )
			: null,
	};
}

/**
 * @param {string} selector CSS selector.
 * @return {number} How many canvas elements match.
 */
function countInCanvas( selector ) {
	const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
	const canvas = frame ? frame.contentDocument : document;
	return canvas.querySelectorAll( selector ).length;
}

/**
 * Run markup through the editor's own paste path.
 *
 * @param {string} html Block markup.
 * @return {Object} Top-level names, the block count, and every invalid block.
 */
function pasteBlocks( html ) {
	const pasted = window.wp.blocks.pasteHandler( {
		HTML: html,
		mode: 'BLOCKS',
	} );
	const list = Array.isArray( pasted ) ? pasted : [];
	const all = [];
	const walk = ( blocks, path ) =>
		blocks.forEach( ( block, index ) => {
			const at = path.concat( index );
			all.push( {
				name: block.name,
				path: at.join( '.' ),
				label: block.attributes?.metadata?.name || '',
				isValid: block.isValid,
				issues: ( block.validationIssues || [] ).map( ( issue ) =>
					( issue.args || [] )
						.map( ( arg ) =>
							typeof arg === 'string'
								? arg
								: JSON.stringify( arg )
						)
						.join( ' ' )
						.slice( 0, 400 )
				),
			} );
			walk( block.innerBlocks || [], at );
		} );
	walk( list, [] );
	return {
		returned: Array.isArray( pasted ) ? 'blocks' : typeof pasted,
		top: list.map( ( block ) => block.name ),
		total: all.length,
		invalid: all.filter( ( block ) => block.isValid !== true ),
	};
}

/* The run. */

let site;
let rest;
let admin;
let patterns = [];
let ctaPage;
const pageErrors = [];

/**
 * @param {string} body PHP statements; see runPhp() in dev.mjs.
 * @return {Promise<unknown>} What they return.
 */
function php( body ) {
	return runPhp( site, body );
}

/**
 * @return {Promise<{started: unknown, dismissed: unknown, options: Object}>} The notice's stored state.
 */
function noticeState() {
	return php( `$user = get_user_by( 'login', 'admin' );
$options = [];
foreach ( ${ ONBOARDING }option_names() as $name ) {
	$options[ $name ] = get_option( $name, null );
}
return [
	'started'   => get_option( ${ ONBOARDING }STARTED, null ),
	'dismissed' => get_user_option( ${ ONBOARDING }DISMISSED, $user->ID ),
	'options'   => $options,
];` );
}

/**
 * Empty every Site Data option and forget the started flag and the dismissal.
 */
async function resetNotice() {
	await php( `$user = get_user_by( 'login', 'admin' );
foreach ( ${ ONBOARDING }option_names() as $name ) {
	delete_option( $name );
}
delete_option( ${ ONBOARDING }STARTED );
delete_user_option( $user->ID, ${ ONBOARDING }DISMISSED );
return true;` );
}

async function clearDismissal() {
	await php( `$user = get_user_by( 'login', 'admin' );
delete_user_option( $user->ID, ${ ONBOARDING }DISMISSED );
return true;` );
}

/**
 * @param {Object<string, string>} values Option name => value.
 */
async function setSiteData( values ) {
	const response = await rest.api( '/wp/v2/settings', {
		method: 'POST',
		body: values,
	} );
	if ( response.status !== 200 ) {
		throw new Error(
			`POST /wp/v2/settings answered ${ response.status }: ${ clip( response.text, 300 ) }`
		);
	}
	for ( const [ name, value ] of Object.entries( values ) ) {
		if ( response.data?.[ name ] !== value ) {
			throw new Error(
				`POST /wp/v2/settings did not store ${ name }: it reads back ${ JSON.stringify( response.data?.[ name ] ) }`
			);
		}
	}
}

/**
 * @param {string} title
 * @param {string} content Block markup.
 * @return {Promise<{id: number, link: string}>} The published page.
 */
async function createPage( title, content ) {
	const response = await rest.api( '/wp/v2/pages', {
		method: 'POST',
		body: { title, status: 'publish', content },
	} );
	if ( response.status !== 201 ) {
		throw new Error(
			`POST /wp/v2/pages answered ${ response.status }: ${ clip( response.text, 300 ) }`
		);
	}
	return { id: response.data.id, link: response.data.link };
}

/**
 * @param {string} name Pattern name.
 * @return {{name: string, title: string, content: string}} The registered pattern.
 */
function pattern( name ) {
	const found = patterns.find( ( item ) => item.name === name );
	if ( ! found ) {
		throw new Error(
			`${ name } is not registered. Registered: ${ patterns.map( ( item ) => item.name ).join( ', ' ) || 'none' }`
		);
	}
	return found;
}

/**
 * The page holding the CTA band, created once.
 *
 * @return {Promise<{id: number, link: string}>} The page.
 */
async function ctaBandPage() {
	ctaPage ??= await createPage(
		'E2E editor: CTA band',
		pattern( 'voyager-open/cta-band' ).content
	);
	return ctaPage;
}

/**
 * @param {string} path Admin path.
 */
async function goAdmin( path ) {
	await admin.goto( `${ site.url }${ path }` );
}

/**
 * Open a post in the block editor and wait until its blocks are on the canvas.
 *
 * @param {number} postId
 */
async function openEditor( postId ) {
	await goAdmin( `/wp-admin/post.php?post=${ postId }&action=edit` );
	const wait = await admin.waitFor( `${ js( editorState, postId ) }.ready`, {
		timeout: 120000,
	} );
	if ( ! wait.ok ) {
		const state = await admin
			.evaluate( js( editorState, postId ) )
			.catch( ( error ) => error.message );
		throw new Error(
			`The block editor did not finish loading post ${ postId } in 120s: ${ JSON.stringify( state ) }`
		);
	}
	await admin.evaluate( js( prepareEditor ) );
}

/**
 * @param {Object} query See findBlocks().
 * @return {Promise<Array<Object>>} Matching blocks in the open editor.
 */
function editorBlocks( query ) {
	return admin.evaluate( js( findBlocks, query ) );
}

/**
 * @param {Object} query See findBlocks().
 * @return {Promise<Object>} The first matching block. Throws when none does.
 */
async function editorBlock( query ) {
	const [ first ] = await editorBlocks( query );
	if ( ! first ) {
		throw new Error(
			`No block in the editor matches ${ JSON.stringify( query ) }`
		);
	}
	return first;
}

/**
 * Select a block and read the hints once a panel with this title shows, or
 * after 15 seconds without one.
 *
 * @param {string} clientId
 * @param {string} title    Panel title to wait for.
 * @return {Promise<Object>} From readHints().
 */
async function selectAndRead( clientId, title ) {
	await admin.evaluate( js( selectBlock, clientId ) );
	await admin.waitFor( js( hasPanel, title ), { timeout: 15000 } );
	return admin.evaluate( js( readHints, clientId ) );
}

/**
 * @param {Object} hints From readHints().
 * @param {string} title Panel title.
 * @return {Object|undefined} That panel.
 */
function panel( hints, title ) {
	return hints.panels.find( ( item ) => item.title.includes( title ) );
}

/**
 * @param {Object} hints From readHints().
 * @return {string} What the inspector showed, for a failure message.
 */
function seen( hints ) {
	return JSON.stringify( {
		selected: hints.selected,
		sidebar: hints.sidebar,
		panels: hints.panels.map( ( item ) => ( {
			title: item.title,
			text: clip( item.text, 300 ),
			links: item.links,
		} ) ),
		canvasClass: hints.canvasClass,
		inspector: hints.panels.length ? undefined : hints.inspector,
	} );
}

async function checkNotice() {
	await resetNotice();
	const fresh = await noticeState();
	const empty = Object.values( fresh.options ).every(
		( value ) => value === null || value === ''
	);
	if ( ! empty || fresh.started !== null || fresh.dismissed !== false ) {
		throw new Error(
			`Could not reset Site Data to a first run: ${ JSON.stringify( fresh ) }`
		);
	}

	await goAdmin( '/wp-admin/plugins.php' );
	let notice = await admin.evaluate( js( readNotice, NOTICE_ID ) );
	expect(
		'a1 first run: Plugins shows the notice, its "Add your business details" link opening Settings > Site Data',
		notice.present &&
			notice.linkText === 'Add your business details' &&
			notice.linkHref.endsWith( SETTINGS_PATH.replace( /^.*\//, '' ) ),
		notice
	);

	const clicked = await admin.waitFor( js( clickDismiss, NOTICE_ID ), {
		timeout: 10000,
	} );
	let state = await noticeState();
	for (
		let tries = 0;
		clicked.ok && state.dismissed !== '1' && tries < 30;
		tries++
	) {
		await new Promise( ( done ) => setTimeout( done, 500 ) );
		state = await noticeState();
	}
	expect(
		'a2 the dismiss button saves the dismissal for the user',
		clicked.ok && state.dismissed === '1',
		{ dismissButton: clicked.ok, dismissed: state.dismissed }
	);

	await goAdmin( '/wp-admin/plugins.php' );
	notice = await admin.evaluate( js( readNotice, NOTICE_ID ) );
	expect(
		'a3 a dismissed notice stays hidden after reload',
		notice.loggedIn && ! notice.present,
		notice
	);

	await clearDismissal();
	await goAdmin( '/wp-admin/plugins.php' );
	notice = await admin.evaluate( js( readNotice, NOTICE_ID ) );
	expect(
		'a4 with the dismissal cleared and Site Data still empty, the notice shows again',
		notice.present,
		notice
	);

	await goAdmin( SETTINGS_PATH );
	await admin.navigate( js( submitSettings, { voyager_site_hours: HOURS } ) );
	state = await noticeState();
	expect(
		'a5 saving one Site Data field records the started flag',
		state.started === '1' &&
			state.options.voyager_site_hours === HOURS &&
			state.dismissed === false,
		state
	);

	await goAdmin( '/wp-admin/plugins.php' );
	notice = await admin.evaluate( js( readNotice, NOTICE_ID ) );
	expect(
		'a6 with one field set and no dismissal, the notice stays hidden',
		notice.loggedIn && ! notice.present,
		notice
	);

	await goAdmin( SETTINGS_PATH );
	await admin.navigate( js( submitSettings, { voyager_site_hours: '' } ) );
	state = await noticeState();
	await goAdmin( '/wp-admin/plugins.php' );
	notice = await admin.evaluate( js( readNotice, NOTICE_ID ) );
	expect(
		'a7 with that field emptied again, the started flag keeps the notice hidden',
		notice.loggedIn &&
			! notice.present &&
			state.started === '1' &&
			Object.values( state.options ).every(
				( value ) => value === null || value === ''
			),
		{ notice, state }
	);
}

async function checkSettings() {
	// A phone change refreshes the demo pages that show it, so the screen has
	// a last refresh to show. The phone is put back afterwards.
	const phoneBefore = ( await rest.api( '/wp/v2/settings' ) ).data
		?.voyager_site_phone;
	await setSiteData( { voyager_site_phone: '(555) 555-0164' } );
	await goAdmin( SETTINGS_PATH );
	const screen = await admin.evaluate( js( readSettings ) );
	if ( typeof phoneBefore === 'string' ) {
		await setSiteData( { voyager_site_phone: phoneBefore } );
	}
	expect(
		'b1 Settings > Site Data shows its intro',
		screen.heading === 'Site Data' &&
			screen.paragraphs.some( ( text ) =>
				text.startsWith(
					'Site Data keeps your business details in one place.'
				)
			),
		screen
	);
	expect(
		'b2 the phone field\'s "Used by:" line names the CTA band',
		typeof screen.phoneUsedBy === 'string' &&
			screen.phoneUsedBy.startsWith( 'Used by:' ) &&
			screen.phoneUsedBy.includes( 'CTA band' ),
		screen.phoneUsedBy
	);
	expect(
		'b3 the phone field\'s "Shown on" line links the published pages that show it',
		/^Shown on \d+ pages?:/.test( screen.phoneShownOn?.text || '' ) &&
			screen.phoneShownOn.links.length > 0 &&
			screen.phoneShownOn.links.every( ( href ) =>
				href.startsWith( 'http' )
			),
		screen.phoneShownOn
	);
	expect(
		'b4 after a phone change, Settings > Site Data shows the last refresh',
		/^Last refreshed (\d+ pages?|every page), .+ ago\./.test(
			screen.lastRefresh || ''
		),
		screen.lastRefresh
	);
}

async function checkEmptyValue() {
	await setSiteData( { voyager_site_phone: '' } );
	const page = await ctaBandPage();

	await openEditor( page.id );
	let button = await editorBlock( { metadataName: 'Click to call' } );
	let hints = await selectAndRead( button.clientId, 'Connected data' );
	let connected = panel( hints, 'Connected data' );
	expect(
		'c1 phone empty: the selected "Click to call" button warns "Not set yet" in the inspector',
		connected?.text.includes( 'Not set yet' ),
		seen( hints )
	);
	expect(
		'c2 phone empty: the inspector links to Settings > Site Data',
		connected?.links.some(
			( link ) =>
				( link.href || '' ).includes(
					'options-general.php?page=voyager-site-data'
				) && link.text.includes( 'Settings > Site Data' )
		),
		connected ? connected.links : seen( hints )
	);
	expect(
		'c3 phone empty: the canvas marks the button as not set',
		( hints.canvasClass || '' )
			.split( ' ' )
			.includes( 'voyager-open-hint-unset' ),
		hints.canvasClass
	);

	const section = await editorBlock( { metadataName: 'CTA band' } );
	const summary = await selectAndRead( section.clientId, 'Still to fill in' );
	expect(
		'c4 phone empty: the selected CTA band lists the phone under "Still to fill in"',
		panel( summary, 'Still to fill in' )?.text.includes(
			'Phone number: not set yet'
		),
		seen( summary )
	);

	await setSiteData( { voyager_site_phone: PHONE } );
	await openEditor( page.id );
	button = await editorBlock( { metadataName: 'Click to call' } );
	hints = await selectAndRead( button.clientId, 'Connected data' );
	connected = panel( hints, 'Connected data' );
	expect(
		'c5 phone set: the inspector shows it as the current value',
		connected?.text.includes( 'Current value' ) &&
			connected.text.includes( PHONE ),
		seen( hints )
	);
	expect(
		'c6 phone set: the "Not set yet" warning is gone',
		connected && ! connected.text.includes( 'Not set yet' ),
		seen( hints )
	);
	expect(
		'c7 phone set: the canvas button shows the phone and loses the not-set mark',
		( hints.canvasText || '' ).includes( PHONE ) &&
			! ( hints.canvasClass || '' )
				.split( ' ' )
				.includes( 'voyager-open-hint-unset' ),
		{ canvasText: hints.canvasText, canvasClass: hints.canvasClass }
	);
}

async function checkPlaceholders() {
	const marquee = pattern( 'voyager-open/logo-marquee' );
	const images = ( marquee.content.match( /<!-- wp:image\b/g ) || [] ).length;
	const local = /src="[^"]*\/assets\/(placeholders\/[^"/]+)"/g;
	const copy = ( base ) => {
		const content = marquee.content.replace( local, `src="${ base }$1"` );
		const count = content.split( `src="${ base }placeholders/` ).length - 1;
		if ( ! images || count !== images ) {
			throw new Error(
				`Expected the logo marquee's ${ images } images to point at assets/placeholders/, but ${ count } did.`
			);
		}
		return content;
	};

	// Two copies besides this site's own: the same path on another host, and
	// a site in a subfolder with the plugin in a renamed folder (a GitHub zip
	// unpacks to voyager-open-blocks-main), where only the end of the path is
	// the same.
	const copies = [
		[
			'this site',
			await createPage( 'E2E editor: logo marquee', marquee.content ),
		],
		[
			'another host',
			await createPage(
				'E2E editor: logo marquee copied',
				copy( OTHER_HOST )
			),
		],
		[
			'another host, subfolder and plugin folder',
			await createPage(
				'E2E editor: logo marquee copied to a subfolder',
				copy( OTHER_FOLDER )
			),
		],
	];

	for ( const [ where, page ] of copies ) {
		await openEditor( page.id );
		const logos = await editorBlocks( { name: 'core/image' } );
		const hints = await selectAndRead(
			logos[ 0 ]?.clientId || '',
			'Placeholder content'
		);
		expect(
			`d placeholder logo on ${ where }: the inspector says to replace it`,
			panel( hints, 'Placeholder content' )?.text.includes(
				'Placeholder. Replace it with your own logo.'
			),
			{ url: logos[ 0 ]?.url, hints: seen( hints ) }
		);

		const selector = '.voyager-open-hint-placeholder';
		await admin.waitFor(
			`${ js( countInCanvas, selector ) } === ${ images }`,
			{ timeout: 10000 }
		);
		const marked = await admin.evaluate( js( countInCanvas, selector ) );
		expect(
			`d placeholder logo on ${ where }: the canvas marks all ${ images } logos`,
			marked === images,
			{ marked, images, urls: logos.map( ( logo ) => logo.url ) }
		);

		const section = await editorBlock( { metadataName: 'Logo marquee' } );
		const summary = await selectAndRead(
			section.clientId,
			'Still to fill in'
		);
		expect(
			`d placeholder logo on ${ where }: the selected section counts the logos to replace`,
			panel( summary, 'Still to fill in' )?.text.includes(
				`${ images } placeholder logos to replace`
			),
			seen( summary )
		);
	}

	await checkImageSlots(
		/src="([^"]*\/assets\/)placeholders\//.exec( marquee.content )?.[ 1 ] ||
			''
	);
}

/**
 * In the editor: a section holding the two image slots, a cover over the
 * 16:9 placeholder and a media and text block beside the 4:3 one, as the
 * editor's own blocks serialize them.
 *
 * @param {string} assets The plugin's assets URL, ending in a slash.
 * @return {string} Block markup.
 */
function imageSlotsMarkup( assets ) {
	const { createBlock, serialize } = window.wp.blocks;
	const focalPoint = { x: 0.5, y: 0.5 };
	return serialize( [
		createBlock(
			'core/group',
			{
				tagName: 'section',
				align: 'full',
				layout: { type: 'constrained' },
				metadata: { name: 'Image slots' },
			},
			[
				createBlock(
					'core/cover',
					{
						url: `${ assets }placeholders/image-16x9.svg`,
						dimRatio: 60,
						overlayColor: 'contrast',
						isUserOverlayColor: true,
						focalPoint,
						metadata: { name: 'Background' },
					},
					[
						createBlock( 'core/paragraph', {
							content: 'Text over the background',
						} ),
					]
				),
				createBlock(
					'core/media-text',
					{
						mediaUrl: `${ assets }placeholders/image-4x3.svg`,
						mediaType: 'image',
						mediaAlt: '',
						imageFill: true,
						focalPoint,
						metadata: { name: 'Photo' },
					},
					[
						createBlock( 'core/paragraph', {
							content: 'Text beside the photo',
						} ),
					]
				),
			]
		),
	] );
}

/**
 * The image slots: a cover's background and a media and text block's image
 * holding one of the pack's placeholders get the placeholder hint, as a
 * placeholder logo in an image block does.
 *
 * @param {string} assets The plugin's assets URL, from the logo marquee.
 */
async function checkImageSlots( assets ) {
	if ( ! assets ) {
		throw new Error(
			'The logo marquee holds no image under the plugin assets URL.'
		);
	}
	const markup = await admin.evaluate( js( imageSlotsMarkup, assets ) );
	const page = await createPage( 'E2E editor: image slots', markup );
	await openEditor( page.id );

	for ( const [ name, label ] of [
		[ 'core/cover', 'cover' ],
		[ 'core/media-text', 'media and text block' ],
	] ) {
		const block = await editorBlock( { name } );
		const hints = await selectAndRead(
			block.clientId,
			'Placeholder content'
		);
		expect(
			`d placeholder image in a ${ label }: the inspector says to replace it and set its focal point`,
			panel( hints, 'Placeholder content' )?.text.includes(
				'Placeholder. Replace it with your own image, then set its focal point on the subject.'
			),
			seen( hints )
		);
	}

	const selector = '.voyager-open-hint-placeholder';
	await admin.waitFor( `${ js( countInCanvas, selector ) } === 2`, {
		timeout: 10000,
	} );
	const marked = await admin.evaluate(
		js(
			( css ) =>
				[
					...(
						document.querySelector( 'iframe[name="editor-canvas"]' )
							?.contentDocument || document
					).querySelectorAll( css ),
				].map( ( el ) => el.getAttribute( 'data-type' ) ),
			selector
		)
	);
	expect(
		'd placeholder image: the canvas marks the cover and the media and text block',
		marked.length === 2 &&
			marked.includes( 'core/cover' ) &&
			marked.includes( 'core/media-text' ),
		marked
	);

	const section = await editorBlock( { metadataName: 'Image slots' } );
	const summary = await selectAndRead( section.clientId, 'Still to fill in' );
	expect(
		'd placeholder image: the selected section counts the images to replace',
		panel( summary, 'Still to fill in' )?.text.includes(
			'2 placeholder images to replace'
		) &&
			! panel( summary, 'Still to fill in' )?.text.includes(
				'placeholder logo'
			),
		seen( summary )
	);
}

async function checkPaste() {
	const page = await ctaBandPage();
	await openEditor( page.id );

	const ours = patterns.filter( ( item ) =>
		item.name.startsWith( 'voyager-open/' )
	);
	expect(
		'e the pack registers nine patterns',
		ours.length === 9,
		ours.map( ( item ) => item.name ).join( ', ' )
	);

	const cases = [
		...ours.map( ( item ) => [ `pattern ${ item.title }`, item.content ] ),
		[ 'icon card from the demo gallery', ICON_CARD ],
	];
	for ( const [ label, markup ] of cases ) {
		const expected = topLevelNames( markup );
		const pasted = await admin.evaluate( js( pasteBlocks, markup ) );
		expect(
			`e paste ${ label }: the top-level blocks match`,
			expected.length > 0 &&
				pasted.top.join( ' ' ) === expected.join( ' ' ),
			{ expected, pasted: pasted.top, returned: pasted.returned }
		);
		expect(
			`e paste ${ label }: every block is valid`,
			pasted.total > 0 && pasted.invalid.length === 0,
			pasted.invalid.length ? pasted.invalid : `${ pasted.total } blocks`
		);
	}
}

async function checkFrontEnd() {
	const page = await ctaBandPage();

	const visitor = await rest.visit( page.link );
	const visitorFound = EDITOR_ONLY.filter( ( text ) =>
		visitor.text.includes( text )
	);
	expect(
		'f1 front end as a visitor: the CTA band renders with no editor-only class or hint text',
		visitor.status === 200 &&
			visitor.text.includes( 'Ready to get started?' ) &&
			visitorFound.length === 0,
		{
			url: visitor.url,
			status: visitor.status,
			heading: visitor.text.includes( 'Ready to get started?' ),
			found: visitorFound,
		}
	);

	await admin.goto( page.link );
	const html = await admin.evaluate( 'document.documentElement.outerHTML' );
	const adminFound = EDITOR_ONLY.filter( ( text ) => html.includes( text ) );
	expect(
		'f2 front end as the logged-in admin: no editor-only class or hint text',
		html.includes( 'id="wpadminbar"' ) &&
			html.includes( 'Ready to get started?' ) &&
			adminFound.length === 0,
		{
			adminBar: html.includes( 'id="wpadminbar"' ),
			heading: html.includes( 'Ready to get started?' ),
			found: adminFound,
		}
	);
}

/**
 * @return {Promise<{posts: number, templates: number, template_parts: number}|null>} get-catalog's used_on for the phone.
 */
async function phoneUsedOn() {
	const response = await rest.api(
		'/wp-abilities/v1/abilities/voyager-open-blocks/get-catalog/run',
		{ query: { 'input[post_type]': 'page' } }
	);
	return response.data?.used_on?.[ 'voyager/site-data:phone' ] ?? null;
}

/**
 * Fire switch_theme for the active theme, as switching to it does.
 *
 * @return {Promise<string[]>} The template IDs that show the phone afterwards.
 */
function switchThemeHook() {
	return php( `do_action( 'switch_theme', get_stylesheet(), wp_get_theme(), wp_get_theme() );
return \\VoyagerOpenBlocks\\Usage\\templates_using( 'voyager/site-data', 'phone' );` );
}

async function checkThemeFiles() {
	const before = await phoneUsedOn();
	const file = await php( `$slug = 'voyager-open-e2e-phone';
$file = get_stylesheet_directory() . '/parts/' . $slug . '.html';
wp_mkdir_p( dirname( $file ) );
$markup = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"phone"}}}}} -->' . "\\n" . '<p>(555) 010-0123</p>' . "\\n" . '<!-- /wp:paragraph -->';
return [
	'written'     => false !== file_put_contents( $file, $markup ),
	'block_theme' => wp_is_block_theme(),
	'id'          => get_stylesheet() . '//' . $slug,
	'path'        => $file,
];` );
	const parts = ( now ) =>
		now && before ? now.template_parts - before.template_parts : null;

	const unbuilt = await phoneUsedOn();
	expect(
		'g1 a bound template part dropped into the theme is not counted before the theme index is rebuilt',
		file.written &&
			file.block_theme &&
			Number.isInteger( before?.template_parts ) &&
			parts( unbuilt ) === 0,
		{ file, before, after: unbuilt }
	);

	const using = await switchThemeHook();
	const rebuilt = await phoneUsedOn();
	expect(
		'g2 after switch_theme, get-catalog counts the file-only template part, and templates_using() names it',
		Array.isArray( using ) &&
			using.includes( file.id ) &&
			parts( rebuilt ) === 1 &&
			rebuilt.templates === before.templates,
		{ id: file.id, templates_using: using, before, after: rebuilt }
	);

	await php( `return unlink( ${ JSON.stringify( file.path ) } );` );
	const usingAfter = await switchThemeHook();
	const removed = await phoneUsedOn();
	expect(
		'g3 with the file removed, the next switch_theme rebuild drops it again',
		Array.isArray( usingAfter ) &&
			! usingAfter.includes( file.id ) &&
			parts( removed ) === 0,
		{ templates_using: usingAfter, before, after: removed }
	);
}

const CHECKS = [
	[ 'a', 'First-run notice', checkNotice ],
	[ 'b', 'Settings > Site Data', checkSettings ],
	[ 'c', 'Editor hints for an empty bound value', checkEmptyValue ],
	[ 'd', 'Placeholder hints', checkPlaceholders ],
	[ 'e', 'Paste each pattern', checkPaste ],
	[ 'f', 'Front end', checkFrontEnd ],
	[ 'g', 'Theme template files', checkThemeFiles ],
];

/**
 * Log in as admin. Playground logs a new browser in on its first request; the
 * login form is the fallback.
 */
async function logIn() {
	await goAdmin( '/wp-admin/' );
	if (
		( await admin.evaluate( 'location.pathname' ) ).endsWith(
			'/wp-login.php'
		)
	) {
		await admin.navigate(
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
	const where = await admin.evaluate(
		"( { path: location.pathname, admin: document.body.classList.contains( 'wp-admin' ) && !! document.getElementById( 'wpadminbar' ) } )"
	);
	if ( ! where.admin ) {
		throw new Error(
			`Could not log in as admin: landed on ${ where.path }`
		);
	}
}

/**
 * Collect uncaught errors and console errors from every page admin opens.
 */
async function listen() {
	await admin.call( 'Runtime.enable' );
	await admin.call( 'Log.enable' );
	admin.on( 'Runtime.exceptionThrown', ( { exceptionDetails: details } ) =>
		pageErrors.push(
			`uncaught: ${ details.exception?.description || details.text } ${ details.url || '' }`
		)
	);
	admin.on( 'Runtime.consoleAPICalled', ( { type, args } ) => {
		if ( type === 'error' ) {
			pageErrors.push(
				`console.error: ${ args
					.map( ( arg ) => arg.value ?? arg.description ?? '' )
					.join( ' ' ) }`
			);
		}
	} );
	admin.on( 'Log.entryAdded', ( { entry } ) => {
		// The copied logos point at a host that does not exist, on purpose.
		if (
			entry.level === 'error' &&
			! ( entry.url || '' ).startsWith( 'https://demo.example.com/' )
		) {
			pageErrors.push(
				`${ entry.source }: ${ entry.text } ${ entry.url || '' }`
			);
		}
	} );
}

/**
 * @param {Object} opts Parsed flags.
 * @return {Promise<number>} Exit code.
 */
async function run( opts ) {
	const only = opts.only
		? new Set(
				String( opts.only )
					.split( ',' )
					.map( ( s ) => s.trim() )
			)
		: null;
	const checks = CHECKS.filter( ( [ id ] ) => ! only || only.has( id ) );
	if ( ! checks.length ) {
		console.error( '--only matched none of a, b, c, d, e, f.' );
		return 2;
	}
	const chrome = findChrome();
	if ( ! chrome ) {
		annotate(
			'error',
			process.env.CHROME_PATH
				? `CHROME_PATH points to ${ process.env.CHROME_PATH }, which does not exist.`
				: 'No Chrome, Chromium or Edge found. Install one, or set CHROME_PATH to its executable.',
			'npm run e2e:editor'
		);
		return 1;
	}

	let browser;
	try {
		group( 'Boot the dev site' );
		try {
			site = await boot( opts );
		} finally {
			endGroup();
		}
		console.log( `Site: ${ site.url }` );

		rest = createRestClient( site.url, {
			user: 'admin',
			password: await appPassword( site ),
		} );
		patterns = await php( `$out = [];
foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $item ) {
	if ( str_starts_with( $item['name'], 'voyager-open/' ) ) {
		$out[] = [ 'name' => $item['name'], 'title' => $item['title'], 'content' => $item['content'] ];
	}
}
return $out;` );

		browser = await launch( chrome );
		admin = await browser.newPage( { width: WIDTH, height: HEIGHT } );
		await listen();
		await logIn();

		for ( const [ id, title, check ] of checks ) {
			group( `${ id }. ${ title }` );
			try {
				await check();
			} catch ( error ) {
				expect(
					`${ id } ${ title }: ran to the end`,
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

		const ours = pageErrors.filter( ( text ) =>
			/voyager-open|VoyagerOpen/.test( text )
		);
		expect(
			"no uncaught or console errors from the pack's editor scripts",
			ours.length === 0,
			ours
		);
		const others = pageErrors.filter( ( text ) => ! ours.includes( text ) );
		if ( others.length ) {
			annotate(
				'notice',
				clip( [ ...new Set( others ) ].join( '\n' ), 3000 ),
				'Other browser errors (not from the pack)'
			);
		}

		const fatal = reportPhpLog( await phpLog( site ) );
		expect(
			'no PHP fatal errors in debug.log',
			fatal === 0,
			`${ fatal } fatal`
		);
	} catch ( error ) {
		expect( 'npm run e2e:editor set up and ran', false, error.message );
		if ( opts.debug ) {
			console.error( error.stack );
		}
	} finally {
		await browser?.close();
		await site?.stop?.();
	}

	const failed = results.filter( ( result ) => ! result.ok );
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		appendFileSync(
			process.env.GITHUB_STEP_SUMMARY,
			[
				'### npm run e2e:editor',
				'',
				'| Result | Assertion | Observed |',
				'|---|---|---|',
				...results.map(
					( result ) =>
						`| ${ result.ok ? 'ok' : 'FAIL' } | ${ result.name.replace( /\|/g, '\\|' ) } | ${ clip(
							result.observed,
							300
						)
							.replace( /\|/g, '\\|' )
							.replace( /\n/g, ' ' ) } |`
				),
				'',
			].join( '\n' )
		);
	}
	console.log(
		`\n${ results.length - failed.length } of ${ results.length } assertions passed.`
	);
	if ( failed.length ) {
		console.log(
			`Failed:\n${ failed.map( ( r ) => `  ${ r.name }` ).join( '\n' ) }`
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
		`Unknown option ${ unknown.length ? `--${ unknown[ 0 ] }` : opts._[ 0 ] }. Run npm run e2e:editor -- --help for the options.`
	);
	process.exit( 2 );
}
setTimeout( () => {
	annotate(
		'error',
		`npm run e2e:editor gave up after ${ TIMEOUT_MINUTES } minutes.`,
		'npm run e2e:editor'
	);
	process.exit( 1 );
}, TIMEOUT_MINUTES * 60000 ).unref();
process.exit( await run( opts ) );
