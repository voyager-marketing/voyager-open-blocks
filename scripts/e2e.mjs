#!/usr/bin/env node
/**
 * End to end, the way an agent would do it: over REST, through the abilities.
 *
 *   VOYAGER_OPEN_APP_PASSWORD=... npm run e2e -- [--url http://127.0.0.1:9402] [--user admin] [--context site.context.json]
 *
 * Against a running site (npm run playground -- serve), it:
 *   1. lists the pack's abilities and reads the catalog,
 *   2. creates a page, finds it with find-posts by title words and by slug, and
 *      inserts the CTA band (bound to voyager/site-data), sets its section style
 *      with configure (Accent, rendered on the front end, then Inverted, then
 *      Plain) after checking get-catalog lists the styles, then inserts it again
 *      with copy for its heading and body, checks copy for its bound call button
 *      is refused with the page unchanged, and inserts the proof strip with
 *      three facts (fourth item omitted) and with six (two repeated),
 *   3. inserts an icon, configures it and binds its link, and inserts an icon
 *      inside the second CTA band's row, beside its buttons,
 *   4. checks bad input is refused (unknown icon, invented args, unbindable attribute,
 *      configure on a bound attribute,
 *      copy for an unknown label, a block that is not text, or a reference, a parent
 *      that does not allow the icon, holds no blocks or is locked, a repeat that
 *      cannot apply, post_type on a page block),
 *   5. runs `block-runner validate --strict` on every markup the abilities returned
 *      and on the saved page, and the validate ability on the same markup, on the
 *      saved page and on broken markup, including one case only block-runner catches,
 *      its stale_fallback warning on bench/runs/loop/willow-vet/after-edit.html,
 *      and its placeholder and missing_alt warnings: the logo marquee's six
 *      logos (then published and fetched: its Marquee group holds the logo
 *      row and a copy of it made at render, aria-hidden and inert with no
 *      id, while its REST content.rendered holds the row once, and validate
 *      still warns six times), the contact section's form
 *      slot and the image slot of each of
 *      the hero's two layouts (listed by get-catalog with their copy labels;
 *      one placeholder warning, and no other), each
 *      placed with insert on a draft of its own, the services section's two
 *      layouts (listed with their copy labels; a fourth card through repeat,
 *      its icon then set with configure, one card dropped through omit, and
 *      six rows in the list, each on a draft of its own and each passing both
 *      validators with no warning), the reviews section's two layouts
 *      (listed with their copy labels; the grid as shipped, published and
 *      fetched, shows three star rows, each five stars hidden from screen
 *      readers and one name, "Rated 5 out of 5"; a fourth card through
 *      repeat with a four-star review through omit, and the single quote,
 *      each filled by copy and passing both validators with no warning),
 *      the FAQ's two layouts (listed with their copy labels, each question
 *      a details block; the list with copy for "Question 1" and its answer,
 *      a fourth question through repeat and the second dropped through omit,
 *      the new summaries read back from the saved page; the split layout
 *      filled by copy; omit of an answer without its question refused as
 *      required; each passing both validators with no warning),
 *      the copy rules (get-catalog's section, layout and copy_rules for the
 *      hero and the CTA band; insert refusing a 61-character hero heading as
 *      too_long, a review quote given as a plain string as a claim, omit of
 *      the hero's heading as required, and a too-long heading, copy for the
 *      bound call button and omit of the body in one call, with and without
 *      dry_run, the page unchanged each time; then the quote saving as text
 *      the site owner gave),
 *      a cover over the 16:9
 *      placeholder at the plugin's URL (one placeholder warning, never
 *      missing_alt) and over the site's own image, and an image with and
 *      without alt text,
 *   6. fetches the page and checks the bound values rendered,
 *  6b. on pages of its own, trial runs and undo: insert, configure and bind
 *      return revision_id, undo with it restores the content byte for byte and
 *      undoing the undo puts the change back, configure and bind with dry_run
 *      change nothing, a dry run is refused for a bound slot as a save is, and
 *      undo refuses another post's revision and a contributor on a page they
 *      cannot edit, while a contributor can undo on their own draft, and undo
 *      refuses to reverse a person's later edit unless forced,
 *   7. the icon prompt end to end: creates a header template part with a call
 *      button, finds it with find-posts, puts a phone icon beside the button and
 *      binds its link to Site Data, then checks it resolves and validates,
 *   8. binds post meta in a template with post_type, and checks it is refused without,
 *   9. checks find-posts as a contributor: their own draft is listed, an
 *      administrator's private post is not, and pages are refused,
 *  10. binds the phone on page A, in a synced pattern on page B and in a footer
 *      template part, and checks get-catalog's used_on counts follow: 2 more
 *      pages and 1 more template part, 1 page once page A loses the block, none
 *      once the synced pattern does, and a page holding a registered pattern,
 *  11. deletes what steps 7 to 10 created. The page from step 2 is kept, as before.
 *  12. the purge, through the recorder mu-plugin blueprints/dev.json installs
 *      (tests/fixtures/purge-recorder.php): changing the phone records one
 *      voyager_open_blocks_purge call, whose URLs gain exactly pages A and B
 *      (the phone, and the phone through a synced pattern) and never page C
 *      (the email), with the front page; the same phone again records none;
 *      a template part with the phone sets purge_all; and bindable post meta
 *      changed on page D refreshes page D alone. Changing the Site Title
 *      records one purge with page T (the company) and the front page, and
 *      not page C or page G (the tagline), and changing the Tagline does the
 *      same for page G. Then it deletes what it created and puts the phone,
 *      the Site Title and the Tagline back.
 *  13. the facts block, a list field on a page: insert puts voyager-open/facts
 *      in a draft, bind ties its items to the services list, the services are
 *      set to three lines and the page is published, and the page shows one
 *      facts ul with those three li, in order. The page then counts in
 *      used_on for the services, emptying them records a purge with the page,
 *      and the page shows the block's own items. Then it deletes the page and
 *      puts the services back.
 *  14. the contact details block: insert puts voyager-open/contact-details in
 *      a draft, bind ties its seven attributes to the seven Site Data keys,
 *      the fields are set to the values in tests/fixtures/site-data-keys.json
 *      and the page is published. Inside the block the page shows one
 *      address holding the street, the city line, one tel: link reading the
 *      phone and one mailto: link reading the email, and the hours after it.
 *      The page counts in used_on for the phone, changing the phone records
 *      a purge with the page, and with the email emptied no email line and
 *      no mailto: link render while the rest still does. validate warns
 *      stale_fallback on the block bound to the phone whose own phone holds
 *      a phone number. Then it deletes the page and puts the fields back.
 *
 * The application password comes from the environment, never argv. Create one with
 * `wp user application-password create admin e2e --porcelain`.
 */
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { flags, repo } from './lib/site.mjs';

const opts = flags( process.argv.slice( 2 ) );
const base = ( opts.url || 'http://127.0.0.1:9402' ).replace( /\/$/, '' );
const user = opts.user || 'admin';
const password = process.env.VOYAGER_OPEN_APP_PASSWORD;
const blockRunner = join(
	repo,
	'node_modules',
	'block-runner',
	'dist',
	'cli.js'
);
const scratch = mkdtempSync( join( tmpdir(), 'voyager-open-e2e-' ) );
let failures = 0;

if ( ! password ) {
	console.error(
		'Set VOYAGER_OPEN_APP_PASSWORD to an application password for --user.'
	);
	process.exit( 2 );
}

const auth =
	'Basic ' + Buffer.from( `${ user }:${ password }` ).toString( 'base64' );
const cookies = new Map();

/**
 * One HTTP request on a fresh connection (agent: false). fetch() reuses
 * keep-alive sockets that Playground's server has already closed, and a
 * write that dies on a stale socket cannot be safely retried.
 *
 * @param {URL}                    url          Request URL.
 * @param {Object}                 init         Request options.
 * @param {string}                 init.method  HTTP method.
 * @param {Object<string, string>} init.headers Request headers.
 * @param {string|undefined}       init.body    Request body.
 * @return {Promise<{status: number, headers: Object, text: string}>} Response.
 */
function send( url, { method = 'GET', headers = {}, body } = {} ) {
	return new Promise( ( resolve, reject ) => {
		const req = ( url.protocol === 'https:' ? https : http ).request(
			url,
			{ method, headers, agent: false },
			( res ) => {
				let text = '';
				res.setEncoding( 'utf8' );
				res.on( 'data', ( chunk ) => ( text += chunk ) );
				res.on( 'end', () =>
					resolve( {
						status: res.statusCode,
						headers: res.headers,
						text,
					} )
				);
			}
		);
		req.on( 'error', reject );
		if ( body ) {
			req.write( body );
		}
		req.end();
	} );
}

/**
 * Follow redirects, surviving `playground serve --login`.
 *
 * That server answers the first cookie-less request with a redirect setting
 * WordPress auth cookies plus a `playground_auto_login_already_happened`
 * marker. Only the marker is kept: with a WordPress auth cookie and no nonce,
 * REST drops to a logged-out user and ignores the application password.
 *
 * @param {URL|string} target
 * @param {Object}     init
 */
async function request( target, init = {} ) {
	let url = new URL( target );
	for ( let hop = 0; hop < 5; hop++ ) {
		const headers = { ...( init.headers || {} ) };
		if ( cookies.size ) {
			headers.Cookie = [ ...cookies ]
				.map( ( [ k, v ] ) => `${ k }=${ v }` )
				.join( '; ' );
		}
		const response = await send( url, { ...init, headers } );
		for ( const line of response.headers[ 'set-cookie' ] || [] ) {
			const [ pair ] = line.split( ';' );
			const eq = pair.indexOf( '=' );
			const name = pair.slice( 0, eq ).trim();
			if ( name.startsWith( 'playground_' ) ) {
				cookies.set( name, pair.slice( eq + 1 ).trim() );
			}
		}
		if ( response.status < 300 || response.status >= 400 ) {
			return response;
		}
		url = new URL( response.headers.location, url );
	}
	throw new Error( `Too many redirects for ${ url }` );
}

async function api( path, { method = 'GET', body, query, as = auth } = {} ) {
	const url = new URL( `${ base }/wp-json${ path }` );
	for ( const [ key, value ] of Object.entries( query || {} ) ) {
		url.searchParams.set( key, String( value ) );
	}
	const response = await request( url, {
		method,
		headers: { Authorization: as, 'Content-Type': 'application/json' },
		body: body ? JSON.stringify( body ) : undefined,
	} );
	let data = {};
	try {
		data = JSON.parse( response.text );
	} catch {}
	return { status: response.status, data };
}

/**
 * Run an ability with the HTTP method its annotations require.
 *
 * @param {string}  name     Ability name.
 * @param {Object}  input    Input.
 * @param {boolean} readonly GET for read-only abilities, POST otherwise.
 * @param {string}  as       Authorization header, the --user's by default.
 */
function run( name, input, readonly = false, as = auth ) {
	if ( readonly ) {
		const query = {};
		for ( const [ key, value ] of Object.entries( input ) ) {
			query[ `input[${ key }]` ] = value;
		}
		return api( `/wp-abilities/v1/abilities/${ name }/run`, { query, as } );
	}
	return api( `/wp-abilities/v1/abilities/${ name }/run`, {
		method: 'POST',
		body: { input },
		as,
	} );
}

function step( label, ok, detail = '' ) {
	console.log(
		`${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ` (${ detail })` : '' }`
	);
	if ( ! ok ) {
		failures++;
	}
	return ok;
}

function validate( label, markup ) {
	const file = join( scratch, `${ label.replace( /\W+/g, '-' ) }.html` );
	writeFileSync( file, markup );
	const args = [ blockRunner, 'validate', file, '--strict', '--json' ];
	if ( opts.context ) {
		args.push( '--context', opts.context );
	}
	const result = spawnSync( process.execPath, args, { encoding: 'utf8' } );
	let report = {};
	try {
		report = JSON.parse( result.stdout );
	} catch {}
	const summary = report.summary
		? `${ report.summary.valid }/${ report.summary.blocks } valid, ${ report.summary.warnings } warnings`
		: `exit ${ result.status }`;
	// Zero blocks means the ability returned nothing, which is a failure, not a pass.
	return step(
		`block-runner validate --strict: ${ label }`,
		result.status === 0 && report.ok === true && report.summary?.blocks > 0,
		summary
	);
}

const ns = 'voyager-open-blocks';

// 1. Discover.
const list = await api( '/wp-abilities/v1/abilities', {
	query: { category: ns, per_page: 50 },
} );
const names = ( Array.isArray( list.data ) ? list.data : [] )
	.map( ( a ) => a.name )
	.sort();
step(
	'abilities listed',
	names.length === 8 &&
		names.includes( `${ ns }/validate` ) &&
		names.includes( `${ ns }/find-posts` ) &&
		names.includes( `${ ns }/undo` ),
	names.join( ', ' )
);

const catalog = await run( `${ ns }/get-catalog`, { post_type: 'page' }, true );
const siteData = ( catalog.data.sources || [] ).find(
	( s ) => s.name === 'voyager/site-data'
);
const phoneLink = ( siteData?.fields || [] ).find(
	( f ) => f.args?.key === 'phone_link'
);
step(
	'get-catalog lists the CTA band and site-data fields',
	catalog.status === 200 &&
		( catalog.data.patterns || [] ).some(
			( p ) => p.name === 'voyager-open/cta-band'
		) &&
		!! phoneLink,
	`${ ( catalog.data.patterns || [] ).length } patterns, ${ ( siteData?.fields || [] ).length } site-data fields`
);

// 2. A fresh page with the CTA band. The stamp keeps this run's posts apart
// from earlier runs' on a site that lasts.
const stamp = String( Date.now() );
const page = await api( '/wp/v2/pages', {
	method: 'POST',
	body: { title: `E2E: CTA band ${ stamp }`, status: 'publish', content: '' },
} );
const postId = page.data.id;
step( 'page created', page.status === 201, `id ${ postId }` );

// find-posts: the route-1 way to get a post_id from "my ... page".
const ids = ( found ) => ( found.data.posts || [] ).map( ( p ) => p.id );
const byTitle = await run( `${ ns }/find-posts`, { search: stamp }, true );
step(
	'find-posts finds the page by words in its title',
	byTitle.status === 200 &&
		ids( byTitle ).includes( postId ) &&
		byTitle.data.posts.every( ( p ) => p.post_type === 'page' ),
	byTitle.data.code || `ids ${ ids( byTitle ).join( ', ' ) }`
);

const bySlug = await run(
	`${ ns }/find-posts`,
	{ slug: page.data.slug },
	true
);
step(
	'find-posts finds the page by its exact slug',
	bySlug.status === 200 &&
		bySlug.data.posts?.length === 1 &&
		bySlug.data.posts[ 0 ].id === postId &&
		bySlug.data.posts[ 0 ].title === `E2E: CTA band ${ stamp }`,
	bySlug.data.code || JSON.stringify( bySlug.data.posts?.[ 0 ] ?? null )
);

const frontPage = await run( `${ ns }/find-posts`, { front_page: true }, true );
step(
	'find-posts answers for the home page',
	frontPage.status === 200 &&
		( frontPage.data.posts || [] ).length <= 1 &&
		( frontPage.data.posts || [] ).every( ( p ) => p.front_page === true ),
	frontPage.data.note ||
		frontPage.data.code ||
		JSON.stringify( frontPage.data.posts?.[ 0 ] ?? null )
);

const badType = await run(
	`${ ns }/find-posts`,
	{ post_type: 'not_a_post_type' },
	true
);
step(
	'find-posts refuses a post type that is not edited in the block editor',
	badType.status === 400 &&
		badType.data.code === 'voyager_open_blocks_invalid_post_type',
	badType.data.code
);

const cta = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/cta-band',
} );
const callButton = ( cta.data.blocks || [] ).find(
	( b ) => b.label === 'Click to call'
);
step(
	'insert cta-band',
	cta.status === 200 &&
		cta.data.saved === true &&
		cta.data.validation?.round_trip === true,
	`index ${ cta.data.index }, ${ ( cta.data.blocks || [] ).length } blocks, call button at ${ callButton?.path }`
);
step(
	'CTA band arrives bound to site data',
	callButton?.bindings?.url?.source === 'voyager/site-data' &&
		callButton?.bindings?.url?.args?.key === 'phone_link'
);
validate( 'cta-band insert', cta.data.markup || '' );

// Section styles: get-catalog lists them, configure puts one on the CTA band's
// group and takes it off again, and the front end renders it.
const catalogStyles = catalog.data.styles?.styles || [];
step(
	'get-catalog lists the Panel, Inverted and Accent section styles',
	[ 'panel', 'inverted', 'accent' ].every( ( style ) =>
		catalogStyles.some(
			( s ) =>
				s.style === style &&
				s.class === `is-style-voyager-open-${ style }`
		)
	) &&
		catalog.data.styles?.block === 'core/group' &&
		typeof catalog.data.styles?.roles === 'string',
	`${ catalogStyles.map( ( s ) => s.class ).join( ', ' ) }; roles ${ catalog.data.styles?.roles }`
);
const bandPath = ( cta.data.blocks || [] ).find(
	( b ) => b.label === 'CTA band'
)?.path;
const styledAs = ( markup ) =>
	( ( markup || '' ).match( /is-style-voyager-open-[a-z]+/g ) || [] ).join(
		' '
	);
