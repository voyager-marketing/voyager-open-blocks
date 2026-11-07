/**
 * The WordPress REST API of a dev site, as a user with an application
 * password, and plain page requests as a logged-out visitor.
 *
 * Every request carries Playground's playground_auto_login_already_happened
 * cookie. Without it, a site booted with "login": true logs the first request
 * in by cookie, and REST then treats a cookie with no nonce as logged out and
 * ignores the application password. Each request opens a fresh connection
 * (agent: false), for the reason given in scripts/lib/http.mjs.
 */
import http from 'node:http';
import https from 'node:https';

const MARKER = 'playground_auto_login_already_happened=1';

/**
 * One request.
 *
 * @param {URL}                    url
 * @param {Object}                 init
 * @param {string}                 [init.method]
 * @param {Object<string, string>} [init.headers]
 * @param {string}                 [init.body]
 * @param {number}                 [init.timeout] Milliseconds.
 * @return {Promise<{status: number, headers: Object, text: string}>} The response.
 */
function send( url, { method = 'GET', headers = {}, body, timeout = 120000 } ) {
	return new Promise( ( resolve, reject ) => {
		const req = ( url.protocol === 'https:' ? https : http ).request(
			url,
			{
				method,
				headers: {
					'user-agent': 'voyager-open-blocks-e2e',
					cookie: MARKER,
					...headers,
					...( body === undefined
						? {}
						: { 'content-length': Buffer.byteLength( body ) } ),
				},
				agent: false,
			},
			( res ) => {
				let text = '';
				res.setEncoding( 'utf8' );
				res.on( 'data', ( chunk ) => ( text += chunk ) );
				res.on( 'end', () =>
					resolve( {
						status: res.statusCode,
						headers: res.headers,
						text,
					} )
				);
				res.on( 'error', reject );
			}
		);
		req.setTimeout( timeout, () =>
			req.destroy(
				new Error(
					`No response from ${ url.href } in ${ timeout / 1000 }s`
				)
			)
		);
		req.on( 'error', reject );
		req.end( body );
	} );
}

/**
 * @param {string} base          Site URL, such as http://127.0.0.1:9400.
 * @param {Object} auth
 * @param {string} auth.user     User login.
 * @param {string} auth.password Application password.
 * @return {{api: (path: string, init?: Object) => Promise<Object>, visit: (target: string) => Promise<Object>}} The client.
 */
export function createRestClient( base, { user, password } ) {
	const root = base.replace( /\/$/, '' );
	const authorization =
		'Basic ' +
		Buffer.from( `${ user }:${ password }` ).toString( 'base64' );

	/**
	 * A REST request as the user.
	 *
	 * @param {string} path          Route, such as /wp/v2/pages.
	 * @param {Object} [init]
	 * @param {string} [init.method] HTTP method.
	 * @param {Object} [init.body]   JSON body.
	 * @param {Object} [init.query]  Query string parameters.
	 * @return {Promise<{status: number, data: unknown, text: string}>} The response, its JSON decoded.
	 */
	async function api( path, { method = 'GET', body, query } = {} ) {
		const url = new URL( `${ root }/wp-json${ path }` );
		for ( const [ key, value ] of Object.entries( query || {} ) ) {
			url.searchParams.set( key, String( value ) );
		}
		const response = await send( url, {
			method,
			headers: {
				authorization,
				'content-type': 'application/json',
			},
			body: body === undefined ? undefined : JSON.stringify( body ),
		} );
		let data = null;
		try {
			data = JSON.parse( response.text );
		} catch {}
		return { status: response.status, data, text: response.text };
	}

	/**
	 * A page as a logged-out visitor, following up to five redirects.
	 *
	 * @param {string} target Path or URL.
	 * @return {Promise<{status: number, text: string, url: string}>} The final response.
	 */
	async function visit( target ) {
		let url = new URL( target, `${ root }/` );
		for ( let hop = 0; ; hop++ ) {
			const response = await send( url, {} );
			if (
				response.status >= 300 &&
				response.status < 400 &&
				response.headers.location &&
				hop < 5
			) {
				url = new URL( response.headers.location, url );
				continue;
			}
			return {
				status: response.status,
				text: response.text,
				url: url.href,
			};
		}
	}

	return { api, visit };
}
