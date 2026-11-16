// The measures bench/score.mjs takes from a run's saved files with no site:
// which claims on a rendered page trace to the business's own Site Data, and
// what each step of a run cost. bench/test-score.mjs checks each against
// known answers, and npm run check runs it.

// ---------------------------------------------------------------------------
// Claim words

// Number words up to ninety-nine, for "two decades" and "twenty-five years".
const UNITS = [ 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen' ];
const TENS = [ 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety' ];
const NUMBER = String.raw`\d+|(?:${ TENS.join( '|' ) })(?:[- ](?:${ UNITS.slice( 0, 9 ).join( '|' ) }))?|${ UNITS.join( '|' ) }`;

/**
 * Years or decades, as a claim: next to a number ("25 years", "25+ years",
 * "a 10-year warranty", "two decades") or to "since" ("years since 1999").
 * "Built to last for years" and "Year founded" give no figure, so they make
 * no claim.
 */
export const YEARS = String.raw`(?:\b(?:${ NUMBER })\+?(?:\s+|-)(?:years?|decades?)\b|\b(?:years?|decades?)\s+since\b)`;

/**
 * Words of a factual claim or a promise: the pack's one list, CLAIM_WORDS in
 * inc/abilities/claims.php, the words insert and compose check an agent's
 * copy with. It has no bare digit and no `#`, which every phone number,
 * address and opening time would trip, and `rating` and `review` start a
 * word, so "operating", "celebrating", "decorating" and "preview" make no
 * claim, while "ratings", "reviews" and "reviewed" still do. One change here,
 * because this reads a whole page: `years?` and `decades?`, two words there,
 * are one part here, YEARS above, which counts them only next to a number or
 * "since".
 *
 * bench/test-score.mjs checks that this is that list with that change.
 */
export const CLAIM_PARTS = [
	'licen[cs]',
	'insur',
	'bonded',
	'certif',
	'accredit',
	'award',
	String.raw`\brated\b`,
	String.raw`\brating`,
	String.raw`\breview`,
	String.raw`\bstars?\b`,
	YEARS,
	String.raw`\bsince\b`,
	'established',
	'guarantee',
	String.raw`\bwarrant`,
	String.raw`\bbest\b`,
	String.raw`\btop\b`,
	String.raw`\bleading\b`,
	String.raw`\bfree\b`,
	'same[- ]day',
];
export const CLAIM_WORDS = new RegExp( CLAIM_PARTS.join( '|' ), 'i' );
const PARTS = CLAIM_PARTS.map( ( part ) => new RegExp( part, 'gi' ) );
const YEARS_PART = CLAIM_PARTS.indexOf( YEARS );

/**
 * Phrases that hold a claim word and make no claim, removed before the test:
 * "feel free", and "top", "best" and "leading" in an idiom ("top to
 * bottom", "the best way", "feel your best", "leading to"). The pack's own
 * list, NOT_CLAIMS in inc/abilities/claims.php, which bench/test-score.mjs
 * holds this to.
 */
export const NOT_CLAIMS = [
	/\bfeel free\b/gi,
	/\btop[- ]to[- ]bottom\b/gi,
	/\bon top of\b/gi,
	/\bbest (?:time|way)s?\b/gi,
	/\b(?:my|your|his|her|its|our|their) best\b(?![- ]*[a-z])/gi,
	/\bleading (?:up )?(?:to|into)\b/gi,
];

/**
 * The words for the people who do the work, the pack's TRADE_NOUNS in
 * inc/abilities/claims.php, which bench/test-score.mjs holds this to. A
 * credential or service whose other words are all trade nouns traces its
 * claim word when one of them sits within two words of it: "Licensed
 * roofers" with "Licensed contractors". A credential with other words still
 * needs one of those: "Certified roofers" shares nothing with
 * "Manufacturer-certified installers". Each counts with an "s" too.
 */
export const TRADE_NOUNS = [ 'installer', 'roofer', 'crew', 'technician', 'contractor', 'staff', 'team' ];
// How many words may stand between a claim word and a trade noun it is said of.
const TRADE_NOUN_REACH = 2;
const isTradeNoun = ( word ) => TRADE_NOUNS.includes( word ) || ( word.endsWith( 's' ) && TRADE_NOUNS.includes( word.slice( 0, -1 ) ) );

/**
 * Whether a trade noun sits within two words of a claim word, either side:
 * no more than TRADE_NOUN_REACH words between them.
 * @param {string} text  The sentence the claim word was found in.
 * @param {Object} claim From claimWords().
 */
function tradeNounNear( text, claim ) {
	const words = ( s ) => s.match( /[\p{L}\p{N}]+/gu ) ?? [];
	return [ ...words( text.slice( 0, claim.start ) ).slice( -TRADE_NOUN_REACH - 1 ), ...words( text.slice( claim.end ) ).slice( 0, TRADE_NOUN_REACH + 1 ) ].some( isTradeNoun );
}

/**
 * A Site Data figure in a sentence traces the claim words about that figure,
 * and no others: "Rated 4.8, the best in Ohio" traces "rated", not "best".
 * Years with a number trace only to the years since the year founded
 * (tenure() below).
 */
const FIGURE_CLAIMS = {
	founded: [ String.raw`\bsince\b`, 'established', YEARS ],
	rating: [ String.raw`\brated\b`, String.raw`\brating`, String.raw`\breview`, String.raw`\bstars?\b` ],
	review_count: [ String.raw`\brated\b`, String.raw`\brating`, String.raw`\breview`, String.raw`\bstars?\b` ],
	license: [ 'licen[cs]' ],
};

// A sentence with NOT_CLAIMS blanked out, each character a space, so a claim
// word keeps its place.
const withoutNotClaims = ( sentence ) => NOT_CLAIMS.reduce( ( s, re ) => s.replace( re, ( m ) => ' '.repeat( m.length ) ), sentence );

/**
 * Each claim word in a sentence, once per kind: its part of CLAIM_PARTS, the
 * whole word it is in, where it is, and for years the number of years. Two
 * years claims with different numbers are two claims.
 * @param {string} sentence Normalized with match().
 */
export function claimWords( sentence ) {
	const text = withoutNotClaims( sentence );
	const found = new Map();
	PARTS.forEach( ( re, part ) => {
		for ( const m of text.matchAll( re ) ) {
			const years = part === YEARS_PART ? yearsIn( m[ 0 ] ) : null;
			const key = `${ part }:${ years?.value ?? '' }`;
			if ( found.has( key ) ) {
				continue;
			}
			let start = m.index;
			let end = m.index + m[ 0 ].length;
			while ( start > 0 && /\p{L}/u.test( text[ start - 1 ] ) && /\p{L}/u.test( text[ start ] ) ) {
				start--;
			}
			while ( end < text.length && /\p{L}/u.test( text[ end ] ) && /\p{L}/u.test( text[ end - 1 ] ) ) {
				end++;
			}
			found.set( key, { part, word: text.slice( start, end ), start, end, years } );
		}
	} );
	return [ ...found.values() ];
}

/**
 * The number of years a years claim gives, and whether it reads as an age:
 * "25 years" and "two decades" do, a "25-year" or "10 year" warranty does
 * not. "years since" gives no number.
 * @param {string} text The match of YEARS.
 */
function yearsIn( text ) {
	const m = /^(.+?)\+?(?:\s+|-)(years?|decades?)$/i.exec( text );
	if ( ! m ) {
		return { value: null, age: false };
	}
	const n = numberOf( m[ 1 ] );
	const unit = m[ 2 ].toLowerCase();
	return {
		value: n === null ? null : n * ( unit.startsWith( 'decade' ) ? 10 : 1 ),
		age: unit.endsWith( 's' ),
	};
}

function numberOf( text ) {
	const t = text.toLowerCase();
	if ( /^\d+$/.test( t ) ) {
		return Number( t );
	}
	const [ tens, unit ] = t.split( /[- ]/ );
	if ( TENS.includes( tens ) ) {
		return ( TENS.indexOf( tens ) + 2 ) * 10 + ( unit ? UNITS.indexOf( unit ) + 1 : 0 );
	}
	return UNITS.includes( t ) ? UNITS.indexOf( t ) + 1 : null;
}

// ---------------------------------------------------------------------------
// Content words

// Words that say nothing about which claim a sentence makes.
const STOP = new Set(
	'about all also and any are aren been but can could did does don each every for from get had has have her here his how isn its into just more most much nor not off onto only our ours out over own per she some than that the their them then there these they this those too under upon very was wasn were what when where which while who why will with within without won would you your yours'.split(
		' '
	)
);

/**
 * A word's stem, enough to match "inspections" with "inspection",
 * "estimates" with "estimated" and "damaged" with "damage".
 * @param {string} word Lowercase.
 */
function stem( word ) {
	if ( /^\d+$/.test( word ) ) {
		return word;
	}
	let w = word.replace( /ies$/, 'y' ).replace( /(?<=(?:[sxz]|[cs]h))es$/, '' ).replace( /(?<!s)s$/, '' );
	for ( const suffix of [ 'ing', 'ed', 'e' ] ) {
		if ( w.endsWith( suffix ) && w.length - suffix.length >= 3 ) {
			w = w.slice( 0, -suffix.length );
			break;
		}
	}
	return w;
}

// Two stems are one word when they are equal, or when both have five
// letters or more and one starts the other ("inspect", "inspection").
function sameWord( a, b ) {
	return a === b || ( a.length >= 5 && b.length >= 5 && ( a.startsWith( b ) || b.startsWith( a ) ) );
}

/**
 * The stems of a text's content words: three letters or more, or a number,
 * and not a stop word. Words inside any of `skip` (claim words, with their
 * spans) are left out.
 * @param {string} text Normalized with match().
 * @param {Array}  skip Spans to leave out.
 */
function contentWords( text, skip = [] ) {
	const out = [];
	for ( const m of text.matchAll( /[\p{L}\p{N}]+/gu ) ) {
		const word = m[ 0 ];
		const start = m.index;
		const end = m.index + m[ 0 ].length;
		if ( skip.some( ( s ) => start < s.end && s.start < end ) ) {
			continue;
		}
		if ( STOP.has( word ) || ( word.length < 3 && ! /^\d+$/.test( word ) ) ) {
			continue;
		}
		out.push( stem( word ) );
	}
	return out;
}

// ---------------------------------------------------------------------------
// Claims traced

/**
 * Each sentence on the page's main content that holds a claim word, and
 * whether it is traced: every claim word in it is.
 *
 * A claim word is traced when
 * - one credential or Site Data service holds the same claim word and shares
 *   another content word with the sentence ("free" with "inspection" traces
 *   to "Free storm damage inspections"; "free" with "estimate" does not), or
 *   holds nothing but claim words ("Licensed and insured" traces
 *   "licensed" on its own). Words of the business name, its industry and its
 *   town are in every sentence, so they share nothing. A source whose other
 *   words are all trade nouns traces it when a trade noun sits within two
 *   words of it ("Licensed roofers" with "Licensed contractors", TRADE_NOUNS
 *   above);
 * - a Site Data figure in the sentence speaks to it: the year founded traces
 *   "since" and "established", the rating and review count "rated",
 *   "rating", "review" and "stars", the licence "licensed";
 * - it is an age in years no more than the years since the year founded, as
 *   of the run's year: "over 25 years" beside "Since 1999" in 2026. With no
 *   run year, no age is traced.
 *
 * A line that is only a label, such as "Average rating", is traced when the
 * line beside it is such a figure with no word beside it, as in the stats
 * row. A claim word that is a word of the business's name or industry makes
 * no claim.
 *
 * @param {string} html  A rendered page.
 * @param {Object} brief The brief, as Site Data holds it.
 * @param {Object} opts
 * @param {?number} opts.year The year the run started.
 * @return {Array<{text: string, traced: boolean, untraced: string[]}>} One per claim sentence, with its untraced claim words.
 */
export function claims( html, brief, { year = null } = {} ) {
	const generic = [ brief.business, brief.industry, brief.address_city ].flatMap( ( t ) => ( t ? contentWords( match( t ) ) : [] ) );
	// A claim word that is a word of the business's name or industry names the
	// business: "insurance" for Anchor Insurance Agency. "Insured" is still a
	// claim there.
	const named = new Set( [ brief.business, brief.industry ].flatMap( ( t ) => ( t ? contentWords( match( t ) ) : [] ) ) );
	const namesBusiness = ( claim ) => {
		const words = contentWords( claim.word );
		return words.length > 0 && words.every( ( w ) => named.has( w ) );
	};
	const shares = ( words, others ) => others.some( ( o ) => ! generic.some( ( g ) => sameWord( g, o ) ) && words.some( ( w ) => sameWord( w, o ) ) );
	const sources = [ ...( brief.credentials || [] ), ...( brief.services || [] ) ]
		.map( match )
		.filter( Boolean )
		.map( ( text ) => {
			const found = claimWords( text );
			const rest = contentWords( text, found ).filter( ( w ) => ! generic.some( ( g ) => sameWord( g, w ) ) );
			return { text, found, bare: found.length > 0 && rest.length === 0, trade: found.length > 0 && rest.length > 0 && rest.every( isTradeNoun ) };
		} );
	const figures = Object.entries( FIGURE_CLAIMS )
		.filter( ( [ key ] ) => brief[ key ] != null && brief[ key ] !== '' )
		.map( ( [ key, parts ] ) => ( { re: figure( String( brief[ key ] ) ), parts: parts.map( ( p ) => CLAIM_PARTS.indexOf( p ) ) } ) );
	const holdsFigure = ( s ) => figures.some( ( f ) => f.re.test( s ) );
	const founded = Number( brief.founded );
	const age = Number.isInteger( year ) && Number.isInteger( founded ) && founded <= year ? year - founded : null;

	const traced = ( claim, sentence, words, tested ) => {
		if ( claim.part === YEARS_PART && claim.years.value !== null ) {
			if ( claim.years.age && age !== null && claim.years.value <= age ) {
				return true;
			}
		} else if ( figures.some( ( f ) => f.parts.includes( claim.part ) && f.re.test( sentence ) ) ) {
			return true;
		}
		return sources.some( ( source ) => {
			const same = source.found.find( ( c ) => c.part === claim.part && ( c.years?.value ?? null ) === ( claim.years?.value ?? null ) );
			if ( ! same ) {
				return false;
			}
			const others = contentWords( source.text, [ same ] );
			return source.bare || shares( words, others ) || ( source.trade && tradeNounNear( tested, claim ) );
		} );
	};

	// A figure on its own line: the figure, and no word beside it, as in
	// "1999", "4.8 / 5" or "276+".
	const isFigure = ( line ) => holdsFigure( line ) && ! /\p{L}/u.test( line );
	const lines = renderedLines( html, { main: true } );
	const out = [];
	lines.forEach( ( line, i ) => {
		const sentences = line.split( /(?<=[.!?])\s+(?=[A-Z0-9"'(])/ );
		for ( const sentence of sentences ) {
			const normal = match( sentence );
			const found = claimWords( normal ).filter( ( c ) => ! namesBusiness( c ) );
			if ( ! found.length ) {
				continue;
			}
			const label = sentences.length === 1 && ! /[.!?]$/.test( sentence.trim() ) && ( isFigure( lines[ i - 1 ] ?? '' ) || isFigure( lines[ i + 1 ] ?? '' ) );
			const words = contentWords( normal );
			const tested = withoutNotClaims( normal );
			const untraced = label ? [] : found.filter( ( c ) => ! traced( c, sentence, words, tested ) ).map( ( c ) => c.word );
			out.push( { text: sentence, traced: untraced.length === 0, untraced } );
		}
	} );
	return out;
}

function figure( value ) {
	const escaped = value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	return new RegExp( `(?<![\\d.,])${ escaped }(?![\\d]|[.,]\\d)` );
}

// Lowercase, straight quotes, plain hyphens and single spaces, for comparing
// a brief's text with a page's.
export function match( s ) {
	return String( s )
		.replace( /[‘’‛′]/g, "'" )
		.replace( /[“”″]/g, '"' )
		.replace( /[‐-―−]/g, '-' )
		.replace( /\s+/g, ' ' )
		.trim()
		.toLowerCase();
}

// Elements whose end is the end of a line of text: a sentence never runs on
// across them.
const BLOCK_TAGS =
	'address|article|aside|blockquote|br|button|caption|dd|details|div|dl|dt|figcaption|figure|footer|form|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|summary|table|td|th|tr|ul';

/**
 * A rendered page's text, one line per block of text, without scripts,
 * styles, comments or the head.
 * @param {string}  html
 * @param {Object}  opts
 * @param {boolean} opts.main Only the page's <main>, when it has one.
 */
export function renderedLines( html, { main = false } = {} ) {
	let body = /<body\b[^>]*>([\s\S]*)<\/body>/i.exec( html )?.[ 1 ] ?? html;
	if ( main ) {
		body = /<main\b[^>]*>([\s\S]*)<\/main>/i.exec( body )?.[ 1 ] ?? body;
	}
	return decode(
		body
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<(script|style|noscript|template|svg)\b[\s\S]*?<\/\1>/gi, ' ' )
			.replace( new RegExp( `</?(?:${ BLOCK_TAGS })\\b[^>]*>`, 'gi' ), '\n' )
			.replace( /<[^>]+>/g, ' ' )
	)
		.split( '\n' )
		.map( ( l ) => l.replace( /\s+/g, ' ' ).trim() )
		.filter( Boolean );
}

export function decode( s ) {
	const named = { amp: '&', nbsp: ' ', quot: '"', apos: "'", lt: '<', gt: '>', rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“', ndash: '–', mdash: '—', hellip: '…', lpar: '(', rpar: ')', middot: '·', copy: '©', times: '×' };
	return String( s )
		.replace( /&#x([0-9a-f]+);/gi, ( all, h ) => String.fromCodePoint( parseInt( h, 16 ) ) )
		.replace( /&#(\d+);/g, ( all, d ) => String.fromCodePoint( Number( d ) ) )
		.replace( /&([a-z]+);/gi, ( all, n ) => named[ n.toLowerCase() ] ?? all )
		.replace( / /g, ' ' );
}

// ---------------------------------------------------------------------------
// Cost

/**
 * What the build and the edit each cost, and the two together, from each
 * transcript's result event. The edit resumes the build's Claude Code
 * session (`--resume` in bench/run.mjs), and a resumed session's
 * `total_cost_usd` is its running total, the build's cost included. So when
 * the edit's session is the build's, its own cost is the difference. A
 * running total below the build's cannot be split, and the cost is unknown
 * rather than a guess.
 * @param {?Object} build The build transcript's result event.
 * @param {?Object} edit  The edit transcript's result event.
 * @return {{build: ?number, edit: ?number, total: ?number}} In US dollars.
 */
export function stepCosts( build, edit ) {
	const cost = ( event ) => ( typeof event?.total_cost_usd === 'number' ? event.total_cost_usd : null );
	const b = cost( build );
	const e = cost( edit );
	const resumed = Boolean( edit?.session_id ) && edit.session_id === build?.session_id;
	if ( resumed ) {
		const own = b !== null && e !== null && e >= b ? e - b : null;
		return { build: b, edit: own === null ? null : round4( own ), total: own === null ? null : round4( b + own ) };
	}
	const known = [ b, e ].filter( ( c ) => c !== null );
	return { build: b, edit: e, total: known.length ? round4( known.reduce( ( x, y ) => x + y, 0 ) ) : null };
}

function round4( n ) {
	return Math.round( n * 10000 ) / 10000;
}