for ( const style of [ 'accent', 'inverted', 'plain' ] ) {
	const styled = await run( `${ ns }/configure`, {
		post_id: postId,
		path: bandPath,
		style,
	} );
	const expected =
		style === 'plain'
			? ''
			: `is-style-voyager-open-${ style } is-style-voyager-open-${ style }`;
	step(
		`configure sets the CTA band's section style to ${ style }, in its className and on its wrapper`,
		styled.status === 200 &&
			styled.data.block === 'core/group' &&
			styledAs( styled.data.markup ) === expected,
		styled.data.code || styledAs( styled.data.markup ) || 'no style'
	);
	validate( `cta-band styled ${ style }`, styled.data.markup || '' );
	if ( style === 'accent' ) {
		const front = ( await request( page.data.link ) ).text;
		step(
			'the front end renders the Accent style from its style data',
			/class="[^"]*\bis-style-voyager-open-accent--\d+\b/.test( front ),
			[
				...new Set(
					front.match(
						/is-style-voyager-open-(?:panel|inverted|accent)[a-z0-9-]*/g
					) || []
				),
			].join( ' ' ) || 'no section style class'
		);
	}
}
for ( const [ label, input, code ] of [
	[
		'configure refuses a section style on a block that is not a group',
		{ path: callButton?.path, style: 'panel' },
		'voyager_open_blocks_invalid_style',
	],
	[
		'configure refuses style and attributes together',
		{ path: bandPath, style: 'panel', attributes: {} },
		'voyager_open_blocks_invalid_style',
	],
	[
		'configure refuses a section style that does not exist',
		{ path: bandPath, style: 'neon' },
	],
] ) {
	const refused = await run( `${ ns }/configure`, {
		post_id: postId,
		...input,
	} );
	step(
		label,
		refused.status === 400 && ( ! code || refused.data.code === code ),
		refused.data.code
	);
}
const styleTrial = await run( `${ ns }/configure`, {
	post_id: postId,
	path: bandPath,
	style: 'panel',
	dry_run: true,
} );
const afterTrial = await run(
	`${ ns }/get-post-blocks`,
	{ post_id: postId },
	true
);
step(
	'configure with style and dry_run returns the styled markup and saves nothing',
	styleTrial.status === 200 &&
		styleTrial.data.saved === false &&
		styleTrial.data.revision_id === null &&
		styledAs( styleTrial.data.markup ) ===
			'is-style-voyager-open-panel is-style-voyager-open-panel' &&
		! JSON.stringify( afterTrial.data ).includes( 'voyager-open-panel' ),
	styleTrial.data.code ||
		`saved ${ styleTrial.data.saved }, ${ styledAs( styleTrial.data.markup ) }`
);

// The CTA band again, with copy. The heading and body carry characters that
// must arrive escaped. The call button's text is bound, so it takes no copy
// and keeps its label.
const withCopy = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/cta-band',
	copy: {
		Heading: 'Leaks fixed today & done right',
		Body: "Call before noon and we're there by 5pm, <b>guaranteed</b>.",
	},
} );
const copied = withCopy.data.markup || '';
const copiedCall = ( withCopy.data.blocks || [] ).find(
	( b ) => b.label === 'Click to call'
);
step(
	'insert cta-band with copy',
	withCopy.status === 200 &&
		withCopy.data.saved === true &&
		withCopy.data.validation?.round_trip === true &&
		copied.includes( '>Leaks fixed today &amp; done right</h2>' ) &&
		copied.includes(
			">Call before noon and we're there by 5pm, &lt;b&gt;guaranteed&lt;/b&gt;.</p>"
		) &&
		copied.includes( '>Call us</a>' ) &&
		! copied.includes( 'Ready to get started?' ) &&
		copiedCall?.bindings?.text?.args?.key === 'phone' &&
		copiedCall?.bindings?.url?.args?.key === 'phone_link',
	withCopy.data.code ||
		`index ${ withCopy.data.index }, call button still bound at ${ copiedCall?.path }`
);
validate( 'cta-band with copy', copied );

// The page's saved content, to show a refused write changed nothing.
const rawContent = async () =>
	(
		await api( `/wp/v2/pages/${ postId }`, {
			query: { context: 'edit' },
		} )
	).data.content?.raw ?? null;

// Copy for a bound slot: the call button's text comes from Site Data, so copy
// for it would be saved only as a fallback that outlives the next change to the
// phone number. The whole call is refused before anything is saved, and the
// heading's copy, which alone would have been fine, is not saved either.
// Inserted at position 1, the slot is named by its path in the page: the call
// button's path inside the first band, under top-level index 1.
const boundCallPath = [
	'1',
	...( callButton?.path || '' ).split( '/' ).slice( 1 ),
].join( '/' );
const beforeBoundCopy = await rawContent();
const boundCopy = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/cta-band',
	position: 1,
	copy: {
		Heading: 'Call us today',
		'Click to call': '(555) 555-0113',
	},
} );
const afterBoundCopy = await rawContent();
step(
	'insert refuses copy for the bound "Click to call" with voyager_open_blocks_bound_slot, names it by its path in the page, and the page is unchanged',
	boundCopy.status === 400 &&
		boundCopy.data.code === 'voyager_open_blocks_bound_slot' &&
		( boundCopy.data.message || '' ).includes(
			`"Click to call" (core/button text at ${ boundCallPath })`
		) &&
		( boundCopy.data.message || '' ).includes(
			'voyager/site-data, key "phone"'
		) &&
		( boundCopy.data.message || '' ).includes( 'Settings > Site Data' ) &&
		!! beforeBoundCopy &&
		afterBoundCopy === beforeBoundCopy,
	boundCopy.data.message || boundCopy.data.code
);

// The proof strip with three facts: copy fills three items, omit drops the fourth.
// Each credential is a claim slot, so the copy is the object form, text the
// site owner gave. The strip ships stand-ins ("Your first credential" to
// "Your fourth credential"), and none may be left.
const strip = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/proof-strip',
	copy: {
		'Credential 1': { text: 'Licensed in three states', source: 'user' },
		'Credential 2': { text: 'Family owned since 1998', source: 'user' },
		'Credential 3': { text: 'Written quotes', source: 'user' },
	},
	omit: [ 'Credential 4' ],
} );
const stripMarkup = strip.data.markup || '';
step(
	'insert proof-strip with copy and omit',
	strip.status === 200 &&
		strip.data.saved === true &&
		strip.data.validation?.round_trip === true &&
		( stripMarkup.match( /<li>/g ) || [] ).length === 3 &&
		stripMarkup.includes( '>Family owned since 1998</li>' ) &&
		! /Your \w+ credential/.test( stripMarkup ) &&
		! ( strip.data.blocks || [] ).some(
			( b ) => b.label === 'Credential 4'
		),
	strip.data.code ||
		`${ ( stripMarkup.match( /<li>/g ) || [] ).length } items`
);
validate( 'proof-strip with omit', stripMarkup );

// The proof strip with six facts: repeat adds a fifth and sixth item after the
// fourth, and copy fills all six.
const sixFacts = [
	'Licensed in three states',
	'Family owned since 1998',
	'Written quotes',
	'Same-week scheduling',
	'Free estimates',
	'Two-year guarantee',
];
const six = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/proof-strip',
	repeat: { 'Credential 4': [ 'Credential 5', 'Credential 6' ] },
	copy: Object.fromEntries(
		sixFacts.map( ( fact, i ) => [
			`Credential ${ i + 1 }`,
			{ text: fact, source: 'user' },
		] )
	),
} );
const sixMarkup = six.data.markup || '';
const sixItems = [ ...sixMarkup.matchAll( /<li>([^<]*)<\/li>/g ) ].map(
	( m ) => m[ 1 ]
);
step(
	'insert proof-strip with six facts (repeat and copy)',
	six.status === 200 &&
		six.data.saved === true &&
		six.data.validation?.round_trip === true &&
		JSON.stringify( sixItems ) === JSON.stringify( sixFacts ) &&
		( six.data.blocks || [] ).some( ( b ) => b.label === 'Credential 6' ),
	six.data.code || sixItems.join( ' | ' )
);
validate( 'proof-strip with six facts', sixMarkup );

for ( const [ label, input ] of [
	[
		'insert refuses repeat for the whole section',
		{ repeat: { 'Proof strip': [ 'Proof strip 2' ] } },
	],
	[
		'insert refuses repeat to a label already used',
		{ repeat: { 'Credential 4': [ 'Credential 2' ] } },
	],
	[
		'insert refuses repeat for a block that holds named blocks',
		{ repeat: { Credentials: [ 'More credentials' ] } },
	],
	[
		'insert refuses repeat on a reference',
		{
			repeat: { 'Credential 4': [ 'Credential 5' ] },
			mode: 'reference',
		},
	],
] ) {
	const refused = await run( `${ ns }/insert`, {
		post_id: postId,
		item: 'voyager-open/proof-strip',
		dry_run: true,
		...input,
	} );
	step(
		label,
		refused.status === 400 &&
			refused.data.code === 'voyager_open_blocks_invalid_repeat',
		refused.data.message || refused.data.code
	);
}

// 3. Icon: insert, configure, bind.
const icon = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/icon',
	attributes: { icon: 'phone', size: 32, label: 'Call us' },
} );
const iconPath = icon.data.blocks?.[ 0 ]?.path;
step(
	'insert icon',
	icon.status === 200 && icon.data.saved === true,
	`path ${ iconPath }`
);

const configured = await run( `${ ns }/configure`, {
	post_id: postId,
	path: iconPath,
	attributes: { animation: 'pulse', animationTrigger: 'always' },
} );
step(
	'configure icon',
	configured.status === 200 &&
		configured.data.attributes?.animation === 'pulse'
);

const field = phoneLink.args;
const bound = await run( `${ ns }/bind`, {
	post_id: postId,
	path: iconPath,
	attribute: 'linkUrl',
	source: 'voyager/site-data',
	args: field,
} );
step(
	'bind icon linkUrl to site-data phone_link (args copied from the catalog)',
	bound.status === 200,
	JSON.stringify( bound.data.attributes?.metadata?.bindings )
);
validate( 'icon after bind', bound.data.markup || '' );

// 4. Refusals.
const badIcon = await run( `${ ns }/configure`, {
	post_id: postId,
	path: iconPath,
	attributes: { icon: 'not-an-icon' },
} );
step(
	'configure refuses an icon outside the enum',
	badIcon.status === 400 &&
		badIcon.data.code === 'voyager_open_blocks_invalid_attributes',
	badIcon.data.code
);

const invented = await run( `${ ns }/bind`, {
	post_id: postId,
	path: iconPath,
	attribute: 'linkUrl',
	source: 'voyager/site-data',
	args: { key: 'telephone' },
} );
step(
	'bind refuses invented args',
	invented.status === 400 &&
		invented.data.code === 'voyager_open_blocks_invalid_args',
	invented.data.code
);

const heading = ( cta.data.blocks || [] ).find(
	( b ) => b.block === 'core/heading'
);
const unbindable = await run( `${ ns }/bind`, {
	post_id: postId,
	path: heading?.path,
	attribute: 'url',
	source: 'voyager/site-data',
	args: field,
} );
step(
	'bind refuses an attribute the block cannot bind',
	unbindable.status === 400 &&
		unbindable.data.code === 'voyager_open_blocks_invalid_attribute',
	unbindable.data.code
);

const staticConfigure = await run( `${ ns }/configure`, {
	post_id: postId,
	path: heading?.path,
	attributes: { level: 3 },
} );
step(
	'configure refuses a static block',
	staticConfigure.status === 400 &&
		staticConfigure.data.code === 'voyager_open_blocks_invalid_item',
	staticConfigure.data.code
);

for ( const [ label, input, code ] of [
	[ 'insert refuses copy for an unknown label', { copy: { Headline: 'x' } } ],
	[
		'insert refuses copy for a block that is not text',
		{ copy: { 'CTA band': 'x' } },
	],
	[
		'insert refuses copy on a reference',
		{ copy: { Heading: 'x' }, mode: 'reference' },
	],
	[
		'insert refuses omit for the whole section',
		{ omit: [ 'CTA band' ] },
		'voyager_open_blocks_invalid_omit',
	],
	[
		'insert refuses omit for an unknown label',
		{ omit: [ 'Headline' ] },
		'voyager_open_blocks_invalid_omit',
	],
	[
		'insert refuses omit on a reference',
		{ omit: [ 'Eyebrow' ], mode: 'reference' },
		'voyager_open_blocks_invalid_omit',
	],
] ) {
	const refused = await run( `${ ns }/insert`, {
		post_id: postId,
		item: 'voyager-open/cta-band',
		dry_run: true,
		...input,
	} );
	step(
		label,
		refused.status === 400 &&
			refused.data.code ===
				( code || 'voyager_open_blocks_invalid_copy' ),
		refused.data.message || refused.data.code
	);
}

// Nested insert: an icon inside the second CTA band's row, before its buttons.
// The row is a core/group, which takes any block. core/buttons takes only
// buttons, a heading holds no blocks, and a locked group takes nothing.
const blocksOf = ( response ) => response.data.blocks || [];
const parentOf = ( path ) => path.split( '/' ).slice( 0, -1 ).join( '/' );
const rowPath = blocksOf( withCopy ).find( ( b ) => b.label === 'Row' )?.path;
const buttonsRow = blocksOf( withCopy ).find(
	( b ) => b.block === 'core/buttons' && parentOf( b.path ) === rowPath
);
const buttonsIndex = Number( buttonsRow?.path.split( '/' ).pop() );
const nested = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/icon',
	parent: rowPath,
	position: buttonsIndex,
	attributes: { icon: 'phone', size: 24, label: 'Call us' },
} );
step(
	'insert an icon inside a row, before its buttons',
	nested.status === 200 &&
		nested.data.saved === true &&
		nested.data.parent === rowPath &&
		nested.data.index === buttonsIndex &&
		nested.data.blocks?.[ 0 ]?.path === `${ rowPath }/${ buttonsIndex }` &&
		nested.data.validation?.section_round_trip === true &&
		( nested.data.section_markup || '' ).includes(
			'<!-- wp:voyager-open/icon {"icon":"phone","size":24,"label":"Call us"} /-->\n\n<!-- wp:buttons'
		),
	nested.data.message || `icon at ${ nested.data.blocks?.[ 0 ]?.path }`
);
validate( 'section with a nested icon', nested.data.section_markup || '' );

const lockedPage = await api( '/wp/v2/pages', {
	method: 'POST',
	body: {
		title: `E2E: locked ${ stamp }`,
		status: 'draft',
		content:
			'<!-- wp:group {"templateLock":"all","layout":{"type":"constrained"}} -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Locked</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->',
	},
} );

for ( const [ label, input ] of [
	[
		'insert refuses an icon inside core/buttons (allowedBlocks)',
		{ parent: buttonsRow?.path },
	],
	[
		'insert refuses a parent that holds no blocks',
		{ parent: heading?.path },
	],
	[ 'insert refuses a parent path with no block', { parent: '99/0' } ],
	[
		'insert refuses a locked parent (templateLock)',
		{ post_id: lockedPage.data.id, parent: '0' },
	],
] ) {
	const refused = await run( `${ ns }/insert`, {
		post_id: postId,
		item: 'voyager-open/icon',
		dry_run: true,
		...input,
	} );
	step(
		label,
		refused.status === 400 &&
			refused.data.code === 'voyager_open_blocks_invalid_parent',
		refused.data.message || refused.data.code
	);
}

const pageWithPostType = await run( `${ ns }/bind`, {
	post_id: postId,
	path: heading?.path,
	attribute: 'content',
	source: 'voyager/site-data',
	args: { key: 'phone' },
	post_type: 'post',
} );
step(
	'bind refuses post_type for a block that reads its own page',
	pageWithPostType.status === 400 &&
		pageWithPostType.data.code === 'voyager_open_blocks_invalid_post_type',
	pageWithPostType.data.code
);

// configure refuses a bound attribute the same way: the icon's link is now
// bound to phone_link, and the CTA band's call button text to phone. Nothing
// is saved. The call button is named as bound even though configure could not
// change it anyway.
for ( const [ label, path, attributes ] of [
	[
		"configure refuses the icon's bound linkUrl",
		iconPath,
		{ linkUrl: 'tel:+15555550113' },
	],
	[
		"configure refuses the call button's bound text",
		callButton?.path,
		{ text: '(555) 555-0113' },
	],
] ) {
	const before = await rawContent();
	const refused = await run( `${ ns }/configure`, {
		post_id: postId,
		path,
		attributes,
	} );
	const after = await rawContent();
	step(
		`${ label } with voyager_open_blocks_bound_slot, and the page is unchanged`,
		refused.status === 400 &&
			refused.data.code === 'voyager_open_blocks_bound_slot' &&
			( refused.data.message || '' ).includes( 'Settings > Site Data' ) &&
			!! before &&
			after === before,
		refused.data.message || refused.data.code
	);
}

// 5. The saved page as a whole.
const saved = await api( `/wp/v2/pages/${ postId }`, {
	query: { context: 'edit' },
} );
validate( 'saved page content', saved.data.content?.raw || '' );

// The validate ability: the PHP floor an agent without Node has. It is
// read-only, so over REST its input travels in the query string.
function checkMarkup( input ) {
	return run( `${ ns }/validate`, input, true );
}

function clean( report ) {
	return (
		report.status === 200 &&
		report.data.valid === true &&
		report.data.round_trip === true &&
		( report.data.blocks || [] ).length > 0 &&
		( report.data.errors || [] ).length === 0
	);
}

const copiedReport = await checkMarkup( { markup: copied } );
step(
	'validate passes the CTA band markup insert returned',
	clean( copiedReport ),
	`${ ( copiedReport.data.blocks || [] ).length } blocks, ${ ( copiedReport.data.errors || [] ).join( ' ' ) || 'no errors' }`
);

const pageReport = await checkMarkup( { post_id: postId } );
step(
	'validate passes the saved page by post_id',
	clean( pageReport ) && pageReport.data.post_type === 'page',
	`${ ( pageReport.data.blocks || [] ).length } blocks, ${ ( pageReport.data.errors || [] ).join( ' ' ) || 'no errors' }`
);

// Spelling WordPress reads the same way (core/ prefix, & in JSON, escaped
// slashes) is not a round-trip failure.
const spelled = await checkMarkup( {
	markup: '<!-- wp:voyager-open/icon {"icon":"phone","linkUrl":"https:\\/\\/example.com\\/?a=1&b=2"} /-->\n\n<!-- wp:core/paragraph -->\n<p>Hello</p>\n<!-- /wp:core/paragraph -->',
} );
step(
	'validate accepts equivalent spelling of block comments',
	clean( spelled ),
	( spelled.data.errors || [] ).join( ' ' ) || spelled.data.code
);

