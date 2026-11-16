<?php
/**
 * Claim words: the check insert and compose run on the plain copy given for
 * a slot that is not a claim slot. Contract 3 in docs/v2-contracts.md has the
 * shapes.
 *
 * CLAIM_WORDS is the one list of the words of a factual claim or a promise.
 * The unit tests hold the pack's default copy to it, with a bare digit and #
 * added (tests/Unit/PatternLayoutsTest.php), and bench/test-score.mjs checks
 * the benchmark scorer's list against it. The functions here take plain
 * arrays and call no WordPress function, so the unit suite runs them as they
 * are.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Words of a factual claim or a promise: a credential, a rating, an award, a
 * year in business, a guarantee or something free. The alternatives of one
 * regular expression, read case-insensitively, without delimiters, and no
 * alternative holds a `|`. Each is a word or the start of one: "rated",
 * "since", "free", "star", "top", "best" and "leading" are whole words, and
 * "rating", "review" and "warrant" start a word, so "operated", "operating",
 * "preview", "freeze", "stop", "sincere", "misleading" and "unwarranted" make
 * no claim. No digit and no #, which every phone number, address, date and
 * price would trip.
 */
const CLAIM_WORDS = 'licen[cs]|insur|bonded|certif|accredit|award|\brated\b|\brating|\breview|\bstars?\b|years?\b|decades?\b|\bsince\b|established|guarantee|\bwarrant|\bbest\b|\btop\b|\bleading\b|\bfree\b|same[- ]day';

/**
 * Phrases that hold a claim word and make no claim, taken out before the
 * check, one a line: bench/test-score.mjs reads them here and holds the
 * benchmark scorer's to them. Besides "feel free", "top", "best" and
 * "leading" in an idiom: top to bottom, on top of, the best time or way, a
 * possessive and "best" with no word after it ("feel your best", "we give
 * it our best"), and leading to. Anywhere else they are claims: "top-rated",
 * "the best roofer in town", "our best roofers", "a leading provider".
 */
const NOT_CLAIMS = [
	'\bfeel free\b',
	'\btop[- ]to[- ]bottom\b',
	'\bon top of\b',
	'\bbest (?:time|way)s?\b',
	'\b(?:my|your|his|her|its|our|their) best\b(?![- ]*[a-z])',
	'\bleading (?:up )?(?:to|into)\b',
];

/**
 * The words for the people who do the work. One of them in a sentence keeps
 * any of them a credential or service says: "manufacturer-certified crews"
 * keeps "Manufacturer-certified installers". Each counts with an "s" too.
 * Words for the work itself, such as "installation" or "roofing", are not
 * on the list.
 */
const TRADE_NOUNS = [ 'installer', 'roofer', 'crew', 'technician', 'contractor', 'staff', 'team' ];

/**
 * The Site Data figure a claim word can be said with, by its alternative in
 * CLAIM_WORDS: "since 1999" with the year founded, "rated 4.8" with the
 * rating, "276 reviews" with the review count. A licence is given by the
 * licence number field being set.
 */
const CLAIM_FIGURES = [
	'years?\b'    => 'founded',
	'decades?\b'  => 'founded',
	'\bsince\b'   => 'founded',
	'established' => 'founded',
	'\brated\b'   => 'rating',
	'\brating'    => 'rating',
	'\bstars?\b'  => 'rating',
	'\breview'    => 'review_count',
	'licen[cs]'   => 'license',
];

/**
 * Claim words that name a figure. With no number in the sentence they make
 * no claim: a stats label such as "Average rating" or "In business since",
 * "an exam each year", or "built to last for decades".
 */
const FIGURE_NAMES = [ 'years?\b', 'decades?\b', '\bsince\b', 'established', '\brating', '\bstars?\b', '\breview' ];

/**
 * An age: a number, written in digits or as words up to ninety-nine, then
 * "years" or "decades", as in "25 years", "25+ years", "twenty-five years"
 * or "two decades". A "25-year" or "10 year" warranty says how long
 * something lasts, not how old the business is, so the unit is plural. The
 * number and the unit are captured.
 */
