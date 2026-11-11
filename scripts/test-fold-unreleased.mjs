/**
 * Known-answer test for scripts/fold-unreleased.mjs.
 *
 *   node --test scripts/test-fold-unreleased.mjs
 *
 * The fixture is tests/fixtures/fold-unreleased/: a readme.txt, an unreleased/
 * folder of notes, and the readme.txt the fold must write from them. Every
 * case runs on a copy in a temporary folder, so the fixture never changes.
 * `npm run check` runs this file.
 */
import { spawnSync } from 'node:child_process';
import {
	cpSync,
	existsSync,
	mkdtempSync,
	readFileSync,
	readdirSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { test } from 'node:test';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const script = join( root, 'scripts', 'fold-unreleased.mjs' );
const fixture = join( root, 'tests', 'fixtures', 'fold-unreleased' );

/**
 * A copy of the fixture in a fresh temporary folder.
 *
 * @return {{dir: string, notes: string, readme: string}} Paths in the copy.
 */
function copy() {
	const dir = mkdtempSync( join( tmpdir(), 'fold-unreleased-' ) );
	cpSync( join( fixture, 'unreleased' ), join( dir, 'unreleased' ), {
		recursive: true,
	} );
	cpSync( join( fixture, 'readme.in.txt' ), join( dir, 'readme.txt' ) );
	return {
		dir,
		notes: join( dir, 'unreleased' ),
		readme: join( dir, 'readme.txt' ),
	};
}

function fold( at, ...args ) {
	return spawnSync(
		process.execPath,
		[ script, '--dir', at.notes, '--readme', at.readme, ...args ],
		{ encoding: 'utf8' }
	);
}

const read = ( file ) => readFileSync( file, 'utf8' );

test( 'the notes land under the new version, feat then fix then docs, and are deleted', () => {
	const at = copy();
	const result = fold( at, '--version', '0.7.0' );

	assert.equal( result.status, 0, result.stderr );
	assert.equal(
		read( at.readme ),
		read( join( fixture, 'readme.expected.txt' ) )
	);
	assert.deepEqual( readdirSync( at.notes ), [ 'README.md' ] );
} );

test( 'the version comes from the plugin header when --version is not given', () => {
	const at = copy();
	const plugin = join( at.dir, 'plugin.php' );
	writeFileSync(
		plugin,
		'<?php\n/**\n * Plugin Name: Example\n * Version:           0.7.0\n */\n'
	);
	const result = fold( at, '--plugin', plugin );

	assert.equal( result.status, 0, result.stderr );
	assert.equal(
		read( at.readme ),
		read( join( fixture, 'readme.expected.txt' ) )
	);
} );

test( 'with no notes, nothing changes', () => {
	const at = copy();
	for ( const name of readdirSync( at.notes ) ) {
		if ( name !== 'README.md' ) {
			rmSync( join( at.notes, name ) );
		}
	}
	const result = fold( at, '--version', '0.7.0' );

	assert.equal( result.status, 0, result.stderr );
	assert.equal( read( at.readme ), read( join( fixture, 'readme.in.txt' ) ) );
	assert.match( result.stdout, /Nothing to fold/ );
} );

test( 'an existing section for the version gets the new lines after its own, once each', () => {
	const at = copy();
	const before = read( at.readme ).replace(
		'== Changelog ==\n\n',
		'== Changelog ==\n\n= 0.7.0 =\n* Written by hand.\n* The FAQ says how to bind a phone number.\n\n'
	);
	writeFileSync( at.readme, before );
	const result = fold( at, '--version', '0.7.0' );

	assert.equal( result.status, 0, result.stderr );
	const section = read( at.readme )
		.split( '= 0.7.0 =\n' )[ 1 ]
		.split( '\n\n' )[ 0 ];
	assert.equal(
		section,
		[
			'* Written by hand.',
			'* The FAQ says how to bind a phone number.',
			'* Settings > Site Data takes a service area.',
			'* `compose` builds a draft page from a recipe or a list of sections.',
			'* The icon block keeps its link when you change its size.',
		].join( '\n' )
	);
} );

for ( const [ name, body, problem ] of [
	[
		'chore.md',
		'type: chore\nTidied up.\n',
		/type must be feat, fix or docs/,
	],
	[
		'no-type.md',
		'The icon block got bigger.\n',
		/type must be feat, fix or docs/,
	],
	[ 'two-lines.md', 'type: feat\nOne line.\nAnd another.\n', /one line/ ],
	[ 'empty-line.md', 'type: fix\n', /one line/ ],
	[
		'bullet.md',
		'type: fix\n* Starts with a bullet.\n',
		/without the leading/,
	],
	[ 'Bad Name.md', 'type: fix\nA line.\n', /file name/ ],
] ) {
	test( `a bad note (${ name }) stops the fold with nothing changed`, () => {
		const at = copy();
		writeFileSync( join( at.notes, name ), body );
		const result = fold( at, '--version', '0.7.0' );

		assert.equal( result.status, 1 );
		assert.match( result.stderr, problem );
		assert.ok( result.stderr.includes( name ), result.stderr );
		assert.equal(
			read( at.readme ),
			read( join( fixture, 'readme.in.txt' ) )
		);
		assert.ok( existsSync( join( at.notes, 'compose.md' ) ) );
	} );
}

test( '--check reads every note and writes nothing', () => {
	const at = copy();
	const good = fold( at, '--check' );

	assert.equal( good.status, 0, good.stderr );
	assert.match( good.stdout, /4 notes/ );
	assert.equal( read( at.readme ), read( join( fixture, 'readme.in.txt' ) ) );
	assert.equal( readdirSync( at.notes ).length, 5 );

	writeFileSync( join( at.notes, 'chore.md' ), 'type: chore\nTidied up.\n' );
	const bad = fold( at, '--check' );

	assert.equal( bad.status, 1 );
	assert.match( bad.stderr, /chore\.md/ );
} );

test( 'a readme with no changelog section is refused', () => {
	const at = copy();
	writeFileSync(
		at.readme,
		'=== Example ===\n\n== Description ==\n\nA plugin.\n'
	);
	const result = fold( at, '--version', '0.7.0' );

	assert.equal( result.status, 1 );
	assert.match( result.stderr, /== Changelog ==/ );
	assert.ok( existsSync( join( at.notes, 'compose.md' ) ) );
} );
