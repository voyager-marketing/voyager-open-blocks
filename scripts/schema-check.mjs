#!/usr/bin/env node
/**
 * The pack's LocalBusiness node, on a real WordPress, checked against
 * schema.org and against the SEO plugins it stands aside for.
 *
 *   npm run check:schema
 *   npm run check:schema -- --plant
 *   npm run check:schema -- --help
 *
 * Downloads the schema.org vocabulary at a pinned release and checks its
 * sha256 (VOCABULARY in scripts/lib/schema-check.mjs), then boots the dev
 * site (boot() in scripts/dev.mjs) with Rank Math, Yoast SEO and All in One
 * SEO installed from WordPress.org at the versions in SEO_PLUGINS, none of
 * them active, and sets Site Data to placeholder values (example.com,
 * (555) 555-0100). It loads pages as a visitor and checks:
 *
 * 1. one-node: the front page holds exactly one JSON-LD node whose type is
 *    LocalBusiness or a type below it, and it is the pack's.
 * 2. properties: every property of every JSON-LD node on the front page is
 *    defined, at that release, for the node's type or a type above it.
 * 3. site-data: the pack's node carries name, url, telephone, email, address,
 *    sameAs, areaServed and foundingDate as Site Data gives them, and no
 *    hours, rating or credential.
 * 4. other-page: another published page, the demo index, holds no
 *    LocalBusiness node and nothing from the pack.
 * 5. rank-math, yoast, aioseo: with that plugin alone active and set up to
 *    print the site's business, the front page holds one LocalBusiness or
 *    Organization node, and it is the plugin's. Rank Math has its setup
 *    skipped as its wizard skips it and its knowledge graph set to a local
 *    business (a Plumber, through its Local SEO module). Yoast SEO represents
 *    a company with a name and a logo. All in One SEO is as it installs: it
 *    represents an organization by default. A check is unmeasured when its
 *    plugin prints no such node: then nothing tested the pack.
 *
 * --plant puts a fault in for each check, through mu-plugins: a second
 * LocalBusiness node on every page (1 and 4), an undefined property and the
 * phone taken out of the pack's node through its filter (2 and 3), and the
 * pack's listener on each SEO plugin's schema filter taken off before the
 * plugin prints (5). The run must fail every check on its own plant and
 * nothing else: then it prints PLANT_LINE and exits 1. CI runs both
 * (.github/workflows/e2e.yml). `--plant crash` makes the harness throw
 * before it downloads anything, and the run must exit 2: a crash is never a
 * pass (scripts/test-schema-check.mjs, which npm run check runs).
 *
 * Exit codes: 0 every check passed, 1 a check failed (with --plant: every
 * check failed on its plant alone, and the line says so), 2 the harness
 * could not tell (a crash, a download, a vocabulary whose sha256 does not
 * match, a setup that did not take, a PHP fatal error), 3 with --plant: a
 * check passed, or failed on something that was not its plant.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { boot, phpLog, reportPhpLog, runPhp } from './dev.mjs';
import { createClient } from './lib/http.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import {
	PACK_CLASS,
	PLANT_CLASS,
	PLANTED_PROPERTY,
	SEO_PLUGINS,
	SITE_DATA,
	SITE_TITLE,
	Unmeasured,
	VOCABULARY,
	ldJsonNodes,
	packChecks,
	readVocabulary,
	seoCheck,
	verdict,
} from './lib/schema-check.mjs';
import { flags } from './lib/site.mjs';

const TIMEOUT_MINUTES = 15;

/** The other published page: the demo index the dev site seeds. */
const OTHER_PAGE = '/voyager-open/';

const MU_PLUGINS = '/wordpress/wp-content/mu-plugins';
const PACK_PLANT = `${ MU_PLUGINS }/voyager-open-schema-check-plant.php`;
const SEO_PLANT = `${ MU_PLUGINS }/voyager-open-schema-check-seo-plant.php`;

/** What each plant prints, so the harness knows it went in. */
const MARKER = 'voyager-open-schema-check-plant:';

/**
 * A second LocalBusiness node on every page, and through the pack's filter an
 * undefined property in its node and no phone.
 */
const PLANT_PACK = `<?php
// Planted by scripts/schema-check.mjs --plant.
add_action(
	'wp_head',
	static function (): void {
		echo '<!-- ${ MARKER } second-node -->';
		wp_print_inline_script_tag(
			(string) wp_json_encode( [ '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => 'Planted second node', 'telephone' => '(555) 555-0199' ] ),
			[ 'type' => 'application/ld+json', 'class' => '${ PLANT_CLASS }' ]
		);
	},
	100
);
add_filter(
	'voyager_open_blocks_structured_data',
	static function ( $node ) {
		echo '<!-- ${ MARKER } filter -->';
		unset( $node['telephone'] );
		$node['${ PLANTED_PROPERTY }'] = 'planted';
		return $node;
	}
);
`;