for ( const [ label, markup, expect ] of [
	[
		'validate reports invented binding args at the block',
		'<!-- wp:voyager-open/icon {"icon":"phone","metadata":{"bindings":{"linkUrl":{"source":"voyager/site-data","args":{"key":"telephone"}}}}} /-->',
		( r ) => r.data.blocks?.[ 0 ]?.errors?.[ 0 ]?.includes( 'telephone' ),
	],
	[
		'validate reports an unregistered block type',
		'<!-- wp:example/not-a-block /-->',
		( r ) =>
			r.data.blocks?.[ 0 ]?.errors?.[ 0 ]?.includes(
				'not a registered block type'
			),
	],
	[
		'validate reports an icon outside the enum',
		'<!-- wp:voyager-open/icon {"icon":"not-an-icon"} /-->',
		( r ) => ( r.data.blocks?.[ 0 ]?.errors || [] ).length === 1,
	],
	[
		'validate reports a block with no closing comment',
		'<!-- wp:group -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph --></div>\n\n<!-- wp:paragraph -->\n<p>After</p>\n<!-- /wp:paragraph -->',
		( r ) =>
			r.data.round_trip === false &&
			( r.data.blocks?.[ 0 ]?.errors || [] ).length === 1,
	],
	[
		'validate reports HTML outside any block',
		'<p>Loose</p>',
		( r ) => r.data.blocks?.[ 0 ]?.block === 'core/freeform',
	],
] ) {
	const report = await checkMarkup( { markup } );
	step(
		label,
		report.status === 200 &&
			report.data.valid === false &&
			!! expect( report ),
		( report.data.errors || [] ).join( ' ' ) || report.data.code
	);
}

// The boundary the skills state: saved HTML that disagrees with its comment
// passes every PHP check, and only block-runner catches it.
const mismatch = readFileSync(
	join( repo, 'tests', 'invalid', 'heading-level-mismatch.html' ),
	'utf8'
);
const mismatchReport = await checkMarkup( { markup: mismatch } );
step(
	'validate passes tests/invalid/heading-level-mismatch.html (saved HTML needs block-runner)',
	clean( mismatchReport ),
	`valid ${ mismatchReport.data.valid }`
);

// stale_fallback: the benchmark's one loss. The willow-vet agent passed the
// phone number as copy for the bound call button, so the stored page kept the
// old number as that button's fallback after the number changed. validate
// warns on it, given the markup and given the stored page, and on nothing in
// this run's page, whose bound call buttons hold "Call us".
const staleWarnings = ( report ) =>
	( report.data.warnings || [] ).filter( ( w ) =>
		w.startsWith( 'stale_fallback: ' )
	);
const willowMarkup = readFileSync(
	join( repo, 'bench', 'runs', 'loop', 'willow-vet', 'after-edit.html' ),
	'utf8'
);
const flagsWillow = ( report ) => {
	const found = staleWarnings( report );
	return (
		report.status === 200 &&
		found.length === 1 &&
		found[ 0 ].includes( 'core/button "Click to call"' ) &&
		found[ 0 ].includes( '"(555) 555-0113" looks like a phone number' )
	);
};
const willowReport = await checkMarkup( { markup: willowMarkup } );
step(
	'validate warns stale_fallback on the markup of bench/runs/loop/willow-vet/after-edit.html',
	flagsWillow( willowReport ),
	staleWarnings( willowReport ).join( ' ' ) ||
		willowReport.data.code ||
		`status ${ willowReport.status }`
);

const willowPage = await api( '/wp/v2/pages', {
	method: 'POST',
	body: {
		title: `E2E: willow-vet ${ stamp }`,
		status: 'draft',
		content: willowMarkup,
	},
} );
const willowStored = await checkMarkup( { post_id: willowPage.data.id } );
step(
	'validate warns stale_fallback on the stored willow-vet page, by post_id',
	willowPage.status === 201 && flagsWillow( willowStored ),
	staleWarnings( willowStored ).join( ' ' ) ||
		willowStored.data.code ||
		`status ${ willowPage.status }`
);

const pageCallLabels = (
	saved.data.content?.raw?.match(
		/"name":"Click to call","bindings":\{"text"[^>]*-->\s*<div[^>]*><a[^>]*>([^<]*)<\/a>/g
	) || []
).map( ( m ) => m.replace( /^[\s\S]*>([^<]*)<\/a>$/, '$1' ) );
step(
	'validate warns nothing on a page whose bound call buttons hold "Call us"',
	pageReport.status === 200 &&
		pageCallLabels.length >= 2 &&
		pageCallLabels.every( ( text ) => text === 'Call us' ) &&
		staleWarnings( pageReport ).length === 0,
	`call buttons hold ${ JSON.stringify( pageCallLabels ) }; ${ staleWarnings( pageReport ).join( ' ' ) || 'no stale_fallback' }`
);

// placeholder and missing_alt: the pack's placeholders left in place, and
// images with no alt text. Each pattern goes on a draft of its own through
// insert, so validate reads them as insert saves them: placeholder logos at
// the plugin's assets URL, not the token the raw markup carries.
const codedWarnings = ( report, code ) =>
	( report.data.warnings || [] ).filter( ( w ) =>
		w.startsWith( `${ code }: ` )
	);
const warningSummary = ( report ) =>
	`valid ${ report.data.valid }, ${
		( report.data.warnings || [] ).join( ' ' ) ||
		report.data.code ||
		`status ${ report.status }, no warnings`
	}`;
const placeholderPages = [];

/**
 * A draft holding one pattern placed with insert, and validate's report on it.
 *
 * @param {string} item Pattern name.
 * @return {Promise<Object>} The draft's id, the insert response and the validate report.
 */
async function validateInserted( item ) {
	const draft = await api( '/wp/v2/pages', {
		method: 'POST',
		body: { title: `E2E: ${ item } ${ stamp }`, status: 'draft' },
	} );
	placeholderPages.push( draft.data.id );
	const placed = await run( `${ ns }/insert`, {
		post_id: draft.data.id,
		item,
	} );
	return {
		id: draft.data.id,
		placed,
		report: await checkMarkup( { post_id: draft.data.id } ),
	};
}

const marquee = await validateInserted( 'voyager-open/logo-marquee' );
const logoWarnings = codedWarnings( marquee.report, 'placeholder' );
step(
	'validate warns placeholder once per logo on a page holding the logo marquee as insert saves it',
	marquee.placed.data.saved === true &&
		marquee.report.status === 200 &&
		logoWarnings.length === 6 &&
		logoWarnings.every( ( w ) =>
			/^placeholder: core\/image at \d+(\/\d+)+ shows the pack's placeholder image placeholders\/logo-[1-6]\.svg\./.test(
				w
			)
		) &&
		new Set( logoWarnings.map( ( w ) => w.match( /logo-\d/ )[ 0 ] ) )
			.size === 6,
	`${ logoWarnings.length } placeholder warnings; ${ warningSummary( marquee.report ) }`
);
step(
	'validate warns no missing_alt for the placeholder logos, and the page stays valid',
	marquee.report.data.valid === true &&
		codedWarnings( marquee.report, 'missing_alt' ).length === 0,
	warningSummary( marquee.report )
);

/**
 * The opening tag of each element directly inside the first element whose
 * class list holds className, in a rendered page.
 *
 * @param {string} html      A rendered page.
 * @param {string} className A class on the parent's own opening tag.
 * @return {{tags: string[], end: number}} One opening tag per child, and where the parent's closing tag starts (-1 when no element has the class).
 */
function childTags( html, className ) {
	const open = new RegExp(
		`<[a-z][a-z0-9-]*\\b[^>]*\\bclass="(?:[^"]*\\s)?${ className }(?:\\s[^"]*)?"[^>]*>`
	).exec( html );
	if ( ! open ) {
		return { tags: [], end: -1 };
	}
	const voids = new Set( [
		'area',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'source',
		'track',
		'wbr',
	] );
	const tag =
		/<!--[\s\S]*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b(?:[^>"']|"[^"]*"|'[^']*')*?(\/?)>/g;
	tag.lastIndex = open.index + open[ 0 ].length;
	const tags = [];
	let depth = 0;
	for ( let m = tag.exec( html ); m; m = tag.exec( html ) ) {
		if ( ! m[ 2 ] ) {
			continue;
		}
		if ( m[ 1 ] ) {
			if ( depth === 0 ) {
				return { tags, end: m.index };
			}
			depth--;
			continue;
		}
		if ( depth === 0 ) {
			tags.push( m[ 0 ] );
		}
		if ( ! m[ 3 ] && ! voids.has( m[ 2 ].toLowerCase() ) ) {
			depth++;
		}
	}
	return { tags, end: -1 };
}

// The fitness check: the logo marquee as insert saves it, published and
// fetched as a visitor. The Marquee group holds the logo row and, made at
// render, a copy of it hidden from screen readers and inert, with no id. The
// stored page still holds each logo once, so validate still warns six times.
const marqueePublished = await api( `/wp/v2/pages/${ marquee.id }`, {
	method: 'POST',
	body: { status: 'publish' },
} );
const marqueeFront = marqueePublished.data.link
	? await request( marqueePublished.data.link )
	: { status: 0, text: '' };
const marqueeRows = childTags(
	marqueeFront.text,
	'is-style-voyager-open-marquee'
);
const [ marqueeRow = '', marqueeCopyTag = '' ] = marqueeRows.tags;
const copyStart = marqueeCopyTag
	? marqueeFront.text.indexOf( marqueeCopyTag )
	: -1;
const marqueeCopy =
	copyStart < 0 || marqueeRows.end < copyStart
		? ''
		: marqueeFront.text.slice( copyStart, marqueeRows.end );
step(
	'fitness: insert voyager-open/logo-marquee, publish and fetch it: the Marquee group holds two logo rows, the second aria-hidden="true" and inert, with the six logos and no id',
	marquee.placed.data.saved === true &&
		marqueeFront.status === 200 &&
		marqueeRows.tags.length === 2 &&
		marqueeRows.tags.every( ( t ) =>
			/^<div\b[^>]*\bclass="wp-block-group\b/.test( t )
		) &&
		! /\saria-hidden=|\sinert\b/.test( marqueeRow ) &&
		/\saria-hidden="true"/.test( marqueeCopyTag ) &&
		/\sinert(?=[\s>=\/])/.test( marqueeCopyTag ) &&
		( marqueeCopy.match( /<img\b/g ) || [] ).length === 6 &&
		! /\sid=/.test( marqueeCopy ),
	`${ marqueeFront.status }; ${
		marqueeRows.tags.length
	} rows in the Marquee group: ${ marqueeRows.tags.join( ' ' ) || 'none' }`
);
// The same page over REST: content.rendered is read by agents, not seen in a
// browser, so it holds the logo row once, with no copy.
const marqueeRest = await api( `/wp/v2/pages/${ marquee.id }`, {
	query: { context: 'view' },
} );
const restRendered = marqueeRest.data.content?.rendered || '';
const restRows = childTags( restRendered, 'is-style-voyager-open-marquee' );
step(
	'the same page over REST: content.rendered holds one logo row in the Marquee group, with the six logos and no copy',
	marqueeRest.status === 200 &&
		restRows.tags.length === 1 &&
		! /\saria-hidden=|\sinert\b|voyager-open-marquee-copy/.test(
			restRendered
		) &&
		( restRendered.match( /<img\b/g ) || [] ).length === 6,
	`${ marqueeRest.status }; ${
		restRows.tags.length
	} rows in the Marquee group: ${ restRows.tags.join( ' ' ) || 'none' }`
);

const marqueePublishedReport = await checkMarkup( { post_id: marquee.id } );
const publishedLogoWarnings = codedWarnings(
	marqueePublishedReport,
	'placeholder'
);
step(
	'validate still warns placeholder once per logo, six times, on the published logo marquee page',
	marqueePublishedReport.status === 200 &&
		publishedLogoWarnings.length === 6 &&
		new Set(
			publishedLogoWarnings.map( ( w ) => w.match( /logo-[1-6]/ )?.[ 0 ] )
		).size === 6,
	`${ publishedLogoWarnings.length } placeholder warnings; ${ warningSummary(
		marqueePublishedReport
	) }`
);

const contact = await validateInserted( 'voyager-open/contact-section' );
const formWarnings = codedWarnings( contact.report, 'placeholder' );
step(
	'validate warns placeholder once for the form slot on a page holding the contact section',
	contact.placed.data.saved === true &&
		contact.report.status === 200 &&
		contact.report.data.valid === true &&
		formWarnings.length === 1 &&
		formWarnings[ 0 ].startsWith( 'placeholder: core/group "Form" at ' ) &&
		formWarnings[ 0 ].includes( '"Form slot" column' ),
	warningSummary( contact.report )
);

// The hero's two layouts, each as insert saves it. Each has one image slot
// and no other placeholder: the split layout's is a core/image over the 4:3
// placeholder, the cover layout's is the cover's background over the 16:9
// one, so validate warns placeholder once and nothing else.
for ( const [ item, block, file ] of [
	[ 'voyager-open/hero', 'core/image "Image"', 'image-4x3.svg' ],
	[ 'voyager-open/hero-cover', 'core/cover "Background"', 'image-16x9.svg' ],
] ) {
	const listed = ( catalog.data.patterns || [] ).find(
		( p ) => p.name === item
	);
	const labels = ( listed?.blocks || [] )
		.filter( ( b ) => b.copy )
		.map( ( b ) => b.label );
	step(
		`get-catalog lists ${ item } with its copy labels`,
		JSON.stringify( labels ) ===
			JSON.stringify( [
				'Eyebrow',
				'Heading',
				'Body',
				'Primary button',
				'Click to call',
			] ),
		listed ? labels.join( ', ' ) : 'not listed'
	);
	const hero = await validateInserted( item );
	const warnings = hero.report.data.warnings || [];
	step(
		`validate warns placeholder once, for its image slot, and nothing else, on a page holding ${ item } as insert saves it`,
		hero.placed.data.saved === true &&
			clean( hero.report ) &&
			warnings.length === 1 &&
			warnings[ 0 ].startsWith( `placeholder: ${ block } at ` ) &&
			warnings[ 0 ].includes(
				`shows the pack's placeholder image placeholders/${ file }`
			),
		`${
			hero.placed.data.code || `saved ${ hero.placed.data.saved }`
		}; ${ warningSummary( hero.report ) }`
	);
}

// The services section in its two layouts. Each item is a block labelled
// "Service <n>" whose named blocks carry its label ("Service <n> heading"),
// so repeat copies a whole card or row and gives its blocks the new label,
// copy fills them, and omit drops an item with everything in it.
const serviceLabels = ( count ) => [
	'Heading',
	'Intro',
	...Array.from( { length: count }, ( _, i ) => [
		`Service ${ i + 1 } heading`,
		`Service ${ i + 1 } text`,
	] ).flat(),
];
for ( const item of [
	'voyager-open/services',
	'voyager-open/services-list',
] ) {
	const listed = ( catalog.data.patterns || [] ).find(
		( p ) => p.name === item
	);
	const labels = ( listed?.blocks || [] )
		.filter( ( b ) => b.copy )
		.map( ( b ) => b.label );
	step(
		`get-catalog lists ${ item } with its copy labels`,
		JSON.stringify( labels ) === JSON.stringify( serviceLabels( 3 ) ),
		listed ? labels.join( ', ' ) : 'not listed'
	);
}

const services = [
	[
		'Leak repairs',
		'Dripping taps, burst pipes and running toilets, found and fixed.',
	],
	[
		'Water heaters',
		'New tank and tankless heaters fitted, and old ones taken away.',
	],
	[
		'Drain cleaning',
		'Slow and blocked drains cleared, with a camera look where needed.',
	],
	[
		'Bathroom refits',
		'New fixtures, pipework and fittings for a bathroom you are redoing.',
	],
	[
		'Gas lines',
		'New gas lines run to a cooker, a dryer or a fire, then tested.',
	],
	[
		'Outdoor taps',
		'Garden taps and hose bibs fitted or moved, and frost-proofed.',
	],
];
const serviceCopy = ( count ) =>
	Object.fromEntries( [
		[ 'Heading', 'Plumbing services' ],
		...services.slice( 0, count ).flatMap( ( [ title, text ], i ) => [
			[ `Service ${ i + 1 } heading`, title ],
			[ `Service ${ i + 1 } text`, text ],
		] ),
	] );
const headingsIn = ( markup ) =>
	[ ...markup.matchAll( /<h3 class="wp-block-heading">([^<]*)<\/h3>/g ) ].map(
		( m ) => m[ 1 ]
	);
// The grid's cards: each item is a bordered group, straight in the grid.
const cardsIn = ( markup ) =>
	(
		markup.match(
			/<!-- wp:group \{"metadata":\{"name":"Service \d+"\},"style":\{"border"/g
		) || []
	).length;

/**
 * Insert a services or reviews layout on a draft of its own, and validate it
 * both ways.
 *
 * @param {string} label What the step checks.
 * @param {Object} input insert's input, less post_id.
 * @return {Promise<Object>} The insert response and validate's report on the saved page.
 */
async function insertServices( label, input ) {
	const draft = await api( '/wp/v2/pages', {
		method: 'POST',
		body: { title: `E2E: ${ label } ${ stamp }`, status: 'draft' },
	} );
	placeholderPages.push( draft.data.id );
	const placed = await run( `${ ns }/insert`, {
		post_id: draft.data.id,
		...input,
	} );
	validate( label, placed.data.markup || '' );
	return {
		postId: draft.data.id,
		placed,
		report: await checkMarkup( { post_id: draft.data.id } ),
	};
}

// The grid with a fourth card: repeat copies "Service 3" as "Service 4", and
// copy fills all four. The copy's icon is the third's until configure sets it.
const fourCards = await insertServices( 'services with a fourth card', {
	item: 'voyager-open/services',
	repeat: { 'Service 3': [ 'Service 4' ] },
	copy: serviceCopy( 4 ),
} );
const fourMarkup = fourCards.placed.data.markup || '';
const fourthIcon = ( fourCards.placed.data.blocks || [] ).find(
	( b ) => b.label === 'Service 4 icon'
);
step(
	'insert services with a fourth card through repeat: four cards in the row, each named for its own number and filled by copy',
	fourCards.placed.status === 200 &&
		fourCards.placed.data.saved === true &&
		fourCards.placed.data.validation?.round_trip === true &&
		cardsIn( fourMarkup ) === 4 &&
		( fourMarkup.match( /<!-- wp:voyager-open\/icon /g ) || [] ).length ===
			4 &&
		JSON.stringify( headingsIn( fourMarkup ) ) ===
			JSON.stringify( services.slice( 0, 4 ).map( ( [ t ] ) => t ) ) &&
		fourMarkup.includes( `<p>${ services[ 3 ][ 1 ] }</p>` ) &&
		[ 'Service 4', 'Service 4 heading', 'Service 4 text' ].every(
			( name ) =>
				( fourCards.placed.data.blocks || [] ).some(
					( b ) => b.label === name
				)
		) &&
		!! fourthIcon,
	fourCards.placed.data.code ||
		`${ headingsIn( fourMarkup ).join( ' | ' ) }; ${ cardsIn(
			fourMarkup
		) } cards`
);
step(
	'validate passes the page with four service cards, with no warning',
	clean( fourCards.report ) &&
		( fourCards.report.data.warnings || [] ).length === 0,
	warningSummary( fourCards.report )
);
const fourthIconSet = fourthIcon
	? await run( `${ ns }/configure`, {
			post_id: fourCards.postId,
			path: fourthIcon.path,
			attributes: { icon: 'bolt' },
		} )
	: { status: 0, data: { code: 'no Service 4 icon in the response' } };
step(
	"configure sets the fourth card's icon, which repeat copied from the third",
	fourthIconSet.status === 200 &&
		fourthIconSet.data.attributes?.icon === 'bolt',
	fourthIconSet.data.code ||
		JSON.stringify( fourthIconSet.data.attributes ?? null )
);

// The grid with one card dropped: omit removes "Service 3" and its blocks.
const twoCards = await insertServices( 'services with a card omitted', {
	item: 'voyager-open/services',
	omit: [ 'Service 3' ],
	copy: serviceCopy( 2 ),
} );
const twoMarkup = twoCards.placed.data.markup || '';
step(
	'insert services with one card dropped through omit: two cards, and nothing of the third',
	twoCards.placed.status === 200 &&
		twoCards.placed.data.saved === true &&
		cardsIn( twoMarkup ) === 2 &&
		JSON.stringify( headingsIn( twoMarkup ) ) ===
			JSON.stringify( services.slice( 0, 2 ).map( ( [ t ] ) => t ) ) &&
		! twoMarkup.includes( 'Service 3' ) &&
		! twoMarkup.includes( 'Maintenance' ),
	twoCards.placed.data.code || headingsIn( twoMarkup ).join( ' | ' )
);
step(
	'validate passes the page with two service cards, with no warning',
	clean( twoCards.report ) &&
		( twoCards.report.data.warnings || [] ).length === 0,
	warningSummary( twoCards.report )
);

// The list with six rows: three copies of the third row, all filled by copy.
const sixRows = await insertServices( 'services list with six rows', {
	item: 'voyager-open/services-list',
	repeat: { 'Service 3': [ 'Service 4', 'Service 5', 'Service 6' ] },
	copy: serviceCopy( 6 ),
} );
const sixRowsMarkup = sixRows.placed.data.markup || '';
step(
	'insert the services list with six rows through repeat, each filled by copy',
	sixRows.placed.status === 200 &&
		sixRows.placed.data.saved === true &&
		JSON.stringify( headingsIn( sixRowsMarkup ) ) ===
			JSON.stringify( services.map( ( [ t ] ) => t ) ) &&
		( sixRowsMarkup.match( /<!-- wp:voyager-open\/icon /g ) || [] )
			.length === 6,
	sixRows.placed.data.code || headingsIn( sixRowsMarkup ).join( ' | ' )
);
step(
	'validate passes the page with six service rows, with no warning',
	clean( sixRows.report ) &&
		( sixRows.report.data.warnings || [] ).length === 0,
	warningSummary( sixRows.report )
);

// A copy may not take a label a block already has, inside the item too.
const takenInside = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/services',
	dry_run: true,
	repeat: { 'Service 3': [ 'Service 2' ] },
} );
step(
	'insert refuses repeat to a label already used, for a card as for a list item',
	takenInside.status === 400 &&
		takenInside.data.code === 'voyager_open_blocks_invalid_repeat',
	takenInside.data.message || takenInside.data.code
);

// The reviews section in its two layouts. Each review is a block labelled
// "Review <n>" whose named blocks carry its label: a rating row of five
// stars and the rating in words ("Review <n> rating"), the customer's quote,
// their name and one detail. The stars have no label, so the icon block
// renders each one aria-hidden and the words are the row's one name.
const reviewLabels = ( count ) => [
	'Heading',
	...Array.from( { length: count }, ( _, i ) =>
		[ 'rating', 'quote', 'name', 'detail' ].map(
			( part ) => `Review ${ i + 1 } ${ part }`
		)
	).flat(),
];
for ( const [ item, count ] of [
	[ 'voyager-open/reviews', 3 ],
	[ 'voyager-open/reviews-single', 1 ],
] ) {
	const listed = ( catalog.data.patterns || [] ).find(
		( p ) => p.name === item
	);
	const labels = ( listed?.blocks || [] )
		.filter( ( b ) => b.copy )
		.map( ( b ) => b.label );
	step(
		`get-catalog lists ${ item } with its copy labels`,
		JSON.stringify( labels ) === JSON.stringify( reviewLabels( count ) ),
		listed ? labels.join( ', ' ) : 'not listed'
	);
}

/**
 * The star rows in rendered HTML, each with what a screen reader is given
 * for it: a run of icons in one group, then the paragraph beside them in
 * the same row. A name is the aria-label of an icon that is not hidden, or
 * the text of the paragraph.
 *
 * @param {string} html A rendered page.
 * @return {Array<{stars: number, hidden: number, names: string[]}>} One entry per row.
 */
function starRows( html ) {
	const rows = [];
	const row =
		/<div class="wp-block-group\b[^"]*">((?:\s*<div class="wp-block-voyager-open-icon\b[^"]*"[^>]*>\s*<svg\b[^>]*>[\s\S]*?<\/svg>\s*<\/div>)+)\s*<\/div>\s*<p\b([^>]*)>([\s\S]*?)<\/p>/g;
	for ( const [ , icons, pAttrs, pText ] of html.matchAll( row ) ) {
		const svgs = [ ...icons.matchAll( /<svg\b([^>]*)>/g ) ].map(
			( m ) => m[ 1 ]
		);
		const hidden = ( attrs ) =>
			/\baria-hidden="true"/.test( attrs ) && ! /\brole=/.test( attrs );
		const spoken = [
			...svgs
				.filter( ( attrs ) => ! hidden( attrs ) )
				.map(
					( attrs ) =>
						attrs.match( /\baria-label="([^"]*)"/ )?.[ 1 ] ?? ''
				),
			...( /\baria-hidden="true"/.test( pAttrs )
				? []
				: [ pText.replace( /<[^>]*>/g, '' ).trim() ] ),
		].filter( ( name ) => name !== '' );
		rows.push( {
			stars: svgs.length,
			hidden: svgs.filter( hidden ).length,
			names: spoken,
		} );
	}
	return rows;
}
const rowSummary = ( rows ) =>
	`${ rows.length } star rows: ${
		rows
			.map(
				( r ) =>
					`${ r.hidden }/${ r.stars } stars hidden, names ${ JSON.stringify( r.names ) }`
			)
			.join( '; ' ) || 'none'
	}`;

