/**
 * Shared helpers for the local demo site.
 *
 * The site is ordinary WordPress files on disk with an SQLite database:
 * WP-CLI installs it, Playground serves it (scripts/playground.mjs serve),
 * and Wesper's WP-CLI collector reads it (scripts/context.mjs). All three
 * see the same files and the same database file.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { delimiter, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const repo = fileURLToPath( new URL( '../..', import.meta.url ) );

/**
 * Parse `--name value` flags.
 *
 * @param {string[]} argv
 */
export function flags( argv ) {
	const out = { _: [] };
	for ( let i = 0; i < argv.length; i++ ) {
		if ( argv[ i ].startsWith( '--' ) ) {
			out[ argv[ i ].slice( 2 ) ] =
				argv[ i + 1 ] && ! argv[ i + 1 ].startsWith( '--' )
					? argv[ ++i ]
					: true;
		} else {
			out._.push( argv[ i ] );
		}
	}
	return out;
}

/**
 * The demo site directory: --site, then VOYAGER_OPEN_SITE, then a sibling of the checkout.
 *
 * Kept outside the checkout because the plugin is linked into the site.
 *
 * @param {Record<string, string>} opts
 */
export function siteDir( opts ) {
	return resolve(
		opts.site ||
			process.env.VOYAGER_OPEN_SITE ||
			join( repo, '..', 'voyager-open-blocks-site' )
	);
}

/**
 * Environment for WP-CLI: PHP must load pdo_sqlite, sqlite3 and zip.
 *
 * When the PHP on PATH lacks any of them, an ini enabling the missing ones is
 * written next to the site and appended to PHP's scan path (a leading
 * delimiter appends rather than replaces).
 *
 * @param {string} site
 */
export function phpEnv( site ) {
	const env = { ...process.env };
	const modules = execFileSync( 'php', [ '-m' ], { encoding: 'utf8' } )
		.toLowerCase()
		.split( /\r?\n/ );
	const missing = [ 'pdo_sqlite', 'sqlite3', 'zip' ].filter(
		( m ) => ! modules.includes( m )
	);

	if ( missing.length ) {
		const iniDir = `${ site }-php`;
		mkdirSync( iniDir, { recursive: true } );
		writeFileSync(
			join( iniDir, 'voyager-open-blocks.ini' ),
			missing.map( ( m ) => `extension=${ m }` ).join( '\n' ) +
				'\nmemory_limit=512M\n'
		);
		env.PHP_INI_SCAN_DIR = `${ env.PHP_INI_SCAN_DIR || '' }${ delimiter }${ iniDir }`;
	}

	return env;
}

/**
 * Run WP-CLI against the site and return stdout.
 *
 * @param {string}   site
 * @param {string[]} args
 * @param {Object}   env
 */
export function wp( site, args, env ) {
	return execFileSync( 'wp', [ `--path=${ site }`, ...args ], {
		encoding: 'utf8',
		env,
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} );
}
