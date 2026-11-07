#!/usr/bin/env node
/**
 * A local WordPress with this checkout as the plugin. Nothing to install
 * beyond `npm install`: WordPress Playground runs PHP and SQLite inside Node.
 *
 *   npm run dev                  boot, log in as admin, open the demo index
 *   npm run dev -- --check       boot, request the demo pages and Settings > Site Data, stop
 *   npm run dev -- --help        every option
 *
 * blueprints/dev.json activates the mounted checkout and runs
 * playground/seed.php, the seeding blueprint.json uses: demo Site Data and
 * one page per item under /voyager-open/. The site lives in memory and starts
 * fresh on every run. PHP files are read from this checkout on every request,
 * so edits show on the next page load.
 *
 * scripts/screenshots.mjs imports boot() and verify() from here.
 */
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { createClient } from './lib/http.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { flags, repo } from './lib/site.mjs';

export const DEMO_PATH = '/voyager-open/';
export const SETTINGS_PATH =
	'/wp-admin/options-general.php?page=voyager-site-data';

const PLUGIN_DIR = '/wordpress/wp-content/plugins/voyager-open-blocks';
const DEBUG_LOG = '/wordpress/wp-content/debug.log';
const CHECK_TIMEOUT_MINUTES = 15;

const HELP = `npm run dev [-- options]

Starts WordPress Playground with this checkout mounted as the plugin, seeds
the demo pages and Site Data (blueprints/dev.json), and logs you in as admin.

  --port <n>       Port to serve on. Default 9400, or a free port if 9400 is taken.
  --wp <version>   WordPress version, such as 6.9. Default latest.
  --php <version>  PHP version, such as 8.1. Default 8.3.
  --no-open        Do not open the demo index in your browser.
  --check          Boot, request the demo pages and Settings > Site Data, then stop.
                   Exits 0 when every one of them answers 200.
  --debug          Print Playground's debug output.
  --help           Show this help.

The first run downloads WordPress from wordpress.org; later runs reuse it.`;

const KNOWN = [ 'port', 'wp', 'php', 'no-open', 'check', 'debug', 'help' ];

/**
 * Boot Playground with this checkout mounted as the plugin.
 *
 * @param {Object}  [options]
 * @param {number}  [options.port]  Port; omit for 9400 or a free one.
 * @param {string}  [options.wp]    WordPress version.
 * @param {string}  [options.php]   PHP version.
 * @param {boolean} [options.debug] Playground's debug output.
 * @return {Promise<{url: string, playground: Object, stop: () => Promise<void>}>} The running site. Call stop() to shut it down.
 */
export async function boot( { port, wp, php, debug = false } = {} ) {
	let cli;
	try {
		cli = await import( '@wp-playground/cli' );
	} catch ( error ) {
		if ( error.code === 'ERR_MODULE_NOT_FOUND' ) {
			throw new Error(
				'WordPress Playground is not installed. Run npm install, then try again.'
			);
		}
		throw error;
	}

	// Passed as an object rather than a path, so --wp and --php can override
	// preferredVersions (Playground ignores its own flags for a blueprint file).
	const blueprint = JSON.parse(
		readFileSync( join( repo, 'blueprints', 'dev.json' ), 'utf8' )
	);
	blueprint.preferredVersions = {
		...blueprint.preferredVersions,
		...( wp ? { wp: String( wp ) } : {} ),
		...( php ? { php: String( php ) } : {} ),
	};

	let server;
	try {
		server = await cli.runCLI( {
			command: 'server',
			blueprint,
			// A host path and a VFS path as an object, so a Windows drive
			// letter never meets Playground's colon-separated --mount format.
			mount: [ { hostPath: resolve( repo ), vfsPath: PLUGIN_DIR } ],
			...( port ? { port: Number( port ) } : {} ),
			// What the CLI defines by default: notices go to wp-content/debug.log.
			'define-bool': {
				WP_DEBUG: true,
				WP_DEBUG_LOG: true,
				WP_DEBUG_DISPLAY: false,
			},
			verbosity: debug ? 'debug' : 'normal',
		} );
	} catch ( error ) {
		if ( error.code === 'EADDRINUSE' ) {
			throw new Error(
				`Port ${ port } is in use. Stop whatever is using it, or pass another, such as --port ${
					Number( port ) + 1
				}.`
			);
		}
		throw error;
	}

	let stopping;
	return {
		url: server.serverUrl.replace( /\/$/, '' ),
		playground: server.playground,
		stop: () => ( stopping ??= server[ Symbol.asyncDispose ]() ),
	};
}