// The fitness check: the grid as insert saves it, published and fetched as a
// visitor. Three star rows, each five stars hidden from screen readers and
// exactly one name, the rating in words.
const reviewsPage = await api( '/wp/v2/pages', {
	method: 'POST',
	body: { title: `E2E: reviews ${ stamp }`, status: 'draft' },
} );
placeholderPages.push( reviewsPage.data.id );
const reviewsPlaced = await run( `${ ns }/insert`, {
	post_id: reviewsPage.data.id,
	item: 'voyager-open/reviews',
} );
validate( 'reviews as insert saves it', reviewsPlaced.data.markup || '' );
const reviewsPublished = await api( `/wp/v2/pages/${ reviewsPage.data.id }`, {
	method: 'POST',
	body: { status: 'publish' },
} );
const reviewsFront = reviewsPublished.data.link
	? await request( reviewsPublished.data.link )
	: { status: 0, text: '' };
const shownRows = starRows( reviewsFront.text );
step(
	'fitness: insert voyager-open/reviews, publish and fetch it: three star rows, each with five stars hidden from screen readers and exactly one accessible name, matching "Rated 5 out of 5"',
	reviewsPlaced.data.saved === true &&
		reviewsFront.status === 200 &&
		shownRows.length === 3 &&
		shownRows.every(
			( r ) =>
				r.stars === 5 &&
				r.hidden === 5 &&
				r.names.length === 1 &&
				/^Rated 5 out of 5$/.test( r.names[ 0 ] )
		),
	`${
		reviewsPlaced.data.code || `saved ${ reviewsPlaced.data.saved }`
	}; ${ reviewsFront.status }; ${ rowSummary( shownRows ) }`
);
const reviewsReport = await checkMarkup( { post_id: reviewsPage.data.id } );
step(
	'validate passes the page holding the reviews grid as shipped, with no warning',
	clean( reviewsReport ) &&
		( reviewsReport.data.warnings || [] ).length === 0,
	warningSummary( reviewsReport )
);

// Four real reviews in the grid: repeat copies "Review 3" as "Review 4", omit
// drops the fifth star of the four-star review, and copy fills every slot.
// A review's rating, quote, name and detail are claim slots, so their copy is
// text the site owner gave: the object form, with "source": "user".
const fromOwner = ( text ) => ( { text, source: 'user' } );
const reviewCopy = ( count ) =>
	Object.fromEntries( [
		[ 'Heading', 'What our customers say' ],
		...Array.from( { length: count }, ( _, i ) => [
			[
				`Review ${ i + 1 } rating`,
				fromOwner( i === 1 ? 'Rated 4 out of 5' : 'Rated 5 out of 5' ),
			],
			[
				`Review ${ i + 1 } quote`,
				fromOwner(
					`E2E review ${ i + 1 }: the words the customer wrote.`
				),
			],
			[
				`Review ${ i + 1 } name`,
				fromOwner( `E2E customer ${ i + 1 }` ),
			],
			[
				`Review ${ i + 1 } detail`,
				fromOwner( `E2E service ${ i + 1 }` ),
			],
		] ).flat(),
	] );
const fourReviews = await insertServices( 'reviews with a fourth card', {
	item: 'voyager-open/reviews',
	repeat: { 'Review 3': [ 'Review 4' ] },
	omit: [ 'Review 2 star 5' ],
	copy: reviewCopy( 4 ),
} );
const fourReviewsMarkup = fourReviews.placed.data.markup || '';
const fourReviewsLabels = ( fourReviews.placed.data.blocks || [] ).map(
	( b ) => b.label
);
step(
	'insert reviews with a fourth card through repeat and a four-star review through omit, filled by copy',
	fourReviews.placed.status === 200 &&
		fourReviews.placed.data.saved === true &&
		fourReviews.placed.data.validation?.round_trip === true &&
		( fourReviewsMarkup.match( /<!-- wp:voyager-open\/icon /g ) || [] )
			.length === 19 &&
		( fourReviewsMarkup.match( /<!-- wp:quote /g ) || [] ).length === 4 &&
		fourReviewsMarkup.includes( '>Rated 4 out of 5</p>' ) &&
		fourReviewsMarkup.includes( '>E2E customer 4</p>' ) &&
		! fourReviewsMarkup.includes( 'Customer name' ) &&
		! fourReviewsLabels.includes( 'Review 2 star 5' ) &&
		[
			'Review 4',
			'Review 4 star 5',
			'Review 4 rating',
			'Review 4 quote',
			'Review 4 name',
			'Review 4 detail',
		].every( ( name ) => fourReviewsLabels.includes( name ) ),
	fourReviews.placed.data.code ||
		`${ ( fourReviewsMarkup.match( /<!-- wp:voyager-open\/icon /g ) || [] ).length } stars`
);
step(
	'validate passes the page with four reviews, with no warning',
	clean( fourReviews.report ) &&
		( fourReviews.report.data.warnings || [] ).length === 0,
	warningSummary( fourReviews.report )
);

// One large quote, filled by copy.
const oneReview = await insertServices( 'reviews as one large quote', {
	item: 'voyager-open/reviews-single',
	copy: reviewCopy( 1 ),
} );
const oneReviewMarkup = oneReview.placed.data.markup || '';
step(
	'insert the reviews section as one large quote, filled by copy',
	oneReview.placed.status === 200 &&
		oneReview.placed.data.saved === true &&
		( oneReviewMarkup.match( /<!-- wp:voyager-open\/icon /g ) || [] )
			.length === 5 &&
		oneReviewMarkup.includes(
			'>E2E review 1: the words the customer wrote.</p>'
		) &&
		! oneReviewMarkup.includes( 'Customer name' ),
	oneReview.placed.data.code || `saved ${ oneReview.placed.data.saved }`
);
step(
	'validate passes the page with one large quote, with no warning',
	clean( oneReview.report ) &&
		( oneReview.report.data.warnings || [] ).length === 0,
	warningSummary( oneReview.report )
);

// The FAQ in its two layouts. Each question is a core/details block labelled
// "Question <n>" whose summary is the question, holding its answer, a
// paragraph labelled "Question <n> answer". So copy writes the summary,
// repeat adds a question with its answer, and omit drops one with its answer.
const faqLabels = ( numbers ) => [
	'Heading',
	'Intro',
	...numbers.flatMap( ( n ) => [
		`Question ${ n }`,
		`Question ${ n } answer`,
	] ),
];
for ( const [ item, layout ] of [
	[ 'voyager-open/faq', 'list' ],
	[ 'voyager-open/faq-split', 'split' ],
] ) {
	const listed = ( catalog.data.patterns || [] ).find(
		( p ) => p.name === item
	);
	const labels = ( listed?.blocks || [] )
		.filter( ( b ) => b.copy )
		.map( ( b ) => b.label );
	step(
		`get-catalog lists ${ item } (section "faq", layout "${ layout }") with Question 1 to Question 3 and their answers as copy labels`,
		listed?.section === 'faq' &&
			listed?.layout === layout &&
			JSON.stringify( labels ) ===
				JSON.stringify( faqLabels( [ 1, 2, 3 ] ) ) &&
			( listed?.blocks || [] )
				.filter( ( b ) => /^Question \d$/.test( b.label || '' ) )
				.every( ( b ) => b.block === 'core/details' && b.copy ),
		listed ? labels.join( ', ' ) : 'not listed'
	);
}

const faqCopy = {
	Heading: 'Questions about our plumbing',
	'Question 1': 'Do you work on weekends?',
	'Question 1 answer':
		'E2E answer 1: the words the site owner gave for weekend work.',
	'Question 3': 'Can I be there while you work?',
	'Question 3 answer': 'E2E answer 3: the words the site owner gave.',
	'Question 4': 'Do you clean up afterwards?',
	'Question 4 answer': 'E2E answer 4: the words the site owner gave.',
};
// The summaries a page holds, in order, and its details blocks.
const summariesIn = ( markup ) =>
	[ ...markup.matchAll( /<summary>([^<]*)<\/summary>/g ) ].map(
		( m ) => m[ 1 ]
	);
const detailsIn = ( markup ) =>
	( markup.match( /<!-- wp:details /g ) || [] ).length;

// The fitness check: copy for Question 1 and its answer, a fourth question
// through repeat, the second dropped through omit, and the new summary read
// back from the saved page.
const faq = await insertServices(
	'faq with a fourth question and one dropped',
	{
		item: 'voyager-open/faq',
		repeat: { 'Question 3': [ 'Question 4' ] },
		omit: [ 'Question 2' ],
		copy: faqCopy,
	}
);
const faqSaved =
	(
		await api( `/wp/v2/pages/${ faq.postId }`, {
			query: { context: 'edit' },
		} )
	).data.content?.raw ?? '';
validate( 'faq as the page saved it', faqSaved );
const faqBlocks = ( faq.placed.data.blocks || [] ).map( ( b ) => b.label );
step(
	'fitness: insert voyager-open/faq with copy for "Question 1" and "Question 1 answer", a fourth question through repeat and the second dropped through omit; the saved page holds the new summaries in order and none of the second',
	faq.placed.status === 200 &&
		faq.placed.data.saved === true &&
		faq.placed.data.validation?.round_trip === true &&
		detailsIn( faqSaved ) === 3 &&
		JSON.stringify( summariesIn( faqSaved ) ) ===
			JSON.stringify( [
				faqCopy[ 'Question 1' ],
				faqCopy[ 'Question 3' ],
				faqCopy[ 'Question 4' ],
			] ) &&
		faqSaved.includes( `<p>${ faqCopy[ 'Question 1 answer' ] }</p>` ) &&
		faqSaved.includes( `<p>${ faqCopy[ 'Question 4 answer' ] }</p>` ) &&
		! faqSaved.includes( 'Do you charge for a quote?' ) &&
		! faqSaved.includes( 'Question 2' ) &&
		[ 'Question 4', 'Question 4 answer' ].every( ( name ) =>
			faqBlocks.includes( name )
		),
	faq.placed.data.code ||
		`${ detailsIn( faqSaved ) } questions: ${ summariesIn( faqSaved ).join(
			' | '
		) }`
);
step(
	'validate passes the page with the FAQ, with no warning',
	clean( faq.report ) && ( faq.report.data.warnings || [] ).length === 0,
	warningSummary( faq.report )
);