/**
 * The pack's listener on one SEO plugin's schema filter, off before the
 * plugin prints.
 *
 * @param {Object} plugin A row of SEO_PLUGINS.
 * @return {string} The mu-plugin.
 */
function plantSeo( plugin ) {
	return `<?php
// Planted by scripts/schema-check.mjs --plant.
add_action(
	'wp_head',
	static function (): void {
		$removed = remove_filter( '${ plugin.observer }', 'VoyagerOpenBlocks\\StructuredData\\${ plugin.callback }', PHP_INT_MAX );
		echo '<!-- ${ MARKER } ' . ( $removed ? 'listener-removed' : 'listener-missing' ) . ' -->';
	},
	0
);
`;
}

const HELP = `npm run check:schema [-- options]

Checks the pack's LocalBusiness node on the front page of a fresh Playground
site against the schema.org ${ VOCABULARY.release } vocabulary, and checks that
the pack stands aside for ${ Object.values( SEO_PLUGINS )
	.map( ( plugin ) => `${ plugin.label } ${ plugin.version }` )
	.join( ', ' ) }.

  --plant              Plant a fault for every check. The run must fail each
                       check on its own plant and nothing else, print the
                       planted-run line and exit 1.
  --vocabulary <path>  Read the vocabulary from this file instead of
                       downloading it. Its sha256 must still match.
  --port <n>           Port to serve on. Default 9400, or a free one.
  --wp <version>       WordPress version. Default latest.
  --php <version>      PHP version. Default 8.3.
  --debug              Playground's debug output, and stack traces.
  --help               Show this help.

Exit codes: 0 pass, 1 a check failed (with --plant: every check failed on
its plant alone), 2 the harness could not tell, 3 with --plant: a check
passed or failed on something else.`;

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
 * The vocabulary file's text: from --vocabulary, else downloaded from the
 * first URL that answers.
 *
 * @param {string|undefined} file --vocabulary.
 * @return {Promise<string>} The text.
 */
async function vocabularyText( file ) {
	if ( file ) {
		return readFileSync( resolve( file ), 'utf8' );
	}
	const tried = [];
	for ( const url of VOCABULARY.urls ) {
		try {
			const response = await fetch( url, {
				signal: AbortSignal.timeout( 60000 ),
			} );
			if ( response.ok ) {
				return await response.text();
			}
			tried.push( `${ url } answered ${ response.status }` );
		} catch ( error ) {
			tried.push( `${ url }: ${ error.message }` );
		}
	}
	throw new Unmeasured(
		`Could not download the schema.org ${ VOCABULARY.release } vocabulary: ${ tried.join(
			'; '
		) }`
	);
}

/**
 * A page as a visitor sees it: no cookies.
 *
 * @param {string} url
 * @return {Promise<string>} Its HTML.
 */
async function visit( url ) {
	const response = await createClient( url ).get( url );
	if ( response.status !== 200 ) {
		throw new Unmeasured( `${ url } answered ${ response.status }.` );
	}
	return response.text;
}

/**
 * @param {string}   html
 * @param {string[]} markers What each plant prints.
 * @param {string}   page    Which page, for the message.
 */
function needPlants( html, markers, page ) {
	for ( const marker of markers ) {
		if ( ! html.includes( `${ MARKER } ${ marker }` ) ) {
			throw new Unmeasured(
				`The plant ${ marker } did not go in on ${ page }, so the planted run proves nothing.`
			);
		}
	}
}

/** PHP: the placeholder Site Data, and the Site Title. */
const SET_SITE_DATA = `foreach ( json_decode( ${ phpString(
	JSON.stringify( SITE_DATA )
) }, true ) as $name => $value ) {
	update_option( $name, $value );
}
update_option( 'blogname', ${ phpString( SITE_TITLE ) } );
return [
	'front' => get_option( 'show_on_front' ),
	'phone' => get_option( 'voyager_site_phone' ),
	'home'  => home_url( '/' ),
];`;

/**
 * PHP: deactivate every SEO plugin, activate this one, which runs its
 * installer, and read its version from its header.
 *
 * @param {Object} plugin A row of SEO_PLUGINS.
 * @return {string} The PHP.
 */
