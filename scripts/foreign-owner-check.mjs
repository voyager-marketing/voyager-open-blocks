#!/usr/bin/env node
/**
 * Check get-catalog and bind on a site where another plugin owns voyager/site-data.
 *
 *   node scripts/foreign-owner-check.mjs [--site DIR]
 *
 * On the fleet, voyager-blocks registers voyager/site-data first and the pack
 * stands aside. This copies tests/fixtures/foreign-site-data.php into the
 * local site's mu-plugins as a stand-in owner (two fields, in voyager-blocks'
 * shape), runs the abilities in-process through `wp eval-file` as admin, and
 * removes the fixture again, pass or fail.
 *
 * Prints one line per check and exits non-zero when any fails:
 *
 *   ok: fields listed from the owning plugin (2)   get-catalog lists both owner fields, each with args
 *   ok: bind accepts the owner's args (2)          bind takes each listed args on a draft page
 *   ok: bind refuses a key the owner does not list the pack's own `company` is refused
 *   ok: fixture removed
 */
import {
	copyFileSync,
	existsSync,
	mkdirSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
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

const fixture = join( repo, 'tests', 'fixtures', 'foreign-site-data.php' );
const muPlugins = join( site, 'wp-content', 'mu-plugins' );
const installed = join(
	muPlugins,
	'voyager-open-blocks-foreign-site-data.php'
);
const expected = {
	fixture_phone: { key: 'fixture_phone' },
	fixture_hours: { key: 'fixture_hours', format: 'short' },
};

// Runs inside WordPress. No strict_types: eval'd code cannot declare it.
const probe = `<?php
$out = [ 'source' => null, 'bind' => [], 'refused' => null ];

$catalog = wp_get_ability( 'voyager-open-blocks/get-catalog' )->execute( [ 'post_type' => 'page' ] );
if ( is_wp_error( $catalog ) ) {
	echo wp_json_encode( [ 'error' => $catalog->get_error_code() ] );
	return;
}
foreach ( $catalog['sources'] as $source ) {
	if ( 'voyager/site-data' === $source['name'] ) {
		$out['source'] = $source;
	}
}

$post_id = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'foreign-owner-check',
		'post_content' => "<!-- wp:paragraph -->\\n<p>Placeholder</p>\\n<!-- /wp:paragraph -->",
	],
	true
);
if ( is_wp_error( $post_id ) ) {
	echo wp_json_encode( [ 'error' => $post_id->get_error_code() ] );
	return;
}

$bind = wp_get_ability( 'voyager-open-blocks/bind' );
$try  = static function ( array $args ) use ( $bind, $post_id ): string {
	$result = $bind->execute(
		[
			'post_id'   => $post_id,
			'path'      => '0',
			'attribute' => 'content',
			'source'    => 'voyager/site-data',
			'args'      => $args,
		]
	);
	return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
};

try {
	foreach ( (array) ( $out['source']['fields'] ?? [] ) as $field ) {
		$out['bind'][] = $try( (array) $field['args'] );
	}
	$out['refused'] = $try( [ 'key' => 'company' ] );
} finally {
	wp_delete_post( $post_id, true );
}

echo wp_json_encode( $out );
`;

const probeFile = join(
	tmpdir(),
	`voyager-open-foreign-owner-${ process.pid }.php`
);
let result;
let failed = false;

mkdirSync( muPlugins, { recursive: true } );
copyFileSync( fixture, installed );
writeFileSync( probeFile, probe );

try {
	const stdout = wp(
		site,
		[ 'eval-file', probeFile, '--user=admin' ],
		phpEnv( site )
	);
	const line = stdout
		.split( /\r?\n/ )
		.reverse()
		.find( ( l ) => l.startsWith( '{' ) );
	result = line ? JSON.parse( line ) : { error: 'no output' };
} catch ( error ) {
	result = { error: error.message };
} finally {
	rmSync( installed, { force: true } );
	rmSync( probeFile, { force: true } );
}

const check = ( pass, ok, detail ) => {
	console.log( pass ? `ok: ${ ok }` : `fail: ${ ok }: ${ detail }` );
	failed ||= ! pass;
};

if ( result.error ) {
	check( false, 'get-catalog ran', result.error );
} else {
	const fields = result.source?.fields;
	const listed = Array.isArray( fields ) ? fields : [];
	const matches = Object.values( expected ).filter( ( args ) =>
		listed.some(
			( f ) => JSON.stringify( f.args ) === JSON.stringify( args )
		)
	);
	check(
		listed.length === matches.length && matches.length === 2,
		`fields listed from the owning plugin (${ matches.length })`,
		`voyager/site-data fields: ${ JSON.stringify( fields ?? null ) }`
	);

	const accepted = result.bind.filter( ( code ) => code === 'ok' );
	check(
		result.bind.length === 2 && accepted.length === 2,
		`bind accepts the owner's args (${ accepted.length })`,
		JSON.stringify( result.bind )
	);

	check(
		result.refused === 'voyager_open_blocks_invalid_args',
		'bind refuses a key the owner does not list',
		`{ "key": "company" } gave ${ result.refused }`
	);
}

check(
	! existsSync( installed ),
	'fixture removed',
	`${ installed } is still there`
);

process.exit( failed ? 1 : 0 );