const CLAIM_AGE = '/\b(\d+|(?:twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen)\+?(?:\s+|-)(years|decades)\b/i';

/** The years in each unit CLAIM_AGE reads. */
const CLAIM_AGE_UNITS = [
	'years'   => 1,
	'decades' => 10,
];

/** A number: a digit, or a number written as a word, from two up. */
const CLAIM_NUMBER = '/\d|\b(?:two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|fifteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundreds?|thousands?|dozens?)\b/i';

/**
 * Words a credential can drop when it is reworded: articles, pronouns,
 * prepositions and conjunctions.
 */
const CLAIM_STOPWORDS = [ 'a', 'all', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'by', 'each', 'every', 'for', 'from', 'in', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 'over', 'per', 'than', 'that', 'the', 'their', 'this', 'to', 'up', 'us', 'we', 'with', 'within', 'you', 'your' ];

/**
 * The alternatives of CLAIM_WORDS, in order.
 *
 * @return list<string>
 */
function claim_alternatives(): array {
	return explode( '|', CLAIM_WORDS );
}

/**
 * What the business has given, read from Site Data: each credential and
 * service that holds a claim word, and each figure that is set.
 *
 * A statement is a credential or a service, with the claim words it holds,
 * counted by alternative, its other words: every word but those claim words
 * and CLAIM_STOPWORDS, each as claim_stem() gives it, and of those, the ones
 * a trade noun gave (TRADE_NOUNS). Figures are the year founded, the rating,
 * the review count and the licence number, each left out when empty. Tenure
 * is the years since the year founded, as of Site Data's current year
 * (`year`, or this year when it is empty), and null when no year founded is
 * set or it is later than that.
 *
 * @param array<string, string> $site_data Site Data values, as SiteData\values() resolves them; a key it lacks is empty.
 * @return array{credentials: list<string>, statements: list<array{claims: array<string, int>, words: list<string>, trade: list<string>}>, figures: array<string, string>, tenure: int|null}
 */
function claim_sources( array $site_data ): array {
	$lines       = static fn ( string $key ): array => array_values( array_filter( array_map( 'trim', explode( "\n", $site_data[ $key ] ?? '' ) ), static fn ( string $line ): bool => '' !== $line ) );
	$credentials = $lines( 'credentials' );
	$statements  = [];

	foreach ( array_merge( $credentials, $lines( 'services' ) ) as $text ) {
		$claims = [];

		foreach ( claim_alternatives() as $alternative ) {
			$count = preg_match_all( '/' . $alternative . '/i', $text );

			if ( $count > 0 ) {
				$claims[ $alternative ] = (int) $count;
			}
		}

		if ( [] === $claims ) {
			continue;
		}

		$words = [];
		$trade = [];

		foreach ( claim_text_words( $text ) as $word ) {
			if ( in_array( $word, CLAIM_STOPWORDS, true ) || 1 === preg_match( '/' . CLAIM_WORDS . '/i', $word ) ) {
				continue;
			}

			$words[] = claim_stem( $word );

			if ( is_trade_noun( $word ) ) {
				$trade[] = claim_stem( $word );
			}
		}

		$statements[] = [
			'claims' => $claims,
			'words'  => array_values( array_unique( $words ) ),
			'trade'  => array_values( array_unique( $trade ) ),
		];
	}

	$figures = [];

	foreach ( [ 'founded', 'rating', 'review_count', 'license' ] as $key ) {
		$value = trim( $site_data[ $key ] ?? '' );

		if ( '' !== $value ) {
			$figures[ $key ] = $value;
		}
	}

	$year   = (int) ( '' === trim( $site_data['year'] ?? '' ) ? gmdate( 'Y' ) : $site_data['year'] );
	$tenure = isset( $figures['founded'] ) && (int) $figures['founded'] <= $year ? $year - (int) $figures['founded'] : null;

	return [
		'credentials' => $credentials,
		'statements'  => $statements,
		'figures'     => $figures,
		'tenure'      => $tenure,
	];
}

