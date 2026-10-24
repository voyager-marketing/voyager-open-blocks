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

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const cli = join( root, 'node_modules', 'block-runner', 'dist', 'cli.js' );
const intents = join( root, 'patterns', 'intents' );
const check = process.argv.includes( '--check' );
const tolerated = ( item ) =>
	/noop resolver does not resolve media IDs/.test( item.reason || '' );

const scratch = mkdtempSync( join( tmpdir(), 'voyager-open-patterns-' ) );
let failed = 0;

for ( const file of readdirSync( intents )
	.filter( ( f ) => f.endsWith( '.json' ) )
	.sort() ) {
	const name = basename( file, '.json' );
	const meta = JSON.parse( readFileSync( join( intents, file ), 'utf8' ) );
	const input = join( scratch, `${ name }.json` );
	writeFileSync( input, JSON.stringify( meta.intent ) );

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

	const target = join( root, 'patterns', `${ name }.html` );
	const markup = `${ report.output.trim() }\n`;

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