function activate( plugin ) {
	const all = Object.values( SEO_PLUGINS ).map( ( row ) => row.file );
	return `require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins( json_decode( ${ phpString( JSON.stringify( all ) ) }, true ) );
$result = activate_plugin( ${ phpString( plugin.file ) } );
$data   = get_plugin_data( WP_PLUGIN_DIR . '/' . ${ phpString(
		plugin.file
	) }, false, false );
return [
	'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
	'active'  => is_plugin_active( ${ phpString( plugin.file ) } ),
	'version' => $data['Version'] ?? null,
];`;
}

/**
 * PHP that sets each plugin up to print the site's business, run once the
 * plugin is active. It returns `ok` when the setup took, and what shows it.
 *
 * - Rank Math 1.0.282 builds no front end while
 *   Helper::is_invalid_registration() is true (init_frontend() in
 *   rank-math.php, includes/helpers/class-conditional.php): a site neither
 *   connected to Rank Math nor marked rank_math_registration_skip, which its
 *   wizard's "skip" sets (Registration::skip_wizard(),
 *   includes/admin/class-registration.php). Helper::is_configured( true ) is
 *   what finishing the wizard sets (includes/admin/wizard/class-ready.php).
 *   The Local SEO module types the site's entity with local_business_type
 *   (Local_Seo::organization(), includes/modules/local-seo/class-local-seo.php)
 *   when knowledgegraph_type is company.
 * - Yoast SEO 28.8 prints its Organization only for a company with a name
 *   and a logo (Meta_Tags_Context::generate_site_represents(),
 *   src/context/meta-tags-context.php). The logo is an attachment of the
 *   pack's placeholder logo, stored as its settings screen stores one
 *   (company_logo and company_logo_id, through WPSEO_Options).
 * - All in One SEO 5.0.5.1 represents an organization as it installs
 *   (siteRepresents in app/Common/Options/Options.php), named after the
 *   Site Title. Nothing to set.
 */
const SET_UP = {
	'rank-math': `update_option( 'rank_math_registration_skip', true );
\\RankMath\\Helper::is_configured( true );
\\RankMath\\Helper::update_modules( [ 'local-seo' => 'on', 'rich-snippet' => 'on' ] );
$titles = get_option( 'rank-math-options-titles', [] );
$titles = is_array( $titles ) ? $titles : [];
$titles['knowledgegraph_type'] = 'company';
$titles['knowledgegraph_name'] = ${ phpString( SITE_TITLE ) };
$titles['local_business_type'] = 'Plumber';
update_option( 'rank-math-options-titles', $titles );
$registered = ! \\RankMath\\Helper::is_invalid_registration();
$configured = \\RankMath\\Helper::is_configured();
return [
	'ok'         => $registered && $configured,
	'registered' => $registered,
	'configured' => $configured,
];`,
	yoast: `$upload = wp_upload_dir();
wp_mkdir_p( $upload['path'] );
$file = trailingslashit( $upload['path'] ) . 'voyager-open-schema-check-logo.svg';
copy( WP_PLUGIN_DIR . '/voyager-open-blocks/assets/placeholders/logo-1.svg', $file );
$logo = (int) wp_insert_attachment( [ 'post_mime_type' => 'image/svg+xml', 'post_title' => 'Logo', 'post_status' => 'inherit' ], $file );
WPSEO_Options::set( 'company_or_person', 'company' );
WPSEO_Options::set( 'company_name', ${ phpString( SITE_TITLE ) } );
WPSEO_Options::set( 'company_logo', (string) wp_get_attachment_url( $logo ) );
WPSEO_Options::set( 'company_logo_id', $logo );
return [
	'ok'         => $logo > 0 && 'company' === WPSEO_Options::get( 'company_or_person' ) && $logo === (int) WPSEO_Options::get( 'company_logo_id' ),
	'logo'       => $logo,
	'represents' => WPSEO_Options::get( 'company_or_person' ),
];`,
	aioseo: `$represents = aioseo()->options->searchAppearance->global->schema->siteRepresents;
return [
	'ok'         => 'organization' === $represents,
	'represents' => $represents,
];`,
};

/**
 * @param {string} path Where the mu-plugin goes.
 * @param {string} code Its PHP, or '' to delete it.
 * @return {string} PHP that writes or deletes it.
 */
function muPlugin( path, code ) {
	return code
		? `wp_mkdir_p( '${ MU_PLUGINS }' );
return false !== file_put_contents( '${ path }', ${ phpString( code ) } );`
		: `return ! file_exists( '${ path }' ) || unlink( '${ path }' );`;
}

