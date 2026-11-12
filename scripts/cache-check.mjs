#!/usr/bin/env node
/**
 * Does a Site Data change reach a page that a cache has already stored?
 *
 *   npm run cache-check -- --plugin wp-super-cache --real
 *   npm run cache-check -- --plugin wp-super-cache --real --no-purge
 *   npm run cache-check -- --plugin wp-super-cache --real --pages 51
 *   npm run cache-check -- --plugin wp-super-cache --real --lockdown
 *   npm run cache-check -- --plugin spinupwp --spy [--no-purge]
 *   npm run cache-check -- --plugin cloudflare --spy [--no-purge]
 *   npm run cache-check -- --plugin dummy --spy [--stand-in] [--no-purge]
 *   npm run cache-check -- --help
 *
 * Boots WordPress Playground with this checkout as the plugin (boot() in
 * scripts/dev.mjs) and one cache, publishes a page whose paragraph is bound
 * to the `phone` Site Data key, changes the phone over REST, and looks for
 * the new number. --pages publishes more pages bound to the phone first and
 * measures the last one, so with 51 it is past the 50 pages the purge cleans
 * one by one. The logic and the exit codes are in
 * scripts/lib/cache-check.mjs: 0 when the change reached the page, 1 when it
 * did not (stale), 2 when the harness could not tell.
 *
 * --real loads the page as a visitor twice, so the second load comes from
 * the cache (the harness checks that it did), changes the phone, and loads
 * it again. --spy is for caches that live in a server or a CDN, or cannot be
 * installed here: it records the cache's purge entry points and its outgoing
 * HTTP, and passes when one of them names the page.
 *
 * The site is frozen for the run: WP-Cron and the background updater are
 * off, because a fresh Playground site updates itself mid-run, and external
 * HTTP is blocked. tests/fixtures/cache-check/ holds the mu-plugin that does
 * the recording and the per-cache setup.
 */
import { appendFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { appPassword, boot, phpLog, reportPhpLog, runPhp } from './dev.mjs';
import {
	EXIT,
	PHONE_AFTER,
	PHONE_BEFORE,
	PLUGINS,
	UsageError,
	describe,
	flagsOf,
	lastByIdProblem,
	pageSlugs,
	parseArgs,
	realVerdict,
	renderMarker,
	shows,
	spyVerdict,
	summaryMarkdown,
} from './lib/cache-check.mjs';
import { createClient } from './lib/http.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { createRestClient } from './lib/rest.mjs';

const TIMEOUT_MINUTES = 15;
const SLUG = 'voyager-open-cache-check';

/** One paragraph bound to the phone. */
const PAGE_CONTENT =
	'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"phone"}}}}} -->\n<p>Call us</p>\n<!-- /wp:paragraph -->';

const HELP = `npm run cache-check -- --plugin <name> (--real | --spy) [options]

Shows whether a Site Data change reaches a page a cache has already stored.

  --plugin <name>  The cache: ${ Object.entries( PLUGINS )
		.map(
			( [ name, spec ] ) => `${ name } (--${ spec.modes.join( ', --' ) })`
		)
		.join( ', ' ) }.
  --real           Load the page from the cache before and after the change.
                   For caches that serve pages from PHP inside Playground.
  --spy            Record the cache's purge entry points and outgoing HTTP
                   during the change, and look for the page's URL.
  --no-purge       Empty the pack's purge (its cache adapters and URLs), the
                   control: the page must go stale.
  --stand-in       Stand in for the pack's purge with the harness's own, which
                   runs contract 2 by hand. The positive control for --spy.
  --lockdown       Turn on the cache's Lock Down, in which it keeps its pages
                   through a traffic spike. The pack must leave them alone.
  --pages <n>      Publish n pages bound to the phone and measure the last,
                   which has the highest ID. Default 1. With 51 the measured
                   page is past the 50 the purge cleans one by one.
  --result-file <path>
                   Write the verdict and what was observed as JSON.
  --port <n>       Port to serve on. Default 9400, or a free one.
  --wp <version>   WordPress version. Default latest.
  --php <version>  PHP version. Default 8.3.
  --debug          Playground's debug output.
  --help           Show this help.

Exit codes: 0 the change reached the page, 1 it did not (stale), 2 the
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
 * Blueprint steps for the cache under test.
 *
 * @param {Object} spec A row of PLUGINS.
 * @return {Object[]} Steps to run after the dev blueprint.
 */
function cacheSteps( spec ) {
	if ( ! spec.install ) {
		return [];
	}
	const { slug, version } = spec.install;
	return [
		{
			step: 'installPlugin',
			pluginData: {
				resource: 'wordpress.org/plugins',
				slug: `${ slug }.${ version }.zip`,
			},
			options: { activate: true },
		},
	];
}

/**
 * A REST call that must succeed.
 *
 * @param {Object} rest  From createRestClient().
 * @param {string} path  Route.
 * @param {Object} init  Method, body, query.
 * @param {string} doing What it is for, for the error.
 * @return {Promise<Object>} The decoded response body.
 */
async function call( rest, path, init, doing ) {
	const response = await rest.api( path, init );
	if ( response.status < 200 || response.status >= 300 ) {
		throw new Error(
			`Could not ${ doing }: ${ init.method || 'GET' } ${ path } answered ${
				response.status
			}. ${ response.text.slice( 0, 300 ) }`
		);
	}
	return response.data;
}

/**
 * A page load as an anonymous visitor: no cookies, no Accept-Encoding.
 *
 * @param {string} url
 * @return {Promise<{status: number, text: string, marker: string|null, served: string}>} The page, its render marker and WP Super Cache's served header.
 */
async function visit( url ) {
	const response = await createClient( url ).get( url );
	return {
		status: response.status,
		text: response.text,
		marker: renderMarker( response.text ),
		served: response.headers[ 'x-wp-super-cache' ] || '',
	};
}

/**
 * Boot, set up, change the phone, and decide.
 *
 * @param {Object} opts From parseArgs().
 * @return {Promise<Object>} The result, as written to --result-file.
 */
async function measure( opts ) {
	const spec = PLUGINS[ opts.plugin ];
	const result = {
		plugin: opts.plugin,
		label: spec.label,
		mode: opts.mode,
		noPurge: opts.noPurge,
		standIn: opts.standIn,
		lockDown: opts.lockDown,
		pages: opts.pages,
		version: spec.install?.version || null,
		outcome: 'unmeasured',
		reason: '',
		observed: {},
	};
	let site;

	try {
		group(
			`Boot WordPress Playground with this checkout and ${ spec.label }`
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
					...( spec.consts || {} ),
				},
				steps: cacheSteps( spec ),
			} );
		} finally {
			endGroup();
		}
		console.log( `ok   booted ${ site.url }` );

		// Playground's CLI server answers the first HTTP request it gets with
		// a 302 to the same URL, login or not. Spend it on a page load that
		// follows redirects, before the cache is on, so no REST call gets it.
		await createClient( site.url ).get( '/' );

		// Before the cache is on: runPhp() loads WordPress outside a request.
		const password = await appPassword( site );
		const config = {
			plugin: opts.plugin,
			mu_plugins: spec.muPlugins,
			hooks: spec.hooks,
			no_purge: opts.noPurge,
			stand_in: opts.standIn,
			lock_down: opts.lockDown,
		};
		const setup = await runPhp(
			site,
			`$voyager_open_cache_check = json_decode( ${ phpString(
				JSON.stringify( config )
			) }, true );
return require WP_PLUGIN_DIR . '/voyager-open-blocks/tests/fixtures/cache-check/setup.php';`
		);
		result.observed.setup = setup;
		const problems = [ ...( setup?.problems || [] ) ];
		if ( ! setup ) {
			problems.push( 'setup.php returned nothing.' );
		}
		if ( spec.install ) {
			result.version =
				setup?.facts?.[ `${ spec.install.slug } version` ] ?? null;
			if ( result.version !== spec.install.version ) {
				problems.push(
					`${ spec.label } ${ result.version } is installed, and the harness is written against ${ spec.install.version }.`
				);
			}
		}
		if ( ! setup?.ok || problems.length ) {
			result.reason = `Setup failed: ${ problems.join( ' ' ) }`;
			return result;
		}
		console.log(
			`ok   set up: ${ JSON.stringify( setup.facts ) }`.slice( 0, 2000 )
		);

		const rest = createRestClient( site.url, { user: 'admin', password } );
		const setPhone = async ( phone ) =>
			(
				await call(
					rest,
					'/wp/v2/settings',
					{ method: 'POST', body: { voyager_site_phone: phone } },
					`set the phone to ${ phone }`
				)
			)?.voyager_site_phone;

		await setPhone( PHONE_BEFORE );
		// One at a time, so each page gets a higher ID than the one before
		// and the measured page, published last, has the highest.
		const slugs = pageSlugs( SLUG, opts.pages );
		const pages = [];
		for ( const [ i, slug ] of slugs.entries() ) {
			const published = await call(
				rest,
				'/wp/v2/pages',
				{
					method: 'POST',
					body: {
						title:
							slug === SLUG
								? 'Cache check'
								: `Cache check ${ i + 1 }`,
						slug,
						status: 'publish',
						content: PAGE_CONTENT,
					},
				},
				`publish page ${ i + 1 } of ${ slugs.length }`
			);
			pages.push( { id: published?.id, link: published?.link } );
		}
		const page = pages[ pages.length - 1 ];
		const url = page.link;
		result.observed.url = url;
		result.observed.pages = {
			count: pages.length,
			ids: pages.map( ( published ) => published.id ),
		};
		const order = lastByIdProblem( pages );
		if ( order ) {
			result.reason = order;
			return result;
		}
		console.log(
			pages.length > 1
				? `ok   published ${ pages.length } pages bound to phone, IDs ${
						pages[ 0 ].id
					} to ${ page.id }; measuring the last, ${ url }`
				: `ok   published ${ url }, its paragraph bound to phone`
		);

		const loads = [ await visit( url ) ];
		if ( opts.mode === 'real' ) {
			loads.push( await visit( url ) );
		}
		await call(
			rest,
			'/voyager-open-cache-check/v1/records',
			{ method: 'DELETE' },
			'clear the records'
		);
		const saved = await setPhone( PHONE_AFTER );
		console.log( `ok   changed the phone to ${ saved }` );
		loads.push( await visit( url ) );
		const spied = await call(
			rest,
			'/voyager-open-cache-check/v1/records',
			{},
			'read the records'
		);

		result.observed.loads = loads.map( ( load ) => ( {
			status: load.status,
			marker: load.marker,
			before: shows( load.text, PHONE_BEFORE ),
			after: shows( load.text, PHONE_AFTER ),
			served: load.served,
			comments: (
				load.text.match( /<!--(?!\s*\/?wp:)[\s\S]*?-->/g ) || []
			)
				.slice( -6 )
				.map( ( comment ) => comment.replace( /\s+/g, ' ' ) ),
		} ) );
		result.observed.records = spied?.records || [];
		result.observed.adapters = spied?.adapters || [];
		result.observed.adapterStates = spied?.adapter_states || [];
		result.observed.saved = saved;

		const verdict =
			opts.mode === 'real'
				? realVerdict( { loads, saved } )
				: spyVerdict( {
						url,
						records: result.observed.records,
						receivers: spec.receivers,
						loads,
						saved,
						adapter: spec.adapter,
						adapters: result.observed.adapterStates,
					} );
		result.outcome = verdict.outcome;
		result.reason = verdict.reason;
		return result;
	} catch ( error ) {
		result.reason = `${ error.message }${
			error.cause ? ` (${ error.cause.message || error.cause })` : ''
		}`;
		if ( opts.debug ) {
			console.error( error.stack );
		}
		return result;
	} finally {
		if ( site ) {
			const fatal = reportPhpLog( await phpLog( site ) );
			if ( fatal && result.outcome !== 'unmeasured' ) {
				result.reason = `A PHP fatal error makes the ${ result.outcome } verdict untrustworthy: ${ result.reason }`;
				result.outcome = 'unmeasured';
			}
			await site.stop();
		}
	}
}

/**
 * Print the result, as annotations in GitHub Actions.
 *
 * @param {Object} result From measure().
 */
function report( result ) {
	const loads = result.observed.loads || [];
	for ( const [ i, load ] of loads.entries() ) {
		console.log(
			`     load ${ i + 1 }: ${ load.status }, render ${
				load.marker
			}, shows ${
				[
					load.before ? PHONE_BEFORE : '',
					load.after ? PHONE_AFTER : '',
				]
					.filter( Boolean )
					.join( ' and ' ) || 'neither number'
			}${ load.served ? `, X-WP-Super-Cache: ${ load.served }` : '' }`
		);
	}
	for ( const record of result.observed.records || [] ) {
		console.log( `     recorded ${ describe( record ) }` );
	}
	if ( result.observed.adapters ) {
		console.log(
			`     cache adapters: ${
				result.observed.adapters.join( ', ' ) || 'none'
			}`
		);
	}
	if ( result.observed.adapterStates?.length ) {
		console.log(
			`     with --no-purge left out: ${ result.observed.adapterStates
				.map(
					( state ) =>
						`${ state.id } ${ state.active ? 'on' : 'off' }`
				)
				.join( ', ' ) }`
		);
	}

	const title = `cache-check ${ flagsOf( result ) }`;
	if ( result.outcome === 'fresh' ) {
		annotate( 'notice', `fresh: ${ result.reason }`, title );
	} else if ( result.outcome === 'stale' ) {
		annotate( 'warning', `stale: ${ result.reason }`, title );
	} else {
		annotate( 'error', `unmeasured: ${ result.reason }`, title );
		for ( const load of loads ) {
			if ( load.comments?.length ) {
				console.log(
					`     HTML comments at the end of a load: ${ load.comments.join(
						' '
					) }`
				);
			}
		}
	}
}

/**
 * Write the result file and the job summary.
 *
 * @param {Object}      result     From measure().
 * @param {string|null} resultFile --result-file, or nothing.
 */
function writeResult( result, resultFile ) {
	if ( resultFile ) {
		writeFileSync(
			resolve( resultFile ),
			JSON.stringify( result, null, 2 ) + '\n'
		);
	}
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		appendFileSync(
			process.env.GITHUB_STEP_SUMMARY,
			summaryMarkdown( result ) + '\n'
		);
	}
}

/**
 * The whole run, as an exit code. It never throws: a crash anywhere, in
 * measure(), in stopping the site, in report() or in writing the result,
 * comes back as 2 (unmeasured). Node's own code for an uncaught exception is
 * 1, the code for stale, so a crash that escaped would pass as a stale page.
 * scripts/test-cache-check.mjs plants crashes in each step to show it.
 *
 * @param {string[]}                                         argv                Arguments after the script name.
 * @param {Object}                                           [steps]             Stand-ins for the steps, for the known-answer test.
 * @param {(opts: Object) => Promise<Object>}                [steps.measure]     Boots, sets up and decides. Default measure().
 * @param {(result: Object) => void}                         [steps.report]      Prints the result. Default report().
 * @param {(result: Object, file: string|undefined) => void} [steps.writeResult] Writes --result-file. Default writeResult().
 * @return {Promise<number>} The exit code: 0 fresh, 1 stale, 2 anything else.
 */
export async function run( argv, steps = {} ) {
	const {
		measure: doMeasure = measure,
		report: doReport = report,
		writeResult: doWrite = writeResult,
	} = steps;
	let opts = {};
	try {
		const problem = nodeVersionProblem();
		if ( problem ) {
			console.error( problem );
			return EXIT.unmeasured;
		}
		try {
			opts = parseArgs( argv );
		} catch ( error ) {
			if ( error instanceof UsageError ) {
				console.error(
					`${ error.message } Run npm run cache-check -- --help for the options.`
				);
				return EXIT.unmeasured;
			}
			throw error;
		}
		if ( opts.help ) {
			console.log( HELP );
			return 0;
		}

		const result = await doMeasure( opts );
		result.exit = EXIT[ result.outcome ] ?? EXIT.unmeasured;
		doReport( result );
		doWrite( result, opts.resultFile );
		return result.exit;
	} catch ( error ) {
		annotate(
			'error',
			`npm run cache-check crashed, so it measured nothing: ${
				error?.stack || error
			}`
		);
		// Best effort: a result file that says so. The workflow fails a row
		// with no result file anyway.
		try {
			if ( opts.resultFile ) {
				writeFileSync(
					resolve( opts.resultFile ),
					JSON.stringify(
						{
							outcome: 'unmeasured',
							exit: EXIT.unmeasured,
							reason: `The harness crashed: ${ error?.message || error }`,
						},
						null,
						2
					) + '\n'
				);
			}
		} catch {}
		return EXIT.unmeasured;
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
			`npm run cache-check crashed, so it measured nothing: ${
				error?.stack || error
			}`
		);
		process.exit( EXIT.unmeasured );
	};
	process.on( 'uncaughtException', crashed );
	process.on( 'unhandledRejection', crashed );
	setTimeout( () => {
		annotate(
			'error',
			`npm run cache-check gave up after ${ TIMEOUT_MINUTES } minutes.`
		);
		process.exit( EXIT.unmeasured );
	}, TIMEOUT_MINUTES * 60000 ).unref();

	process.exit( await run( process.argv.slice( 2 ) ) );
}
