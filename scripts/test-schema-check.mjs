/**
 * Known-answer test for what npm run check:schema decides.
 *
 *   node --test scripts/test-schema-check.mjs
 *
 * The check itself needs Playground and the schema.org vocabulary, so CI runs
 * it (.github/workflows/e2e.yml). Reading JSON-LD out of a page, checking a
 * node against a vocabulary and turning the checks into an exit code need
 * neither, and npm run check runs this file. The vocabulary here is a small
 * one in the real file's shape. It also plants crashes in run(), which must
 * never exit 0 or 1, and runs the real script with --plant crash, which must
 * exit 2.
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

// run() writes annotations in GitHub Actions. They do not belong in CI's log
// from a test, so they are off before it is imported.
process.env.GITHUB_ACTIONS = 'false';
const lib = await import( './lib/schema-check.mjs' );
const { run } = await import( './schema-check.mjs' );
const {
	CHECKS,
	PACK_CLASS,
	PLANT_CLASS,
	PLANT_LINE,
	PLANTED_PROPERTY,
	SEO_PLUGINS,
	Unmeasured,
	expectedNode,
	ldJsonNodes,
	ldJsonScripts,
	packChecks,
	propertyProblems,
	seoCheck,
	readVocabulary,
	sha256,
	verdict,
} = lib;

const RANK_MATH = SEO_PLUGINS[ 'rank-math' ];
const RANK_MATH_CLASS = RANK_MATH.scriptClass;

const script = fileURLToPath(
	new URL( './schema-check.mjs', import.meta.url )
);

const HOME = 'https://example.com/';

/**
 * A class term.
 *
 * @param {string}   name
 * @param {string[]} parents
 * @return {Object} The term, as the vocabulary file writes it.
 */
function type( name, parents = [] ) {
	const term = { '@id': `schema:${ name }`, '@type': 'rdfs:Class' };
	if ( parents.length ) {
		term[ 'rdfs:subClassOf' ] =
			parents.length === 1
				? { '@id': `schema:${ parents[ 0 ] }` }
				: parents.map( ( parent ) => ( {
						'@id': `schema:${ parent }`,
					} ) );
	}
	return term;
}

/**
 * A property term.
 *
 * @param {string}   name
 * @param {string[]} domains
 * @return {Object} The term.
 */
function property( name, domains ) {
	return {
		'@id': `schema:${ name }`,
		'@type': 'rdf:Property',
		'schema:domainIncludes':
			domains.length === 1
				? { '@id': `schema:${ domains[ 0 ] }` }
				: domains.map( ( domain ) => ( {
						'@id': `schema:${ domain }`,
					} ) ),
	};
}

/** A small vocabulary, in the shape of schemaorg-current-https.jsonld. */
const VOCAB_TEXT = JSON.stringify( {
	'@context': { schema: 'https://schema.org/' },
	'@graph': [
		type( 'Thing' ),
		type( 'Organization', [ 'Thing' ] ),
		type( 'Place', [ 'Thing' ] ),
		type( 'Person', [ 'Thing' ] ),
		type( 'LocalBusiness', [ 'Organization', 'Place' ] ),
		type( 'HomeAndConstructionBusiness', [ 'LocalBusiness' ] ),
		type( 'Plumber', [ 'HomeAndConstructionBusiness' ] ),
		type( 'Intangible', [ 'Thing' ] ),
		type( 'StructuredValue', [ 'Intangible' ] ),
		type( 'ContactPoint', [ 'StructuredValue' ] ),
		type( 'PostalAddress', [ 'ContactPoint' ] ),
		type( 'CreativeWork', [ 'Thing' ] ),
		type( 'WebSite', [ 'CreativeWork' ] ),
		{ '@id': 'schema:Monday', '@type': 'schema:DayOfWeek' },
		property( 'name', [ 'Thing' ] ),
		property( 'url', [ 'Thing' ] ),
		property( 'sameAs', [ 'Thing' ] ),
		property( 'telephone', [
			'ContactPoint',
			'Organization',
			'Person',
			'Place',
		] ),
		property( 'email', [ 'ContactPoint', 'Organization', 'Person' ] ),
		property( 'address', [ 'Organization', 'Person', 'Place' ] ),
		property( 'areaServed', [ 'ContactPoint', 'Organization' ] ),
		property( 'foundingDate', [ 'Organization' ] ),
		property( 'openingHours', [ 'LocalBusiness' ] ),
		property( 'streetAddress', [ 'PostalAddress' ] ),
		property( 'addressLocality', [ 'PostalAddress' ] ),
		property( 'addressRegion', [ 'PostalAddress' ] ),
		property( 'postalCode', [ 'PostalAddress' ] ),
		property( 'publisher', [ 'CreativeWork' ] ),
	],
} );
const vocabulary = readVocabulary( VOCAB_TEXT, sha256( VOCAB_TEXT ) );

