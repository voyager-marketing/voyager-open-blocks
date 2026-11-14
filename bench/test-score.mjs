#!/usr/bin/env node
// Known-answer test for score.mjs.
//
// Version 1 (fixtures/runs): a bound page that survives the edit, a typed
// page whose tel: link goes stale, an invalid page, and a missing build.
//
// Version 2 (fixtures/runs-v2): a page whose stored markup survives the edit
// while the cached render still shows the old phone; a pack block validated
// against the run's site context, and the same block on a site without the
// pack; a rendered page that
// gets the edit right beside a ZIP+4 and a year; a tel: link left on the old
// number; traced and untraced claims; turns and cost from the transcripts;
// the pack abilities a discover call listed; a harness failure; and runs
// missing from two groups.

import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const here = dirname( fileURLToPath( import.meta.url ) );
let checks = 0;
const check = ( fn ) => {
	fn();
	checks++;
};

function score( runs, briefs ) {
	const out = mkdtempSync( join( tmpdir(), 'bench-score-' ) );
	const res = spawnSync( process.execPath, [ join( here, 'score.mjs' ), '--runs', join( here, 'fixtures', runs ), '--briefs', join( here, 'fixtures', briefs ), '--out', out ], { encoding: 'utf8' } );
	assert.equal( res.status, 0, res.stderr );
	return {
		rows: JSON.parse( readFileSync( join( out, 'results.json' ), 'utf8' ) ),
		summary: JSON.parse( readFileSync( join( out, 'summary.json' ), 'utf8' ) ),
		md: readFileSync( join( out, 'summary.md' ), 'utf8' ),
	};
}

// ---------------------------------------------------------------------------
// Version 1

{
	const { rows } = score( 'runs', 'briefs.json' );
	const get = ( arm, brief ) => rows.find( ( r ) => r.arm === arm && r.brief === brief );

	check( () => {
		const loopOk = get( 'loop', 'ridgeline-roofing' );
		assert.equal( loopOk.m1_valid, true, 'bound page is valid' );
		assert.equal( loopOk.m2_bound_share, 1, 'phone, email and address are all bound' );
		assert.equal( loopOk.m3_survived, true, 'bound page survives the phone change' );
	} );

	check( () => {
		const typed = get( 'baseline', 'ridgeline-roofing' );
		assert.equal( typed.m1_valid, true, 'typed page is still valid markup' );
		assert.equal( typed.m2_bound_share, 0, 'nothing bound' );
		assert.equal( typed.m3_survived, false, 'stale tel: link fails the edit' );
		assert.equal( typed.m3_reason, 'old phone still on page' );
	} );

	check( () => {
		const invalid = get( 'baseline', 'brightpath-hvac' );
		assert.equal( invalid.m1_valid, false, 'unclosed paragraph is invalid' );
	} );

	check( () => {
		const missing = get( 'loop', 'brightpath-hvac' );
		assert.equal( missing.built, false, 'missing page.html counts as a failed build' );
		assert.equal( missing.m3_survived, false );
	} );
}

// ---------------------------------------------------------------------------
// Version 2

