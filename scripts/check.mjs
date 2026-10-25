#!/usr/bin/env node
/**
 * Every offline gate, in one command. No WordPress needed.
 *
 *   npm run check
 *
 * 1. patterns/*.html match their intents (`patterns:build --check`).
 * 2. `block-runner validate --strict` passes on every pattern.
 * 3. The same command fails on tests/invalid/, so the gate is shown to bite.
 * 4. `block-runner plugin inspect` recognizes the plugin as a wp-scripts host.
 * 5. readme.txt's `Stable tag` matches the plugin's `Version` header.
 *
 * The site-level gate (Wesper sees every block, source and pattern) needs a
 * running site: `npm run playground -- setup`, then `npm run context`.
 */
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const blockRunner = join(
	root,
	'node_modules',
	'block-runner',
	'dist',
	'cli.js'
);
let failed = 0;

function run( args ) {
	const result = spawnSync( process.execPath, args, {
		cwd: root,
		encoding: 'utf8',
	} );
	return {
		code: result.status,
		stdout: result.stdout || '',
		stderr: result.stderr || '',
	};
}

function report( name, ok, detail = '' ) {
	console.log(
		`${ ok ? 'ok  ' : 'FAIL' } ${ name }${ detail ? ` (${ detail })` : '' }`
	);
	if ( ! ok ) {
		failed++;
	}
}

const build = run( [
	join( root, 'scripts', 'build-patterns.mjs' ),
	'--check',
] );
report(
	'patterns match their intents',
	build.code === 0,
	build.code ? build.stderr.trim() : ''
);

const valid = run( [
	blockRunner,
	'validate',
	'patterns/*.html',
	'--strict',
	'--json',
] );
const validReport = JSON.parse( valid.stdout || '{}' );
report(
	'block-runner validate --strict patterns/*.html',
	valid.code === 0 && validReport.ok === true,
	validReport.summary
		? `${ validReport.summary.valid }/${ validReport.summary.blocks } valid, ${ validReport.summary.warnings } warnings`
		: `exit ${ valid.code }`
);

const invalid = run( [
	blockRunner,
	'validate',
	'tests/invalid/*.html',
	'--strict',
	'--json',
] );
report(
	'block-runner rejects tests/invalid/*.html',
	invalid.code === 1,
	`exit ${ invalid.code }`
);

const inspect = run( [ blockRunner, 'plugin', 'inspect', '.', '--json' ] );
const profile = JSON.parse( inspect.stdout || '{}' );
// block-runner 1.0.0 builds `__DIR__ . '/build'` with a forward slash and
// compares it to path.join(), so on Windows the bootstrap is never matched.
// Linux and macOS (and CI) are unaffected.
const windowsPathBug =
	process.platform === 'win32' &&
	/No supported PHP registration bootstrap/.test( profile.reason || '' );
if ( windowsPathBug ) {
	console.log(
		'skip block-runner plugin inspect (block-runner 1.0.0 path-separator bug on Windows; run on Linux or macOS)'
	);
} else {
	report(
		'block-runner plugin inspect',
		profile.kind === 'recognized',
		profile.profile || profile.reason || `exit ${ inspect.code }`
	);
}

// WordPress.org serves the version the readme's Stable tag names, so a tag
// left behind after a Version bump ships the old release.
function header( file, pattern ) {
	const match = readFileSync( join( root, file ), 'utf8' ).match( pattern );
	return match ? match[ 1 ] : '';
}
const version = header(
	'voyager-open-blocks.php',
	/^ \* Version:\s*(\S+)\s*$/m
);
const stableTag = header( 'readme.txt', /^Stable tag:\s*(\S+)\s*$/im );
report(
	'readme.txt Stable tag matches the plugin Version',
	version !== '' && stableTag === version,
	`Stable tag ${ stableTag || 'missing' }, Version ${ version || 'missing' }`
);

process.exit( failed ? 1 : 0 );
