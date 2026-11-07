/**
 * Drive a system Chrome or Chromium over the DevTools protocol, with no
 * browser download and no npm dependency: Node's built-in WebSocket talks to
 * Chrome started with --remote-debugging-port=0.
 *
 * Only what scripts/screenshots.mjs needs: open a page at a fixed viewport,
 * optionally with cookies, run a script in it, and capture a PNG.
 */
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { delimiter, join } from 'node:path';

/**
 * Where Chrome, Chromium or Edge usually live, in the order to try them.
 *
 * @return {string[]} Candidate executable paths.
 */
function candidates() {
	const env = process.env;
	if ( process.platform === 'win32' ) {
		const roots = [
			env.PROGRAMFILES,
			env[ 'PROGRAMFILES(X86)' ],
			env.LOCALAPPDATA,
		].filter( Boolean );
		return [
			...roots.map( ( root ) =>
				join( root, 'Google', 'Chrome', 'Application', 'chrome.exe' )
			),
			...roots.map( ( root ) =>
				join( root, 'Chromium', 'Application', 'chrome.exe' )
			),
			...roots.map( ( root ) =>
				join( root, 'Microsoft', 'Edge', 'Application', 'msedge.exe' )
			),
		];
	}
	if ( process.platform === 'darwin' ) {
		return [
			'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
			'/Applications/Chromium.app/Contents/MacOS/Chromium',
			'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
		];
	}
	const names = [
		'google-chrome',
		'google-chrome-stable',
		'chromium',
		'chromium-browser',
		'microsoft-edge',
	];
	const dirs = ( env.PATH || '' ).split( delimiter ).filter( Boolean );
	return [
		...names.flatMap( ( name ) =>
			dirs.map( ( dir ) => join( dir, name ) )
		),
		'/snap/bin/chromium',
	];
}

/**
 * CHROME_PATH, or the first browser found in a usual place.
 *
 * @return {string|null} An executable path, or null when none is found.
 */
export function findChrome() {
	if ( process.env.CHROME_PATH ) {
		return existsSync( process.env.CHROME_PATH )
			? process.env.CHROME_PATH
			: null;
	}
	return candidates().find( ( path ) => existsSync( path ) ) || null;
}

/**
 * Start headless Chrome and connect to it.
 *
 * @param {string} executable Path from findChrome().
 * @return {Promise<Browser>} A connected browser.
 */
export async function launch( executable ) {
	if ( typeof WebSocket === 'undefined' ) {
		throw new Error(
			'This Node has no built-in WebSocket. Use the Node version in .nvmrc.'
		);
	}
	const profile = mkdtempSync( join( tmpdir(), 'voyager-open-chrome-' ) );
	const args = [
		'--headless=new',
		'--remote-debugging-port=0',
		`--user-data-dir=${ profile }`,
		'--no-first-run',
		'--no-default-browser-check',
		'--disable-gpu',
		'--disable-extensions',
		'--disable-background-networking',
		'--disable-component-update',
		'--disable-sync',
		'--hide-scrollbars',
		'--mute-audio',
		'--force-color-profile=srgb',
		'--font-render-hinting=none',
		'--password-store=basic',
		'--use-mock-keychain',
	];
	// Chrome refuses to start as root without this, which is common in containers.
	if ( process.getuid?.() === 0 ) {
		args.push( '--no-sandbox' );
	}
	args.push( 'about:blank' );

	const child = spawn( executable, args, {
		stdio: [ 'ignore', 'ignore', 'pipe' ],
		windowsHide: true,
	} );

	const endpoint = await new Promise( ( resolve, reject ) => {
		let stderr = '';
		const timer = setTimeout( () => {
			reject(
				new Error(
					`Chrome did not start in 30s.\n${ stderr.slice( -2000 ) }`
				)
			);
		}, 30000 );
		child.stderr.setEncoding( 'utf8' );
		child.stderr.on( 'data', ( chunk ) => {
			stderr += chunk;
			const match = /DevTools listening on (ws:\/\/\S+)/.exec( stderr );
			if ( match ) {
				clearTimeout( timer );
				resolve( match[ 1 ] );
			}
		} );
		child.on( 'error', ( error ) => {
			clearTimeout( timer );
			reject( error );
		} );
		child.on( 'exit', ( code ) => {
			clearTimeout( timer );
			reject(
				new Error(
					`Chrome exited with code ${ code } before it was ready.\n${ stderr.slice(
						-2000
					) }`
				)
			);
		} );
	} );

	const socket = new WebSocket( endpoint );
	await new Promise( ( resolve, reject ) => {
		socket.addEventListener( 'open', resolve, { once: true } );
		socket.addEventListener(
			'error',
			() => reject( new Error( `Could not connect to ${ endpoint }` ) ),
			{ once: true }
		);
	} );

	return new Browser( child, socket, profile );
}