/** The pack's node for the placeholder Site Data. */
const PACK_NODE = {
	'@context': 'https://schema.org',
	'@type': 'LocalBusiness',
	'@id': `${ HOME }#localbusiness`,
	...expectedNode( HOME ),
};

/**
 * A JSON-LD script tag.
 *
 * @param {unknown} data
 * @param {string}  className
 * @return {string} The tag.
 */
function tag( data, className = PACK_CLASS ) {
	return `<script type="application/ld+json" class="${ className }">${ JSON.stringify(
		data
	) }</script>`;
}

/**
 * A page with these tags in its head.
 *
 * @param {...string} tags
 * @return {string} HTML.
 */
function page( ...tags ) {
	return `<!doctype html><html><head><title>x</title><script>var a = 1;</script>${ tags.join(
		'\n'
	) }</head><body><p>Hi</p></body></html>`;
}

const PLANTED_NODE = {
	'@context': 'https://schema.org',
	'@type': 'LocalBusiness',
	name: 'Planted second node',
	telephone: '(555) 555-0199',
};

const RANK_MATH_GRAPH = {
	'@context': 'https://schema.org',
	'@graph': [
		{
			'@type': 'Plumber',
			'@id': `${ HOME }#organization`,
			name: 'Example Plumbing',
			url: 'https://example.com',
		},
		{
			'@type': 'WebSite',
			'@id': `${ HOME }#website`,
			publisher: { '@id': `${ HOME }#organization` },
		},
	],
};

/**
 * @param {string}   status   pass, fail or unmeasured.
 * @param {string[]} problems
 * @return {Object} A check result.
 */
function found( status, problems = [] ) {
	return { status, problems, detail: problems.join( '; ' ) || status };
}

/**
 * verdict() with its lines collected.
 *
 * @param {Object}  results
 * @param {boolean} plant
 * @return {{code: number, lines: string[]}} The exit code and what it printed.
 */
function decide( results, plant ) {
	const lines = [];
	const code = verdict( results, plant, ( line ) => lines.push( line ) );
	return { code, lines };
}

const ALL_PASS = Object.fromEntries(
	CHECKS.map( ( check ) => [ check, found( 'pass' ) ] )
);

/** Each check failing on its own plant alone, as --plant must make them. */
const ALL_CAUGHT = {
	'one-node': found( 'fail', [ `2 nodes, one in "${ PLANT_CLASS }"` ] ),
	properties: found( 'fail', [
		`LocalBusiness.${ PLANTED_PROPERTY } is not a property`,
	] ),
	'site-data': found( 'fail', [ 'telephone is missing' ] ),
	'other-page': found( 'fail', [ `a node in "${ PLANT_CLASS }"` ] ),
	...Object.fromEntries(
		Object.keys( SEO_PLUGINS ).map( ( check ) => [
			check,
			found( 'fail', [ `a node in "${ PACK_CLASS }"` ] ),
		] )
	),
};

/**
 * run() with its lines held back, so the test log stays readable.
 *
 * @param {string[]} argv
 * @param {Object}   steps
 * @return {Promise<number>} The exit code.
 */
async function quiet( argv, steps ) {
	const log = console.log;
	const error = console.error;
	console.log = () => {};
	console.error = () => {};
	try {
		return await run( argv, { print: () => {}, ...steps } );
	} finally {
		console.log = log;
		console.error = error;
	}
}

