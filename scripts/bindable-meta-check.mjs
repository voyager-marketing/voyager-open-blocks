#!/usr/bin/env node
/**
 * Check that a row in the voyager_open_blocks_bindable_meta option makes a
 * post meta field bindable, with no PHP.
 *
 *   node scripts/bindable-meta-check.mjs [--site DIR]
 *
 * Writes two page rows through `wp option update` (the Settings > Site Data
 * screen saves the same option): a valid key and a `_private` one. A fresh
 * `wp eval-file` then runs get-catalog and PostMetaText\resolve() on a draft
 * page as admin. Finally the option is put back as it was and get-catalog is
 * asked again, pass or fail.
 *
 * Prints one line per check and exits non-zero when any fails:
 *
 *   ok: row stored, _private row refused          the sanitizer kept one row of two
 *   ok: get-catalog lists the field               { "key": "bindable_meta_check" } for page
 *   ok: resolve() returns the meta value          the draft page's meta, read through the source
 *   ok: get-catalog does not list the _private key
 *   ok: row removed, get-catalog no longer lists the field
 */
import { execFileSync } from 'node:child_process';
import { existsSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { flags, phpEnv, siteDir, wp } from './lib/site.mjs';

const opts = flags( process.argv.slice( 2 ) );
const site = siteDir( opts );

if ( ! existsSync( join( site, 'wp-config.php' ) ) ) {
	console.error(
		`No site at ${ site }. Build one with: npm run playground -- setup --site <dir>`
	);
	process.exit( 2 );
}

const option = 'voyager_open_blocks_bindable_meta';
const key = 'bindable_meta_check';
const privateKey = '_bindable_meta_check';
const value = 'Bindable meta check value';
const env = phpEnv( site );
const rows = [
	{ post_type: 'page', key, label: 'Bindable meta check' },
	{ post_type: 'page', key: privateKey, label: 'Private' },
];

// Runs inside WordPress. No strict_types: eval'd code cannot declare it.
const probe = `<?php
$out = [ 'stored' => get_option( ${ JSON.stringify( option ) }, null ), 'fields' => null, 'resolved' => null ];

$catalog = wp_get_ability( 'voyager-open-blocks/get-catalog' )->execute( [ 'post_type' => 'page' ] );
if ( is_wp_error( $catalog ) ) {
	echo wp_json_encode( [ 'error' => $catalog->get_error_code() ] );
	return;
}
foreach ( $catalog['sources'] as $source ) {
	if ( 'voyager/post-meta-text' === $source['name'] ) {
		$out['fields'] = $source['fields'];
	}
}

if ( getenv( 'BINDABLE_META_RESOLVE' ) ) {
	$post_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'bindable-meta-check' ], true );
	if ( is_wp_error( $post_id ) ) {
		echo wp_json_encode( [ 'error' => $post_id->get_error_code() ] );
		return;
	}
	try {
		update_post_meta( $post_id, ${ JSON.stringify( key ) }, ${ JSON.stringify( value ) } );
		$out['resolved'] = \\VoyagerOpenBlocks\\PostMetaText\\resolve( $post_id, ${ JSON.stringify( key ) } );
	} finally {
		wp_delete_post( $post_id, true );
	}
}

echo wp_json_encode( $out );
`;

const probeFile = join(
	tmpdir(),
	`voyager-open-bindable-meta-${ process.pid }.php`
);

/**
 * Run the probe in a fresh WordPress process, so init sees the current option.
 *
 * @param {boolean} resolve Also create a draft page and resolve its meta.
 */
function runProbe( resolve ) {
	try {
		const stdout = wp( site, [ 'eval-file', probeFile, '--user=admin' ], {
			...env,
			BINDABLE_META_RESOLVE: resolve ? '1' : '',
		} );
		const line = stdout
			.split( /\r?\n/ )
			.reverse()
			.find( ( l ) => l.startsWith( '{' ) );
		return line ? JSON.parse( line ) : { error: 'no output' };
	} catch ( error ) {
		return { error: error.message };
	}
}

/**
 * Write the option as JSON through STDIN, which keeps the value out of the
 * Windows command line (WP-CLI is a .bat shim there).
 *
 * @param {unknown} json
 */
function writeOption( json ) {
	execFileSync(
		'wp',
		[ `--path=${ site }`, 'option', 'update', option, '--format=json' ],
		{
			input: JSON.stringify( json ),
			encoding: 'utf8',
			env,
			stdio: [ 'pipe', 'pipe', 'inherit' ],
		}
	);
}

let before = null;
try {
	before = JSON.parse(
		wp( site, [ 'option', 'get', option, '--format=json' ], env )
	);
} catch {
	before = null;
}

let failed = false;
const check = ( pass, ok, detail ) => {
	console.log( pass ? `ok: ${ ok }` : `fail: ${ ok }: ${ detail }` );
	failed ||= ! pass;
};
const lists = ( fields, k ) =>
	Array.isArray( fields ) && fields.some( ( f ) => f?.args?.key === k );

let result;
let after;
writeFileSync( probeFile, probe );

try {
	writeOption( rows );
	result = runProbe( true );
} catch ( error ) {
	result = { error: error.message };
} finally {
	try {
		if ( null === before ) {
			wp( site, [ 'option', 'delete', option ], env );
		} else {
			writeOption( before );
		}
		after = runProbe( false );
	} catch ( error ) {
		after = { error: error.message };
	}
	rmSync( probeFile, { force: true } );
}

if ( result.error ) {
	check( false, 'probe ran', result.error );
} else {
	const stored = Array.isArray( result.stored ) ? result.stored : [];
	check(
		stored.some( ( r ) => r.key === key ) &&
			! stored.some( ( r ) => r.key === privateKey ),
		'row stored, _private row refused',
		`stored: ${ JSON.stringify( result.stored ) }`
	);
	check(
		lists( result.fields, key ),
		'get-catalog lists the field',
		`voyager/post-meta-text fields: ${ JSON.stringify( result.fields ) }`
	);
	check(
		result.resolved === value,
		'resolve() returns the meta value',
		`resolved ${ JSON.stringify( result.resolved ) }`
	);
	check(
		! lists( result.fields, privateKey ),
		'get-catalog does not list the _private key',
		`voyager/post-meta-text fields: ${ JSON.stringify( result.fields ) }`
	);
}

check(
	! after.error && ! lists( after.fields, key ),
	'row removed, get-catalog no longer lists the field',
	after.error ||
		`voyager/post-meta-text fields: ${ JSON.stringify( after.fields ) }`
);

process.exit( failed ? 1 : 0 );