class Browser {
	/**
	 * @param {import('node:child_process').ChildProcess} child
	 * @param {WebSocket}                                 socket
	 * @param {string}                                    profile Temporary user data dir.
	 */
	constructor( child, socket, profile ) {
		this.child = child;
		this.socket = socket;
		this.profile = profile;
		this.nextId = 0;
		this.pending = new Map();
		this.waiters = new Set();
		socket.addEventListener( 'message', ( event ) =>
			this.receive( JSON.parse( event.data ) )
		);
		socket.addEventListener( 'close', () => {
			for ( const { reject } of this.pending.values() ) {
				reject( new Error( 'Chrome closed the connection.' ) );
			}
			this.pending.clear();
		} );
	}

	/**
	 * @param {Object} message A DevTools protocol message.
	 */
	receive( message ) {
		if ( message.id !== undefined ) {
			const call = this.pending.get( message.id );
			this.pending.delete( message.id );
			if ( ! call ) {
				return;
			}
			if ( message.error ) {
				call.reject(
					new Error( `${ call.method }: ${ message.error.message }` )
				);
			} else {
				call.resolve( message.result );
			}
			return;
		}
		for ( const waiter of this.waiters ) {
			if (
				waiter.method === message.method &&
				waiter.sessionId === message.sessionId
			) {
				this.waiters.delete( waiter );
				waiter.resolve( message.params );
			}
		}
	}

	/**
	 * Call a protocol method.
	 *
	 * @param {string} method
	 * @param {Object} [params]
	 * @param {string} [sessionId] Page session, from attachToTarget.
	 * @return {Promise<Object>} The result.
	 */
	send( method, params = {}, sessionId ) {
		const id = ++this.nextId;
		this.socket.send(
			JSON.stringify( {
				id,
				method,
				params,
				...( sessionId ? { sessionId } : {} ),
			} )
		);
		return new Promise( ( resolve, reject ) =>
			this.pending.set( id, { resolve, reject, method } )
		);
	}

	/**
	 * Wait for one event.
	 *
	 * @param {string} method
	 * @param {string} sessionId
	 * @param {number} timeout   Milliseconds.
	 * @return {Promise<Object>} The event's params.
	 */
	once( method, sessionId, timeout ) {
		return new Promise( ( resolve, reject ) => {
			const waiter = {
				method,
				sessionId,
				resolve: ( params ) => {
					clearTimeout( timer );
					resolve( params );
				},
			};
			const timer = setTimeout( () => {
				this.waiters.delete( waiter );
				reject(
					new Error(
						`${ method } did not arrive in ${ timeout / 1000 }s`
					)
				);
			}, timeout );
			this.waiters.add( waiter );
		} );
	}

