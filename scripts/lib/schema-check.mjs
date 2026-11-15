/**
 * What npm run check:schema decides, apart from the site it boots: reading
 * JSON-LD out of a page, reading the schema.org vocabulary, checking a node
 * against it, and turning the checks into an exit code.
 *
 * Nothing here needs WordPress, so scripts/test-schema-check.mjs runs it with
 * known answers, and npm run check runs that.
 */
import { createHash } from 'node:crypto';

/**
 * The schema.org vocabulary the check reads, pinned to one release. The file
 * is downloaded on each run and must match the sha256 here; a mismatch is a
 * run that could not tell, never a pass.
 */
export const VOCABULARY = {
	release: '30.1',
	sha256: '0a4b8c4c910fc831ec8695196510973390906a5ee34dc6b862dfec4d21a12f74',
	urls: [
		'https://schema.org/version/30.1/schemaorg-current-https.jsonld',
		'https://raw.githubusercontent.com/schemaorg/schemaorg/v30.1/data/releases/30.1/schemaorg-current-https.jsonld',
	],
};

/** The class on the pack's script tag (SCRIPT_CLASS in inc/structured-data.php). */
export const PACK_CLASS = 'voyager-open-blocks-structured-data';

/**
 * The SEO plugins the pack stands aside for, each installed from WordPress.org
 * at the version its source was read at, and the class it puts on its script
 * tag: Rank Math's JsonLD::json_ld() (includes/modules/schema/class-jsonld.php),
 * Yoast SEO's Schema_Presenter::present() (src/presenters/schema-presenter.php)
 * and AIOSEO's app/Common/Views/main/schema.php. Yoast Local SEO is not on
 * WordPress.org, so only the unit tests cover it.
 */
export const SEO_PLUGINS = {
	'rank-math': {
		label: 'Rank Math',
		slug: 'seo-by-rank-math',
		version: '1.0.282',
		file: 'seo-by-rank-math/rank-math.php',
		scriptClass: 'rank-math-schema',
		observer: 'rank_math/schema/validated_data',
		callback: 'observe_rank_math',
	},
	yoast: {
		label: 'Yoast SEO',
		slug: 'wordpress-seo',
		version: '28.8',
		file: 'wordpress-seo/wp-seo.php',
		scriptClass: 'yoast-schema-graph',
		observer: 'wpseo_schema_graph',
		callback: 'observe_yoast',
	},
	aioseo: {
		label: 'All in One SEO',
		slug: 'all-in-one-seo-pack',
		version: '5.0.5.1',
		file: 'all-in-one-seo-pack/all_in_one_seo_pack.php',
		scriptClass: 'aioseo-schema',
		observer: 'aioseo_schema_output',
		callback: 'observe_aioseo',
	},
};

/** The class on the second node --plant prints, and the property it adds. */
export const PLANT_CLASS = 'voyager-open-schema-check-plant';
export const PLANTED_PROPERTY = 'voyagerOpenPlantedProperty';

/** The line a --plant run prints when every check failed on its own plant alone. */
export const PLANT_LINE =
	'Planted run: every check failed on its plant, and nothing else failed.';

/** The checks, in the order they run and print. */
export const CHECKS = [
	'one-node',
	'properties',
	'site-data',
	'other-page',
	...Object.keys( SEO_PLUGINS ),
];

/**
 * What --plant does to each check, and how a problem is known to come from
 * the plant: the second node is named by its class, the undefined property by
 * its name, the dropped phone by `telephone`, and the pack's node, printed
 * beside an SEO plugin's once the pack no longer hears that plugin, by its
 * class.
 */
export const PLANTED = {
	'one-node': PLANT_CLASS,
	properties: PLANTED_PROPERTY,
	'site-data': 'telephone',
	'other-page': PLANT_CLASS,
	...Object.fromEntries(
		Object.keys( SEO_PLUGINS ).map( ( check ) => [ check, PACK_CLASS ] )
	),
};

