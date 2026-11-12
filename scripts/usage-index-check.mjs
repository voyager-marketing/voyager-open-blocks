#!/usr/bin/env node
/**
 * The usage index's two whole-site paths, on a real WordPress: the backfill
 * that activation starts, and `wp voyager-open-blocks reindex`.
 *
 *   npm run check:usage-index
 *   npm run check:usage-index -- --plant activation
 *   npm run check:usage-index -- --plant reindex
 *   npm run check:usage-index -- --plant crash
 *   npm run check:usage-index -- --help
 *
 * blueprints/dev.json activates the plugin before it seeds the demo pages, so
 * every page there is indexed on save, and no other check runs either path.
 * This one boots the dev site (boot() in scripts/dev.mjs) with WP-Cron off,
 * adds 60 pages bound to the phone, and records what the index holds for
 * every post after those saves. Then, for each path, it deletes the whole
 * index and checks that the path puts every row back, and nothing else:
 *
 * 1. reindex: `wp voyager-open-blocks reindex`, through WP-CLI. The site has
 *    more than one batch of 50 posts.
 * 2. activation: the plugin is deactivated, a page bound to the email is
 *    added while it is off, and the plugin is activated again. The backfill
 *    event that activation schedules runs as WP-Cron would, until it
 *    schedules no more. Here activation is the only thing that can start it:
 *    the plugin version the last backfill ran for has not changed, so the
 *    check on init starts nothing.
 * 3. order: the "Shown on" line's query (shown_on() in
 *    inc/freshness/usage.php) lists pages in the database's title order, so
 *    "Usage check 10" comes before "Usage check 2".
 *
 * --plant puts a fault in through an mu-plugin, and the run passes only when
 * the check fails that path and passes the others: `activation` takes the
 * backfill off the activation hook, and `reindex` replaces the command with
 * one that reports success and indexes nothing. CI runs all three
 * (.github/workflows/e2e.yml). `crash` makes the harness throw before it boots
 * the site, and the run must exit 2: a crash is never a pass
 * (scripts/test-usage-index-check.mjs, which npm run check runs).
 *
 * Exit codes: 0 every path passed (with --plant, the planted fault was
 * caught), 1 a path did not do what it should, 2 the harness could not tell,
 * which a crash anywhere is, whatever the paths measured before it.
 */
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { boot, phpLog, reportPhpLog, runPhp } from './dev.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { flags } from './lib/site.mjs';

const TIMEOUT_MINUTES = 15;
const PLUGIN = 'voyager-open-blocks/voyager-open-blocks.php';
const PROBES = 60;
const USAGE = '\\VoyagerOpenBlocks\\Usage\\';

/** Options the harness keeps its facts in, inside the throwaway site. */
const FACTS = 'voyager_open_usage_check';
const PLANTED = 'voyager_open_usage_check_planted';

/** The post meta key the "Usage check" pages bind. */
const ORDER_KEY = 'voyager_open_usage_check_order';

const MU_PLUGIN =
	'/wordpress/wp-content/mu-plugins/voyager-open-usage-check-plant.php';

/** Where Playground's wp-cli step puts WP-CLI, and our runner for it. */
const WP_CLI = '/tmp/wp-cli.phar';
const RUN_CLI = '/tmp/voyager-open-usage-check-cli.php';

const PLANTS = {
	activation: `<?php
// Planted by scripts/usage-index-check.mjs --plant activation: takes the
// usage backfill off the plugin's activation hook.
add_action(
	'activate_plugin',
	static function ( $plugin ) {
		if ( '${ PLUGIN }' === $plugin ) {
			$removed = remove_action( 'activate_' . $plugin, 'VoyagerOpenBlocks\\Usage\\start_backfill' );
			update_option( '${ PLANTED }', $removed ? 'activation' : 'start_backfill was not on the activation hook', false );
		}
	}
);
`,
	reindex: `<?php
// Planted by scripts/usage-index-check.mjs --plant reindex: replaces the
// reindex command with one that reports success and indexes nothing.
add_action(
	'cli_init',
	static function () {
		WP_CLI::add_command(
			'voyager-open-blocks reindex',
			static function () {
				WP_CLI::success( 'Indexed 0 posts.' );
			}
		);
		update_option( '${ PLANTED }', 'reindex', false );
	},
	20
);
`,
};

