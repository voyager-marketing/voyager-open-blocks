#!/usr/bin/env node
// Scores the with/without benchmark (VOY-1774), measures 1 to 3. No judge, no network.
//
//   node bench/score.mjs [--runs bench/runs] [--briefs bench/briefs.json] [--out bench/results]
//
// Expected layout, one folder per arm and brief:
//
//   runs/<arm>/<brief-id>/page.html        post_content saved after the build task
//   runs/<arm>/<brief-id>/after-edit.html  post_content after the "change the phone" task
//   runs/<arm>/<brief-id>/site-data.json   optional: voyager_site_* values after the edit task,
//                                          as {"phone": "...", "email": "...", ...}
//
// A missing page.html scores as a failed build, never as skipped.

import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const args = parseArgs( process.argv.slice( 2 ) );
const runsDir = resolve( args.runs ?? join( here, 'runs' ) );
const briefsPath = resolve( args.briefs ?? join( here, 'briefs.json' ) );
const outDir = resolve( args.out ?? join( here, 'results' ) );
// The CLI's JS entry, run with this Node: the .bin shim is a shell script that
// spawnSync cannot start on Windows, so every page scored invalid there.
const blockRunner = resolve( here, '..', 'node_modules', 'block-runner', 'dist', 'cli.js' );

const { briefs } = JSON.parse( readFileSync( briefsPath, 'utf8' ) );
const arms = existsSync( runsDir )
	? readdirSync( runsDir, { withFileTypes: true } ).filter( ( d ) => d.isDirectory() ).map( ( d ) => d.name ).sort()
	: [];
if ( ! arms.length ) {
	console.error( `No arms under ${ runsDir }.` );
	process.exit( 2 );
}

const FIELDS = {
	phone: { keys: [ 'phone', 'phone_link' ], literal: ( b ) => digits( b.phone ) },
	email: { keys: [ 'email', 'email_link' ], literal: ( b ) => b.email.toLowerCase() },
	address: { keys: [ 'address', 'address_locality' ], literal: ( b ) => norm( b.address ) },
};

const rows = [];
for ( const arm of arms ) {
	for ( const brief of briefs ) {
		rows.push( scoreOne( arm, brief ) );
	}
}

mkdirSync( outDir, { recursive: true } );
writeFileSync( join( outDir, 'results.json' ), JSON.stringify( rows, null, 2 ) + '\n' );
writeFileSync( join( outDir, 'results.csv' ), toCsv( rows ) );
const summary = summarize( rows );
writeFileSync( join( outDir, 'summary.json' ), JSON.stringify( summary, null, 2 ) + '\n' );
const md = toMarkdown( summary );
writeFileSync( join( outDir, 'summary.md' ), md );
console.log( md );

// ---------------------------------------------------------------------------

function scoreOne( arm, brief ) {
	const dir = join( runsDir, arm, brief.id );
	const row = { arm, brief: brief.id, split: brief.split, industry: brief.industry };

	// Measure 1: first-try valid.
	const page = read( join( dir, 'page.html' ) );
	row.built = page !== null && page.includes( '<!-- wp:' );
	const v = row.built ? validate( join( dir, 'page.html' ) ) : null;
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
		const va = validate( join( dir, 'after-edit.html' ) );
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

function validate( file ) {
	const res = spawnSync( process.execPath, [ blockRunner, 'validate', file, '--strict', '--json' ], { encoding: 'utf8' } );
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

function toCsv( rs ) {
	const cols = Object.keys( rs[ 0 ] );
	const esc = ( v ) => ( v === null || v === undefined ? '' : /[",\n]/.test( String( v ) ) ? `"${ String( v ).replace( /"/g, '""' ) }"` : String( v ) );
	return [ cols.join( ',' ), ...rs.map( ( r ) => cols.map( ( c ) => esc( r[ c ] ) ).join( ',' ) ) ].join( '\n' ) + '\n';
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