test( 'a vocabulary whose sha256 is not the pinned one is a run that could not tell', () => {
	assert.throws(
		() => readVocabulary( VOCAB_TEXT, '0'.repeat( 64 ) ),
		( error ) =>
			error instanceof Unmeasured && /sha256/.test( error.message )
	);
	assert.throws(
		() => readVocabulary( '{"@graph":[]}', sha256( '{"@graph":[]}' ) ),
		Unmeasured,
		'A vocabulary with no LocalBusiness proves nothing.'
	);
} );

test( 'the vocabulary gives every type its parents, enumeration members aside', () => {
	assert.deepEqual( [ ...lib.lineage( vocabulary, 'Plumber' ) ].sort(), [
		'HomeAndConstructionBusiness',
		'LocalBusiness',
		'Organization',
		'Place',
		'Plumber',
		'Thing',
	] );
	assert.equal(
		lib.lineage( vocabulary, 'Monday' ),
		null,
		'A DayOfWeek member is not a class.'
	);
	assert.equal( lib.lineage( vocabulary, 'Plumbr' ), null );
	assert.ok(
		lib.isA(
			vocabulary,
			{ '@type': 'https://schema.org/Plumber' },
			'LocalBusiness'
		)
	);
	assert.ok(
		lib.isA(
			vocabulary,
			{ '@type': [ 'Person', 'Organization' ] },
			'Organization'
		)
	);
	assert.ok(
		! lib.isA( vocabulary, { '@type': 'Place' }, 'LocalBusiness' ),
		'A parent is not a child.'
	);
} );

test( 'only ld+json scripts are read, quoted either way, and one that does not parse is named', () => {
	const html = `<script>var x = "{}";</script>
<script type='application/ld+json' class='a'>{"@type":"Thing"}</script>
<script class="b" type="application/ld+json">[{"@type":"Thing"}]</script>
<script type="text/javascript">{"@type":"Organization"}</script>
<SCRIPT TYPE="Application/LD+JSON">{ broken</SCRIPT>`;

	const scripts = ldJsonScripts( html );
	assert.deepEqual(
		scripts.map( ( item ) => [ item.className, Boolean( item.error ) ] ),
		[
			[ 'a', false ],
			[ 'b', false ],
			[ '', true ],
		]
	);
	const { nodes, broken } = ldJsonNodes( html );
	assert.equal( nodes.length, 2 );
	assert.equal( broken.length, 1 );
} );

test( 'a @graph and a list are read node by node', () => {
	const { nodes } = ldJsonNodes(
		page(
			tag( RANK_MATH_GRAPH, RANK_MATH_CLASS ),
			tag( [ PACK_NODE, PLANTED_NODE ] )
		)
	);
	assert.deepEqual(
		nodes.map( ( entry ) => [
			[].concat( entry.node[ '@type' ] ).join(),
			entry.className,
		] ),
		[
			[ 'Plumber', RANK_MATH_CLASS ],
			[ 'WebSite', RANK_MATH_CLASS ],
			[ 'LocalBusiness', PACK_CLASS ],
			[ 'LocalBusiness', PACK_CLASS ],
		]
	);
} );

test( "the pack's node has no property that is not schema.org for its type", () => {
	assert.deepEqual( propertyProblems( vocabulary, PACK_NODE ), [] );
	assert.deepEqual(
		propertyProblems( vocabulary, { ...PACK_NODE, '@type': 'Plumber' } ),
		[],
		"A subtype takes its parents' properties."
	);
	assert.deepEqual(
		propertyProblems( vocabulary, {
			'@type': 'WebSite',
			publisher: { '@id': `${ HOME }#organization` },
		} ),
		[],
		'A reference with no @type is not checked as a node.'
	);
} );