/**
 * Every --plant: a fault the mu-plugin puts in a path, or crash, which makes
 * the harness throw before it boots the site, as a crash anywhere would, so
 * the run must exit 2.
 */
const PLANT_NAMES = [ ...Object.keys( PLANTS ), 'crash' ];

const HELP = `npm run check:usage-index [-- options]

Checks that the usage index's backfill on activation and
\`wp voyager-open-blocks reindex\` each rebuild the whole index, on a fresh
Playground site.

  --plant <fault>  Plant a fault and pass only when the check catches it:
                   activation (no backfill on activation) or reindex (a
                   reindex command that indexes nothing). crash makes the
                   harness throw before it boots the site, and the run must
                   exit 2.
  --port <n>       Port to serve on. Default 9400, or a free one.
  --wp <version>   WordPress version. Default latest.
  --php <version>  PHP version. Default 8.3.
  --debug          Playground's debug output, and stack traces.
  --help           Show this help.

Exit codes: 0 pass (with --plant: the fault was caught), 1 fail, 2 the
harness could not tell, or a usage error.`;

/**
 * A PHP single-quoted string literal.
 *
 * @param {string} value
 * @return {string} The literal.
 */
function phpString( value ) {
	return `'${ String( value ).replace( /[\\']/g, '\\$&' ) }'`;
}

/**
 * A paragraph bound to one field.
 *
 * @param {string} source Binding source.
 * @param {string} key    Binding key.
 * @param {string} text   The paragraph's own text.
 * @return {string} Block markup.
 */
function bound( source, key, text ) {
	return `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"${ source }","args":{"key":"${ key }"}}}}} -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`;
}

/**
 * PHP: a published page.
 *
 * @param {string} title   Title, as PHP.
 * @param {string} content Block markup.
 * @return {string} An expression giving the new page's ID.
 */
function page( title, content ) {
	return `(int) wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ${ title }, 'post_content' => ${ phpString(
		content
	) } ] ) )`;
}

/**
 * PHP: `$index()` reads every post's rows, and `$compare( $expected )` lists
 * how the index differs from them. Needs the plugin loaded.
 */
const COMPARE = `global $wpdb;
$index   = static function (): array {
	global $wpdb;
	$out = [];
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", ${ USAGE }META, ${ USAGE }REFS ) ) as $id ) {
		$out[ (int) $id ] = [
			'uses' => ${ USAGE }stored( (int) $id, ${ USAGE }META ),
			'refs' => ${ USAGE }stored( (int) $id, ${ USAGE }REFS ),
		];
	}
	return $out;
};
$compare = static function ( array $expected, array $theme ) use ( $index ): array {
	$now      = $index();
	$problems = [];
	foreach ( $expected as $id => $rows ) {
		$got = $now[ (int) $id ] ?? [ 'uses' => [], 'refs' => [] ];
		if ( $got !== $rows ) {
			$problems[] = sprintf( 'post %d has %s, expected %s', $id, wp_json_encode( $got['uses'], JSON_UNESCAPED_SLASHES ), wp_json_encode( $rows['uses'], JSON_UNESCAPED_SLASHES ) );
		}
	}
	foreach ( array_diff_key( $now, $expected ) as $id => $rows ) {
		$problems[] = sprintf( 'post %d has %s, expected no rows', $id, wp_json_encode( $rows['uses'], JSON_UNESCAPED_SLASHES ) );
	}
	if ( ${ USAGE }theme_index() !== $theme ) {
		$problems[] = 'the theme index differs';
	}
	return [
		'posts'    => count( $expected ),
		'missing'  => count( $problems ),
		'problems' => array_slice( $problems, 0, 4 ),
	];
};`;

