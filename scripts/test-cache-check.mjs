/**
 * Known-answer test for scripts/lib/cache-check.mjs, the logic of
 * npm run cache-check.
 *
 *   node --test scripts/test-cache-check.mjs
 *
 * The harness itself needs Playground, so CI runs it
 * (.github/workflows/cache.yml). What decides its verdict needs nothing, and
 * npm run check runs this file: the options, the render marker, and both
 * verdicts on pages and records written out by hand. Each verdict is shown
 * giving all three outcomes, because a harness that cannot say "stale" or
 * "unmeasured" proves nothing when it says "fresh".
 *
 * It also plants crashes: in each step of run() in scripts/cache-check.mjs,
 * which must answer 2, and a real Node crash (exit 1, the code for stale)
 * fed to scripts/cache-check-row.mjs, which must fail a row expecting stale.
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

import {
	EXIT,
	MOST_PAGES,
	PHONE_AFTER,
	PHONE_BEFORE,
	PLUGINS,
	UsageError,
	covers,
	flagsOf,
	lastByIdProblem,
	normalizeUrl,
	pageSlugs,
	parseArgs,
	realVerdict,
	renderMarker,
	rowVerdict,
	shows,
	spyVerdict,
	summaryMarkdown,
} from './lib/cache-check.mjs';

// run() writes a job summary and annotations in GitHub Actions. Neither
// belongs in CI's log from a test, so both are off before it is imported.
delete process.env.GITHUB_STEP_SUMMARY;
process.env.GITHUB_ACTIONS = 'false';
const { run } = await import( './cache-check.mjs' );

const scripts = fileURLToPath( new URL( '.', import.meta.url ) );

const URL_ = 'http://127.0.0.1:9400/voyager-open-cache-check/';

/**
 * A page as the harness mu-plugin renders it.
 *
 * @param {string} phone  The number in the bound paragraph.
 * @param {string} render The render marker, or '' for none.
 * @return {{status: number, text: string}} A load.
 */
function page( phone, render ) {
	return {
		status: 200,
		text: `<html><body><p>${ phone }</p>${
			render
				? `\n<!-- voyager-open-cache-check rendered ${ render } php_self=/index.php sapi=cli -->\n`
				: ''
		}</body></html>`,
	};
}

test( 'the exit codes are 0 fresh, 1 stale, 2 unmeasured', () => {
	assert.deepEqual( EXIT, { fresh: 0, stale: 1, unmeasured: 2 } );
} );

test( 'parseArgs reads the documented commands', () => {
	assert.deepEqual(
		parseArgs( [ '--plugin', 'wp-super-cache', '--real', '--no-purge' ] ),
		{
			plugin: 'wp-super-cache',
			mode: 'real',
			noPurge: true,
			standIn: false,
			lockDown: false,
			pages: 1,
			resultFile: undefined,
			port: undefined,
			wp: undefined,
			php: undefined,
			debug: false,
			help: false,
		}
	);
	const spy = parseArgs( [
		'--plugin=dummy',
		'--spy',
		'--stand-in',
		'--result-file',
		'out.json',
		'--port=9500',
	] );
	assert.equal( spy.mode, 'spy' );
	assert.equal( spy.standIn, true );
	assert.equal( spy.resultFile, 'out.json' );
	assert.equal( spy.port, '9500' );
	assert.equal( parseArgs( [ '--help' ] ).help, true );
} );

test( 'parseArgs refuses what makes no sense', () => {
	for ( const [ argv, message ] of [
		[ [ '--real' ], /--plugin is required/ ],
		[ [ '--plugin', 'nope', '--real' ], /Unknown plugin nope/ ],
		[ [ '--plugin', 'wp-super-cache' ], /exactly one of --real and --spy/ ],
		[
			[ '--plugin', 'wp-super-cache', '--real', '--spy' ],
			/exactly one of --real and --spy/,
		],
		[ [ '--plugin', 'wp-super-cache', '--spy' ], /not --spy/ ],
		[ [ '--plugin', 'dummy', '--real' ], /not --real/ ],
		[ [ '--plugin', 'dummy', '--spy', '--purge' ], /Unknown option/ ],
		[ [ '--plugin', 'dummy', '--spy', '--result-file' ], /needs a value/ ],
		[ [ '--plugin', 'dummy', '--spy', 'extra' ], /Unexpected argument/ ],
		[ [ '--plugin', 'dummy', '--spy', '--port', 'abc' ], /takes a number/ ],
		[ [ '--plugin', 'dummy', '--spy', '--pages' ], /needs a value/ ],
	] ) {
		assert.throws(
			() => parseArgs( argv ),
			( error ) => {
				assert.ok( error instanceof UsageError, error.message );
				assert.match( error.message, message );
				return true;
			}
		);
	}
} );

