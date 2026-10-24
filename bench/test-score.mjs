#!/usr/bin/env node
// Known-answer test for score.mjs: the fixtures cover a bound page that survives the
// edit, a typed page whose tel: link goes stale, an invalid page, and a missing build.

import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = mkdtempSync( join( tmpdir(), 'bench-score-' ) );
const res = spawnSync(
	process.execPath,
	[ join( here, 'score.mjs' ), '--runs', join( here, 'fixtures', 'runs' ), '--briefs', join( here, 'fixtures', 'briefs.json' ), '--out', out ],
	{ encoding: 'utf8' }
);
assert.equal( res.status, 0, res.stderr );

const rows = JSON.parse( readFileSync( join( out, 'results.json' ), 'utf8' ) );
const get = ( arm, brief ) => rows.find( ( r ) => r.arm === arm && r.brief === brief );

const loopOk = get( 'loop', 'ridgeline-roofing' );
assert.equal( loopOk.m1_valid, true, 'bound page is valid' );
assert.equal( loopOk.m2_bound_share, 1, 'phone, email and address are all bound' );
assert.equal( loopOk.m3_survived, true, 'bound page survives the phone change' );

const typed = get( 'baseline', 'ridgeline-roofing' );
assert.equal( typed.m1_valid, true, 'typed page is still valid markup' );
assert.equal( typed.m2_bound_share, 0, 'nothing bound' );
assert.equal( typed.m3_survived, false, 'stale tel: link fails the edit' );
assert.equal( typed.m3_reason, 'old phone still on page' );

const invalid = get( 'baseline', 'brightpath-hvac' );
assert.equal( invalid.m1_valid, false, 'unclosed paragraph is invalid' );

const missing = get( 'loop', 'brightpath-hvac' );
assert.equal( missing.built, false, 'missing page.html counts as a failed build' );
assert.equal( missing.m3_survived, false );

console.log( 'ok   bench/score.mjs known-answer fixtures (4/4)' );
