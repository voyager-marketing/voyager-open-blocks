#!/usr/bin/env node
/**
 * Every offline gate, in one command. No WordPress needed.
 *
 *   npm run check
 *
 * 1. patterns/*.html match their intents (`patterns:build --check`).
 * 2. `block-runner validate --strict` passes on every pattern.
 * 3. The same command fails on tests/invalid/, so the gate is shown to bite.
 * 4. `block-runner plugin inspect` recognizes the plugin as a wp-scripts host.
 * 5. readme.txt's `Stable tag` matches the plugin's `Version` header.
 * 6. The entry skill's `pack-version` matches the plugin's `Version` header.
 * 7. tests/fixtures/site-data-keys.json matches its sha256 pin.
 * 8. readme.txt says where the source of the built files is.
 * 9. readme.txt's changelog has an entry for the plugin's `Version`, so a
 *    release pull request stays red until its notes are folded.
 * 10. Every change note in unreleased/ is well-formed (unreleased/README.md).
 * 11. scripts/fold-unreleased.mjs passes its known-answer test, all of its
 *     12 cases at least.
 * 12. DESIGN.md lints with no errors and no warnings.
 * 13. The same linter rejects tests/invalid/design-broken-ref.md, so that
 *     gate is shown to bite.
 * 14. The gate in 12 rejects tests/invalid/design-low-contrast.md, which has
 *     no errors and one contrast warning, so its fail-on-warning rule is
 *     shown to bite.
 * 15. npm run cache-check's logic (scripts/lib/cache-check.mjs, and run() in
 *     scripts/cache-check.mjs) passes its known-answer test, all of its 26
 *     cases at least, planted crashes included.
 * 16. The test-count guard behind 11 and 15 rejects tests/invalid/no-tests.mjs,
 *     a test file with no tests, so that gate is shown to bite.
 * 17. No tracked file but CHANGELOG.md and bench/runs/ holds a tracker ID,
 *     and the same search finds one planted in a temporary file.
 *
 * The site-level gate (Wesper sees every block, source and pattern) needs a
 * running site: `npm run playground -- setup`, then `npm run context`.
 */
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const blockRunner = join(
	root,
	'node_modules',
	'block-runner',
	'dist',
	'cli.js'
);
let failed = 0;

function run( args ) {
	const result = spawnSync( process.execPath, args, {
		cwd: root,
		encoding: 'utf8',
	} );
	return {
		code: result.status,
		stdout: result.stdout || '',
		stderr: result.stderr || '',
	};
}

function report( name, ok, detail = '' ) {
	console.log(
		`${ ok ? 'ok  ' : 'FAIL' } ${ name }${ detail ? ` (${ detail })` : '' }`
	);
	if ( ! ok ) {
		failed++;
	}
}

const build = run( [
	join( root, 'scripts', 'build-patterns.mjs' ),
	'--check',
] );
report(
	'patterns match their intents',
	build.code === 0,
	build.code ? build.stderr.trim() : ''
);

const valid = run( [
	blockRunner,
	'validate',
	'patterns/*.html',
	'--strict',
	'--json',
] );
const validReport = JSON.parse( valid.stdout || '{}' );
report(
	'block-runner validate --strict patterns/*.html',
	valid.code === 0 && validReport.ok === true,
	validReport.summary
		? `${ validReport.summary.valid }/${ validReport.summary.blocks } valid, ${ validReport.summary.warnings } warnings`
		: `exit ${ valid.code }`
);

const invalid = run( [
	blockRunner,
	'validate',
	'tests/invalid/*.html',
	'--strict',
	'--json',
] );
report(
	'block-runner rejects tests/invalid/*.html',
	invalid.code === 1,
	`exit ${ invalid.code }`
);

const inspect = run( [ blockRunner, 'plugin', 'inspect', '.', '--json' ] );
const profile = JSON.parse( inspect.stdout || '{}' );
// block-runner 1.0.0 builds `__DIR__ . '/build'` with a forward slash and
// compares it to path.join(), so on Windows the bootstrap is never matched.
// Linux and macOS (and CI) are unaffected.
const windowsPathBug =
	process.platform === 'win32' &&
	/No supported PHP registration bootstrap/.test( profile.reason || '' );