test( 'an undefined property, one of another type, an unknown type and a bad @context each fail', () => {
	assert.deepEqual(
		propertyProblems( vocabulary, {
			...PACK_NODE,
			[ PLANTED_PROPERTY ]: 'x',
		} ),
		[
			`LocalBusiness.${ PLANTED_PROPERTY } is not a schema.org 30.1 property`,
		]
	);
	assert.match(
		propertyProblems( vocabulary, {
			'@type': 'LocalBusiness',
			streetAddress: '1 Main',
		} )[ 0 ],
		/streetAddress is a schema.org property of PostalAddress, not of LocalBusiness/
	);
	assert.match(
		propertyProblems( vocabulary, {
			'@type': 'WebSite',
			foundingDate: '1998',
		} )[ 0 ],
		/foundingDate/,
		'A property of Organization is not one of a WebSite.'
	);
	assert.match(
		propertyProblems( vocabulary, { '@type': 'Plumbr', name: 'x' } )[ 0 ],
		/Plumbr is not a schema.org 30.1 type/
	);
	assert.match(
		propertyProblems( vocabulary, { name: 'x' } )[ 0 ],
		/has no @type/
	);
	assert.match(
		propertyProblems( vocabulary, {
			...PACK_NODE,
			'@context': 'https://example.com/',
		} )[ 0 ],
		/@context/
	);
	assert.match(
		propertyProblems( vocabulary, { ...PACK_NODE, '@reverse': {} } )[ 0 ],
		/@reverse/
	);
} );

test( 'a typed node inside the node is checked too, with its path', () => {
	assert.deepEqual(
		propertyProblems( vocabulary, {
			...PACK_NODE,
			address: {
				'@type': 'PostalAddress',
				streetAddress: '1 Main',
				foundingDate: '1998',
			},
		} ),
		[
			'LocalBusiness.address.foundingDate is a schema.org property of Organization, not of PostalAddress or a type above it',
		]
	);
	assert.match(
		propertyProblems( vocabulary, {
			'@type': 'LocalBusiness',
			address: [
				{ '@type': 'PostalAddress' },
				{ '@type': 'PostalAdress' },
			],
		} )[ 0 ],
		/address\[1\]: PostalAdress is not a schema.org 30.1 type/
	);
} );

test( 'the front page with the pack alone passes every pack check', () => {
	const results = packChecks( {
		vocabulary,
		front: page( tag( PACK_NODE ) ),
		other: page(),
		home: HOME,
	} );
	for ( const check of [
		'one-node',
		'properties',
		'site-data',
		'other-page',
	] ) {
		assert.equal(
			results[ check ].status,
			'pass',
			`${ check }: ${ results[ check ].detail }`
		);
	}
} );

test( 'a second LocalBusiness node, on the front page or another, fails on that node', () => {
	const results = packChecks( {
		vocabulary,
		front: page( tag( PACK_NODE ), tag( PLANTED_NODE, PLANT_CLASS ) ),
		other: page( tag( PLANTED_NODE, PLANT_CLASS ) ),
		home: HOME,
	} );
	assert.equal( results[ 'one-node' ].status, 'fail' );
	assert.match(
		results[ 'one-node' ].detail,
		/2 LocalBusiness nodes, 1 from the pack/
	);
	assert.ok( lib.caughtItsPlant( 'one-node', results[ 'one-node' ] ) );
	assert.ok( lib.caughtItsPlant( 'other-page', results[ 'other-page' ] ) );
	assert.equal(
		results.properties.status,
		'pass',
		'The planted node is valid, so properties is not what it fails.'
	);
} );

test( 'a subtype counts as a second LocalBusiness, and no node at all fails too', () => {
	const results = packChecks( {
		vocabulary,
		front: page(
			tag( PACK_NODE ),
			tag( { '@type': 'Plumber', name: 'Other' }, 'other-plugin' )
		),
		other: page(),
		home: HOME,
	} );
	assert.equal( results[ 'one-node' ].status, 'fail' );

	const none = packChecks( {
		vocabulary,
		front: page(),
		other: page(),
		home: HOME,
	} );
	assert.equal( none[ 'one-node' ].status, 'fail' );
	assert.equal( none[ 'site-data' ].status, 'fail' );
	assert.match( none[ 'site-data' ].detail, /printed no LocalBusiness node/ );
} );

test( "the pack's node elsewhere fails other-page, whatever its type", () => {
	const results = packChecks( {
		vocabulary,
		front: page( tag( PACK_NODE ) ),
		other: page( tag( { '@type': 'Thing', name: 'x' } ) ),
		home: HOME,
	} );
	assert.equal( results[ 'other-page' ].status, 'fail' );
	assert.match( results[ 'other-page' ].detail, new RegExp( PACK_CLASS ) );
} );

