#!/usr/bin/env node
// Scores the benchmark. No judge, no network.
//
//   node bench/score.mjs [--runs bench/runs] [--briefs bench/briefs.json] [--out bench/results]
//   node bench/score.mjs --briefs bench/briefs-v2.json [--runs bench/runs-v2] [--out bench/results-v2]
//
// The briefs file's `version` says which protocol the runs follow.
//
// Version 1, one folder per arm and brief, measures 1 to 3:
//
//   runs/<arm>/<brief-id>/page.html        post_content saved after the build task
//   runs/<arm>/<brief-id>/after-edit.html  post_content after the "change the phone" task
//   runs/<arm>/<brief-id>/site-data.json   optional: voyager_site_* values after the edit task,
//                                          as {"phone": "...", "email": "...", ...}
//
// Version 2, one folder per group, model, brief and repeat, with every v1
// measure and these besides (bench/README.md, "Version 2"):
//
//   runs-v2/<group>/<model>/<brief-id>/<repeat>/
//     page.html, after-edit.html, site-data.json   as in v1
//     page.rendered.html                            the page through the cache after the build
//     after-edit.rendered.html                      the same after the edit
//     transcript-build.jsonl, transcript-edit.jsonl Claude Code's stream-json output
//     run.json                                      what the runner saw, the cache loads included
//     wesper-manifest.json                          the site's own blocks; page.html and
//                                                   after-edit.html are validated against it
//
// Every group, model, brief and repeat found anywhere in a v2 tree is
// expected in every group, so a run missing from one group scores there as a
// failed build. A missing page.html scores as a failed build, never as
// skipped.

import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const args = parseArgs( process.argv.slice( 2 ) );
const briefsPath = resolve( args.briefs ?? join( here, 'briefs.json' ) );
const briefsFile = JSON.parse( readFileSync( briefsPath, 'utf8' ) );
const v2 = briefsFile.version === 2;
const runsDir = resolve( args.runs ?? join( here, v2 ? 'runs-v2' : 'runs' ) );
const outDir = resolve( args.out ?? join( here, v2 ? 'results-v2' : 'results' ) );
// The CLI's JS entry, run with this Node: the .bin shim is a shell script that
// spawnSync cannot start on Windows, so every page scored invalid there.
const blockRunner = resolve( here, '..', 'node_modules', 'block-runner', 'dist', 'cli.js' );

/**
 * Words of a factual claim or a promise: the list in
 * tests/Unit/PatternLayoutsTest.php (CLAIMS), with two changes, because it is
 * written for a pattern's default copy and this reads an agent's page:
 *
 * - no bare digit and no `#`, which every phone number, address and opening
 *   time would trip;
 * - `rating` and `review` start a word, so "operating", "celebrating",
 *   "decorating" and "preview" make no claim, while "ratings", "reviews" and
 *   "reviewed" still do.
 *
 * bench/test-score.mjs checks that this is that list with those changes.
 */
const CLAIM_WORDS = /licen[cs]|insur|bonded|certif|accredit|award|\brated\b|\brating|\breview|\bstars?\b|years?\b|\bsince\b|established|guarantee|\bwarrant|\bbest\b|\btop\b|\bleading\b|\bfree\b|same[- ]day/i;

/** Phrases that hold a claim word and make no claim, removed before the test. */
const NOT_CLAIMS = [ /\bfeel free\b/gi ];

// Elements whose end is the end of a line of text: a sentence never runs on
// across them.
const BLOCK_TAGS =
	'address|article|aside|blockquote|br|button|caption|dd|details|div|dl|dt|figcaption|figure|footer|form|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|summary|table|td|th|tr|ul';

const FIELDS = {
	phone: { keys: [ 'phone', 'phone_link' ], literal: ( b ) => digits( b.phone ) },
	email: { keys: [ 'email', 'email_link' ], literal: ( b ) => b.email.toLowerCase() },
	address: { keys: [ 'address', 'address_locality' ], literal: ( b ) => norm( b.address ) },
};

const { briefs } = briefsFile;
const rows = v2 ? scoreV2() : scoreV1();