if ( windowsPathBug ) {
	console.log(
		'skip block-runner plugin inspect (block-runner 1.0.0 path-separator bug on Windows; run on Linux or macOS)'
	);
} else {
	report(
		'block-runner plugin inspect',
		profile.kind === 'recognized',
		profile.profile || profile.reason || `exit ${ inspect.code }`
	);
}

// WordPress.org serves the version the readme's Stable tag names, so a tag
// left behind after a Version bump ships the old release.
function header( file, pattern ) {
	const match = readFileSync( join( root, file ), 'utf8' ).match( pattern );
	return match ? match[ 1 ] : '';
}
const version = header(
	'voyager-open-blocks.php',
	/^ \* Version:\s*(\S+)\s*$/m
);
const stableTag = header( 'readme.txt', /^Stable tag:\s*(\S+)\s*$/im );
report(
	'readme.txt Stable tag matches the plugin Version',
	version !== '' && stableTag === version,
	`Stable tag ${ stableTag || 'missing' }, Version ${ version || 'missing' }`
);

// An agent reads pack-version to know which pack the skills describe.
// release-please moves it on each release through the x-release-please-version
// marker on its line, so a mismatch means the marker or the line was lost.
const entrySkill = join( 'skills', 'voyager-open-blocks', 'SKILL.md' );
const frontmatter =
	readFileSync( join( root, entrySkill ), 'utf8' ).match(
		/^---\r?\n([\s\S]*?)\r?\n---\r?\n/
	)?.[ 1 ] ?? '';
const packVersion =
	frontmatter.match( /^\s+pack-version:\s*["']?([^"'\s#]+)["']?/m )?.[ 1 ] ??
	'';
report(
	`${ entrySkill } pack-version matches the plugin Version`,
	version !== '' && packVersion === version,
	`pack-version ${ packVersion || 'missing' }, Version ${ version || 'missing' }`
);

// voyager-blocks carries a byte-identical copy of the site-data fixture and
// compares it to the same hash, so a change here moves the pin with it.
const fixtureBytes = readFileSync(
	join( root, 'tests', 'fixtures', 'site-data-keys.json' )
);
const pinned = readFileSync(
	join( root, 'tests', 'fixtures', 'site-data-keys.sha256' ),
	'utf8'
).match( /^([0-9a-f]{64})\b/ );
const fixtureHash = createHash( 'sha256' )
	.update( fixtureBytes )
	.digest( 'hex' );
report(
	'site-data fixture matches its sha256 pin',
	pinned !== null && pinned[ 1 ] === fixtureHash,
	`pinned ${ pinned ? pinned[ 1 ].slice( 0, 12 ) : 'missing' }, fixture ${ fixtureHash.slice( 0, 12 ) }`
);

// The plugin directory asks a plugin that ships built code to say where its
// source is. Plugin Check does not test for it, so this does.
const readme = readFileSync( join( root, 'readme.txt' ), 'utf8' );
const sourceSection =
	readme.match( /^= Source code =\r?\n([\s\S]*?)(?=^=)/m )?.[ 1 ] ?? '';
const sourceNamed =
	sourceSection.includes(
		'https://github.com/voyager-marketing/voyager-open-blocks'
	) && sourceSection.includes( 'npm run build' );
let sourceDetail = 'no "= Source code =" section';
if ( sourceNamed ) {
	sourceDetail = 'GitHub link and build command';
} else if ( sourceSection ) {
	sourceDetail = 'the section needs the GitHub link and npm run build';
}
report(
	'readme.txt says where the source of build/ is',
	sourceNamed,
	sourceDetail
);

// A release pull request bumps Version, and its notes reach readme.txt's
// changelog only when someone runs npm run changelog:fold on it. Until then
// there is no entry for the new version, so the release pull request fails
// here instead of shipping without its changelog.
const changelog =
	readme.match(
		/^== Changelog ==\r?\n([\s\S]*?)(?=^== |(?![\s\S]))/m
	)?.[ 1 ] ?? '';
