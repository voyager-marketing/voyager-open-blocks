#!/usr/bin/env node
/**
 * End to end, the way an agent would do it: over REST, through the abilities.
 *
 *   VOYAGER_OPEN_APP_PASSWORD=... npm run e2e -- [--url http://127.0.0.1:9402] [--user admin] [--context site.context.json]
 *
 * Against a running site (npm run playground -- serve), it:
 *   1. lists the pack's abilities and reads the catalog,
 *   2. creates a page, finds it with find-posts by title words and by slug, and
 *      inserts the CTA band (bound to voyager/site-data), then inserts it again
 *      with copy for its heading, body and bound call button, and inserts the
 *      proof strip with three facts (fourth item omitted) and with six (two repeated),
 *   3. inserts an icon, configures it and binds its link, and inserts an icon
 *      inside the second CTA band's row, beside its buttons,
 *   4. checks bad input is refused (unknown icon, invented args, unbindable attribute,
 *      copy for an unknown label, a block that is not text, or a reference, a parent
 *      that does not allow the icon, holds no blocks or is locked, a repeat that
 *      cannot apply, post_type on a page block),
 *   5. runs `block-runner validate --strict` on every markup the abilities returned
 *      and on the saved page, and the validate ability on the same markup, on the
 *      saved page and on broken markup, including one case only block-runner catches,
 *   6. fetches the page and checks the bound values rendered,
 *   7. the icon prompt end to end: creates a header template part with a call
 *      button, finds it with find-posts, puts a phone icon beside the button and
 *      binds its link to Site Data, then checks it resolves and validates,
 *   8. binds post meta in a template with post_type, and checks it is refused without,
 *   9. checks find-posts as a contributor: their own draft is listed, an
 *      administrator's private post is not, and pages are refused,
 *  10. deletes what steps 7 to 9 created. The page from step 2 is kept, as before.
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
	names.length === 7 &&
		names.includes( `${ ns }/validate` ) &&
		names.includes( `${ ns }/find-posts` ),
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

// The CTA band again, with copy. The heading and body carry characters that
// must arrive escaped, and the bound call button gets new fallback text.
const withCopy = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/cta-band',
	copy: {
		Heading: 'Leaks fixed today & done right',
		Body: "Call before noon and we're there by 5pm, <b>guaranteed</b>.",
		'Click to call': 'Call now',
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
		copied.includes( '>Call now</a>' ) &&
		! copied.includes( 'Ready to get started?' ) &&
		copiedCall?.bindings?.text?.args?.key === 'phone' &&
		copiedCall?.bindings?.url?.args?.key === 'phone_link',
	withCopy.data.code ||
		`index ${ withCopy.data.index }, call button still bound at ${ copiedCall?.path }`
);
validate( 'cta-band with copy', copied );

// The proof strip with three facts: copy fills three items, omit drops the fourth.
const strip = await run( `${ ns }/insert`, {
	post_id: postId,
	item: 'voyager-open/proof-strip',
	copy: {
		'Credential 1': 'Licensed in three states',
		'Credential 2': 'Family owned since 1998',
		'Credential 3': 'Written quotes',
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
		! stripMarkup.includes( 'Same-week scheduling' ) &&
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
		sixFacts.map( ( fact, i ) => [ `Credential ${ i + 1 }`, fact ] )
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

// 10. Clean up what steps 7 to 9 created. The step 2 page stays, as before.
for ( const id of [ ownDraft.data.id, privatePost.data.id ] ) {
	if ( id ) {
		cleanup.push( `/wp/v2/posts/${ id }` );
	}
}
if ( lockedPage.data.id ) {
	cleanup.push( `/wp/v2/pages/${ lockedPage.data.id }` );
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

rmSync( scratch, { recursive: true, force: true } );
console.log(
	`\n${ failures ? `${ failures } step(s) failed` : 'All steps passed' }. Page: ${ saved.data.link }`
);
process.exit( failures ? 1 : 0 );