mkdirSync( outDir, { recursive: true } );
writeFileSync( join( outDir, 'results.json' ), JSON.stringify( rows, null, 2 ) + '\n' );
writeFileSync( join( outDir, 'results.csv' ), toCsv( rows ) );
const summary = v2 ? summarizeV2( rows ) : summarize( rows );
writeFileSync( join( outDir, 'summary.json' ), JSON.stringify( summary, null, 2 ) + '\n' );
const md = v2 ? toMarkdownV2( summary, rows ) : toMarkdown( summary );
writeFileSync( join( outDir, 'summary.md' ), md );
console.log( md );

// ---------------------------------------------------------------------------
// Version 1

function scoreV1() {
	const arms = dirs( runsDir );
	if ( ! arms.length ) {
		console.error( `No arms under ${ runsDir }.` );
		process.exit( 2 );
	}
	const out = [];
	for ( const arm of arms ) {
		for ( const brief of briefs ) {
			out.push( {
				arm,
				brief: brief.id,
				split: brief.split,
				industry: brief.industry,
				...scoreStored( join( runsDir, arm, brief.id ), brief ),
			} );
		}
	}
	return out;
}

// The v1 measures. `context` is a site context for Block Runner, or null.
function scoreStored( dir, brief, context = null ) {
	const row = {};

	// Measure 1: first-try valid.
	const page = read( join( dir, 'page.html' ) );
	row.built = page !== null && page.includes( '<!-- wp:' );
	const v = row.built ? validate( join( dir, 'page.html' ), context ) : null;
	row.blocks = v?.blocks ?? 0;
	row.invalid = v?.invalid ?? null;
	row.warnings = v?.warnings ?? null;
	row.core_html = row.built ? ( page.match( /<!--\s*wp:html\b/g ) || [] ).length : null;
	row.m1_valid = Boolean( v && v.blocks > 0 && v.invalid === 0 && v.warnings === 0 );

	// Measure 2: bound, not typed. Each contact field the page carries counts once.
	const bound = row.built ? boundKeys( page ) : new Set();
	const text = row.built ? visibleText( page ) : '';
	let present = 0;
	let boundCount = 0;
	for ( const [ name, f ] of Object.entries( FIELDS ) ) {
		const isBound = f.keys.some( ( k ) => bound.has( k ) );
		const isTyped = name === 'phone' ? digitsIn( text ).includes( f.literal( brief ) ) : text.includes( f.literal( brief ) );
		row[ `${ name }_state` ] = isBound ? 'bound' : isTyped ? 'typed' : 'absent';
		if ( isBound || isTyped ) {
			present++;
		}
		if ( isBound ) {
			boundCount++;
		}
	}
	row.contact_fields = present;
	row.m2_bound_share = present ? round( boundCount / present ) : null;

	// Measure 3: edit survival after "change the phone number to <new_phone>".
	const after = read( join( dir, 'after-edit.html' ) );
	const siteData = readJson( join( dir, 'site-data.json' ) );
	const oldDigits = digits( brief.phone );
	const newDigits = digits( brief.new_phone );
	if ( after === null ) {
		row.m3_survived = false;
		row.m3_reason = 'no after-edit.html';
	} else {
		const va = validate( join( dir, 'after-edit.html' ), context );
		const afterText = digitsIn( visibleText( after ) + ' ' + hrefs( after ) );
		const afterBound = boundKeys( after );
		const phoneBound = afterBound.has( 'phone' ) || afterBound.has( 'phone_link' );
		const staleLeft = afterText.includes( oldDigits );
		const newShown = afterText.includes( newDigits ) || ( phoneBound && digits( siteData?.phone ?? '' ) === newDigits );
		const valid = Boolean( va && va.invalid === 0 && va.warnings === 0 );
		row.m3_survived = valid && ! staleLeft && newShown;
		row.m3_reason = ! valid ? 'invalid after edit' : staleLeft ? 'old phone still on page' : ! newShown ? 'new phone not shown' : '';
	}
	return row;
}

function validate( file, context = null ) {
	const res = spawnSync( process.execPath, [ blockRunner, 'validate', file, '--strict', '--json', ...( context ? [ '--context', context ] : [] ) ], { encoding: 'utf8' } );
	try {
		const out = JSON.parse( res.stdout );
		return { blocks: out.summary?.blocks ?? 0, invalid: out.summary?.invalid ?? 0, warnings: out.summary?.warnings ?? 0 };
	} catch {
		return null;
	}
}