const changelogEntry =
	version !== '' &&
	changelog
		.split( /\r?\n/ )
		.some( ( line ) => line.trim() === `= ${ version } =` );
let changelogDetail = 'no "== Changelog ==" section';
if ( changelogEntry ) {
	changelogDetail = `"= ${ version } =" is there`;
} else if ( changelog ) {
	changelogDetail = `no "= ${
		version || 'Version'
	} =" entry; on a release pull request, run npm run changelog:fold`;
}
report(
	'readme.txt has a changelog entry for the plugin Version',
	changelogEntry,
	changelogDetail
);

// Pull requests add a note to unreleased/ instead of editing readme.txt's
// changelog. A malformed note would only fail on the release PR, so every
// pull request reads them all.
const notes = run( [
	join( root, 'scripts', 'fold-unreleased.mjs' ),
	'--check',
] );
report(
	'unreleased/ change notes are well-formed',
	notes.code === 0,
	notes.code === 0
		? notes.stdout.trim().replace( /^ok\s+/, '' )
		: notes.stderr.trim().split( '\n' )[ 0 ]
);

// A test runner that ran nothing exits 0 too, and node --test counts a file
// with no tests in it as one passing test. So the floor is each file's own
// count of known cases, not "more than none": a file that lost its tests, or
// most of them, fails here. Raise a floor when you add cases.
function knownAnswer( file, minimum ) {
	const result = run( [ '--test', '--test-reporter=tap', file ] );
	const count = ( name ) =>
		Number(
			result.stdout.match(
				new RegExp( `^# ${ name } (\\d+)$`, 'm' )
			)?.[ 1 ] ?? 0
		);
	return {
		ok:
			result.code === 0 &&
			count( 'tests' ) >= minimum &&
			count( 'pass' ) >= minimum &&
			count( 'fail' ) === 0,
		detail: `${ count( 'pass' ) }/${ count(
			'tests'
		) } passed, at least ${ minimum } expected`,
	};
}

const FOLD_CASES = 12;
const foldTest = knownAnswer(
	join( root, 'scripts', 'test-fold-unreleased.mjs' ),
	FOLD_CASES
);
report(
	'scripts/fold-unreleased.mjs passes its known-answer test',
	foldTest.ok,
	foldTest.detail
);

// DESIGN.md is linted with Google's DESIGN.md linter. Any error or warning
// fails the gate. The linter exits 1 on errors only, and it reports a
// component's text under 4.5:1 against its background, an unused colour and
// a section out of order as warnings, so the gate reads the findings.
const designLint = join(
	root,
	'node_modules',
	'@google',
	'design.md',
	'dist',
	'index.js'
);
function lintDesign( file ) {
	const result = run( [ designLint, 'lint', '--format', 'json', file ] );
	let parsed = {};
	try {
		parsed = JSON.parse( result.stdout );
	} catch {}
	const findings = Array.isArray( parsed.findings ) ? parsed.findings : [];
	return {
		code: result.code,
		parsed: Array.isArray( parsed.findings ),
		errors: findings.filter( ( item ) => item.severity === 'error' ),
		warnings: findings.filter( ( item ) => item.severity === 'warning' ),
	};
}
// The gate, in one place, so the control below tests the same rule.
function designClean( result ) {
	return (
		result.code === 0 &&
		result.parsed &&
		result.errors.length === 0 &&
		result.warnings.length === 0
	);
}
const design = lintDesign( 'DESIGN.md' );
report(
	'DESIGN.md lints clean (@google/design.md)',
	designClean( design ),
	design.parsed
		? [ ...design.errors, ...design.warnings ]
				.map( ( item ) =>
					item.path
						? `${ item.path }: ${ item.message }`
						: item.message
				)
				.join( ' ' ) ||
				`${ design.errors.length } errors, ${ design.warnings.length } warnings`
		: `exit ${ design.code }, no JSON report`
);

