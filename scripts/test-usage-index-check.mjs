/**
 * Known-answer test for the verdict of npm run check:usage-index.
 *
 *   node --test scripts/test-usage-index-check.mjs
 *
 * The check itself needs Playground, so CI runs it
 * (.github/workflows/e2e.yml). How it turns what it measured into an exit
 * code needs nothing, and npm run check runs this file. It plants crashes in
 * each step of run() in scripts/usage-index-check.mjs, before and after the
 * paths are measured, and none of them may exit 0: a crash is a run that
 * could not tell. It also runs the real script with --plant crash, which
 * must exit 2.
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

// run() writes annotations in GitHub Actions. They do not belong in CI's log
// from a test, so they are off before it is imported.
process.env.GITHUB_ACTIONS = 'false';
const { listsVerdict, report, run } = await import( './usage-index-check.mjs' );

const script = fileURLToPath(
	new URL( './usage-index-check.mjs', import.meta.url )
);

/**
 * A measure() stand-in that sets each path's status, then does what `then`
 * says.
 *
 * @param {Object<string, string>} statuses Path => pass, fail or unmeasured.
 * @param {() => void}             [then]   Runs after, such as a planted crash.
 * @return {(opts: Object, results: Object) => Promise<void>} The stand-in.
 */
function measured( statuses, then = () => {} ) {
	return async ( opts, results ) => {
		for ( const [ path, status ] of Object.entries( statuses ) ) {
			results[ path ] = { status, detail: `planted ${ status }` };
		}
		then();
	};
}

const boom = () => {
	throw new Error( 'planted crash' );
};
const quiet = { report: ( results, plant ) => report( results, plant ) };
const PASS = {
	reindex: 'pass',
	activation: 'pass',
	order: 'pass',
	lists: 'pass',
};

/**
 * run() with console output held back, so the test log stays readable.
 *
 * @param {string[]} argv
 * @param {Object}   steps
 * @return {Promise<number>} Its exit code.
 */
async function quietly( argv, steps ) {
	const { log, error } = console;
	console.log = () => {};
	console.error = () => {};
	try {
		return await run( argv, { ...quiet, ...steps } );
	} finally {
		console.log = log;
		console.error = error;
	}
}

test( 'every path passes: exit 0', async () => {
	assert.equal( await quietly( [], { measure: measured( PASS ) } ), 0 );
} );

test( 'a path that does not do what it should: exit 1', async () => {
	assert.equal(
		await quietly( [], {
			measure: measured( { ...PASS, activation: 'fail' } ),
		} ),
		1
	);
} );

test( 'a path left unmeasured: exit 2', async () => {
	assert.equal(
		await quietly( [], {
			measure: measured( { ...PASS, order: 'unmeasured' } ),
		} ),
		2
	);
} );

test( 'a planted fault caught, and the other paths passing: exit 0', async () => {
	for ( const plant of [ 'activation', 'reindex', 'lists' ] ) {
		assert.equal(
			await quietly( [ '--plant', plant ], {
				measure: measured( { ...PASS, [ plant ]: 'fail' } ),
			} ),
			0,
			plant
		);
	}
} );

test( 'a planted fault the check misses: exit 1', async () => {
	assert.equal(
		await quietly( [ '--plant', 'reindex' ], {
			measure: measured( PASS ),
		} ),
		1
	);
} );

test( 'a crash before any path is measured: exit 2', async () => {
	assert.equal( await quietly( [], { measure: async () => boom() } ), 2 );
} );

test( 'a crash after the paths it reached passed: exit 2, never 0', async () => {
	for ( const [ name, statuses ] of [
		[
			'reindex, order and lists passed',
			{ reindex: 'pass', order: 'pass', lists: 'pass' },
		],
		[ 'every path passed, then stopping the site threw', PASS ],
	] ) {
		assert.equal(
			await quietly( [], { measure: measured( statuses, boom ) } ),
			2,
			name
		);
	}
} );

test( 'a crash after a planted fault was caught: exit 2, never 0', async () => {
	assert.equal(
		await quietly( [ '--plant', 'reindex' ], {
			measure: measured( { ...PASS, reindex: 'fail' }, boom ),
		} ),
		2
	);
} );

test( 'a crash after a path failed keeps the failure: exit 1', async () => {
	assert.equal(
		await quietly( [], {
			measure: measured( { ...PASS, reindex: 'fail' }, boom ),
		} ),
		1
	);
} );

test( 'a crash in report(): exit 2', async () => {
	assert.equal(
		await quietly( [], { measure: measured( PASS ), report: boom } ),
		2
	);
} );

test( 'a usage error: exit 2', async () => {
	for ( const argv of [ [ '--plant', 'nothing' ], [ '--nope' ], [ 'x' ] ] ) {
		assert.equal(
			await quietly( argv, { measure: measured( PASS ) } ),
			2,
			argv.join( ' ' )
		);
	}
} );

const rebuilt = { posts: 4, missing: 0, problems: [] };
const lost = { posts: 4, missing: 1, problems: [ 'post 9 lists []' ] };

test( 'lists: the saves and both rebuilds right is a pass', () => {
	assert.equal(
		listsVerdict(
			[],
			[
				[ 'reindex', rebuilt ],
				[ 'the backfill', rebuilt ],
			]
		).status,
		'pass'
	);
} );

test( 'lists: a save that listed the wrong rows fails, whatever the rebuilds did', () => {
	assert.equal(
		listsVerdict(
			[ 'the Query Loop page lists []' ],
			[
				[ 'reindex', rebuilt ],
				[ 'the backfill', rebuilt ],
			]
		).status,
		'fail'
	);
} );

test( 'lists: a rebuild that lost list rows fails', () => {
	for ( const rebuilds of [
		[
			[ 'reindex', lost ],
			[ 'the backfill', rebuilt ],
		],
		[
			[ 'reindex', null ],
			[ 'the backfill', lost ],
		],
	] ) {
		assert.equal( listsVerdict( [], rebuilds ).status, 'fail' );
	}
} );

test( 'lists: a rebuild not judged, since its own path failed, leaves the other to decide', () => {
	const found = listsVerdict(
		[],
		[
			[ 'reindex', null ],
			[ 'the backfill', rebuilt ],
		]
	);
	assert.equal( found.status, 'pass' );
	assert.match( found.detail, /reindex not judged/ );
} );

test( 'lists: no rebuild judged at all is unmeasured, never a pass', () => {
	assert.equal(
		listsVerdict(
			[],
			[
				[ 'reindex', null ],
				[ 'the backfill', null ],
			]
		).status,
		'unmeasured'
	);
} );

test( 'the real script with --plant crash exits 2', () => {
	const result = spawnSync(
		process.execPath,
		[ script, '--plant', 'crash' ],
		{
			encoding: 'utf8',
			env: { ...process.env, GITHUB_ACTIONS: 'false' },
			timeout: 60000,
		}
	);
	assert.equal( result.status, 2, `${ result.stdout }${ result.stderr }` );
	assert.match( `${ result.stdout }${ result.stderr }`, /planted crash/ );
} );