/** Placeholder Site Data: the option values the check sets. */
export const SITE_TITLE = 'Example Plumbing';
export const SITE_DATA = {
	voyager_site_phone: '(555) 555-0100',
	voyager_site_email: 'hello@example.com',
	voyager_site_address: '100 Main Street, Suite 200',
	voyager_site_address_city: 'Springfield',
	voyager_site_address_state: 'ST',
	voyager_site_address_zip: '00000',
	voyager_site_hours: 'Monday to Friday, 8am to 5pm',
	voyager_site_social_facebook: 'https://facebook.com/example',
	voyager_site_social_instagram: 'https://instagram.com/example',
	voyager_site_social_linkedin: 'https://www.linkedin.com/company/example',
	voyager_site_social_x: 'https://x.com/example',
	voyager_site_service_area: 'Springfield\nShelbyville',
	voyager_site_credentials: 'Licensed and insured',
	voyager_site_founded: '1998',
	voyager_site_license: 'PL-000123',
	voyager_site_rating: '4.8',
	voyager_site_review_count: '127',
};

/** What the node must not state, whatever Site Data holds. */
export const NEVER = [
	'openingHours',
	'openingHoursSpecification',
	'aggregateRating',
	'review',
	'hasCredential',
];

/**
 * The properties the pack's node must carry for SITE_DATA.
 *
 * @param {string} home The home URL, with its trailing slash.
 * @return {Object} Property => value.
 */
export function expectedNode( home ) {
	return {
		name: SITE_TITLE,
		url: home,
		telephone: SITE_DATA.voyager_site_phone,
		email: SITE_DATA.voyager_site_email,
		address: {
			'@type': 'PostalAddress',
			streetAddress: SITE_DATA.voyager_site_address,
			addressLocality: SITE_DATA.voyager_site_address_city,
			addressRegion: SITE_DATA.voyager_site_address_state,
			postalCode: SITE_DATA.voyager_site_address_zip,
		},
		sameAs: [
			SITE_DATA.voyager_site_social_facebook,
			SITE_DATA.voyager_site_social_instagram,
			SITE_DATA.voyager_site_social_linkedin,
			SITE_DATA.voyager_site_social_x,
		],
		areaServed: SITE_DATA.voyager_site_service_area.split( '\n' ),
		foundingDate: SITE_DATA.voyager_site_founded,
	};
}

/** A run that could not tell: a download, a setup or a page it could not trust. */
export class Unmeasured extends Error {}

/**
 * @param {string} text
 * @return {string} Its sha256, in hex.
 */
export function sha256( text ) {
	return createHash( 'sha256' ).update( text ).digest( 'hex' );
}

/**
 * @param {string} name A type or property: `LocalBusiness`, `schema:LocalBusiness` or `https://schema.org/LocalBusiness`.
 * @return {string} The bare name.
 */
export function bare( name ) {
	return String( name ).replace(
		/^(?:https?:\/\/schema\.org\/|schema:)/,
		''
	);
}

/**
 * @param {unknown} value A JSON-LD value: one item, a list or nothing.
 * @return {Array} A list.
 */
function list( value ) {
	if ( value === undefined || value === null ) {
		return [];
	}
	return Array.isArray( value ) ? value : [ value ];
}

/**
 * Read the vocabulary file, after checking it is the pinned one.
 *
 * @param {string} text   The file's text.
 * @param {string} [hash] The sha256 it must have.
 * @return {{release: string, classes: Map<string, string[]>, properties: Map<string, string[]>}} Each class's parents, and each property's domain.
 */