/**
 * One SEO plugin's leg: activate it alone, set it up, load the front page.
 *
 * @param {Object}  site       From boot().
 * @param {string}  check      Its key in SEO_PLUGINS.
 * @param {Object}  vocabulary From readVocabulary().
 * @param {boolean} plant      Whether --plant was given.
 * @param {Object}  shown      What the run saw, for the report.
 * @return {Promise<Object>} The check's result.
 */
async function seoLeg( site, check, vocabulary, plant, shown ) {
	const plugin = SEO_PLUGINS[ check ];
	if ( plant ) {
		if (
			! ( await runPhp(
				site,
				muPlugin( SEO_PLANT, plantSeo( plugin ) )
			) )
		) {
			throw new Unmeasured( 'Could not write the planted mu-plugin.' );
		}
	}
	const activated = await runPhp( site, activate( plugin ) );
	if ( ! activated?.active ) {
		throw new Unmeasured(
			`${ plugin.label } did not activate: ${ activated?.error || 'no reason given' }`
		);
	}
	if ( activated.version !== plugin.version ) {
		throw new Unmeasured(
			`${ plugin.label } ${ activated.version } is installed, and the check is written against ${ plugin.version }.`
		);
	}
	const setup = await runPhp( site, SET_UP[ check ] );
	if ( ! setup?.ok ) {
		throw new Unmeasured(
			`${ plugin.label }'s setup did not take: ${ JSON.stringify( setup ) }`
		);
	}
	console.log(
		`ok   ${ plugin.label } ${ activated.version } active and set up: ${ JSON.stringify( setup ) }`
	);
	const front = await visit( `${ site.url }/` );
	if ( plant ) {
		needPlants(
			front,
			[ 'listener-removed' ],
			`the front page with ${ plugin.label }`
		);
	}
	shown.seo[ check ] = ldJsonNodes( front ).nodes.map(
		( entry ) =>
			`${ [].concat( entry.node[ '@type' ] ).join( ',' ) } in "${
				entry.className
			}"`
	);
	return seoCheck( { vocabulary, front, plugin } );
}

/**
 * Boot, set up, load the pages and run the checks.
 *
 * @param {Object} opts    Parsed flags.
 * @param {Object} results Check => result, filled in as each runs.
 * @param {Object} shown   What the run saw, for the report.
 */
async function measure( opts, results, shown ) {
	if ( opts.plant === 'crash' ) {
		throw new Error( 'planted crash (--plant crash)' );
	}
	const plant = Boolean( opts.plant );

	const vocabulary = readVocabulary(
		await vocabularyText( opts.vocabulary )
	);
	console.log(
		`ok   schema.org ${ vocabulary.release } vocabulary, sha256 ${ VOCABULARY.sha256 }: ${ vocabulary.classes.size } types, ${ vocabulary.properties.size } properties`
	);

	let site;
	let fatal = 0;
	try {
		group(
			'Boot the dev site, with the SEO plugins installed and not active'
		);
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
				steps: Object.values( SEO_PLUGINS ).map( ( plugin ) => ( {
					step: 'installPlugin',
					pluginData: {
						resource: 'wordpress.org/plugins',
						slug: `${ plugin.slug }.${ plugin.version }.zip`,
					},
					options: { activate: false },
				} ) ),
			} );
		} finally {
			endGroup();
		}
		console.log( `ok   booted ${ site.url }` );
		// Playground's server answers its first request with a redirect to
		// the same URL. Spend it here.
		await createClient( site.url ).get( '/' );

		const set = await runPhp( site, SET_SITE_DATA );
		if ( set?.phone !== SITE_DATA.voyager_site_phone ) {
			throw new Unmeasured(
				`Site Data did not take: the phone reads ${ JSON.stringify(
					set?.phone
				) }.`
			);
		}
		console.log(
			`ok   Site Data set to placeholder values; the front page shows ${ set.front }`
		);

		if ( plant ) {
			if (
				! ( await runPhp( site, muPlugin( PACK_PLANT, PLANT_PACK ) ) )
			) {
				throw new Unmeasured(
					'Could not write the planted mu-plugin.'
				);
			}
		}
		const front = await visit( `${ site.url }/` );
		const other = await visit( `${ site.url }${ OTHER_PAGE }` );
		if ( plant ) {
			needPlants( front, [ 'second-node', 'filter' ], 'the front page' );
			needPlants( other, [ 'second-node' ], OTHER_PAGE );
			await runPhp( site, muPlugin( PACK_PLANT, '' ) );
		}
		shown.pack = ldJsonNodes( front ).nodes.find(
			( entry ) => entry.className === PACK_CLASS
		)?.node;
		Object.assign(
			results,
			packChecks( { vocabulary, front, other, home: set.home } )
		);

		shown.seo = {};
		for ( const check of Object.keys( SEO_PLUGINS ) ) {
			const plugin = SEO_PLUGINS[ check ];
			group( `${ plugin.label } ${ plugin.version }, alone, set up` );
			try {
				results[ check ] = await seoLeg(
					site,
					check,
					vocabulary,
					plant,
					shown
				);
			} catch ( error ) {
				if ( ! ( error instanceof Unmeasured ) ) {
					throw error;
				}
				// One plugin's setup that did not take leaves the others to run.
				results[ check ] = {
					status: 'unmeasured',
					problems: [],
					detail: error.message,
				};
			} finally {
				endGroup();
			}
		}
	} finally {
		if ( site ) {
			fatal = reportPhpLog( await phpLog( site ) );
			await site.stop();
		}
	}
	if ( fatal ) {
		throw new Unmeasured(
			`${ fatal } PHP fatal error${
				fatal === 1 ? '' : 's'
			} in the run, so no verdict from it can be trusted.`
		);
	}
}

