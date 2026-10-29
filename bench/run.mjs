#!/usr/bin/env node
/**
 * Runs the with/without benchmark (VOY-1774): per brief and arm, a fresh
 * WordPress site, a build prompt and an edit prompt through headless Claude
 * Code, with MCP pointed at that site.
 *
 *   node bench/run.mjs prepare [--work DIR]
 *   node bench/run.mjs run [--arms baseline,loop] [--briefs id,id | --split derive] [--limit N]
 *                          [--model ID] [--use-login] [--force] [--keep] [--work DIR]
 *
 * `prepare` builds one template site per arm under <work>/templates: WordPress
 * on SQLite (the README's "Local site" setup), Twenty Twenty-Five, the
 * WordPress MCP Adapter and bench/harness/site-tools.php as an mu-plugin. The
 * `loop` template also gets this pack, copied from the package.json `files`
 * list, which is what the release zip ships.
 *
 * `run` copies the arm's template for each brief, so every run starts from the
 * same fresh site and nothing carries over. It writes the brief's business
 * details (blogname and the voyager_site_* options, in both arms), serves the
 * copy with Playground, and runs the two prompts from bench/README.md in one
 * Claude Code session. The `loop` arm also gets the pack's skills (as a plugin
 * directory), a Wesper manifest of its own site and Block Runner. Results land
 * in bench/runs/<arm>/<brief-id>/: page.html, after-edit.html, site-data.json,
 * run.json and both transcripts.
 *
 * Runs go one at a time: one Playground site is up at any moment, and it is
 * shut down before the next boots. Four sites booting at once killed
 * Playground with a V8 fatal error (exit 2147483651) on Windows. A run whose
 * folder is complete (run.json finished, with no harness failure) is skipped,
 * so a crash costs one run; --force runs it again.
 *
 * Needs `wp`, `php` and `claude` on PATH, and a Node that Block Runner
 * accepts (22.23.4 or 24.22.1 and later; 25 fails): the agent's PATH starts
 * with this Node. --use-login drops ANTHROPIC_API_KEY from the agent's
 * environment so Claude Code uses its claude.ai login.
 */