const brokenDesign = lintDesign(
	join( 'tests', 'invalid', 'design-broken-ref.md' )
);
report(
	'the DESIGN.md linter rejects tests/invalid/design-broken-ref.md',
	brokenDesign.code === 1 &&
		brokenDesign.errors.some( ( item ) => item.rule === 'broken-ref' ),
	`exit ${ brokenDesign.code }, ${ brokenDesign.errors.length } errors`
);

// The linter passes this file: exit 0, no errors, one contrast warning. Only
// the gate's fail-on-warning rule turns it away, so a gate loosened to the
// linter's exit code turns this red.
const lowContrast = lintDesign(
	join( 'tests', 'invalid', 'design-low-contrast.md' )
);
report(
	'the DESIGN.md gate rejects tests/invalid/design-low-contrast.md',
	lowContrast.code === 0 &&
		lowContrast.errors.length === 0 &&
		lowContrast.warnings.some(
			( item ) => item.rule === 'contrast-ratio'
		) &&
		! designClean( lowContrast ),
	`exit ${ lowContrast.code }, ${ lowContrast.errors.length } errors, ${ lowContrast.warnings.length } warnings`
);

// npm run cache-check needs Playground, so CI runs it. What decides its
// verdict needs nothing, so it is gated here.
const cacheCheckTest = knownAnswer(
	join( root, 'scripts', 'test-cache-check.mjs' ),
	26
);
report(
	"npm run cache-check's logic passes its known-answer test",
	cacheCheckTest.ok,
	cacheCheckTest.detail
);

// node --test reports this empty file as "1/1 passed". The guard, at the
// fold test's floor, has to refuse it.
const noTests = knownAnswer(
	join( root, 'tests', 'invalid', 'no-tests.mjs' ),
	FOLD_CASES
);
report(
	'the test-count guard rejects tests/invalid/no-tests.mjs',
	! noTests.ok,
	noTests.detail
);

// Tracker IDs belong to the maintainers' tracker, so a file this public repo
// ships names an issue by what it is. git grep exits 1 when nothing matches
// and 0 when something does, so the scan passes only on 1. The same search
// must find an ID planted in a temporary file, made here so that no tracked
// file holds one.
const TRACKER_ID = '(VOY|TK)-[0-9]+';
function trackerIds( cwd, options, paths ) {
	const result = spawnSync(
		'git',
		[ 'grep', ...options, '-n', '-I', '-E', TRACKER_ID, '--', ...paths ],
		{ cwd, encoding: 'utf8' }
	);
	return {
		code: result.status,
		lines: ( result.stdout || '' ).split( '\n' ).filter( Boolean ),
		error: result.error?.message || ( result.stderr || '' ).trim(),
	};
}
const ids = trackerIds( root, [], [ '.', ':!CHANGELOG.md', ':!bench/runs' ] );
const plantDir = mkdtempSync( join( tmpdir(), 'voyager-open-tracker-id-' ) );
writeFileSync(
	join( plantDir, 'planted.md' ),
	`Planted by scripts/check.mjs: ${ [ 'VOY', '1234' ].join( '-' ) }.\n`
);
const plantedId = trackerIds( plantDir, [ '--no-index' ], [ 'planted.md' ] );
rmSync( plantDir, { recursive: true, force: true } );
let idDetail = 'none, and the planted one was found';
if ( plantedId.code !== 0 || plantedId.lines.length !== 1 ) {
	idDetail = `the search missed the planted ID (exit ${ plantedId.code }${
		plantedId.error ? `: ${ plantedId.error }` : ''
	})`;
} else if ( ids.code === 0 ) {
	idDetail = `${ ids.lines.length } lines, such as ${ ids.lines
		.slice( 0, 3 )
		.join( ' | ' ) }`;
} else if ( ids.code !== 1 ) {
	idDetail = `git grep did not run (exit ${ ids.code }${
		ids.error ? `: ${ ids.error }` : ''
	})`;
}
report(
	'no tracker IDs in tracked files (CHANGELOG.md and bench/runs aside)',
	ids.code === 1 && plantedId.code === 0 && plantedId.lines.length === 1,
	idDetail
);

process.exit( failed ? 1 : 0 );
