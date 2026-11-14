#!/usr/bin/env node
/**
 * A request reads no pattern file: the pack's patterns, intents and icons
 * come from the PHP arrays `npm run patterns:build` writes in inc/generated/.
 *
 *   npm run check:generated-arrays
 *   npm run check:generated-arrays -- --keep    keep the copy, and say where
 *   npm run check:generated-arrays -- --help
 *
 * It copies the files the plugin ships (package.json `files`) to a temporary
 * folder, with playground/, which the dev blueprint seeds the demo from. Then
 * it boots that copy in WordPress Playground twice, one site at a time and on
 * the same port, so the plugin's URL is the same in both:
 *
 * 1. As copied, a normal boot. It reads the voyager-open/* patterns in the
 *    registry, and renders a published page that holds the phone icon.
 * 2. With patterns/intents/, every patterns/*.html and assets/icons.json
 *    deleted from the copy, never from this checkout. The same again.
 *
 * It passes when, in boot 1, each voyager-open/* pattern is what its intent
 * and markup in this checkout say, one per intent, with the plugin's assets
 * URL in its content; and when, in boot 2, WordPress sees none of the
 * deleted files, the registry holds every pattern of boot 1 in the same
 * order, each byte for byte as in boot 1 both as registered and as
 * get_all_registered() gives it, the page renders the icon's SVG byte for
 * byte as in boot 1 with its path from assets/icons.json, and debug.log holds
 * no PHP error, warning or notice from the plugin.
 *
 * Before the arrays, boot 2 registered none of the patterns: register()
 * skipped every intent it could not read.
 *
 * Exit codes: 0 every check passed, 1 a check failed, 2 the run could not
 * tell: a site did not boot, or the script crashed.
 */
