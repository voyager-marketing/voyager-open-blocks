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
 * @return {{get: (path: string) => Promise<Object>, cookies: Map<string, string>}} The client.
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
	 * @param {URL} url
	 * @return {Promise<{status: number, headers: Object, text: string}>} The response.
	 */
	function send( url ) {
		const headers = { 'user-agent': 'voyager-open-blocks-dev' };
		if ( cookies.size ) {
			headers.cookie = [ ...cookies ]
				.map( ( [ name, value ] ) => `${ name }=${ value }` )
				.join( '; ' );
		}
		return new Promise( ( resolve, reject ) => {
			const req = ( url.protocol === 'https:' ? https : http ).request(
				url,
				{ method: 'GET', headers, agent: false },
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
			req.end();
		} );
	}

	/**
	 * GET a path, following up to five redirects.
	 *
	 * @param {string} path Path or URL.
	 * @return {Promise<{status: number, headers: Object, text: string, url: string}>} The final response and its URL.
	 */
	async function get( path ) {
		let url = new URL( path, `${ base.replace( /\/$/, '' ) }/` );
		for ( let hop = 0; ; hop++ ) {
			const res = await send( url );
			remember( res.headers[ 'set-cookie' ] );
			if (
				REDIRECTS.includes( res.status ) &&
				res.headers.location &&
				hop < 5
			) {
				url = new URL( res.headers.location, url );
				continue;
			}
			return { ...res, url: url.href };
		}
	}

	return { get, cookies };
}