	/**
	 * Open a tab in its own browser context, so cookies never leak between pages.
	 *
	 * @param {Object}   options
	 * @param {number}   options.width   Viewport width in CSS pixels.
	 * @param {number}   options.height  Viewport height in CSS pixels.
	 * @param {Object[]} options.cookies Cookies to set first: {name, value, url}.
	 * @return {Promise<Page>} The page.
	 */
	async newPage( { width, height, cookies = [] } ) {
		const { browserContextId } = await this.send(
			'Target.createBrowserContext',
			{ disposeOnDetach: true }
		);
		if ( cookies.length ) {
			await this.send( 'Storage.setCookies', {
				cookies,
				browserContextId,
			} );
		}
		const { targetId } = await this.send( 'Target.createTarget', {
			url: 'about:blank',
			browserContextId,
		} );
		const { sessionId } = await this.send( 'Target.attachToTarget', {
			targetId,
			flatten: true,
		} );
		const page = new Page( this, sessionId, targetId, browserContextId );
		await page.call( 'Page.enable' );
		await page.call( 'Emulation.setDeviceMetricsOverride', {
			width,
			height,
			deviceScaleFactor: 1,
			mobile: false,
		} );
		return page;
	}

	async close() {
		try {
			await Promise.race( [
				this.send( 'Browser.close' ),
				new Promise( ( resolve ) => setTimeout( resolve, 5000 ) ),
			] );
		} catch {
			// Already gone.
		}
		this.socket.close();
		if ( this.child.exitCode === null ) {
			this.child.kill();
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 200 ) );
		try {
			rmSync( this.profile, { recursive: true, force: true } );
		} catch {
			// Windows can hold the profile a moment longer; it is in the temp dir.
		}
	}
}

class Page {
	/**
	 * @param {Browser} browser
	 * @param {string}  sessionId
	 * @param {string}  targetId
	 * @param {string}  browserContextId
	 */
	constructor( browser, sessionId, targetId, browserContextId ) {
		this.browser = browser;
		this.sessionId = sessionId;
		this.targetId = targetId;
		this.browserContextId = browserContextId;
	}

	/**
	 * @param {string} method
	 * @param {Object} [params]
	 * @return {Promise<Object>} The result.
	 */
	call( method, params ) {
		return this.browser.send( method, params, this.sessionId );
	}

	/**
	 * Navigate and wait for the load event.
	 *
	 * @param {string} url
	 * @param {number} [timeout] Milliseconds.
	 */
	async goto( url, timeout = 180000 ) {
		const loaded = this.browser.once(
			'Page.loadEventFired',
			this.sessionId,
			timeout
		);
		const result = await this.call( 'Page.navigate', { url } );
		if ( result.errorText ) {
			loaded.catch( () => {} );
			throw new Error( `Could not open ${ url }: ${ result.errorText }` );
		}
		await loaded;
	}

	/**
	 * Run an expression in the page and return its value.
	 *
	 * @param {string} expression JavaScript; a promise is awaited.
	 * @return {Promise<unknown>} The value, which must be JSON-serializable.
	 */
	async evaluate( expression ) {
		const { result, exceptionDetails } = await this.call(
			'Runtime.evaluate',
			{ expression, awaitPromise: true, returnByValue: true }
		);
		if ( exceptionDetails ) {
			throw new Error(
				exceptionDetails.exception?.description || exceptionDetails.text
			);
		}
		return result.value;
	}

	/**
	 * Capture a PNG of a region of the page, in CSS pixels from the top left.
	 *
	 * @param {{x: number, y: number, width: number, height: number}} clip
	 * @return {Promise<Buffer>} PNG bytes.
	 */
	async screenshot( clip ) {
		const { data } = await this.call( 'Page.captureScreenshot', {
			format: 'png',
			clip: { ...clip, scale: 1 },
			captureBeyondViewport: true,
		} );
		return Buffer.from( data, 'base64' );
	}

	async close() {
		await this.browser.send( 'Target.closeTarget', {
			targetId: this.targetId,
		} );
		await this.browser
			.send( 'Target.disposeBrowserContext', {
				browserContextId: this.browserContextId,
			} )
			.catch( () => {} );
	}
}