export function readVocabulary( text, hash = VOCABULARY.sha256 ) {
	const actual = sha256( text );
	if ( actual !== hash ) {
		throw new Unmeasured(
			`The schema.org ${ VOCABULARY.release } vocabulary has sha256 ${ actual }, and the check pins ${ hash }.`
		);
	}
	const graph = JSON.parse( text )[ '@graph' ];
	if ( ! Array.isArray( graph ) ) {
		throw new Unmeasured( 'The vocabulary file has no @graph.' );
	}
	const classes = new Map();
	const properties = new Map();
	for ( const term of graph ) {
		const types = list( term[ '@type' ] );
		const name = bare( term[ '@id' ] );
		if ( types.includes( 'rdfs:Class' ) ) {
			classes.set(
				name,
				list( term[ 'rdfs:subClassOf' ] ).map( ( parent ) =>
					bare( parent[ '@id' ] )
				)
			);
		}
		if ( types.includes( 'rdf:Property' ) ) {
			properties.set(
				name,
				list( term[ 'schema:domainIncludes' ] ).map( ( domain ) =>
					bare( domain[ '@id' ] )
				)
			);
		}
	}
	if ( ! classes.has( 'LocalBusiness' ) || ! properties.has( 'telephone' ) ) {
		throw new Unmeasured(
			'The vocabulary file names no LocalBusiness or no telephone.'
		);
	}
	return { release: VOCABULARY.release, classes, properties };
}

/**
 * A type and every type above it, or null for a type the vocabulary does not have.
 *
 * @param {Object} vocabulary From readVocabulary().
 * @param {string} type       A bare type name.
 * @return {Set<string>|null} The type and its ancestors.
 */
export function lineage( vocabulary, type ) {
	if ( ! vocabulary.classes.has( type ) ) {
		return null;
	}
	const seen = new Set();
	const queue = [ type ];
	while ( queue.length ) {
		const next = queue.shift();
		if ( seen.has( next ) ) {
			continue;
		}
		seen.add( next );
		queue.push( ...( vocabulary.classes.get( next ) || [] ) );
	}
	return seen;
}

/**
 * @param {Object} node A JSON-LD node.
 * @return {string[]} Its bare type names.
 */
export function typesOf( node ) {
	return list( node?.[ '@type' ] ).map( bare );
}

/**
 * Whether a node is of the type, or of a type below it.
 *
 * @param {Object} vocabulary From readVocabulary().
 * @param {Object} node       A JSON-LD node.
 * @param {string} base       A bare type name, such as LocalBusiness.
 * @return {boolean} True when one of its types is the base or below it.
 */
export function isA( vocabulary, node, base ) {
	return typesOf( node ).some(
		( type ) => lineage( vocabulary, type )?.has( base ) ?? false
	);
}

/**
 * Every `<script type="application/ld+json">` in a page.
 *
 * @param {string} html The page.
 * @return {Array<{className: string, text: string, data: unknown, error: string}>} Each script's class, text, and its JSON or why it does not parse.
 */
export function ldJsonScripts( html ) {
	const out = [];
	for ( const match of String( html ).matchAll(
		/<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi
	) ) {
		const attributes = match[ 1 ];
		const type = /\btype\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i.exec(
			attributes
		);
		if (
			( type?.[ 1 ] ?? type?.[ 2 ] ?? type?.[ 3 ] ?? '' )
				.trim()
				.toLowerCase() !== 'application/ld+json'
		) {
			continue;
		}
		const classAttribute =
			/\bclass\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i.exec(
				attributes
			);
		const className = (
			classAttribute?.[ 1 ] ??
			classAttribute?.[ 2 ] ??
			classAttribute?.[ 3 ] ??
			''
		).trim();
		const text = match[ 2 ];
		let data = null;
		let error = '';
		try {
			data = JSON.parse( text );
		} catch ( parseError ) {
			error = parseError.message;
		}
		out.push( { className, text, data, error } );
	}
	return out;
}

/**
 * The top-level nodes of every JSON-LD script in a page: a list is read item
 * by item, and `@graph` from inside.
 *
 * @param {string} html The page.
 * @return {{nodes: Array<{node: Object, className: string}>, broken: string[]}} The nodes, each with its script's class, and the scripts that do not parse.
 */
