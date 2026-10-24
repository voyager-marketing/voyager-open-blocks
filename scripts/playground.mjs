#!/usr/bin/env node
/**
 * Local demo site for the pack.
 *
 *   npm run playground -- setup     [--site DIR] [--port 9400]   install WordPress + SQLite, link and seed the pack
 *   npm run playground -- serve     [--site DIR] [--port 9400]   serve that site with WordPress Playground
 *   npm run playground -- blueprint <name> [--port 9401]          boot a fresh in-memory Playground from blueprints/<name>.json,
 *                                                                  with this checkout mounted in place of the GitHub download
 *
 * Needs `wp` (WP-CLI) and `php` on PATH for setup. See README, "Local site".
 */
import { execFileSync, spawn } from 'node:child_process';
import {
	copyFileSync,
	existsSync,
	mkdirSync,
	readFileSync,
	renameSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { flags, phpEnv, repo, siteDir, wp } from './lib/site.mjs';

const opts = flags( process.argv.slice( 2 ) );
const command = opts._[ 0 ];
const site = siteDir( opts );
const playgroundCli = join(
	repo,
	'node_modules',
	'@wp-playground',
	'cli',
	'wp-playground.js'
);

/**
 * Download a zip and unpack it into a directory with PHP's ZipArchive.
 *
 * @param {string} url
 * @param {string} into
 * @param {Object} env  Environment with the zip extension available.
 */
async function unzip( url, into, env ) {
	const response = await fetch( url );
	if ( ! response.ok ) {
		throw new Error( `Download failed (${ response.status }): ${ url }` );
	}
	mkdirSync( into, { recursive: true } );
	const file = join( into, 'download.zip' );
	writeFileSync( file, Buffer.from( await response.arrayBuffer() ) );
	execFileSync(
		'php',
		[
			'-r',
			'$z = new ZipArchive(); if ( true !== $z->open( $argv[1] ) ) { exit( 1 ); } $z->extractTo( $argv[2] ); $z->close();',
			file,
			into,
		],
		{ env, stdio: 'inherit' }
	);
	rmSync( file );
}

async function setup() {
	const port = opts.port || '9400';
	const env = phpEnv( site );

	if ( existsSync( join( site, 'wp-config.php' ) ) ) {
		console.log( `Site exists at ${ site }; re-seeding only.` );
	} else {
		// WordPress and the SQLite integration plugin come down as zips and are
		// unpacked with PHP in place. (`wp core download` unpacks under the
		// system temp dir, which overflows Windows' path limit on WordPress 7.1.)
		const staging = `${ site }-download`;
		await unzip( 'https://wordpress.org/latest.zip', staging, env );
		renameSync( join( staging, 'wordpress' ), site );
		await unzip(
			'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip',
			join( site, 'wp-content', 'plugins' ),
			env
		);
		rmSync( staging, { recursive: true, force: true } );

		// The drop-in finds the plugin relative to wp-content, so the same file
		// works on disk and inside Playground, and Playground skips its own
		// SQLite preload when a db.php is present.
		copyFileSync(
			join(
				site,
				'wp-content',
				'plugins',
				'sqlite-database-integration',
				'db.copy'
			),
			join( site, 'wp-content', 'db.php' )
		);

		wp(
			site,
			[
				'config',
				'create',
				'--dbname=wordpress',
				'--dbuser=wordpress',
				'--dbpass=wordpress',
				'--skip-check',
				'--force',
			],
			env
		);
		// The SQLite plugin defaults to WAL. Playground's PHP (WebAssembly) cannot
		// share WAL's memory-mapped index with host PHP, and every request after
		// the first fails with "database is locked". Rollback journal works in both.
		wp( site, [ 'config', 'set', 'SQLITE_JOURNAL_MODE', 'DELETE' ], env );
		// Local environment type lets application passwords work over plain
		// HTTP, which is how scripts/e2e.mjs calls the abilities like an agent.
		wp( site, [ 'config', 'set', 'WP_ENVIRONMENT_TYPE', 'local' ], env );
		wp(
			site,
			[
				'core',
				'install',
				`--url=http://127.0.0.1:${ port }`,
				'--title=Example Co.',
				'--admin_user=admin',
				'--admin_password=password',
				'--admin_email=admin@example.com',
				'--skip-email',
			],
			env
		);
	}

	const link = join( site, 'wp-content', 'plugins', 'voyager-open-blocks' );
	if ( ! existsSync( link ) ) {
		// 'junction' is ignored outside Windows, where a directory symlink is made.
		symlinkSync( repo, link, 'junction' );
	}

	wp( site, [ 'plugin', 'activate', 'voyager-open-blocks' ], env );
	wp( site, [ 'eval-file', join( link, 'playground', 'seed.php' ) ], env );
	wp( site, [ 'rewrite', 'flush' ], env );

	console.log(
		`Ready: ${ site }\nServe it with: npm run playground -- serve${ opts.site ? ` --site ${ opts.site }` : '' }`
	);
}

function serve() {
	const port = opts.port || '9400';
	run( [
		'server',
		`--port=${ port }`,
		`--site-url=http://127.0.0.1:${ port }`,
		'--php=8.3',
		'--wordpress-install-mode=do-not-attempt-installing',
		'--follow-symlinks',
		'--login',
		'--mount-dir-before-install',
		site,
		'/wordpress',
	] );
}

function blueprint() {
	const name = opts._[ 1 ] || 'blueprint';
	const file =
		name === 'blueprint'
			? join( repo, 'blueprint.json' )
			: join( repo, 'blueprints', `${ name }.json` );
	const source = JSON.parse( readFileSync( file, 'utf8' ) );

	// The published blueprint downloads the pack from GitHub. Locally the
	// checkout is mounted instead, so the download step becomes an activation.
	source.steps = source.steps.map( ( step ) =>
		step.step === 'installPlugin'
			? {
					step: 'activatePlugin',
					pluginPath: 'voyager-open-blocks/voyager-open-blocks.php',
				}
			: step
	);

	const local = join( tmpdir(), `voyager-open-${ name }.blueprint.json` );
	writeFileSync( local, JSON.stringify( source, null, '\t' ) );

	const port = opts.port || '9401';
	run( [
		'server',
		`--port=${ port }`,
		`--blueprint=${ local }`,
		'--mount-dir',
		repo,
		'/wordpress/wp-content/plugins/voyager-open-blocks',
	] );
}

function run( args ) {
	const child = spawn( process.execPath, [ playgroundCli, ...args ], {
		stdio: 'inherit',
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
	} );
	child.on( 'exit', ( code ) => process.exit( code ?? 0 ) );
}

const commands = { setup, serve, blueprint };

if ( ! commands[ command ] ) {
	console.error(
		'Usage: npm run playground -- <setup|serve|blueprint> [options]'
	);
	process.exit( 2 );
}

await commands[ command ]();