test( 'parseArgs reads --pages, and a run without it publishes one page', () => {
	const base = [ '--plugin', 'wp-super-cache', '--real' ];
	assert.equal( parseArgs( base ).pages, 1 );
	assert.equal( parseArgs( [ ...base, '--pages', '51' ] ).pages, 51 );
	assert.equal( parseArgs( [ ...base, '--pages=1' ] ).pages, 1 );
	assert.equal(
		parseArgs( [ ...base, '--pages', String( MOST_PAGES ) ] ).pages,
		MOST_PAGES
	);
	assert.equal(
		parseArgs( [ ...base, '--pages', '51', '--no-purge' ] ).noPurge,
		true
	);
} );

test( 'parseArgs refuses a --pages that is not a whole number from 1 to the most', () => {
	const base = [ '--plugin', 'wp-super-cache', '--real', '--pages' ];
	for ( const value of [
		'0',
		'-1',
		'1.5',
		'51 ',
		'1e2',
		'0x33',
		'abc',
		String( MOST_PAGES + 1 ),
		'99999999999999999999',
	] ) {
		assert.throws(
			() => parseArgs( [ ...base, value ] ),
			( error ) => {
				assert.ok( error instanceof UsageError, value );
				assert.match(
					error.message,
					/--pages takes a whole number from 1 to/,
					value
				);
				return true;
			},
			value
		);
	}
} );

test( 'parseArgs reads --lockdown only for a cache the harness can lock down', () => {
	assert.equal(
		parseArgs( [ '--plugin', 'wp-super-cache', '--real', '--lockdown' ] )
			.lockDown,
		true
	);
	assert.equal(
		parseArgs( [ '--plugin', 'wp-super-cache', '--real' ] ).lockDown,
		false
	);
	for ( const [ argv, message ] of [
		[
			[ '--plugin', 'dummy', '--spy', '--lockdown' ],
			/--lockdown is for a cache the harness can lock down/,
		],
		[
			[ '--plugin', 'wp-super-cache', '--real', '--lockdown=1' ],
			/takes no value/,
		],
	] ) {
		assert.throws(
			() => parseArgs( argv ),
			( error ) => {
				assert.ok( error instanceof UsageError, error.message );
				assert.match( error.message, message );
				return true;
			}
		);
	}
	for ( const [ name, spec ] of Object.entries( PLUGINS ) ) {
		assert.equal( typeof spec.lockDown, 'boolean', name );
	}
} );

test( 'pageSlugs publishes the measured page last, under the one-page slug', () => {
	assert.deepEqual( pageSlugs( 'cc', 1 ), [ 'cc' ] );
	assert.deepEqual( pageSlugs( 'cc', 3 ), [ 'cc-1', 'cc-2', 'cc' ] );
	const many = pageSlugs( 'cc', 51 );
	assert.equal( many.length, 51 );
	assert.equal( many[ 50 ], 'cc' );
	assert.equal( new Set( many ).size, 51, 'every slug is its own' );
} );

test( 'the measured page must be the last by ID, or nothing is measured', () => {
	assert.equal( lastByIdProblem( [ { id: 7 } ] ), null );
	assert.equal(
		lastByIdProblem( [ { id: 7 }, { id: 9 }, { id: 12 } ] ),
		null
	);
	assert.match(
		lastByIdProblem( [ { id: 7 }, { id: 12 }, { id: 9 } ] ),
		/has ID 9, and pages published before it have 12/
	);
	assert.match(
		lastByIdProblem( [ { id: 9 }, { id: 9 } ] ),
		/not the last by ID/
	);
	assert.match(
		lastByIdProblem( [ { id: 7 }, { id: undefined } ] ),
		/without IDs/
	);
	assert.match( lastByIdProblem( [] ), /without IDs/ );
} );

