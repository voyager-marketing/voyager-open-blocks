/**
 * The pack's own blocks, read from every block.json under build/, for the
 * offline checks that run Block Runner.
 *
 * Block Runner 1.0.0 knows only the blocks of the WordPress it pins. Two of
 * its commands meet the pack's blocks in pattern markup:
 *
 * - `validate` reports a block it does not know as `unregistered-block`, a
 *   strict warning, unless a Wesper site context that lists the block reaches
 *   it (`--context`). Then the item is `info` and does not count. That is all
 *   validate reads from a context (`blocks.types[].name`), so a context of the
 *   pack's blocks changes no other result.
 * - `assemble` refuses an intent node whose block it does not know, with or
 *   without a context: "Registering custom block types remains outside the
 *   public API" (its CHANGELOG, 0.9.10). So scripts/build-patterns.mjs hands
 *   it a core block in each pack block's place and writes the pack block's own
 *   comment there afterwards.
 *
 * The context is tests/fixtures/pack.context.json, a Wesper site context
 * (site-context-v1, collector "fixture") that lists the pack's blocks and
 * nothing else. It is written from every block.json under build/: `npm run
 * patterns:build` rewrites it, and `npm run check` fails while it differs
 * from what build/ gives, so a block added to src/ and built needs no list
 * edited by hand. It is committed so the Block Check workflow can pass it to
 * Block Runner as a manifest too.
 */
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';

/** The committed site context of the pack's blocks, from the repo root. */
export const PACK_CONTEXT = 'tests/fixtures/pack.context.json';

/**
 * A fixture is not collected from a site, so its collection time is fixed:
 * the file changes only when the blocks do.
 */
const COLLECTED_AT = '1970-01-01T00:00:00Z';

/** The Wesper surfaces the context does not hold: all but site and blocks. */
const UNCOLLECTED = [
	'wordpress',
	'theme',
	'plugins',
	'bindings',
	'contentModel',
	'patterns',
	'media',
];

/**
 * Every block.json under a folder, at any depth.
 *
 * @param {string} dir Folder.
 * @return {string[]} Paths, sorted.
 */
function blockJsonFiles( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			out.push( ...blockJsonFiles( path ) );
		} else if ( entry.name === 'block.json' ) {
			out.push( path );
		}
	}
	return out.sort();
}

/**
 * The pack's blocks, one per block.json under build/.
 *
 * @param {string} root The repo root.
 * @return {{name: string, file: string, json: Object}[]} Sorted by name. Throws on a block.json that is not JSON or has no name.
 */
export function packBlocks( root ) {
	const blocks = blockJsonFiles( join( root, 'build' ) ).map( ( file ) => {
		const json = JSON.parse( readFileSync( file, 'utf8' ) );
		if ( typeof json?.name !== 'string' || ! json.name.includes( '/' ) ) {
			throw new Error( `${ relative( root, file ) } has no block name` );
		}
		return { name: json.name, file: relative( root, file ), json };
	} );
	return blocks.sort( ( a, b ) => a.name.localeCompare( b.name ) );
}

/**
 * The site context of the pack's blocks, as the text of PACK_CONTEXT: a
 * Wesper site context (contextVersion 1) whose blocks are written the way
 * `wesper collect` writes a plugin's block (name, API version, title,
 * category, attributes and supports, source "plugin"). It is partial, since
 * it lists the pack's blocks and none of core's. The source hash covers each
 * block.json's path and content.
 *
 * @param {{name: string, file: string, json: Object}[]} blocks The pack's blocks.
 * @return {string} JSON, tab-indented, with a final newline.
 */