export function ldJsonNodes( html ) {
	const nodes = [];
	const broken = [];
	for ( const script of ldJsonScripts( html ) ) {
		if ( script.error ) {
			broken.push(
				`a JSON-LD script with class "${ script.className }" does not parse: ${ script.error }`
			);
			continue;
		}
		for ( const item of list( script.data ) ) {
			const inner =
				item && typeof item === 'object' && '@graph' in item
					? list( item[ '@graph' ] )
					: [ item ];
			for ( const node of inner ) {
				if ( node && typeof node === 'object' ) {
					nodes.push( { node, className: script.className } );
				}
			}
		}
	}
	return { nodes, broken };
}

/** JSON-LD keywords a node may carry beside its properties. */
const KEYWORDS = new Set( [ '@context', '@type', '@id', '@graph' ] );

/**
 * What in a node is not schema.org at the pinned release: an unknown type, a
 * property the vocabulary does not have, or one defined for none of the
 * node's types or the types above them. Typed nodes inside it are checked
 * too.
 *
 * @param {Object} vocabulary From readVocabulary().
 * @param {Object} node       A JSON-LD node.
 * @param {string} [path]     Where the node is, for the messages.
 * @return {string[]} One line per problem.
 */
export function propertyProblems( vocabulary, node, path = '' ) {
	const problems = [];
	const types = typesOf( node );
	const where = path || types.join( ',' ) || 'a node';
	if ( ! types.length ) {
		return [ `${ where } has no @type` ];
	}
	const lineages = [];
	for ( const type of types ) {
		const found = lineage( vocabulary, type );
		if ( found ) {
			lineages.push( found );
		} else {
			problems.push(
				`${ where }: ${ type } is not a schema.org ${ vocabulary.release } type`
			);
		}
	}
	const context = node[ '@context' ];
	if (
		context !== undefined &&
		! /^https?:\/\/schema\.org\/?$/.test( String( context ) )
	) {
		problems.push(
			`${ where }: @context is ${ JSON.stringify(
				context
			) }, not https://schema.org`
		);
	}
	for ( const [ key, value ] of Object.entries( node ) ) {
		if ( key.startsWith( '@' ) ) {
			if ( ! KEYWORDS.has( key ) ) {
				problems.push(
					`${ where }: ${ key } is not a JSON-LD keyword the check knows`
				);
			}
			continue;
		}
		const domains = vocabulary.properties.get( bare( key ) );
		if ( ! domains ) {
			problems.push(
				`${ where }.${ key } is not a schema.org ${ vocabulary.release } property`
			);
		} else if (
			lineages.length &&
			! domains.some( ( domain ) =>
				lineages.some( ( found ) => found.has( domain ) )
			)
		) {
			problems.push(
				`${ where }.${ key } is a schema.org property of ${ domains.join(
					', '
				) }, not of ${ types.join( ', ' ) } or a type above it`
			);
		}
		for ( const [ i, item ] of list( value ).entries() ) {
			if ( item && typeof item === 'object' && '@type' in item ) {
				problems.push(
					...propertyProblems(
						vocabulary,
						item,
						`${ where }.${ key }${
							Array.isArray( value ) ? `[${ i }]` : ''
						}`
					)
				);
			}
		}
	}
	return problems;
}

/**
 * @param {Object}                            vocabulary From readVocabulary().
 * @param {{node: Object, className: string}} entry      A node and its script's class.
 * @return {string} The node in a few words: its type, name and script.
 */
export function describe( vocabulary, entry ) {
	const name = entry.node.name ? ` "${ entry.node.name }"` : '';
	return `${ typesOf( entry.node ).join( ',' ) || 'untyped' }${ name } in ${
		entry.className
			? `script class "${ entry.className }"`
			: 'a script with no class'
	}`;
}

/**
 * @param {string[]} problems
 * @param {string}   ok       The detail when there are none.
 * @return {{status: string, problems: string[], detail: string}} A check's result.
 */
function result( problems, ok ) {
	return problems.length
		? { status: 'fail', problems, detail: problems.join( '; ' ) }
		: { status: 'pass', problems: [], detail: ok };
}

/**
 * @param {unknown} a
 * @param {unknown} b
 * @return {boolean} Whether two JSON values are the same.
 */
