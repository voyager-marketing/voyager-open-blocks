#!/usr/bin/env node
/**
 * End to end, the way an agent would do it: over REST, through the abilities.
 *
 *   VOYAGER_OPEN_APP_PASSWORD=... npm run e2e -- [--url http://127.0.0.1:9402] [--user admin] [--context site.context.json]
 *
 * Against a running site (npm run playground -- serve), it:
 *   1. lists the pack's abilities and reads the catalog,
 *   2. creates a page and inserts the CTA band (bound to voyager/site-data), then
 *      inserts it again with copy for its heading, body and bound call button,
 *   3. inserts an icon, configures it and binds its link,
 *   4. checks bad input is refused (unknown icon, invented args, unbindable attribute,
 *      copy for an unknown label, a block that is not text, or a reference),
 *   5. runs `block-runner validate --strict` on every markup the abilities returned
 *      and on the saved page,
 *   6. fetches the page and checks the bound values rendered.
 *
 * The application password comes from the environment, never argv. Create one with
 * `wp user application-password create admin e2e --porcelain`.
 */
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
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

async function api( path, { method = 'GET', body, query } = {} ) {
	const url = new URL( `${ base }/wp-json${ path }` );
	for ( const [ key, value ] of Object.entries( query || {} ) ) {
		url.searchParams.set( key, String( value ) );
	}
	const response = await request( url, {
		method,
		headers: { Authorization: auth, 'Content-Type': 'application/json' },
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
 */
function run( name, input, readonly = false ) {
	if ( readonly ) {
		const query = {};
		for ( const [ key, value ] of Object.entries( input ) ) {
			query[ `input[${ key }]` ] = value;
		}
		return api( `/wp-abilities/v1/abilities/${ name }/run`, { query } );
	}
	return api( `/wp-abilities/v1/abilities/${ name }/run`, {
		method: 'POST',
		body: { input },
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
step( 'abilities listed', names.length === 5, names.join( ', ' ) );

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

// 2. A fresh page with the CTA band.
const page = await api( '/wp/v2/pages', {
	method: 'POST',
	body: { title: 'E2E: CTA band', status: 'publish', content: '' },
} );
const postId = page.data.id;
step( 'page created', page.status === 201, `id ${ postId }` );

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

for ( const [ label, input ] of [
	[ 'insert refuses copy for an unknown label', { copy: { Headline: 'x' } } ],
	[
		'insert refuses copy for a block that is not text',
		{ copy: { 'CTA band': 'x' } },
	],
	[
		'insert refuses copy on a reference',
		{ copy: { Heading: 'x' }, mode: 'reference' },
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
			refused.data.code === 'voyager_open_blocks_invalid_copy',
		refused.data.message || refused.data.code
	);
}

// 5. The saved page as a whole.
const saved = await api( `/wp/v2/pages/${ postId }`, {
	query: { context: 'edit' },
} );
validate( 'saved page content', saved.data.content?.raw || '' );

const tree = await run( `${ ns }/get-post-blocks`, { post_id: postId }, true );
step(
	'get-post-blocks',
	tree.status === 200 && ( tree.data.blocks || [] ).length > 0,
	`${ ( tree.data.blocks || [] ).length } blocks`
);

// 6. Rendered output.
const html = ( await request( saved.data.link ) ).text;
const telLinks = html.match( /href="tel:[^"]+"/g ) || [];
step(
	'rendered page carries bound tel: links (button and icon)',
	telLinks.length >= 2,
	telLinks.join( ' ' )
);

rmSync( scratch, { recursive: true, force: true } );
console.log(
	`\n${ failures ? `${ failures } step(s) failed` : 'All steps passed' }. Page: ${ saved.data.link }`
);
process.exit( failures ? 1 : 0 );
