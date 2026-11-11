#!/usr/bin/env node
/**
 * Decide one row of .github/workflows/cache.yml from the harness's result
 * file and exit code, with rowVerdict() in scripts/lib/cache-check.mjs.
 *
 *   node scripts/cache-check-row.mjs --expect stale --exit 1 --result-file cache-check.json
 *
 * Exits 0 when the row passes and 1 when it does not. A missing or
 * unreadable result file fails the row, so a harness that crashed (exit 1,
 * the same code as stale) can never pass a row that expects stale.
 */
import { readFileSync } from 'node:fs';

import { rowVerdict } from './lib/cache-check.mjs';
import { annotate } from './lib/log.mjs';

const args = process.argv.slice( 2 );
const option = ( name ) => {
	const at = args.indexOf( `--${ name }` );
	return at === -1 ? undefined : args[ at + 1 ];
};

const expect = option( 'expect' );
const exit = Number( option( 'exit' ) );
let result = null;
try {
	result = JSON.parse( readFileSync( option( 'result-file' ), 'utf8' ) );
} catch {
	// Missing or unreadable: rowVerdict() fails the row.
}

const verdict = rowVerdict( { expect, exit, result } );
if ( verdict.ok ) {
	console.log( `ok   ${ verdict.reason }` );
} else {
	annotate( 'error', verdict.reason, `cache-check row expects ${ expect }` );
}
process.exit( verdict.ok ? 0 : 1 );
