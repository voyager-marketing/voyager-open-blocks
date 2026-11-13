/**
 * The page recipes in recipes/, read the way compose reads them (contract 5
 * in docs/v2-contracts.md), for the rhythm gate in npm run check and the
 * recipe pages npm run design-check renders.
 *
 * rhythm is DESIGN.md's Layout rule on adjacent styles: two sections next to
 * each other never share a style other than plain. A section that names no
 * pattern cannot be placed at all, so the gate refuses that too. The rest of
 * contract 5 (one hero and where it sits, no two strong sections touching,
 * the keys, the copy guidance) is tests/Unit/RecipesTest.php's.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

export const RECIPES = 'recipes';
export const INTENTS = join( 'patterns', 'intents' );

/** What a pattern name starts with, as Catalog\PREFIX holds it. */
const PREFIX = 'voyager-open/';

/** A file name an intent or recipe may have, as intent_meta() reads one. */
const SLUG = /^[a-z0-9]+(-[a-z0-9]+)*$/;

/**
 * The rules rhythm refuses a recipe for. adjacent: two sections next to each
 * other share a style other than plain. layout: a section names a pattern and
 * layout no intent carries. shape: the recipe cannot be read for either.
 */
export const RULES = [ 'adjacent', 'layout', 'shape' ];

/**
 * The planted recipes in tests/invalid/recipes/, by file name, and the rule
 * each breaks. Each breaks that rule and no other.
 */
export const PLANTS = {
	'adjacent-accent': 'adjacent',
	'unknown-layout': 'layout',
};

/**
 * The JSON files in a folder, sorted, as PHP's glob() lists them.
 *
 * @param {string} dir Folder.
 * @return {string[]} File names.
 */
function jsonFiles( dir ) {
	return readdirSync( dir )
		.filter( ( file ) => file.endsWith( '.json' ) )
		.sort();
}

/**
 * The pack's sections and their layouts, read from the intents as compose's
 * section_layouts() reads them (inc/abilities/compose.php). An intent with a
 * `section` key is one layout of that section. One without is a section with
 * one layout, named by its own slug. The layout that keeps the section's slug
 * comes first.
 *
 * @param {string} dir patterns/intents.
 * @return {Map<string, Array<{layout: string|null, pattern: string}>>} Section slug => its layouts, each with its pattern's slug.
 */
export function sectionLayouts( dir ) {
	const out = new Map();
	for ( const file of jsonFiles( dir ) ) {
		const slug = file.slice( 0, -5 );
		if ( ! SLUG.test( slug ) ) {
			continue;
		}
		const meta = JSON.parse( readFileSync( join( dir, file ), 'utf8' ) );
		const section = typeof meta?.section === 'string' ? meta.section : null;
		const entry = {
			layout:
				section !== null && typeof meta.layout === 'string'
					? meta.layout
					: null,
			pattern: slug,
		};
		const key = section ?? slug;
		const list = out.get( key ) ?? [];
		out.set( key, key === slug ? [ entry, ...list ] : [ ...list, entry ] );
	}
	return out;
}

/**
 * The pattern a recipe section names, as compose's resolve_layout() resolves
 * it: `pattern` names the section, and a null layout is its first layout, the
 * one that keeps the section's slug. A layout no intent of the section
 * carries names nothing, and neither does a layout's own pattern name.
 *
 * @param {Map}         layouts From sectionLayouts().
 * @param {unknown}     pattern The section's `pattern`, such as voyager-open/hero.
 * @param {string|null} layout  The section's `layout`.
 * @return {string|null} The pattern's slug, such as hero-cover, or null.
 */
export function resolveLayout( layouts, pattern, layout ) {
	if ( typeof pattern !== 'string' || ! pattern.startsWith( PREFIX ) ) {
		return null;
	}
	const section = pattern.slice( PREFIX.length );
	for ( const entry of layouts.get( section ) ?? [] ) {
		if (
			layout === null
				? entry.pattern === section
				: entry.layout === layout
		) {
			return entry.pattern;
		}
	}
	return null;
}