/**
 * PHP: add the probes and the order pages, record the index as the saves
 * left it, then delete it.
 */
const SEED = `${ COMPARE }
wp_set_current_user( 1 );
$probes = [];
for ( $i = 1; $i <= ${ PROBES }; $i++ ) {
	$probes[] = ${ page(
		"'Usage probe ' . $i",
		bound( 'voyager/site-data', 'phone', 'Call us' )
	) };
}
foreach ( [ 'Usage check 2', 'Usage check 10' ] as $title ) {
	${ page( '$title', bound( 'voyager/post-meta-text', ORDER_KEY, 'Price' ) ) };
}
$shown = ${ USAGE }shown_on( [ 'voyager/post-meta-text:${ ORDER_KEY }' ] );
$facts = [
	'probed'   => count( array_filter( $probes, static fn ( $id ) => [ 'voyager/site-data:phone' ] === ${ USAGE }uses( $id ) ) ),
	'order'    => array_map( static fn ( $post ) => $post->post_title, $shown['posts'] ),
	'expected' => $index(),
	'theme'    => ${ USAGE }theme_index(),
];
update_option( '${ FACTS }', $facts, false );
delete_post_meta_by_key( ${ USAGE }META );
delete_post_meta_by_key( ${ USAGE }REFS );
delete_option( ${ USAGE }THEME_OPTION );
return [
	'probed'  => $facts['probed'],
	'order'   => $facts['order'],
	'posts'   => count( $facts['expected'] ),
	'left'    => count( $index() ),
	'planted' => get_option( '${ PLANTED }', null ),
];`;

/**
 * PHP: the index against the snapshot.
 *
 * @param {Object<number, string[]>} [extra] More posts to expect, ID => rows.
 * @return {string} PHP returning what $compare() found.
 */
function compared( extra = {} ) {
	const more = Object.entries( extra )
		.map(
			( [ id, uses ] ) =>
				`$expected[ ${ Number(
					id
				) } ] = [ 'uses' => json_decode( ${ phpString(
					JSON.stringify( uses )
				) }, true ), 'refs' => [] ];`
		)
		.join( '\n' );
	return `${ COMPARE }
$facts    = get_option( '${ FACTS }' );
$expected = $facts['expected'];
${ more }
return $compare( $expected, $facts['theme'] );`;
}

/** PHP: deactivate the plugin, and note what the init check will see. */
const DEACTIVATE = `require_once ABSPATH . 'wp-admin/includes/plugin.php';
$version = (string) get_option( 'voyager_open_blocks_usage_version' );
deactivate_plugins( '${ PLUGIN }' );
return [ 'active' => is_plugin_active( '${ PLUGIN }' ), 'version' => $version ];`;

/**
 * PHP, with the plugin off: delete the index, clear its events so only a new
 * one can run, and add a page that only a backfill can index.
 */
const WHILE_OFF = `global $wpdb;
wp_set_current_user( 1 );
delete_post_meta_by_key( '_voyager_open_blocks_uses' );
delete_post_meta_by_key( '_voyager_open_blocks_refs' );
delete_option( 'voyager_open_blocks_theme_uses' );
delete_option( 'voyager_open_blocks_usage_cursor' );
wp_clear_scheduled_hook( 'voyager_open_blocks_usage_backfill' );
wp_clear_scheduled_hook( 'voyager_open_blocks_usage_queue' );
$id = ${ page(
	"'Added while off'",
	bound( 'voyager/site-data', 'email', 'Email us' )
) };
return [
	'loaded' => function_exists( ${ phpString( `${ USAGE }index_post` ) } ),
	'rows'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", '_voyager_open_blocks_uses', '_voyager_open_blocks_refs' ) ),
	'post'   => $id,
];`;

