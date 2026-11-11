/**
 * The logic of npm run cache-check, apart from Playground: the plugin table,
 * the options, and the two verdicts. scripts/test-cache-check.mjs is its
 * known-answer test and needs no WordPress, so npm run check runs it.
 *
 * Three outcomes, each with its own exit code:
 *
 *   fresh       0  the change reached the page: --real served the new number
 *                  on the next request, --spy saw the cache told to purge the
 *                  page's URL (or everything)
 *   stale       1  it did not: --real served the old number from the cache,
 *                  --spy saw no purge of the page reach the cache
 *   unmeasured  2  the harness could not tell, so neither of the above is
 *                  evidence: the site did not boot, the page did not show
 *                  the bound number, the cache never served the page, or the
 *                  change did not save. Usage errors exit 2 too.
 *
 * Telling stale from unmeasured is the point of the harness. A cache that
 * never stored the page would show the new number every time, which reads
 * like a working purge, so --real only gives a verdict once the second load
 * of the page is shown to come from the cache.
 */
export const EXIT = { fresh: 0, stale: 1, unmeasured: 2 };

/** The number the page shows before the change, and after it. */
export const PHONE_BEFORE = '(555) 555-0142';
export const PHONE_AFTER = '(555) 555-0187';

/** The harness mu-plugin prints this, with a value unique to each render. */
const MARKER = /<!-- voyager-open-cache-check rendered ([0-9a-f]+)\b[^>]*-->/;

/**
 * The caches the harness knows, and how it runs each.
 *
 * modes:     real (the cache serves pages from PHP inside Playground, so the
 *            page itself is the evidence) or spy (the cache lives in a
 *            server or a CDN, or cannot be installed, so its purge entry
 *            points are recorded instead).
 * install:   a wordpress.org plugin, pinned. Playground downloads
 *            https://downloads.wordpress.org/plugin/<slug>.<version>.zip.
 * muPlugins: files from tests/fixtures/cache-check/ to install as mu-plugins.
 * hooks:     actions that are the cache's purge entry points. The harness
 *            records every call, with the URLs in its arguments. `all` marks
 *            one that purges everything.
 * receivers: which records mean the cache itself was told to purge. --spy
 *            passes when one of them covers the page. A record of the pack's
 *            own voyager_open_blocks_purge action is not one: the action
 *            firing says nothing about whether a cache heard it.
 *
 * A Wave 2 cache is a new row here, plus a matrix row in
 * .github/workflows/cache.yml.
 */
export const PLUGINS = {
	'wp-super-cache': {
		label: 'WP Super Cache',
		modes: [ 'real' ],
		install: {
			slug: 'wp-super-cache',
			version: '3.1.5',
			file: 'wp-super-cache/wp-cache.php',
		},
		muPlugins: [],
		// wp_cache_clear_cache() fires it after deleting every cached page.
		// Recorded for the report only: in --real mode the page decides.
		hooks: { wp_cache_cleared: { all: true } },
		receivers: [],
	},
	dummy: {
		label: 'Dummy cache (test fixture)',
		modes: [ 'spy' ],
		install: null,
		muPlugins: [ 'dummy-cache.php' ],
		hooks: {
			dummy_cache_purge_urls: { all: false },
			dummy_cache_purge_all: { all: true },
		},
		receivers: [
			{ kind: 'hook', name: 'dummy_cache_purge_urls' },
			{ kind: 'hook', name: 'dummy_cache_purge_all' },
			{ kind: 'http', name: 'api.cdn.example' },
		],
	},
};

const BOOLEAN = [ 'real', 'spy', 'no-purge', 'stand-in', 'debug', 'help' ];
const VALUED = [ 'plugin', 'result-file', 'port', 'wp', 'php' ];

export class UsageError extends Error {}