import { execFileSync, spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
	copyFileSync,
	cpSync,
	createWriteStream,
	existsSync,
	mkdirSync,
	readFileSync,
	renameSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs';
import http from 'node:http';
import { freemem } from 'node:os';
import { delimiter, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { flags, phpEnv, repo, wp } from '../scripts/lib/site.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );
const opts = flags( process.argv.slice( 2 ) );
const work = resolve(
	opts.work || join( repo, '..', 'voyager-open-blocks-bench' )
);
const ARMS = [ 'baseline', 'loop' ];
const MODEL = opts.model || 'claude-sonnet-5';
const MCP_ADAPTER = 'v0.7.1';
const THEME = 'twentytwentyfive';
const STEP_TIMEOUT_MS = 30 * 60 * 1000;
const STEP_BUDGET_USD = '10';
const PORT = 9471;
// Below this much free memory a site is not booted.
const MIN_FREE_GB = 1.5;
const playgroundCli = join(
	repo,
	'node_modules',
	'@wp-playground',
	'cli',
	'wp-playground.js'
);

// ---------------------------------------------------------------------------
// prepare

async function download( url, file ) {
	if ( existsSync( file ) ) {
		return file;
	}
	const response = await fetch( url );
	if ( ! response.ok ) {
		throw new Error( `Download failed (${ response.status }): ${ url }` );
	}
	mkdirSync( dirname( file ), { recursive: true } );
	writeFileSync( file, Buffer.from( await response.arrayBuffer() ) );
	return file;
}

function unzip( file, into, env ) {
	mkdirSync( into, { recursive: true } );
	execFileSync(
		'php',
		[
			'-r',
			'$z = new ZipArchive(); if ( true !== $z->open( $argv[1] ) ) { exit( 1 ); } $z->extractTo( $argv[2] ); $z->close();',
			file,
			into,
		],
		{ env, stdio: 'inherit' }
	);
}

async function prepare() {
	const cache = join( work, 'cache' );
	const zips = {
		wordpress: await download(
			'https://wordpress.org/latest.zip',
			join( cache, 'wordpress.zip' )
		),
		sqlite: await download(
			'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip',
			join( cache, 'sqlite-database-integration.zip' )
		),
		mcp: await download(
			`https://github.com/WordPress/mcp-adapter/releases/download/${ MCP_ADAPTER }/mcp-adapter.zip`,
			join( cache, `mcp-adapter-${ MCP_ADAPTER }.zip` )
		),
	};

	for ( const arm of ARMS ) {
		const site = join( work, 'templates', arm );
		rmSync( site, { recursive: true, force: true } );
		rmSync( `${ site }-php`, { recursive: true, force: true } );
		const env = phpEnv( site );
		const staging = `${ site }-download`;
		rmSync( staging, { recursive: true, force: true } );
		unzip( zips.wordpress, staging, env );
		cpSync( join( staging, 'wordpress' ), site, { recursive: true } );
		rmSync( staging, { recursive: true, force: true } );
		const plugins = join( site, 'wp-content', 'plugins' );
		unzip( zips.sqlite, plugins, env );
		unzip( zips.mcp, plugins, env );
		copyFileSync(
			join( plugins, 'sqlite-database-integration', 'db.copy' ),
			join( site, 'wp-content', 'db.php' )
		);
		mkdirSync( join( site, 'wp-content', 'mu-plugins' ), {
			recursive: true,
		} );
		copyFileSync(
			join( here, 'harness', 'site-tools.php' ),
			join( site, 'wp-content', 'mu-plugins', 'bench-site-tools.php' )
		);

		const cli = ( args ) => wp( site, args, env ).trim();
		cli( [
			'config',
			'create',
			'--dbname=wordpress',
			'--dbuser=wordpress',
			'--dbpass=wordpress',
			'--skip-check',
			'--force',
		] );
		cli( [ 'config', 'set', 'SQLITE_JOURNAL_MODE', 'DELETE' ] );
		cli( [ 'config', 'set', 'WP_ENVIRONMENT_TYPE', 'local' ] );
		cli( [
			'core',
			'install',
			'--url=http://127.0.0.1:9470',
			'--title=Example Co.',
			'--admin_user=admin',
			'--admin_password=password',
			'--admin_email=admin@example.com',
			'--skip-email',
		] );
		if ( ! existsSync( join( site, 'wp-content', 'themes', THEME ) ) ) {
			cli( [ 'theme', 'install', THEME ] );
		}
		cli( [ 'theme', 'activate', THEME ] );
		cli( [ 'rewrite', 'structure', '/%postname%/' ] );
		cli( [ 'option', 'update', 'blog_public', '0' ] );
		cli( [ 'plugin', 'activate', 'mcp-adapter' ] );

		if ( arm === 'loop' ) {
			const pack = join( plugins, 'voyager-open-blocks' );
			const { files } = JSON.parse(
				readFileSync( join( repo, 'package.json' ), 'utf8' )
			);
			for ( const f of [ ...files, 'package.json' ] ) {
				cpSync( join( repo, f ), join( pack, f ), { recursive: true } );
			}
			cli( [ 'plugin', 'activate', 'voyager-open-blocks' ] );
		}

		const password = cli( [
			'user',
			'application-password',
			'create',
			'admin',
			'bench',
			'--porcelain',
		] );
		const pages = cli( [
			'post',
			'list',
			'--post_type=page',
			'--post_status=any',
			'--field=ID',
		] )
			.split( /\s+/ )
			.filter( Boolean )
			.map( Number );
		const meta = {
			arm,
			password,
			pages,
			wordpress: cli( [ 'core', 'version' ] ),
			theme: `${ THEME } ${ cli( [
				'theme',
				'get',
				THEME,
				'--field=version',
			] ) }`,
			plugins: JSON.parse(
				cli( [
					'plugin',
					'list',
					'--status=active',
					'--fields=name,version',
					'--format=json',
				] )
			),
			pack_commit: git( [ 'rev-parse', 'HEAD' ] ),
		};
		writeFileSync(
			join( work, 'templates', `${ arm }.json` ),
			JSON.stringify( meta, null, 2 ) + '\n'
		);
		console.log(
			`template ${ arm }: WordPress ${ meta.wordpress }, ${ meta.plugins
				.map( ( p ) => `${ p.name } ${ p.version }` )
				.join( ', ' ) }`
		);
	}
}

// ---------------------------------------------------------------------------
// run

function prompts() {
	const readme = readFileSync( join( here, 'README.md' ), 'utf8' );
	const quote = ( label ) => {
		const m = readme.match(
			new RegExp( `\\*\\*${ label }\\.\\*\\*[^\\n]*\\n+> ([^\\n]+)` )
		);
		if ( ! m ) {
			throw new Error( `No ${ label } prompt in bench/README.md` );
		}
		return m[ 1 ];
	};
	return { build: quote( 'Build' ), edit: quote( 'Edit' ) };
}

function fill( template, brief ) {
	return template.replace( /\{([a-z_.]+)\}/g, ( all, path ) => {
		const value = path
			.split( '.' )
			.reduce( ( v, k ) => ( v == null ? v : v[ k ] ), brief );
		if ( value == null ) {
			throw new Error( `Brief ${ brief.id } has no ${ path }` );
		}
		return Array.isArray( value ) ? value.join( ', ' ) : String( value );
	} );
}

function git( args ) {
	return execFileSync( 'git', args, { cwd: repo, encoding: 'utf8' } ).trim();
}

function versionOf( pkg ) {
	return JSON.parse(
		readFileSync(
			join( repo, 'node_modules', pkg, 'package.json' ),
			'utf8'
		)
	).version;
}

function get( url, auth ) {
	return new Promise( ( done ) => {
		const req = http.get(
			url,
			{
				agent: false,
				headers: auth ? { Authorization: `Basic ${ auth }` } : {},
			},
			( res ) => {
				let body = '';
				res.on( 'data', ( c ) => ( body += c ) );
				res.on( 'end', () => done( { status: res.statusCode, body } ) );
			}
		);
		req.on( 'error', ( e ) => done( { status: 0, body: e.message } ) );
		req.setTimeout( 30000, () => req.destroy( new Error( 'timeout' ) ) );
	} );
}

// Playground can die at boot with a V8 fatal error, so a failed boot is
// retried once.
async function serve( site, port, log ) {
	try {
		return await boot( site, port, log );
	} catch ( e ) {
		return await boot( site, port, `${ log }.retry` );
	}
}

async function boot( site, port, log ) {
	if ( ( await get( `http://127.0.0.1:${ port }/` ) ).status !== 0 ) {
		throw new Error( `Something already answers on port ${ port }` );
	}
	const free = freemem() / 2 ** 30;
	if ( free < MIN_FREE_GB ) {
		throw new Error( `Only ${ free.toFixed( 1 ) } GB free, not booting` );
	}
	const child = spawn(
		process.execPath,
		[
			playgroundCli,
			'server',
			`--port=${ port }`,
			`--site-url=http://127.0.0.1:${ port }`,
			'--php=8.3',
			'--workers=2',
			'--wordpress-install-mode=do-not-attempt-installing',
			'--mount-dir-before-install',
			site,
			'/wordpress',
		],
		{ env: { ...process.env, MSYS_NO_PATHCONV: '1' } }
	);
	const out = createWriteStream( log );
	child.stdout.pipe( out );
	child.stderr.pipe( out );
	const deadline = Date.now() + 180000;
	while ( Date.now() < deadline ) {
		if ( child.exitCode !== null ) {
			throw new Error(
				`Playground exited (${ child.exitCode }), see ${ log }`
			);
		}
		const res = await get( `http://127.0.0.1:${ port }/wp-json/` );
		if ( res.status === 200 ) {
			return child;
		}
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
	}
	await stop( child );
	throw new Error( `Playground did not answer on port ${ port }` );
}

/**
 * Kill a child and its tree, and wait until it has exited.
 * @param child
 */
async function stop( child ) {
	if ( ! child || child.exitCode !== null || child.signalCode !== null ) {
		return;
	}
	const exited = new Promise( ( r ) => child.once( 'exit', r ) );
	if ( process.platform === 'win32' ) {
		try {
			execFileSync(
				'taskkill',
				[ '/pid', String( child.pid ), '/T', '/F' ],
				{
					stdio: 'ignore',
				}
			);
		} catch {}
	} else {
		child.kill( 'SIGTERM' );
	}
	await Promise.race( [
		exited,
		new Promise( ( r ) => setTimeout( r, 15000 ) ),
	] );
}

/**
 * One headless Claude Code turn. Resolves with the parsed stream and the exit.
 * @param root0
 * @param root0.cwd
 * @param root0.args
 * @param root0.transcript
 * @param root0.useLogin
 */
function agent( { cwd, args, transcript, useLogin } ) {
	// This Node first, so the agent's npx runs Block Runner on a version it
	// accepts.
	const env = {
		...process.env,
		PATH: `${ dirname( process.execPath ) }${ delimiter }${ process.env.PATH }`,
	};
	if ( useLogin ) {
		delete env.ANTHROPIC_API_KEY;
	}
	return new Promise( ( done ) => {
		const started = Date.now();
		const child = spawn( 'claude', args, {
			cwd,
			env,
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
		const out = createWriteStream( transcript );
		let buffer = '';
		let stderr = '';
		child.stdout.on( 'data', ( c ) => {
			out.write( c );
			buffer += c;
		} );
		child.stderr.on( 'data', ( c ) => ( stderr += c ) );
		const timer = setTimeout( () => {
			stderr += `\n[bench] killed after ${ STEP_TIMEOUT_MS / 60000 } minutes`;
			stop( child );
		}, STEP_TIMEOUT_MS );
		child.on( 'close', ( code ) => {
			clearTimeout( timer );
			out.end();
			const events = buffer
				.split( '\n' )
				.filter( Boolean )
				.map( ( l ) => {
					try {
						return JSON.parse( l );
					} catch {
						return null;
					}
				} )
				.filter( Boolean );
			const init = events.find(
				( e ) => e.type === 'system' && e.subtype === 'init'
			);
			const result = events.findLast( ( e ) => e.type === 'result' );
			const models = new Set(
				events
					.filter( ( e ) => e.type === 'assistant' )
					.map( ( e ) => e.message?.model )
					.filter( Boolean )
			);
			const tools = {};
			for ( const e of events.filter(
				( x ) => x.type === 'assistant'
			) ) {
				for ( const part of e.message?.content || [] ) {
					if ( part.type === 'tool_use' ) {
						const key =
							part.name ===
							'mcp__wordpress__mcp-adapter-execute-ability'
								? `ability:${ part.input?.ability_name ?? '?' }`
								: part.name;
						tools[ key ] = ( tools[ key ] || 0 ) + 1;
					}
				}
			}
			done( {
				exit: code,
				seconds: Math.round( ( Date.now() - started ) / 1000 ),
				init: init
					? {
							model: init.model,
							tools: init.tools,
							mcp_servers: init.mcp_servers,
							skills: init.skills,
							plugins: init.plugins?.map( ( p ) => p.name ),
							claude_code_version: init.claude_code_version,
						}
					: null,
				models: [ ...models ],
				is_error: result?.is_error ?? true,
				subtype: result?.subtype ?? null,
				turns: result?.num_turns ?? null,
				cost_usd: result?.total_cost_usd ?? null,
				result: result?.result ?? null,
				tool_calls: tools,
				stderr: stderr.trim().slice( -2000 ),
			} );
		} );
	} );
}

function businessOptions( brief ) {
	return {
		blogname: brief.business,
		voyager_site_phone: brief.phone,
		voyager_site_email: brief.email,
		voyager_site_address: brief.address,
		voyager_site_address_city: brief.address_city,
		voyager_site_address_state: brief.address_state,
		voyager_site_address_zip: brief.address_zip,
		voyager_site_hours: brief.hours,
	};
}

async function runOne( arm, brief, port, text, useLogin ) {
	const out = join( here, 'runs', arm, brief.id );
	rmSync( out, { recursive: true, force: true } );
	mkdirSync( out, { recursive: true } );
	const template = JSON.parse(
		readFileSync( join( work, 'templates', `${ arm }.json` ), 'utf8' )
	);
	const site = join( work, 'sites', `${ arm }-${ brief.id }` );
	const desk = join( work, 'agents', `${ arm }-${ brief.id }` );
	for ( const d of [ site, `${ site }-php`, desk ] ) {
		rmSync( d, { recursive: true, force: true } );
	}
	const record = {
		arm,
		brief: brief.id,
		split: brief.split,
		model: MODEL,
		port,
		started: new Date().toISOString(),
		wordpress: template.wordpress,
		theme: template.theme,
		plugins: template.plugins,
		pack_commit: arm === 'loop' ? template.pack_commit : null,
		block_runner: versionOf( 'block-runner' ),
		wesper: arm === 'loop' ? versionOf( 'wesper' ) : null,
		playground_cli: versionOf( '@wp-playground/cli' ),
		prompts: {
			build: fill( text.build, brief ),
			edit: fill( text.edit, brief ),
		},
		steps: {},
		page_id: null,
		failure: null,
	};
	// Write then rename, so a crash mid-write cannot leave a torn run.json.
	const save = () => {
		writeFileSync(
			join( out, 'run.json.tmp' ),
			JSON.stringify( record, null, 2 ) + '\n'
		);
		renameSync( join( out, 'run.json.tmp' ), join( out, 'run.json' ) );
	};

	let server = null;
	try {
		cpSync( join( work, 'templates', arm ), site, { recursive: true } );
		const env = phpEnv( site );
		const cli = ( args ) => wp( site, args, env ).trim();
		const url = `http://127.0.0.1:${ port }`;
		cli( [ 'option', 'update', 'siteurl', url ] );
		cli( [ 'option', 'update', 'home', url ] );
		for ( const [ option, value ] of Object.entries(
			businessOptions( brief )
		) ) {
			cli( [ 'option', 'update', option, value ] );
		}

		mkdirSync( desk, { recursive: true } );
		const auth = Buffer.from( `admin:${ template.password }` ).toString(
			'base64'
		);
		writeFileSync(
			join( desk, '.mcp-bench.json' ),
			JSON.stringify( {
				mcpServers: {
					wordpress: {
						type: 'http',
						url: `${ url }/wp-json/mcp/mcp-adapter-default-server`,
						headers: { Authorization: `Basic ${ auth }` },
					},
				},
			} )
		);

		const common = [
			'--model',
			MODEL,
			'--restricted',
			'--strict-mcp-config',
			'--mcp-config',
			join( desk, '.mcp-bench.json' ),
			'--permission-mode',
			'dontAsk',
			'--output-format',
			'stream-json',
			'--verbose',
			'--max-budget-usd',
			STEP_BUDGET_USD,
		];
		let toolArgs;
		if ( arm === 'loop' ) {
			// The pack's skills as a plugin, the site's own Wesper manifest,
			// the intents the skills point at, and Block Runner and Wesper
			// through npx.
			const plugin = join( desk, '.pack-skills' );
			mkdirSync( join( plugin, '.claude-plugin' ), { recursive: true } );
			writeFileSync(
				join( plugin, '.claude-plugin', 'plugin.json' ),
				JSON.stringify( {
					name: 'voyager-open-blocks',
					version: JSON.parse(
						readFileSync( join( repo, 'package.json' ), 'utf8' )
					).version,
					description: 'Voyager Open Blocks skills',
				} )
			);
			cpSync( join( repo, 'skills' ), join( plugin, 'skills' ), {
				recursive: true,
			} );
			cpSync(
				join( repo, 'patterns', 'intents' ),
				join( desk, 'patterns', 'intents' ),
				{ recursive: true }
			);
			symlinkSync(
				join( repo, 'node_modules' ),
				join( desk, 'node_modules' ),
				'junction'
			);
			execFileSync(
				process.execPath,
				[
					join( repo, 'node_modules', 'wesper', 'dist', 'cli.js' ),
					'collect',
					'--wp-path',
					site,
					'--out',
					join( desk, 'site.context.json' ),
				],
				{ env, stdio: 'ignore' }
			);
			copyFileSync(
				join( desk, 'site.context.json' ),
				join( out, 'wesper-manifest.json' )
			);
			toolArgs = [
				'--plugin-dir',
				plugin,
				'--tools',
				'Read,Glob,Grep,Write,Edit,Bash,Skill',
				'--allowedTools',
				'mcp__wordpress',
				'Read',
				'Glob',
				'Grep',
				'Write',
				'Edit',
				'Skill',
				'Bash(npx block-runner *)',
				'Bash(npx wesper *)',
			];
		} else {
			toolArgs = [ '--tools', '', '--allowedTools', 'mcp__wordpress' ];
		}

		record.free_gb_at_boot =
			Math.round( ( freemem() / 2 ** 30 ) * 10 ) / 10;
		server = await serve(
			site,
			port,
			join( work, `server-${ port }.log` )
		);

		const session = randomUUID();
		record.session_id = session;
		record.steps.build = await agent( {
			cwd: desk,
			args: [
				'-p',
				record.prompts.build,
				'--session-id',
				session,
				...common,
				...toolArgs,
			],
			transcript: join( out, 'transcript-build.jsonl' ),
			useLogin,
		} );
		save();

		const pages = JSON.parse(
			cli( [
				'post',
				'list',
				'--post_type=page',
				'--post_status=any',
				'--fields=ID,post_title,post_status,post_modified_gmt',
				'--format=json',
			] )
		).filter( ( p ) => ! template.pages.includes( Number( p.ID ) ) );
		record.new_pages = pages;
		const published = pages.filter( ( p ) => p.post_status === 'publish' );
		const pick = ( published.length ? published : pages ).sort(
			( a, b ) =>
				b.post_modified_gmt.localeCompare( a.post_modified_gmt ) ||
				Number( b.ID ) - Number( a.ID )
		)[ 0 ];
		const buildError = record.steps.build.is_error
			? `build: ${ record.steps.build.subtype || 'error' }`
			: null;
		if ( ! pick ) {
			record.failure = [ buildError, 'build: no new page on the site' ]
				.filter( Boolean )
				.join( '; ' );
			return record;
		}
		record.page_id = Number( pick.ID );
		record.page_status = pick.post_status;
		const content = () =>
			wp(
				site,
				[
					'post',
					'get',
					String( record.page_id ),
					'--field=post_content',
				],
				env
			).replace( /\r?\n$/, '' );
		writeFileSync( join( out, 'page.html' ), content() );
		save();

		record.steps.edit = await agent( {
			cwd: desk,
			args: [
				'-p',
				record.prompts.edit,
				'--resume',
				session,
				...common,
				...toolArgs,
			],
			transcript: join( out, 'transcript-edit.jsonl' ),
			useLogin,
		} );
		writeFileSync( join( out, 'after-edit.html' ), content() );
		const after = {};
		for ( const [ option ] of Object.entries( businessOptions( brief ) ) ) {
			after[
				option === 'blogname'
					? 'company'
					: option.replace( 'voyager_site_', '' )
			] = cli( [ 'option', 'get', option ] );
		}
		writeFileSync(
			join( out, 'site-data.json' ),
			JSON.stringify( after, null, 2 ) + '\n'
		);
		const editError = record.steps.edit.is_error
			? `edit: ${ record.steps.edit.subtype || 'error' }`
			: null;
		record.failure =
			[ buildError, editError ].filter( Boolean ).join( '; ' ) || null;
		return record;
	} catch ( e ) {
		record.failure = `harness: ${ e.message.split( '\n' )[ 0 ] }`;
		return record;
	} finally {
		await stop( server );
		record.finished = new Date().toISOString();
		save();
		if ( ! opts.keep ) {
			for ( const d of [ site, `${ site }-php`, desk ] ) {
				try {
					rmSync( d, { recursive: true, force: true } );
				} catch {}
			}
		}
	}
}

async function run() {
	const { briefs } = JSON.parse(
		readFileSync( join( here, 'briefs.json' ), 'utf8' )
	);
	const arms = ( opts.arms || ARMS.join( ',' ) ).split( ',' );
	let chosen = briefs;
	if ( opts.briefs ) {
		const ids = opts.briefs.split( ',' );
		chosen = briefs.filter( ( b ) => ids.includes( b.id ) );
	}
	if ( opts.split ) {
		chosen = chosen.filter( ( b ) => b.split === opts.split );
	}
	if ( opts.limit ) {
		chosen = chosen.slice( 0, Number( opts.limit ) );
	}
	for ( const arm of arms ) {
		if ( ! existsSync( join( work, 'templates', `${ arm }.json` ) ) ) {
			throw new Error(
				`No ${ arm } template. Run: node bench/run.mjs prepare`
			);
		}
	}
	const text = prompts();
	for ( const brief of chosen ) {
		for ( const arm of arms ) {
			if ( ! opts.force && complete( arm, brief ) ) {
				console.log( `${ arm }/${ brief.id }: complete, skipped` );
				continue;
			}
			const r = await runOne(
				arm,
				brief,
				PORT,
				text,
				Boolean( opts[ 'use-login' ] )
			);
			const cost = [ r.steps.build?.cost_usd, r.steps.edit?.cost_usd ]
				.filter( ( c ) => c != null )
				.reduce( ( a, b ) => a + b, 0 );
			console.log(
				`${ arm }/${ brief.id }: ${ r.failure || 'ok' } (page ${ r.page_id ?? '-' }, $${ cost.toFixed( 2 ) })`
			);
		}
	}
}

/**
 * A run is complete when its run.json finished with no harness failure. A
 * failure the agent caused is a result, so it is kept.
 * @param arm
 * @param brief
 */
function complete( arm, brief ) {
	try {
		const r = JSON.parse(
			readFileSync(
				join( here, 'runs', arm, brief.id, 'run.json' ),
				'utf8'
			)
		);
		return Boolean( r.finished ) && ! /^harness:/.test( r.failure ?? '' );
	} catch {
		return false;
	}
}

const commands = { prepare, run };
if ( ! commands[ opts._[ 0 ] ] ) {
	console.error( 'Usage: node bench/run.mjs <prepare|run> [options]' );
	process.exit( 2 );
}
await commands[ opts._[ 0 ] ]();