test( 'every plugin row is complete, and every --spy plugin names its receivers', () => {
	for ( const [ name, spec ] of Object.entries( PLUGINS ) ) {
		assert.ok( spec.label, name );
		assert.ok( spec.modes.length, name );
		assert.ok( Array.isArray( spec.muPlugins ), name );
		if ( spec.install ) {
			assert.match( spec.install.version, /^\d+\.\d+(\.\d+)?$/, name );
		}
		if ( spec.modes.includes( 'spy' ) ) {
			assert.ok( spec.receivers.length, `${ name } has no receivers` );
		}
	}
	assert.equal( PLUGINS[ 'wp-super-cache' ].install.version, '3.1.5' );
} );

test( 'the render marker is read from the footer comment, and only from it', () => {
	assert.equal( renderMarker( page( PHONE_BEFORE, 'a1b2' ).text ), 'a1b2' );
	assert.equal( renderMarker( page( PHONE_BEFORE, '' ).text ), null );
	assert.equal(
		renderMarker( '<!-- voyager-open-cache-check rendered -->' ),
		null
	);
} );

test( 'shows() finds a number as typed or as the page escapes it', () => {
	assert.ok( shows( '<p>(555) 555-0142</p>', PHONE_BEFORE ) );
	assert.ok( shows( '<p>&#040;555&#041; 555&#8211;0142</p>', PHONE_BEFORE ) );
	assert.ok( ! shows( '<p>(555) 555-01425</p>', PHONE_BEFORE ) );
	assert.ok( ! shows( '<p>(555) 555-0187</p>', PHONE_BEFORE ) );
} );

test( '--real: fresh when the load after the change is rendered again with the new number', () => {
	const verdict = realVerdict( {
		loads: [
			page( PHONE_BEFORE, 'aa' ),
			page( PHONE_BEFORE, 'aa' ),
			page( PHONE_AFTER, 'bb' ),
		],
		saved: PHONE_AFTER,
	} );
	assert.equal( verdict.outcome, 'fresh', verdict.reason );
	assert.deepEqual( verdict.markers, [ 'aa', 'aa', 'bb' ] );
} );

test( '--real: stale when the cache serves the copy it stored before the change', () => {
	const verdict = realVerdict( {
		loads: [
			page( PHONE_BEFORE, 'aa' ),
			page( PHONE_BEFORE, 'aa' ),
			page( PHONE_BEFORE, 'aa' ),
		],
		saved: PHONE_AFTER,
	} );
	assert.equal( verdict.outcome, 'stale', verdict.reason );
	assert.match( verdict.reason, /still shows \(555\) 555-0142/ );
} );

test( '--real: unmeasured, never fresh, when the cache did not serve the second load', () => {
	// The trap: with no cache at all, the third load shows the new number,
	// which looks exactly like a working purge.
	const verdict = realVerdict( {
		loads: [
			page( PHONE_BEFORE, 'aa' ),
			page( PHONE_BEFORE, 'bb' ),
			page( PHONE_AFTER, 'cc' ),
		],
		saved: PHONE_AFTER,
	} );
	assert.equal( verdict.outcome, 'unmeasured' );
	assert.match( verdict.reason, /did not serve it/ );
} );

test( '--real: unmeasured when the page cannot be measured', () => {
	const cases = [
		[
			'no marker',
			{
				loads: [
					page( PHONE_BEFORE, '' ),
					page( PHONE_BEFORE, '' ),
					page( PHONE_BEFORE, '' ),
				],
				saved: PHONE_AFTER,
			},
			/render marker/,
		],
		[
			'the first load is an error',
			{
				loads: [
					{ status: 500, text: '' },
					page( PHONE_BEFORE, 'aa' ),
					page( PHONE_AFTER, 'bb' ),
				],
				saved: PHONE_AFTER,
			},
			/answered 500/,
		],
		[
			'the binding did not render',
			{
				loads: [
					page( 'Call us', 'aa' ),
					page( 'Call us', 'aa' ),
					page( 'Call us', 'aa' ),
				],
				saved: PHONE_AFTER,
			},
			/does not show the bound number/,
		],
		[
			'the change did not save',
			{
				loads: [
					page( PHONE_BEFORE, 'aa' ),
					page( PHONE_BEFORE, 'aa' ),
					page( PHONE_BEFORE, 'aa' ),
				],
				saved: PHONE_BEFORE,
			},
			/did not save/,
		],
		[
			'rendered again with the old number',
			{
				loads: [
					page( PHONE_BEFORE, 'aa' ),
					page( PHONE_BEFORE, 'aa' ),
					page( PHONE_BEFORE, 'bb' ),
				],
				saved: PHONE_AFTER,
			},
			/did not reach the render/,
		],
	];
	for ( const [ name, observed, reason ] of cases ) {
		const verdict = realVerdict( observed );
		assert.equal( verdict.outcome, 'unmeasured', name );
		assert.match( verdict.reason, reason, name );
	}
} );

