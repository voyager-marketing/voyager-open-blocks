#!/usr/bin/env node
// Known-answer test for score.mjs and the measures it takes from measures.mjs.
// npm run check runs it, with a floor on the number of tests.
//
// Version 1 (fixtures/runs): a bound page that survives the edit, a typed
// page whose tel: link goes stale, an invalid page, and a missing build.
//
// Version 2 (fixtures/runs-v2): a page whose stored markup survives the edit
// while the cached render still shows the old phone; a pack block validated
// against the run's site context, and the same block on a site without the
// pack; a rendered page that gets the edit right beside a ZIP+4 and a year; a
// tel: link left on the old number; traced and untraced claims; turns and
// cost from the transcripts, the edit resuming the build's session; the pack
// abilities a discover call listed; a harness failure; and runs missing from
// two groups.
//
// The smoke runs (fixtures/runs-v2-smoke): the main content of the three
// rendered pages of the first benchmark smoke runs, one brief in the pack
// group, line for line as the scorer reads them, and the result event of each
// transcript. Twelve sentences scored untraced there. Seven were rewordings of
// the brief's own credentials; the rest are the agent's own claims.
//
// Then the rules one at a time, through measures.mjs: tracing per claim word,
// years and decades only next to a number, a figure derived from the year
// founded, and the edit's own cost.

import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import test from 'node:test';

const here = dirname( fileURLToPath( import.meta.url ) );
const MODEL = 'claude-sonnet-5';

function score( runs, briefs ) {
	const out = mkdtempSync( join( tmpdir(), 'bench-score-' ) );
	try {
		const res = spawnSync( process.execPath, [ join( here, 'score.mjs' ), '--runs', join( here, 'fixtures', runs ), '--briefs', join( here, 'fixtures', briefs ), '--out', out ], { encoding: 'utf8' } );
		assert.equal( res.status, 0, res.stderr );
		return {
			rows: JSON.parse( readFileSync( join( out, 'results.json' ), 'utf8' ) ),
			summary: JSON.parse( readFileSync( join( out, 'summary.json' ), 'utf8' ) ),
			md: readFileSync( join( out, 'summary.md' ), 'utf8' ),
		};
	} finally {
		rmSync( out, { recursive: true, force: true } );
	}
}

// Each fixture tree is scored once, by the first test that needs it. A crash
// fails every test that reads it.
function once( fn ) {
	let result;
	return () => ( result ??= fn() );
}
const v1 = once( () => score( 'runs', 'briefs.json' ) );
const v2 = once( () => score( 'runs-v2', 'briefs-v2.json' ) );
const smoke = once( () => score( 'runs-v2-smoke', 'briefs-v2.json' ) );
const measures = () => import( './measures.mjs' );
const kestovar = () => JSON.parse( readFileSync( join( here, 'fixtures', 'briefs-v2.json' ), 'utf8' ) ).briefs.find( ( b ) => b.id === 'kestovar-roofing' );
const page = ( ...lines ) => `<main>${ lines.map( ( l ) => `<p>${ l }</p>` ).join( '' ) }</main>`;

// ---------------------------------------------------------------------------
// Version 1

const getV1 = ( arm, brief ) => v1().rows.find( ( r ) => r.arm === arm && r.brief === brief );

test( 'v1: a bound page is valid, bound and survives the edit', () => {
	const loopOk = getV1( 'loop', 'ridgeline-roofing' );
	assert.equal( loopOk.m1_valid, true, 'bound page is valid' );
	assert.equal( loopOk.m2_bound_share, 1, 'phone, email and address are all bound' );
	assert.equal( loopOk.m3_survived, true, 'bound page survives the phone change' );
} );

test( 'v1: a typed page fails the edit on a stale tel: link', () => {
	const typed = getV1( 'baseline', 'ridgeline-roofing' );
	assert.equal( typed.m1_valid, true, 'typed page is still valid markup' );
	assert.equal( typed.m2_bound_share, 0, 'nothing bound' );
	assert.equal( typed.m3_survived, false, 'stale tel: link fails the edit' );
	assert.equal( typed.m3_reason, 'old phone still on page' );
} );

test( 'v1: an unclosed paragraph is invalid', () => {
	assert.equal( getV1( 'baseline', 'brightpath-hvac' ).m1_valid, false );
} );

test( 'v1: a missing page.html is a failed build', () => {
	const missing = getV1( 'loop', 'brightpath-hvac' );
	assert.equal( missing.built, false, 'missing page.html counts as a failed build' );
	assert.equal( missing.m3_survived, false );
} );

// ---------------------------------------------------------------------------
// Version 2

const getV2 = ( group, repeat ) => v2().rows.find( ( r ) => r.group === group && r.model === MODEL && r.brief === 'quillmere-plumbing' && r.repeat === repeat );