// Keys bound to voyager/site-data anywhere in the markup's block comments.
function boundKeys( markup ) {
	const keys = new Set();
	const re = /"source"\s*:\s*"voyager\/site-data"\s*,\s*"args"\s*:\s*\{\s*"key"\s*:\s*"([a-z_]+)"/g;
	for ( const m of markup.matchAll( re ) ) {
		keys.add( m[ 1 ] );
	}
	return keys;
}

function visibleText( markup ) {
	return norm(
		markup
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<[^>]+>/g, ' ' )
			.replace( /&amp;/g, '&' )
			.replace( /&nbsp;/g, ' ' )
	);
}

function hrefs( markup ) {
	return [ ...markup.matchAll( /href="([^"]*)"/g ) ].map( ( m ) => m[ 1 ] ).join( ' ' );
}

function summarize( all ) {
	const groups = {};
	for ( const r of all ) {
		for ( const key of [ `${ r.arm }|all`, `${ r.arm }|${ r.split }` ] ) {
			( groups[ key ] ??= [] ).push( r );
		}
	}
	return Object.entries( groups ).map( ( [ key, rs ] ) => {
		const [ arm, split ] = key.split( '|' );
		const withContact = rs.filter( ( r ) => r.m2_bound_share !== null );
		return {
			arm,
			split,
			pages: rs.length,
			built: rs.filter( ( r ) => r.built ).length,
			m1_valid: rs.filter( ( r ) => r.m1_valid ).length,
			m2_bound_share: withContact.length ? round( withContact.reduce( ( s, r ) => s + r.m2_bound_share, 0 ) / withContact.length ) : null,
			m2_pages_with_contact: withContact.length,
			m3_survived: rs.filter( ( r ) => r.m3_survived ).length,
			core_html_blocks: rs.reduce( ( s, r ) => s + ( r.core_html ?? 0 ), 0 ),
		};
	} );
}

function toMarkdown( summary ) {
	const lines = [
		'| Arm | Split | Pages | First-try valid | Contact data bound | Edit survived | core/html blocks |',
		'|---|---|---:|---:|---:|---:|---:|',
	];
	for ( const s of summary ) {
		lines.push(
			`| ${ s.arm } | ${ s.split } | ${ s.pages } | ${ s.m1_valid }/${ s.pages } | ${ s.m2_bound_share === null ? 'n/a' : Math.round( s.m2_bound_share * 100 ) + '%' } (${ s.m2_pages_with_contact } pages) | ${ s.m3_survived }/${ s.pages } | ${ s.core_html_blocks } |`
		);
	}
	return lines.join( '\n' ) + '\n';
}

// ---------------------------------------------------------------------------
// Version 2

function scoreV2() {
	const known = new Map( briefs.map( ( b ) => [ b.id, b ] ) );
	const order = [ 'baseline', 'plugin', 'pack' ];
	const groups = dirs( runsDir ).sort( ( a, b ) => rank( a, order ) - rank( b, order ) || a.localeCompare( b ) );
	if ( ! groups.length ) {
		console.error( `No groups under ${ runsDir }.` );
		process.exit( 2 );
	}
	const models = new Set();
	const ids = new Set();
	const repeats = new Set();
	for ( const group of groups ) {
		for ( const model of dirs( join( runsDir, group ) ) ) {
			models.add( model );
			for ( const id of dirs( join( runsDir, group, model ) ) ) {
				ids.add( id );
				for ( const repeat of dirs( join( runsDir, group, model, id ) ) ) {
					repeats.add( repeat );
				}
			}
		}
	}
	const unknown = [ ...ids ].filter( ( id ) => ! known.has( id ) );
	if ( unknown.length ) {
		console.error( `Runs for briefs ${ briefsPath } does not hold: ${ unknown.join( ', ' ) }.` );
		process.exit( 2 );
	}
	const chosen = briefs.filter( ( b ) => ids.has( b.id ) );
	const out = [];
	for ( const group of groups ) {
		for ( const model of [ ...models ].sort() ) {
			for ( const brief of chosen ) {
				for ( const repeat of [ ...repeats ].sort( ( a, b ) => Number( a ) - Number( b ) || a.localeCompare( b ) ) ) {
					out.push( scoreRun( join( runsDir, group, model, brief.id, repeat ), { group, model, brief, repeat } ) );
				}
			}
		}
	}
	return out;
}