test( 'a missing or wrong Site Data value, or the hours, fail site-data', () => {
	const { telephone, ...noPhone } = PACK_NODE;
	assert.ok( telephone );
	const missing = packChecks( {
		vocabulary,
		front: page( tag( noPhone ) ),
		other: page(),
		home: HOME,
	} );
	assert.deepEqual( missing[ 'site-data' ].problems, [
		'telephone is missing',
	] );

	const wrong = packChecks( {
		vocabulary,
		front: page(
			tag( { ...PACK_NODE, areaServed: 'Springfield, Shelbyville' } )
		),
		other: page(),
		home: HOME,
	} );
	assert.match(
		wrong[ 'site-data' ].detail,
		/areaServed is "Springfield, Shelbyville"/
	);

	const hours = packChecks( {
		vocabulary,
		front: page(
			tag( { ...PACK_NODE, openingHours: 'Mo-Fr 08:00-17:00' } )
		),
		other: page(),
		home: HOME,
	} );
	assert.deepEqual( hours[ 'site-data' ].problems, [
		'openingHours is stated, and must never be',
	] );
	assert.equal(
		hours.properties.status,
		'pass',
		'openingHours is schema.org for a LocalBusiness: only site-data refuses it.'
	);
} );

test( "Rank Math's node alone passes; the pack's beside it fails; no Rank Math node cannot tell", () => {
	assert.equal(
		seoCheck( {
			vocabulary,
			plugin: RANK_MATH,
			front: page( tag( RANK_MATH_GRAPH, `${ RANK_MATH_CLASS }` ) ),
		} ).status,
		'pass'
	);

	const both = seoCheck( {
		plugin: RANK_MATH,
		vocabulary,
		front: page(
			tag( RANK_MATH_GRAPH, RANK_MATH_CLASS ),
			tag( PACK_NODE )
		),
	} );
	assert.equal( both.status, 'fail' );
	assert.ok( lib.caughtItsPlant( 'rank-math', both ) );
	assert.match(
		both.detail,
		/2 LocalBusiness or Organization nodes, 1 from Rank Math, and 1 node from the pack/
	);

	assert.equal(
		seoCheck( {
			vocabulary,
			plugin: RANK_MATH,
			front: page(
				tag( { '@type': 'WebSite' }, RANK_MATH_CLASS ),
				tag( PACK_NODE )
			),
		} ).status,
		'unmeasured',
		'Rank Math printed no business, so the pack was not tested.'
	);
	assert.equal(
		seoCheck( {
			plugin: RANK_MATH,
			vocabulary,
			front: page(
				tag( RANK_MATH_GRAPH, RANK_MATH_CLASS ),
				tag( { '@type': 'Organization', name: 'Theme' }, 'theme' )
			),
		} ).status,
		'fail',
		'A second Organization from anyone fails.'
	);
} );

test( "each SEO plugin's node is known by its own script class, and only its own", () => {
	const organization = {
		'@context': 'https://schema.org',
		'@graph': [
			{
				'@type': 'Organization',
				'@id': `${ HOME }#organization`,
				name: 'Example Plumbing',
			},
		],
	};
	for ( const plugin of Object.values( SEO_PLUGINS ) ) {
		assert.equal(
			seoCheck( {
				vocabulary,
				plugin,
				front: page( tag( organization, plugin.scriptClass ) ),
			} ).status,
			'pass',
			plugin.label
		);
	}
	assert.equal(
		seoCheck( {
			vocabulary,
			plugin: SEO_PLUGINS.yoast,
			front: page( tag( organization, SEO_PLUGINS.aioseo.scriptClass ) ),
		} ).status,
		'unmeasured',
		"AIOSEO's node does not stand in for Yoast's."
	);
	assert.equal(
		seoCheck( {
			vocabulary,
			plugin: SEO_PLUGINS.aioseo,
			front: page( tag( organization, 'aioseo-schema extra' ) ),
		} ).status,
		'pass',
		'A class list is read token by token.'
	);
} );