test( 'v2: every run is expected in every group', () => {
	assert.equal( v2().rows.length, 6, 'three groups times two repeats, each expected in every group' );
} );

test( 'v2: stored markup survives the edit while the cached render goes stale', () => {
	const stale = getV2( 'pack', 1 );
	assert.equal( stale.m1_valid, true, 'the bound page is valid' );
	assert.equal( stale.m2_bound_share, 1 );
	assert.equal( stale.m3_survived, true, 'the stored markup survives the edit: bound, and the site holds the new phone' );
	assert.equal( stale.m3r_survived, false, 'the cached render still shows the old phone, so the edit fails' );
	assert.equal( stale.m3r_reason, 'old phone still on the rendered page' );
	assert.equal( stale.cache_ok, true, 'both second loads came from the cache' );
	assert.equal( stale.cache_stale, true );
} );

test( 'v2: a pack block validates against the run site context only', () => {
	assert.equal( getV2( 'pack', 1 ).warnings, 0, 'a pack block validates against the run site context, which registers it' );
	const noPack = getV2( 'baseline', 2 );
	assert.equal( noPack.m1_valid, false, 'the same block on a site without the pack warns as unregistered' );
	assert.ok( noPack.warnings > 0 );
} );

test( 'v2: a ZIP+4 and a year are not the old phone', () => {
	const fresh = getV2( 'baseline', 1 );
	assert.equal( fresh.m3r_survived, true, 'the new phone shows, and a ZIP+4 and a year are not the old phone' );
	assert.equal( fresh.m3r_reason, '' );
} );

test( 'v2: a tel: link on the old number fails the rendered edit', () => {
	const telLeft = getV2( 'baseline', 2 );
	assert.equal( telLeft.m3r_survived, false );
	assert.equal( telLeft.m3r_reason, 'old phone still on the rendered page' );
} );

test( 'v2: turns per step, and cost per brief is the build plus the edit own cost', () => {
	const stale = getV2( 'pack', 1 );
	assert.equal( stale.turns_build, 7 );
	assert.equal( stale.turns_edit, 3 );
	assert.equal( stale.cost_build_usd, 0.1234 );
	assert.equal( stale.cost_edit_usd, 0.0456, 'the edit resumed the build session, whose running total was 0.169, so its own cost is the difference' );
	assert.equal( stale.cost_usd, 0.169, 'cost per brief is the build plus the edit own cost, not plus the running total' );
	const noTranscripts = getV2( 'baseline', 2 );
	assert.equal( noTranscripts.turns_build, null, 'no transcript, no turns' );
	assert.equal( noTranscripts.cost_usd, null );
} );

test( 'v2: claims on the main content, traced or not', () => {
	const stale = getV2( 'pack', 1 );
	assert.deepEqual( stale.untraced_claims, [ 'The best plumbers in the county.' ], 'one untraced claim; the header, footer and JSON-LD are not the page' );
	assert.equal( stale.claims, 6, 'a credential, a year, two figure labels and a rating are traced; "Year founded" names no number, so it is no claim; started, operated, misleading and feel free make no claim' );
	assert.equal( stale.claims_untraced, 1 );
	const typed = getV2( 'baseline', 1 );
	assert.deepEqual( typed.untraced_claims, [ 'Over 20 years of experience.' ], 'a credential in a heading is traced; years with no run date to count from are not' );
	assert.equal( typed.claims, 2 );
} );

test( 'v2: the pack abilities a discover call listed', () => {
	assert.deepEqual( getV2( 'pack', 1 ).pack_abilities_listed, [ 'voyager-open-blocks/insert', 'voyager-open-blocks/bind' ], 'from both transcripts, string and array results' );
	assert.deepEqual( getV2( 'baseline', 1 ).pack_abilities_listed, [], 'a failed call by name is not a listing' );
} );

test( 'v2: a harness failure, and a run missing from a group', () => {
	const failed = getV2( 'plugin', 1 );
	assert.match( failed.failure, /^harness: probe:/ );
	assert.equal( failed.built, false );
	assert.equal( failed.cache_ok, false );
	const missing = getV2( 'pack', 2 );
	assert.equal( missing.built, false, 'a run missing from a group scores as a failed build' );
	assert.equal( missing.m3r_survived, false );
	assert.equal( missing.m3r_reason, 'no rendered page after the edit' );
	assert.equal( missing.claims, null );
	assert.equal( missing.failure, 'no run.json' );
} );

