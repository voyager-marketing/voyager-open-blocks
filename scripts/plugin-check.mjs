#!/usr/bin/env node
/**
 * Plugin Check on the plugin zip, in a fresh WordPress, the way the plugin
 * directory sees the plugin.
 *
 *   npm run plugin-check                   zip this checkout, then check the zip
 *   npm run plugin-check -- --zip <path>   check a zip that is built already
 *   npm run plugin-check -- --help         every option
 *
 * WordPress Playground boots a fresh WordPress with WP_DEBUG_LOG on, installs
 * Plugin Check from WordPress.org and the zip as a plugin, then:
 *
 * 1. activates the pack, and fails on an activation error or output;
 * 2. requests the home page, a page with every pattern and the icon block,
 *    the Dashboard, the Plugins screen and Settings > Site Data as admin, and
 *    fails on anything but a 200 or on a PHP fatal error;
 * 3. runs `wp plugin check voyager-open-blocks`, runtime checks included, and
 *    fails on any error. Warnings are reported and do not fail;
 * 4. deactivates the pack and runs uninstall.php;
 * 5. fails on any PHP fatal error in debug.log, and on any warning, notice or
 *    deprecation that names the plugin.
 *
 * Every finding names its file, line and code. In GitHub Actions each one is
 * an annotation, and the job summary lists them all.
 *
 * Needs network access to wordpress.org: Playground downloads WordPress,
 * Plugin Check and WP-CLI.
 */