/**
 * Request the demo index, every demo page it links to, and Settings > Site
 * Data as the logged-in admin.
 *
 * @param {{url: string}} site From boot(), or any running dev site.
 * @return {Promise<{passed: string[], failed: string[], pages: string[]}>} What passed, what failed, and the demo page paths.
 */
export async function verify( site ) {
	const client = createClient( site.url );
	const passed = [];
	const failed = [];
	const expect = ( ok, message ) => ( ok ? passed : failed ).push( message );

	// A fresh Playground server answers its first request with a redirect to
	// the same URL. Spend it here, so later requests and cookies are ordinary.
	const first = await client.get( '/' );
	const notes = [
		`first request: ${ first.hops.join( ', ' ) || first.status }`,
	];

	const index = await client.get( DEMO_PATH );
	expect(
		index.status === 200,
		`${ DEMO_PATH } answers ${ index.status } (the demo index)`
	);
	notes.push(
		`admin toolbar on the demo index: ${
			index.text.includes( 'id="wpadminbar"' ) ? 'yes' : 'no'
		}`
	);

	const escaped = site.url.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const links = new RegExp(
		`href="${ escaped }(/voyager-open/[a-z0-9-]+/)"`,
		'g'
	);
	const pages = [
		...new Set(
			[ ...index.text.matchAll( links ) ].map( ( m ) => m[ 1 ] )
		),
	];
	expect(
		pages.length > 0,
		`the demo index links to ${ pages.length } demo pages`
	);

	for ( const path of pages ) {
		const page = await client.get( path );
		expect( page.status === 200, `${ path } answers ${ page.status }` );
	}

	notes.push(
		`cookies held: ${ [ ...client.cookies.keys() ].join( ', ' ) }`
	);
	let settings = await client.get( SETTINGS_PATH );
	const onSettings = ( res ) =>
		new URL( res.url ).pathname.endsWith( '/options-general.php' );

	if ( ! onSettings( settings ) ) {
		notes.push( `settings redirects: ${ settings.hops.join( ', ' ) }` );
		// Playground's automatic login did not hold. Log in the way a person
		// would, so the report says whether sessions work at all.
		await client.get( '/wp-login.php' );
		const login = await client.post( '/wp-login.php', {
			log: 'admin',
			pwd: 'password',
			'wp-submit': 'Log In',
			testcookie: '1',
			redirect_to: `${ site.url }${ SETTINGS_PATH }`,
		} );
		notes.push(
			`form login: ${ login.hops.join( ', ' ) || login.status } ending at ${
				new URL( login.url ).pathname
			}`
		);
		failed.push(
			`Settings > Site Data sent the request to ${ settings.url }, so the automatic admin login did not hold. ${ notes.join(
				'; '
			) }`
		);
		settings = login;
	}

	if ( onSettings( settings ) ) {
		expect(
			settings.status === 200,
			`Settings > Site Data answers ${ settings.status } logged in as admin`
		);
		const phone =
			/id="voyager_site_phone"[^>]*\svalue="([^"]*)"/.exec(
				settings.text
			)?.[ 1 ] || '';
		expect(
			phone !== '',
			phone
				? `Settings > Site Data shows the demo phone ${ phone }`
				: 'Settings > Site Data has no phone number, so the demo Site Data was not seeded'
		);
	}

	return { passed, failed, pages };
}

/**
 * Lines of wp-content/debug.log, or null when it cannot be read.
 *
 * @param {{playground: Object}} site From boot().
 * @return {Promise<string[]|null>} Log lines.
 */
export async function phpLog( site ) {
	try {
		if ( ! ( await site.playground.fileExists( DEBUG_LOG ) ) ) {
			return [];
		}
		return ( await site.playground.readFileAsText( DEBUG_LOG ) )
			.split( /\r?\n/ )
			.filter( Boolean );
	} catch {
		return null;
	}
}

/**
 * Report PHP errors from the log. Fatal errors fail; notices, warnings and
 * deprecations from this plugin's files are annotated.
 *
 * @param {string[]|null} lines From phpLog().
 * @return {number} How many fatal errors there were.
 */
