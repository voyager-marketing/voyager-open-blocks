#!/usr/bin/env node
/**
 * Check what the voyager/site-data keys resolve to against a pinned fixture.
 *
 *   node scripts/site-data-fixture-check.mjs [--site DIR]
 *
 * Writes tests/fixtures/site-data-keys.json `options` to the local site, runs
 * the pack's values() in-process through `wp eval-file`, compares every key to
 * `expected`, and puts the previous option values back, pass or fail. In
 * `expected`, the token {{year}} stands for the current UTC year.
 *
 * The voyager_site_* options are written with their sanitize_option filters
 * removed, so the untidy values reach values() as stored and the check pins
 * values(), not the settings-page sanitizers. blogname and blogdescription
 * still pass through core's own sanitizing.
 *
 * Prints one line and exits non-zero on any mismatch:
 *
 *   ok: 18 site-data keys match the fixture
 *   fail: phone: expected "+1 (555) 010-0123", got "5550100123" (1 of 18 differ)
 */
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { flags, phpEnv, repo, siteDir, wp } from './lib/site.mjs';

const opts = flags( process.argv.slice( 2 ) );
const site = siteDir( opts );

if ( ! existsSync( join( site, 'wp-config.php' ) ) ) {
	console.error(
		`No site at ${ site }. Build one with: npm run playground -- setup --site <dir>`
	);
	process.exit( 2 );
}

const fixture = JSON.parse(
	readFileSync(
		join( repo, 'tests', 'fixtures', 'site-data-keys.json' ),
		'utf8'
	)
);
const year = String( new Date().getUTCFullYear() );
const expected = Object.fromEntries(
	Object.entries( fixture.expected ).map( ( [ key, value ] ) => [
		key,
		value.replaceAll( '{{year}}', year ),
	] )
);

// Runs inside WordPress. No strict_types: eval'd code cannot declare it.
// $args[0] is a JSON file: { "mode": "snapshot|write|restore", "options": {...} }.
const probe = `<?php
$job  = json_decode( (string) file_get_contents( $args[0] ), true );
$none = '__voyager_open_blocks_unset__';

$put = static function ( string $name, $value ): void {
	remove_all_filters( "sanitize_option_{$name}" );
	update_option( $name, $value );
};

if ( 'snapshot' === $job['mode'] ) {
	$out = [];
	foreach ( array_keys( $job['options'] ) as $name ) {
		$out[ $name ] = get_option( $name, $none );
	}
	echo wp_json_encode( [ 'snapshot' => $out, 'none' => $none ] );
	return;
}

if ( 'restore' === $job['mode'] ) {
	foreach ( $job['options'] as $name => $value ) {
		if ( $none === $value ) {
			delete_option( $name );
		} else {
			$put( $name, $value );
		}
	}
	echo wp_json_encode( [ 'restored' => count( $job['options'] ) ] );
	return;
}

if ( ! function_exists( 'VoyagerOpenBlocks\\\\SiteData\\\\values' ) ) {
	echo wp_json_encode( [ 'error' => 'VoyagerOpenBlocks\\\\SiteData\\\\values() is not loaded' ] );
	return;
}

foreach ( $job['options'] as $name => $value ) {
	$put( $name, $value );
}

echo wp_json_encode( [ 'values' => \\VoyagerOpenBlocks\\SiteData\\values() ] );
`;

const run = ( job ) => {
	const jobFile = join(
		tmpdir(),
		`voyager-open-site-data-job-${ process.pid }.json`
	);
	const probeFile = join(
		tmpdir(),
		`voyager-open-site-data-probe-${ process.pid }.php`
	);
	writeFileSync( jobFile, JSON.stringify( job ) );
	writeFileSync( probeFile, probe );
	try {
		const stdout = wp(
			site,
			[ 'eval-file', probeFile, jobFile ],
			phpEnv( site )
		);
		const line = stdout
			.split( /\r?\n/ )
			.reverse()
			.find( ( l ) => l.startsWith( '{' ) );
		return line ? JSON.parse( line ) : { error: 'no output' };
	} finally {
		rmSync( jobFile, { force: true } );
		rmSync( probeFile, { force: true } );
	}
};

let result;
let before;
let failed = false;

try {
	before = run( { mode: 'snapshot', options: fixture.options } );
	result = before.error
		? before
		: run( { mode: 'write', options: fixture.options } );
} catch ( error ) {
	result = { error: error.message };
} finally {
	if ( before?.snapshot ) {
		try {
			run( { mode: 'restore', options: before.snapshot } );
		} catch ( error ) {
			console.log(
				`fail: previous options not restored: ${ error.message }`
			);
			failed = true;
		}
	}
}

if ( result.error ) {
	console.log( `fail: values() did not run: ${ result.error }` );
	process.exit( 1 );
}

const got = result.values;
const keys = [
	...new Set( [ ...Object.keys( expected ), ...Object.keys( got ) ] ),
];
const bad = keys.filter( ( key ) => got[ key ] !== expected[ key ] );

if ( bad.length ) {
	const key = bad[ 0 ];
	console.log(
		`fail: ${ key }: expected ${ JSON.stringify( expected[ key ] ?? null ) }, got ${ JSON.stringify( got[ key ] ?? null ) } (${ bad.length } of ${ keys.length } differ)`
	);
	failed = true;
} else {
	console.log( `ok: ${ keys.length } site-data keys match the fixture` );
}

process.exit( failed ? 1 : 0 );