function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * The checks on the front page and another published page, with the pack alone.
 *
 * @param {Object} input
 * @param {Object} input.vocabulary From readVocabulary().
 * @param {string} input.front      The front page's HTML.
 * @param {string} input.other      Another published page's HTML.
 * @param {string} input.home       The home URL, with its trailing slash.
 * @return {Object<string, {status: string, problems: string[], detail: string}>} one-node, properties, site-data and other-page.
 */
export function packChecks( { vocabulary, front, other, home } ) {
	const page = ldJsonNodes( front );
	const businesses = page.nodes.filter( ( entry ) =>
		isA( vocabulary, entry.node, 'LocalBusiness' )
	);
	const ours = businesses.filter(
		( entry ) => entry.className === PACK_CLASS
	);

	const oneNode = [ ...page.broken ];
	if ( businesses.length !== 1 || ours.length !== 1 ) {
		oneNode.push(
			`the front page holds ${ businesses.length } LocalBusiness nodes, ${
				ours.length
			} from the pack${
				businesses.length
					? `: ${ businesses
							.map( ( entry ) => describe( vocabulary, entry ) )
							.join( '; ' ) }`
					: ''
			}`
		);
	}

	const properties = page.nodes.flatMap( ( entry ) =>
		propertyProblems( vocabulary, entry.node )
	);

	const siteData = [];
	const node = ours[ 0 ]?.node;
	if ( ! node ) {
		siteData.push( 'the pack printed no LocalBusiness node' );
	} else {
		for ( const [ key, value ] of Object.entries( expectedNode( home ) ) ) {
			if ( ! ( key in node ) ) {
				siteData.push( `${ key } is missing` );
			} else if ( ! same( node[ key ], value ) ) {
				siteData.push(
					`${ key } is ${ JSON.stringify(
						node[ key ]
					) }, and Site Data gives ${ JSON.stringify( value ) }`
				);
			}
		}
		for ( const key of NEVER ) {
			if ( key in node ) {
				siteData.push( `${ key } is stated, and must never be` );
			}
		}
	}

	const elsewhere = ldJsonNodes( other );
	const strays = elsewhere.nodes.filter(
		( entry ) =>
			entry.className === PACK_CLASS ||
			isA( vocabulary, entry.node, 'LocalBusiness' )
	);
	const otherPage = [ ...elsewhere.broken ];
	if ( strays.length ) {
		otherPage.push(
			`another published page holds ${ strays.length } node${
				strays.length === 1 ? '' : 's'
			} from the pack or of type LocalBusiness: ${ strays
				.map( ( entry ) => describe( vocabulary, entry ) )
				.join( '; ' ) }`
		);
	}

	return {
		'one-node': result(
			oneNode,
			`one LocalBusiness node, the pack's: ${
				ours[ 0 ] ? describe( vocabulary, ours[ 0 ] ) : ''
			}`
		),
		properties: result(
			properties,
			`every property of the ${ page.nodes.length } node${
				page.nodes.length === 1 ? '' : 's'
			} on the front page is schema.org ${ vocabulary.release } for its type`
		),
		'site-data': result(
			siteData,
			`the node carries ${ Object.keys( expectedNode( home ) ).join(
				', '
			) } from Site Data, and none of ${ NEVER.join( ', ' ) }`
		),
		'other-page': result(
			otherPage,
			`another published page holds no LocalBusiness node and nothing from the pack (${ elsewhere.nodes.length } JSON-LD nodes there)`
		),
	};
}

/**
 * The check on the front page with an SEO plugin set up to print the site's
 * business: one LocalBusiness or Organization node, the plugin's, and nothing
 * from the pack.
 *
 * @param {Object} input
 * @param {Object} input.vocabulary From readVocabulary().
 * @param {string} input.front      The front page's HTML.
 * @param {Object} input.plugin     A row of SEO_PLUGINS.
 * @return {{status: string, problems: string[], detail: string}} The result. Unmeasured when the plugin printed no such node, since then nothing tested whether the pack stands aside.
 */
