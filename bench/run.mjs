#!/usr/bin/env node
/**
 * Runs benchmark v2: per brief, group, model and repeat, a fresh WordPress
 * site behind WP Super Cache, a build prompt and an edit prompt through
 * headless Claude Code, with MCP pointed at that site.
 *
 *   node bench/run.mjs prepare [--work DIR]
 *   node bench/run.mjs run [--groups baseline,plugin,pack] [--models id,id] [--repeats N]
 *                          [--briefs id,id | --split derive] [--limit N]
 *                          [--use-login] [--force] [--keep] [--work DIR]
 *
 * `prepare` builds one template site per group under <work>/templates:
 * WordPress on SQLite (the README's "Local site" setup), Twenty Twenty-Five,
 * the WordPress MCP Adapter, bench/harness/site-tools.php and
 * bench/harness/page-cache.php as mu-plugins, and WP Super Cache at the
 * version npm run cache-check pins, turned on with cache-check's own setup
 * (bench/harness/setup-wp-super-cache.php). The `plugin` and `pack` templates
 * also get this pack, copied from the package.json `files` list, which is
 * what the release zip ships. The `plugin` template also gets
 * bench/harness/plugin-group.php, which unregisters every ability the pack
 * registers. Each template records the abilities the MCP Adapter lists, and
 * prepare stops when a group's list is wrong.
 *
 * `run` copies the group's template for each run, so every run starts from
 * the same fresh site and nothing carries over. It writes the brief's
 * business details and business profile into Site Data's options (blogname
 * and the voyager_site_* options, in every group), serves the copy with
 * Playground, checks that the page cache serves the front page, and runs the
 * two prompts from bench/README.md ("Build v2" and "Edit v2") in one Claude
 * Code session. The `pack` group also gets the pack's skills (as a plugin
 * directory), a Wesper manifest of its own site and Block Runner; `baseline`
 * and `plugin` get the MCP tools only.
 *
 * After the build and after the edit the runner loads the page twice as an
 * anonymous visitor. The second load must come from the cache (the same
 * render marker as the first), or the run is a harness failure. After the
 * build it also captures the rendered page at 1440 px wide, full height,
 * through scripts/lib/chrome.mjs.
 *
 * Results land in bench/runs-v2/<group>/<model>/<brief-id>/<repeat>/:
 * page.html and after-edit.html (post_content), page.rendered.html and
 * after-edit.rendered.html (the second anonymous load, which is what a
 * visitor gets through the cache), page.png, site-data.json, run.json,
 * wesper-manifest.json (the site's own blocks, which the scorer validates
 * against, in every group) and both transcripts. v1's runs/ and RESULTS.md
 * are left as they were.
 *
 * Runs go one at a time: one Playground site is up at any moment, and it is
 * shut down before the next boots. Four sites booting at once killed
 * Playground with a V8 fatal error (exit 2147483651) on Windows. A run whose
 * folder is complete (run.json finished, with no harness failure) is skipped,
 * so a crash costs one run; --force runs it again.
 *
 * Needs `wp`, `php` and `claude` on PATH, Chrome or Chromium (CHROME_PATH, or
 * a usual place), and a Node that Block Runner accepts (22.23.4 or 24.22.1
 * and later; 25 fails): the agent's PATH starts with this Node. --use-login
 * drops ANTHROPIC_API_KEY from the agent's environment so Claude Code uses
 * its claude.ai login.
 */