/**
 * The whole run, as an exit code. It never throws, and a crash never passes:
 * a crash anywhere, before or after the checks, exits 2. Node's own code for
 * an uncaught exception is 1, the code for a failed check, so the entry point
 * below catches those too. scripts/test-schema-check.mjs plants crashes to
 * show it.
 *
 * @param {string[]}                                                        argv            Arguments after the script name.
 * @param {Object}                                                          [steps]         Stand-ins for the known-answer test.
 * @param {(opts: Object, results: Object, shown: Object) => Promise<void>} [steps.measure] Boots the site and fills in results. Default measure().
 * @param {(line: string, ok: boolean) => void}                             [steps.print]   Prints a line.
 * @return {Promise<number>} 0, 1, 2 or 3, as the header says.
 */
export async function run( argv, steps = {} ) {
	const {
		measure: doMeasure = measure,
		print = ( line, ok ) =>
			ok
				? console.log( `ok   ${ line }` )
				: annotate( 'error', line, 'check:schema' ),
	} = steps;
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
		const known = [
			'_',
			'plant',
			'vocabulary',
			'port',
			'wp',
			'php',
			'debug',
		];
		const unknown = Object.keys( opts ).find(
			( key ) => ! known.includes( key )
		);
		if ( unknown || opts._.length ) {
			console.error(
				`Unknown option ${
					unknown ? `--${ unknown }` : opts._[ 0 ]
				}. Run npm run check:schema -- --help for the options.`
			);
			return 2;
		}
		if (
			opts.plant !== undefined &&
			! [ true, 'crash' ].includes( opts.plant )
		) {
			console.error( '--plant takes no value.' );
			return 2;
		}
		if ( opts.vocabulary === true ) {
			console.error( '--vocabulary needs a file.' );
			return 2;
		}

		const results = {};
		const shown = {};
		let crashed = false;
		try {
			await doMeasure( opts, results, shown );
		} catch ( error ) {
			crashed = true;
			annotate(
				'error',
				`The harness could not tell: ${ error?.message || error }`,
				'check:schema'
			);
			if ( opts.debug ) {
				console.error( error?.stack );
			}
		}
		if ( shown.pack ) {
			console.log(
				`     the pack's node on the front page: ${ JSON.stringify(
					shown.pack
				) }`
			);
		}
		for ( const [ check, nodes ] of Object.entries( shown.seo || {} ) ) {
			console.log(
				`     JSON-LD nodes on the front page with ${
					SEO_PLUGINS[ check ].label
				}: ${ nodes.join( '; ' ) || 'none' }`
			);
		}
		const code = verdict( results, Boolean( opts.plant ), print );
		// A crash, even after checks that passed, is a run that could not tell.
		return crashed ? 2 : code;
	} catch ( error ) {
		annotate(
			'error',
			`npm run check:schema crashed, so it measured nothing: ${
				error?.stack || error
			}`,
			'check:schema'
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
			`npm run check:schema crashed, so it measured nothing: ${
				error?.stack || error
			}`,
			'check:schema'
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
				'check:schema'
			);
			process.exit( 2 );
		},
		TIMEOUT_MINUTES * 60 * 1000
	).unref();

	process.exit( await run( process.argv.slice( 2 ) ) );
}
