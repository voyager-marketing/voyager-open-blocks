#!/usr/bin/env node
/**
 * Every offline gate, in one command. No WordPress needed.
 *
 *   npm run check
 *
 * 1. patterns/*.html match their intents (`patterns:build --check`).
 * 2. tests/fixtures/pack.context.json, the site context that lists the pack's
 *    own blocks, is what every block.json under build/ gives
 *    (scripts/lib/pack-blocks.mjs), and Wesper validates it.
 *    `block-runner validate --strict` passes on every pattern given that
 *    context, and no block in them warns unregistered-block. Without the context the pack's blocks warn and
 *    nothing else changes, so the context is shown to pass only them.
 * 3. The same command fails on tests/invalid/, so the gate is shown to bite,
 *    and on tests/invalid/blocks/, whose block no plugin registers, so the
 *    context is shown to know the pack's blocks and no others.
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
 *     scripts/cache-check.mjs) passes its known-answer test, all of its 32
 *     cases at least, planted crashes included.
 * 16. The test-count guard behind 11 and 15 rejects tests/invalid/no-tests.mjs,
 *     a test file with no tests, so that gate is shown to bite.
 * 17. No tracked file but CHANGELOG.md and bench/runs/ holds a tracker ID,
 *     and the same search finds one planted in a temporary file.
 * 18. presets: no block in patterns/*.html sets a colour, font size, font
 *     family, radius or shadow that is not a theme preset, uses a spacing
 *     slug outside 20 to 60, or is core/html or HTML outside a block
 *     (scripts/lib/presets.mjs).
 * 19. The same gate rejects each of the seven files in tests/invalid/presets/,
 *     one per rule, and every block in each for that file's rule alone, so
 *     each rule is shown to bite.
 * 20. The same gate passes tests/fixtures/presets/allowed.html, which writes
 *     every preset form it accepts, so a rule grown to refuse a preset turns
 *     the check red.
 *
 * The site-level gate (Wesper sees every block, source and pattern) needs a
 * running site: `npm run playground -- setup`, then `npm run context`.
 */
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	PACK_CONTEXT,
	packBlocks,
	packContextText,
} from './lib/pack-blocks.mjs';
import { checkMarkup, describeFinding, RULES } from './lib/presets.mjs';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const blockRunner = join(
	root,
	'node_modules',
	'block-runner',
	'dist',
	'cli.js'
);
const wesper = join( root, 'node_modules', 'wesper', 'dist', 'cli.js' );
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

// Block Runner knows only core's blocks. A site context that lists the pack's
// own blocks tells validate they are registered on the target
// (scripts/lib/pack-blocks.mjs). It is committed, so it must be what every
// block.json under build/ gives now, and Wesper must accept it, as the Block
// Check action does before it uses a manifest. A crash here fails the line
// and points the gates below at no context, so they fail too.
let pack = [];
let packContext = null;
let contextDetail = '';
try {
	pack = packBlocks( root );
	const committed = readFileSync( join( root, PACK_CONTEXT ), 'utf8' );
	const schema = run( [ wesper, 'validate', PACK_CONTEXT ] );
	if ( committed !== packContextText( pack ) ) {
		contextDetail = `${ PACK_CONTEXT } is stale, run npm run patterns:build`;
	} else if ( schema.code !== 0 ) {
		contextDetail = `wesper validate refuses ${ PACK_CONTEXT }: ${ (
			schema.stdout + schema.stderr
		)
			.trim()
			.split( '\n' )
			.join( '; ' ) }`;
	} else {
		packContext = join( root, PACK_CONTEXT );
	}
} catch ( error ) {
	pack = [];
	contextDetail = error?.message || String( error );
}
const packNames = new Set( pack.map( ( block ) => block.name ) );
report(
	`${ PACK_CONTEXT } lists the pack's own blocks, from every block.json under build/, and Wesper validates it`,
	packContext !== null && pack.length > 0,
	contextDetail ||
		pack.map( ( block ) => block.name ).join( ', ' ) ||
		'none found'
);

/**
 * `block-runner validate --strict --json` on a glob, with the pack's context
 * or without it.
 *
 * @param {string}  glob        Files.
 * @param {boolean} withContext Pass --context with the pack's blocks.
 * @return {{code: number|null, report: Object}} Exit code and parsed report.
 */