// The split layout, filled by copy: the heading column beside the questions.
const faqSplit = await insertServices( 'faq beside its heading', {
	item: 'voyager-open/faq-split',
	copy: {
		Heading: 'Questions about our plumbing',
		'Question 2': 'Do you give a written quote?',
		'Question 2 answer': 'E2E answer 2: the words the site owner gave.',
	},
} );
const faqSplitMarkup = faqSplit.placed.data.markup || '';
step(
	'insert the FAQ beside its heading, filled by copy: one row of two columns, the heading first, three questions',
	faqSplit.placed.status === 200 &&
		faqSplit.placed.data.saved === true &&
		( faqSplitMarkup.match( /<!-- wp:column[ {]/g ) || [] ).length === 2 &&
		faqSplitMarkup.indexOf( 'Questions about our plumbing' ) <
			faqSplitMarkup.indexOf( '<summary>' ) &&
		detailsIn( faqSplitMarkup ) === 3 &&
		summariesIn( faqSplitMarkup )[ 1 ] === 'Do you give a written quote?',
	faqSplit.placed.data.code || summariesIn( faqSplitMarkup ).join( ' | ' )
);
step(
	'validate passes the page with the FAQ beside its heading, with no warning',
	clean( faqSplit.report ) &&
		( faqSplit.report.data.warnings || [] ).length === 0,
	warningSummary( faqSplit.report )
);

// A question may be left out with its answer, never its answer alone.
const answerAlone = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/faq',
	dry_run: true,
	omit: [ 'Question 2 answer' ],
} );
step(
	'insert refuses omit of an answer without its question, as required',
	answerAlone.status === 400 &&
		answerAlone.data.code === 'voyager_open_blocks_copy_rules' &&
		JSON.stringify(
			( answerAlone.data.data?.refused || [] ).map(
				( r ) => `${ r.slot } ${ r.reason }`
			)
		) === JSON.stringify( [ 'Question 2 answer required' ] ),
	answerAlone.data.message || answerAlone.data.code
);

// Copy rules (contract 3). get-catalog gives each pattern its section, its
// layout and its copy_rules, read from its intent. insert refuses copy and
// omit that break them before anything is saved, dry run or not, and lists
// every refused slot in data.refused.
const listedPattern = ( name ) =>
	( catalog.data.patterns || [] ).find( ( p ) => p.name === name );
const heroListed = listedPattern( 'voyager-open/hero' );
const bandListed = listedPattern( 'voyager-open/cta-band' );
const bandIntent = JSON.parse(
	readFileSync( join( repo, 'patterns', 'intents', 'cta-band.json' ), 'utf8' )
);
step(
	'get-catalog lists voyager-open/hero with section "hero", layout "split" and copy_rules.Heading.max 60',
	heroListed?.section === 'hero' &&
		heroListed?.layout === 'split' &&
		heroListed?.copy_rules?.Heading?.max === 60,
	heroListed
		? JSON.stringify( {
				section: heroListed.section,
				layout: heroListed.layout,
				Heading: heroListed.copy_rules?.Heading,
			} )
		: 'not listed'
);
step(
	'get-catalog lists voyager-open/cta-band with section and layout null, and copy_rules as its intent holds them, as an object',
	bandListed?.section === null &&
		bandListed?.layout === null &&
		!! bandListed?.copy_rules &&
		typeof bandListed.copy_rules === 'object' &&
		! Array.isArray( bandListed.copy_rules ) &&
		JSON.stringify( bandListed.copy_rules ) ===
			JSON.stringify( bandIntent.copy_rules || {} ),
	bandListed
		? JSON.stringify( {
				section: bandListed.section,
				layout: bandListed.layout,
				copy_rules: bandListed.copy_rules,
			} )
		: 'not listed'
);

// One draft that holds a paragraph, so "unchanged" compares real content.
const rulesPage = await api( '/wp/v2/pages', {
	method: 'POST',
	body: {
		title: `E2E: copy rules ${ stamp }`,
		status: 'draft',
		content:
			'<!-- wp:paragraph -->\n<p>Kept as it was.</p>\n<!-- /wp:paragraph -->',
	},
} );
placeholderPages.push( rulesPage.data.id );
const rulesContent = async () =>
	(
		await api( `/wp/v2/pages/${ rulesPage.data.id }`, {
			query: { context: 'edit' },
		} )
	).data.content?.raw ?? null;
const refusedRows = ( response ) => response.data.data?.refused || [];
const refusedAs = ( response ) =>
	refusedRows( response ).map( ( r ) => `${ r.slot } ${ r.reason }` );
const refusalSummary = ( response ) =>
	response.data.code
		? `${ response.status } ${ response.data.code }: ${ refusedAs(
				response
			).join( ', ' ) }`
		: `${ response.status }, saved ${ response.data.saved }`;
const insertInto = ( input ) =>
	run( `${ ns }/insert`, { post_id: rulesPage.data.id, ...input } );
const beforeRules = await rulesContent();

// The fitness check: the hero's Heading allows 60 characters.
const longHeading =
	'Same-day plumbing repairs for every home and business in town';
const tooLong = await insertInto( {
	item: 'voyager-open/hero',
	copy: { Heading: longHeading },
} );
step(
	'fitness: insert voyager-open/hero with a 61-character Heading is refused with a 400 whose data.refused holds { slot: "Heading", reason: "too_long" }, and the page is unchanged',
	longHeading.length === 61 &&
		tooLong.status === 400 &&
		tooLong.data.code === 'voyager_open_blocks_copy_rules' &&
		refusedRows( tooLong ).some(
			( r ) =>
				r.slot === 'Heading' &&
				r.reason === 'too_long' &&
				r.max === 60 &&
				r.length === 61
		) &&
		!! beforeRules &&
		( await rulesContent() ) === beforeRules,
	refusalSummary( tooLong )
);

// A review's quote is a claim: the site owner's words, never the agent's.
const ownerQuote = 'They came the same day and fixed the leak for good.';
const plainClaim = await insertInto( {
	item: 'voyager-open/reviews',
	copy: { 'Review 1 quote': ownerQuote },
} );
step(
	'insert of voyager-open/reviews with a plain string for "Review 1 quote" is refused with reason claim, and the page is unchanged',
	plainClaim.status === 400 &&
		plainClaim.data.code === 'voyager_open_blocks_copy_rules' &&
		JSON.stringify( refusedAs( plainClaim ) ) ===
			JSON.stringify( [ 'Review 1 quote claim' ] ) &&
		( refusedRows( plainClaim )[ 0 ]?.message || '' ).includes(
			'"source": "user"'
		) &&
		( await rulesContent() ) === beforeRules,
	refusalSummary( plainClaim )
);

// A review repeat adds keeps the rule of the one it copies, so it is a claim too.
const repeatedClaim = await insertInto( {
	item: 'voyager-open/reviews',
	repeat: { 'Review 3': [ 'Review 4' ] },
	copy: { 'Review 4 quote': ownerQuote },
	dry_run: true,
} );
step(
	'insert of voyager-open/reviews with a fourth review through repeat refuses a plain string for "Review 4 quote" with reason claim',
	repeatedClaim.status === 400 &&
		JSON.stringify( refusedAs( repeatedClaim ) ) ===
			JSON.stringify( [ 'Review 4 quote claim' ] ) &&
		( await rulesContent() ) === beforeRules,
	refusalSummary( repeatedClaim )
);

const omitHeading = await insertInto( {
	item: 'voyager-open/hero',
	omit: [ 'Heading' ],
} );
step(
	'insert of voyager-open/hero with omit ["Heading"] is refused with reason required, and the page is unchanged',
	omitHeading.status === 400 &&
		omitHeading.data.code === 'voyager_open_blocks_copy_rules' &&
		JSON.stringify( refusedAs( omitHeading ) ) ===
			JSON.stringify( [ 'Heading required' ] ) &&
		( await rulesContent() ) === beforeRules,
	refusalSummary( omitHeading )
);

// Three faults at once: too long, a bound slot and a required slot left out.
// The bound slot comes first, and keeps its own row shape.
const threeFaults = {
	item: 'voyager-open/hero',
	copy: { Heading: longHeading, 'Click to call': '(555) 555-0113' },
	omit: [ 'Body' ],
};
const three = await insertInto( threeFaults );
const threeTrial = await insertInto( { ...threeFaults, dry_run: true } );
const threeRows = [
	'Click to call bound',
	'Heading too_long',
	'Body required',
];
step(
	'one insert with three faults (a 61-character Heading, copy for the bound "Click to call", omit ["Body"]) is refused with all three in data.refused, and the page is unchanged',
	three.status === 400 &&
		three.data.code === 'voyager_open_blocks_copy_rules' &&
		JSON.stringify( refusedAs( three ) ) === JSON.stringify( threeRows ) &&
		refusedRows( three )[ 0 ]?.key === 'phone' &&
		( await rulesContent() ) === beforeRules,
	refusalSummary( three )
);
step(
	'with dry_run, the same three faults are refused the same way, and the page is unchanged',
	threeTrial.status === 400 &&
		threeTrial.data.code === 'voyager_open_blocks_copy_rules' &&
		JSON.stringify( refusedRows( threeTrial ) ) ===
			JSON.stringify( refusedRows( three ) ) &&
		( await rulesContent() ) === beforeRules,
	refusalSummary( threeTrial )
);

// The object form takes text and source "user", and nothing else.
for ( const [ label, value ] of [
	[ 'a source other than "user"', { text: ownerQuote, source: 'agent' } ],
	[
		'a key other than text and source',
		{ text: ownerQuote, source: 'user', verified: true },
	],
	[ 'an empty text', { text: '', source: 'user' } ],
] ) {
	const refused = await insertInto( {
		item: 'voyager-open/reviews',
		copy: { 'Review 1 quote': value },
		dry_run: true,
	} );
	step(
		`insert refuses a copy value with ${ label }`,
		refused.status === 400 && ( await rulesContent() ) === beforeRules,
		`${ refused.status } ${ refused.data.code || '' }`
	);
}

// Last, as it saves: the same quote as text the site owner gave.
const ownerClaim = await insertInto( {
	item: 'voyager-open/reviews',
	copy: { 'Review 1 quote': { text: ownerQuote, source: 'user' } },
} );
step(
	'the same quote as { text, source: "user" } saves, and the page holds it',
	ownerClaim.status === 200 &&
		ownerClaim.data.saved === true &&
		( ownerClaim.data.markup || '' ).includes( ownerQuote ) &&
		( ( await rulesContent() ) || '' ).includes( ownerQuote ),
	ownerClaim.data.code || `saved ${ ownerClaim.data.saved }`
);

// A cover whose background is the pack's 16:9 placeholder at the plugin's
// own URL. The URL comes from the site: the folder of the first logo as
// insert saved the marquee, with the file name swapped. A cover's
// background is decoration, so it never gets missing_alt.
const marqueeSaved = await api( `/wp/v2/pages/${ placeholderPages[ 0 ] }`, {
	query: { context: 'edit' },
} );
const assetsUrl =
	( marqueeSaved.data.content?.raw || '' ).match(
		/src="([^"]*\/assets\/)placeholders\/logo-1\.svg"/
	)?.[ 1 ] || '';
const background = `${ assetsUrl }placeholders/image-16x9.svg`;
const served = assetsUrl ? await request( background ) : null;
step(
	'the 16:9 placeholder is served at the plugin URL the patterns insert, as an SVG',
	served?.status === 200 &&
		/^image\/svg\+xml\b/.test( served.headers[ 'content-type' ] || '' ) &&
		served.text.includes( 'viewBox="0 0 1600 900"' ),
	served
		? `${ background }: ${ served.status }, ${ served.headers[ 'content-type' ] }`
		: 'no assets URL in the saved logo marquee'
);
const cover = ( url ) =>
	`<!-- wp:cover {"url":"${ url }","dimRatio":60,"overlayColor":"contrast","isUserOverlayColor":true,"focalPoint":{"x":0.5,"y":0.5},"metadata":{"name":"Background"},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-60 has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="${ url }" style="object-position:50% 50%" data-object-fit="cover" data-object-position="50% 50%"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->
<p>Text over the background</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->`;
const onPlaceholder = await checkMarkup( { markup: cover( background ) } );
const coverWarnings = onPlaceholder.data.warnings || [];
step(
	'validate warns placeholder once, and nothing else, on a cover whose background is the 16:9 placeholder at the plugin URL, and it stays valid',
	!! assetsUrl &&
		clean( onPlaceholder ) &&
		coverWarnings.length === 1 &&
		coverWarnings[ 0 ].startsWith(
			'placeholder: core/cover "Background" at 0 shows the pack\'s placeholder image placeholders/image-16x9.svg as its background.'
		) &&
		codedWarnings( onPlaceholder, 'missing_alt' ).length === 0,
	`${ background }: ${ warningSummary( onPlaceholder ) }`
);
const onOwnImage = await checkMarkup( {
	markup: cover( 'https://example.com/wp-content/uploads/hero.jpg' ),
} );
step(
	"validate warns nothing on the same cover over the site's own image, with its empty alt",
	clean( onOwnImage ) && ( onOwnImage.data.warnings || [] ).length === 0,
	warningSummary( onOwnImage )
);

const photo = ( alt ) =>
	`<!-- wp:image -->\n<figure class="wp-block-image"><img src="https://example.com/wp-content/uploads/team.jpg" alt="${ alt }"/></figure>\n<!-- /wp:image -->`;
const noAlt = await checkMarkup( { markup: photo( '' ) } );
step(
	'validate warns missing_alt once on an image with empty alt outside the placeholders, and it stays valid',
	clean( noAlt ) &&
		codedWarnings( noAlt, 'missing_alt' ).length === 1 &&
		codedWarnings( noAlt, 'missing_alt' )[ 0 ].startsWith(
			'missing_alt: core/image at 0 '
		) &&
		codedWarnings( noAlt, 'placeholder' ).length === 0,
	warningSummary( noAlt )
);
const withAlt = await checkMarkup( {
	markup: photo( 'Our team outside the clinic' ),
} );
step(
	'validate warns nothing on the same image with alt text',
	clean( withAlt ) && ( withAlt.data.warnings || [] ).length === 0,
	warningSummary( withAlt )
);

for ( const id of placeholderPages ) {
	await api( `/wp/v2/pages/${ id }`, {
		method: 'DELETE',
		query: { force: true },
	} );
}

const neither = await checkMarkup( {} );
step(
	'validate refuses input with neither markup nor post_id',
	neither.status === 400 &&
		neither.data.code === 'voyager_open_blocks_invalid_markup',
	neither.data.code
);

const tree = await run( `${ ns }/get-post-blocks`, { post_id: postId }, true );
step(
	'get-post-blocks',
	tree.status === 200 && ( tree.data.blocks || [] ).length > 0,
	`${ ( tree.data.blocks || [] ).length } blocks`
);

const treeCall = ( tree.data.blocks || [] ).find(
	( b ) => b.label === 'Click to call'
);
const treeResolution = treeCall?.bindings?.url?.resolution;
step(
	'get-post-blocks reports the CTA band phone_link binding as resolved',
	treeResolution?.status === 'resolved' &&
		treeResolution.reason === null &&
		( treeResolution.value_preview || '' ).startsWith( 'tel:' ),
	JSON.stringify( treeResolution ?? null )
);

// 6. Rendered output.
const html = ( await request( saved.data.link ) ).text;
const telLinks = html.match( /href="tel:[^"]+"/g ) || [];
step(
	'rendered page carries bound tel: links (button and icon)',
	telLinks.length >= 2,
	telLinks.join( ' ' )
);

