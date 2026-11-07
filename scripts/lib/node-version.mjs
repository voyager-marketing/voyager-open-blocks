/**
 * Check the running Node against `engines.node` in package.json.
 *
 * Imports only Node built-ins, so a script can call it before it loads
 * anything that an unsupported Node might fail on. Understands the range
 * forms package.json uses: `||` between sets, and `^`, `~`, `>=`, `>`, `<=`,
 * `<` or a bare version in each set.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '../..', import.meta.url ) );

/**
 * @param {string} text A version such as 24.22.1 or v22.
 * @return {number[]|null} [major, minor, patch], with missing parts as 0.
 */
function parse( text ) {
	const match = /^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/.exec( text.trim() );
	return match ? [ 1, 2, 3 ].map( ( i ) => Number( match[ i ] || 0 ) ) : null;
}

/**
 * @param {number[]} a
 * @param {number[]} b
 * @return {number} Negative, zero or positive, like a sort comparator.
 */
function compare( a, b ) {
	return a[ 0 ] - b[ 0 ] || a[ 1 ] - b[ 1 ] || a[ 2 ] - b[ 2 ];
}

/**
 * @param {number[]} version
 * @param {string}   comparator One term of a range set, such as ^22.23.4.
 * @return {boolean} Whether the version meets it.
 */
function meets( version, comparator ) {
	const match = /^(\^|~|>=|<=|>|<|=)?\s*(.+)$/.exec( comparator );
	const op = match[ 1 ] || '=';
	const target = parse( match[ 2 ] );
	if ( ! target ) {
		return false;
	}
	const diff = compare( version, target );
	switch ( op ) {
		case '^':
			return (
				diff >= 0 &&
				( target[ 0 ] > 0
					? version[ 0 ] === target[ 0 ]
					: version[ 1 ] === target[ 1 ] )
			);
		case '~':
			return (
				diff >= 0 &&
				version[ 0 ] === target[ 0 ] &&
				version[ 1 ] === target[ 1 ]
			);
		case '>=':
			return diff >= 0;
		case '>':
			return diff > 0;
		case '<=':
			return diff <= 0;
		case '<':
			return diff < 0;
		default:
			return diff === 0;
	}
}

/**
 * @param {string} version A version such as 22.23.4.
 * @param {string} range   An engines range such as ^22.23.4 || >=26.12.1.
 * @return {boolean} Whether the version is in the range.
 */
export function satisfies( version, range ) {
	const parsed = parse( version );
	if ( ! parsed ) {
		return false;
	}
	return range.split( '||' ).some( ( set ) => {
		const terms = set
			.trim()
			.replace( /(\^|~|>=|<=|>|<|=)\s+/g, '$1' )
			.split( /\s+/ )
			.filter( Boolean );
		return (
			terms.length === 0 ||
			terms.every(
				( term ) =>
					term === '*' || term === 'x' || meets( parsed, term )
			)
		);
	} );
}

/**
 * One plain line saying how to fix the Node version, or null when it is fine.
 *
 * @param {string} [version] Defaults to the running Node.
 * @return {string|null} The fix, or null.
 */
export function nodeVersionProblem( version = process.versions.node ) {
	const pkg = JSON.parse( readFileSync( `${ root }/package.json`, 'utf8' ) );
	const range = pkg.engines?.node;
	if ( ! range || satisfies( version, range ) ) {
		return null;
	}
	let pinned = '';
	try {
		pinned = readFileSync( `${ root }/.nvmrc`, 'utf8' ).trim();
	} catch {
		// No .nvmrc: name the range only.
	}
	return `Node ${ version } is not supported here (this repo needs ${ range }). Install Node ${
		pinned || 'from that range'
	}, for example with: nvm install ${ pinned || 'lts/*' }`;
}
