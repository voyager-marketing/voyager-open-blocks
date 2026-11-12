#!/usr/bin/env node
/**
 * Assemble every pattern from its intent with Block Runner.
 *
 * patterns/intents/<name>.json -> `block-runner assemble` -> patterns/<name>.html
 *
 * Fails on any invalid block and on any dropped attribute (an attribute
 * Gutenberg discarded means the intent asked for something the block cannot
 * hold). The only report item it tolerates is the noop media resolver note:
 * pattern images are placeholders with no attachment ID by design.
 *
 * Also fails when two blocks in one pattern share a `metadata.name`: the
 * insert ability's `copy` input finds blocks by that label.
 *
 * The pack's own blocks, such as voyager-open/icon, are read from every
 * block.json under build/ (scripts/lib/pack-blocks.mjs). Block Runner cannot
 * assemble a block it does not know, so a core block stands in for each one
 * while it assembles, and the pack block's own comment goes back in its place
 * after. A pack block's attributes must fit its block.json, an icon name from
 * the set included. A pattern that holds one is then validated again with
 * `block-runner validate --strict --context`, given the site context that
 * lists the pack's blocks, so the markup written is the markup checked. That
 * context, tests/fixtures/pack.context.json, is written here from build/ too,
 * and --check fails while the committed one differs.
 *
 *   npm run patterns:build            write every pattern
 *   npm run patterns:build -- --check fail if any written file is stale
 */
import { execFileSync } from 'node:child_process';
import {
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
	existsSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	PACK_CONTEXT,
	packBlocks,
	packContextText,
	restorePackBlocks,
	standInPackBlocks,
} from './lib/pack-blocks.mjs';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const cli = join( root, 'node_modules', 'block-runner', 'dist', 'cli.js' );
const intents = join( root, 'patterns', 'intents' );
const check = process.argv.includes( '--check' );
const tolerated = ( item ) =>
	/noop resolver does not resolve media IDs/.test( item.reason || '' );

/**
 * Labels (`metadata.name`) used more than once in an intent tree.
 *
 * @param {Object[]} nodes Intent nodes.
 * @return {string[]} Repeated labels.
 */
function repeatedLabels( nodes ) {
	const seen = new Set();
	const repeated = new Set();
	const walk = ( list ) => {
		for ( const node of list || [] ) {
			const label = node.attrs?.metadata?.name;
			if ( label ) {
				( seen.has( label ) ? repeated : seen ).add( label );
			}
			walk( node.children );
		}
	};
	walk( nodes );
	return [ ...repeated ];
}

const scratch = mkdtempSync( join( tmpdir(), 'voyager-open-patterns-' ) );
let failed = 0;

// The site context of the pack's blocks, written from build/ so it is never
// out of date here; --check fails while the committed one is.
const pack = packBlocks( root );
const byName = new Map( pack.map( ( block ) => [ block.name, block.json ] ) );
const context = join( root, PACK_CONTEXT );
const contextText = packContextText( pack );
const contextCurrent =
	existsSync( context ) && readFileSync( context, 'utf8' ) === contextText;
if ( check && ! contextCurrent ) {
	failed++;
	console.error(
		`x ${ PACK_CONTEXT } is stale: it is not what build/ gives, run npm run patterns:build`
	);
} else if ( ! contextCurrent ) {
	writeFileSync( context, contextText );
	console.log( `ok ${ PACK_CONTEXT } (${ pack.length } blocks, written)` );
}

/**
 * `block-runner validate --strict --json --context` on markup.
 *
 * @param {string} name   Pattern name.
 * @param {string} markup Block markup.
 * @return {Object} The report, or one that says why there is none.
 */
function validateWithPack( name, markup ) {
	const file = join( scratch, `${ name }.html` );
	writeFileSync( file, markup );
	try {
		return JSON.parse(
			execFileSync(
				process.execPath,
				[
					cli,
					'validate',
					file,
					'--strict',
					'--json',
					'--context',
					context,
				],
				{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
			)
		);
	} catch ( error ) {
		try {
			return JSON.parse( error.stdout );
		} catch {
			return {
				ok: false,
				items: [ { reason: 'validate did not return JSON' } ],
			};
		}
	}
}

for ( const file of readdirSync( intents )
	.filter( ( f ) => f.endsWith( '.json' ) )
	.sort() ) {
	const name = basename( file, '.json' );
	const meta = JSON.parse( readFileSync( join( intents, file ), 'utf8' ) );
	const repeated = repeatedLabels( meta.intent.blocks );
	if ( repeated.length ) {
		failed++;
		console.error(
			`x ${ name }: more than one block named ${ repeated.join( ', ' ) }`
		);
		continue;
	}
	const standIn = standInPackBlocks( meta.intent.blocks, byName );
	if ( standIn.problems.length ) {
		failed++;
		console.error(
			`x ${ name }: a pack block does not fit its block.json`
		);
		for ( const problem of standIn.problems ) {
			console.error( `    ${ problem }` );
		}
		continue;
	}
	const input = join( scratch, `${ name }.json` );
	writeFileSync(
		input,
		JSON.stringify( { ...meta.intent, blocks: standIn.nodes } )
	);

	let report;
	try {
		report = JSON.parse(
			execFileSync(
				process.execPath,
				[ cli, 'assemble', input, '--json' ],
				{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
			)
		);
	} catch ( error ) {
		report = JSON.parse(
			error.stdout ||
				'{"ok":false,"items":[{"reason":"assemble did not return JSON"}]}'
		);
	}

	const problems = ( report.items || [] ).filter(
		( item ) => ! tolerated( item )
	);

	if ( ! report.ok || report.summary?.invalid || problems.length ) {
		failed++;
		console.error( `x ${ name }: assemble failed` );
		for ( const item of problems.length ? problems : report.items || [] ) {
			console.error(
				`    ${ item.block || '' } ${ item.code || item.status || '' }: ${ item.reason || JSON.stringify( item ) }`
			);
		}
		continue;
	}

	const restored = restorePackBlocks( report.output.trim(), standIn.placed );
	if ( restored.problems.length ) {
		failed++;
		console.error( `x ${ name }: the pack blocks did not go back` );
		for ( const problem of restored.problems ) {
			console.error( `    ${ problem }` );
		}
		continue;
	}
	const markup = `${ restored.markup }\n`;

	if ( standIn.placed.length ) {
		const gate = validateWithPack( name, markup );
		if (
			gate.ok !== true ||
			gate.summary?.blocks !== report.summary.blocks
		) {
			failed++;
			console.error(
				`x ${ name }: block-runner validate --strict --context fails the markup with its pack blocks in place`
			);
			for ( const item of ( gate.items || [] ).filter(
				( entry ) => entry.severity !== 'info'
			) ) {
				console.error(
					`    ${ item.block || '' } ${ item.code || item.status || '' }: ${ item.reason || JSON.stringify( item ) }`
				);
			}
			continue;
		}
	}

	const target = join( root, 'patterns', `${ name }.html` );

	if ( check ) {
		const current = existsSync( target )
			? readFileSync( target, 'utf8' )
			: '';
		if ( current !== markup ) {
			failed++;
			console.error(
				`x ${ name }: patterns/${ name }.html is stale, run npm run patterns:build`
			);
			continue;
		}
		console.log(
			`ok ${ name } (${ report.summary.blocks } blocks, current)`
		);
		continue;
	}

	writeFileSync( target, markup );
	console.log( `ok ${ name } (${ report.summary.blocks } blocks)` );
}

rmSync( scratch, { recursive: true, force: true } );
process.exit( failed ? 1 : 0 );