test( 'v2: the summary, by group and model, then split', () => {
	const { summary, md } = v2();
	const of = ( group, split ) => summary.find( ( s ) => s.group === group && s.model === MODEL && s.split === split );
	assert.deepEqual( summary.map( ( s ) => `${ s.group }|${ s.split }` ), [ 'baseline|all', 'baseline|derive', 'plugin|all', 'plugin|derive', 'pack|all', 'pack|derive' ], 'groups in order' );
	assert.equal( of( 'pack', 'all' ).m3_survived, 1 );
	assert.equal( of( 'pack', 'all' ).m3r_survived, 0 );
	assert.equal( of( 'baseline', 'all' ).m3r_survived, 1 );
	assert.equal( of( 'baseline', 'all' ).claims_untraced, 2 );
	assert.equal( of( 'plugin', 'all' ).harness_failures, 1 );
	assert.equal( of( 'pack', 'all' ).cost_usd_mean, 0.169 );
	assert.match( md, /\| pack \| claude-sonnet-5 \| quillmere-plumbing \| 1 \| yes \| 100% \| yes \| no: old phone still on the rendered page \| 1 \/ 6 \| 7 \/ 3 \| \$0\.17 \| yes \| 2 \|/ );
} );

// ---------------------------------------------------------------------------
// The smoke runs

const getSmoke = ( repeat ) => smoke().rows.find( ( r ) => r.brief === 'kestovar-roofing' && r.repeat === repeat );

test( 'smoke runs: only the agent own claims stay untraced', () => {
	assert.deepEqual(
		[ 1, 2, 3 ].map( ( repeat ) => getSmoke( repeat ).untraced_claims ),
		[
			[ 'Free estimate', 'Ready for a free roofing quote?', 'Call or email us today and we will schedule your free estimate.' ],
			[ 'Ready for a free roofing estimate?' ],
			[],
		],
		'a free estimate or quote is in no credential; free inspections, licensed and manufacturer-certified crews are'
	);
	assert.deepEqual( [ 1, 2, 3 ].map( ( repeat ) => getSmoke( repeat ).untraced_claim_words ), [ [ 'free', 'free', 'free' ], [ 'free' ], [] ], 'each untraced sentence names the claim word no Site Data holds' );
	assert.deepEqual( [ 1, 2, 3 ].map( ( repeat ) => getSmoke( repeat ).claims ), [ 14, 10, 8 ], 'every claim the old rule saw but two "Year founded" labels, which name no number' );
} );

test( 'smoke runs: the reworded credential is traced (the fitness case)', () => {
	const second = getSmoke( 2 );
	assert.ok( second.claims > 0 );
	assert.ok(
		! second.untraced_claims.includes( 'Licensed, manufacturer-certified crews serving Kestovar and nearby towns, with written estimates in two days.' ),
		'"licensed" stands on "Licensed and insured", a credential of nothing but claim words, and "certified" shares "manufacturer" with "Manufacturer-certified installers"'
	);
} );

test( 'smoke runs: cost per brief is the build plus the edit own cost', () => {
	assert.deepEqual(
		[ 1, 2, 3 ].map( ( repeat ) => {
			const r = getSmoke( repeat );
			return [ r.cost_build_usd, r.cost_edit_usd, r.cost_usd ];
		} ),
		[
			[ 0.6821592000000001, 0.0717, 0.7539 ],
			[ 0.6827304000000001, 0.1089, 0.7916 ],
			[ 0.6028847999999999, 0.0867, 0.6896 ],
		],
		'each edit resumed its build session, so its total_cost_usd is the running total'
	);
	assert.equal( smoke().summary.find( ( s ) => s.split === 'all' ).cost_usd_mean, 0.745, 'the mean of the three, not about 1.40' );
} );

// ---------------------------------------------------------------------------
// The rules, one at a time

test( 'a claim word is traced when one credential or service holds it and shares another content word', async () => {
	const { claims } = await measures();
	const brief = kestovar();
	const untraced = ( ...lines ) => claims( page( ...lines ), brief, { year: 2026 } ).map( ( c ) => [ c.text, c.untraced ] );
	assert.deepEqual(
		untraced(
			'Fast fixes for leaks, with free inspections.',
			'Free estimate',
			'Licensed roofers, the best in Ohio.',
			'Certified roofers.',
			'Built with manufacturer-certified materials.',
			'Backed by a 10 year warranty.',
			'Backed by a 25-year warranty.',
			'Licensed and insured'
		),
		[
			[ 'Fast fixes for leaks, with free inspections.', [] ],
			[ 'Free estimate', [ 'free' ] ],
			[ 'Licensed roofers, the best in Ohio.', [ 'best' ] ],
			[ 'Certified roofers.', [ 'certified' ] ],
			[ 'Built with manufacturer-certified materials.', [] ],
			[ 'Backed by a 10 year warranty.', [] ],
			[ 'Backed by a 25-year warranty.', [ '25-year' ] ],
			[ 'Licensed and insured', [] ],
		],
		'free shares inspection; a free estimate shares nothing; licensed stands alone, best does not; certified needs manufacturer; ten years is the warranty, 25 is not'
	);
	const services = { ...brief, credentials: [], services: [ 'Same-day roof and gutter repair' ] };
	assert.deepEqual(
		claims( page( 'Same-day repairs for your gutters.', 'Same-day roof replacement.' ), services, { year: 2026 } ).map( ( c ) => c.untraced ),
		[ [], [ 'same-day' ] ],
		'a service traces too; roof names the business, so it links no claim'
	);
} );