// 6b. Trial runs and undo, on pages of their own so the step 2 page is left
// as it was. Every save returns revision_id, the revision WordPress made for
// it, and undo with that ID restores the content the save replaced, byte for
// byte. A page created over REST has no revision until its first update, so
// the first undo also shows the abilities store the state before their save.
// The block keeps its names to itself; it deletes what it creates.
{
	const contentOf = async ( route, as = auth ) =>
		( await api( route, { query: { context: 'edit' }, as } ) ).data.content
			?.raw ?? null;
	const revisionOf = ( response ) => response.data.revision_id;
	const startingContent =
		'<!-- wp:paragraph -->\n<p>Before the insert: Smith &amp; Sons, "since 1998".</p>\n<!-- /wp:paragraph -->';

	const undoPage = await api( '/wp/v2/pages', {
		method: 'POST',
		body: {
			title: `E2E: undo ${ stamp }`,
			status: 'publish',
			content: startingContent,
		},
	} );
	const undoId = undoPage.data.id;
	const undoRoute = `/wp/v2/pages/${ undoId }`;
	const beforeInsert = await contentOf( undoRoute );
	step(
		'undo: page created',
		undoPage.status === 201 && !! beforeInsert,
		undoPage.data.code || `id ${ undoId }`
	);

	// Fitness, part 1: insert, then undo with the revision_id insert returned.
	const inserted = await run( `${ ns }/insert`, {
		post_id: undoId,
		item: 'voyager-open/icon',
		attributes: { icon: 'phone', size: 32, label: 'Call us' },
	} );
	const afterInsert = await contentOf( undoRoute );
	step(
		'insert returns revision_id, the revision WordPress made for its save',
		inserted.status === 200 &&
			inserted.data.saved === true &&
			Number.isInteger( revisionOf( inserted ) ) &&
			afterInsert !== beforeInsert,
		inserted.data.message || `revision ${ revisionOf( inserted ) }`
	);

	const undone = await run( `${ ns }/undo`, {
		post_id: undoId,
		revision_id: revisionOf( inserted ),
	} );
	const afterUndo = await contentOf( undoRoute );
	step(
		"undo with insert's revision_id restores the page's post_content byte for byte",
		undone.status === 200 &&
			undone.data.matches === true &&
			Number.isInteger( revisionOf( undone ) ) &&
			!! beforeInsert &&
			afterUndo === beforeInsert,
		undone.data.message ||
			`restored revision ${ undone.data.restored }: ${ JSON.stringify( afterUndo ) }`
	);

	const redone = await run( `${ ns }/undo`, {
		post_id: undoId,
		revision_id: revisionOf( undone ),
	} );
	const afterRedo = await contentOf( undoRoute );
	step(
		"undo with undo's own revision_id puts the insert back",
		redone.status === 200 && !! afterInsert && afterRedo === afterInsert,
		redone.data.message || `restored revision ${ redone.data.restored }`
	);

	// Fitness, part 2: configure and bind with dry_run return what they would
	// save and save nothing.
	const iconAt = inserted.data.blocks?.[ 0 ]?.path;
	const trialConfigure = await run( `${ ns }/configure`, {
		post_id: undoId,
		path: iconAt,
		attributes: { size: 48 },
		dry_run: true,
	} );
	const afterTrialConfigure = await contentOf( undoRoute );
	step(
		'configure with dry_run returns the would-be markup and leaves post_content unchanged',
		trialConfigure.status === 200 &&
			trialConfigure.data.saved === false &&
			trialConfigure.data.revision_id === null &&
			( trialConfigure.data.markup || '' ).includes( '"size":48' ) &&
			!! afterInsert &&
			afterTrialConfigure === afterInsert,
		trialConfigure.data.message || trialConfigure.data.markup
	);

	const trialBind = await run( `${ ns }/bind`, {
		post_id: undoId,
		path: iconAt,
		attribute: 'linkUrl',
		source: 'voyager/site-data',
		args: field,
		dry_run: true,
	} );
	const afterTrialBind = await contentOf( undoRoute );
	step(
		'bind with dry_run returns the would-be markup and leaves post_content unchanged',
		trialBind.status === 200 &&
			trialBind.data.saved === false &&
			trialBind.data.revision_id === null &&
			( trialBind.data.markup || '' ).includes( '"key":"phone_link"' ) &&
			!! afterInsert &&
			afterTrialBind === afterInsert,
		trialBind.data.message || trialBind.data.markup
	);

	const configuredSave = await run( `${ ns }/configure`, {
		post_id: undoId,
		path: iconAt,
		attributes: { size: 48 },
	} );
	const afterConfigure = await contentOf( undoRoute );
	step(
		'configure returns revision_id when it saves',
		configuredSave.status === 200 &&
			configuredSave.data.saved === true &&
			Number.isInteger( revisionOf( configuredSave ) ) &&
			revisionOf( configuredSave ) !== revisionOf( inserted ) &&
			( afterConfigure || '' ).includes( '"size":48' ),
		configuredSave.data.message ||
			`revision ${ revisionOf( configuredSave ) }`
	);

	// A save that changes nothing makes no revision, and says so rather than
	// naming an older save that undo would then reverse.
	const sameAgain = await run( `${ ns }/configure`, {
		post_id: undoId,
		path: iconAt,
		attributes: { size: 48 },
	} );
	step(
		'configure that changes nothing returns revision_id null and a revision_note',
		sameAgain.status === 200 &&
			sameAgain.data.revision_id === null &&
			!! sameAgain.data.revision_note &&
			( await contentOf( undoRoute ) ) === afterConfigure,
		sameAgain.data.message || sameAgain.data.revision_note
	);

	const boundSave = await run( `${ ns }/bind`, {
		post_id: undoId,
		path: iconAt,
		attribute: 'linkUrl',
		source: 'voyager/site-data',
		args: field,
	} );
	const afterBind = await contentOf( undoRoute );
	step(
		'bind returns revision_id when it saves',
		boundSave.status === 200 &&
			boundSave.data.saved === true &&
			Number.isInteger( revisionOf( boundSave ) ) &&
			( afterBind || '' ).includes( '"key":"phone_link"' ),
		boundSave.data.message || `revision ${ revisionOf( boundSave ) }`
	);

	// A trial run is refused for a bound slot exactly as a save is.
	const trialBound = await run( `${ ns }/configure`, {
		post_id: undoId,
		path: iconAt,
		attributes: { linkUrl: 'tel:+15555550113' },
		dry_run: true,
	} );
	step(
		"configure with dry_run is refused for the icon's bound linkUrl with voyager_open_blocks_bound_slot, and saves nothing",
		trialBound.status === 400 &&
			trialBound.data.code === 'voyager_open_blocks_bound_slot' &&
			( await contentOf( undoRoute ) ) === afterBind,
		trialBound.data.message || trialBound.data.code
	);

	const trialCopy = await run( `${ ns }/insert`, {
		post_id: undoId,
		item: 'voyager-open/cta-band',
		copy: { 'Click to call': '(555) 555-0113' },
		dry_run: true,
	} );
	step(
		'insert with dry_run is refused for copy aimed at the bound "Click to call" with voyager_open_blocks_bound_slot, and saves nothing',
		trialCopy.status === 400 &&
			trialCopy.data.code === 'voyager_open_blocks_bound_slot' &&
			( await contentOf( undoRoute ) ) === afterBind,
		trialCopy.data.message || trialCopy.data.code
	);

	const unbound = await run( `${ ns }/undo`, {
		post_id: undoId,
		revision_id: revisionOf( boundSave ),
	} );
	step(
		"undo with bind's revision_id restores the content configure left",
		unbound.status === 200 &&
			unbound.data.matches === true &&
			!! afterConfigure &&
			( await contentOf( undoRoute ) ) === afterConfigure,
		unbound.data.message || `restored revision ${ unbound.data.restored }`
	);

	// Another post's revision is refused, even one the user may edit.
	const otherPage = await api( '/wp/v2/pages', {
		method: 'POST',
		body: {
			title: `E2E: undo other ${ stamp }`,
			status: 'draft',
			content: startingContent,
		},
	} );
	const otherInsert = await run( `${ ns }/insert`, {
		post_id: otherPage.data.id,
		item: 'voyager-open/icon',
		attributes: { icon: 'phone' },
	} );
	const foreign = await run( `${ ns }/undo`, {
		post_id: undoId,
		revision_id: revisionOf( otherInsert ),
	} );
	step(
		'undo refuses a revision of another post with voyager_open_blocks_invalid_revision, and changes nothing',
		Number.isInteger( revisionOf( otherInsert ) ) &&
			foreign.status === 400 &&
			foreign.data.code === 'voyager_open_blocks_invalid_revision' &&
			( await contentOf( undoRoute ) ) === afterConfigure,
		foreign.data.message || foreign.data.code
	);

	// A contributor cannot undo on a page they cannot edit. The same
	// contributor can undo their own insert on their own draft, so the refusal
	// is about the page, not the ability.
	const writer = await api( '/wp/v2/users', {
		method: 'POST',
		body: {
			username: `voyager-open-e2e-undo-${ stamp }`,
			email: `voyager-open-e2e-undo-${ stamp }@example.com`,
			password: `${ stamp }-${ Math.random().toString( 36 ).slice( 2 ) }`,
			roles: [ 'contributor' ],
		},
	} );
	const writerKey = writer.data.id
		? await api( `/wp/v2/users/${ writer.data.id }/application-passwords`, {
				method: 'POST',
				body: { name: 'e2e' },
			} )
		: { data: {} };
	const asWriter =
		'Basic ' +
		Buffer.from(
			`${ writer.data.username }:${ writerKey.data.password }`
		).toString( 'base64' );

	const writerUndo = await run(
		`${ ns }/undo`,
		{ post_id: undoId, revision_id: revisionOf( configuredSave ) },
		false,
		asWriter
	);
	step(
		'undo refuses a contributor on a page they cannot edit, and changes nothing',
		writer.status === 201 &&
			!! writerKey.data.password &&
			writerUndo.status === 403 &&
			( await contentOf( undoRoute ) ) === afterConfigure,
		writerUndo.data.code || `status ${ writerUndo.status }`
	);

	const draft = await api( '/wp/v2/posts', {
		method: 'POST',
		body: {
			title: `E2E: contributor undo ${ stamp }`,
			status: 'draft',
			content: startingContent,
		},
		as: asWriter,
	} );
	const draftRoute = `/wp/v2/posts/${ draft.data.id }`;
	const draftBefore = await contentOf( draftRoute, asWriter );
	const draftInsert = await run(
		`${ ns }/insert`,
		{
			post_id: draft.data.id,
			item: 'voyager-open/icon',
			attributes: { icon: 'phone' },
		},
		false,
		asWriter
	);
	const draftUndo = await run(
		`${ ns }/undo`,
		{ post_id: draft.data.id, revision_id: revisionOf( draftInsert ) },
		false,
		asWriter
	);
	step(
		'a contributor can insert into their own draft and undo it, byte for byte',
		draft.status === 201 &&
			draftInsert.status === 200 &&
			Number.isInteger( revisionOf( draftInsert ) ) &&
			draftUndo.status === 200 &&
			!! draftBefore &&
			( await contentOf( draftRoute, asWriter ) ) === draftBefore,
		draftUndo.data.message ||
			draftInsert.data.message ||
			`status ${ draftInsert.status } then ${ draftUndo.status }`
	);

	// Undo never reverses a save it was not given without saying so. A person
	// edits the page after the agent's save, through the REST route the editor
	// saves with: undo with the agent's revision_id is refused and names that
	// edit, force reverses both, and undoing the forced undo brings the
	// person's edit back.
	const clashPage = await api( '/wp/v2/pages', {
		method: 'POST',
		body: {
			title: `E2E: undo conflict ${ stamp }`,
			status: 'publish',
			content: startingContent,
		},
	} );
	const clashId = clashPage.data.id;
	const clashRoute = `/wp/v2/pages/${ clashId }`;
	const clashBefore = await contentOf( clashRoute );
	const agentSave = await run( `${ ns }/insert`, {
		post_id: clashId,
		item: 'voyager-open/icon',
		attributes: { icon: 'phone', label: 'Call us' },
	} );
	const afterAgent = await contentOf( clashRoute );
	const personEdit = await api( clashRoute, {
		method: 'POST',
		body: {
			content: `${ afterAgent }\n\n<!-- wp:paragraph -->\n<p>Added by a person in the editor.</p>\n<!-- /wp:paragraph -->`,
		},
	} );
	const afterPerson = await contentOf( clashRoute );
	const clashRevisions = await api( `${ clashRoute }/revisions`, {
		query: { per_page: 100, context: 'edit' },
	} );
	const personRevision = Math.max(
		...( Array.isArray( clashRevisions.data )
			? clashRevisions.data
			: []
		).map( ( revision ) => revision.id )
	);

	const clash = await run( `${ ns }/undo`, {
		post_id: clashId,
		revision_id: revisionOf( agentSave ),
	} );
	const later = clash.data.data?.later || [];
	step(
		'undo refuses when the post was saved after revision_id with voyager_open_blocks_undo_conflict, names that save, and changes nothing',
		Number.isInteger( revisionOf( agentSave ) ) &&
			personEdit.status === 200 &&
			!! afterAgent &&
			afterPerson !== afterAgent &&
			clash.status === 409 &&
			clash.data.code === 'voyager_open_blocks_undo_conflict' &&
			later.length === 1 &&
			later[ 0 ].revision_id === personRevision &&
			!! later[ 0 ].author &&
			!! later[ 0 ].date_gmt &&
			( await contentOf( clashRoute ) ) === afterPerson,
		clash.data.message || JSON.stringify( clash.data )
	);

	const forced = await run( `${ ns }/undo`, {
		post_id: clashId,
		revision_id: revisionOf( agentSave ),
		force: true,
	} );
	const reversedIds = ( forced.data.reversed || [] ).map(
		( row ) => row.revision_id
	);
	step(
		"undo with force reverses the agent's save and the person's, and lists both",
		forced.status === 200 &&
			forced.data.matches === true &&
			!! clashBefore &&
			( await contentOf( clashRoute ) ) === clashBefore &&
			JSON.stringify( reversedIds ) ===
				JSON.stringify( [ revisionOf( agentSave ), personRevision ] ),
		forced.data.message || `reversed ${ JSON.stringify( reversedIds ) }`
	);

	const unforced = await run( `${ ns }/undo`, {
		post_id: clashId,
		revision_id: revisionOf( forced ),
	} );
	step(
		"undo with the forced undo's revision_id brings the person's edit back",
		unforced.status === 200 &&
			!! afterPerson &&
			( await contentOf( clashRoute ) ) === afterPerson,
		unforced.data.message || `restored revision ${ unforced.data.restored }`
	);

	for ( const route of [
		undoId && undoRoute,
		otherPage.data.id && `/wp/v2/pages/${ otherPage.data.id }`,
		draft.data.id && draftRoute,
		clashId && clashRoute,
	] ) {
		if ( route ) {
			await api( route, { method: 'DELETE', query: { force: true } } );
		}
	}
	if ( writer.data.id ) {
		const admin = await api( '/wp/v2/users/me' );
		await api( `/wp/v2/users/${ writer.data.id }`, {
			method: 'DELETE',
			query: { force: true, reassign: admin.data.id },
		} );
	}
}

// 7. The icon prompt, end to end: "Add a phone icon next to the call button
// in my header, linked to my Site Data phone number." The header is a
// template part with a post of its own, as one saved in the Site Editor is.
const cleanup = [];
const partSlug = `voyager-open-e2e-header-${ stamp }`;
const part = await api( '/wp/v2/template-parts', {
	method: 'POST',
	body: {
		slug: partSlug,
		title: `E2E header ${ stamp }`,
		area: 'header',
		content:
			'<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Example Co.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button -->\n<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="tel:+15550100">Call us</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons --></div>\n<!-- /wp:group -->',
	},
} );
const partId = part.data.wp_id;
if ( part.data.id ) {
	cleanup.push( `/wp/v2/template-parts/${ part.data.id }` );
}
step(
	'header template part created',
	part.status === 201 && partId > 0,
	part.data.code || `post ${ partId }`
);

const foundPart = await run(
	`${ ns }/find-posts`,
	{ post_type: 'wp_template_part', search: `header ${ stamp }` },
	true
);
const partRow = foundPart.data.posts?.[ 0 ];
step(
	'find-posts finds the header by words in its title',
	foundPart.status === 200 &&
		partRow?.id === partId &&
		partRow.area === 'header' &&
		partRow.source === 'custom',
	foundPart.data.code || JSON.stringify( partRow ?? null )
);

const partTree = await run(
	`${ ns }/get-post-blocks`,
	{ post_id: partId },
	true
);
const partCall = ( partTree.data.blocks || [] ).find(
	( b ) => b.block === 'core/button' && b.text === 'Call us'
);
step(
	'get-post-blocks shows the call button by its text',
	partTree.status === 200 && !! partCall,
	partCall?.path
);

// The icon goes beside the buttons block, in the row that holds it: inside
// core/buttons only buttons belong.
const partButtons = parentOf( partCall?.path || '' );
const partIcon = await run( `${ ns }/insert`, {
	post_id: partId,
	item: 'voyager-open/icon',
	parent: parentOf( partButtons ),
	position: Number( partButtons.split( '/' ).pop() ),
	attributes: { icon: 'phone', size: 24, label: 'Call us' },
} );
const partIconPath = partIcon.data.blocks?.[ 0 ]?.path;
step(
	"insert the phone icon beside the header's call button",
	partIcon.status === 200 &&
		partIcon.data.saved === true &&
		partIconPath === partButtons,
	partIcon.data.message || `icon at ${ partIconPath }`
);
validate( 'header with the icon', partIcon.data.section_markup || '' );

const partBound = await run( `${ ns }/bind`, {
	post_id: partId,
	path: partIconPath,
	attribute: 'linkUrl',
	source: 'voyager/site-data',
	args: field,
} );
step(
	'bind the header icon to Site Data phone_link',
	partBound.status === 200,
	partBound.data.message ||
		JSON.stringify( partBound.data.attributes?.metadata?.bindings )
);

const partAfter = await run(
	`${ ns }/get-post-blocks`,
	{ post_id: partId },
	true
);
const partLink = ( partAfter.data.blocks || [] ).find(
	( b ) => b.path === partIconPath
)?.bindings?.linkUrl?.resolution;
step(
	'the header icon link resolves to a tel: link',
	partLink?.status === 'resolved' &&
		( partLink.value_preview || '' ).startsWith( 'tel:' ),
	JSON.stringify( partLink ?? null )
);

const partReport = await checkMarkup( { post_id: partId } );
step(
	'validate passes the saved header',
	clean( partReport ),
	( partReport.data.errors || [] ).join( ' ' ) || partReport.data.code
);

// 8. Post meta in a template: bind checks args against the post type the
// template shows, given as post_type, not against wp_template.
const template = await api( '/wp/v2/templates', {
	method: 'POST',
	body: {
		slug: `voyager-open-e2e-single-${ stamp }`,
		title: `E2E single ${ stamp }`,
		content:
			'<!-- wp:paragraph -->\n<p>Subtitle</p>\n<!-- /wp:paragraph -->',
	},
} );
const templateId = template.data.wp_id;
if ( template.data.id ) {
	cleanup.push( `/wp/v2/templates/${ template.data.id }` );
}
step(
	'template created',
	template.status === 201 && templateId > 0,
	template.data.code || `post ${ templateId }`
);

const postFields = await run(
	`${ ns }/get-catalog`,
	{ post_type: 'post' },
	true
);
const templateFields = await run(
	`${ ns }/get-catalog`,
	{ post_type: 'wp_template' },
	true
);
const listed = ( catalogResponse, source ) =>
	(
		( catalogResponse.data.sources || [] ).find(
			( x ) => x.name === source
		)?.fields || []
	).map( ( f ) => JSON.stringify( f.args ) );
