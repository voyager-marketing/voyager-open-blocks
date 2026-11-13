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
 * 21. npm run check:usage-index's verdict (run() in
 *     scripts/usage-index-check.mjs) passes its known-answer test, all of its
 *     12 cases at least: a crash planted before or after the paths are
 *     measured never exits 0, and the script run with --plant crash exits 2.
 * 22. Each known failure in tests/fixtures/contrast.json names its pair by
 *     where it is, the kind of target and its place among the section's
 *     targets of that kind, never by its text, and the same rule refuses a
 *     planted entry keyed on text.
 * 23. skills: the quick card, the entry skill, is under 500 words, counted
 *     as `wc -w` counts them (scripts/lib/words.mjs), and the same rule
 *     refuses tests/invalid/quick-card-500.md, a copy of it at 500 words.
 * 24. rhythm: in every recipe in recipes/, no two sections next to each
 *     other share a style other than plain, and each section names a
 *     pattern and layout an intent carries, resolved as compose resolves
 *     them (scripts/lib/recipes.mjs).
 * 25. The same gate rejects each recipe in tests/invalid/recipes/, two
 *     adjacent accent sections and an unknown layout, for its own rule and
 *     no other, so each rule is shown to bite.
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
import {
	INTENTS as RECIPE_INTENTS,
	PLANTS as RHYTHM_PLANTS,
	RECIPES,
	readRecipes,
	rhythmProblems,
	sectionLayouts,
} from './lib/recipes.mjs';
import { CARD_LIMIT, cardFits, countWords, ENTRY_SKILL } from './lib/words.mjs';

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
const PLANTED_CASES = 42;
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
const ALLOWED_CASES = 23;
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

// npm run check:usage-index needs Playground too. How it turns what it saw
// into an exit code does not, and a crash must never come out as a pass.
const usageIndexTest = knownAnswer(
	join( root, 'scripts', 'test-usage-index-check.mjs' ),
	12
);
report(
	"npm run check:usage-index's verdict passes its known-answer test, planted crashes included",
	usageIndexTest.ok,
	usageIndexTest.detail
);

// npm run design-check lists the contrast pairs it lets through under the
// floor in tests/fixtures/contrast.json. Each names its pair by where it is,
// the kind of target and its place among the section's targets of that kind
// (knownKey() there), never by its text: a list keyed on text is renamed
// whole by a Site Data or wording change. The same rule must refuse a
// planted entry keyed on text.
const KNOWN_FIELDS = [
	'kind',
	'nth',
	'pattern',
	'ratio',
	'state',
	'style',
	'variation',
];
const TARGET_KINDS = [ 'button', 'outline button', 'link', 'heading', 'text' ];
function knownFailureProblems( entry, styles, patterns ) {
	const problems = [];
	const keys = Object.keys( entry ?? {} ).sort();
	if ( JSON.stringify( keys ) !== JSON.stringify( KNOWN_FIELDS ) ) {
		problems.push(
			`has ${ keys.join( ', ' ) }, not ${ KNOWN_FIELDS.join( ', ' ) }`
		);
	}
	if ( ! TARGET_KINDS.includes( entry?.kind ) ) {
		problems.push( `kind ${ JSON.stringify( entry?.kind ) }` );
	}
	if ( ! Number.isInteger( entry?.nth ) || entry.nth < 1 ) {
		problems.push( `nth ${ JSON.stringify( entry?.nth ) }` );
	}
	if ( ! [ 'rest', 'hover' ].includes( entry?.state ) ) {
		problems.push( `state ${ JSON.stringify( entry?.state ) }` );
	}
	if ( typeof entry?.ratio !== 'number' ) {
		problems.push( `ratio ${ JSON.stringify( entry?.ratio ) }` );
	}
	if ( ! styles.includes( entry?.style ) ) {
		problems.push( `style ${ JSON.stringify( entry?.style ) }` );
	}
	if ( ! patterns.includes( entry?.pattern ) ) {
		problems.push( `pattern ${ JSON.stringify( entry?.pattern ) }` );
	}
	if ( typeof entry?.variation !== 'string' ) {
		problems.push( `variation ${ JSON.stringify( entry?.variation ) }` );
	}
	return problems;
}
let knownCount = 0;
const knownProblems = [];
let plantedKnown = [];
try {
	const contrast = JSON.parse(
		readFileSync(
			join( root, 'tests', 'fixtures', 'contrast.json' ),
			'utf8'
		)
	);
	const names = readdirSync( join( root, 'patterns' ) )
		.filter( ( file ) => file.endsWith( '.html' ) )
		.map( ( file ) => file.slice( 0, -5 ) );
	for ( const theme of contrast.themes ) {
		for ( const entry of theme.known_failures || [] ) {
			knownCount++;
			const found = knownFailureProblems( entry, contrast.styles, names );
			if ( found.length ) {
				knownProblems.push(
					`${ theme.theme } ${ JSON.stringify( entry ) }: ${ found.join(
						'; '
					) }`
				);
			}
		}
	}
	plantedKnown = knownFailureProblems(
		{
			variation: '',
			pattern: 'cta-band',
			style: 'plain',
			target: 'outline button "(555) 010-0199"',
			state: 'rest',
			ratio: 4.23,
		},
		contrast.styles,
		names
	);
} catch ( error ) {
	knownProblems.push( `the gate crashed: ${ error?.message || error }` );
}
report(
	"design-check's known failures name each pair by kind and position, never by its text, and an entry keyed on text is refused",
	knownProblems.length === 0 && plantedKnown.length > 0,
	knownProblems.length
		? `${ plural( knownProblems.length, 'problem' ) }, below`
		: `${ knownCount } listed${
				plantedKnown.length ? '' : ', but the planted text key passed'
			}`
);
printEach( knownProblems.slice( 0, 10 ) );