/**
 * Every recipe file in a folder, by slug, parsed. A file that is not JSON
 * comes back with its error.
 *
 * @param {string} dir recipes/, or a folder of planted recipes.
 * @return {Array<{slug: string, recipe?: unknown, error?: string}>} Sorted by slug.
 */
export function readRecipes( dir ) {
	return jsonFiles( dir ).map( ( file ) => {
		const slug = file.slice( 0, -5 );
		try {
			return {
				slug,
				recipe: JSON.parse( readFileSync( join( dir, file ), 'utf8' ) ),
			};
		} catch ( error ) {
			return { slug, error: error?.message || String( error ) };
		}
	} );
}

/**
 * Everything rhythm refuses in one recipe, one line each.
 *
 * A missing `layout` is null and a missing `style` plain, as compose reads
 * them (read_recipe() in inc/abilities/recipes.php). A style must be a
 * string to be compared, and plain is the one style two sections next to
 * each other may share.
 *
 * @param {{slug: string, recipe?: unknown, error?: string}} entry   From readRecipes().
 * @param {Map}                                              layouts From sectionLayouts().
 * @return {Array<{rule: string, line: string}>} Problems, each with its rule from RULES.
 */
export function rhythmProblems( entry, layouts ) {
	const problems = [];
	const add = ( rule, line ) =>
		problems.push( { rule, line: `${ entry.slug }${ line }` } );
	if ( entry.error ) {
		add( 'shape', `: is not JSON (${ entry.error })` );
		return problems;
	}
	const sections = entry.recipe?.sections;
	if ( ! Array.isArray( sections ) || ! sections.length ) {
		add( 'shape', ': sections is not a non-empty list' );
		return problems;
	}
	let previous = null;
	sections.forEach( ( section, index ) => {
		const isObject =
			section !== null &&
			typeof section === 'object' &&
			! Array.isArray( section );
		const pattern = isObject ? section.pattern : undefined;
		const where = ` section ${ index + 1 }${
			typeof pattern === 'string' ? ` (${ pattern })` : ''
		}`;
		if ( ! isObject ) {
			add( 'shape', `${ where }: is not a JSON object` );
			previous = null;
			return;
		}
		const layout = section.layout ?? null;
		if ( layout !== null && typeof layout !== 'string' ) {
			add(
				'layout',
				`${ where }: layout is neither a layout's name nor null`
			);
		} else if ( typeof pattern !== 'string' ) {
			add( 'layout', `${ where }: pattern is not a string` );
		} else if ( resolveLayout( layouts, pattern, layout ) === null ) {
			add(
				'layout',
				`${ where }: names no pattern, since no intent in patterns/intents/ carries "${ pattern }" with layout ${
					layout === null ? 'null' : `"${ layout }"`
				}`
			);
		}
		const style = section.style ?? 'plain';
		if ( typeof style !== 'string' ) {
			add( 'shape', `${ where }: style is not a string` );
			previous = null;
			return;
		}
		if ( style === previous && style !== 'plain' ) {
			add(
				'adjacent',
				`${ where }: shares the style "${ style }" with the section before it, and only plain sections sit next to each other in one style`
			);
		}
		previous = style;
	} );
	return problems;
}

/**
 * Each recipe's sections as compose builds them: the pattern each names and
 * its style, for the recipe pages design-check renders. A recipe rhythm
 * refuses comes back with its problems and no sections.
 *
 * @param {string} root The repo.
 * @return {Array<{slug: string, sections: Array<{pattern: string, style: string}>, problems: string[]}>} Sorted by slug.
 */
export function recipePages( root ) {
	const layouts = sectionLayouts( join( root, INTENTS ) );
	return readRecipes( join( root, RECIPES ) ).map( ( entry ) => {
		const problems = rhythmProblems( entry, layouts ).map(
			( p ) => p.line
		);
		return {
			slug: entry.slug,
			problems,
			sections: problems.length
				? []
				: entry.recipe.sections.map( ( section ) => ( {
						pattern: resolveLayout(
							layouts,
							section.pattern,
							section.layout ?? null
						),
						style: section.style ?? 'plain',
					} ) ),
		};
	} );
}