function validateGlob( glob, withContext = true ) {
	const args = [ blockRunner, 'validate', glob, '--strict', '--json' ];
	if ( withContext ) {
		args.push( '--context', packContext ?? 'missing' );
	}
	const result = run( args );
	let parsed = {};
	try {
		parsed = JSON.parse( result.stdout || '{}' );
	} catch {}
	return { code: result.code, report: parsed };
}
const unregistered = ( items, severity ) =>
	( items || [] ).filter(
		( item ) =>
			item.code === 'unregistered-block' && item.severity === severity
	);

const valid = validateGlob( 'patterns/*.html' );
const validReport = valid.report;
const strayBlocks = unregistered( validReport.items, 'warning' );
const knownBlocks = unregistered( validReport.items, 'info' );
report(
	'block-runner validate --strict --context <pack blocks> patterns/*.html',
	valid.code === 0 &&
		validReport.ok === true &&
		strayBlocks.length === 0 &&
		knownBlocks.every( ( item ) => packNames.has( item.block ) ),
	validReport.summary
		? `${ validReport.summary.valid }/${ validReport.summary.blocks } valid, ${ validReport.summary.warnings } warnings, ${ strayBlocks.length } unregistered-block warnings, ${ knownBlocks.length } pack blocks registered on target`
		: `exit ${ valid.code }`
);

// The context must change nothing but the pack's blocks. validate reads only
// the block names from it, so without it every other item is the same and
// the pack's blocks warn unregistered-block instead: the context is what
// passes them, and it passes nothing else.
const bare = validateGlob( 'patterns/*.html', false );
const sansPack = ( items ) =>
	JSON.stringify(
		( items || [] ).filter(
			( item ) =>
				! (
					item.code === 'unregistered-block' &&
					packNames.has( item.block )
				)
		)
	);
const bareStray = unregistered( bare.report.items, 'warning' );
report(
	'without the pack context, block-runner warns unregistered-block on the pack blocks in patterns/ and reports nothing else differently',
	bare.report.summary !== undefined &&
		bare.report.summary.blocks === validReport.summary?.blocks &&
		bare.report.summary.invalid === validReport.summary?.invalid &&
		bare.report.summary.warnings ===
			( validReport.summary?.warnings ?? NaN ) + knownBlocks.length &&
		bareStray.length === knownBlocks.length &&
		bareStray.every( ( item ) => packNames.has( item.block ) ) &&
		sansPack( bare.report.items ) === sansPack( validReport.items ) &&
		( knownBlocks.length === 0 || bare.code === 1 ),
	`${ bareStray.length } unregistered-block warnings without it, ${ knownBlocks.length } pack blocks with it`
);

const invalid = validateGlob( 'tests/invalid/*.html' );
report(
	'block-runner --context <pack blocks> rejects tests/invalid/*.html',
	invalid.code === 1,
	`exit ${ invalid.code }`
);