import { spawnSync } from 'node:child_process';
import {
	appendFileSync,
	copyFileSync,
	existsSync,
	mkdtempSync,
	rmSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import { SETTINGS_PATH, parseFlags, phpLog, runPhp } from './dev.mjs';
import { createClient } from './lib/http.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { repo } from './lib/site.mjs';

const SLUG = 'voyager-open-blocks';
const PLUGIN_FILE = `${ SLUG }/${ SLUG }.php`;
const ZIP_DIR = '/voyager-open-blocks-zip';
const WP_CLI = '/tmp/wp-cli.phar';
const PLUGIN_CHECK_CLI = '/wordpress/wp-content/plugins/plugin-check/cli.php';
const OBJECT_CACHE = '/wordpress/wp-content/object-cache.php';
const FIELDS = 'file,line,column,type,code,message,docs';
const TIMEOUT_MINUTES = 20;

const HELP = `npm run plugin-check [-- options]

Boots a fresh WordPress in Playground, installs Plugin Check from WordPress.org
and the plugin zip, activates the pack, requests a few front-end and admin
pages, runs wp plugin check on it, then deactivates and uninstalls it. Fails on
any Plugin Check error and on any PHP error, warning or notice from the plugin.

  --zip <path>     The zip to check. Default: run npm run zip and check
                   voyager-open-blocks.zip.
  --wp <version>   WordPress version, such as 6.9. Default latest.
  --php <version>  PHP version, such as 8.3. Default 8.1, the plugin's minimum.
  --debug          Print Playground's debug output and Plugin Check's raw output.
  --help           Show this help.

Needs network access to wordpress.org.`;

const KNOWN = [ 'zip', 'wp', 'php', 'debug', 'help' ];

/**
 * Build voyager-open-blocks.zip from this checkout, as npm run zip does.
 *
 * @return {string} Path to the zip.
 */
function buildZip() {
	group( 'npm run zip' );
	const result = spawnSync(
		process.execPath,
		[
			join(
				repo,
				'node_modules',
				'@wordpress',
				'scripts',
				'bin',
				'wp-scripts.js'
			),
			'plugin-zip',
		],
		{ cwd: repo, stdio: 'inherit' }
	);
	endGroup();
	if ( result.status !== 0 ) {
		throw new Error( 'npm run zip failed, so there is no zip to check.' );
	}
	return join( repo, `${ SLUG }.zip` );
}

/**
 * Boot Playground with Plugin Check active and the zip installed, not active.
 *
 * @param {Object}  options
 * @param {string}  options.zip   Host path of the plugin zip.
 * @param {string}  options.wp    WordPress version.
 * @param {string}  options.php   PHP version.
 * @param {boolean} options.debug Playground's debug output.
 * @return {Promise<{url: string, playground: Object, stop: () => Promise<void>}>} The running site.
 */
async function boot( { zip, wp, php, debug } ) {
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

	// Mounted rather than read from the checkout, so the plugin under test
	// is exactly what the zip holds.
	const zipDir = mkdtempSync( join( tmpdir(), `${ SLUG }-zip-` ) );
	copyFileSync( zip, join( zipDir, `${ SLUG }.zip` ) );

	const server = await cli.runCLI( {
		command: 'server',
		blueprint: {
			preferredVersions: { php, wp },
			login: false,
			// Downloads WP-CLI to /tmp/wp-cli.phar, which every worker shares.
			extraLibraries: [ 'wp-cli' ],
			steps: [
				{
					step: 'installPlugin',
					pluginData: {
						resource: 'wordpress.org/plugins',
						slug: 'plugin-check',
					},
					options: { activate: true },
				},
				{
					step: 'installPlugin',
					pluginData: {
						resource: 'vfs',
						path: `${ ZIP_DIR }/${ SLUG }.zip`,
					},
					options: { activate: false },
				},
			],
		},
		mount: [ { hostPath: zipDir, vfsPath: ZIP_DIR } ],
		'define-bool': {
			WP_DEBUG: true,
			WP_DEBUG_LOG: true,
			WP_DEBUG_DISPLAY: false,
		},
		verbosity: debug ? 'debug' : 'normal',
	} );

	let stopping;
	return {
		url: server.serverUrl.replace( /\/$/, '' ),
		playground: server.playground,
		stop: () =>
			( stopping ??= server[ Symbol.asyncDispose ]().finally( () =>
				rmSync( zipDir, { recursive: true, force: true } )
			) ),
	};
}

/**
 * Run WP-CLI in the site, with PHP's command-line SAPI.
 *
 * @param {{playground: Object}} site From boot().
 * @param {string[]}             args Arguments after `wp`.
 * @return {Promise<{code: number, stdout: string, stderr: string}>} What it printed and its exit code.
 */
async function wpCli( site, args ) {
	const response = await site.playground.cli( [
		'/internal/shared/bin/php',
		// PHPCS on the whole plugin needs more than Playground's default.
		'-d',
		'memory_limit=1024M',
		WP_CLI,
		'--path=/wordpress',
		'--allow-root',
		...args,
	] );
	const sink = ( chunks ) =>
		new WritableStream( {
			write( chunk ) {
				chunks.push( Buffer.from( chunk ) );
			},
		} );
	const out = [];
	const err = [];
	const [ code ] = await Promise.all( [
		response.exitCode,
		response.stdout.pipeTo( sink( out ) ),
		response.stderr.pipeTo( sink( err ) ),
	] );
	return {
		code,
		stdout: Buffer.concat( out ).toString( 'utf8' ),
		stderr: Buffer.concat( err ).toString( 'utf8' ),
	};
}

/**
 * Versions of what runs, for the report.
 *
 * @param {{playground: Object}} site From boot().
 * @return {Promise<{wp: string, php: string, pluginCheck: string, pack: string}>} Versions.
 */
function versions( site ) {
	return runPhp(
		site,
		`require_once ABSPATH . 'wp-admin/includes/plugin.php';
return [
	'wp'          => get_bloginfo( 'version' ),
	'php'         => PHP_VERSION,
	'pluginCheck' => get_plugin_data( WP_PLUGIN_DIR . '/plugin-check/plugin.php', false, false )['Version'] ?? '',
	'pack'        => file_exists( WP_PLUGIN_DIR . '/${ PLUGIN_FILE }' ) ? get_plugin_data( WP_PLUGIN_DIR . '/${ PLUGIN_FILE }', false, false )['Version'] : '',
];`
	);
}

/**
 * Activate the pack as an admin would.
 *
 * activate_plugin() returns an error for a fatal error and for any output
 * the plugin prints while it loads.
 *
 * @param {{playground: Object}} site From boot().
 * @return {Promise<string>} '' when active, else what went wrong.
 */
async function activate( site ) {
	const result = await runPhp(
		site,
		`require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
$result = activate_plugin( '${ PLUGIN_FILE }' );
if ( is_wp_error( $result ) ) {
	$data = $result->get_error_data();
	return $result->get_error_code() . ': ' . wp_strip_all_tags( $result->get_error_message() ) . ( is_string( $data ) && '' !== $data ? ' Output: ' . $data : '' );
}
return is_plugin_active( '${ PLUGIN_FILE }' ) ? '' : 'activate_plugin() returned no error, and the plugin is not active.';`
	);
	return String( result ?? '' );
}

/**
 * Publish a page holding every pattern the pack registers and an icon block,
 * so the front-end request renders the blocks, bindings and icon markup.
 *
 * @param {{playground: Object}} site From boot().
 * @return {Promise<{url: string, patterns: string[]}>} The page URL and the patterns on it.
 */
async function seedPage( site ) {
	const result = await runPhp(
		site,
		`wp_set_current_user( 1 );
$patterns = [];
foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
	if ( str_starts_with( $pattern['name'], 'voyager-open/' ) ) {
		$patterns[] = $pattern['name'];
	}
}
$blocks = array_map(
	static fn ( $name ) => '<!-- wp:pattern ' . wp_json_encode( [ 'slug' => $name ] ) . ' /-->',
	$patterns
);
$blocks[] = '<!-- wp:voyager-open/icon {"icon":"phone","linkUrl":"https://example.com/","label":"Call us"} /-->';
$id = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Plugin Check',
		'post_content' => implode( "\\n\\n", $blocks ),
	],
	true
);
if ( is_wp_error( $id ) ) {
	return [ 'error' => $id->get_error_message() ];
}
return [ 'url' => get_permalink( $id ), 'patterns' => $patterns ];`
	);
	if ( ! result?.url ) {
		throw new Error(
			`Could not publish the test page: ${ result?.error || 'no URL returned' }`
		);
	}
	return result;
}

/**
 * Request the front end, then log in and request the admin screens the pack
 * adds to or changes.
 *
 * @param {{url: string}} site    From boot().
 * @param {string}        pageUrl From seedPage().
 * @return {Promise<{passed: string[], failed: string[]}>} What passed and what failed.
 */
async function requestPages( site, pageUrl ) {
	const client = createClient( site.url );
	const passed = [];
	const failed = [];

	// A fresh Playground server answers its first request with a redirect.
	await client.get( '/' );

	const request = async ( path, label, admin = false ) => {
		const res = await client.get( path );
		const at = new URL( res.url ).pathname;
		if ( admin && at.endsWith( '/wp-login.php' ) ) {
			failed.push(
				`${ label } (${ path }) sent the request to the login screen, so the admin login did not hold.`
			);
		} else if ( res.status !== 200 ) {
			failed.push( `${ label } (${ path }) answers ${ res.status }.` );
		} else if (
			/There has been a critical error|<b>(Fatal|Parse) error<\/b>/.test(
				res.text
			)
		) {
			failed.push(
				`${ label } (${ path }) answers 200 with a PHP fatal error in the page.`
			);
		} else {
			passed.push( `${ label } (${ path }) answers 200` );
		}
	};

	await request( '/', 'The home page' );
	await request( pageUrl, 'A page with every pattern and the icon block' );

	await client.get( '/wp-login.php' );
	await client.post( '/wp-login.php', {
		log: 'admin',
		pwd: 'password',
		'wp-submit': 'Log In',
		testcookie: '1',
		redirect_to: `${ site.url }/wp-admin/`,
	} );
	await request( '/wp-admin/', 'The Dashboard', true );
	await request( '/wp-admin/plugins.php', 'The Plugins screen', true );
	await request( SETTINGS_PATH, 'Settings > Site Data', true );

	return { passed, failed };
}

/**
 * Plugin Check's findings from its strict-json output.
 *
 * @param {string} text What wp plugin check printed.
 * @return {Object[]|null} Findings, [] for none, null when the output holds no result.
 */
function parseFindings( text ) {
	if ( /Checks complete\. No errors found\./.test( text ) ) {
		return [];
	}
	const line = text
		.split( /\r?\n/ )
		.map( ( l ) => l.trim() )
		.find( ( l ) => l.startsWith( '[' ) );
	if ( ! line ) {
		return null;
	}
	try {
		const items = JSON.parse( line );
		return Array.isArray( items ) ? items : null;
	} catch {
		return null;
	}
}

/**
 * Plain text from a Plugin Check message, which may hold HTML.
 *
 * @param {unknown} html
 * @return {string} Text.
 */
function plain( html ) {
	return String( html ?? '' )
		.replace( /<[^>]+>/g, '' )
		.replace( /&quot;/g, '"' )
		.replace( /&#0?39;/g, "'" )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Run wp plugin check on the installed pack.
 *
 * Runtime checks need Plugin Check's cli.php loaded before WordPress. If
 * that run gives no result, the static checks run alone and a warning says
 * so.
 *
 * @param {{playground: Object}} site  From boot().
 * @param {boolean}              debug Print the raw output.
 * @return {Promise<{findings: Object[]|null, runtime: boolean, raw: string}>} Findings, whether runtime checks ran, and the raw output.
 */
async function pluginCheck( site, debug ) {
	const args = [
		'plugin',
		'check',
		SLUG,
		'--format=strict-json',
		`--fields=${ FIELDS }`,
	];
	let raw = '';
	for ( const runtime of [ true, false ] ) {
		const run = await wpCli( site, [
			...( runtime ? [ `--require=${ PLUGIN_CHECK_CLI }` ] : [] ),
			...args,
		] );
		raw = `exit ${ run.code }\n--- stdout\n${ run.stdout }\n--- stderr\n${ run.stderr }`;
		if ( debug ) {
			console.log( raw );
		}
		const findings =
			run.code === 0 ? parseFindings( run.stdout + run.stderr ) : null;
		if ( findings ) {
			return { findings, runtime, raw };
		}
		annotate(
			'warning',
			`wp plugin check${
				runtime ? ' with runtime checks' : ''
			} gave no result. ${ raw.slice( -1500 ) }`,
			'Plugin Check did not run'
		);
		// A failed runtime run can leave Plugin Check's drop-in behind.
		try {
			if ( await site.playground.fileExists( OBJECT_CACHE ) ) {
				await site.playground.unlink( OBJECT_CACHE );
			}
		} catch {
			// Nothing to remove.
		}
	}
	return { findings: null, runtime: false, raw };
}

/**
 * Deactivate the pack and run its uninstall.php, as deleting it from the
 * Plugins screen does (without deleting the files).
 *
 * @param {{playground: Object}} site From boot().
 * @return {Promise<string>} '' when uninstall.php ran, else what went wrong.
 */
async function uninstall( site ) {
	const result = await runPhp(
		site,
		`require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
deactivate_plugins( '${ PLUGIN_FILE }' );
if ( is_plugin_active( '${ PLUGIN_FILE }' ) ) {
	return 'The plugin is still active after deactivate_plugins().';
}
if ( ! is_uninstallable_plugin( '${ PLUGIN_FILE }' ) ) {
	return 'WordPress does not see uninstall.php, so nothing runs on uninstall.';
}
ob_start();
uninstall_plugin( '${ PLUGIN_FILE }' );
$output = (string) ob_get_clean();
return '' === trim( $output ) ? '' : 'uninstall.php printed output: ' . $output;`
	);
	return String( result ?? '' );
}

/**
 * Where a debug.log line points in this plugin, as a repo-relative path.
 *
 * @param {string} line
 * @return {{file?: string, line?: number}} The file and line, or nothing.
 */
function logLocation( line ) {
	const match = new RegExp(
		`/plugins/${ SLUG }/([^\\s:]+\\.php)(?: on line |:)(\\d+)`
	).exec( line );
	return match ? { file: match[ 1 ], line: Number( match[ 2 ] ) } : {};
}

/**
 * PHP errors in debug.log since `from`: every warning, notice, deprecation
 * or fatal error that names this plugin, and with `anyFatal`, every fatal
 * error. Plugin Check's own run is read without `anyFatal`, so a fault in
 * Plugin Check is not blamed on the plugin.
 *
 * @param {string[]|null} lines    From phpLog().
 * @param {number}        from     Lines to skip.
 * @param {string}        during   What ran, for the report.
 * @param {boolean}       anyFatal Count fatal errors that do not name the plugin.
 * @return {number} How many there were.
 */
function reportLog( lines, from, during, anyFatal ) {
	if ( lines === null ) {
		annotate(
			'error',
			`Could not read wp-content/debug.log, so PHP errors ${ during } were not checked.`,
			'PHP errors'
		);
		return 1;
	}
	const fresh = lines.slice( from );
	const fatal = ( line ) => /PHP (Fatal|Parse) error/.test( line );
	const ours = ( line ) =>
		/voyager-open-blocks|VoyagerOpenBlocks|voyager-open\//.test( line );
	const bad = fresh.filter(
		( line ) =>
			( fatal( line ) && ( anyFatal || ours( line ) ) ) ||
			( /PHP (Warning|Notice|Deprecated)/.test( line ) && ours( line ) )
	);
	for ( const line of bad ) {
		annotate(
			'error',
			line,
			`PHP ${ fatal( line ) ? 'fatal error' : 'notice' } ${ during }`,
			logLocation( line )
		);
	}
	if ( ! bad.length ) {
		console.log(
			`ok   no PHP errors, warnings or notices from the plugin ${ during } (${ fresh.length } new lines in debug.log)`
		);
	}
	return bad.length;
}

/**
 * Report Plugin Check's findings: one annotation each, a notice that lists
 * them all (Actions shows ten annotations of a kind per step), and a table
 * in the job summary.
 *
 * @param {Object[]} findings From parseFindings().
 * @param {Object}   about    Versions and whether runtime checks ran.
 * @return {{errors: number, warnings: number}} Counts.
 */
function reportFindings( findings, about ) {
	const items = findings.map( ( item ) => ( {
		type: String( item.type || '' ).startsWith( 'ERROR' )
			? 'ERROR'
			: 'WARNING',
		file: String( item.file || '' )
			.replace( new RegExp( `^.*/plugins/${ SLUG }/` ), '' )
			.replace( /^\/+/, '' ),
		line: Number( item.line ) || undefined,
		col: Number( item.column ) || undefined,
		code: String( item.code || '' ),
		message: plain( item.message ),
		docs: String( item.docs || '' ),
	} ) );
	const errors = items.filter( ( item ) => item.type === 'ERROR' ).length;
	const warnings = items.length - errors;

	for ( const item of items ) {
		annotate(
			item.type === 'ERROR' ? 'error' : 'warning',
			`${ item.code }: ${ item.message }${
				item.docs ? ` (${ item.docs })` : ''
			}`,
			`Plugin Check ${ item.type.toLowerCase() }: ${ item.code }`,
			item.file ? { file: item.file, line: item.line, col: item.col } : {}
		);
	}

	const heading = `Plugin Check ${ about.pluginCheck } on ${ SLUG } ${ about.pack } (WordPress ${ about.wp }, PHP ${ about.php }${
		about.runtime ? '' : ', static checks only'
	}): ${ errors } errors, ${ warnings } warnings.`;
	annotate(
		errors ? 'error' : 'notice',
		[
			heading,
			...items.map(
				( item ) =>
					`${ item.type } ${ item.file || '(plugin)' }${
						item.line ? `:${ item.line }` : ''
					} ${ item.code }: ${ item.message }`
			),
		].join( '\n' ),
		'Plugin Check results'
	);

	if ( process.env.GITHUB_STEP_SUMMARY ) {
		const cell = ( text ) =>
			String( text ).replace( /\|/g, '\\|' ).replace( /\n/g, ' ' );
		appendFileSync(
			process.env.GITHUB_STEP_SUMMARY,
			[
				'### Plugin Check',
				'',
				heading,
				'',
				...( items.length
					? [
							'| Type | File | Line | Code | Message |',
							'| --- | --- | --- | --- | --- |',
							...items.map(
								( item ) =>
									`| ${ item.type } | ${ cell(
										item.file
									) } | ${ item.line || '' } | ${ cell(
										item.code
									) } | ${ cell( item.message ) } |`
							),
						]
					: [] ),
				'',
			].join( '\n' )
		);
	}

	return { errors, warnings };
}

/**
 * Boot, check, always stop.
 *
 * @param {Object} opts Parsed flags.
 * @return {Promise<number>} Exit code.
 */
async function check( opts ) {
	setTimeout( () => {
		annotate(
			'error',
			`npm run plugin-check gave up after ${ TIMEOUT_MINUTES } minutes.`,
			'npm run plugin-check'
		);
		process.exit( 1 );
	}, TIMEOUT_MINUTES * 60000 ).unref();

	const zip = opts.zip ? resolve( String( opts.zip ) ) : buildZip();
	if ( ! existsSync( zip ) ) {
		annotate(
			'error',
			`There is no zip at ${ zip }. Run npm run zip, or pass --zip <path>.`,
			'npm run plugin-check'
		);
		return 1;
	}

	let site;
	let failures = 0;
	try {
		group(
			'Boot WordPress Playground with Plugin Check and the zip installed'
		);
		try {
			site = await boot( {
				zip,
				wp: String( opts.wp || 'latest' ),
				php: String( opts.php || '8.1' ),
				debug: Boolean( opts.debug ),
			} );
		} finally {
			endGroup();
		}
		const about = await versions( site );
		if ( ! about.pack ) {
			annotate(
				'error',
				`The zip did not install to wp-content/plugins/${ SLUG }/. Its top folder must be ${ SLUG }.`,
				'Install the zip'
			);
			return 1;
		}
		annotate(
			'notice',
			`WordPress ${ about.wp }, PHP ${ about.php }, Plugin Check ${ about.pluginCheck }, ${ SLUG } ${ about.pack } from ${ zip }.`,
			'Plugin Check site booted'
		);

		// Each stage reads only the debug.log lines written since the last.
		let logged = ( await phpLog( site ) )?.length ?? 0;
		const readLog = async ( during, anyFatal ) => {
			const lines = await phpLog( site );
			failures += reportLog( lines, logged, during, anyFatal );
			logged = lines?.length ?? logged;
		};

		const activation = await activate( site );
		if ( activation ) {
			annotate( 'error', activation, 'Activate the pack', {
				file: `${ SLUG }.php`,
			} );
			failures++;
		} else {
			console.log(
				'ok   the pack activates with no error and no output'
			);

			const page = await seedPage( site );
			const pages = await requestPages( site, page.url );
			for ( const message of pages.passed ) {
				console.log( `ok   ${ message }` );
			}
			for ( const message of pages.failed ) {
				annotate( 'error', message, 'Front-end and admin requests' );
			}
			failures += pages.failed.length;
		}
		await readLog( 'during activation and the page requests', true );

		group( 'wp plugin check voyager-open-blocks' );
		let result;
		try {
			result = await pluginCheck( site, Boolean( opts.debug ) );
		} finally {
			endGroup();
		}
		if ( result.findings === null ) {
			annotate(
				'error',
				`wp plugin check gave no result. ${ result.raw.slice(
					-3000
				) }`,
				'Plugin Check did not run'
			);
			failures++;
		} else {
			const counts = reportFindings( result.findings, {
				...about,
				runtime: result.runtime,
			} );
			failures += counts.errors;
		}
		await readLog( 'during wp plugin check', false );

		if ( ! activation ) {
			const removal = await uninstall( site );
			if ( removal ) {
				annotate( 'error', removal, 'Deactivate and uninstall', {
					file: 'uninstall.php',
				} );
				failures++;
			} else {
				console.log(
					'ok   the pack deactivates and uninstall.php runs'
				);
			}
			await readLog( 'during deactivation and uninstall', true );
		}
	} catch ( error ) {
		annotate(
			'error',
			`${ error.message }${
				error.cause ? ` (${ error.cause.message || error.cause })` : ''
			}`,
			'npm run plugin-check'
		);
		if ( opts.debug ) {
			console.error( error.stack );
		}
		failures++;
	} finally {
		if ( site ) {
			await site.stop();
		}
	}
	return failures ? 1 : 0;
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
			`Unknown option ${
				unknown.length ? `--${ unknown[ 0 ] }` : opts._[ 0 ]
			}. Run npm run plugin-check -- --help for the options.`
		);
		process.exit( 2 );
	}
	for ( const key of [ 'zip', 'wp', 'php' ] ) {
		if ( opts[ key ] === true ) {
			console.error(
				`--${ key } needs a value, such as --${ key } ${
					{ zip: 'voyager-open-blocks.zip', wp: '6.9', php: '8.3' }[
						key
					]
				}.`
			);
			process.exit( 2 );
		}
	}

	process.exit( await check( opts ) );
}

await main();