/**
 * Read the command line.
 *
 * @param {string[]} argv Arguments after the script name.
 * @return {{plugin: string, mode: 'real'|'spy', noPurge: boolean, standIn: boolean, resultFile: string|undefined, port: string|undefined, wp: string|undefined, php: string|undefined, debug: boolean, help: boolean}} The options.
 * @throws {UsageError} When they make no sense together.
 */
export function parseArgs( argv ) {
	const opts = {};
	for ( let i = 0; i < argv.length; i++ ) {
		const match = /^--([^=]+)(?:=(.*))?$/s.exec( argv[ i ] );
		if ( ! match ) {
			throw new UsageError( `Unexpected argument ${ argv[ i ] }.` );
		}
		const [ , key, inline ] = match;
		if ( BOOLEAN.includes( key ) ) {
			if ( inline !== undefined ) {
				throw new UsageError(
					`--${ key } takes no value, and was given ${ inline }.`
				);
			}
			opts[ key ] = true;
		} else if ( VALUED.includes( key ) ) {
			const value =
				inline ??
				( argv[ i + 1 ] !== undefined &&
				! argv[ i + 1 ].startsWith( '--' )
					? argv[ ++i ]
					: undefined );
			if ( value === undefined || value === '' ) {
				throw new UsageError( `--${ key } needs a value.` );
			}
			opts[ key ] = value;
		} else {
			throw new UsageError( `Unknown option --${ key }.` );
		}
	}

	const parsed = {
		plugin: opts.plugin,
		mode: undefined,
		noPurge: opts[ 'no-purge' ] === true,
		standIn: opts[ 'stand-in' ] === true,
		resultFile: opts[ 'result-file' ],
		port: opts.port,
		wp: opts.wp,
		php: opts.php,
		debug: opts.debug === true,
		help: opts.help === true,
	};
	if ( parsed.help ) {
		return parsed;
	}

	const names = Object.keys( PLUGINS ).join( ', ' );
	if ( ! parsed.plugin ) {
		throw new UsageError( `--plugin is required: one of ${ names }.` );
	}
	const spec = PLUGINS[ parsed.plugin ];
	if ( ! spec ) {
		throw new UsageError(
			`Unknown plugin ${ parsed.plugin }. Known: ${ names }.`
		);
	}
	if ( Boolean( opts.real ) === Boolean( opts.spy ) ) {
		throw new UsageError( 'Pass exactly one of --real and --spy.' );
	}
	parsed.mode = opts.real ? 'real' : 'spy';
	if ( ! spec.modes.includes( parsed.mode ) ) {
		throw new UsageError(
			`${ spec.label } runs with --${ spec.modes.join(
				' or --'
			) }, not --${ parsed.mode }.`
		);
	}
	if ( parsed.port !== undefined && ! /^\d+$/.test( parsed.port ) ) {
		throw new UsageError( `--port takes a number, not ${ parsed.port }.` );
	}
	return parsed;
}

/**
 * The render marker in a page, or null.
 *
 * @param {string} html
 * @return {string|null} The value unique to the render that produced the page.
 */
export function renderMarker( html ) {
	return MARKER.exec( html || '' )?.[ 1 ] ?? null;
}

/**
 * Whether a page shows a number, as typed or with its brackets and hyphen
 * written as HTML entities or turned into an en dash.
 *
 * @param {string} html
 * @param {string} phone Such as (555) 555-0142.
 * @return {boolean} True when the page contains it.
 */
export function shows( html, phone ) {
	const forms = {
		'(': '(?:\\(|&#0?40;|&lpar;)',
		')': '(?:\\)|&#0?41;|&rpar;)',
		'-': '(?:-|–|&#8211;|&#x2013;|&ndash;)',
	};
	const pattern = [ ...phone ]
		.map(
			( char ) =>
				forms[ char ] ?? char.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' )
		)
		.join( '' );
	return new RegExp( `${ pattern }(?!\\d)` ).test( html || '' );
}

