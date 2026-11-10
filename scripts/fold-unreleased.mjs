#!/usr/bin/env node
/**
 * Fold the change notes in unreleased/ into readme.txt's changelog.
 *
 *   npm run changelog:fold                 on the release PR: write, then delete the notes
 *   npm run changelog:fold -- --check      read every note, write nothing (npm run check)
 *
 * Each pull request that changes something a site owner would notice adds one
 * note, unreleased/<branch-slug>.md, instead of editing readme.txt:
 *
 *   type: feat
 *   `compose` builds a draft page from a recipe or a list of sections.
 *
 * The first line is the type: feat, fix or docs. The second is the one line
 * the change wants under `== Changelog ==`, without the leading `* `. The
 * release PR runs this script: it writes every note's line under
 * `= <version> =`, feat first, then fix, then docs, each type in file-name
 * order, and deletes the notes. unreleased/README.md is kept. A version that
 * already has a section gets the new lines after its own, and a line it
 * already has is not added twice.
 *
 * Options:
 *   --version <x.y.z>  version to fold under. Default: `Version:` in --plugin.
 *   --plugin <file>    plugin file to read the version from. Default: voyager-open-blocks.php.
 *   --dir <dir>        notes folder. Default: unreleased.
 *   --readme <file>    readme to write. Default: readme.txt.
 *   --check            check the notes and change nothing.
 *
 * Exits 1, with nothing changed, when a note is malformed or the readme has
 * no `== Changelog ==` section.
 */
import { readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const TYPES = [ 'feat', 'fix', 'docs' ];
const KEEP = 'README.md';
const NAME = /^[a-z0-9][a-z0-9-]*\.md$/;

function option( args, name, fallback ) {
	const at = args.indexOf( name );
	return at >= 0 && at + 1 < args.length ? args[ at + 1 ] : fallback;
}

/**
 * Read one note.
 *
 * @param {string} file Path to the note.
 * @return {{type: string, line: string, name: string}|{problem: string, name: string}} The note, or what is wrong with it.
 */
function note( file ) {
	const name = basename( file );
	if ( ! NAME.test( name ) ) {
		return {
			name,
			problem:
				'the file name must be the branch slug: lowercase letters, digits and hyphens, ending in .md',
		};
	}

	const lines = readFileSync( file, 'utf8' )
		.split( /\r?\n/ )
		.map( ( text ) => text.trim() )
		.filter( ( text ) => text !== '' );
	const type = lines[ 0 ]?.match( /^type:\s*(\S+)$/ )?.[ 1 ] ?? '';

	if ( ! TYPES.includes( type ) ) {
		return {
			name,
			problem: `the first line must be "type: <type>", and type must be feat, fix or docs (found "${
				lines[ 0 ] ?? ''
			}")`,
		};
	}
	if ( lines.length !== 2 ) {
		return {
			name,
			problem: `after the type line, write exactly one line for the changelog (found ${
				lines.length - 1
			})`,
		};
	}
	if ( /^[*-]\s/.test( lines[ 1 ] ) ) {
		return {
			name,
			problem: 'write the changelog line without the leading "* "',
		};
	}

	return { name, type, line: lines[ 1 ] };
}

/**
 * readme.txt with the lines under `= version =`.
 *
 * @param {string}   readme  The readme.
 * @param {string}   version Version.
 * @param {string[]} bullets Lines to add, each starting with `* `.
 * @return {string|null} The new readme, or null when it has no changelog section.
 */
export function insert( readme, version, bullets ) {
	const eol = readme.includes( '\r\n' ) ? '\r\n' : '\n';
	const lines = readme.split( /\r?\n/ );
	const changelog = lines.findIndex(
		( text ) => text.trim() === '== Changelog =='
	);
	if ( changelog < 0 ) {
		return null;
	}

	// The changelog runs to the next `== Section ==` or the end of the file.
	let end = lines.findIndex(
		( text, index ) => index > changelog && /^==[^=].*==\s*$/.test( text )
	);
	end = end < 0 ? lines.length : end;

	const heading = `= ${ version } =`;
	const existing = lines.findIndex(
		( text, index ) =>
			index > changelog && index < end && text.trim() === heading
	);

	if ( existing >= 0 ) {
		// After the section's last line, before the next `= x =` or `== x ==`.
		let last = existing;
		for ( let index = existing + 1; index < end; index++ ) {
			if ( /^=/.test( lines[ index ] ) ) {
				break;
			}
			if ( lines[ index ].trim() !== '' ) {
				last = index;
			}
		}
		const have = new Set(
			lines.slice( existing + 1, last + 1 ).map( ( text ) => text.trim() )
		);
		lines.splice(
			last + 1,
			0,
			...bullets.filter( ( bullet ) => ! have.has( bullet ) )
		);
		return lines.join( eol );
	}

	// A new section, above the newest one.
	let first = changelog + 1;
	while ( first < end && lines[ first ].trim() === '' ) {
		first++;
	}
	const block = [ heading, ...bullets, '' ];
	if ( first === end ) {
		// An empty changelog: keep one blank line after the heading.
		lines.splice( changelog + 1, first - changelog - 1, '', ...block );
	} else {
		lines.splice( first, 0, ...block );
	}
	return lines.join( eol );
}

function main( args ) {
	const dir = resolve( option( args, '--dir', join( root, 'unreleased' ) ) );
	const files = readdirSync( dir )
		.filter( ( name ) => name !== KEEP && ! name.startsWith( '.' ) )
		.sort();
	const notes = files.map( ( name ) => note( join( dir, name ) ) );
	const bad = notes.filter( ( item ) => item.problem );

	if ( bad.length ) {
		for ( const item of bad ) {
			console.error(
				`FAIL ${ relative( root, join( dir, item.name ) ) }: ${
					item.problem
				}`
			);
		}
		console.error(
			`${ bad.length } malformed note(s). The format is in unreleased/README.md.`
		);
		return 1;
	}

	if ( args.includes( '--check' ) ) {
		console.log(
			`ok   ${ notes.length } notes in ${ relative( root, dir ) || '.' }`
		);
		return 0;
	}

	if ( ! notes.length ) {
		console.log( 'Nothing to fold: no notes besides README.md.' );
		return 0;
	}

	let version = option( args, '--version', '' );
	if ( ! version ) {
		const plugin = resolve(
			option( args, '--plugin', join( root, 'voyager-open-blocks.php' ) )
		);
		version =
			readFileSync( plugin, 'utf8' ).match(
				/^ \* Version:\s*(\S+)\s*$/m
			)?.[ 1 ] ?? '';
		if ( ! version ) {
			console.error( `FAIL no "Version:" header in ${ plugin }.` );
			return 1;
		}
	}

	const bullets = TYPES.flatMap( ( type ) =>
		notes
			.filter( ( item ) => item.type === type )
			.map( ( item ) => `* ${ item.line }` )
	);
	const readmePath = resolve(
		option( args, '--readme', join( root, 'readme.txt' ) )
	);
	const readme = readFileSync( readmePath, 'utf8' );
	const folded = insert( readme, version, bullets );

	if ( folded === null ) {
		console.error(
			`FAIL ${ readmePath } has no "== Changelog ==" section. Nothing was changed.`
		);
		return 1;
	}

	writeFileSync( readmePath, folded );
	for ( const item of notes ) {
		rmSync( join( dir, item.name ) );
	}
	console.log(
		`Folded ${ notes.length } note(s) into ${ basename(
			readmePath
		) } under = ${ version } =, and deleted them.`
	);
	return 0;
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	process.exit( main( process.argv.slice( 2 ) ) );
}