test( 'years and decades are claim words only next to a number or since', async () => {
	const { CLAIM_WORDS, YEARS } = await measures();
	for ( const text of [ 'Built to last for years.', 'Built to last for decades.', 'Year founded', 'In recent years we grew.', 'Open year-round', 'Call (555) 555-0125 any day of the year', 'Kestovar, OH 43968-1234', 'From 1999 to 2026', 'Monday to Friday, 7am to 5pm' ] ) {
		assert.doesNotMatch( text, CLAIM_WORDS, `"${ text }" makes no claim` );
	}
	const years = new RegExp( YEARS, 'i' );
	for ( const text of [ 'Over 25 years in Kestovar', '25+ years', 'A 10-year warranty', 'Two decades on the job', 'Twenty-five years of roofs', 'Years since 1999' ] ) {
		assert.match( text, years, `"${ text }" makes a years claim` );
	}
} );

test( 'years since the year founded count as a Site Data figure', async () => {
	const { claims } = await measures();
	const brief = kestovar();
	const lines = [ 'Over 25 years serving Kestovar.', '27 years on local roofs.', 'Over 30 years of experience.', 'Two decades of roofing.', 'Three decades of roofing.', 'Since 1999.', 'A 25-year roof.' ];
	assert.deepEqual(
		claims( page( ...lines ), brief, { year: 2026 } ).map( ( c ) => [ c.text, c.traced ] ),
		[
			[ 'Over 25 years serving Kestovar.', true ],
			[ '27 years on local roofs.', true ],
			[ 'Over 30 years of experience.', false ],
			[ 'Two decades of roofing.', true ],
			[ 'Three decades of roofing.', false ],
			[ 'Since 1999.', true ],
			[ 'A 25-year roof.', false ],
		],
		'27 years since 1999 in 2026: up to 27 years is traced, 30 is not, and a 25-year roof is no age'
	);
	assert.deepEqual(
		claims( page( 'Over 25 years serving Kestovar.' ), brief ).map( ( c ) => c.traced ),
		[ false ],
		'with no run date the years cannot be counted, so they are not traced'
	);
} );

test( 'the edit own cost is its running total less the build, when it resumed the build session', async () => {
	const { stepCosts } = await measures();
	const step = ( session_id, total_cost_usd ) => ( { type: 'result', session_id, total_cost_usd } );
	assert.deepEqual( stepCosts( step( 'a', 0.6827304 ), step( 'a', 0.7916488 ) ), { build: 0.6827304, edit: 0.1089, total: 0.7916 }, 'resumed: the difference' );
	assert.deepEqual( stepCosts( step( 'a', 0.1 ), step( 'b', 0.05 ) ), { build: 0.1, edit: 0.05, total: 0.15 }, 'a session of its own: its own total' );
	assert.deepEqual( stepCosts( step( 'a', 0.2 ), step( 'a', 0.1 ) ), { build: 0.2, edit: null, total: null }, 'a running total below the build cannot be split, so the cost is unknown, never a guess' );
	assert.deepEqual( stepCosts( step( 'a', 0.2 ), null ), { build: 0.2, edit: null, total: 0.2 }, 'no edit transcript: the build alone, as before' );
	assert.deepEqual( stepCosts( null, null ), { build: null, edit: null, total: null } );
} );

// ---------------------------------------------------------------------------
// The claim words are tests/Unit/PatternLayoutsTest.php's, with the changes
// measures.mjs names: no bare digit and no #, rating and review start a word,
// and years count only next to a number or "since", decades with them.

test( 'the claim words follow PatternLayoutsTest.php CLAIMS', async () => {
	const { CLAIM_PARTS, YEARS } = await measures();
	const php = readFileSync( join( here, '..', 'tests', 'Unit', 'PatternLayoutsTest.php' ), 'utf8' );
	const source = /private const CLAIMS = '\/(.+)\/i';/.exec( php )?.[ 1 ];
	assert.ok( source, 'PatternLayoutsTest.php holds the CLAIMS list; if it moved, read it from its new home here' );
	const expected = source
		.split( '|' )
		.filter( ( part ) => ! [ '#', '\\d' ].includes( part ) )
		.map( ( part ) => ( [ 'rating', 'review' ].includes( part ) ? `\\b${ part }` : part ) )
		.map( ( part ) => ( part === 'years?\\b' ? YEARS : part ) );
	assert.ok( expected.includes( YEARS ), 'the PHP list still holds years?\\b, which this replaces' );
	assert.deepEqual( CLAIM_PARTS, expected );
} );