/**
 * The verdict of --real, from three anonymous loads of the page: two before
 * the change, one after it.
 *
 * @param {Object}                           observed
 * @param {{status: number, text: string}[]} observed.loads    The three loads.
 * @param {string}                           observed.saved    The number the settings endpoint returned after the change.
 * @param {string}                           [observed.before]
 * @param {string}                           [observed.after]
 * @return {{outcome: 'fresh'|'stale'|'unmeasured', reason: string, markers: (string|null)[]}} The verdict and why.
 */
export function realVerdict( {
	loads,
	saved,
	before = PHONE_BEFORE,
	after = PHONE_AFTER,
} ) {
	const [ first, second, third ] = loads;
	const markers = loads.map( ( load ) => renderMarker( load?.text ) );
	const verdict = ( outcome, reason ) => ( { outcome, reason, markers } );

	if ( first?.status !== 200 || ! markers[ 0 ] ) {
		return verdict(
			'unmeasured',
			`The first load answered ${ first?.status } ${
				markers[ 0 ] ? '' : 'without the harness render marker '
			}so the harness cannot tell a cached copy from a fresh one.`
		);
	}
	if ( ! shows( first.text, before ) ) {
		return verdict(
			'unmeasured',
			`The first load does not show the bound number ${ before }, so there is nothing to go stale.`
		);
	}
	if ( second?.status !== 200 || markers[ 1 ] !== markers[ 0 ] ) {
		return verdict(
			'unmeasured',
			`The second load was rendered again (marker ${ markers[ 0 ] }, then ${ markers[ 1 ] }), so the cache did not serve it, and a fresh third load would prove nothing.`
		);
	}
	if ( saved !== after ) {
		return verdict(
			'unmeasured',
			`The change did not save: the settings endpoint returned ${ saved } for the phone, not ${ after }.`
		);
	}
	if ( third?.status !== 200 || ! markers[ 2 ] ) {
		return verdict(
			'unmeasured',
			`The load after the change answered ${ third?.status } without the render marker.`
		);
	}
	if ( markers[ 2 ] === markers[ 0 ] ) {
		return verdict(
			'stale',
			`The cache served the page it stored before the change: it still shows ${ before }, not ${ after }.`
		);
	}
	if ( shows( third.text, after ) && ! shows( third.text, before ) ) {
		return verdict(
			'fresh',
			`The first request after the change was rendered again and shows ${ after }.`
		);
	}
	return verdict(
		'unmeasured',
		`The page was rendered again after the change but shows ${
			shows( third.text, before ) ? before : 'neither number'
		}, so the change did not reach the render.`
	);
}

/**
 * One URL, comparable: lowercase scheme and host, no fragment, no trailing
 * slash.
 *
 * @param {string} url
 * @return {string|null} The normalized URL, or null when it is not one.
 */
export function normalizeUrl( url ) {
	try {
		const parsed = new URL( url );
		const path = parsed.pathname.replace( /\/+$/, '' );
		return `${ parsed.protocol }//${ parsed.host }${ path }${ parsed.search }`;
	} catch {
		return null;
	}
}

/**
 * Whether a recorded purge covers the page.
 *
 * @param {Object} record A record from the harness mu-plugin.
 * @param {string} url    The page's URL.
 * @return {boolean} True when it purged everything or named the page.
 */