let metaBinding = null;
for ( const source of [ 'voyager/post-meta-text', 'core/post-meta' ] ) {
	const forTemplate = listed( templateFields, source );
	const forPosts = listed( postFields, source ).filter(
		( args ) => ! forTemplate.includes( args )
	);
	if ( forPosts.length ) {
		metaBinding = { source, args: JSON.parse( forPosts[ 0 ] ) };
		break;
	}
}
step(
	'get-catalog lists a post meta field for posts that templates do not have',
	!! metaBinding,
	JSON.stringify( metaBinding )
);

const bindInTemplate = ( extra ) =>
	run( `${ ns }/bind`, {
		post_id: templateId,
		path: '0',
		attribute: 'content',
		...metaBinding,
		...extra,
	} );
const withoutType = await bindInTemplate( {} );
step(
	'bind refuses post meta in a template without post_type, and says to pass it',
	withoutType.status === 400 &&
		withoutType.data.code === 'voyager_open_blocks_invalid_args' &&
		( withoutType.data.message || '' ).includes( 'post_type' ),
	withoutType.data.message
);

const withType = await bindInTemplate( { post_type: 'post' } );
step(
	'bind accepts post meta in a template with post_type "post"',
	withType.status === 200,
	withType.data.message ||
		JSON.stringify( withType.data.attributes?.metadata?.bindings )
);

const unknownType = await bindInTemplate( { post_type: 'not_a_post_type' } );
step(
	'bind refuses a post_type that does not exist',
	unknownType.status === 400 &&
		unknownType.data.code === 'voyager_open_blocks_invalid_post_type',
	unknownType.data.code
);

const templateReport = await checkMarkup( {
	post_id: templateId,
	post_type: 'post',
} );
const templateAsTemplate = await checkMarkup( { post_id: templateId } );
step(
	'validate passes the template for posts, and not for wp_template',
	clean( templateReport ) && templateAsTemplate.data.valid === false,
	( templateReport.data.errors || [] ).join( ' ' ) || templateReport.data.code
);

// 9. find-posts as a contributor: their own draft is listed, an
// administrator's private post is not, and pages (which they cannot edit) are refused.
const me = await api( '/wp/v2/users/me' );
const privatePost = await api( '/wp/v2/posts', {
	method: 'POST',
	body: { title: `E2E private ${ stamp }`, status: 'private' },
} );
const contributor = await api( '/wp/v2/users', {
	method: 'POST',
	body: {
		username: `voyager-open-e2e-${ stamp }`,
		email: `voyager-open-e2e-${ stamp }@example.com`,
		password: `${ stamp }-${ Math.random().toString( 36 ).slice( 2 ) }`,
		roles: [ 'contributor' ],
	},
} );
const contributorKey = contributor.data.id
	? await api(
			`/wp/v2/users/${ contributor.data.id }/application-passwords`,
			{ method: 'POST', body: { name: 'e2e' } }
		)
	: { data: {} };
const asContributor =
	'Basic ' +
	Buffer.from(
		`${ contributor.data.username }:${ contributorKey.data.password }`
	).toString( 'base64' );
step(
	'contributor and private post created',
	privatePost.status === 201 &&
		contributor.status === 201 &&
		!! contributorKey.data.password,
	`${ privatePost.data.code || privatePost.status } ${ contributor.data.code || contributor.status }`
);

const ownDraft = await api( '/wp/v2/posts', {
	method: 'POST',
	body: { title: `E2E contributor ${ stamp }`, status: 'draft' },
	as: asContributor,
} );
const contributorFound = await run(
	`${ ns }/find-posts`,
	{ post_type: 'post', search: stamp },
	true,
	asContributor
);
const adminFound = await run(
	`${ ns }/find-posts`,
	{ post_type: 'post', search: stamp },
	true
);
step(
	"find-posts shows a contributor their own draft and not an administrator's private post",
	ownDraft.status === 201 &&
		contributorFound.status === 200 &&
		ids( contributorFound ).includes( ownDraft.data.id ) &&
		! ids( contributorFound ).includes( privatePost.data.id ) &&
		ids( adminFound ).includes( privatePost.data.id ),
	contributorFound.data.code ||
		`contributor sees ${ ids( contributorFound ).join( ', ' ) }`
);

const contributorPages = await run(
	`${ ns }/find-posts`,
	{ post_type: 'page' },
	true,
	asContributor
);
step(
	'find-posts refuses pages to a contributor',
	contributorPages.status === 403 &&
		contributorPages.data.code === 'voyager_open_blocks_invalid_post_type',
	contributorPages.data.code
);

// 10. The usage index: get-catalog's used_on counts the published pages and
// the templates that show a Site Data field. Counts are compared with what
// the site reported before this step, because the demo content and step 2
// already show the phone.
const phoneUse = 'voyager/site-data:phone';
const usedOn = async () =>
	( await run( `${ ns }/get-catalog`, { post_type: 'page' }, true ) ).data
		.used_on?.[ phoneUse ] ?? null;
const boundPhone =
	'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"phone"}}}}} -->\n<p>(555) 555-0100</p>\n<!-- /wp:paragraph -->';
const plainText =
	'<!-- wp:paragraph -->\n<p>No bound field</p>\n<!-- /wp:paragraph -->';
const usedBefore = await usedOn();
step(
	'get-catalog reports used_on for the phone',
	Number.isInteger( usedBefore?.posts ) &&
		Number.isInteger( usedBefore?.templates ) &&
		Number.isInteger( usedBefore?.template_parts ),
	JSON.stringify( usedBefore )
);
const usedDelta = ( now ) =>
	now && usedBefore
		? {
				posts: now.posts - usedBefore.posts,
				templates: now.templates - usedBefore.templates,
				template_parts: now.template_parts - usedBefore.template_parts,
			}
		: null;
const deltaIs = ( now, posts, parts ) => {
	const delta = usedDelta( now );
	return (
		!! delta &&
		delta.posts === posts &&
		delta.templates === 0 &&
		delta.template_parts === parts
	);
};

const pageA = await api( '/wp/v2/pages', {
	method: 'POST',
	body: {
		title: `E2E usage A ${ stamp }`,
		status: 'publish',
		content: boundPhone,
	},
} );
const syncedPattern = await api( '/wp/v2/blocks', {
	method: 'POST',
	body: {
		title: `E2E usage pattern ${ stamp }`,
		status: 'publish',
		content: boundPhone,
	},
} );
const pageB = await api( '/wp/v2/pages', {
	method: 'POST',
	body: {
		title: `E2E usage B ${ stamp }`,
		status: 'publish',
		content: `<!-- wp:block {"ref":${ syncedPattern.data.id }} /-->`,
	},
} );
const footerPart = await api( '/wp/v2/template-parts', {
	method: 'POST',
	body: {
		slug: `voyager-open-e2e-footer-${ stamp }`,
		title: `E2E footer ${ stamp }`,
		area: 'footer',
		content: boundPhone,
	},
} );
for ( const path of [
	pageA.data.id && `/wp/v2/pages/${ pageA.data.id }`,
	pageB.data.id && `/wp/v2/pages/${ pageB.data.id }`,
	syncedPattern.data.id && `/wp/v2/blocks/${ syncedPattern.data.id }`,
	footerPart.data.id && `/wp/v2/template-parts/${ footerPart.data.id }`,
] ) {
	if ( path ) {
		cleanup.push( path );
	}
}
step(
	'pages A and B, a synced pattern and a footer template part created',
	pageA.status === 201 &&
		syncedPattern.status === 201 &&
		pageB.status === 201 &&
		footerPart.status === 201,
	[ pageA, syncedPattern, pageB, footerPart ]
		.map( ( r ) => r.data.code || r.status )
		.join( ' ' )
);

const usedAll = await usedOn();
step(
	'phone bound on page A, through a synced pattern on page B and in a footer part: used on 2 more pages and 1 more template part',
	deltaIs( usedAll, 2, 1 ),
	JSON.stringify( usedDelta( usedAll ) )
);

const plainA = await api( `/wp/v2/pages/${ pageA.data.id }`, {
	method: 'POST',
	body: { content: plainText },
} );
const usedWithoutA = await usedOn();
step(
	'removing the bound block from page A drops it to 1 more page',
	plainA.status === 200 && deltaIs( usedWithoutA, 1, 1 ),
	JSON.stringify( usedDelta( usedWithoutA ) )
);

const plainPattern = await api( `/wp/v2/blocks/${ syncedPattern.data.id }`, {
	method: 'POST',
	body: { content: plainText },
} );
const usedWithoutPattern = await usedOn();
step(
	'unbinding the phone in the synced pattern drops page B too',
	plainPattern.status === 200 && deltaIs( usedWithoutPattern, 0, 1 ),
	JSON.stringify( usedDelta( usedWithoutPattern ) )
);

const pageC = await api( '/wp/v2/pages', {
	method: 'POST',
	body: {
		title: `E2E usage C ${ stamp }`,
		status: 'publish',
		content: '<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->',
	},
} );
if ( pageC.data.id ) {
	cleanup.push( `/wp/v2/pages/${ pageC.data.id }` );
}
const usedWithC = await usedOn();
step(
	'a page holding the registered CTA band pattern counts as using the phone',
	pageC.status === 201 && deltaIs( usedWithC, 1, 1 ),
	JSON.stringify( usedDelta( usedWithC ) )
);

// 11. Clean up what steps 7 to 10 created. The step 2 page stays, as before.
for ( const id of [ ownDraft.data.id, privatePost.data.id ] ) {
	if ( id ) {
		cleanup.push( `/wp/v2/posts/${ id }` );
	}
}
for ( const id of [ lockedPage.data.id, willowPage.data.id ] ) {
	if ( id ) {
		cleanup.push( `/wp/v2/pages/${ id }` );
	}
}
for ( const path of cleanup ) {
	await api( path, { method: 'DELETE', query: { force: true } } );
}
if ( contributor.data.id ) {
	await api( `/wp/v2/users/${ contributor.data.id }`, {
		method: 'DELETE',
		query: { force: true, reassign: me.data.id },
	} );
}

// 12. The purge. tests/fixtures/purge-recorder.php, which blueprints/dev.json
// installs, records each voyager_open_blocks_purge call. The demo pages and
// the step 2 page show the phone too, so the pages a change refreshes are
// compared with a first change made before pages A, B and C exist.
const recorder = '/voyager-open-blocks-test/v1/purges';
const purgesDuring = async ( change ) => {
	await api( recorder, { method: 'DELETE' } );
	const changed = await change();
	const recorded = await api( recorder );
	return {
		changed,
		status: recorded.status,
		records: Array.isArray( recorded.data.records )
			? recorded.data.records
			: [],
	};
};
const setPhone = ( phone ) =>
	api( '/wp/v2/settings', {
		method: 'POST',
		body: { voyager_site_phone: phone },
	} );
const described = ( records ) =>
	JSON.stringify(
		records.map( ( r ) => ( {
			urls: r.urls,
			reason: r.context?.reason,
			keys: r.context?.keys,
			post_ids: r.context?.post_ids,
			templates: r.context?.templates,
			purge_all: r.context?.purge_all,
		} ) )
	);
const sameList = ( a, b ) =>
	JSON.stringify( [ ...a ].sort() ) === JSON.stringify( [ ...b ].sort() );
const front = `${ String( ( await api( '/' ) ).data.home || base ).replace(
	/\/$/,
	''
) }/`;
const originalPhone = ( await api( '/wp/v2/settings' ) ).data
	.voyager_site_phone;
const purgeCleanup = [];

const firstChange = await purgesDuring( () => setPhone( '(555) 555-0161' ) );
step(
	'the purge recorder is installed (tests/fixtures/purge-recorder.php)',
	firstChange.status === 200,
	`GET ${ recorder } answered ${ firstChange.status }`
);
const before = firstChange.records[ 0 ];
step(
	'changing the phone fires voyager_open_blocks_purge once, for the phone, with the front page',
	firstChange.changed.status === 200 &&
		firstChange.records.length === 1 &&
		before.context?.reason === 'site-data' &&
		before.context?.source === 'voyager/site-data' &&
		sameList( before.context?.keys || [], [ 'phone' ] ) &&
		( before.urls || [] ).includes( front ),
	described( firstChange.records )
);
const beforeUrls = before?.urls || [];

const boundEmail =
	'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"email"}}}}} -->\n<p>hello@example.com</p>\n<!-- /wp:paragraph -->';
const publish = async ( path, title, content ) => {
	const made = await api( path, {
		method: 'POST',
		body: { title: `${ title } ${ stamp }`, status: 'publish', content },
	} );
	if ( made.data.id ) {
		purgeCleanup.push( `${ path }/${ made.data.id }` );
	}
	return made;
};
const purgePattern = await publish(
	'/wp/v2/blocks',
	'E2E purge pattern',
	boundPhone
);
const purgeA = await publish( '/wp/v2/pages', 'E2E purge A', boundPhone );
const purgeB = await publish(
	'/wp/v2/pages',
	'E2E purge B',
	`<!-- wp:block {"ref":${ purgePattern.data.id }} /-->`
);
const purgeC = await publish( '/wp/v2/pages', 'E2E purge C', boundEmail );
step(
	'page A (phone), page B (phone through a synced pattern) and page C (email) created',
	[ purgePattern, purgeA, purgeB, purgeC ].every( ( r ) => r.status === 201 ),
	[ purgePattern, purgeA, purgeB, purgeC ]
		.map( ( r ) => r.data.code || r.status )
		.join( ' ' )
);

const withPages = await purgesDuring( () => setPhone( '(555) 555-0162' ) );
const after = withPages.records[ 0 ];
const afterUrls = after?.urls || [];
const added = afterUrls.filter( ( url ) => ! beforeUrls.includes( url ) );
step(
	'fitness: changing the phone records one purge whose new URLs are exactly pages A and B, with the front page, and not page C',
	withPages.records.length === 1 &&
		sameList( added, [ purgeA.data.link, purgeB.data.link ] ) &&
		beforeUrls.every( ( url ) => afterUrls.includes( url ) ) &&
		afterUrls.includes( front ) &&
		! afterUrls.includes( purgeC.data.link ) &&
		new Set( afterUrls ).size === afterUrls.length,
	`added ${ JSON.stringify( added ) }; ${ described( withPages.records ) }`
);
step(
	'its context names pages A and B, not page C or the synced pattern, and does not purge everything',
	( after?.context?.post_ids || [] ).includes( purgeA.data.id ) &&
		( after?.context?.post_ids || [] ).includes( purgeB.data.id ) &&
		! ( after?.context?.post_ids || [] ).includes( purgeC.data.id ) &&
		! ( after?.context?.post_ids || [] ).includes( purgePattern.data.id ) &&
		after?.context?.purge_all === false,
	described( withPages.records )
);

const sameAgain = await purgesDuring( () => setPhone( '(555) 555-0162' ) );
step(
	'saving the same phone again fires nothing',
	sameAgain.changed.status === 200 && sameAgain.records.length === 0,
	described( sameAgain.records )
);

const purgePart = await api( '/wp/v2/template-parts', {
	method: 'POST',
	body: {
		slug: `voyager-open-e2e-purge-footer-${ stamp }`,
		title: `E2E purge footer ${ stamp }`,
		area: 'footer',
		content: boundPhone,
	},
} );
if ( purgePart.data.id ) {
	purgeCleanup.push( `/wp/v2/template-parts/${ purgePart.data.id }` );
}
const withPart = await purgesDuring( () => setPhone( '(555) 555-0163' ) );
step(
	'with the phone in a template part, the purge sets purge_all and names the part',
	purgePart.status === 201 &&
		withPart.records.length === 1 &&
		withPart.records[ 0 ].context?.purge_all === true &&
		( withPart.records[ 0 ].context?.templates || [] ).includes(
			purgePart.data.id
		),
	`${ purgePart.data.code || purgePart.status }; ${ described(
		withPart.records
	) }`
);

const boundMeta =
	'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"demo_eyebrow"}}}}} -->\n<p>Eyebrow</p>\n<!-- /wp:paragraph -->';
const purgeD = await publish( '/wp/v2/pages', 'E2E purge D', boundMeta );
const purgeE = await publish( '/wp/v2/pages', 'E2E purge E', boundMeta );
const metaChange = await purgesDuring( () =>
	api( `/wp/v2/pages/${ purgeD.data.id }`, {
		method: 'POST',
		body: { meta: { demo_eyebrow: `Eyebrow ${ stamp }` } },
	} )
);
step(
	'changing bindable post meta on page D refreshes page D only, not page E or the front page',
	purgeD.status === 201 &&
		purgeE.status === 201 &&
		metaChange.changed.status === 200 &&
		metaChange.records.length === 1 &&
		sameList( metaChange.records[ 0 ].urls || [], [ purgeD.data.link ] ) &&
		metaChange.records[ 0 ].context?.reason === 'post-meta' &&
		metaChange.records[ 0 ].context?.source === 'voyager/post-meta-text' &&
		metaChange.records[ 0 ].context?.purge_all === false,
	`${ metaChange.changed.data.code || metaChange.changed.status }; ${ described(
		metaChange.records
	) }`
);

// 12b. The Site Title and the Tagline. `company` is blogname and `tagline` is
// blogdescription, which /wp/v2/settings names title and description. Page T
// shows the company, page G the tagline and page C (from above) the email.
const general = ( await api( '/wp/v2/settings' ) ).data;
const boundSiteData = ( key, text ) =>
	`<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"${ key }"}}}}} -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`;