test( 'without --plant: 0 when all pass, 1 on a failure, 2 when one could not tell', () => {
	assert.equal( decide( ALL_PASS, false ).code, 0 );
	assert.equal(
		decide( { ...ALL_PASS, properties: found( 'fail', [ 'x' ] ) }, false )
			.code,
		1
	);
	assert.equal(
		decide( { ...ALL_PASS, 'rank-math': found( 'unmeasured' ) }, false )
			.code,
		2
	);
	assert.equal(
		decide(
			{
				...ALL_PASS,
				'rank-math': found( 'unmeasured' ),
				properties: found( 'fail', [ 'x' ] ),
			},
			false
		).code,
		1
	);
	const { 'rank-math': dropped, ...missing } = ALL_PASS;
	assert.ok( dropped );
	assert.equal(
		decide( missing, false ).code,
		2,
		'A check that never ran could not tell.'
	);
} );

test( 'with --plant: exit 1 and the line only when every check fails on its own plant alone', () => {
	const caught = decide( ALL_CAUGHT, true );
	assert.equal( caught.code, 1 );
	assert.equal( caught.lines.at( -1 ), PLANT_LINE );

	for ( const check of CHECKS ) {
		const passed = decide(
			{ ...ALL_CAUGHT, [ check ]: found( 'pass' ) },
			true
		);
		assert.equal(
			passed.code,
			3,
			`${ check } passing with its plant in is a miss.`
		);
		assert.ok( ! passed.lines.includes( PLANT_LINE ) );

		const elsewhere = decide(
			{
				...ALL_CAUGHT,
				[ check ]: found( 'fail', [
					...ALL_CAUGHT[ check ].problems,
					'a fault nobody planted',
				] ),
			},
			true
		);
		assert.equal(
			elsewhere.code,
			3,
			`${ check } failing on something else too is a miss.`
		);
	}

	const unmeasured = decide(
		{ ...ALL_CAUGHT, 'rank-math': found( 'unmeasured' ) },
		true
	);
	assert.equal( unmeasured.code, 2 );
	assert.ok( ! unmeasured.lines.includes( PLANT_LINE ) );
} );

test( 'run(): a crash before or after the checks exits 2, never 0 or 1', async () => {
	const boom = () => {
		throw new Error( 'planted crash' );
	};
	assert.equal( await quiet( [], { measure: async () => boom() } ), 2 );
	assert.equal(
		await quiet( [], {
			measure: async ( opts, results ) => {
				Object.assign( results, ALL_PASS );
				boom();
			},
		} ),
		2,
		'Checks that passed before a crash do not pass the run.'
	);
	assert.equal(
		await quiet( [ '--plant' ], {
			measure: async ( opts, results ) => {
				Object.assign( results, ALL_CAUGHT );
				boom();
			},
		} ),
		2,
		'A planted run that crashed did not catch its plants.'
	);
	assert.equal(
		await quiet( [], {
			measure: async ( opts, results ) =>
				Object.assign( results, ALL_PASS ),
		} ),
		0
	);
	assert.equal(
		await quiet( [ '--plant' ], {
			measure: async ( opts, results ) =>
				Object.assign( results, ALL_CAUGHT ),
		} ),
		1
	);
} );

test( 'run(): an unknown option or a --plant value is a usage error, exit 2', async () => {
	const never = async () => assert.fail( 'measure() ran on a usage error' );
	assert.equal( await quiet( [ '--plnt' ], { measure: never } ), 2 );
	assert.equal(
		await quiet( [ '--plant', 'everything' ], { measure: never } ),
		2
	);
	assert.equal( await quiet( [ 'stray' ], { measure: never } ), 2 );
	assert.equal( await quiet( [ '--help' ], { measure: never } ), 0 );
} );

test( 'the script itself, with --plant crash, exits 2', () => {
	const result = spawnSync(
		process.execPath,
		[ script, '--plant', 'crash' ],
		{
			encoding: 'utf8',
			env: { ...process.env, GITHUB_ACTIONS: 'false' },
		}
	);
	assert.equal( result.status, 2, `${ result.stdout }${ result.stderr }` );
	assert.match( `${ result.stdout }${ result.stderr }`, /planted crash/ );
} );