/**
 * The claim words in a text that Site Data does not give, each once.
 *
 * Read sentence by sentence (claim_sentences()). In each, after NOT_CLAIMS
 * are taken out, every claim word is sourced, or the word is returned:
 *
 * - A word that names a figure (FIGURE_NAMES) makes no claim when the
 *   sentence holds no number (CLAIM_NUMBER) but the Site Data figures
 *   (without_figures()).
 * - A word with a figure (CLAIM_FIGURES) is sourced when that figure is set
 *   and the sentence holds its value, or holds no other number ("highly
 *   rated" with a rating set). A licence word is sourced whenever the
 *   licence number is set. A number of years or decades is sourced by the
 *   year founded when each "years" or "decades" in the sentence is an age
 *   (CLAIM_AGE) no more than the years since then: "over 25 years" or "two
 *   decades" when the business was founded 27 years ago, never "over 30
 *   years" or "three decades" (ages_within()).
 * - Otherwise a credential or service sources it when it holds the same claim
 *   word and the sentence holds every one of its other words, in any order
 *   and form ("installers" for "installer", "storm-damage" for "storm
 *   damage"). So "Free storm damage inspections" is said again as "a free
 *   inspection for storm damage", while "a free inspection" and "a free
 *   estimate" say more than it does. A trade noun in the sentence keeps the
 *   statement's trade nouns: "manufacturer-certified crews" keeps
 *   "Manufacturer-certified installers". The credential's other claim words
 *   may be left out: "Licensed and insured" sources "licensed" alone. Each
 *   time a word appears needs a statement of its own: "free" twice in a
 *   sentence needs two statements that say "free".
 *
 * @param string                                                                                                                                                                             $text    Plain copy the agent wrote.
 * @param array{credentials: list<string>, statements: list<array{claims: array<string, int>, words: list<string>, trade: list<string>}>, figures: array<string, string>, tenure: int|null} $sources From claim_sources().
 * @return array<string, string> Each unsourced word, lowercased and whole ("certified" for "certif"), => its alternative in CLAIM_WORDS.
 */
function unsourced_claims( string $text, array $sources ): array {
	$out = [];

	foreach ( claim_sentences( $text ) as $sentence ) {
		$tested = $sentence;

		foreach ( NOT_CLAIMS as $phrase ) {
			$tested = (string) preg_replace( '/' . $phrase . '/i', ' ', $tested );
		}

		$numbered = 1 === preg_match( CLAIM_NUMBER, without_figures( $sentence, $sources['figures'] ) );
		$words    = claim_text_words( $sentence );
		$stems    = array_map( __NAMESPACE__ . '\claim_stem', $words );
		$trade    = [] !== array_filter( $words, __NAMESPACE__ . '\is_trade_noun' );

		foreach ( claim_alternatives() as $alternative ) {
			$found = (int) preg_match_all( '/' . $alternative . '/i', $tested, $matches, PREG_OFFSET_CAPTURE );

			if ( 0 === $found ) {
				continue;
			}

			$figure = CLAIM_FIGURES[ $alternative ] ?? null;
			$value  = null === $figure ? '' : ( $sources['figures'][ $figure ] ?? '' );

			if ( ! $numbered && in_array( $alternative, FIGURE_NAMES, true ) ) {
				continue;
			}

			if ( '' !== $value && ( 'license' === $figure || ! $numbered || holds_figure( $sentence, $value ) ) ) {
				continue;
			}

			if ( 'founded' === $figure && ages_within( $tested, $alternative, $found, $sources['tenure'] ) ) {
				continue;
			}

			$given = 0;

			foreach ( $sources['statements'] as $statement ) {
				$count = $statement['claims'][ $alternative ] ?? 0;

				if ( $count > 0 && [] === array_diff( $statement['words'], $stems, $trade ? $statement['trade'] : [] ) ) {
					$given += $count;
				}
			}

			if ( $found > $given ) {
				$out[ whole_word( $tested, $matches[0][0][0], $matches[0][0][1] ) ] = $alternative;
			}
		}
	}

	return $out;
}

/**
 * A text's sentences: split after a full stop, question mark or exclamation
 * mark followed by a space, and at each line break.
 *
 * @param string $text Plain text.
 * @return list<string>
 */