function scoreRun( dir, { group, model, brief, repeat } ) {
	const row = {
		group,
		model,
		brief: brief.id,
		repeat: Number( repeat ),
		split: brief.split,
		vertical: brief.vertical ?? brief.industry,
		credentials: brief.credentials.length,
		// Validated against the site's own blocks, from the Wesper manifest the
		// runner saved: a pack block on a site with the pack is registered.
		...scoreStored( dir, brief, existsSync( join( dir, 'wesper-manifest.json' ) ) ? join( dir, 'wesper-manifest.json' ) : null ),
	};
	const run = readJson( join( dir, 'run.json' ) );
	row.failure = run?.failure ?? ( run ? null : 'no run.json' );

	// Edit survival on the rendered page, through the cache: what a visitor
	// gets after the edit.
	const rendered = read( join( dir, 'after-edit.rendered.html' ) );
	if ( rendered === null ) {
		row.m3r_survived = false;
		row.m3r_reason = 'no rendered page after the edit';
	} else {
		const shown = digitsIn( renderedText( rendered ) + ' ' + telLinks( rendered ) );
		const staleLeft = shown.includes( digits( brief.phone ) );
		const newShown = shown.includes( digits( brief.new_phone ) );
		row.m3r_survived = ! staleLeft && newShown;
		row.m3r_reason = staleLeft ? 'old phone still on the rendered page' : ! newShown ? 'new phone not on the rendered page' : '';
	}
	const build = run?.cache?.build;
	const edit = run?.cache?.edit;
	row.cache_ok = Boolean( build?.from_cache && edit?.from_cache );
	row.cache_stale = edit?.stale ?? null;

	// Turns and cost, from each transcript's result event.
	for ( const step of [ 'build', 'edit' ] ) {
		const result = resultEvent( join( dir, `transcript-${ step }.jsonl` ) );
		row[ `turns_${ step }` ] = result?.num_turns ?? null;
		row[ `cost_${ step }_usd` ] = result?.total_cost_usd ?? null;
	}
	const costs = [ row.cost_build_usd, row.cost_edit_usd ].filter( ( c ) => typeof c === 'number' );
	row.cost_usd = costs.length ? round4( costs.reduce( ( a, b ) => a + b, 0 ) ) : null;

	// Claims traced, on the rendered build page.
	const page = read( join( dir, 'page.rendered.html' ) );
	if ( page === null ) {
		row.claims = null;
		row.claims_untraced = null;
		row.untraced_claims = [];
	} else {
		const found = claims( page, brief );
		row.claims = found.length;
		row.untraced_claims = found.filter( ( c ) => ! c.traced ).map( ( c ) => c.text );
		row.claims_untraced = row.untraced_claims.length;
	}

	// What the agent saw over MCP: the pack's abilities its discover calls listed.
	row.pack_abilities_listed = [ 'build', 'edit' ]
		.flatMap( ( step ) => listedAbilities( join( dir, `transcript-${ step }.jsonl` ) ) )
		.filter( ( name, i, all ) => name.startsWith( 'voyager-open-blocks/' ) && all.indexOf( name ) === i );
	return row;
}

/**
 * Each sentence on the page's main content that holds a claim word, and
 * whether it is traced: it contains one of the brief's credentials, or a
 * Site Data figure (the founding year, rating, review count or licence).
 * Other Site Data values, such as the services or the business name, source
 * no claim, so they trace nothing. A line that is only a label, such as
 * "Year founded", is traced when the line beside it is such a figure with no
 * word beside it, as in the stats row.
 * @param html
 * @param brief
 */