// An agent reads the entry skill before its first call, so every word of it
// is paid for on every build. The detail lives in the abilities' own
// descriptions; the card keeps the path and the rules. Counted as `wc -w`
// counts, so the number is easy to check by hand. The plant is a copy of the
// card at exactly 500 words, the first count the rule must refuse. A crash
// fails the line rather than reading as a count.
const plantedCard = 'tests/invalid/quick-card-500.md';
let cardWords = 0;
let plantWords = 0;
let cardError = '';
try {
	cardWords = countWords( readFileSync( join( root, ENTRY_SKILL ), 'utf8' ) );
	plantWords = countWords(
		readFileSync( join( root, plantedCard ), 'utf8' )
	);
} catch ( error ) {
	cardError = `the gate crashed: ${ error?.message || error }`;
}
report(
	`skills: the quick card is ${ cardWords } words`,
	cardError === '' && cardFits( cardWords ),
	cardError || `${ ENTRY_SKILL }, under ${ CARD_LIMIT }`
);
report(
	`skills: the same rule refuses ${ plantedCard }, ${ plantWords } words`,
	cardError === '' && plantWords === CARD_LIMIT && ! cardFits( plantWords ),
	cardError ||
		( plantWords === CARD_LIMIT
			? `the plant is ${ CARD_LIMIT } words`
			: `the plant must be exactly ${ CARD_LIMIT } words` )
);

// rhythm: DESIGN.md's Layout rule on adjacent styles, read from every page
// recipe. Two sections next to each other never share a style other than
// plain, and each section names a pattern and layout an intent carries,
// resolved as compose resolves them (scripts/lib/recipes.mjs). The rest of
// contract 5 is tests/Unit/RecipesTest.php's. A crash fails the line rather
// than reading as a pass.
function rhythm( dir ) {
	try {
		const layouts = sectionLayouts( join( root, RECIPE_INTENTS ) );
		return readRecipes( join( root, dir ) ).map( ( entry ) => ( {
			slug: entry.slug,
			sections: Array.isArray( entry.recipe?.sections )
				? entry.recipe.sections.length
				: 0,
			problems: rhythmProblems( entry, layouts ),
		} ) );
	} catch ( error ) {
		return { error: error?.message || String( error ) };
	}
}

