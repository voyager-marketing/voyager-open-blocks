<?php
/**
 * The page recipes in recipes/, read as contract 5 in docs/v2-contracts.md
 * gives their shape.
 *
 * The Design lane writes the files and this reads them: get-catalog lists the
 * recipes, and compose builds a page from one. Each section's pattern and
 * layout resolve with resolve_layout(), the function compose resolves its
 * sections with, so a recipe and a list of sections cannot disagree on what a
 * layout names. A file that does not decode, is not in contract 5's shape, or
 * names a section and layout no intent carries is skipped with a
 * _doing_it_wrong() notice, so neither ability ever sees it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\SectionStyles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every recipe that reads as contract 5's shape, in file name order.
 *
 * A section's layout is null when the file gives null or no layout, its style
 * plain when it gives none, and its copy_guidance empty when it gives none.
 * Its omit is there only when the file gives one, so get-catalog lists each
 * section as its file holds it.
 *
 * @param string|null $dir     Recipes directory. Defaults to this plugin's recipes.
 * @param string|null $intents Intents directory, for section_layouts(). Defaults to this plugin's.
 * @return list<array{slug: string, title: string, description: string, page_type: string, sections: list<array{pattern: string, layout: string|null, style: string, copy_guidance: array<string, string>, omit?: list<string>}>}>
 */
function recipes( ?string $dir = null, ?string $intents = null ): array {
	$dir     = $dir ?? VOYAGER_OPEN_BLOCKS_DIR . '/recipes';
	$layouts = section_layouts( $intents );
	$out     = [];

	foreach ( glob( $dir . '/*.json' ) ?: [] as $file ) {
		$slug   = basename( $file, '.json' );
		$recipe = read_recipe( $file, $layouts );

		if ( is_string( $recipe ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html( sprintf( 'recipes/%1$s.json is skipped, so get-catalog does not list it and compose does not build it: %2$s. Contract 5 in docs/v2-contracts.md gives a recipe\'s shape.', $slug, $recipe ) ),
				'0.9.0'
			);
			continue;
		}

		$out[] = [ 'slug' => $slug ] + $recipe;
	}

	return $out;
}

/**
 * One recipe file, read, or why it is skipped.
 *
 * @param string                                                            $file    Path to the file.
 * @param array<string, list<array{layout: string|null, pattern: string}>> $layouts From section_layouts().
 * @return array{title: string, description: string, page_type: string, sections: list<array{pattern: string, layout: string|null, style: string, copy_guidance: array<string, string>, omit?: list<string>}>}|string The recipe, or the reason it is skipped.
 */
function read_recipe( string $file, array $layouts ) {
	if ( 1 !== preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', basename( $file, '.json' ) ) ) {
		return 'its file name is not a slug';
	}

	$contents = is_readable( $file ) ? file_get_contents( $file ) : false;
	$data     = false === $contents ? null : json_decode( $contents, true );

	if ( ! is_array( $data ) || array_is_list( $data ) ) {
		return 'it does not decode to a JSON object';
	}

	foreach ( [ 'title', 'description', 'page_type' ] as $key ) {
		if ( ! is_string( $data[ $key ] ?? null ) || '' === trim( $data[ $key ] ) ) {
			return sprintf( '"%s" is not a non-empty string', $key );
		}
	}

	$sections = $data['sections'] ?? null;
	if ( ! is_array( $sections ) || [] === $sections || ! array_is_list( $sections ) ) {
		return '"sections" is not a non-empty list';
	}
	if ( count( $sections ) > MAX_SECTIONS ) {
		return sprintf( 'it has %1$d sections, and compose builds at most %2$d', count( $sections ), MAX_SECTIONS );
	}

	$out = [];

	foreach ( $sections as $index => $section ) {
		if ( ! is_array( $section ) || ( [] !== $section && array_is_list( $section ) ) ) {
			return sprintf( 'sections[%d] is not a JSON object', $index );
		}

		$pattern  = $section['pattern'] ?? null;
		$layout   = $section['layout'] ?? null;
		$style    = $section['style'] ?? 'plain';
		$guidance = $section['copy_guidance'] ?? [];
		$omit     = $section['omit'] ?? null;

		if ( ! is_string( $pattern ) ) {
			return sprintf( 'sections[%d] has no pattern', $index );
		}
		if ( null !== $layout && ! is_string( $layout ) ) {
			return sprintf( 'sections[%d]: layout is neither a layout\'s name nor null', $index );
		}
		if ( null === resolve_layout( $layouts, $pattern, $layout ) ) {
			return sprintf( 'sections[%1$d] names %2$s with layout %3$s, and no intent in patterns/intents/ carries it', $index, $pattern, null === $layout ? 'null' : '"' . $layout . '"' );
		}
		if ( ! is_string( $style ) || ( 'plain' !== $style && ! isset( SectionStyles\STYLES[ $style ] ) ) ) {
			return sprintf( 'sections[%1$d]: style is not one of plain, %2$s', $index, implode( ', ', array_keys( SectionStyles\STYLES ) ) );
		}
		if ( ! is_array( $guidance ) || ( [] !== $guidance && array_is_list( $guidance ) ) ) {
			return sprintf( 'sections[%d]: copy_guidance is not an object of copy label => line', $index );
		}

		$lines = [];
		foreach ( $guidance as $slot => $line ) {
			if ( ! is_string( $line ) ) {
				return sprintf( 'sections[%1$d]: the copy_guidance for "%2$s" is not a string', $index, (string) $slot );
			}
			$lines[ (string) $slot ] = $line;
		}

		// omit, when the file gives it: a non-empty list of block labels, as a
		// compose section's omit takes them. compose holds it to the copy rules.
		if ( null !== $omit && ( ! is_array( $omit ) || [] === $omit || ! array_is_list( $omit ) || [] !== array_filter( $omit, static fn ( mixed $label ): bool => ! is_string( $label ) || '' === trim( $label ) ) ) ) {
			return sprintf( 'sections[%d]: omit is not a non-empty list of block labels', $index );
		}

		/** @var non-empty-list<string>|null $omit */
		$out[] = [
			'pattern'       => $pattern,
			'layout'        => $layout,
			'style'         => $style,
			'copy_guidance' => $lines,
		] + ( null === $omit ? [] : [ 'omit' => $omit ] );
	}

	return [
		'title'       => (string) $data['title'],
		'description' => (string) $data['description'],
		'page_type'   => (string) $data['page_type'],
		'sections'    => $out,
	];
}