export function reportPhpLog( lines ) {
	if ( lines === null ) {
		annotate( 'warning', 'Could not read wp-content/debug.log.' );
		return 0;
	}
	const fatal = lines.filter( ( line ) =>
		/PHP (Fatal|Parse) error/.test( line )
	);
	const ours = lines.filter(
		( line ) =>
			line.includes( 'plugins/voyager-open-blocks/' ) &&
			/PHP (Warning|Notice|Deprecated)/.test( line ) &&
			! fatal.includes( line )
	);
	for ( const line of fatal ) {
		annotate( 'error', line, 'PHP fatal error' );
	}
	for ( const line of ours ) {
		annotate( 'warning', line, 'PHP notice from the plugin' );
	}
	if ( ! fatal.length && ! ours.length ) {
		console.log(
			`ok   no PHP errors from the plugin in debug.log (${ lines.length } lines)`
		);
	}
	return fatal.length;
}

/**
 * On a failed check, one notice with what Playground was told and the end of
 * the PHP log, since CI job logs are not always readable.
 *
 * @param {{playground: Object}} site From boot().
 * @param {string[]|null}        log  From phpLog().
 */
async function explain( site, log ) {
	const facts = [];
	try {
		const consts = JSON.parse(
			await site.playground.readFileAsText(
				'/internal/shared/consts.json'
			)
		);
		facts.push(
			`Playground constants: ${ Object.keys( consts ).join( ', ' ) }`
		);
	} catch ( error ) {
		facts.push( `Playground constants unreadable: ${ error.message }` );
	}
	try {
		const result = await site.playground.run( {
			code: `<?php echo json_encode( [
				'auto_login_plugin' => file_exists( '/internal/shared/mu-plugins/1-auto-login.php' ),
				'auto_login_user' => defined( 'PLAYGROUND_AUTO_LOGIN_AS_USER' ) ? PLAYGROUND_AUTO_LOGIN_AS_USER : null,
			] );`,
		} );
		facts.push( `PHP sees: ${ result.text }` );
	} catch ( error ) {
		facts.push( `PHP probe failed: ${ error.message }` );
	}
	facts.push(
		`debug.log tail: ${ ( log || [] ).slice( -12 ).join( ' | ' ) || 'empty' }`
	);
	annotate( 'notice', facts.join( '\n' ), 'Why the check failed' );
}

/**
 * Open a URL in the default browser. Best effort: failures are ignored.
 *
 * @param {string} url
 */
function openBrowser( url ) {
	const [ command, args, extra ] =
		process.platform === 'win32'
			? [
					'cmd.exe',
					[ '/d', '/s', '/c', `start "" "${ url }"` ],
					{ windowsVerbatimArguments: true },
				]
			: [ process.platform === 'darwin' ? 'open' : 'xdg-open', [ url ] ];
	try {
		const child = spawn( command, args, {
			stdio: 'ignore',
			detached: true,
			windowsHide: true,
			...extra,
		} );
		child.on( 'error', () => {} );
		child.unref();
	} catch {
		// No browser to open; the URL is printed.
	}
}

/**
 * --check: boot, verify, read the PHP log, always stop.
 *
 * @param {Object} opts Parsed flags.
 * @return {Promise<number>} Exit code.
 */
async function check( opts ) {
	const started = Date.now();
	const seconds = () => Math.round( ( Date.now() - started ) / 1000 );
	let site;
	let code = 0;

	setTimeout( () => {
		annotate(
			'error',
			`npm run dev -- --check gave up after ${ CHECK_TIMEOUT_MINUTES } minutes.`
		);
		process.exit( 1 );
	}, CHECK_TIMEOUT_MINUTES * 60000 ).unref();

	try {
		group( 'Boot WordPress Playground with this checkout as the plugin' );
		try {
			site = await boot( opts );
		} finally {
			endGroup();
		}
		annotate(
			'notice',
			`Booted ${ site.url } in ${ seconds() }s on Node ${ process.versions.node } (${ process.platform }).`,
			'Dev site booted'
		);

		const { passed, failed } = await verify( site );
		for ( const message of passed ) {
			console.log( `ok   ${ message }` );
		}
		for ( const message of failed ) {
			annotate( 'error', message, 'npm run dev -- --check' );
		}
		const log = await phpLog( site );
		const fatal = reportPhpLog( log );
		code = failed.length || fatal ? 1 : 0;
		if ( code ) {
			await explain( site, log );
		}
	} catch ( error ) {
		annotate(
			'error',
			`${ error.message }${ error.cause ? ` (${ error.cause.message || error.cause })` : '' }`,
			'npm run dev -- --check'
		);
		if ( opts.debug ) {
			console.error( error.stack );
		}
		code = 1;
	} finally {
		if ( site ) {
			await site.stop();
		}
	}

	if ( code === 0 ) {
		annotate(
			'notice',
			`Demo index and Settings > Site Data answered 200. Done in ${ seconds() }s, server stopped.`,
			'npm run dev -- --check'
		);
	}
	return code;
}

