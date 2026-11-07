/**
 * Output that reads plainly in a terminal and becomes check annotations in
 * GitHub Actions, where job logs are not always at hand.
 */

const inActions = process.env.GITHUB_ACTIONS === 'true';

/**
 * Escape a message for a workflow command.
 *
 * @param {string} text
 * @return {string} Text safe to put after `::error::`.
 */
function escape( text ) {
	return String( text )
		.replace( /%/g, '%25' )
		.replace( /\r/g, '%0D' )
		.replace( /\n/g, '%0A' );
}

/**
 * Print a line, as an annotation in GitHub Actions.
 *
 * @param {'error'|'warning'|'notice'} level
 * @param {string}                     message
 * @param {string}                     [title] Annotation title in Actions.
 */
export function annotate( level, message, title ) {
	if ( inActions ) {
		const props = title
			? ` title=${ escape( title ).replace( /,/g, '%2C' ).replace( /:/g, '%3A' ) }`
			: '';
		console.log( `::${ level }${ props }::${ escape( message ) }` );
		return;
	}
	const prefix = { error: 'FAIL', warning: 'warn', notice: 'ok  ' }[ level ];
	( level === 'error' ? console.error : console.log )(
		`${ prefix } ${ message }`
	);
}

/**
 * Open a collapsible group in Actions, or print a heading elsewhere.
 *
 * @param {string} name
 */
export function group( name ) {
	console.log( inActions ? `::group::${ escape( name ) }` : `\n${ name }` );
}

export function endGroup() {
	if ( inActions ) {
		console.log( '::endgroup::' );
	}
}
