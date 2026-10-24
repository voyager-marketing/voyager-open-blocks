#!/usr/bin/env node
/**
 * Read the demo site with Wesper and check the pack is fully visible.
 *
 *   npm run context -- [--site DIR] [--out site.context.json]
 *
 * Runs `wesper collect --wp-path <site>` (the WP-CLI collector), then fails
 * unless the manifest lists every block, binding source and pattern in this
 * repo, and no warning names one of them.
 *
 * Warnings about anything else are printed, not failed. On WordPress 7.1.3,
 * core/navigation and core/query declare providesContext keys they no longer
 * register as attributes, so every 7.1.3 site carries three such warnings and
 * a partial flag, with or without this plugin (checked by collecting the same
 * site with the plugin deactivated).
 */
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';

import { flags, phpEnv, repo, siteDir } from './lib/site.mjs';

const opts = flags( process.argv.slice( 2 ) );
const site = siteDir( opts );
const out = resolve( opts.out || join( repo, 'site.context.json' ) );
const wesper = join( repo, 'node_modules', 'wesper', 'dist', 'cli.js' );

execFileSync(
	process.execPath,
	[ wesper, 'collect', '--wp-path', site, '--out', out ],
	{ env: phpEnv( site ), stdio: 'inherit' }
);

const manifest = JSON.parse( readFileSync( out, 'utf8' ) );

const expected = {
	blocks: readdirSync( join( repo, 'src' ), { withFileTypes: true } )
		.filter( ( d ) => d.isDirectory() )
		.map(
			( d ) =>
				JSON.parse(
					readFileSync(
						join( repo, 'src', d.name, 'block.json' ),
						'utf8'
					)
				).name
		),
	bindingSources: [ 'voyager/site-data', 'voyager/post-meta-text' ],
	patterns: readdirSync( join( repo, 'patterns', 'intents' ) )
		.filter( ( f ) => f.endsWith( '.json' ) )
		.map( ( f ) => `voyager-open/${ basename( f, '.json' ) }` ),
};

const listed = {
	blocks: new Set( ( manifest.blocks?.types || [] ).map( ( b ) => b.name ) ),
	bindingSources: new Set(
		( manifest.bindings?.sources || [] ).map( ( s ) => s.name )
	),
	patterns: new Set(
		( manifest.patterns?.items || [] ).map( ( p ) => p.name )
	),
};

const ours = Object.values( expected ).flat();
const missing = Object.entries( expected ).flatMap( ( [ kind, names ] ) =>
	names
		.filter( ( name ) => ! listed[ kind ].has( name ) )
		.map( ( name ) => `${ kind }: ${ name }` )
);

const warnings = [
	...( manifest.warnings || [] ),
	...( manifest.bindings?.warnings || [] ),
];
const namesOurs = ( w ) =>
	ours.some( ( name ) => `${ w.surface } ${ w.message }`.includes( name ) );
const packWarnings = warnings.filter( namesOurs );
const otherWarnings = warnings.filter( ( w ) => ! namesOurs( w ) );

console.log( `\nManifest: ${ out }` );
console.log(
	`WordPress ${ manifest.wordpress?.version }, collector ${ manifest.provenance?.collector } ${ manifest.provenance?.collectorVersion }`
);
for ( const [ kind, names ] of Object.entries( expected ) ) {
	console.log(
		`${ kind }: ${ names.map( ( n ) => `${ listed[ kind ].has( n ) ? 'ok' : 'MISSING' } ${ n }` ).join( ', ' ) }`
	);
}
console.log( `Warnings naming the pack: ${ packWarnings.length }` );
for ( const w of packWarnings ) {
	console.log(
		`  [${ w.severity }] ${ w.code } (${ w.surface }): ${ w.message }`
	);
}
console.log(
	`Other warnings (site and core, not the pack): ${ otherWarnings.length }`
);
for ( const w of otherWarnings ) {
	console.log( `  [${ w.severity }] ${ w.code } (${ w.surface })` );
}

if ( missing.length ) {
	console.error( `\nMissing from the manifest: ${ missing.join( '; ' ) }` );
}

process.exit( missing.length || packWarnings.length ? 1 : 0 );
