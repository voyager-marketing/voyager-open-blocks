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
 * Escape a workflow command property, such as a title or a file path.
 *
 * @param {string|number} value
 * @return {string} Text safe to put in `::error title=...::`.
 */
function escapeProperty( value ) {
	return escape( value ).replace( /,/g, '%2C' ).replace( /:/g, '%3A' );
}

/**
 * Print a line, as an annotation in GitHub Actions.
 *
 * @param {'error'|'warning'|'notice'}                   level
 * @param {string}                                       message
 * @param {string}                                       [title] Annotation title in Actions.
 * @param {{file?: string, line?: number, col?: number}} [where] Repo-relative file, line and column the annotation points at.
 */
export function annotate( level, message, title, where = {} ) {
	if ( inActions ) {
		const props = Object.entries( {
			file: where.file,
			line: where.line,
			col: where.col,
			title,
		} )
			.filter( ( [ , value ] ) => value !== undefined && value !== '' )
			.map(
				( [ key, value ] ) => `${ key }=${ escapeProperty( value ) }`
			)
			.join( ',' );
		console.log(
			`::${ level }${ props ? ` ${ props }` : '' }::${ escape( message ) }`
		);
		return;
	}
	const prefix = { error: 'FAIL', warning: 'warn', notice: 'ok  ' }[ level ];
	const at = where.file
		? `${ where.file }${ where.line ? `:${ where.line }` : '' } `
		: '';
	( level === 'error' ? console.error : console.log )(
		`${ prefix } ${ at }${ message }`
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