/** PHP: activate the plugin again. */
const ACTIVATE = `require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( '${ PLUGIN }' );
return [
	'error'     => is_wp_error( $result ) ? $result->get_error_message() : null,
	'scheduled' => false !== wp_next_scheduled( 'voyager_open_blocks_usage_backfill' ),
	'planted'   => get_option( '${ PLANTED }', null ),
];`;

/** PHP: the backfill event, run as WP-Cron would, until none is scheduled. */
const RUN_BACKFILL = `$hook = ${ USAGE }BACKFILL_HOOK;
$runs = 0;
while ( $runs < 20 && false !== ( $at = wp_next_scheduled( $hook ) ) ) {
	wp_unschedule_event( $at, $hook );
	do_action( $hook );
	++$runs;
}
return [ 'runs' => $runs, 'left' => false !== wp_next_scheduled( $hook ) ];`;

/**
 * Run a WP-CLI command in the site, the way Playground's wp-cli step does.
 *
 * @param {{playground: Object}} site From boot().
 * @param {string[]}             args The command's words after `wp`.
 * @return {Promise<{code: number, out: string}>} Exit code and output.
 */
async function wpCli( site, args ) {
	await site.playground.writeFile(
		RUN_CLI,
		`<?php
putenv( 'SHELL_PIPE=0' );
$GLOBALS['argv'] = array_merge( [ ${ phpString(
			WP_CLI
		) }, '--path=/wordpress', '--no-color' ], json_decode( ${ phpString(
			JSON.stringify( args )
		) }, true ) );
define( 'STDIN', fopen( 'php://memory', 'rb' ) );
define( 'STDOUT', fopen( 'php://stdout', 'wb' ) );
define( 'STDERR', fopen( 'php://stdout', 'wb' ) );
require ${ phpString( WP_CLI ) };`
	);
	try {
		const result = await site.playground.run( { scriptPath: RUN_CLI } );
		return {
			code: result.exitCode,
			out: `${ result.text }${ result.errors || '' }`.trim(),
		};
	} catch ( error ) {
		return { code: 1, out: error.message };
	}
}

/** A failure the harness could not measure past. */
class Unmeasured extends Error {}

/**
 * @param {boolean} condition
 * @param {string}  message   Why the harness cannot tell, when it is false.
 */
function need( condition, message ) {
	if ( ! condition ) {
		throw new Unmeasured( message );
	}
}

/**
 * @param {{missing: number, posts: number, problems: string[]}} found From compared().
 * @return {{status: string, detail: string}} The path's verdict.
 */
function verdict( found ) {
	return found.missing === 0
		? {
				status: 'pass',
				detail: `all ${ found.posts } posts with rows have them back`,
			}
		: {
				status: 'fail',
				detail: `${ found.missing } differences: ${ found.problems.join(
					'; '
				) }`,
			};
}

/**
 * Run every path.
 *
 * @param {Object}                                           opts    Parsed flags.
 * @param {Object<string, {status: string, detail: string}>} results Path => verdict, filled in as each runs.
 */
