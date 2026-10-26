#!/usr/bin/env node
/**
 * Check that get-post-blocks reports whether each binding resolved or fell back.
 *
 *   node scripts/binding-resolution-check.mjs [--site DIR]
 *
 * Creates a draft page with two paragraphs bound to voyager/site-data: one to
 * `phone` (the option is set for the run) and one to a key the source does not
 * have. Runs get-post-blocks in-process through `wp eval-file` as admin, then
 * deletes the page and puts the phone option back, pass or fail.
 *
 * Prints one line per check and exits non-zero when any fails:
 *
 *   ok: 1 resolved, 1 fell back           the phone binding resolved, the other fell back
 *   ok: resolved binding previews the value   value_preview is the phone number
 *   ok: unknown key says unknown_key      the fall back names its reason
 *   ok: page and option restored
 */
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

const phone = '555-0142';

// Runs inside WordPress. No strict_types: eval'd code cannot declare it.
const probe = `<?php
$out    = [ 'blocks' => null, 'restored' => false ];
$phone  = ${ JSON.stringify( phone ) };
$option = 'voyager_site_phone';
$before = get_option( $option, null );

$bound = static fn ( string $key, string $text ): string => sprintf(
	"<!-- wp:paragraph {\\"metadata\\":{\\"bindings\\":{\\"content\\":{\\"source\\":\\"voyager/site-data\\",\\"args\\":{\\"key\\":\\"%s\\"}}}}} -->\\n<p>%s</p>\\n<!-- /wp:paragraph -->",
	$key,
	$text
);

update_option( $option, $phone );

$post_id = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'binding-resolution-check',
		'post_content' => wp_slash( $bound( 'phone', 'Placeholder phone' ) . "\\n\\n" . $bound( 'not_a_field', 'Placeholder other' ) ),
	],
	true
);

try {
	if ( is_wp_error( $post_id ) ) {
		$out['error'] = $post_id->get_error_code();
	} else {
		$result = wp_get_ability( 'voyager-open-blocks/get-post-blocks' )->execute( [ 'post_id' => $post_id ] );
		if ( is_wp_error( $result ) ) {
			$out['error'] = $result->get_error_code() . ': ' . $result->get_error_message();
		} else {
			$out['blocks'] = $result['blocks'];
		}
	}
} finally {
	if ( ! is_wp_error( $post_id ) ) {
		wp_delete_post( $post_id, true );
	}
	null === $before ? delete_option( $option ) : update_option( $option, $before );
	$out['restored'] = ( ! is_wp_error( $post_id ) && null === get_post( $post_id ) ) && get_option( $option, null ) === $before;
}

echo wp_json_encode( $out );
`;

const probeFile = join(
	tmpdir(),
	`voyager-open-binding-resolution-${ process.pid }.php`
);
let result;
let failed = false;

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
	rmSync( probeFile, { force: true } );
}

const check = ( pass, ok, detail ) => {
	console.log( pass ? `ok: ${ ok }` : `fail: ${ ok }: ${ detail }` );
	failed ||= ! pass;
};

if ( result.error ) {
	check( false, 'get-post-blocks ran', result.error );
} else {
	const bound = result.blocks.filter( ( b ) => b.bindings?.content );
	const resolutions = bound.map( ( b ) => b.bindings.content.resolution );
	const resolved = resolutions.filter( ( r ) => r?.status === 'resolved' );
	const fellBack = resolutions.filter( ( r ) => r?.status === 'fell_back' );

	check(
		bound.length === 2 && resolved.length === 1 && fellBack.length === 1,
		`${ resolved.length } resolved, ${ fellBack.length } fell back`,
		`expected 1 resolved, 1 fell back across 2 bound blocks: ${ JSON.stringify( resolutions ) }`
	);
	check(
		resolved[ 0 ]?.value_preview === phone &&
			resolved[ 0 ]?.reason === null,
		'resolved binding previews the value',
		JSON.stringify( resolved[ 0 ] ?? null )
	);
	check(
		fellBack[ 0 ]?.reason === 'unknown_key' &&
			fellBack[ 0 ]?.value_preview === null,
		'unknown key says unknown_key',
		JSON.stringify( fellBack[ 0 ] ?? null )
	);
}

check(
	result.restored === true,
	'page and option restored',
	'the draft page or the voyager_site_phone option was left behind'
);

process.exit( failed ? 1 : 0 );