import {
	cpSync,
	existsSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { boot, phpLog, runPhp } from './dev.mjs';
import { createClient } from './lib/http.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { flags, repo } from './lib/site.mjs';

const TIMEOUT_MINUTES = 20;
const TITLE = 'generated arrays';
const PREFIX = 'voyager-open/';
const ASSETS_TOKEN = '/voyager-open-blocks-assets/';
const ICON = 'phone';
const PAGE_SLUG = 'generated-arrays-check';
const SVG = /<svg class="wp-block-voyager-open-icon__svg"[\s\S]*?<\/svg>/g;

const HELP = `npm run check:generated-arrays [-- options]

Copies the files the plugin ships to a temporary folder and boots the copy in
WordPress Playground twice: as copied, then with patterns/intents/,
patterns/*.html and assets/icons.json deleted from the copy. Passes when the
second boot registers every pattern byte for byte as the first did and renders
the icon's SVG, so no request needs those files.

  --keep           Keep the copy, and print where it is.
  --wp <version>   WordPress version, such as 6.9. Default latest.
  --php <version>  PHP version, such as 8.1. Default 8.3.
  --debug          Print Playground's debug output.
  --help           Show this help.`;

// Runs in the booted site, with WordPress and the plugin loaded.
const PROBE = `
$registry = WP_Block_Patterns_Registry::get_instance();
$ours     = static fn ( array $pattern ): bool => str_starts_with( (string) ( $pattern['name'] ?? '' ), '${ PREFIX }' );
// As register_block_pattern() stored them, before the registry's getters
// run the Block Hooks pass over the content.
$raw = Closure::bind( fn () => $this->registered_patterns, $registry, WP_Block_Patterns_Registry::class )();

$page = get_page_by_path( '${ PAGE_SLUG }' );
if ( ! $page ) {
	$id   = wp_insert_post(
		[
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Generated arrays check',
			'post_name'    => '${ PAGE_SLUG }',
			'post_content' => '<!-- wp:voyager-open/icon {"icon":"${ ICON }","size":32} /-->',
		],
		true
	);
	$page = is_wp_error( $id ) ? null : get_post( $id );
}

$dir = VOYAGER_OPEN_BLOCKS_DIR;
return [
	'raw'     => array_values( array_filter( $raw, $ours ) ),
	'public'  => array_values(
		array_map(
			static fn ( array $pattern ): array => [ 'name' => $pattern['name'], 'content' => $pattern['content'] ],
			array_filter( $registry->get_all_registered(), $ours )
		)
	),
	'assets'  => plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE ),
	'page'    => $page ? wp_make_link_relative( get_permalink( $page ) ) : null,
	'present' => [
		'patterns/intents/'          => is_dir( $dir . '/patterns/intents' ),
		'patterns/*.html'            => count( glob( $dir . '/patterns/*.html' ) ?: [] ),
		'assets/icons.json'          => file_exists( $dir . '/assets/icons.json' ),
		'inc/generated/patterns.php' => file_exists( $dir . '/inc/generated/patterns.php' ),
		'inc/generated/icons.php'    => file_exists( $dir . '/inc/generated/icons.php' ),
	],
];
`;

/**
 * Copy what the plugin ships, and playground/ for the dev blueprint's seed.
 *
 * @return {string} The copy's folder.
 */
function copyPlugin() {
	const pkg = JSON.parse(
		readFileSync( join( repo, 'package.json' ), 'utf8' )
	);
	const copy = mkdtempSync( join( tmpdir(), 'voyager-open-generated-' ) );
	for ( const entry of [ ...pkg.files, 'playground' ] ) {
		cpSync( join( repo, entry ), join( copy, entry ), { recursive: true } );
	}
	return copy;
}

/**
 * Delete the pattern sources and the icon set from the copy, and only there.
 *
 * @param {string} copy The copy's folder.
 * @return {string} What was deleted, in words.
 */
function deleteSources( copy ) {
	const fold = ( path ) =>
		process.platform === 'win32'
			? resolve( path ).toLowerCase()
			: resolve( path );
	if (
		fold( copy ) === fold( repo ) ||
		fold( copy ).startsWith( fold( repo ) + sep )
	) {
		throw new Error(
			`Refusing to delete from ${ copy }: it is this checkout or inside it.`
		);
	}
	const intents = readdirSync( join( copy, 'patterns', 'intents' ) ).length;
	rmSync( join( copy, 'patterns', 'intents' ), { recursive: true } );
	let markup = 0;
	for ( const file of readdirSync( join( copy, 'patterns' ) ) ) {
		if ( file.endsWith( '.html' ) ) {
			rmSync( join( copy, 'patterns', file ) );
			markup++;
		}
	}
	rmSync( join( copy, 'assets', 'icons.json' ) );
	return `patterns/intents/ (${ intents } files), ${ markup } patterns/*.html and assets/icons.json`;
}

/**
 * What this checkout's intents and markup say each pattern is, as
 * register_block_pattern() stores it: its args, then its name.
 *
 * @param {string} assets The plugin's assets/ URL in the site.
 * @return {Object[]} One per intent, in glob() order.
 */
function expectedPatterns( assets ) {
	const intents = join( repo, 'patterns', 'intents' );
	return readdirSync( intents )
		.filter( ( file ) => file.endsWith( '.json' ) )
		.sort()
		.map( ( file ) => {
			const name = file.slice( 0, -5 );
			const meta = JSON.parse(
				readFileSync( join( intents, file ), 'utf8' )
			);
			const markup = readFileSync(
				join( repo, 'patterns', `${ name }.html` ),
				'utf8'
			);
			return {
				title: meta.title,
				description: meta.description ?? '',
				content: markup.split( ASSETS_TOKEN ).join( assets ),
				categories: meta.categories ?? [ 'voyager-open' ],
				keywords: meta.keywords ?? [],
				blockTypes: meta.blockTypes ?? [],
				viewportWidth: meta.viewportWidth ?? 1280,
				name: PREFIX + name,
			};
		} );
}

/**
 * Boot the copy, read the registry and the icon page, stop.
 *
 * @param {string} copy The copy's folder.
 * @param {Object} opts Parsed flags, and the port to use.
 * @return {Promise<Object>} What the probe returned, the port, the SVGs on the page and the plugin's lines in debug.log.
 */
async function visit( copy, opts ) {
	let site;
	try {
		site = await boot( {
			plugin: copy,
			port: opts.port,
			wp: opts.wp,
			php: opts.php,
			debug: opts.debug,
			login: false,
		} );
		const probe = await runPhp( site, PROBE );
		const page = probe?.page
			? await createClient( site.url ).get( probe.page )
			: { status: 0, text: '' };
		const log = ( await phpLog( site ) ) ?? [
			'debug.log could not be read',
		];
		return {
			...probe,
			port: Number( new URL( site.url ).port ),
			status: page.status,
			svgs: page.text.match( SVG ) ?? [],
			log: log.filter(
				( line ) =>
					/PHP (Fatal|Parse) error/.test( line ) ||
					( line.includes( 'plugins/voyager-open-blocks/' ) &&
						/PHP (Warning|Notice|Deprecated)/.test( line ) )
			),
		};
	} finally {
		if ( site ) {
			await site.stop();
		}
	}
}

/**
 * Each check, as a line, and how many failed.
 *
 * @param {Object} first   Boot 1, from visit().
 * @param {Object} second  Boot 2, from visit().
 * @param {string} deleted What deleteSources() deleted, in words.
 * @return {number} Failed checks.
 */
function verdict( first, second, deleted ) {
	let failed = 0;
	const check = ( ok, line, detail = '' ) => {
		if ( ok ) {
			console.log( `ok   ${ line }${ detail ? ` (${ detail })` : '' }` );
			return;
		}
		failed++;
		annotate(
			'error',
			`${ line }${ detail ? `: ${ detail }` : '' }`,
			TITLE
		);
	};
	const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );
	const names = ( list ) => list.map( ( pattern ) => pattern.name );
	const icons = JSON.parse(
		readFileSync( join( repo, 'assets', 'icons.json' ), 'utf8' )
	).icons;

	const expected = expectedPatterns( first.assets );
	const matching = expected.filter( ( pattern, index ) =>
		same( first.raw[ index ], pattern )
	);
	check(
		expected.length > 0 &&
			first.raw.length === expected.length &&
			matching.length === expected.length,
		`boot 1, as copied: ${ first.raw.length } ${ PREFIX }* patterns registered, each as its intent and markup in this checkout say, with the assets URL in its content`,
		matching.length === expected.length
			? `${ expected.length } intents`
			: `${ matching.length } of ${ expected.length } match; registered ${ names(
					first.raw
				).join( ', ' ) }`
	);
	check(
		first.svgs.length === 1 &&
			first.svgs[ 0 ].includes( `d="${ icons[ ICON ] }"` ),
		`boot 1: ${ first.page } renders the ${ ICON } icon's SVG, its path from assets/icons.json`,
		`HTTP ${ first.status }, ${ first.svgs.length } icon ${ first.svgs.length === 1 ? 'SVG' : 'SVGs' }`
	);

	const seen = Object.entries( second.present ?? {} )
		.filter( ( [ path ] ) => ! path.startsWith( 'inc/' ) )
		.filter( ( [ , present ] ) => present !== false && present !== 0 )
		.map( ( [ path ] ) => path );
	const generated = Object.entries( second.present ?? {} ).filter(
		( [ path, present ] ) => path.startsWith( 'inc/' ) && present === true
	);
	let unseen = `on port ${ second.port }, as boot 1`;
	if ( seen.length ) {
		unseen = `it still sees ${ seen.join( ', ' ) }`;
	} else if ( second.port !== first.port ) {
		unseen = `on port ${ second.port }, and boot 1 on ${ first.port }, so the plugin's URL differs`;
	}
	check(
		second.port === first.port && seen.length === 0,
		`boot 2: ${ deleted } deleted from the copy, and WordPress sees none of them`,
		unseen
	);
	check(
		generated.length === 2,
		'boot 2: WordPress sees inc/generated/patterns.php and inc/generated/icons.php',
		`${ generated.length } of 2`
	);

	const identical = second.raw.filter( ( pattern, index ) =>
		same( pattern, first.raw[ index ] )
	);
	check(
		second.raw.length === first.raw.length &&
			identical.length === first.raw.length &&
			first.raw.length === expected.length,
		`boot 2: ${ second.raw.length } of ${ expected.length } ${ PREFIX }* patterns registered, in boot 1's order, each byte for byte as in boot 1`,
		identical.length === first.raw.length
			? 'title, description, content, categories, keywords, blockTypes and viewportWidth'
			: `${ identical.length } identical; registered ${
					names( second.raw ).join( ', ' ) || 'none'
				}`
	);
	check(
		second.public.length === expected.length &&
			same( second.public, first.public ),
		`boot 2: get_all_registered() gives each pattern the content it gave in boot 1`,
		`${ second.public.length } patterns`
	);
	check(
		second.svgs.length === 1 && same( second.svgs, first.svgs ),
		`boot 2: ${ second.page } renders the ${ ICON } icon's SVG, byte for byte as in boot 1`,
		`HTTP ${ second.status }, ${ second.svgs.length } icon ${ second.svgs.length === 1 ? 'SVG' : 'SVGs' }`
	);
	check(
		second.log.length === 0,
		'boot 2: no PHP error, warning or notice from the plugin in debug.log',
		second.log.slice( 0, 3 ).join( ' | ' )
	);

	return failed;
}