function claim_sentences( string $text ): array {
	return preg_split( '/(?<=[.!?])\s+|\R+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
}

/**
 * A text's words, lowercased: runs of letters and digits, so a hyphen or a
 * full stop splits one ("10-year" is "10" and "year").
 *
 * @param string $text Plain text.
 * @return list<string>
 */
function claim_text_words( string $text ): array {
	return preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
}

/**
 * A word with one common ending taken off, so its forms compare equal:
 * "installers", "installer", "installed" and "installation" are "install";
 * "damages" and "damage" are "damag". At least three letters are kept, and
 * a word ending in "ss" keeps its last "s".
 *
 * @param string $word A lowercased word from claim_text_words().
 */
function claim_stem( string $word ): string {
	if ( str_ends_with( $word, 'ies' ) && strlen( $word ) > 4 ) {
		return substr( $word, 0, -3 ) . 'y';
	}

	foreach ( [ 'ations', 'ation', 'ings', 'ions', 'ers', 'ing', 'ion', 'es', 'ed', 'er', 's', 'e' ] as $ending ) {
		if ( ! str_ends_with( $word, $ending ) || strlen( $word ) - strlen( $ending ) < 3 ) {
			continue;
		}

		if ( 's' === $ending && str_ends_with( $word, 'ss' ) ) {
			return $word;
		}

		return substr( $word, 0, -strlen( $ending ) );
	}

	return $word;
}

/**
 * Whether a claim word that goes with the year founded is said only of ages
 * no more than the business's tenure: as many CLAIM_AGE matches, each at
 * most the tenure once counted in years (CLAIM_AGE_UNITS), as the sentence
 * says the word. "years" counts the ages in years and "decades" the ages in
 * decades; "since" and "established" count either, as in "established two
 * decades ago".
 *
 * @param string   $tested      The sentence, NOT_CLAIMS taken out.
 * @param string   $alternative The claim word's alternative in CLAIM_WORDS.
 * @param int      $found       How many times the sentence says it.
 * @param int|null $tenure      Years since the year founded, from claim_sources().
 */
function ages_within( string $tested, string $alternative, int $found, ?int $tenure ): bool {
	if ( null === $tenure || ! preg_match_all( CLAIM_AGE, $tested, $matches, PREG_SET_ORDER ) ) {
		return false;
	}

	$only   = [
		'years?\b'   => 'years',
		'decades?\b' => 'decades',
	][ $alternative ] ?? null;
	$within = 0;

	foreach ( $matches as $match ) {
		$unit   = strtolower( $match[2] );
		$number = claim_number( $match[1] );

		if ( ( null === $only || $only === $unit ) && null !== $number && $number * ( CLAIM_AGE_UNITS[ $unit ] ?? 1 ) <= $tenure ) {
			++$within;
		}
	}

	return $within >= $found;
}

/**
 * Whether a word is one of TRADE_NOUNS, or one of them with an "s".
 *
 * @param string $word A lowercased word from claim_text_words().
 */
function is_trade_noun( string $word ): bool {
	return in_array( $word, TRADE_NOUNS, true ) || ( str_ends_with( $word, 's' ) && in_array( substr( $word, 0, -1 ), TRADE_NOUNS, true ) );
}

/**
 * A number in digits or in words up to ninety-nine, as CLAIM_AGE captures it,
 * or null.
 *
 * @param string $text Such as 25, twenty-five or twenty five.
 */
function claim_number( string $text ): ?int {
	$text  = strtolower( $text );
	$units = [ 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen' ];
	$tens  = [ 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety' ];

	if ( ctype_digit( $text ) ) {
		return (int) $text;
	}

	$parts = preg_split( '/[- ]/', $text ) ?: [];
	$ten   = array_search( $parts[0] ?? '', $tens, true );

	if ( false !== $ten ) {
		$unit = isset( $parts[1] ) ? array_search( $parts[1], $units, true ) : -1;

		return false === $unit ? null : ( $ten + 2 ) * 10 + $unit + 1;
	}

	$unit = array_search( $text, $units, true );

	return false === $unit ? null : $unit + 1;
}

/**
 * A sentence without the Site Data figures it holds, so a number left in it
 * is one Site Data does not give: "Highly rated, with 276 reviews" holds no
 * other number when the review count is 276.
 *
 * @param string                $sentence A sentence.
 * @param array<string, string> $figures  From claim_sources().
 */
function without_figures( string $sentence, array $figures ): string {
	foreach ( $figures as $value ) {
		$sentence = (string) preg_replace( figure_pattern( $value ), ' ', $sentence );
	}

	return $sentence;
}

/**
 * The pattern holds_figure() matches a figure's value with.
 *
 * @param string $value A Site Data figure.
 */
function figure_pattern( string $value ): string {
	return '/(?<![\d.,])' . preg_quote( $value, '/' ) . '(?!\d|[.,]\d)/iu';
}

/**
 * Whether a sentence holds a figure's value as a number of its own: 1999 in
 * "since 1999" and 4.8 in "4.8-star", but not 99 in "1999" or 4.8 in "4.85".
 *
 * @param string $sentence A sentence.
 * @param string $value    A Site Data figure, such as 1999, 4.8 or 276.
 */
function holds_figure( string $sentence, string $value ): bool {
	return 1 === preg_match( figure_pattern( $value ), $sentence );
}

/**
 * The whole word a claim word was found in, lowercased: the letters before
 * the match, the match, and the letters after it.
 *
 * @param string $text   The text searched.
 * @param string $match  What the alternative matched, such as "certif".
 * @param int    $offset Its byte offset in $text.
 */
function whole_word( string $text, string $match, int $offset ): string {
	preg_match( '/\p{L}*$/u', substr( $text, 0, $offset ), $before );
	preg_match( '/^\p{L}*/u', substr( $text, $offset + strlen( $match ) ), $after );

	return mb_strtolower( ( $before[0] ?? '' ) . $match . ( $after[0] ?? '' ) );
}

/**
 * Items as a list of quoted strings: "a", "b".
 *
 * @param array<string> $items Words or credentials.
 */
function quoted_list( array $items ): string {
	return '"' . implode( '", "', $items ) . '"';
}

/**
 * The refusal for a slot whose copy holds claim words no Site Data gives.
 *
 * Its message names the words and lists the Site Data credentials, the
 * claims the agent may make in their own words, and, when a word has a
 * figure, the figures that are set.
 *
 * @param string                                                                                                                                                                             $label   The slot.
 * @param array<string, string>                                                                                                                                                              $words   From unsourced_claims().
 * @param array{credentials: list<string>, statements: list<array{claims: array<string, int>, words: list<string>, trade: list<string>}>, figures: array<string, string>, tenure: int|null} $sources From claim_sources().
 * @return array{slot: string, reason: string, message: string, words: list<string>, credentials: list<string>}
 */
function unsourced_claim_refusal( string $label, array $words, array $sources ): array {
	$credentials = $sources['credentials'];
	$message     = sprintf(
		'"%1$s" says %2$s, a claim the business has not given: no Site Data credential, service or figure says it. Leave it out, or say what a credential says, in its own words. %3$s',
		$label,
		quoted_list( array_keys( $words ) ),
		[] === $credentials ? 'Site Data holds no credentials.' : 'Site Data credentials: ' . quoted_list( $credentials ) . '.'
	);

	if ( [] !== array_intersect( $words, array_keys( CLAIM_FIGURES ) ) ) {
		$figures = [];

		foreach ( $sources['figures'] as $key => $value ) {
			$figures[] = $key . ' ' . $value;
		}

		$message .= [] === $figures
			? ' Site Data holds no year founded, rating, review count or licence number.'
			: ' Say a figure with its Site Data value: ' . implode( ', ', $figures ) . ( null === $sources['tenure'] ? '' : sprintf( ', or a number of years in business up to %d', $sources['tenure'] ) ) . '.';
	}

	return [
		'slot'        => $label,
		'reason'      => 'unsourced_claim',
		'message'     => $message,
		'words'       => array_keys( $words ),
		'credentials' => $credentials,
	];
}