function claims( html, brief ) {
	const texts = ( brief.credentials || [] ).map( match ).filter( Boolean );
	const figures = [ brief.founded, brief.rating, brief.review_count, brief.license ].filter( ( f ) => f != null && f !== '' ).map( String );
	const holdsFigure = ( s ) => figures.some( ( f ) => figure( f ).test( s ) );
	// A figure on its own line: the figure, and no word beside it, as in
	// "1999", "4.8 / 5" or "276+".
	const isFigure = ( line ) => holdsFigure( line ) && ! /\p{L}/u.test( line );
	const lines = renderedLines( html, { main: true } );
	const out = [];
	lines.forEach( ( line, i ) => {
		const sentences = line.split( /(?<=[.!?])\s+(?=[A-Z0-9"'(])/ );
		for ( const sentence of sentences ) {
			const tested = NOT_CLAIMS.reduce( ( s, re ) => s.replace( re, ' ' ), sentence );
			if ( ! CLAIM_WORDS.test( tested ) ) {
				continue;
			}
			const m = match( sentence );
			const label = sentences.length === 1 && ! /[.!?]$/.test( sentence.trim() ) && ( isFigure( lines[ i - 1 ] ?? '' ) || isFigure( lines[ i + 1 ] ?? '' ) );
			const traced = texts.some( ( t ) => m.includes( t ) ) || holdsFigure( sentence ) || label;
			out.push( { text: sentence, traced } );
		}
	} );
	return out;
}

function figure( value ) {
	const escaped = value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	return new RegExp( `(?<![\\d.,])${ escaped }(?![\\d]|[.,]\\d)` );
}

// Lowercase, straight quotes, plain hyphens and single spaces, for comparing
// a brief's text with a page's.
function match( s ) {
	return String( s )
		.replace( /[‘’‛′]/g, "'" )
		.replace( /[“”″]/g, '"' )
		.replace( /[‐-―−]/g, '-' )
		.replace( /\s+/g, ' ' )
		.trim()
		.toLowerCase();
}

/**
 * A rendered page's text, one line per block of text, without scripts,
 * styles, comments or the head.
 * @param html
 * @param root0
 * @param root0.main Only the page's <main>, when it has one.
 */
function renderedLines( html, { main = false } = {} ) {
	let body = /<body\b[^>]*>([\s\S]*)<\/body>/i.exec( html )?.[ 1 ] ?? html;
	if ( main ) {
		body = /<main\b[^>]*>([\s\S]*)<\/main>/i.exec( body )?.[ 1 ] ?? body;
	}
	return decode(
		body
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<(script|style|noscript|template|svg)\b[\s\S]*?<\/\1>/gi, ' ' )
			.replace( new RegExp( `</?(?:${ BLOCK_TAGS })\\b[^>]*>`, 'gi' ), '\n' )
			.replace( /<[^>]+>/g, ' ' )
	)
		.split( '\n' )
		.map( ( l ) => l.replace( /\s+/g, ' ' ).trim() )
		.filter( Boolean );
}

function renderedText( html ) {
	// Lines joined by a mark digitsIn() stops at, so a number never runs on
	// into the next line's.
	return renderedLines( html ).join( ' | ' );
}

function telLinks( html ) {
	return [ ...html.matchAll( /href\s*=\s*["']\s*(tel:[^"']*)["']/gi ) ].map( ( m ) => decode( m[ 1 ] ) ).join( ' ' );
}

function decode( s ) {
	const named = { amp: '&', nbsp: ' ', quot: '"', apos: "'", lt: '<', gt: '>', rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“', ndash: '–', mdash: '—', hellip: '…', lpar: '(', rpar: ')', middot: '·', copy: '©', times: '×' };
	return String( s )
		.replace( /&#x([0-9a-f]+);/gi, ( all, h ) => String.fromCodePoint( parseInt( h, 16 ) ) )
		.replace( /&#(\d+);/g, ( all, d ) => String.fromCodePoint( Number( d ) ) )
		.replace( /&([a-z]+);/gi, ( all, n ) => named[ n.toLowerCase() ] ?? all )
		.replace( / /g, ' ' );
}

function events( file ) {
	const text = read( file );
	if ( text === null ) {
		return [];
	}
	return text
		.split( '\n' )
		.filter( Boolean )
		.map( ( l ) => {
			try {
				return JSON.parse( l );
			} catch {
				return null;
			}
		} )
		.filter( Boolean );
}

function resultEvent( file ) {
	return events( file ).findLast( ( e ) => e.type === 'result' ) ?? null;
}

// Every ability name the MCP Adapter's discover tool listed in a transcript.
function listedAbilities( file ) {
	const all = events( file );
	const ids = new Set();
	for ( const e of all.filter( ( x ) => x.type === 'assistant' ) ) {
		for ( const part of e.message?.content || [] ) {
			if ( part.type === 'tool_use' && part.name === 'mcp__wordpress__mcp-adapter-discover-abilities' ) {
				ids.add( part.id );
			}
		}
	}
	const names = [];
	for ( const e of all.filter( ( x ) => x.type === 'user' ) ) {
		for ( const part of e.message?.content || [] ) {
			if ( part.type !== 'tool_result' || ! ids.has( part.tool_use_id ) ) {
				continue;
			}
			const text = Array.isArray( part.content ) ? part.content.map( ( c ) => c?.text ?? '' ).join( '' ) : String( part.content ?? '' );
			try {
				for ( const a of JSON.parse( text ).abilities || [] ) {
					if ( a?.name ) {
						names.push( a.name );
					}
				}
			} catch {}
		}
	}
	return names;
}

function summarizeV2( all ) {
	const groups = {};
	for ( const r of all ) {
		for ( const split of [ 'all', r.split ] ) {
			( groups[ `${ r.group }|${ r.model }|${ split }` ] ??= [] ).push( r );
		}
	}
	const mean = ( xs ) => ( xs.length ? xs.reduce( ( a, b ) => a + b, 0 ) / xs.length : null );
	const nums = ( rs, key ) => rs.map( ( r ) => r[ key ] ).filter( ( v ) => typeof v === 'number' );
	return Object.entries( groups ).map( ( [ key, rs ] ) => {
		const [ group, model, split ] = key.split( '|' );
		const withContact = rs.filter( ( r ) => r.m2_bound_share !== null );
		const scored = rs.filter( ( r ) => r.claims !== null );
		const turnsBuild = mean( nums( rs, 'turns_build' ) );
		const turnsEdit = mean( nums( rs, 'turns_edit' ) );
		const cost = mean( nums( rs, 'cost_usd' ) );
		return {
			group,
			model,
			split,
			runs: rs.length,
			built: rs.filter( ( r ) => r.built ).length,
			m1_valid: rs.filter( ( r ) => r.m1_valid ).length,
			m2_bound_share: withContact.length ? round( withContact.reduce( ( s, r ) => s + r.m2_bound_share, 0 ) / withContact.length ) : null,
			m2_pages_with_contact: withContact.length,
			m3_survived: rs.filter( ( r ) => r.m3_survived ).length,
			m3r_survived: rs.filter( ( r ) => r.m3r_survived ).length,
			claims: scored.reduce( ( s, r ) => s + r.claims, 0 ),
			claims_untraced: scored.reduce( ( s, r ) => s + r.claims_untraced, 0 ),
			pages_with_untraced: scored.filter( ( r ) => r.claims_untraced > 0 ).length,
			pages_scored_for_claims: scored.length,
			turns_build_mean: turnsBuild === null ? null : round( turnsBuild ),
			turns_edit_mean: turnsEdit === null ? null : round( turnsEdit ),
			cost_usd_mean: cost === null ? null : round4( cost ),
			cache_ok: rs.filter( ( r ) => r.cache_ok ).length,
			harness_failures: rs.filter( ( r ) => /^harness:/.test( r.failure ?? '' ) ).length,
			core_html_blocks: rs.reduce( ( s, r ) => s + ( r.core_html ?? 0 ), 0 ),
		};
	} );
}

function toMarkdownV2( summary, all ) {
	const pct = ( v ) => ( v === null ? 'n/a' : Math.round( v * 100 ) + '%' );
	const num = ( v, d = 1 ) => ( v === null || v === undefined ? '-' : Number( v ).toFixed( d ) );
	const lines = [
		'| Group | Model | Split | Runs | First-try valid | Contact data bound | Edit survived (stored) | Edit survived (rendered) | Untraced claims | Turns, build / edit | Cost per brief | Cached loads | Harness failures |',
		'|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
	];
	for ( const s of summary ) {
		lines.push(
			`| ${ s.group } | ${ s.model } | ${ s.split } | ${ s.runs } | ${ s.m1_valid }/${ s.runs } | ${ pct( s.m2_bound_share ) } (${ s.m2_pages_with_contact } pages) | ${ s.m3_survived }/${ s.runs } | ${ s.m3r_survived }/${ s.runs } | ${ s.claims_untraced } of ${ s.claims } (${ s.pages_with_untraced } of ${ s.pages_scored_for_claims } pages) | ${ num( s.turns_build_mean ) } / ${ num( s.turns_edit_mean ) } | ${ s.cost_usd_mean === null ? '-' : '$' + num( s.cost_usd_mean, 2 ) } | ${ s.cache_ok }/${ s.runs } | ${ s.harness_failures } |`
		);
	}
	lines.push(
		'',
		'| Group | Model | Brief | Repeat | Valid | Bound | Edit (stored) | Edit (rendered) | Claims, untraced / all | Turns, build / edit | Cost | Cached loads | Pack abilities listed |',
		'|---|---|---|---:|---|---:|---|---|---:|---:|---:|---|---:|'
	);
	for ( const r of all ) {
		lines.push(
			`| ${ r.group } | ${ r.model } | ${ r.brief } | ${ r.repeat } | ${ r.m1_valid ? 'yes' : 'no' } | ${ pct( r.m2_bound_share ) } | ${ r.m3_survived ? 'yes' : `no: ${ r.m3_reason }` } | ${ r.m3r_survived ? 'yes' : `no: ${ r.m3r_reason }` } | ${ r.claims === null ? '-' : `${ r.claims_untraced } / ${ r.claims }` } | ${ r.turns_build ?? '-' } / ${ r.turns_edit ?? '-' } | ${ r.cost_usd === null ? '-' : '$' + num( r.cost_usd, 2 ) } | ${ r.cache_ok ? 'yes' : 'no' } | ${ r.pack_abilities_listed.length } |`
		);
	}
	const untraced = all.filter( ( r ) => r.untraced_claims.length );
	if ( untraced.length ) {
		lines.push( '', 'Untraced claims, per page:', '' );
		for ( const r of untraced ) {
			lines.push( `- ${ r.group }/${ r.model }/${ r.brief }/${ r.repeat }: ${ r.untraced_claims.map( ( c ) => `"${ c }"` ).join( '; ' ) }` );
		}
	}
	return lines.join( '\n' ) + '\n';
}

// ---------------------------------------------------------------------------

function toCsv( rs ) {
	const cols = Object.keys( rs[ 0 ] );
	const esc = ( v ) => {
		if ( v === null || v === undefined ) {
			return '';
		}
		const s = Array.isArray( v ) ? v.join( ' | ' ) : String( v );
		return /[",\n]/.test( s ) ? `"${ s.replace( /"/g, '""' ) }"` : s;
	};
	return [ cols.join( ',' ), ...rs.map( ( r ) => cols.map( ( c ) => esc( r[ c ] ) ).join( ',' ) ) ].join( '\n' ) + '\n';
}

function dirs( p ) {
	return existsSync( p )
		? readdirSync( p, { withFileTypes: true } ).filter( ( d ) => d.isDirectory() ).map( ( d ) => d.name ).sort()
		: [];
}
function rank( name, order ) {
	const i = order.indexOf( name );
	return i === -1 ? order.length : i;
}
function read( p ) {
	return existsSync( p ) ? readFileSync( p, 'utf8' ) : null;
}
function readJson( p ) {
	try {
		return JSON.parse( readFileSync( p, 'utf8' ) );
	} catch {
		return null;
	}
}
function digits( s ) {
	return String( s ).replace( /\D/g, '' );
}
// Digits of each phone-like run, space-separated, so two adjacent numbers can't
// combine into a false match.
function digitsIn( s ) {
	return ( String( s ).match( /\+?\d[\d\s().-]{5,}\d/g ) || [] ).map( digits ).join( ' ' );
}
function norm( s ) {
	return String( s ).replace( /\s+/g, ' ' ).trim().toLowerCase();
}
function round( n ) {
	return Math.round( n * 1000 ) / 1000;
}
function round4( n ) {
	return Math.round( n * 10000 ) / 10000;
}
function parseArgs( argv ) {
	const out = {};
	for ( let i = 0; i < argv.length; i++ ) {
		if ( argv[ i ].startsWith( '--' ) ) {
			out[ argv[ i ].slice( 2 ) ] = argv[ i + 1 ];
			i++;
		}
	}
	return out;
}