export function seoCheck( { vocabulary, front, plugin } ) {
	const page = ldJsonNodes( front );
	const entities = page.nodes.filter(
		( entry ) =>
			isA( vocabulary, entry.node, 'LocalBusiness' ) ||
			isA( vocabulary, entry.node, 'Organization' )
	);
	const theirs = entities.filter( ( entry ) =>
		entry.className.split( /\s+/ ).includes( plugin.scriptClass )
	);
	if ( ! theirs.length ) {
		return {
			status: 'unmeasured',
			problems: [],
			detail: `${ plugin.label } printed no LocalBusiness or Organization node, so nothing tested whether the pack stands aside (${ entities.length } such nodes on the page, ${ page.nodes.length } nodes in all)`,
		};
	}
	const problems = [ ...page.broken ];
	const pack = page.nodes.filter(
		( entry ) => entry.className === PACK_CLASS
	);
	if ( entities.length !== 1 || pack.length ) {
		problems.push(
			`the front page holds ${ entities.length } LocalBusiness or Organization nodes, ${
				theirs.length
			} from ${ plugin.label }, and ${ pack.length } node${
				pack.length === 1 ? '' : 's'
			} from the pack: ${ [ ...new Set( [ ...entities, ...pack ] ) ]
				.map( ( entry ) => describe( vocabulary, entry ) )
				.join( '; ' ) }`
		);
	}
	return result(
		problems,
		`one node, ${ plugin.label }'s: ${ describe( vocabulary, theirs[ 0 ] ) }`
	);
}

/**
 * Whether a check did what --plant asks of it: failed, on its plant alone.
 *
 * @param {string}                               check A name from CHECKS.
 * @param {{status: string, problems: string[]}} found The check's result.
 * @return {boolean} True when it failed and every problem names its plant.
 */
export function caughtItsPlant( check, found ) {
	return (
		found.status === 'fail' &&
		found.problems.length > 0 &&
		found.problems.every( ( problem ) =>
			problem.includes( PLANTED[ check ] )
		)
	);
}

/**
 * Print each check and decide the exit code.
 *
 * Without --plant: 0 when every check passed, 1 when one failed, 2 when one
 * could not tell and none failed. With --plant every check must fail on its
 * own plant alone: then the run prints PLANT_LINE and exits 1. A planted run
 * that could not tell exits 2, and one where a check passed, or failed on
 * something else, exits 3.
 *
 * @param {Object<string, {status: string, problems: string[], detail: string}>} results Check => result.
 * @param {boolean}                                                              plant   Whether --plant was given.
 * @param {(line: string, ok: boolean) => void}                                  print   Prints a line.
 * @return {number} The exit code.
 */
export function verdict( results, plant, print ) {
	let failed = 0;
	let unmeasured = 0;
	let missed = 0;
	for ( const check of CHECKS ) {
		const found = results[ check ] || {
			status: 'unmeasured',
			problems: [],
			detail: 'not measured',
		};
		if ( found.status === 'unmeasured' ) {
			unmeasured++;
		} else if ( found.status === 'fail' ) {
			failed++;
		}
		if ( plant ) {
			const caught = caughtItsPlant( check, found );
			if ( ! caught && found.status !== 'unmeasured' ) {
				missed++;
			}
			print(
				`${ check }: ${ found.status }, expected fail on its plant (${ PLANTED[ check ] })${
					found.status === 'fail' && ! caught
						? ', and it failed on something else too'
						: ''
				}: ${ found.detail }`,
				caught
			);
		} else {
			print(
				`${ check }: ${ found.status }: ${ found.detail }`,
				found.status === 'pass'
			);
		}
	}
	if ( ! plant ) {
		if ( failed ) {
			return 1;
		}
		return unmeasured ? 2 : 0;
	}
	if ( unmeasured ) {
		return 2;
	}
	if ( missed ) {
		return 3;
	}
	print( PLANT_LINE, true );
	return 1;
}
