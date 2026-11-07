#!/usr/bin/env node
/**
 * The seven WordPress.org screenshots, taken from the demo pages.
 *
 *   npm run screenshots                          boot a fresh dev site, write all seven
 *   npm run screenshots -- --only 3,6            just those numbers
 *   npm run screenshots -- --url http://127.0.0.1:9400
 *                                                use a site npm run dev is already serving
 *
 * Writes .wordpress-org/screenshot-1.png to screenshot-7.png, 1280 px wide:
 * 1 CTA band, 2 Proof strip, 3 Logo marquee, 4 Closing CTA, 5 Contact section,
 * 6 Icon block, 7 Settings > Site Data. Pattern shots are taken as a logged-out
 * visitor and cropped to the page content, with animations held at their
 * first frame. Shot 7 is the settings screen as the logged-in admin.
 *
 * Needs Chrome, Chromium or Edge. Set CHROME_PATH if it is not in the usual place.
 */
import { appendFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { isAbsolute, join, relative, resolve } from 'node:path';

import { boot, DEMO_PATH, parseFlags, SETTINGS_PATH, verify } from './dev.mjs';
import { findChrome, launch } from './lib/chrome.mjs';
import { annotate, endGroup, group } from './lib/log.mjs';
import { nodeVersionProblem } from './lib/node-version.mjs';
import { repo } from './lib/site.mjs';

const WIDTH = 1280;
const HEIGHT = 900;
const PADDING = 40;
const TIMEOUT_MINUTES = 20;

/** The shared order. Changing it changes what readme.txt's captions describe. */
const SHOTS = [
	{ n: 1, name: 'CTA band', path: `${ DEMO_PATH }cta-band/` },
	{ n: 2, name: 'Proof strip', path: `${ DEMO_PATH }proof-strip/` },
	{ n: 3, name: 'Logo marquee', path: `${ DEMO_PATH }logo-marquee/` },
	{ n: 4, name: 'Closing CTA', path: `${ DEMO_PATH }closing-cta/` },
	{ n: 5, name: 'Contact section', path: `${ DEMO_PATH }contact-section/` },
	{ n: 6, name: 'Icon block', path: `${ DEMO_PATH }icon/` },
	{ n: 7, name: 'Settings > Site Data', path: SETTINGS_PATH, admin: true },
];

const HELP = `npm run screenshots [-- options]

Boots the dev site (npm run dev) and writes the WordPress.org screenshots to
.wordpress-org/screenshot-1.png to screenshot-7.png with headless Chrome.

  --only <list>    Only these numbers, such as --only 3,6.
  --url <url>      Use a dev site that is already running instead of booting one.
  --out <dir>      Write somewhere else. Default .wordpress-org.
  --debug          Print Playground's debug output.
  --help           Show this help.

Set CHROME_PATH to use a particular Chrome, Chromium or Edge binary.`;

/**
 * Waits for fonts and images, holds every animation at its first frame,
 * and measures what to crop. Runs in the page.
 *
 * @param {boolean} admin Settings screen rather than a demo page.
 * @return {string} An expression for Runtime.evaluate.
 */
function measure( admin ) {
	return `( async () => {
		for ( const img of document.images ) {
			img.loading = 'eager';
		}
		const settle = Promise.all( [ ...document.images ].map( ( img ) =>
			img.complete ? null : new Promise( ( done ) => {
				img.addEventListener( 'load', done, { once: true } );
				img.addEventListener( 'error', done, { once: true } );
			} )
		) );
		await Promise.race( [ settle, new Promise( ( done ) => setTimeout( done, 15000 ) ) ] );
		await document.fonts.ready;
		for ( const animation of document.getAnimations() ) {
			try {
				animation.pause();
				animation.currentTime = 0;
			} catch {}
		}
		await new Promise( ( done ) => requestAnimationFrame( () => requestAnimationFrame( done ) ) );

		const docHeight = document.documentElement.scrollHeight;
		const adminBar = !! document.getElementById( 'wpadminbar' );
		if ( ${ admin } ) {
			const wrap = document.querySelector( '#wpbody-content .wrap' );
			return {
				path: location.pathname,
				adminBar,
				docHeight,
				top: 0,
				bottom: wrap ? wrap.getBoundingClientRect().bottom + scrollY + 24 : 0,
			};
		}
		const content = document.querySelector( '.wp-block-post-content, .entry-content, main' );
		if ( ! content ) {
			return { path: location.pathname, adminBar, docHeight, top: 0, bottom: 0 };
		}
		const parts = [ ...content.children ].filter( ( el ) => el.getBoundingClientRect().height > 0 );
		const rects = ( parts.length ? parts : [ content ] ).map( ( el ) => el.getBoundingClientRect() );
		return {
			path: location.pathname,
			adminBar,
			docHeight,
			top: Math.min( ...rects.map( ( r ) => r.top ) ) + scrollY,
			bottom: Math.max( ...rects.map( ( r ) => r.bottom ) ) + scrollY,
		};
	} )()`;
}

/**
 * Take one screenshot.
 *
 * @param {Object} browser From launch().
 * @param {string} base    Site URL.
 * @param {Object} shot    One of SHOTS.
 * @return {Promise<{png: Buffer, width: number, height: number}>} The image.
 */
async function take( browser, base, shot ) {
	const url = `${ base }${ shot.path }`;
	// Playground logs every new browser in as admin unless this cookie says
	// it already did. Demo pages are shot as a visitor sees them.
	const cookies = shot.admin
		? []
		: [
				{
					name: 'playground_auto_login_already_happened',
					value: '1',
					url: base,
				},
			];
	const page = await browser.newPage( {
		width: WIDTH,
		height: HEIGHT,
		cookies,
	} );
	try {
		await page.goto( url );
		let box = await page.evaluate( measure( !! shot.admin ) );

		if ( shot.admin ) {
			if (
				! box.path.endsWith( '/options-general.php' ) ||
				! box.adminBar
			) {
				throw new Error(
					`${ url } did not open as the logged-in admin (landed on ${ box.path }).`
				);
			}
		} else if ( box.adminBar ) {
			// Logged in after all: hide the toolbar rather than shoot it.
			await page.evaluate(
				`document.getElementById( 'wpadminbar' ).remove(); document.documentElement.style.setProperty( 'margin-top', '0', 'important' ); true`
			);
			box = await page.evaluate( measure( false ) );
			console.log(
				`     the admin toolbar showed on ${ shot.path }; hid it`
			);
		}

		const top = Math.max(
			0,
			Math.floor( box.top - ( shot.admin ? 0 : PADDING ) )
		);
		const bottom = Math.min(
			box.docHeight,
			Math.ceil( box.bottom + ( shot.admin ? 0 : PADDING ) )
		);
		const height = bottom - top;
		if ( height < 40 ) {
			throw new Error(
				`${ url } has nothing to show (content is ${ Math.max( 0, height ) } px tall).`
			);
		}
		const png = await page.screenshot( {
			x: 0,
			y: top,
			width: WIDTH,
			height,
		} );
		return { png, width: WIDTH, height };
	} finally {
		await page.close().catch( () => {} );
	}
}

/**
 * @param {Object} opts Parsed flags.
 * @return {string} Where the PNGs go.
 */
function outDir( opts ) {
	return resolve( repo, opts.out || '.wordpress-org' );
}

/**
 * @param {string} file
 * @return {string} The path relative to the checkout when it is inside it.
 */
function shown( file ) {
	const rel = relative( repo, file );
	return rel.startsWith( '..' ) || isAbsolute( rel ) ? file : rel || '.';
}

/**
 * @param {Object} opts Parsed flags.
 * @return {Promise<number>} Exit code.
 */
async function run( opts ) {
	const only = opts.only
		? new Set( String( opts.only ).split( ',' ).map( Number ) )
		: null;
	const shots = SHOTS.filter( ( shot ) => ! only || only.has( shot.n ) );
	if ( ! shots.length ) {
		console.error( `--only matched none of 1 to ${ SHOTS.length }.` );
		return 2;
	}
	const chrome = findChrome();
	if ( ! chrome ) {
		annotate(
			'error',
			process.env.CHROME_PATH
				? `CHROME_PATH points to ${ process.env.CHROME_PATH }, which does not exist.`
				: 'No Chrome, Chromium or Edge found. Install one, or set CHROME_PATH to its executable.',
			'npm run screenshots'
		);
		return 1;
	}

	let site;
	let browser;
	let code = 0;
	const written = [];
	try {
		if ( opts.url ) {
			site = { url: String( opts.url ).replace( /\/$/, '' ) };
		} else {
			group( 'Boot the dev site' );
			try {
				site = await boot( opts );
			} finally {
				endGroup();
			}
		}

		const { failed } = await verify( site );
		if ( failed.length ) {
			for ( const message of failed ) {
				annotate( 'error', message, 'npm run screenshots' );
			}
			return 1;
		}

		browser = await launch( chrome );
		mkdirSync( outDir( opts ), { recursive: true } );
		for ( const shot of shots ) {
			const file = join( outDir( opts ), `screenshot-${ shot.n }.png` );
			try {
				const { png, width, height } = await take(
					browser,
					site.url,
					shot
				);
				writeFileSync( file, png );
				written.push( {
					...shot,
					file,
					width,
					height,
					bytes: png.length,
				} );
				console.log(
					`ok   ${ shown( file ) }  ${ shot.name }  ${ width }x${ height }`
				);
			} catch ( error ) {
				annotate(
					'error',
					`screenshot-${ shot.n } (${ shot.name }): ${ error.message }`,
					'npm run screenshots'
				);
				code = 1;
			}
		}
	} catch ( error ) {
		annotate( 'error', error.message, 'npm run screenshots' );
		if ( opts.debug ) {
			console.error( error.stack );
		}
		code = 1;
	} finally {
		await browser?.close();
		await site?.stop?.();
	}

	if ( process.env.GITHUB_STEP_SUMMARY && written.length ) {
		appendFileSync(
			process.env.GITHUB_STEP_SUMMARY,
			[
				'### WordPress.org screenshots',
				'',
				'Download the `screenshots` artifact from this run.',
				'',
				'| File | Shows | Size |',
				'|---|---|---|',
				...written.map(
					( shot ) =>
						`| screenshot-${ shot.n }.png | ${ shot.name.replace( '>', '&gt;' ) } | ${ shot.width }x${ shot.height }, ${ Math.round( shot.bytes / 1024 ) } KB |`
				),
				'',
			].join( '\n' )
		);
	}
	if ( code === 0 ) {
		annotate(
			'notice',
			`Wrote ${ written.length } screenshots to ${ shown( outDir( opts ) ) }.`,
			'npm run screenshots'
		);
	}
	return code;
}

const problem = nodeVersionProblem();
if ( problem ) {
	console.error( problem );
	process.exit( 1 );
}
const opts = parseFlags( process.argv.slice( 2 ) );
if ( opts.help ) {
	console.log( HELP );
	process.exit( 0 );
}
setTimeout( () => {
	annotate(
		'error',
		`npm run screenshots gave up after ${ TIMEOUT_MINUTES } minutes.`
	);
	process.exit( 1 );
}, TIMEOUT_MINUTES * 60000 ).unref();
process.exit( await run( opts ) );
