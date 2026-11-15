/**
 * How much of get-catalog an agent reads in one tool result.
 *
 * An agent reads what an ability returns as one tool result, and a client
 * may save a long result to a file instead of showing it, so the agent
 * spends turns reading the file back. get-catalog with no options, and its
 * detail for one recipe, each stay under CATALOG_LIMIT characters of JSON
 * (npm run e2e).
 *
 * The count is the response body as WordPress sends it over REST, which is
 * the JSON an MCP adapter passes on: no spaces, a slash written `\/`, so a
 * number printed here is the body's length, easy to reproduce with curl.
 */

export const CATALOG_LIMIT = 20000;

/**
 * The answer fits in one tool result.
 *
 * @param {number} chars Characters of JSON in the response body.
 * @return {boolean} Under CATALOG_LIMIT, and not empty.
 */
export function catalogFits( chars ) {
	return Number.isInteger( chars ) && chars > 0 && chars < CATALOG_LIMIT;
}