/**
 * The interactive server: print where to go, open the browser, stop on Ctrl+C.
 *
 * @param {Object} opts Parsed flags.
 */
async function serve( opts ) {
	const site = await boot( opts );
	const at = ( path ) => `${ site.url }${ path }`;

	console.log( `
Voyager Open Blocks is running at ${ site.url }
You are logged in as admin (password: password).

Try this first:
  1. Open the demo index, one page per block, binding source and pattern:
     ${ at( DEMO_PATH ) }
  2. Change the phone number under Settings > Site Data:
     ${ at( SETTINGS_PATH ) }
  3. Reload the CTA band. Its call button is bound to that number:
     ${ at( `${ DEMO_PATH }cta-band/` ) }

PHP and patterns/*.html changes show on the next page load. For src/, run
npm run start in a second terminal. The site starts fresh on every run.
Press Ctrl+C to stop.
` );

	const graphical =
		process.platform !== 'linux' ||
		process.env.DISPLAY ||
		process.env.WAYLAND_DISPLAY;
	if (
		! opts[ 'no-open' ] &&
		process.stdout.isTTY &&
		! process.env.CI &&
		graphical
	) {
		openBrowser( at( DEMO_PATH ) );
	}

	const shutdown = async () => {
		console.log( '\nStopping the server.' );
		await site.stop();
		process.exit( 0 );
	};
	process.once( 'SIGINT', shutdown );
	process.once( 'SIGTERM', shutdown );
}

/**
 * Accept --name=value as well as --name value.
 *
 * @param {string[]} argv
 * @return {Object} Parsed flags.
 */
export function parseFlags( argv ) {
	return flags(
		argv.flatMap( ( arg ) =>
			/^--[^=]+=/.test( arg )
				? arg.split( /=(.*)/s ).slice( 0, 2 )
				: [ arg ]
		)
	);
}

async function main() {
	const problem = nodeVersionProblem();
	if ( problem ) {
		console.error( problem );
		process.exit( 1 );
	}

	const opts = parseFlags( process.argv.slice( 2 ) );
	if ( opts.help ) {
		console.log( HELP );
		return;
	}
	const unknown = Object.keys( opts ).filter(
		( key ) => key !== '_' && ! KNOWN.includes( key )
	);
	if ( unknown.length || opts._.length ) {
		console.error(
			`Unknown option ${ unknown.length ? `--${ unknown[ 0 ] }` : opts._[ 0 ] }. Run npm run dev -- --help for the options.`
		);
		process.exit( 2 );
	}
	for ( const key of [ 'port', 'wp', 'php' ] ) {
		if ( opts[ key ] === true ) {
			console.error(
				`--${ key } needs a value, such as --${ key } ${
					{ port: '9500', wp: '6.9', php: '8.1' }[ key ]
				}.`
			);
			process.exit( 2 );
		}
	}
	if ( opts.port !== undefined && ! /^\d+$/.test( opts.port ) ) {
		console.error( `--port takes a number, not ${ opts.port }.` );
		process.exit( 2 );
	}

	if ( opts.check ) {
		process.exit( await check( opts ) );
	}

	try {
		await serve( opts );
	} catch ( error ) {
		console.error( `Could not start the dev site: ${ error.message }` );
		if ( opts.debug ) {
			console.error( error.stack );
		}
		process.exit( 1 );
	}
}

/**
 * True when this file is the script node was asked to run, not an import.
 *
 * @return {boolean} Whether to run main().
 */
function isMain() {
	if ( ! process.argv[ 1 ] ) {
		return false;
	}
	const self = fileURLToPath( import.meta.url );
	const invoked = resolve( process.argv[ 1 ] );
	return process.platform === 'win32'
		? self.toLowerCase() === invoked.toLowerCase()
		: self === invoked;
}

if ( isMain() ) {
	await main();
}