/**
 * The recipes as get-catalog lists them.
 *
 * Each section's copy_guidance is an object, so a section with none gives
 * `{}` in JSON, never `[]`.
 *
 * @param string|null $dir     Recipes directory. Defaults to this plugin's recipes.
 * @param string|null $intents Intents directory. Defaults to this plugin's.
 * @return list<array{slug: string, title: string, description: string, page_type: string, sections: list<array{pattern: string, layout: string|null, style: string, copy_guidance: object, omit?: list<string>}>}>
 */
function recipe_list( ?string $dir = null, ?string $intents = null ): array {
	return array_map(
		static function ( array $recipe ): array {
			$recipe['sections'] = array_map(
				static function ( array $section ): array {
					$section['copy_guidance'] = (object) $section['copy_guidance'];
					return $section;
				},
				$recipe['sections']
			);
			return $recipe;
		},
		recipes( $dir, $intents )
	);
}

/**
 * The recipe a slug names, or null when none does.
 *
 * @param string      $slug    Recipe slug, such as home.
 * @param string|null $dir     Recipes directory. Defaults to this plugin's recipes.
 * @param string|null $intents Intents directory. Defaults to this plugin's.
 * @return array{slug: string, title: string, description: string, page_type: string, sections: list<array{pattern: string, layout: string|null, style: string, copy_guidance: array<string, string>, omit?: list<string>}>}|null
 */
function find_recipe( string $slug, ?string $dir = null, ?string $intents = null ): ?array {
	foreach ( recipes( $dir, $intents ) as $recipe ) {
		if ( $slug === $recipe['slug'] ) {
			return $recipe;
		}
	}

	return null;
}

/**
 * compose's recipe input: an enum of the recipes' slugs.
 *
 * With no recipe to read, the enum is left out, since JSON Schema refuses an
 * empty one, and compose refuses every slug itself.
 *
 * @return array{type: string, description: string, enum?: list<string>}
 */
function recipe_input_schema(): array {
	$slugs  = array_column( recipes(), 'slug' );
	$schema = [
		'type'        => 'string',
		'description' => 'The slug of a page recipe get-catalog lists under recipes, such as home, in place of sections. compose builds the recipe\'s sections in order, each in its layout and style, with each pattern\'s own text, less the blocks a section omits: stand-ins for the site owner to replace. To write the copy in the same call, pass the recipe\'s sections as sections instead, each with copy written from its copy_guidance and the omit it carries. Pass recipe or sections, not both.',
	];

	if ( [] !== $slugs ) {
		$schema['enum'] = $slugs;
	}

	return $schema;
}

/**
 * A recipe's sections as compose's sections input: each section's pattern,
 * layout, style and omit, and no copy.
 *
 * They go through sections_input(), the same reading compose gives a list of
 * sections, so a recipe page is built exactly as that list would be, and its
 * omit is held to the copy rules as a section's omit is.
 *
 * @param array{sections: list<array{pattern: string, layout: string|null, style: string, omit?: list<string>}>} $recipe From recipes().
 * @return list<array{pattern: string, layout: string|null, style: string, copy: array<string, array{text: string, user: bool}>, omit: list<string>, repeat: array<string, string[]>}>
 */
function recipe_sections( array $recipe ): array {
	return sections_input(
		array_map(
			static fn ( array $section ): array => [
				'pattern' => $section['pattern'],
				'layout'  => $section['layout'],
				'style'   => $section['style'],
				'omit'    => $section['omit'] ?? [],
			],
			$recipe['sections']
		)
	);
}