export function covers( record, url ) {
	if ( record.all ) {
		return true;
	}
	const want = normalizeUrl( url );
	if ( ( record.urls || [] ).some( ( u ) => normalizeUrl( u ) === want ) ) {
		return true;
	}
	if ( record.kind === 'http' ) {
		const raw = `${ record.url || '' } ${ record.body || '' }`;
		const bare = url.replace( /\/+$/, '' );
		return [ bare, bare.replace( /\//g, '\\/' ) ].some( ( form ) =>
			new RegExp(
				`${ form.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }(?![\\w-])`
			).test( raw )
		);
	}
	return false;
}

/**
 * One line naming a record, for reasons and the report.
 *
 * @param {Object} record
 * @return {string} Such as "hook dummy_cache_purge_urls (2 URLs)".
 */
export function describe( record ) {
	if ( record.all ) {
		return `${ record.kind } ${ record.name } (everything)`;
	}
	const count = ( record.urls || [] ).length;
	return `${ record.kind } ${ record.name } (${ count } URL${
		count === 1 ? '' : 's'
	}${ count ? `: ${ record.urls.join( ', ' ) }` : '' })`;
}

/**
 * The verdict of --spy, from what the harness recorded during the change.
 *
 * @param {Object}                           observed
 * @param {string}                           observed.url       The page's URL.
 * @param {Object[]}                         observed.records   What the harness mu-plugin recorded.
 * @param {Object[]}                         observed.receivers The plugin's receivers, from PLUGINS.
 * @param {{status: number, text: string}[]} observed.loads     The page before the change and after it. No cache is in front of it, so both are rendered.
 * @param {string}                           observed.saved     The number the settings endpoint returned after the change.
 * @param {string}                           [observed.before]
 * @param {string}                           [observed.after]
 * @return {{outcome: 'fresh'|'stale'|'unmeasured', reason: string, purges: Object[]}} The verdict, why, and the purges that reached the cache.
 */
export function spyVerdict( {
	url,
	records,
	receivers,
	loads,
	saved,
	before = PHONE_BEFORE,
	after = PHONE_AFTER,
} ) {
	const received = records.filter( ( record ) =>
		receivers.some(
			( receiver ) =>
				receiver.kind === record.kind && receiver.name === record.name
		)
	);
	const verdict = ( outcome, reason ) => ( {
		outcome,
		reason,
		purges: received,
	} );

	const [ first, last ] = loads;
	if ( first?.status !== 200 || ! shows( first.text, before ) ) {
		return verdict(
			'unmeasured',
			`The page answered ${ first?.status } before the change and does not show the bound number ${ before }.`
		);
	}
	if ( saved !== after ) {
		return verdict(
			'unmeasured',
			`The change did not save: the settings endpoint returned ${ saved } for the phone, not ${ after }.`
		);
	}
	if ( last?.status !== 200 || ! shows( last.text, after ) ) {
		return verdict(
			'unmeasured',
			`With no cache in front of it, the page should show ${ after } after the change, and it answered ${ last?.status } without it.`
		);
	}

	const hits = received.filter( ( record ) => covers( record, url ) );
	if ( hits.length ) {
		return verdict(
			'fresh',
			`The cache was told to purge the page: ${ hits
				.map( describe )
				.join( '; ' ) }.`
		);
	}
	const action = records.filter( ( record ) => record.kind === 'action' );
	const seen = [ ...action, ...received ].map( describe );
	return verdict(
		'stale',
		`No purge of ${ url } reached the cache. ${
			seen.length
				? `Seen: ${ seen.join( '; ' ) }.`
				: 'Nothing purged anything.'
		}`
	);
}

/**
 * The result as Markdown, for the job summary in GitHub Actions.
 *
 * @param {Object} result What scripts/cache-check.mjs writes to --result-file.
 * @return {string} A heading, the outcome and the reason.
 */
export function summaryMarkdown( result ) {
	const flagsUsed = [
		`--plugin ${ result.plugin }`,
		`--${ result.mode }`,
		result.noPurge ? '--no-purge' : '',
		result.standIn ? '--stand-in' : '',
	]
		.filter( Boolean )
		.join( ' ' );
	const lines = [
		`### npm run cache-check -- ${ flagsUsed }`,
		'',
		`**${ result.outcome }** (exit ${ result.exit }): ${ result.reason }`,
		'',
	];
	if ( result.version ) {
		lines.push( `${ result.label } ${ result.version }.`, '' );
	}
	return lines.join( '\n' );
}