const titlePage = await publish(
	'/wp/v2/pages',
	'E2E purge T',
	boundSiteData( 'company', 'Example Company' )
);
const taglinePage = await publish(
	'/wp/v2/pages',
	'E2E purge G',
	boundSiteData( 'tagline', 'Example tagline' )
);
step(
	'page T (company) and page G (tagline) created',
	titlePage.status === 201 && taglinePage.status === 201,
	[ titlePage, taglinePage ]
		.map( ( r ) => r.data.code || r.status )
		.join( ' ' )
);
const generalChange = async ( setting, value, key, shows, hides ) => {
	const changed = await purgesDuring( () =>
		api( '/wp/v2/settings', {
			method: 'POST',
			body: { [ setting ]: value },
		} )
	);
	const urls = changed.records[ 0 ]?.urls || [];
	return {
		ok:
			changed.changed.status === 200 &&
			changed.records.length === 1 &&
			changed.records[ 0 ].context?.reason === 'site-data' &&
			sameList( changed.records[ 0 ].context?.keys || [], [ key ] ) &&
			urls.includes( shows ) &&
			urls.includes( front ) &&
			hides.every( ( link ) => ! urls.includes( link ) ),
		detail: `${ changed.changed.data.code || changed.changed.status }; ${ described(
			changed.records
		) }`,
	};
};
const titleChange = await generalChange(
	'title',
	`E2E site ${ stamp }`,
	'company',
	titlePage.data.link,
	[ purgeC.data.link, taglinePage.data.link ]
);
step(
	'fitness: changing the Site Title records one purge for the company, with page T and the front page, and not page C or G',
	titleChange.ok,
	titleChange.detail
);
const taglineChange = await generalChange(
	'description',
	`E2E tagline ${ stamp }`,
	'tagline',
	taglinePage.data.link,
	[ purgeC.data.link, titlePage.data.link ]
);
step(
	'fitness: changing the Tagline records one purge for the tagline, with page G and the front page, and not page C or T',
	taglineChange.ok,
	taglineChange.detail
);
await api( '/wp/v2/settings', {
	method: 'POST',
	body: { title: general.title, description: general.description },
} );

for ( const path of purgeCleanup ) {
	await api( path, { method: 'DELETE', query: { force: true } } );
}
if ( typeof originalPhone === 'string' ) {
	await setPhone( originalPhone );
}
await api( recorder, { method: 'DELETE' } );

// 13. The facts block, a list field on a page. insert puts voyager-open/facts
// in a draft with items of its own, bind ties items to the services list,
// and once the services are set and the page is published it shows one ul
// with one li per line, in order. The published page counts in used_on for
// the services, emptying them records a purge with the page, and the page
// then shows the block's own items again.
const factsItem = 'voyager-open/facts';
const factsUse = 'voyager/site-data:services';
const factsType = ( catalog.data.blocks || [] ).find(
	( b ) => b.name === factsItem
);
step(
	'get-catalog lists voyager-open/facts with items among its bindable attributes',
	!! factsType && ( factsType.bindable || [] ).includes( 'items' ),
	factsType
		? `bindable ${ JSON.stringify( factsType.bindable ) }`
		: 'not in blocks'
);
const servicesField = ( siteData?.fields || [] ).find(
	( f ) => f.args?.key === 'services'
);
const setServices = ( value ) =>
	api( '/wp/v2/settings', {
		method: 'POST',
		body: { voyager_site_services: value },
	} );
const originalServices = ( await api( '/wp/v2/settings' ) ).data
	.voyager_site_services;
const factsLists = ( pageHtml ) =>
	[
		...pageHtml.matchAll(
			/<ul\b[^>]*\bclass="[^"]*\bwp-block-voyager-open-facts\b[^"]*"[^>]*>([\s\S]*?)<\/ul>/g
		),
	].map( ( ul ) =>
		[ ...ul[ 1 ].matchAll( /<li\b[^>]*>([\s\S]*?)<\/li>/g ) ].map( ( li ) =>
			li[ 1 ].trim()
		)
	);
const factsDraft = await api( '/wp/v2/pages', {
	method: 'POST',
	body: { title: `E2E facts ${ stamp }`, status: 'draft', content: '' },
} );
const factsId = factsDraft.data.id;
const factsInsert = await run( `${ ns }/insert`, {
	post_id: factsId,
	item: factsItem,
	attributes: { items: 'Own item one\nOwn item two' },
} );
const factsPath = factsInsert.data.blocks?.[ 0 ]?.path;
step(
	'insert voyager-open/facts into a draft',
	factsDraft.status === 201 &&
		factsInsert.status === 200 &&
		factsInsert.data.saved === true &&
		factsInsert.data.blocks?.[ 0 ]?.block === factsItem,
	factsInsert.data.message || factsInsert.data.code || `path ${ factsPath }`
);
const factsBound = await run( `${ ns }/bind`, {
	post_id: factsId,
	path: factsPath,
	attribute: 'items',
	source: 'voyager/site-data',
	args: servicesField?.args,
} );
step(
	'bind facts items to site-data services (args copied from the catalog)',
	factsBound.status === 200 &&
		factsBound.data.attributes?.metadata?.bindings?.items?.source ===
			'voyager/site-data' &&
		factsBound.data.attributes?.metadata?.bindings?.items?.args?.key ===
			'services',
	factsBound.data.code ||
		JSON.stringify( factsBound.data.attributes?.metadata?.bindings )
);
validate( 'facts after bind', factsBound.data.markup || '' );

const factsUsedBefore = (
	await run( `${ ns }/get-catalog`, { post_type: 'page' }, true )
).data.used_on?.[ factsUse ];
const threeServices = await setServices(
	'Drain cleaning\r\nHeating & cooling\r\nLeak detection'
);
const factsPublished = await api( `/wp/v2/pages/${ factsId }`, {
	method: 'POST',
	body: { status: 'publish' },
} );
const factsLink = factsPublished.data.link;
const shownBound = factsLink
	? factsLists( ( await request( factsLink ) ).text )
	: [];
step(
	'fitness: bound to services set to three lines, the published page shows one facts ul with those three li, in order',
	threeServices.status === 200 &&
		factsPublished.status === 200 &&
		shownBound.length === 1 &&
		JSON.stringify( shownBound[ 0 ] ) ===
			JSON.stringify( [
				'Drain cleaning',
				'Heating &amp; cooling',
				'Leak detection',
			] ),
	`${ threeServices.data.code || threeServices.status }; ${ JSON.stringify(
		shownBound
	) }`
);

const factsUsedAfter = (
	await run( `${ ns }/get-catalog`, { post_type: 'page' }, true )
).data.used_on?.[ factsUse ];
step(
	'get-catalog used_on for services counts the published facts page',
	Number.isInteger( factsUsedBefore?.posts ) &&
		factsUsedAfter?.posts === factsUsedBefore.posts + 1,
	`before ${ JSON.stringify( factsUsedBefore ) }, after ${ JSON.stringify(
		factsUsedAfter
	) }`
);

const factsPurge = await purgesDuring( () => setServices( '' ) );
step(
	'emptying the services records one purge, for the services, with the facts page',
	factsPurge.changed.status === 200 &&
		factsPurge.records.length === 1 &&
		sameList( factsPurge.records[ 0 ].context?.keys || [], [
			'services',
		] ) &&
		( factsPurge.records[ 0 ].urls || [] ).includes( factsLink ) &&
		( factsPurge.records[ 0 ].context?.post_ids || [] ).includes( factsId ),
	described( factsPurge.records )
);
const shownOwn = factsLink
	? factsLists( ( await request( factsLink ) ).text )
	: [];
step(
	'with the services empty, the facts block shows its own items',
	shownOwn.length === 1 &&
		JSON.stringify( shownOwn[ 0 ] ) ===
			JSON.stringify( [ 'Own item one', 'Own item two' ] ),
	JSON.stringify( shownOwn )
);

if ( factsId ) {
	await api( `/wp/v2/pages/${ factsId }`, {
		method: 'DELETE',
		query: { force: true },
	} );
}
if ( typeof originalServices === 'string' ) {
	await setServices( originalServices );
}
await api( recorder, { method: 'DELETE' } );

// 14. The contact details block. A binding fills a core paragraph's whole
// text, so it cannot put a link around a phone number inside it. This block
// takes the seven contact fields as attributes and prints the phone and the
// email as links. insert puts it in a draft, bind ties each attribute to its
// Site Data key, the fields get the fixture's values through
// POST /wp/v2/settings, and the published page shows one address with the
// street, the city line, a tel: link and a mailto: link, then the hours.
const contactItem = 'voyager-open/contact-details';
const contactKeys = {
	phone: 'phone',
	phoneLink: 'phone_link',
	email: 'email',
	emailLink: 'email_link',
	address: 'address',
	addressLocality: 'address_locality',
	hours: 'hours',
};
const contactOptions = [
	'voyager_site_phone',
	'voyager_site_email',
	'voyager_site_address',
	'voyager_site_address_city',
	'voyager_site_address_state',
	'voyager_site_address_zip',
	'voyager_site_hours',
];
const keysFixture = JSON.parse(
	readFileSync(
		join( repo, 'tests', 'fixtures', 'site-data-keys.json' ),
		'utf8'
	)
);
const contactExpected = keysFixture.expected;
const contactType = ( catalog.data.blocks || [] ).find(
	( b ) => b.name === contactItem
);
step(
	'get-catalog lists voyager-open/contact-details with its seven attributes bindable',
	!! contactType &&
		sameList( contactType.bindable || [], Object.keys( contactKeys ) ),
	contactType
		? `bindable ${ JSON.stringify( contactType.bindable ) }`
		: 'not in blocks'
);
const settingsBefore = ( await api( '/wp/v2/settings' ) ).data;
const setContact = ( values ) =>
	api( '/wp/v2/settings', { method: 'POST', body: values } );
const contactFixture = await setContact(
	Object.fromEntries(
		contactOptions.map( ( option ) => [
			option,
			keysFixture.options[ option ],
		] )
	)
);
const contactDraft = await api( '/wp/v2/pages', {
	method: 'POST',
	body: { title: `E2E contact ${ stamp }`, status: 'draft', content: '' },
} );
const contactId = contactDraft.data.id;
const contactInsert = await run( `${ ns }/insert`, {
	post_id: contactId,
	item: contactItem,
} );
const contactPath = contactInsert.data.blocks?.[ 0 ]?.path;
step(
	'insert voyager-open/contact-details into a draft',
	contactDraft.status === 201 &&
		contactInsert.status === 200 &&
		contactInsert.data.saved === true &&
		contactInsert.data.blocks?.[ 0 ]?.block === contactItem,
	contactInsert.data.message ||
		contactInsert.data.code ||
		`path ${ contactPath }`
);
const contactBinds = [];
for ( const [ attribute, key ] of Object.entries( contactKeys ) ) {
	contactBinds.push( [
		attribute,
		key,
		await run( `${ ns }/bind`, {
			post_id: contactId,
			path: contactPath,
			attribute,
			source: 'voyager/site-data',
			args: ( siteData?.fields || [] ).find(
				( f ) => f.args?.key === key
			)?.args,
		} ),
	] );
}
const contactBound = contactBinds.at( -1 )?.[ 2 ];
step(
	'bind each of the seven attributes to its site-data key (args copied from the catalog)',
	contactBinds.length === 7 &&
		contactBinds.every( ( [ , , answer ] ) => answer.status === 200 ) &&
		Object.entries( contactKeys ).every(
			( [ attribute, key ] ) =>
				contactBound?.data.attributes?.metadata?.bindings?.[ attribute ]
					?.source === 'voyager/site-data' &&
				contactBound?.data.attributes?.metadata?.bindings?.[ attribute ]
					?.args?.key === key
		),
	contactBinds
		.filter( ( [ , , answer ] ) => answer.status !== 200 )
		.map(
			( [ attribute, , answer ] ) =>
				`${ attribute }: ${ answer.data.code }`
		)
		.join( ', ' ) ||
		JSON.stringify( contactBound?.data.attributes?.metadata?.bindings )
);
validate( 'contact details after bind', contactBound?.data.markup || '' );

/**
 * The contact details blocks on a page, each as the parts a visitor sees.
 *
 * @param {string} pageHtml The page.
 * @return {Array<{html: string, addresses: number, inAddress: string, after: string}>} One per block.
 */
const contactBlocks = ( pageHtml ) =>
	[
		...pageHtml.matchAll(
			/<div\b[^>]*\bclass="[^"]*\bwp-block-voyager-open-contact-details\b[^"]*"[^>]*>([\s\S]*?)<\/div>/g
		),
	].map( ( match ) => {
		const inner = match[ 1 ];
		const address = inner.match( /<address\b[^>]*>([\s\S]*?)<\/address>/ );
		return {
			html: inner,
			addresses: ( inner.match( /<address\b/g ) || [] ).length,
			inAddress: address ? address[ 1 ] : '',
			after: address
				? inner.slice( address.index + address[ 0 ].length )
				: '',
		};
	} );
const linksTo = ( markup, scheme ) =>
	[
		...markup.matchAll(
			new RegExp(
				`<a\\b[^>]*\\bhref="(${ scheme }:[^"]*)"[^>]*>([\\s\\S]*?)</a>`,
				'g'
			)
		),
	].map( ( m ) => ( { href: m[ 1 ], text: m[ 2 ].trim() } ) );
const contactUse = 'voyager/site-data:phone';
const contactUsedBefore = (
	await run( `${ ns }/get-catalog`, { post_type: 'page' }, true )
).data.used_on?.[ contactUse ];
const contactPublished = await api( `/wp/v2/pages/${ contactId }`, {
	method: 'POST',
	body: { status: 'publish' },
} );
const contactLink = contactPublished.data.link;
const contactShown = contactLink
	? contactBlocks( ( await request( contactLink ) ).text )
	: [];
const shownContact = contactShown[ 0 ];
const tels = linksTo( shownContact?.html || '', 'tel' );
const mails = linksTo( shownContact?.html || '', 'mailto' );
step(
	'fitness: bound to the seven keys and published, the block shows one address with a tel: link reading the phone and a mailto: link reading the email',
	contactFixture.status === 200 &&
		contactPublished.status === 200 &&
		contactShown.length === 1 &&
		shownContact.addresses === 1 &&
		tels.length === 1 &&
		tels[ 0 ].href === contactExpected.phone_link &&
		tels[ 0 ].text === contactExpected.phone &&
		mails.length === 1 &&
		mails[ 0 ].href === contactExpected.email_link &&
		mails[ 0 ].text === contactExpected.email &&
		linksTo( shownContact.inAddress, 'tel' ).length === 1 &&
		linksTo( shownContact.inAddress, 'mailto' ).length === 1,
	`${ contactFixture.data.code || contactFixture.status }; ${ contactShown.length } block(s); tel ${ JSON.stringify(
		tels
	) }; mailto ${ JSON.stringify( mails ) }`
);
step(
	'the address holds the street and the city line, and the hours follow it',
	!! shownContact &&
		shownContact.inAddress.includes( `>${ contactExpected.address }<` ) &&
		shownContact.inAddress.includes(
			`>${ contactExpected.address_locality }<`
		) &&
		shownContact.after.includes( `>${ contactExpected.hours }</p>` ) &&
		! shownContact.inAddress.includes( contactExpected.hours ),
	JSON.stringify( shownContact?.html || '' )
);

const contactUsedAfter = (
	await run( `${ ns }/get-catalog`, { post_type: 'page' }, true )
).data.used_on?.[ contactUse ];
step(
	'get-catalog used_on for the phone counts the published contact details page',
	Number.isInteger( contactUsedBefore?.posts ) &&
		contactUsedAfter?.posts === contactUsedBefore.posts + 1,
	`before ${ JSON.stringify( contactUsedBefore ) }, after ${ JSON.stringify(
		contactUsedAfter
	) }`
);

const contactPurge = await purgesDuring( () => setPhone( '(555) 010-0177' ) );
step(
	'changing the phone records one purge, for the phone, with the contact details page',
	contactPurge.changed.status === 200 &&
		contactPurge.records.length === 1 &&
		( contactPurge.records[ 0 ].context?.keys || [] ).includes( 'phone' ) &&
		( contactPurge.records[ 0 ].urls || [] ).includes( contactLink ) &&
		( contactPurge.records[ 0 ].context?.post_ids || [] ).includes(
			contactId
		),
	described( contactPurge.records )
);

const noEmail = await setContact( { voyager_site_email: '' } );
const withoutEmail = contactLink
	? contactBlocks( ( await request( contactLink ) ).text )
	: [];
step(
	'with the email empty, no email line and no mailto: link render, and the rest still does',
	noEmail.status === 200 &&
		withoutEmail.length === 1 &&
		linksTo( withoutEmail[ 0 ].html, 'mailto' ).length === 0 &&
		! withoutEmail[ 0 ].html.includes( contactExpected.email ) &&
		! withoutEmail[ 0 ].html.includes( '__email' ) &&
		withoutEmail[ 0 ].addresses === 1 &&
		linksTo( withoutEmail[ 0 ].inAddress, 'tel' ).length === 1 &&
		withoutEmail[ 0 ].inAddress.includes(
			`>${ contactExpected.address }<`
		) &&
		withoutEmail[ 0 ].after.includes( `>${ contactExpected.hours }</p>` ),
	JSON.stringify( withoutEmail[ 0 ]?.html || '' )
);

const staleContact = await run(
	`${ ns }/validate`,
	{
		markup: '<!-- wp:voyager-open/contact-details {"phone":"(555) 010-0199","metadata":{"bindings":{"phone":{"source":"voyager/site-data","args":{"key":"phone"}}}}} /-->',
	},
	true
);
step(
	'validate warns stale_fallback on the block bound to the phone whose own phone holds a number',
	staleContact.status === 200 &&
		( staleContact.data.warnings || [] ).some(
			( w ) =>
				w.startsWith( 'stale_fallback: ' ) &&
				w.includes( contactItem ) &&
				w.includes( '"phone"' )
		),
	JSON.stringify( staleContact.data.warnings || staleContact.data.code )
);

if ( contactId ) {
	await api( `/wp/v2/pages/${ contactId }`, {
		method: 'DELETE',
		query: { force: true },
	} );
}
await setContact(
	Object.fromEntries(
		contactOptions
			.filter(
				( option ) => typeof settingsBefore[ option ] === 'string'
			)
			.map( ( option ) => [ option, settingsBefore[ option ] ] )
	)
);
await api( recorder, { method: 'DELETE' } );

rmSync( scratch, { recursive: true, force: true } );
console.log(
	`\n${ failures ? `${ failures } step(s) failed` : 'All steps passed' }. Page: ${ saved.data.link }`
);
process.exit( failures ? 1 : 0 );