async function measure( opts, results ) {
	let site;
	if ( opts.plant === 'crash' ) {
		throw new Error( 'planted crash (--plant crash)' );
	}

	try {
		group( 'Boot the dev site' );
		try {
			site = await boot( {
				port: opts.port,
				wp: opts.wp,
				php: opts.php,
				debug: opts.debug,
				login: false,
				consts: {
					DISABLE_WP_CRON: true,
					AUTOMATIC_UPDATER_DISABLED: true,
					WP_HTTP_BLOCK_EXTERNAL: true,
				},
				// The wp-cli step makes Playground fetch WP-CLI, and shows it runs.
				steps: [
					...( PLANTS[ opts.plant ]
						? [
								{
									step: 'mkdir',
									path: '/wordpress/wp-content/mu-plugins',
								},
								{
									step: 'writeFile',
									path: MU_PLUGIN,
									data: PLANTS[ opts.plant ],
								},
							]
						: [] ),
					{ step: 'wp-cli', command: 'wp cli version' },
				],
			} );
		} finally {
			endGroup();
		}
		console.log( `ok   booted ${ site.url }` );

		const seeded = await runPhp( site, SEED );
		need(
			seeded?.probed === PROBES,
			`On save, the index recorded ${ seeded?.probed } of the ${ PROBES } probe pages, so it cannot be what the paths are checked against.`
		);
		need(
			seeded.left === 0,
			`${ seeded.left } posts kept rows after the index was deleted.`
		);
		console.log(
			`ok   ${ seeded.posts } posts had rows after their saves; deleted them all`
		);
		results.order =
			JSON.stringify( seeded.order ) ===
			JSON.stringify( [ 'Usage check 10', 'Usage check 2' ] )
				? {
						status: 'pass',
						detail: 'database title order, "Usage check 10" first',
					}
				: {
						status: 'fail',
						detail: `listed ${ JSON.stringify( seeded.order ) }`,
					};

		const cli = await wpCli( site, [ 'voyager-open-blocks', 'reindex' ] );
		console.log(
			`     wp voyager-open-blocks reindex exited ${ cli.code }: ${ cli.out
				.split( '\n' )
				.slice( -2 )
				.join( ' ' ) }`
		);
		const planted = await runPhp(
			site,
			`return get_option( '${ PLANTED }', null );`
		);
		need(
			opts.plant !== 'reindex' || planted === 'reindex',
			`The reindex plant did not take: ${ planted }.`
		);
		const reindexed = verdict( await runPhp( site, compared() ) );
		results.reindex =
			cli.code === 0
				? reindexed
				: {
						status: 'fail',
						detail: `the command exited ${ cli.code }: ${ cli.out.slice(
							-300
						) }; ${ reindexed.detail }`,
					};

		const off = await runPhp( site, DEACTIVATE );
		need( off?.active === false, 'The plugin is still active.' );
		need(
			off.version !== '',
			'No backfill version is stored, so the check on init would start a backfill too.'
		);
		const cleared = await runPhp( site, WHILE_OFF );
		need( cleared?.loaded === false, 'The plugin loaded while inactive.' );
		need(
			cleared.rows === 0 && cleared.post > 0,
			`With the plugin off: ${ JSON.stringify( cleared ) }.`
		);
		const on = await runPhp( site, ACTIVATE );
		need( on?.error === null, `Activation failed: ${ on?.error }` );
		need(
			opts.plant !== 'activation' || on.planted === 'activation',
			`The activation plant did not take: ${ on.planted }.`
		);
		const ran = await runPhp( site, RUN_BACKFILL );
		need(
			ran?.left === false,
			'The backfill was still scheduled after 20 runs.'
		);
		const backfilled = verdict(
			await runPhp(
				site,
				compared( { [ cleared.post ]: [ 'voyager/site-data:email' ] } )
			)
		);
		results.activation = {
			...backfilled,
			detail: `activation scheduled ${
				on.scheduled ? 'a' : 'no'
			} backfill, which ran ${ ran.runs } times; ${ backfilled.detail }`,
		};
	} finally {
		if ( site ) {
			if ( reportPhpLog( await phpLog( site ) ) ) {
				for ( const result of Object.values( results ) ) {
					if ( result.status !== 'unmeasured' ) {
						result.detail = `a PHP fatal error makes this untrustworthy: ${ result.detail }`;
						result.status = 'unmeasured';
					}
				}
			}
			await site.stop();
		}
	}
}

/**
 * Print each path's verdict, and decide the exit code.
 *
 * @param {Object<string, {status: string, detail: string}>} results From measure().
 * @param {string|null}                                      plant
 * @return {number} 0, 1 or 2.
 */