test( 'URLs compare without case, fragment or trailing slash', () => {
	assert.equal(
		normalizeUrl( 'HTTP://127.0.0.1:9400/voyager-open-cache-check/#x' ),
		normalizeUrl( 'http://127.0.0.1:9400/voyager-open-cache-check' )
	);
	assert.notEqual(
		normalizeUrl( 'http://127.0.0.1:9400/voyager-open-cache-check/' ),
		normalizeUrl( 'http://127.0.0.1:9400/voyager-open-cache-check-2/' )
	);
	assert.equal( normalizeUrl( 'not a url' ), null );
} );

test( 'a record covers the page by URL, by purging everything, or in an HTTP body', () => {
	assert.ok( covers( { kind: 'hook', urls: [ URL_ ] }, URL_ ) );
	assert.ok( covers( { kind: 'hook', urls: [], all: true }, URL_ ) );
	assert.ok(
		covers(
			{
				kind: 'http',
				urls: [],
				body: '{"files":["http:\\/\\/127.0.0.1:9400\\/voyager-open-cache-check\\/"]}',
			},
			URL_
		)
	);
	assert.ok(
		! covers(
			{
				kind: 'http',
				urls: [],
				body: '{"files":["http://127.0.0.1:9400/voyager-open-cache-check-2/"]}',
			},
			URL_
		)
	);
	assert.ok(
		! covers(
			{
				kind: 'hook',
				urls: [ 'http://127.0.0.1:9400/somewhere-else/' ],
			},
			URL_
		)
	);
} );

const receivers = PLUGINS.dummy.receivers;
const spyLoads = [ page( PHONE_BEFORE, 'aa' ), page( PHONE_AFTER, 'bb' ) ];

test( '--spy: fresh when the cache is told to purge the page', () => {
	const verdict = spyVerdict( {
		url: URL_,
		receivers,
		loads: spyLoads,
		saved: PHONE_AFTER,
		records: [
			{
				kind: 'action',
				name: 'voyager_open_blocks_purge',
				urls: [ URL_ ],
			},
			{ kind: 'hook', name: 'dummy_cache_purge_urls', urls: [ URL_ ] },
			{
				kind: 'http',
				name: 'api.cdn.example',
				urls: [ URL_ ],
				url: 'https://api.cdn.example/v1/purge',
				body: `{"files":["${ URL_ }"]}`,
			},
		],
	} );
	assert.equal( verdict.outcome, 'fresh', verdict.reason );
	assert.equal( verdict.purges.length, 2 );
} );

test( '--spy: fresh when the cache is told to purge everything', () => {
	const verdict = spyVerdict( {
		url: URL_,
		receivers,
		loads: spyLoads,
		saved: PHONE_AFTER,
		records: [
			{
				kind: 'hook',
				name: 'dummy_cache_purge_all',
				urls: [],
				all: true,
			},
		],
	} );
	assert.equal( verdict.outcome, 'fresh', verdict.reason );
	assert.match( verdict.reason, /everything/ );
} );