/**
 * The whole run, as an exit code. A crash is never a pass.
 *
 * @param {string[]} argv Arguments after the script name.
 * @return {Promise<number>} 0, 1 or 2.
 */
async function run( argv ) {
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
	const known = [ '_', 'keep', 'wp', 'php', 'debug' ];
	const unknown = Object.keys( opts ).find(
		( key ) => ! known.includes( key )
	);
	if ( unknown || opts._.length ) {
		console.error(
			`Unknown option ${ unknown ? `--${ unknown }` : opts._[ 0 ] }. Run npm run check:generated-arrays -- --help for the options.`
		);
		return 2;
	}
	for ( const file of [
		'inc/generated/patterns.php',
		'inc/generated/icons.php',
	] ) {
		if ( ! existsSync( join( repo, file ) ) ) {
			annotate(
				'warning',
				`${ file } is missing. Run npm run patterns:build.`,
				TITLE
			);
		}
	}

	const copy = copyPlugin();
	try {
		group( 'Boot 1: the copy as shipped' );
		let first;
		try {
			first = await visit( copy, opts );
		} finally {
			endGroup();
		}

		const deleted = deleteSources( copy );
		group(
			`Boot 2: ${ deleted } deleted from the copy (${ copy.split( sep ).join( '/' ) })`
		);
		let second;
		try {
			second = await visit( copy, { ...opts, port: first.port } );
		} finally {
			endGroup();
		}

		return verdict( first, second, deleted ) ? 1 : 0;
	} finally {
		if ( opts.keep ) {
			console.log( `The copy is kept at ${ copy }` );
		} else {
			rmSync( copy, { recursive: true, force: true } );
		}
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
	const crashed = ( error ) => {
		annotate(
			'error',
			`npm run check:generated-arrays crashed, so it checked nothing: ${
				error?.stack || error
			}`,
			TITLE
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
				TITLE
			);
			process.exit( 2 );
		},
		TIMEOUT_MINUTES * 60 * 1000
	).unref();

	let code;
	try {
		code = await run( process.argv.slice( 2 ) );
	} catch ( error ) {
		crashed( error );
	}
	process.exit( code );
}
