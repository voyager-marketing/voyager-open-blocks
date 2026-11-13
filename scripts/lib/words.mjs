/**
 * What an agent reads before its first call, in words.
 *
 * Two budgets. The quick card, the entry skill, stays under CARD_LIMIT words
 * (npm run check). The card plus every ability's description and every
 * description in its input schema, read live over REST, stays under
 * READING_LIMIT (npm run e2e): those descriptions are the tool definitions an
 * agent is sent on each request. What an ability returns is not counted.
 *
 * Words are counted as `wc -w` counts them, runs of characters that are not
 * whitespace, so a number printed here is easy to reproduce by hand.
 */

export const ENTRY_SKILL = 'skills/voyager-open-blocks/SKILL.md';
export const CARD_LIMIT = 500;
export const READING_LIMIT = 3000;

/**
 * Words in a text, as `wc -w` counts them. Anything but a string has none.
 *
 * @param {unknown} text
 * @return {number} Word count.
 */
export function countWords( text ) {
	return typeof text === 'string' ? ( text.match( /\S+/g ) || [] ).length : 0;
}

/**
 * The quick card is within its budget.
 *
 * @param {number} words The card's word count.
 * @return {boolean} Under CARD_LIMIT, and not empty.
 */
export function cardFits( words ) {
	return words > 0 && words < CARD_LIMIT;
}

/**
 * Every description in a JSON Schema, at any depth: the schema's own, each
 * property's, and those of items, additionalProperties and every anyOf,
 * oneOf and allOf branch, the way a client reads them.
 *
 * @param {unknown} schema A JSON Schema.
 * @param {string}  path   Where it sits, for the printed parts.
 * @return {{path: string, words: number}[]} Each description and its words.
 */
export function schemaDescriptions( schema, path = 'input' ) {
	if ( ! schema || typeof schema !== 'object' || Array.isArray( schema ) ) {
		return [];
	}
	const found =
		typeof schema.description === 'string'
			? [ { path, words: countWords( schema.description ) } ]
			: [];
	for ( const [ key, child ] of Object.entries( schema.properties || {} ) ) {
		found.push( ...schemaDescriptions( child, `${ path }.${ key }` ) );
	}
	for ( const key of [ 'items', 'additionalProperties', 'not' ] ) {
		const child = schema[ key ];
		for ( const [ index, branch ] of ( Array.isArray( child )
			? child
			: [ child ]
		).entries() ) {
			found.push(
				...schemaDescriptions(
					branch,
					Array.isArray( child )
						? `${ path }.${ key }[${ index }]`
						: `${ path }.${ key }`
				)
			);
		}
	}
	for ( const key of [ 'anyOf', 'oneOf', 'allOf' ] ) {
		for ( const [ index, branch ] of ( Array.isArray( schema[ key ] )
			? schema[ key ]
			: []
		).entries() ) {
			found.push(
				...schemaDescriptions(
					branch,
					`${ path }.${ key }[${ index }]`
				)
			);
		}
	}
	for ( const [ key, child ] of Object.entries(
		schema.patternProperties || {}
	) ) {
		found.push(
			...schemaDescriptions(
				child,
				`${ path }.patternProperties.${ key }`
			)
		);
	}
	return found;
}

/**
 * The reading budget: the quick card, and each ability's description and
 * input descriptions, as /wp-abilities/v1/abilities lists them.
 *
 * @param {Array<{name?: string, description?: string, input_schema?: Object}>} abilities The listing.
 * @param {string}                                                              card      The quick card's text.
 * @return {{parts: Array<{name: string, description: number, input: number}>, card: number, total: number, fits: boolean}} The budget.
 */
export function readingBudget( abilities, card ) {
	const parts = abilities.map( ( ability ) => ( {
		name: String( ability?.name ),
		description: countWords( ability?.description ),
		input: schemaDescriptions( ability?.input_schema ).reduce(
			( sum, found ) => sum + found.words,
			0
		),
	} ) );
	const cardWords = countWords( card );
	const total = parts.reduce(
		( sum, part ) => sum + part.description + part.input,
		cardWords
	);
	return { parts, card: cardWords, total, fits: total < READING_LIMIT };
}