export function report( results, plant ) {
	let wrong = 0;
	let unmeasured = 0;
	for ( const [ path, result ] of Object.entries( results ) ) {
		const expected = path === plant ? 'fail' : 'pass';
		const line = `${ path }: ${ result.status }${
			plant ? `, expected ${ expected } with ${ plant } planted` : ''
		}${ result.detail ? ` (${ result.detail })` : '' }`;
		if ( result.status === expected ) {
			console.log( `ok   ${ line }` );
			continue;
		}
		annotate( 'error', line, `usage index ${ path }` );
		if ( result.status === 'unmeasured' ) {
			unmeasured++;
		} else {
			wrong++;
		}
	}
	if ( wrong ) {
		return 1;
	}
	return unmeasured ? 2 : 0;
}

/**
 * The whole run, as an exit code. It never throws, and a crash never passes:
 * a crash in measure() keeps what the paths measured before it and exits 2
 * where they would otherwise give 0, and a crash anywhere else exits 2.
 * Node's own code for an uncaught exception is 1, so the entry point below
 * catches those too. scripts/test-usage-index-check.mjs plants crashes in
 * each step to show it.
 *
 * @param {string[]}                                         argv            Arguments after the script name.
 * @param {Object}                                           [steps]         Stand-ins for the steps, for the known-answer test.
 * @param {(opts: Object, results: Object) => Promise<void>} [steps.measure] Boots the site and fills in results. Default measure().
 * @param {(results: Object, plant: string|null) => number}  [steps.report]  Prints the verdicts. Default report().
 * @return {Promise<number>} 0 pass (with --plant: the fault was caught), 1 fail, 2 the harness could not tell.
 */
export async function run( argv, steps = {} ) {
	const { measure: doMeasure = measure, report: doReport = report } = steps;
	try {
		const problem = nodeVersionProblem();
		if ( problem ) {
			console.error( problem );
			return 2;
		}
		const opts = flags( argv );
		if ( opts.help ) {
			console.log( HELP );
			return 0;
		}
		const known = [ '_', 'plant', 'port', 'wp', 'php', 'debug' ];
		const unknown = Object.keys( opts ).find(
			( key ) => ! known.includes( key )
		);
		if ( unknown || opts._.length ) {
			console.error(
				`Unknown option ${
					unknown ? `--${ unknown }` : opts._[ 0 ]
				}. Run npm run check:usage-index -- --help for the options.`
			);
			return 2;
		}
		if (
			opts.plant !== undefined &&
			! PLANT_NAMES.includes( opts.plant )
		) {
			console.error( `--plant takes ${ PLANT_NAMES.join( ', ' ) }.` );
			return 2;
		}
		opts.plant = opts.plant ?? null;

		const results = {
			reindex: { status: 'unmeasured', detail: '' },
			activation: { status: 'unmeasured', detail: '' },
			order: { status: 'unmeasured', detail: '' },
		};
		try {
			await doMeasure( opts, results );
		} catch ( error ) {
			annotate(
				'error',
				`The harness could not tell: ${ error?.message || error }`,
				'usage index'
			);
			if ( opts.debug ) {
				console.error( error?.stack );
			}
		}
		return doReport( results, opts.plant );
	} catch ( error ) {
		annotate(
			'error',
			`npm run check:usage-index crashed, so it measured nothing: ${
				error?.stack || error
			}`,
			'usage index'
		);
		return 2;
	}
}

/**
 * True when this file is the script node was asked to run, not an import.
 *
 * @return {boolean} Whether to run run().
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
	// Whatever escapes run(), such as an error a Playground worker throws
	// after the fact, exits 2 too, never Node's 1.
	const crashed = ( error ) => {
		annotate(
			'error',
			`npm run check:usage-index crashed, so it measured nothing: ${
				error?.stack || error
			}`,
			'usage index'
		);
		process.exit( 2 );
	};
	process.on( 'uncaughtException', crashed );
	process.on( 'unhandledRejection', crashed );
	setTimeout(
		() => {
			annotate(
				'error',
				`Gave up after ${ TIMEOUT_MINUTES } minutes.`,
				'usage index'
			);
			process.exit( 2 );
		},
		TIMEOUT_MINUTES * 60 * 1000
	).unref();

	process.exit( await run( process.argv.slice( 2 ) ) );
}