test( '--spy: stale when nothing purges, or only the pack action fires, or the URL is wrong', () => {
	const cases = [
		[ 'nothing', [], /Nothing purged anything/ ],
		[
			'the action fired and no cache heard it',
			[
				{
					kind: 'action',
					name: 'voyager_open_blocks_purge',
					urls: [ URL_ ],
				},
			],
			/action voyager_open_blocks_purge \(1 URL/,
		],
		[
			'the cache purged another page',
			[
				{
					kind: 'hook',
					name: 'dummy_cache_purge_urls',
					urls: [ 'http://127.0.0.1:9400/somewhere-else/' ],
				},
			],
			/somewhere-else/,
		],
		[
			'another host was called',
			[
				{
					kind: 'http',
					name: 'api.wordpress.org',
					urls: [],
					url: `https://api.wordpress.org/?u=${ URL_ }`,
					body: '',
				},
			],
			/Nothing purged anything/,
		],
	];
	for ( const [ name, records, reason ] of cases ) {
		const verdict = spyVerdict( {
			url: URL_,
			receivers,
			loads: spyLoads,
			saved: PHONE_AFTER,
			records,
		} );
		assert.equal( verdict.outcome, 'stale', name );
		assert.match( verdict.reason, reason, name );
	}
} );

test( '--spy: unmeasured when the page or the change did not work', () => {
	const purged = [
		{ kind: 'hook', name: 'dummy_cache_purge_urls', urls: [ URL_ ] },
	];
	for ( const [ name, observed ] of [
		[
			'the binding did not render',
			{ loads: [ page( 'Call us', 'aa' ), page( 'Call us', 'bb' ) ] },
		],
		[ 'the change did not save', { saved: PHONE_BEFORE } ],
		[
			'the new number never rendered',
			{
				loads: [
					page( PHONE_BEFORE, 'aa' ),
					page( PHONE_BEFORE, 'bb' ),
				],
			},
		],
	] ) {
		const verdict = spyVerdict( {
			url: URL_,
			receivers,
			loads: spyLoads,
			saved: PHONE_AFTER,
			records: purged,
			...observed,
		} );
		assert.equal( verdict.outcome, 'unmeasured', name );
	}
} );

test( 'the job summary names the command, the outcome and the exit code', () => {
	const text = summaryMarkdown( {
		plugin: 'wp-super-cache',
		label: 'WP Super Cache',
		mode: 'real',
		noPurge: true,
		standIn: false,
		version: '3.1.5',
		outcome: 'stale',
		exit: 1,
		reason: 'The cache served the page it stored before the change.',
	} );
	assert.match(
		text,
		/npm run cache-check -- --plugin wp-super-cache --real --no-purge/
	);
	assert.match( text, /\*\*stale\*\* \(exit 1\)/ );
	assert.match( text, /WP Super Cache 3\.1\.5/ );
} );

test( 'the flags name --pages past one page, so the two 51-page rows read apart', () => {
	const result = {
		plugin: 'wp-super-cache',
		mode: 'real',
		noPurge: false,
		standIn: false,
		pages: 1,
	};
	assert.equal( flagsOf( result ), '--plugin wp-super-cache --real' );
	assert.equal(
		flagsOf( { ...result, pages: 51 } ),
		'--plugin wp-super-cache --real --pages 51'
	);
	assert.equal(
		flagsOf( { ...result, pages: 51, noPurge: true } ),
		'--plugin wp-super-cache --real --pages 51 --no-purge'
	);
	assert.equal(
		flagsOf( { ...result, lockDown: true } ),
		'--plugin wp-super-cache --real --lockdown'
	);
	// A result written before --pages existed has no pages field.
	assert.equal(
		flagsOf( { ...result, pages: undefined, noPurge: true } ),
		'--plugin wp-super-cache --real --no-purge'
	);
	assert.match(
		summaryMarkdown( {
			...result,
			pages: 51,
			label: 'WP Super Cache',
			outcome: 'fresh',
			exit: 0,
			reason: 'rendered again',
		} ),
		/npm run cache-check -- --plugin wp-super-cache --real --pages 51\n/
	);
} );

test( 'a row passes only when the result file and the exit code agree with it', () => {
	const stale = { outcome: 'stale', exit: 1, reason: 'old number' };
	const fresh = { outcome: 'fresh', exit: 0, reason: 'new number' };
	const unmeasured = { outcome: 'unmeasured', exit: 2, reason: 'no hit' };
	for ( const [ name, row, ok ] of [
		[
			'stale as expected',
			{ expect: 'stale', exit: 1, result: stale },
			true,
		],
		[
			'fresh as expected',
			{ expect: 'fresh', exit: 0, result: fresh },
			true,
		],
		[
			'a crash: exit 1 and no result',
			{ expect: 'stale', exit: 1, result: null },
			false,
		],
		[
			'unmeasured, whatever the exit code',
			{ expect: 'stale', exit: 1, result: unmeasured },
			false,
		],
		[
			'the purge landed: fresh where stale is expected',
			{ expect: 'stale', exit: 0, result: fresh },
			false,
		],
		[
			'the exit code disagrees with the result',
			{ expect: 'stale', exit: 2, result: stale },
			false,
		],
		[
			'an expectation that is neither',
			{ expect: 'unmeasured', exit: 2, result: unmeasured },
			false,
		],
	] ) {
		assert.equal( rowVerdict( row ).ok, ok, name );
	}
} );

test( 'a planted crash cannot pass a row that expects stale', () => {
	// A real crash: Node exits 1 on an uncaught exception, the code for stale.
	const crash = spawnSync(
		process.execPath,
		[ '-e', "throw new Error( 'planted crash' )" ],
		{ encoding: 'utf8' }
	);
	assert.equal( crash.status, EXIT.stale );

	const dir = mkdtempSync( join( tmpdir(), 'cache-check-row-' ) );
	const row = ( resultFile ) =>
		spawnSync(
			process.execPath,
			[
				join( scripts, 'cache-check-row.mjs' ),
				'--expect',
				'stale',
				'--exit',
				String( crash.status ),
				'--result-file',
				resultFile,
			],
			{
				encoding: 'utf8',
				env: { ...process.env, GITHUB_ACTIONS: 'false' },
			}
		);

	const missing = row( join( dir, 'never-written.json' ) );
	assert.equal( missing.status, 1, missing.stdout + missing.stderr );
	assert.match( missing.stderr, /wrote no result/ );

	const said = join( dir, 'unmeasured.json' );
	writeFileSync(
		said,
		JSON.stringify( { outcome: 'unmeasured', exit: 2, reason: 'crashed' } )
	);
	assert.equal( row( said ).status, 1 );

	const stale = join( dir, 'stale.json' );
	writeFileSync(
		stale,
		JSON.stringify( { outcome: 'stale', exit: 1, reason: 'old number' } )
	);
	assert.equal( row( stale ).status, 0 );
} );

/**
 * A finished measurement, as measure() returns it.
 *
 * @param {string} outcome
 * @return {Object} A result.
 */
function measured( outcome ) {
	return {
		plugin: 'wp-super-cache',
		label: 'WP Super Cache',
		mode: 'real',
		noPurge: true,
		standIn: false,
		version: '3.1.5',
		outcome,
		reason: `planted ${ outcome }`,
		observed: {},
	};
}

const ARGV = [ '--plugin', 'wp-super-cache', '--real', '--no-purge' ];

test( 'run() answers 2, never 1, when any step crashes, and the result file says so', async () => {
	const dir = mkdtempSync( join( tmpdir(), 'cache-check-run-' ) );
	const boom = () => {
		throw new Error( 'planted crash' );
	};
	for ( const [ name, steps ] of [
		[
			'measure() throws',
			{
				measure: async () => boom(),
			},
		],
		[
			'stopping the site throws after a stale verdict',
			{
				measure: async () => {
					try {
						return measured( 'stale' );
					} finally {
						boom();
					}
				},
			},
		],
		[
			'report() throws after a stale verdict',
			{ measure: async () => measured( 'stale' ), report: boom },
		],
		[
			'writing the result throws after a stale verdict',
			{
				measure: async () => measured( 'stale' ),
				report: () => {},
				writeResult: boom,
			},
		],
	] ) {
		const file = join( dir, `${ name.replace( /\W+/g, '-' ) }.json` );
		const code = await run( [ ...ARGV, '--result-file', file ], steps );
		assert.equal( code, EXIT.unmeasured, name );
		if ( existsSync( file ) ) {
			assert.equal(
				JSON.parse( readFileSync( file, 'utf8' ) ).outcome,
				'unmeasured',
				name
			);
		}
	}

	// The real writeResult(), pointed at a directory, fails to write.
	const code = await run( [ ...ARGV, '--result-file', dir ], {
		measure: async () => measured( 'stale' ),
		report: () => {},
	} );
	assert.equal(
		code,
		EXIT.unmeasured,
		'a result file that cannot be written'
	);
} );

test( 'run() answers the outcome and writes it when nothing crashes', async () => {
	const dir = mkdtempSync( join( tmpdir(), 'cache-check-run-' ) );
	for ( const outcome of [ 'stale', 'fresh', 'unmeasured' ] ) {
		const file = join( dir, `${ outcome }.json` );
		const code = await run( [ ...ARGV, '--result-file', file ], {
			measure: async () => measured( outcome ),
			report: () => {},
		} );
		assert.equal( code, EXIT[ outcome ], outcome );
		const written = JSON.parse( readFileSync( file, 'utf8' ) );
		assert.equal( written.outcome, outcome );
		assert.equal( written.exit, EXIT[ outcome ] );
		assert.ok(
			rowVerdict( { expect: outcome, exit: code, result: written } )
				.ok ===
				( outcome !== 'unmeasured' )
		);
	}
} );