export function packContextText( blocks ) {
	const hash = createHash( 'sha256' );
	for ( const { file, json } of blocks ) {
		hash.update(
			`${ file.split( '\\' ).join( '/' ) }\0${ JSON.stringify( json ) }\0`
		);
	}
	const types = blocks.map( ( { name, json } ) => ( {
		name,
		apiVersion: json.apiVersion ?? 1,
		title: json.title ?? name,
		category: json.category ?? null,
		attributes: json.attributes ?? {},
		supports: json.supports ?? {},
		source: 'plugin',
		parent: json.parent ?? null,
		ancestor: json.ancestor ?? null,
	} ) );
	const context = {
		contextVersion: 1,
		site: {
			name: "Voyager Open Blocks: the pack's own blocks",
			environment: 'unknown',
			isMultisite: false,
		},
		provenance: {
			collectedAt: COLLECTED_AT,
			collector: 'fixture',
			collectorVersion: 'scripts/lib/pack-blocks.mjs',
			sourceHash: `sha256:${ hash.digest( 'hex' ) }`,
			partial: true,
		},
		// Every surface but the blocks is declared unavailable, at info, so
		// `wesper validate` reads the gaps as known and exits 0.
		warnings: UNCOLLECTED.map( ( surface ) => ( {
			code: `${ surface }.absent_evidence`,
			severity: 'info',
			surface,
			message:
				"This context lists the pack's own blocks only. It was not collected from a site.",
			coverage: 'unavailable',
		} ) ),
		blocks: { types },
	};
	return `${ JSON.stringify( context, null, '\t' ) }\n`;
}

/**
 * Attributes every block can carry that block.json does not declare: the
 * ones core adds for metadata, locking and the supports the pack's blocks
 * opt into.
 */
const SUPPORT_ATTRIBUTES = new Set( [
	'metadata',
	'lock',
	'className',
	'anchor',
	'align',
	'style',
	'textColor',
	'backgroundColor',
	'fontSize',
] );

const TYPES = {
	string: ( value ) => typeof value === 'string',
	number: ( value ) => typeof value === 'number' && Number.isFinite( value ),
	integer: ( value ) => Number.isInteger( value ),
	boolean: ( value ) => typeof value === 'boolean',
	object: ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value ),
	array: ( value ) => Array.isArray( value ),
};

/**
 * What is wrong with an intent node's attributes for a pack block. WordPress
 * drops a value outside an attribute's type or enum without a word, so an
 * icon name that is not in the set renders the default star: this says so
 * at build time instead.
 *
 * @param {Object} json  The block's block.json.
 * @param {Object} attrs The node's attributes.
 * @return {string[]} One line per problem, none when the attributes fit.
 */
export function attributeProblems( json, attrs ) {
	const declared = json.attributes || {};
	const problems = [];
	for ( const [ key, value ] of Object.entries( attrs || {} ) ) {
		const schema = declared[ key ];
		if ( ! schema ) {
			if ( ! SUPPORT_ATTRIBUTES.has( key ) ) {
				problems.push(
					`"${ key }" is not an attribute of ${ json.name }`
				);
			}
			continue;
		}
		if ( schema.source !== undefined ) {
			problems.push(
				`"${ key }" is read from saved HTML, which ${ json.name } does not save`
			);
		}
		const types = [ schema.type ].flat().filter( Boolean );
		if (
			types.length &&
			! types.some( ( type ) => TYPES[ type ]?.( value ) )
		) {
			problems.push(
				`"${ key }" is ${ JSON.stringify( value ) }, not a ${ types.join( ' or ' ) }`
			);
		}
		if ( Array.isArray( schema.enum ) && ! schema.enum.includes( value ) ) {
			problems.push(
				`"${ key }" is ${ JSON.stringify( value ) }, which is not one of ${ schema.enum
					.map( ( item ) => JSON.stringify( item ) )
					.join( ', ' ) }`
			);
		}
	}
	return problems;
}

/**
 * A block comment's attributes, written as serializeAttributes() in
 * `@wordpress/blocks` 17.0.0 (src/api/serializer.tsx) writes them, so the
 * markup reads the same as the editor's.
 *
 * @param {Object} attrs Attributes.
 * @return {string} JSON safe to put inside an HTML comment.
 */
function serializeAttributes( attrs ) {
	return JSON.stringify( attrs )
		.replaceAll( '\\\\', '\\u005c' )
		.replaceAll( '--', '\\u002d\\u002d' )
		.replaceAll( '<', '\\u003c' )
		.replaceAll( '>', '\\u003e' )
		.replaceAll( '&', '\\u0026' )
		.replaceAll( '\\"', '\\u0022' );
}