{
	const { rows, summary, md } = score( 'runs-v2', 'briefs-v2.json' );
	const get = ( group, repeat ) => rows.find( ( r ) => r.group === group && r.model === 'claude-sonnet-5' && r.brief === 'quillmere-plumbing' && r.repeat === repeat );

	check( () => {
		assert.equal( rows.length, 6, 'three groups times two repeats, each expected in every group' );
	} );

	check( () => {
		const stale = get( 'pack', 1 );
		assert.equal( stale.m1_valid, true, 'the bound page is valid' );
		assert.equal( stale.m2_bound_share, 1 );
		assert.equal( stale.m3_survived, true, 'the stored markup survives the edit: bound, and the site holds the new phone' );
		assert.equal( stale.m3r_survived, false, 'the cached render still shows the old phone, so the edit fails' );
		assert.equal( stale.m3r_reason, 'old phone still on the rendered page' );
		assert.equal( stale.cache_ok, true, 'both second loads came from the cache' );
		assert.equal( stale.cache_stale, true );
	} );

	check( () => {
		assert.equal( get( 'pack', 1 ).warnings, 0, 'a pack block validates against the run site context, which registers it' );
		const noPack = get( 'baseline', 2 );
		assert.equal( noPack.m1_valid, false, 'the same block on a site without the pack warns as unregistered' );
		assert.ok( noPack.warnings > 0 );
	} );

	check( () => {
		const fresh = get( 'baseline', 1 );
		assert.equal( fresh.m3r_survived, true, 'the new phone shows, and a ZIP+4 and a year are not the old phone' );
		assert.equal( fresh.m3r_reason, '' );
	} );

	check( () => {
		const telLeft = get( 'baseline', 2 );
		assert.equal( telLeft.m3r_survived, false, 'a tel: link on the old number fails the rendered edit' );
		assert.equal( telLeft.m3r_reason, 'old phone still on the rendered page' );
	} );

	check( () => {
		const stale = get( 'pack', 1 );
		assert.equal( stale.turns_build, 7 );
		assert.equal( stale.turns_edit, 3 );
		assert.equal( stale.cost_build_usd, 0.1234 );
		assert.equal( stale.cost_edit_usd, 0.0456 );
		assert.equal( stale.cost_usd, 0.169, 'cost per brief is build plus edit' );
		const noTranscripts = get( 'baseline', 2 );
		assert.equal( noTranscripts.turns_build, null, 'no transcript, no turns' );
		assert.equal( noTranscripts.cost_usd, null );
	} );

	check( () => {
		const stale = get( 'pack', 1 );
		assert.deepEqual( stale.untraced_claims, [ 'The best plumbers in the county.' ], 'one untraced claim; the header, footer and JSON-LD are not the page' );
		assert.equal( stale.claims, 7, 'a credential, a year, three figure labels and a rating are traced; started, operated, misleading and feel free make no claim' );
		assert.equal( stale.claims_untraced, 1 );
	} );

	check( () => {
		const typed = get( 'baseline', 1 );
		assert.deepEqual( typed.untraced_claims, [ 'Over 20 years of experience.' ], 'a credential in a heading is traced, a years claim with no source is not' );
		assert.equal( typed.claims, 2 );
	} );

	check( () => {
		assert.deepEqual( get( 'pack', 1 ).pack_abilities_listed, [ 'voyager-open-blocks/insert', 'voyager-open-blocks/bind' ], 'from both transcripts, string and array results' );
		assert.deepEqual( get( 'baseline', 1 ).pack_abilities_listed, [], 'a failed call by name is not a listing' );
	} );

	check( () => {
		const failed = get( 'plugin', 1 );
		assert.match( failed.failure, /^harness: probe:/ );
		assert.equal( failed.built, false );
		assert.equal( failed.cache_ok, false );
		const missing = get( 'pack', 2 );
		assert.equal( missing.built, false, 'a run missing from a group scores as a failed build' );
		assert.equal( missing.m3r_survived, false );
		assert.equal( missing.m3r_reason, 'no rendered page after the edit' );
		assert.equal( missing.claims, null );
		assert.equal( missing.failure, 'no run.json' );
	} );

	check( () => {
		const of = ( group, split ) => summary.find( ( s ) => s.group === group && s.model === 'claude-sonnet-5' && s.split === split );
		assert.deepEqual( summary.map( ( s ) => `${ s.group }|${ s.split }` ), [ 'baseline|all', 'baseline|derive', 'plugin|all', 'plugin|derive', 'pack|all', 'pack|derive' ], 'by group and model, then split, groups in order' );
		assert.equal( of( 'pack', 'all' ).m3_survived, 1 );
		assert.equal( of( 'pack', 'all' ).m3r_survived, 0 );
		assert.equal( of( 'baseline', 'all' ).m3r_survived, 1 );
		assert.equal( of( 'baseline', 'all' ).claims_untraced, 2 );
		assert.equal( of( 'plugin', 'all' ).harness_failures, 1 );
		assert.match( md, /\| pack \| claude-sonnet-5 \| quillmere-plumbing \| 1 \| yes \| 100% \| yes \| no: old phone still on the rendered page \| 1 \/ 7 \| 7 \/ 3 \| \$0\.17 \| yes \| 2 \|/ );
	} );
}

// ---------------------------------------------------------------------------
// The claim words are tests/Unit/PatternLayoutsTest.php's, with the changes
// score.mjs names: no bare digit and no #, and rating and review start a word.

check( () => {
	const php = readFileSync( join( here, '..', 'tests', 'Unit', 'PatternLayoutsTest.php' ), 'utf8' );
	const source = /private const CLAIMS = '\/(.+)\/i';/.exec( php )?.[ 1 ];
	assert.ok( source, 'PatternLayoutsTest.php holds the CLAIMS list' );
	const expected = source
		.split( '|' )
		.filter( ( part ) => ! [ '#', '\\d' ].includes( part ) )
		.map( ( part ) => ( [ 'rating', 'review' ].includes( part ) ? `\\b${ part }` : part ) )
		.join( '|' );
	const scorer = /const CLAIM_WORDS = \/(.+)\/i;/.exec( readFileSync( join( here, 'score.mjs' ), 'utf8' ) )?.[ 1 ];
	assert.equal( scorer, expected, 'score.mjs CLAIM_WORDS follows PatternLayoutsTest.php CLAIMS' );
} );

console.log( `ok   bench/score.mjs known-answer fixtures (${ checks }/${ checks })` );