import { execFileSync, spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
	copyFileSync,
	cpSync,
	createWriteStream,
	existsSync,
	mkdirSync,
	readFileSync,
	renameSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs';
import http from 'node:http';
import { freemem } from 'node:os';
import { delimiter, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { PLUGINS, renderMarker } from '../scripts/lib/cache-check.mjs';
import { findChrome, launch } from '../scripts/lib/chrome.mjs';
import { createClient } from '../scripts/lib/http.mjs';
import { flags, phpEnv, repo, wp } from '../scripts/lib/site.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );
const opts = flags( process.argv.slice( 2 ) );
const work = resolve(
	opts.work || join( repo, '..', 'voyager-open-blocks-bench' )
);
const PROTOCOL = 2;
const GROUPS = [ 'baseline', 'plugin', 'pack' ];
// Claude Code's current model per family, as `--model` takes them.
const MODELS = [ 'claude-haiku-4-5', 'claude-sonnet-5', 'claude-opus-5-5' ];
// The groups that have the pack installed.
const WITH_PACK = [ 'plugin', 'pack' ];
const ABILITY_PREFIX = 'voyager-open-blocks/';
const MCP_ADAPTER = 'v0.7.1';
const THEME = 'twentytwentyfive';
const CACHE = PLUGINS[ 'wp-super-cache' ];
const CAPTURE_WIDTH = 1440;
// Chrome cannot capture past this many pixels in one shot.
const CAPTURE_MAX_HEIGHT = 16000;
const STEP_TIMEOUT_MS = 30 * 60 * 1000;
const STEP_BUDGET_USD = '10';
const PORT = 9471;
// Below this much free memory a site is not booted.
const MIN_FREE_GB = 1.5;
const playgroundCli = join(
	repo,
	'node_modules',
	'@wp-playground',
	'cli',
	'wp-playground.js'
);

// ---------------------------------------------------------------------------
// prepare

async function download( url, file ) {
	if ( existsSync( file ) ) {
		return file;
	}
	const response = await fetch( url );
	if ( ! response.ok ) {
		throw new Error( `Download failed (${ response.status }): ${ url }` );
	}
	mkdirSync( dirname( file ), { recursive: true } );
	writeFileSync( file, Buffer.from( await response.arrayBuffer() ) );
	return file;
}

function unzip( file, into, env ) {
	mkdirSync( into, { recursive: true } );
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
}

/**
 * The JSON object a harness PHP file printed last. WP-CLI and the plugins it
 * loads can print notices before it.
 * @param text
 * @param what
 */
function lastJson( text, what ) {
	const line = text
		.split( /\r?\n/ )
		.map( ( l ) => l.trim() )
		.filter( ( l ) => l.startsWith( '{' ) )
		.pop();
	try {
		return JSON.parse( line );
	} catch {
		throw new Error(
			`${ what } printed no JSON: ${ text.trim().slice( -500 ) }`
		);
	}
}

/**
 * The abilities the MCP Adapter lists on a site, and what is wrong with that
 * list for the group, or null.
 * @param site
 * @param env
 * @param group
 */
function offeredAbilities( site, env, group ) {
	const found = lastJson(
		wp(
			site,
			[ 'eval-file', join( here, 'harness', 'offered-abilities.php' ) ],
			env
		),
		'offered-abilities.php'
	);
	if ( found.error ) {
		return { abilities: [], problem: found.error };
	}
	const pack = found.abilities.filter( ( a ) =>
		a.startsWith( ABILITY_PREFIX )
	);
	let problem = null;
	if ( group === 'pack' && ! pack.length ) {
		problem = `the pack group lists none of the pack's abilities (${ found.abilities.join(
			', '
		) })`;
	} else if ( group !== 'pack' && pack.length ) {
		problem = `the ${ group } group lists the pack's abilities ${ pack.join(
			', '
		) }`;
	} else if ( ! found.abilities.some( ( a ) => a === 'site/create-page' ) ) {
		problem = 'the harness site tools are not listed';
	}
	return { abilities: found.abilities, problem };
}

async function prepare() {
	const cache = join( work, 'cache' );
	const { slug, version } = CACHE.install;
	const zips = {
		wordpress: await download(
			'https://wordpress.org/latest.zip',
			join( cache, 'wordpress.zip' )
		),
		sqlite: await download(
			'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip',
			join( cache, 'sqlite-database-integration.zip' )
		),
		mcp: await download(
			`https://github.com/WordPress/mcp-adapter/releases/download/${ MCP_ADAPTER }/mcp-adapter.zip`,
			join( cache, `mcp-adapter-${ MCP_ADAPTER }.zip` )
		),
		cache: await download(
			`https://downloads.wordpress.org/plugin/${ slug }.${ version }.zip`,
			join( cache, `${ slug }.${ version }.zip` )
		),
	};

	for ( const group of GROUPS ) {
		const site = join( work, 'templates', group );
		rmSync( site, { recursive: true, force: true } );
		rmSync( `${ site }-php`, { recursive: true, force: true } );
		const env = phpEnv( site );
		const staging = `${ site }-download`;
		rmSync( staging, { recursive: true, force: true } );
		unzip( zips.wordpress, staging, env );
		cpSync( join( staging, 'wordpress' ), site, { recursive: true } );
		rmSync( staging, { recursive: true, force: true } );
		const plugins = join( site, 'wp-content', 'plugins' );
		unzip( zips.sqlite, plugins, env );
		unzip( zips.mcp, plugins, env );
		unzip( zips.cache, plugins, env );
		copyFileSync(
			join( plugins, 'sqlite-database-integration', 'db.copy' ),
			join( site, 'wp-content', 'db.php' )
		);
		const mu = join( site, 'wp-content', 'mu-plugins' );
		mkdirSync( mu, { recursive: true } );
		const harness = {
			'site-tools.php': 'bench-site-tools.php',
			'page-cache.php': 'bench-page-cache.php',
			...( group === 'plugin'
				? { 'plugin-group.php': 'bench-plugin-group.php' }
				: {} ),
		};
		for ( const [ from, to ] of Object.entries( harness ) ) {
			copyFileSync( join( here, 'harness', from ), join( mu, to ) );
		}

		const cli = ( args ) => wp( site, args, env ).trim();
		cli( [
			'config',
			'create',
			'--dbname=wordpress',
			'--dbuser=wordpress',
			'--dbpass=wordpress',
			'--skip-check',
			'--force',
		] );
		cli( [ 'config', 'set', 'SQLITE_JOURNAL_MODE', 'DELETE' ] );
		cli( [ 'config', 'set', 'WP_ENVIRONMENT_TYPE', 'local' ] );
		cli( [
			'core',
			'install',
			'--url=http://127.0.0.1:9470',
			'--title=Example Co.',
			'--admin_user=admin',
			'--admin_password=password',
			'--admin_email=admin@example.com',
			'--skip-email',
		] );
		if ( ! existsSync( join( site, 'wp-content', 'themes', THEME ) ) ) {
			cli( [ 'theme', 'install', THEME ] );
		}
		cli( [ 'theme', 'activate', THEME ] );
		cli( [ 'rewrite', 'structure', '/%postname%/' ] );
		cli( [ 'option', 'update', 'blog_public', '0' ] );
		cli( [ 'plugin', 'activate', 'mcp-adapter' ] );
		// Freeze the site. Otherwise cron runs WordPress's background updater
		// mid-run: it upgraded core under every agent in the first full pass,
		// and while it works every request answers "Briefly unavailable for
		// scheduled maintenance" (503).
		for ( const constant of [
			'AUTOMATIC_UPDATER_DISABLED',
			'DISABLE_WP_CRON',
			'WP_HTTP_BLOCK_EXTERNAL',
		] ) {
			cli( [ 'config', 'set', constant, 'true', '--raw' ] );
		}

		if ( WITH_PACK.includes( group ) ) {
			const pack = join( plugins, 'voyager-open-blocks' );
			const { files } = JSON.parse(
				readFileSync( join( repo, 'package.json' ), 'utf8' )
			);
			for ( const f of [ ...files, 'package.json' ] ) {
				cpSync( join( repo, f ), join( pack, f ), { recursive: true } );
			}
			cli( [ 'plugin', 'activate', 'voyager-open-blocks' ] );
		}

		// The page cache, last, so the activations above did not run with it.
		cli( [ 'plugin', 'activate', slug ] );
		const setup = lastJson(
			cli( [
				'eval-file',
				join( here, 'harness', 'setup-wp-super-cache.php' ),
				join(
					repo,
					'tests',
					'fixtures',
					'cache-check',
					'wp-super-cache',
					'setup.php'
				),
			] ),
			'setup-wp-super-cache.php'
		);
		const installed = setup.facts?.[ `${ slug } version` ] ?? null;
		const problems = [ ...( setup.problems || [] ) ];
		if ( installed !== version ) {
			problems.push(
				`${ CACHE.label } ${ installed } is installed, and the bench is written against ${ version }.`
			);
		}
		if ( problems.length ) {
			throw new Error(
				`template ${ group }: ${ CACHE.label } setup failed: ${ problems.join(
					' '
				) }`
			);
		}

		const offered = offeredAbilities( site, env, group );
		if ( offered.problem ) {
			throw new Error( `template ${ group }: ${ offered.problem }` );
		}

		const password = cli( [
			'user',
			'application-password',
			'create',
			'admin',
			'bench',
			'--porcelain',
		] );
		const pages = cli( [
			'post',
			'list',
			'--post_type=page',
			'--post_status=any',
			'--field=ID',
		] )
			.split( /\s+/ )
			.filter( Boolean )
			.map( Number );
		const meta = {
			protocol: PROTOCOL,
			group,
			password,
			pages,
			wordpress: cli( [ 'core', 'version' ] ),
			theme: `${ THEME } ${ cli( [
				'theme',
				'get',
				THEME,
				'--field=version',
			] ) }`,
			plugins: JSON.parse(
				cli( [
					'plugin',
					'list',
					'--status=active',
					'--fields=name,version',
					'--format=json',
				] )
			),
			page_cache: `${ CACHE.label } ${ installed }`,
			abilities: offered.abilities,
			pack_commit: git( [ 'rev-parse', 'HEAD' ] ),
		};
		writeFileSync(
			join( work, 'templates', `${ group }.json` ),
			JSON.stringify( meta, null, 2 ) + '\n'
		);
		console.log(
			`template ${ group }: WordPress ${ meta.wordpress }, ${ meta.plugins
				.map( ( p ) => `${ p.name } ${ p.version }` )
				.join( ', ' ) }; ${
				offered.abilities.filter( ( a ) =>
					a.startsWith( ABILITY_PREFIX )
				).length
			} pack abilities listed`
		);
	}
}

// ---------------------------------------------------------------------------
// run

function prompts() {
	const readme = readFileSync( join( here, 'README.md' ), 'utf8' );
	const quote = ( label ) => {
		const m = readme.match(
			new RegExp( `\\*\\*${ label }\\.\\*\\*[^\\n]*\\n+> ([^\\n]+)` )
		);
		if ( ! m ) {
			throw new Error( `No ${ label } prompt in bench/README.md` );
		}
		return m[ 1 ];
	};
	return { build: quote( 'Build v2' ), edit: quote( 'Edit v2' ) };
}

function fill( template, brief ) {
	return template.replace( /\{([a-z_.]+)\}/g, ( all, path ) => {
		const value = path
			.split( '.' )
			.reduce( ( v, k ) => ( v == null ? v : v[ k ] ), brief );
		if ( value == null ) {
			throw new Error( `Brief ${ brief.id } has no ${ path }` );
		}
		return Array.isArray( value ) ? value.join( ', ' ) : String( value );
	} );
}

function git( args ) {
	return execFileSync( 'git', args, { cwd: repo, encoding: 'utf8' } ).trim();
}

function versionOf( pkg ) {
	return JSON.parse(
		readFileSync(
			join( repo, 'node_modules', pkg, 'package.json' ),
			'utf8'
		)
	).version;
}

function get( url, auth, post = null, timeout = 30000 ) {
	return new Promise( ( done ) => {
		const headers = auth ? { Authorization: `Basic ${ auth }` } : {};
		if ( post !== null ) {
			headers[ 'Content-Type' ] = 'application/json';
			headers.Accept = 'application/json, text/event-stream';
		}
		const req = http.request(
			url,
			{ method: post === null ? 'GET' : 'POST', agent: false, headers },
			( res ) => {
				let body = '';
				res.on( 'data', ( c ) => ( body += c ) );
				res.on( 'end', () => done( { status: res.statusCode, body } ) );
			}
		);
		req.on( 'error', ( e ) => done( { status: 0, body: e.message } ) );
		req.setTimeout( timeout, () => req.destroy( new Error( 'timeout' ) ) );
		req.end( post === null ? undefined : JSON.stringify( post ) );
	} );
}

/**
 * Get three MCP initialize answers in a row from the site before an agent
 * step connects.
 *
 * Without this the agent sometimes started with the WordPress MCP server
 * "failed" and no WordPress tools at all. Three in a row reach both
 * Playground workers. Resolves with each attempt's status and time, and
 * throws when the site has not answered three times running in two minutes.
 * @param url
 * @param auth
 */
async function warm( url, auth ) {
	const tries = [];
	const deadline = Date.now() + 120000;
	let streak = 0;
	while ( streak < 3 ) {
		if ( Date.now() > deadline ) {
			throw new Error(
				`MCP warm-up failed: ${ JSON.stringify( tries.slice( -3 ) ) }`
			);
		}
		const started = Date.now();
		const res = await get(
			url,
			auth,
			{
				jsonrpc: '2.0',
				id: tries.length + 1,
				method: 'initialize',
				params: {
					protocolVersion: '2025-06-18',
					capabilities: {},
					clientInfo: { name: 'bench-warmup', version: '1' },
				},
			},
			60000
		);
		const ok = res.status === 200;
		tries.push( {
			status: res.status,
			ms: Date.now() - started,
			...( ok ? {} : { body: res.body.slice( 0, 300 ) } ),
		} );
		streak = ok ? streak + 1 : 0;
		if ( ! ok ) {
			await new Promise( ( r ) => setTimeout( r, 2000 ) );
		}
	}
	return tries;
}

/**
 * Load a page as an anonymous visitor: a fresh client, so no cookies.
 * @param url
 */
async function visit( url ) {
	try {
		const res = await createClient( url, { timeout: 120000 } ).get( url );
		return {
			status: res.status,
			text: res.text,
			marker: renderMarker( res.text ),
			served: res.headers[ 'x-wp-super-cache' ] || '',
		};
	} catch ( e ) {
		return {
			status: 0,
			text: '',
			marker: null,
			served: '',
			error: e.message,
		};
	}
}

/**
 * Two anonymous loads of a URL, and whether the second came from the cache:
 * both answered 200 with a render marker, and the marker is the same.
 * @param url
 */
async function twoLoads( url ) {
	const loads = [ await visit( url ), await visit( url ) ];
	const fromCache =
		loads.every( ( l ) => l.status === 200 && l.marker ) &&
		loads[ 0 ].marker === loads[ 1 ].marker;
	return {
		url,
		loads,
		record: {
			url,
			from_cache: fromCache,
			loads: loads.map( ( l ) => ( {
				status: l.status,
				marker: l.marker,
				served: l.served,
				...( l.error ? { error: l.error } : {} ),
			} ) ),
		},
	};
}

function cacheProblem( what, record ) {
	return `${ what }: the second anonymous load did not come from the cache (${ record.loads
		.map(
			( l ) =>
				`${ l.status } ${ l.marker ?? 'no marker' }${
					l.error ? ` ${ l.error }` : ''
				}`
		)
		.join( ', then ' ) })`;
}

/**
 * The rendered page at CAPTURE_WIDTH, full height, as a PNG.
 * @param url
 * @param file
 */
async function capture( url, file ) {
	const browser = await launch( findChrome() );
	try {
		const page = await browser.newPage( {
			width: CAPTURE_WIDTH,
			height: 900,
		} );
		await page.goto( url );
		await page.waitFor(
			'document.fonts ? document.fonts.ready.then( () => true ) : true',
			{ timeout: 30000 }
		);
		const full = await page.evaluate(
			'Math.ceil( Math.max( document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0 ) )'
		);
		const height = Math.min( full, CAPTURE_MAX_HEIGHT );
		const png = await page.screenshot( {
			x: 0,
			y: 0,
			width: CAPTURE_WIDTH,
			height,
		} );
		writeFileSync( file, png );
		await page.close().catch( () => {} );
		return {
			width: CAPTURE_WIDTH,
			height,
			page_height: full,
			truncated: full > height,
		};
	} finally {
		await browser.close();
	}
}

// Playground can die at boot with a V8 fatal error, so a failed boot is
// retried once.
async function serve( site, port, log ) {
	try {
		return await boot( site, port, log );
	} catch {
		return await boot( site, port, `${ log }.retry` );
	}
}

async function boot( site, port, log ) {
	if ( ( await get( `http://127.0.0.1:${ port }/` ) ).status !== 0 ) {
		throw new Error( `Something already answers on port ${ port }` );
	}
	const free = freemem() / 2 ** 30;
	if ( free < MIN_FREE_GB ) {
		throw new Error( `Only ${ free.toFixed( 1 ) } GB free, not booting` );
	}
	const child = spawn(
		process.execPath,
		[
			playgroundCli,
			'server',
			`--port=${ port }`,
			`--site-url=http://127.0.0.1:${ port }`,
			'--php=8.3',
			'--workers=2',
			'--wordpress-install-mode=do-not-attempt-installing',
			'--mount-dir-before-install',
			site,
			'/wordpress',
		],
		{ env: { ...process.env, MSYS_NO_PATHCONV: '1' } }
	);
	const out = createWriteStream( log );
	child.stdout.pipe( out );
	child.stderr.pipe( out );
	const deadline = Date.now() + 180000;
	while ( Date.now() < deadline ) {
		if ( child.exitCode !== null ) {
			throw new Error(
				`Playground exited (${ child.exitCode }), see ${ log }`
			);
		}
		const res = await get( `http://127.0.0.1:${ port }/wp-json/` );
		if ( res.status === 200 ) {
			return child;
		}
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
	}
	await stop( child );
	throw new Error( `Playground did not answer on port ${ port }` );
}

/**
 * Kill a child and its tree, and wait until it has exited.
 * @param child
 */
async function stop( child ) {
	if ( ! child || child.exitCode !== null || child.signalCode !== null ) {
		return;
	}
	const exited = new Promise( ( r ) => child.once( 'exit', r ) );
	if ( process.platform === 'win32' ) {
		try {
			execFileSync(
				'taskkill',
				[ '/pid', String( child.pid ), '/T', '/F' ],
				{
					stdio: 'ignore',
				}
			);
		} catch {}
	} else {
		child.kill( 'SIGTERM' );
	}
	await Promise.race( [
		exited,
		new Promise( ( r ) => setTimeout( r, 15000 ) ),
	] );
}

/**
 * The ability names an MCP discover call returned, from one tool result.
 * @param content
 */
function discovered( content ) {
	const text = Array.isArray( content )
		? content.map( ( c ) => c?.text ?? '' ).join( '' )
		: String( content ?? '' );
	try {
		return ( JSON.parse( text ).abilities || [] )
			.map( ( a ) => a?.name )
			.filter( Boolean );
	} catch {
		return [];
	}
}

/**
 * One headless Claude Code turn. Resolves with the parsed stream and the exit.
 * @param root0
 * @param root0.cwd
 * @param root0.args
 * @param root0.transcript
 * @param root0.useLogin
 */
function agent( { cwd, args, transcript, useLogin } ) {
	// This Node first, so the agent's npx runs Block Runner on a version it
	// accepts.
	// MCP_TIMEOUT gives a slow site a minute to answer the MCP handshake.
	const env = {
		...process.env,
		PATH: `${ dirname( process.execPath ) }${ delimiter }${ process.env.PATH }`,
		MCP_TIMEOUT: '60000',
	};
	if ( useLogin ) {
		delete env.ANTHROPIC_API_KEY;
	}
	return new Promise( ( done ) => {
		const started = Date.now();
		const child = spawn( 'claude', args, {
			cwd,
			env,
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
		const out = createWriteStream( transcript );
		let buffer = '';
		let stderr = '';
		child.stdout.on( 'data', ( c ) => {
			out.write( c );
			buffer += c;
		} );
		child.stderr.on( 'data', ( c ) => ( stderr += c ) );
		const timer = setTimeout( () => {
			stderr += `\n[bench] killed after ${ STEP_TIMEOUT_MS / 60000 } minutes`;
			stop( child );
		}, STEP_TIMEOUT_MS );
		child.on( 'close', ( code ) => {
			clearTimeout( timer );
			out.end();
			const events = buffer
				.split( '\n' )
				.filter( Boolean )
				.map( ( l ) => {
					try {
						return JSON.parse( l );
					} catch {
						return null;
					}
				} )
				.filter( Boolean );
			const init = events.find(
				( e ) => e.type === 'system' && e.subtype === 'init'
			);
			const result = events.findLast( ( e ) => e.type === 'result' );
			const models = new Set(
				events
					.filter( ( e ) => e.type === 'assistant' )
					.map( ( e ) => e.message?.model )
					.filter( Boolean )
			);
			const tools = {};
			const discoverIds = new Set();
			for ( const e of events.filter(
				( x ) => x.type === 'assistant'
			) ) {
				for ( const part of e.message?.content || [] ) {
					if ( part.type === 'tool_use' ) {
						const key =
							part.name ===
							'mcp__wordpress__mcp-adapter-execute-ability'
								? `ability:${ part.input?.ability_name ?? '?' }`
								: part.name;
						tools[ key ] = ( tools[ key ] || 0 ) + 1;
						if (
							part.name ===
							'mcp__wordpress__mcp-adapter-discover-abilities'
						) {
							discoverIds.add( part.id );
						}
					}
				}
			}
			const listed = new Set();
			for ( const e of events.filter( ( x ) => x.type === 'user' ) ) {
				for ( const part of e.message?.content || [] ) {
					if (
						part.type === 'tool_result' &&
						discoverIds.has( part.tool_use_id )
					) {
						discovered( part.content ).forEach( ( n ) =>
							listed.add( n )
						);
					}
				}
			}
			done( {
				exit: code,
				seconds: Math.round( ( Date.now() - started ) / 1000 ),
				init: init
					? {
							model: init.model,
							tools: init.tools,
							mcp_servers: init.mcp_servers,
							skills: init.skills,
							plugins: init.plugins?.map( ( p ) => p.name ),
							claude_code_version: init.claude_code_version,
						}
					: null,
				models: [ ...models ],
				is_error: result?.is_error ?? true,
				subtype: result?.subtype ?? null,
				turns: result?.num_turns ?? null,
				cost_usd: result?.total_cost_usd ?? null,
				result: result?.result ?? null,
				tool_calls: tools,
				mcp_tools: ( init?.tools || [] ).filter( ( t ) =>
					t.startsWith( 'mcp__' )
				),
				abilities_listed: [ ...listed ].sort(),
				stderr: stderr.trim().slice( -2000 ),
			} );
		} );
	} );
}

/**
 * Site Data's options for a brief: the business details (blogname and the
 * contact options) and the business profile. A list is one item per line.
 * @param brief
 */
function businessOptions( brief ) {
	const options = {
		blogname: brief.business,
		voyager_site_phone: brief.phone,
		voyager_site_email: brief.email,
		voyager_site_address: brief.address,
		voyager_site_address_city: brief.address_city,
		voyager_site_address_state: brief.address_state,
		voyager_site_address_zip: brief.address_zip,
		voyager_site_hours: brief.hours,
		voyager_site_services: brief.services,
		voyager_site_service_area: brief.service_area,
		voyager_site_credentials: brief.credentials,
		voyager_site_founded: brief.founded,
		voyager_site_license: brief.license,
		voyager_site_rating: brief.rating,
		voyager_site_review_count: brief.review_count,
		voyager_site_cta_label: brief.primary_cta?.label,
		voyager_site_cta_url: brief.primary_cta?.url,
	};
	return Object.fromEntries(
		Object.entries( options )
			.filter( ( [ , v ] ) => v !== undefined && v !== null )
			.map( ( [ k, v ] ) => [
				k,
				Array.isArray( v ) ? v.join( '\n' ) : String( v ),
			] )
	);
}

/**
 * Set an option through WP-CLI, the value on STDIN, so a list's line breaks
 * and any quote reach it as they are.
 * @param site
 * @param env
 * @param option
 * @param value
 */
function setOption( site, env, option, value ) {
	execFileSync( 'wp', [ `--path=${ site }`, 'option', 'update', option ], {
		encoding: 'utf8',
		env,
		input: value,
		stdio: [ 'pipe', 'pipe', 'inherit' ],
	} );
}

function runDir( group, model, brief, repeat ) {
	return join( here, 'runs-v2', group, model, brief.id, String( repeat ) );
}

async function runOne( group, model, brief, repeat, port, text, useLogin ) {
	const out = runDir( group, model, brief, repeat );
	rmSync( out, { recursive: true, force: true } );
	mkdirSync( out, { recursive: true } );
	const template = JSON.parse(
		readFileSync( join( work, 'templates', `${ group }.json` ), 'utf8' )
	);
	const name = `${ group }-${ model }-${ brief.id }-${ repeat }`;
	const site = join( work, 'sites', name );
	const desk = join( work, 'agents', name );
	for ( const d of [ site, `${ site }-php`, desk ] ) {
		rmSync( d, { recursive: true, force: true } );
	}
	const record = {
		protocol: PROTOCOL,
		group,
		model,
		brief: brief.id,
		split: brief.split,
		vertical: brief.vertical,
		credentials: brief.credentials.length,
		repeat,
		port,
		started: new Date().toISOString(),
		wordpress: template.wordpress,
		theme: template.theme,
		plugins: template.plugins,
		page_cache: template.page_cache,
		pack_commit: WITH_PACK.includes( group ) ? template.pack_commit : null,
		block_runner: versionOf( 'block-runner' ),
		wesper: versionOf( 'wesper' ),
		playground_cli: versionOf( '@wp-playground/cli' ),
		prompts: {
			build: fill( text.build, brief ),
			edit: fill( text.edit, brief ),
		},
		abilities_offered: null,
		cache: {},
		capture: null,
		steps: {},
		page_id: null,
		failure: null,
	};
	// Write then rename, so a crash mid-write cannot leave a torn run.json.
	const save = () => {
		writeFileSync(
			join( out, 'run.json.tmp' ),
			JSON.stringify( record, null, 2 ) + '\n'
		);
		renameSync( join( out, 'run.json.tmp' ), join( out, 'run.json' ) );
	};

	let server = null;
	try {
		if ( template.protocol !== PROTOCOL ) {
			throw new Error(
				`the ${ group } template is not a v2 template. Run: node bench/run.mjs prepare`
			);
		}
		cpSync( join( work, 'templates', group ), site, { recursive: true } );
		const env = phpEnv( site );
		const cli = ( args ) => wp( site, args, env ).trim();
		const url = `http://127.0.0.1:${ port }`;
		cli( [ 'option', 'update', 'siteurl', url ] );
		cli( [ 'option', 'update', 'home', url ] );
		const options = businessOptions( brief );
		for ( const [ option, value ] of Object.entries( options ) ) {
			setOption( site, env, option, value );
		}
		const offered = offeredAbilities( site, env, group );
		record.abilities_offered = offered.abilities;
		if ( offered.problem ) {
			throw new Error( offered.problem );
		}

		mkdirSync( desk, { recursive: true } );
		// The MCP config and Claude Code's debug logs carry the site's
		// application password, so they live outside the agent's working
		// directory, where its file tools cannot read them into a transcript.
		const debug = join( work, 'debug' );
		mkdirSync( debug, { recursive: true } );
		const mcpConfig = join( debug, `${ name }.mcp.json` );
		const auth = Buffer.from( `admin:${ template.password }` ).toString(
			'base64'
		);
		writeFileSync(
			mcpConfig,
			JSON.stringify( {
				mcpServers: {
					wordpress: {
						type: 'http',
						url: `${ url }/wp-json/mcp/mcp-adapter-default-server`,
						headers: { Authorization: `Basic ${ auth }` },
					},
				},
			} )
		);

		const common = [
			'--model',
			model,
			'--restricted',
			'--strict-mcp-config',
			'--mcp-config',
			mcpConfig,
			'--permission-mode',
			'dontAsk',
			'--output-format',
			'stream-json',
			'--verbose',
			'--max-budget-usd',
			STEP_BUDGET_USD,
		];
		// The site's own block registry, in every group: the scorer validates
		// the pages against it, so a block the site registers never warns as
		// unregistered, and one it does not still does.
		execFileSync(
			process.execPath,
			[
				join( repo, 'node_modules', 'wesper', 'dist', 'cli.js' ),
				'collect',
				'--wp-path',
				site,
				'--out',
				join( out, 'wesper-manifest.json' ),
			],
			{ env, stdio: 'ignore' }
		);
		let toolArgs;
		if ( group === 'pack' ) {
			// The pack's skills as a plugin, the site's own Wesper manifest,
			// the intents the skills point at, and Block Runner and Wesper
			// through npx.
			const plugin = join( desk, '.pack-skills' );
			mkdirSync( join( plugin, '.claude-plugin' ), { recursive: true } );
			writeFileSync(
				join( plugin, '.claude-plugin', 'plugin.json' ),
				JSON.stringify( {
					name: 'voyager-open-blocks',
					version: JSON.parse(
						readFileSync( join( repo, 'package.json' ), 'utf8' )
					).version,
					description: 'Voyager Open Blocks skills',
				} )
			);
			cpSync( join( repo, 'skills' ), join( plugin, 'skills' ), {
				recursive: true,
			} );
			cpSync(
				join( repo, 'patterns', 'intents' ),
				join( desk, 'patterns', 'intents' ),
				{ recursive: true }
			);
			symlinkSync(
				join( repo, 'node_modules' ),
				join( desk, 'node_modules' ),
				'junction'
			);
			copyFileSync(
				join( out, 'wesper-manifest.json' ),
				join( desk, 'site.context.json' )
			);
			toolArgs = [
				'--plugin-dir',
				plugin,
				'--tools',
				'Read,Glob,Grep,Write,Edit,Bash,Skill',
				'--allowedTools',
				'mcp__wordpress',
				'Read',
				'Glob',
				'Grep',
				'Write',
				'Edit',
				'Skill',
				'Bash(npx block-runner *)',
				'Bash(npx wesper *)',
			];
		} else {
			// baseline and plugin: the site's MCP tools and nothing else.
			toolArgs = [ '--tools', '', '--allowedTools', 'mcp__wordpress' ];
		}

		record.free_gb_at_boot =
			Math.round( ( freemem() / 2 ** 30 ) * 10 ) / 10;
		server = await serve(
			site,
			port,
			join( work, `server-${ port }.log` )
		);
		// Before any agent spend: the cache must serve the front page.
		const probe = await twoLoads( `${ url }/` );
		record.cache.probe = probe.record;
		if ( ! probe.record.from_cache ) {
			throw new Error( cacheProblem( 'probe', probe.record ) );
		}
		const mcpUrl = `${ url }/wp-json/mcp/mcp-adapter-default-server`;
		record.mcp_warmup = { build: await warm( mcpUrl, auth ) };

		const session = randomUUID();
		record.session_id = session;
		record.steps.build = await agent( {
			cwd: desk,
			args: [
				'-p',
				record.prompts.build,
				'--session-id',
				session,
				'--debug-file',
				join( debug, `${ name }-build.log` ),
				...common,
				...toolArgs,
			],
			transcript: join( out, 'transcript-build.jsonl' ),
			useLogin,
		} );
		save();
		if ( ! connected( record.steps.build ) ) {
			throw new Error(
				'build: the WordPress MCP server did not connect'
			);
		}

		const listPages = () =>
			JSON.parse(
				cli( [
					'post',
					'list',
					'--post_type=page',
					'--post_status=any',
					'--fields=ID,post_title,post_status,post_modified_gmt,url',
					'--format=json',
				] )
			).filter( ( p ) => ! template.pages.includes( Number( p.ID ) ) );
		const pages = listPages();
		record.new_pages = pages;
		const published = pages.filter( ( p ) => p.post_status === 'publish' );
		const pick = ( published.length ? published : pages ).sort(
			( a, b ) =>
				b.post_modified_gmt.localeCompare( a.post_modified_gmt ) ||
				Number( b.ID ) - Number( a.ID )
		)[ 0 ];
		const buildError = record.steps.build.is_error
			? `build: ${ record.steps.build.subtype || 'error' }`
			: null;
		if ( ! pick ) {
			record.failure = [ buildError, 'build: no new page on the site' ]
				.filter( Boolean )
				.join( '; ' );
			return record;
		}
		record.page_id = Number( pick.ID );
		record.page_status = pick.post_status;
		const content = () =>
			wp(
				site,
				[
					'post',
					'get',
					String( record.page_id ),
					'--field=post_content',
				],
				env
			).replace( /\r?\n$/, '' );
		writeFileSync( join( out, 'page.html' ), content() );

		// What a visitor gets, through the cache. A page the agent left
		// unpublished has nothing to load: the rendered measures score it as
		// missing, which is the agent's result, not the harness's.
		const rendered = async ( status, link, what, file ) => {
			if ( status !== 'publish' ) {
				record.cache[ what ] = {
					skipped: `the page is ${ status }, so no visitor can load it`,
				};
				return null;
			}
			const pair = await twoLoads( link );
			record.cache[ what ] = pair.record;
			if ( ! pair.record.from_cache ) {
				throw new Error( cacheProblem( what, pair.record ) );
			}
			writeFileSync( join( out, file ), pair.loads[ 1 ].text );
			return pair;
		};
		const built = await rendered(
			pick.post_status,
			pick.url,
			'build',
			'page.rendered.html'
		);
		if ( built ) {
			record.capture = await capture(
				pick.url,
				join( out, 'page.png' )
			);
		}
		record.mcp_warmup.edit = await warm( mcpUrl, auth );
		save();

		record.steps.edit = await agent( {
			cwd: desk,
			args: [
				'-p',
				record.prompts.edit,
				'--resume',
				session,
				'--debug-file',
				join( debug, `${ name }-edit.log` ),
				...common,
				...toolArgs,
			],
			transcript: join( out, 'transcript-edit.jsonl' ),
			useLogin,
		} );
		if ( ! connected( record.steps.edit ) ) {
			throw new Error( 'edit: the WordPress MCP server did not connect' );
		}
		writeFileSync( join( out, 'after-edit.html' ), content() );
		const now = listPages().find(
			( p ) => Number( p.ID ) === record.page_id
		);
		record.page_status_after = now?.post_status ?? null;
		const edited = await rendered(
			now?.post_status ?? 'missing',
			now?.url,
			'edit',
			'after-edit.rendered.html'
		);
		if ( edited && built ) {
			// The cache kept the copy it stored after the build.
			record.cache.edit.stale =
				edited.loads[ 0 ].marker === built.loads[ 1 ].marker;
		}
		const after = {};
		for ( const option of Object.keys( businessOptions( brief ) ) ) {
			after[
				option === 'blogname'
					? 'company'
					: option.replace( 'voyager_site_', '' )
			] = cli( [ 'option', 'get', option ] );
		}
		writeFileSync(
			join( out, 'site-data.json' ),
			JSON.stringify( after, null, 2 ) + '\n'
		);
		// Proof the site did not change version under the agent.
		record.wordpress_after = cli( [ 'core', 'version' ] );
		if ( record.wordpress_after !== template.wordpress ) {
			throw new Error(
				`WordPress went from ${ template.wordpress } to ${ record.wordpress_after } during the run`
			);
		}
		const editError = record.steps.edit.is_error
			? `edit: ${ record.steps.edit.subtype || 'error' }`
			: null;
		record.failure =
			[ buildError, editError ].filter( Boolean ).join( '; ' ) || null;
		return record;
	} catch ( e ) {
		record.failure = `harness: ${ e.message.split( '\n' )[ 0 ] }`;
		return record;
	} finally {
		await stop( server );
		record.finished = new Date().toISOString();
		save();
		if ( ! opts.keep ) {
			for ( const d of [ site, `${ site }-php`, desk ] ) {
				try {
					rmSync( d, { recursive: true, force: true } );
				} catch {}
			}
		}
	}
}

function list( value, all ) {
	return ( value && value !== true ? String( value ) : all.join( ',' ) )
		.split( ',' )
		.map( ( v ) => v.trim() )
		.filter( Boolean );
}

async function run() {
	const { briefs } = JSON.parse(
		readFileSync( join( here, 'briefs-v2.json' ), 'utf8' )
	);
	const groups = list( opts.groups, GROUPS );
	const unknown = groups.filter( ( g ) => ! GROUPS.includes( g ) );
	if ( unknown.length ) {
		throw new Error(
			`Unknown group ${ unknown.join( ', ' ) }. Groups: ${ GROUPS.join(
				', '
			) }`
		);
	}
	const models = list( opts.models, MODELS );
	const repeats = opts.repeats ? Number( opts.repeats ) : 1;
	if ( ! Number.isInteger( repeats ) || repeats < 1 ) {
		throw new Error(
			`--repeats takes a whole number, not ${ opts.repeats }`
		);
	}
	let chosen = briefs;
	if ( opts.briefs ) {
		const ids = String( opts.briefs ).split( ',' );
		chosen = briefs.filter( ( b ) => ids.includes( b.id ) );
	}
	if ( opts.split ) {
		chosen = chosen.filter( ( b ) => b.split === opts.split );
	}
	if ( opts.limit ) {
		chosen = chosen.slice( 0, Number( opts.limit ) );
	}
	if ( ! chosen.length ) {
		throw new Error( 'No brief matches --briefs, --split and --limit' );
	}
	for ( const group of groups ) {
		if ( ! existsSync( join( work, 'templates', `${ group }.json` ) ) ) {
			throw new Error(
				`No ${ group } template. Run: node bench/run.mjs prepare`
			);
		}
	}
	if ( ! findChrome() ) {
		throw new Error(
			'No Chrome or Chromium for the page capture. Set CHROME_PATH.'
		);
	}
	const text = prompts();
	for ( let repeat = 1; repeat <= repeats; repeat++ ) {
		for ( const brief of chosen ) {
			for ( const model of models ) {
				for ( const group of groups ) {
					const label = `${ group }/${ model }/${ brief.id }/${ repeat }`;
					if (
						! opts.force &&
						complete( group, model, brief, repeat )
					) {
						console.log( `${ label }: complete, skipped` );
						continue;
					}
					const r = await runOne(
						group,
						model,
						brief,
						repeat,
						PORT,
						text,
						Boolean( opts[ 'use-login' ] )
					);
					const cost = [
						r.steps.build?.cost_usd,
						r.steps.edit?.cost_usd,
					]
						.filter( ( c ) => c !== undefined && c !== null )
						.reduce( ( a, b ) => a + b, 0 );
					console.log(
						`${ label }: ${ r.failure || 'ok' } (page ${
							r.page_id ?? '-'
						}, $${ cost.toFixed( 2 ) }, turns ${
							r.steps.build?.turns ?? '-'
						}/${ r.steps.edit?.turns ?? '-' })`
					);
				}
			}
		}
	}
}

/**
 * Did the step's session start with the WordPress MCP server connected?
 * @param step
 */
function connected( step ) {
	return Boolean(
		step?.init?.mcp_servers?.some(
			( s ) => s.name === 'wordpress' && s.status === 'connected'
		)
	);
}

/**
 * A run is complete when its run.json finished with no harness failure and
 * every step it ran had the site's MCP server. A failure the agent caused is
 * a result, so it is kept.
 * @param group
 * @param model
 * @param brief
 * @param repeat
 */
function complete( group, model, brief, repeat ) {
	try {
		const r = JSON.parse(
			readFileSync(
				join( runDir( group, model, brief, repeat ), 'run.json' ),
				'utf8'
			)
		);
		return (
			r.protocol === PROTOCOL &&
			Boolean( r.finished ) &&
			! /^harness:/.test( r.failure ?? '' ) &&
			Object.values( r.steps ).every( connected )
		);
	} catch {
		return false;
	}
}

const commands = { prepare, run };
if ( ! commands[ opts._[ 0 ] ] ) {
	console.error( 'Usage: node bench/run.mjs <prepare|run> [options]' );
	process.exit( 2 );
}
await commands[ opts._[ 0 ] ]();