const shipped = rhythm( RECIPES );
const shippedProblems = shipped.error
	? [ `the gate crashed: ${ shipped.error }` ]
	: shipped.flatMap( ( recipe ) => recipe.problems.map( ( p ) => p.line ) );
const recipeCount = shipped.error ? 0 : shipped.length;
report(
	`rhythm: the ${ recipeCount } recipes in ${ RECIPES }/ keep adjacent styles apart`,
	recipeCount > 0 && shippedProblems.length === 0,
	shippedProblems.length
		? `${ plural( shippedProblems.length, 'problem' ) }, below`
		: `${ shipped.reduce(
				( sum, recipe ) => sum + recipe.sections,
				0
			) } sections, each naming a pattern`
);
printEach( shippedProblems );

// Each planted recipe breaks one rule and no other, and the gate must refuse
// it for that rule alone, as the presets gate refuses its plants.
const plantedRecipesDir = 'tests/invalid/recipes';
const planted = rhythm( plantedRecipesDir );
const recipePlantProblems = [];
let refusedRecipes = 0;
if ( planted.error ) {
	recipePlantProblems.push( `the gate crashed: ${ planted.error }` );
} else {
	for ( const { slug } of planted ) {
		if ( ! Object.hasOwn( RHYTHM_PLANTS, slug ) ) {
			recipePlantProblems.push(
				`${ plantedRecipesDir }/${ slug }.json is named for no rule`
			);
		}
	}
	for ( const [ slug, rule ] of Object.entries( RHYTHM_PLANTS ) ) {
		const file = `${ plantedRecipesDir }/${ slug }.json`;
		const found = planted.find( ( recipe ) => recipe.slug === slug );
		if ( ! found ) {
			recipePlantProblems.push( `${ file } is missing` );
			continue;
		}
		const before = recipePlantProblems.length;
		if ( ! found.problems.some( ( p ) => p.rule === rule ) ) {
			recipePlantProblems.push( `${ file } passes the ${ rule } rule` );
		}
		for ( const p of found.problems.filter( ( q ) => q.rule !== rule ) ) {
			recipePlantProblems.push(
				`${ p.line }, a ${ p.rule } problem in the ${ rule } plant`
			);
		}
		if ( recipePlantProblems.length === before ) {
			refusedRecipes++;
		}
	}
}
const rhythmPlantCount = Object.keys( RHYTHM_PLANTS ).length;
report(
	`rhythm: rejects ${ refusedRecipes } of ${ rhythmPlantCount } planted recipes in ${ plantedRecipesDir }/`,
	refusedRecipes === rhythmPlantCount && recipePlantProblems.length === 0,
	recipePlantProblems.length
		? `${ plural( recipePlantProblems.length, 'problem' ) }, below`
		: 'each refused for its own rule and no other'
);
printEach( recipePlantProblems );

// A section with no style is plain, as compose reads it (read_recipe() in
// inc/abilities/recipes.php), so the gate and design-check's recipe pages
// build the page compose builds: the home recipe with its Panel proof strip's
// style taken out passes, and with a style that is no string it is refused.
let styleless = [];
let unreadable = [];
let styleError = '';
try {
	const layouts = sectionLayouts( join( root, RECIPE_INTENTS ) );
	const home = readRecipes( join( root, RECIPES ) ).find(
		( entry ) => entry.slug === 'home'
	);
	const without = structuredClone( home );
	delete without.recipe.sections[ 1 ].style;
	const numbered = structuredClone( home );
	numbered.recipe.sections[ 1 ].style = 5;
	styleless = rhythmProblems( without, layouts );
	unreadable = rhythmProblems( numbered, layouts );
} catch ( error ) {
	styleError = `the gate crashed: ${ error?.message || error }`;
}
report(
	'rhythm: reads a section with no style as plain, as compose does, and refuses a style that is no string',
	styleError === '' &&
		styleless.length === 0 &&
		unreadable.length === 1 &&
		unreadable[ 0 ].rule === 'shape',
	styleError ||
		[ ...styleless, ...unreadable ].map( ( p ) => p.line ).join( '; ' )
);

process.exit( failed ? 1 : 0 );