/**
 * A pack block's markup: a dynamic block saves its comment and nothing else.
 * As getCommentAttributes() in the same file does, the attributes block.json
 * declares come first in its order, and one equal to its default, read from
 * saved HTML (`source`) or local to the editor (`role: local`) is left out.
 *
 * @param {Object} json  The block's block.json.
 * @param {Object} attrs The node's attributes.
 * @return {string} `<!-- wp:<name> {...} /-->`.
 */
export function packBlockMarkup( json, attrs = {} ) {
	const declared = json.attributes || {};
	const ordered = {};
	for ( const [ key, schema ] of Object.entries( declared ) ) {
		if (
			! Object.hasOwn( attrs, key ) ||
			schema.source !== undefined ||
			schema.role === 'local'
		) {
			continue;
		}
		if (
			Object.hasOwn( schema, 'default' ) &&
			JSON.stringify( schema.default ) === JSON.stringify( attrs[ key ] )
		) {
			continue;
		}
		ordered[ key ] = attrs[ key ];
	}
	for ( const [ key, value ] of Object.entries( attrs ) ) {
		if ( ! Object.hasOwn( declared, key ) ) {
			ordered[ key ] = value;
		}
	}
	const comment = Object.keys( ordered ).length
		? ` ${ serializeAttributes( ordered ) }`
		: '';
	return `<!-- wp:${ json.name }${ comment } /-->`;
}

/** The core block that stands in for a pack block while Block Runner assembles. */
const STAND_IN = 'core/latest-posts';

/**
 * Put a stand-in in each pack block's place in an intent tree, for
 * `block-runner assemble`, which refuses a block it does not know.
 *
 * The stand-in is a dynamic core block, which saves its comment alone, as a
 * pack block does, labelled with a marker no intent uses. restorePackBlocks()
 * swaps each one's comment for the pack block's own.
 *
 * @param {Object[]}            nodes  Intent nodes.
 * @param {Map<string, Object>} byName Pack block name => its block.json.
 * @return {{nodes: Object[], placed: {marker: string, markup: string}[], problems: string[]}} The tree to assemble, what goes back where, and anything wrong with a pack node.
 */
export function standInPackBlocks( nodes, byName ) {
	const placed = [];
	const problems = [];
	const walk = ( list, path ) =>
		( list || [] ).map( ( node, index ) => {
			const at = `${ path }[${ index }]`;
			const json = byName.get( node?.block );
			if ( ! json ) {
				return node?.children
					? {
							...node,
							children: walk( node.children, `${ at }.children` ),
						}
					: node;
			}
			if ( node.children?.length || node.text !== undefined ) {
				problems.push(
					`${ at }: ${ node.block } is dynamic and saves no content, so it takes no children or text`
				);
			}
			for ( const problem of attributeProblems( json, node.attrs ) ) {
				problems.push( `${ at }: ${ problem }` );
			}
			const marker = `voyager-open-pack-block-${ placed.length }`;
			placed.push( {
				marker,
				markup: packBlockMarkup( json, node.attrs ),
			} );
			return { block: STAND_IN, attrs: { metadata: { name: marker } } };
		} );
	return { nodes: walk( nodes, 'blocks' ), placed, problems };
}

/**
 * Swap each stand-in's comment in assembled markup for its pack block's.
 *
 * @param {string}                             markup Assembled markup.
 * @param {{marker: string, markup: string}[]} placed What standInPackBlocks() put in.
 * @return {{markup: string, problems: string[]}} The markup, and a line for each stand-in not found exactly once.
 */
export function restorePackBlocks( markup, placed ) {
	const problems = [];
	let out = markup;
	for ( const { marker, markup: block } of placed ) {
		const comment = `<!-- wp:${ STAND_IN.replace( /^core\//, '' ) } ${ serializeAttributes(
			{ metadata: { name: marker } }
		) } /-->`;
		const count = out.split( comment ).length - 1;
		if ( count !== 1 ) {
			problems.push(
				`the stand-in for ${ block } is in the assembled markup ${ count } times, not once`
			);
			continue;
		}
		out = out.replace( comment, () => block );
	}
	return { markup: out, problems };
}