// The context lists the pack's blocks and nothing more, so a block from
// anywhere else still fails. The plant holds an icon and a block no plugin
// registers: the icon passes, and the other fails the run on its own.
const PLANTED_BLOCK = 'example/not-registered';
const stranger = validateGlob( 'tests/invalid/blocks/*.html' );
const strangerStray = unregistered( stranger.report.items, 'warning' );
const strangerKnown = unregistered( stranger.report.items, 'info' );
report(
	'block-runner --context <pack blocks> still rejects a block the pack does not ship (tests/invalid/blocks/)',
	stranger.code === 1 &&
		stranger.report.ok === false &&
		stranger.report.summary?.invalid === 0 &&
		strangerStray.length === 1 &&
		strangerStray[ 0 ].block === PLANTED_BLOCK &&
		strangerKnown.length > 0 &&
		strangerKnown.every( ( item ) => packNames.has( item.block ) ),
	`exit ${ stranger.code }, unregistered-block warnings on ${
		strangerStray.map( ( item ) => item.block ).join( ', ' ) || 'nothing'
	}, ${ strangerKnown.length } pack blocks passed`
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
	32
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

// presets: patterns take every colour, font size, font family, radius and
// shadow from the theme's presets, use spacing slugs 20 to 60 only, and hold
// no raw HTML (DESIGN.md, "Do's and Don'ts"). A crash in the gate is caught
// and fails the line it was for, so it can never read as a pass.
function presets( file ) {
	try {
		return checkMarkup( readFileSync( join( root, file ), 'utf8' ) );
	} catch ( error ) {
		return { error: error?.message || String( error ) };
	}
}
function plural( number, noun ) {
	return `${ number } ${ noun }${ number === 1 ? '' : 's' }`;
}
function printEach( lines ) {
	for ( const line of lines ) {
		console.log( `     ${ line }` );
	}
}

const patternFiles = readdirSync( join( root, 'patterns' ) )
	.filter( ( file ) => file.endsWith( '.html' ) )
	.sort()
	.map( ( file ) => `patterns/${ file }` );
let patternBlocks = 0;
const patternFindings = [];
for ( const file of patternFiles ) {
	const result = presets( file );
	if ( result.error ) {
		patternFindings.push(
			`${ file }: the gate crashed: ${ result.error }`
		);
		continue;
	}
	patternBlocks += result.blocks;
	for ( const finding of result.findings ) {
		patternFindings.push( describeFinding( file, finding ) );
	}
}
report(
	`presets: the ${ patternFiles.length } patterns in patterns/ use only theme presets`,
	patternFiles.length > 0 &&
		patternBlocks > 0 &&
		patternFindings.length === 0,
	patternFindings.length
		? `${ plural( patternFindings.length, 'finding' ) }, below`
		: `${ patternBlocks } blocks`
);
printEach( patternFindings );

// Each planted file breaks its rule once in every top-level block, and
// breaks no other rule. The gate must refuse every one of those blocks for
// that rule, so a rule that stops biting on any one case turns this red, and
// so does a file that loses its cases. Raise the floor when you add a case.
const PLANTED_CASES = 39;
const plantedDir = 'tests/invalid/presets';
const plantedFiles = readdirSync( join( root, plantedDir ) ).filter( ( file ) =>
	file.endsWith( '.html' )
);
const plantProblems = plantedFiles
	.filter( ( file ) => ! RULES.includes( file.slice( 0, -5 ) ) )
	.map( ( file ) => `${ plantedDir }/${ file } is named for no rule` );
let plantedCases = 0;
let rejected = 0;
for ( const rule of RULES ) {
	const file = `${ plantedDir }/${ rule }.html`;
	const before = plantProblems.length;
	const result = plantedFiles.includes( `${ rule }.html` )
		? presets( file )
		: { error: 'the file is missing' };
	if ( result.error ) {
		plantProblems.push( `${ file }: ${ result.error }` );
		continue;
	}
	plantedCases += result.top;
	if ( result.top === 0 ) {
		plantProblems.push( `${ file } holds no blocks` );
	}
	for ( let index = 0; index < result.top; index++ ) {
		const caught = result.findings.some(
			( finding ) =>
				finding.rule === rule &&
				finding.path.split( '/' )[ 0 ] === `${ index }`
		);
		if ( ! caught ) {
			plantProblems.push(
				`${ file }: block ${ index } passes the ${ rule } rule`
			);
		}
	}
	for ( const finding of result.findings ) {
		if ( finding.rule !== rule ) {
			plantProblems.push(
				`${ describeFinding( file, finding ) }, in the ${ rule } plant`
			);
		}
	}
	if ( plantProblems.length === before ) {
		rejected++;
	}
}
if ( plantedCases < PLANTED_CASES ) {
	plantProblems.push(
		`${ plantedCases } planted blocks, at least ${ PLANTED_CASES } expected`
	);
}
report(
	`presets: rejects ${ rejected } of ${ RULES.length } planted files in ${ plantedDir }/`,
	rejected === RULES.length && plantProblems.length === 0,
	plantProblems.length
		? `${ plural( plantProblems.length, 'problem' ) }, below`
		: `${ plantedCases } planted blocks, each refused for its file's rule`
);
printEach( plantProblems );

// The other direction: every preset form the gate accepts, written once.
// A rule that has grown to refuse a preset reference or a slug fails here.
const ALLOWED_CASES = 22;
const allowedFile = 'tests/fixtures/presets/allowed.html';
const allowed = presets( allowedFile );
const allowedFindings = allowed.error
	? [ `${ allowedFile }: the gate crashed: ${ allowed.error }` ]
	: allowed.findings.map( ( finding ) =>
			describeFinding( allowedFile, finding )
		);
report(
	`presets: passes ${ allowedFile }, which uses every preset form`,
	! allowed.error &&
		allowed.top >= ALLOWED_CASES &&
		allowedFindings.length === 0,
	allowedFindings.length
		? `${ plural( allowedFindings.length, 'finding' ) }, below`
		: `${ allowed.top } cases, at least ${ ALLOWED_CASES } expected`
);
printEach( allowedFindings );

process.exit( failed ? 1 : 0 );
