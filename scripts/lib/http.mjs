/**
 * A small HTTP client for a local site: follows redirects and keeps cookies,
 * so a request that Playground logs in stays logged in on the next one.
 *
 * Each request opens a fresh connection (agent: false). fetch() reuses
 * keep-alive sockets that Playground's server may already have closed.
 */
import http from 'node:http';
import https from 'node:https';

const REDIRECTS = [ 301, 302, 303, 307, 308 ];

/**
 * @param {string} base            Site URL, such as http://127.0.0.1:9400.
 * @param {Object} options
 * @param {number} options.timeout Milliseconds to wait for one response.
 * @return {{get: (path: string) => Promise<Object>, post: (path: string, form: Object) => Promise<Object>, cookies: Map<string, string>}} The client.
 */
export function createClient( base, { timeout = 180000 } = {} ) {
	const cookies = new Map();

	/**
	 * @param {string[]|undefined} headers Set-Cookie headers.
	 */
	function remember( headers ) {
		for ( const header of headers || [] ) {
			const [ pair, ...attributes ] = header.split( ';' );
			const eq = pair.indexOf( '=' );
			if ( eq < 1 ) {
				continue;
			}
			const name = pair.slice( 0, eq ).trim();
			const value = pair.slice( eq + 1 ).trim();
			const expired = attributes.some( ( attribute ) => {
				const [ key, val = '' ] = attribute.trim().split( '=' );
				return (
					( key.toLowerCase() === 'max-age' && Number( val ) <= 0 ) ||
					( key.toLowerCase() === 'expires' &&
						Date.parse( val ) < Date.now() )
				);
			} );
			if ( expired || value === '' || value === 'deleted' ) {
				cookies.delete( name );
			} else {
				cookies.set( name, value );
			}
		}
	}

	/**
	 * @param {URL}    url
	 * @param {string} [body] A form body; makes the request a POST.
	 * @return {Promise<{status: number, headers: Object, text: string}>} The response.
	 */
	function send( url, body ) {
		const headers = { 'user-agent': 'voyager-open-blocks-dev' };
		if ( cookies.size ) {
			headers.cookie = [ ...cookies ]
				.map( ( [ name, value ] ) => `${ name }=${ value }` )
				.join( '; ' );
		}
		if ( body !== undefined ) {
			headers[ 'content-type' ] = 'application/x-www-form-urlencoded';
			headers[ 'content-length' ] = Buffer.byteLength( body );
		}
		return new Promise( ( resolve, reject ) => {
			const req = ( url.protocol === 'https:' ? https : http ).request(
				url,
				{
					method: body === undefined ? 'GET' : 'POST',
					headers,
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
	 * Request a path, following up to five redirects as GETs.
	 *
	 * @param {string} path   Path or URL.
	 * @param {string} [body] A form body for the first request, which makes it a POST.
	 * @return {Promise<{status: number, headers: Object, text: string, url: string, hops: string[]}>} The final response, its URL, and each redirect taken.
	 */
	async function request( path, body ) {
		let url = new URL( path, `${ base.replace( /\/$/, '' ) }/` );
		const hops = [];
		for ( let hop = 0; ; hop++ ) {
			const res = await send( url, hop === 0 ? body : undefined );
			remember( res.headers[ 'set-cookie' ] );
			if (
				REDIRECTS.includes( res.status ) &&
				res.headers.location &&
				hop < 5
			) {
				const next = new URL( res.headers.location, url );
				hops.push(
					`${ res.status } ${ url.pathname }${ url.search } -> ${ next.pathname }${ next.search }`
				);
				url = next;
				continue;
			}
			return { ...res, url: url.href, hops };
		}
	}

	/**
	 * @param {string} path Path or URL.
	 * @return {ReturnType<typeof request>} The final response.
	 */
	const get = ( path ) => request( path );

	/**
	 * @param {string}                 path Path or URL.
	 * @param {Object<string, string>} form Fields to send.
	 * @return {ReturnType<typeof request>} The final response.
	 */
	const post = ( path, form ) =>
		request( path, new URLSearchParams( form ).toString() );

	return { get, post, cookies };
}
