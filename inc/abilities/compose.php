<?php
/**
 * The compose ability: a whole draft page from a list of sections, or from a
 * page recipe, in one call.
 *
 * Contract 6 in docs/v2-contracts.md has the shapes. A recipe (recipes.php)
 * stands in for the list: its sections, each with its style, its omit and no
 * copy, go through sections_input() and are built as a list is. Each section
 * names a pack pattern by its section and layout, resolved as contract 5 says,
 * or by the pattern's own name, read as its section and layout
 * (layout_named()), and is inserted as a copy. Its repeat, omit and copy are
 * applied and checked
 * with fill_pattern(), the code insert fills a pattern with (copy-rules.php),
 * then bound_copy_slots(), and its style
 * is set on its top-level group the way configure's style input sets it
 * (SectionStyles\apply()). Two sections next to each other may not share a
 * style other than plain. When anything is refused, nothing is saved, and
 * every refused slot is listed with its section's index.
 *
 * The page is saved with save_blocks(), the revision handling insert uses, so
 * undo reverses a compose save. compose never renames a draft, since undo
 * restores content and not the title: title beside post_id is taken only when
 * it is the draft's title already (title_refusal()).
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\SectionStyles;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The most sections one compose call takes. A home page holds about eight.
 */
const MAX_SECTIONS = 20;

/**
 * Register compose.
 */
function register_compose(): void {
	wp_register_ability(
		CATEGORY . '/compose',
		[
			'label'               => __( 'Compose a Voyager Open draft page', 'voyager-open-blocks' ),
			'description'         => __( 'Builds a whole draft page in one call: title for a new page, or post_id of a draft to replace, and sections, top to bottom. For most pages, pass the sections get-catalog gives with recipe, as it lists them, each with copy written from its copy_guidance, and omit any item you have no copy for. Each section is placed and checked as insert places a pattern copy, its copy, omit and repeat included, with its section style on its top-level group. Only drafts: change a live page with insert and configure. When anything is refused, nothing is saved, and refused lists every slot with its section index (adjacent_style for two sections in one style). Returns post_id, revision_id for undo, preview_url, validation (what validate returns for the page, placeholder: on each stand-in left) and empty, each Site Data key the page binds that is empty now, so the page shows the pattern\'s own text there.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'A draft whose whole content compose replaces. Its title stays.',
					],
					'title'    => [
						'type'        => 'string',
						'minLength'   => 1,
						'pattern'     => '\S',
						'description' => 'Title of a new draft page, owned by you. With post_id, only the draft\'s own title.',
					],
					'recipe'   => recipe_input_schema(),
					'sections' => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => MAX_SECTIONS,
						'description' => 'The page\'s sections, top to bottom. Not with recipe. copy_guidance and copy_rules, as get-catalog lists them, are ignored.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'pattern'       => [
									'type'        => 'string',
									'enum'        => compose_patterns(),
									'description' => 'A pattern get-catalog lists, by its name, such as voyager-open/services-list, or by its section, such as voyager-open/services with layout list.',
								],
								'layout'        => [
									'type'        => [ 'string', 'null' ],
									'minLength'   => 1,
									'description' => 'With a section, its layout get-catalog lists; null, the default, is the first. With a pattern\'s name, null or that pattern\'s layout.',
								],
								'style'         => [
									'type'        => 'string',
									'enum'        => array_merge( [ 'plain' ], array_keys( SectionStyles\STYLES ) ),
									'default'     => 'plain',
									'description' => 'plain, the default, sets none. Two sections next to each other never share a style but plain.',
								],
								'copy'          => [
									'type'                 => 'object',
									'default'              => [],
									'additionalProperties' => copy_value_schema(),
									'description'          => 'Text by copy label, as insert\'s copy takes it, within the pattern\'s copy_rules. A claim slot takes only the object form, and a slot whose text is bound, such as "Founded value", none.',
								],
								'omit'          => omit_schema( 'As insert\'s omit, for this section, such as ["Service 3"] for two service cards. A recipe\'s section may carry omit: pass it on.' ),
								'repeat'        => repeat_schema( 'As insert\'s repeat, such as {"Credential 4": ["Credential 5"]} for a fifth fact. A copy holds the text and copy rule of the block it copies, so give it copy: a copy of a claim slot is a claim.' ),
								// What get-catalog lists on a recipe's section, taken
								// and ignored, so a section copied from it goes in as
								// it is. Every other key is still refused.
								'copy_guidance' => [ 'type' => 'object' ],
								'copy_rules'    => [ 'type' => 'object' ],
							],
							'required'             => [ 'pattern' ],
							'additionalProperties' => false,
						],
					],
					'dry_run'  => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Run every check, and create or change no post.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'post_id'     => [
						'type'        => [ 'integer', 'null' ],
						'description' => 'The draft saved, or the post_id given. null when title was given and nothing was saved.',
					],
					'preview_url' => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The draft\'s preview link, from get_preview_post_link(). null when nothing was saved.',
					],
					'saved'       => [ 'type' => 'boolean' ],
					'validation'  => [
						'type'        => 'object',
						'description' => 'What validate returns for the page\'s markup, warnings included.',
					],
					'empty'       => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Each Site Data key the page binds whose value is empty now. Its blocks show the pattern\'s own text until the value is set under Settings > Site Data.',
					],
					'refused'     => [
						'type'        => 'array',
						'description' => 'Every refused slot, each with section (its index in sections), slot, reason and what the reason needs, as insert lists them. adjacent_style names both sections in sections. Empty when nothing was refused.',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'section' => [ 'type' => 'integer' ],
								'slot'    => [ 'type' => 'string' ],
								'reason'  => [ 'type' => 'string' ],
								'message' => [ 'type' => 'string' ],
							],
						],
					],
				] + revision_output_schema(),
			],
			'execute_callback'    => __NAMESPACE__ . '\compose',
			'permission_callback' => __NAMESPACE__ . '\can_compose',
			// Not idempotent: each call with title creates a draft. Not
			// destructive: the content a draft held is kept as a revision.
			'meta'                => meta( false, false ),
		]
	);
}

/**
 * Permission: edit the draft named by post_id, or, to create one, edit pages.
 *
 * @param array<string, mixed>|null $input Ability input.
 */
function can_compose( $input = null ): bool {
	if ( is_array( $input ) && isset( $input['post_id'] ) ) {
		return can_edit_post( $input );
	}

	return current_user_can( 'edit_pages' );
}

/**
 * The pack's sections and their layouts, as the intents say (contract 5).
 *
 * An intent with a `section` key is one layout of that section. One without is
 * a section with one layout, named by its own slug. The layout that keeps the
 * section's slug is its first.
 *
 * Read from the generated array (Patterns\generated()), which holds the
 * intents in the order glob() lists their files, so registering get-catalog
 * and compose reads no intent file.
 *
 * @param string|null $dir Intents directory to read instead, such as a test's. Defaults to the generated array, built from this plugin's patterns/intents.
 * @return array<string, list<array{layout: string|null, pattern: string}>> Section (voyager-open/<section>) => its layouts, the first one first.
 */
function section_layouts( ?string $dir = null ): array {
	$patterns = null === $dir
		? array_keys( Patterns\generated() )
		: array_map( static fn ( string $file ): string => Catalog\PREFIX . basename( $file, '.json' ), glob( $dir . '/*.json' ) ?: [] );
	$out      = [];

	foreach ( $patterns as $pattern ) {
		$slug    = substr( $pattern, strlen( Catalog\PREFIX ) );
		$meta    = Patterns\intent_meta( $pattern, $dir );
		$section = Catalog\PREFIX . ( $meta['section'] ?? $slug );
		$entry   = [
			'layout'  => null === $meta['section'] ? null : $meta['layout'],
			'pattern' => $pattern,
		];

		$out[ $section ] = $section === $pattern
			? array_merge( [ $entry ], $out[ $section ] ?? [] )
			: array_merge( $out[ $section ] ?? [], [ $entry ] );
	}

	return $out;
}

/**
 * The pattern a section and layout name, as contract 5 resolves them.
 *
 * null is the section's first layout, the pattern that keeps the section's
 * slug. A layout no intent of the section carries names no pattern.
 *
 * @param array<string, list<array{layout: string|null, pattern: string}>> $layouts From section_layouts().
 * @param string                                                            $section Section, such as voyager-open/hero.
 * @param string|null                                                       $layout  Layout name, or null.
 * @return string|null Pattern name, such as voyager-open/hero-cover, or null when none.
 */
function resolve_layout( array $layouts, string $section, ?string $layout ): ?string {
	foreach ( $layouts[ $section ] ?? [] as $entry ) {
		if ( null === $layout ? $entry['pattern'] === $section : $entry['layout'] === $layout ) {
			return $entry['pattern'];
		}
	}

	return null;
}

/**
 * What a compose section's pattern may name: each section, and each layout by
 * its own pattern name, each once, sorted.
 *
 * A section's first layout keeps the section's name, so most names are both.
 *
 * @param string|null $dir Intents directory to read instead, as section_layouts() takes it.
 * @return list<string>
 */
function compose_patterns( ?string $dir = null ): array {
	$layouts = section_layouts( $dir );
	$names   = array_keys( $layouts );

	foreach ( $layouts as $entries ) {
		array_push( $names, ...array_column( $entries, 'pattern' ) );
	}

	$names = array_values( array_unique( $names ) );
	sort( $names );

	return $names;
}

/**
 * The section and layout a layout's own pattern name stands for.
 *
 * get-catalog lists every pattern by its name, such as
 * voyager-open/proof-strip-facts, beside its section and layout. compose
 * takes that name as a section's pattern and reads it as its section and
 * layout, proof-strip and facts, so it builds what the two would. A section's
 * own name is read as the section, as before, so voyager-open/proof-strip with
 * layout facts is still the facts layout.
 *
 * @param array<string, list<array{layout: string|null, pattern: string}>> $layouts From section_layouts().
 * @param string                                                            $pattern A compose section's pattern.
 * @return array{section: string, layout: string}|null Null when pattern names a section, or no layout.
 */
function layout_named( array $layouts, string $pattern ): ?array {
	if ( isset( $layouts[ $pattern ] ) ) {
		return null;
	}

	foreach ( $layouts as $section => $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry['pattern'] === $pattern && null !== $entry['layout'] ) {
				return [
					'section' => $section,
					'layout'  => $entry['layout'],
				];
			}
		}
	}

	return null;
}

/**
 * Why a layout's own pattern name and a layout that is not its own are refused, as one sentence.
 *
 * @param int                                    $index   Index of the section.
 * @param string                                 $pattern The pattern given, a layout's own name.
 * @param string                                 $layout  The layout given.
 * @param array{section: string, layout: string} $named   From layout_named().
 */
function layout_disagrees( int $index, string $pattern, string $layout, array $named ): string {
	return sprintf(
		'section %1$d: %2$s is the %3$s layout of %4$s, and layout "%5$s" disagrees with it. Leave layout out, or pass %4$s with the layout you want.',
		$index,
		$pattern,
		$named['layout'],
		$named['section'],
		$layout
	);
}

/**
 * Why compose refuses title beside post_id, or '' when it takes it.
 *
 * compose never renames a draft: undo restores a save's content and not its
 * title, so a title compose changed could not be undone. A title that is the
 * draft's own already, such as one left in a call that now adds post_id, is
 * taken and changes nothing. Both are compared with surrounding space trimmed
 * and HTML entities decoded, since WordPress may store "&" as "&amp;".
 *
 * @param int    $post_id The draft.
 * @param string $title   The title given.
 * @param string $current The draft's title, as WordPress stores it.
 */
function title_refusal( int $post_id, string $title, string $current ): string {
	$same = static fn ( string $text ): string => html_entity_decode( trim( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	if ( $same( $title ) === $same( $current ) ) {
		return '';
	}

	return sprintf(
		'Nothing was saved: draft %1$d is titled "%2$s", and compose does not rename a draft (undo restores content, not titles). Drop title to replace the draft\'s content and keep its title, or drop post_id to build a new draft titled "%3$s".',
		$post_id,
		$current,
		$title
	);
}

/**
 * compose's sections input, each with its pattern, layout, style, copy, omit and repeat.
 *
 * omit and repeat are read with insert's readers, omit_input() and repeat_input().
 *
 * @param mixed $value Input.
 * @return list<array{pattern: string, layout: string|null, style: string, copy: array<string, array{text: string, user: bool}>, omit: list<string>, repeat: array<string, string[]>}>
 */
function sections_input( mixed $value ): array {
	$out = [];

	foreach ( is_array( $value ) ? $value : [] as $section ) {
		$section = assoc( is_object( $section ) ? get_object_vars( $section ) : $section );

		$out[] = [
			'pattern' => Cast\to_string( $section['pattern'] ?? '' ),
			'layout'  => isset( $section['layout'] ) ? Cast\to_string( $section['layout'] ) : null,
			'style'   => isset( $section['style'] ) ? Cast\to_string( $section['style'] ) : 'plain',
			'copy'    => copy_values( $section['copy'] ?? null ),
			'omit'    => omit_input( $section['omit'] ?? null ),
			'repeat'  => repeat_input( $section['repeat'] ?? null ),
		];
	}

	return $out;
}

/**
 * A section's style: the section style class on its top-level group, plain when it has none.
 *
 * @param array<int, Block> $blocks The section's top-level blocks.
 */
function section_style( array $blocks ): string {
	$split   = preg_split( '/\s+/', Cast\to_string( $blocks[0]['attrs']['className'] ?? '' ) );
	$classes = false === $split ? [] : $split;

	foreach ( array_keys( SectionStyles\STYLES ) as $style ) {
		if ( in_array( SectionStyles\class_name( $style ), $classes, true ) ) {
			return $style;
		}
	}

	return 'plain';
}

/**
 * The sections that share a style other than plain with the one before them.
 *
 * DESIGN.md's Layout rule, and contract 5: two adjacent sections never share
 * a style other than plain. Each pair is one refusal, on the second section.
 *
 * @param array<int, string> $styles Each section's style, in page order.
 * @return list<array{section: int, slot: string, reason: string, message: string, style: string, sections: array{0: int, 1: int}}>
 */
function adjacent_style_refusals( array $styles ): array {
	$styles   = array_values( $styles );
	$refused  = [];
	$previous = null;

	foreach ( $styles as $index => $style ) {
		if ( null !== $previous && 'plain' !== $style && $styles[ $previous ] === $style ) {
			$refused[] = [
				'section'  => $index,
				'slot'     => 'style',
				'reason'   => 'adjacent_style',
				'message'  => sprintf( 'Sections %1$d and %2$d are both %3$s, and two sections next to each other never share a style other than plain. Give one of them another style, or plain.', $previous, $index, $style ),
				'style'    => $style,
				'sections' => [ $previous, $index ],
			];
		}

		$previous = $index;
	}

	return $refused;
}

/**
 * One section's refused slots, as insert refuses a pattern's, each with the section's index.
 *
 * The rows are the ones copy_error() lists in data.refused: the bound slots,
 * then the copy rules' refusals beside them. compose returns them with no
 * error message around them, so a bound row also carries message, the line
 * insert's message gives it, which says where to change the value instead.
 *
 * @param int                                                                                                                    $section Index of the section.
 * @param list<array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string}> $bound   From bound_copy_slots().
 * @param list<array{slot: string, reason: string, message: string, max?: int, length?: int, omitted?: string}>                  $broken  From copy_rule_refusals().
 * @return list<array<mixed>>
 */
function section_refusals( int $section, array $bound, array $broken ): array {
	if ( [] === $bound && [] === $broken ) {
		return [];
	}

	$lines = [];
	foreach ( $bound as $slot ) {
		$lines[ $slot['slot'] ] = bound_slot_line( $slot );
	}

	$data = copy_error( $bound, $broken )->get_error_data();
	$rows = is_array( $data ) && is_array( $data['refused'] ?? null ) ? $data['refused'] : [];
	$out  = [];

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$slot = Cast\to_string( $row['slot'] ?? '' );
		$line = 'bound' === ( $row['reason'] ?? null ) && isset( $lines[ $slot ] ) ? [ 'message' => $lines[ $slot ] ] : [];

		$out[] = [ 'section' => $section ] + $row + $line;
	}

	return $out;
}

/**
 * The Site Data keys a page binds whose value is empty now.
 *
 * Each key once, in the order the page first binds it, read as the pack's
 * binding reads it: SiteData\get_binding_value(), which applies the
 * voyager_site_data_value filter and returns null for an empty value. A key
 * Site Data does not have is left out.
 *
 * @param array<int, Row> $rows The page's blocks as BlockTree\describe() lists them.
 * @return list<string>
 */
function empty_site_data( array $rows ): array {
	$known = SiteData\values();
	$seen  = [];
	$empty = [];

	foreach ( $rows as $row ) {
		foreach ( (array) ( $row['bindings'] ?? [] ) as $attribute => $binding ) {
			$binding = is_array( $binding ) ? $binding : [];
			$args    = is_array( $binding['args'] ?? null ) ? $binding['args'] : [];
			$key     = Cast\to_string( $args['key'] ?? '' );

			if ( SiteData\SOURCE !== ( $binding['source'] ?? null ) || ! array_key_exists( $key, $known ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( null === SiteData\get_binding_value( assoc( $args ), null, (string) $attribute ) ) {
				$empty[] = $key;
			}
		}
	}

	return $empty;
}

/**
 * Why a section's layout names no pattern, as one sentence.
 *
 * @param int                                                               $index   Index of the section.
 * @param string                                                            $section Its section, such as voyager-open/hero.
 * @param string|null                                                       $layout  The layout given.
 * @param array<string, list<array{layout: string|null, pattern: string}>> $layouts From section_layouts().
 */
function unknown_layout( int $index, string $section, ?string $layout, array $layouts ): string {
	$names = array_values( array_filter( array_column( $layouts[ $section ] ?? [], 'layout' ), 'is_string' ) );

	if ( null === $layout ) {
		return sprintf( 'section %1$d: %2$s is not registered on this site.', $index, $section );
	}

	return [] === $names
		? sprintf( 'section %1$d: %2$s comes in one layout, so its layout is null or left out, not "%3$s".', $index, $section, $layout )
		: sprintf( 'section %1$d: %2$s has no layout "%3$s". Its layouts: %4$s.', $index, $section, $layout, implode( ', ', $names ) );
}

/**
 * The error for one section's input, naming the section.
 *
 * @param int      $index   Index of the section.
 * @param string   $pattern Its pattern.
 * @param WP_Error $error   What was wrong.
 */
function section_error( int $index, string $pattern, WP_Error $error ): WP_Error {
	$data = $error->get_error_data();

	return new WP_Error(
		$error->get_error_code(),
		sprintf( 'Nothing was saved: section %1$d (%2$s): %3$s', $index, $pattern, $error->get_error_message() ),
		[ 'section' => $index ] + ( is_array( $data ) ? $data : [] ) + [ 'status' => 400 ]
	);
}

/**
 * Execute compose.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function compose( array $input ) {
	$has_post   = isset( $input['post_id'] );
	$has_title  = isset( $input['title'] );
	$has_recipe = isset( $input['recipe'] );
	$dry_run    = ! empty( $input['dry_run'] );

	if ( $has_recipe && isset( $input['sections'] ) ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_recipe',
			'Nothing was saved: pass recipe or sections, not both. recipe builds a recipe\'s sections with the patterns\' own text. To give them copy, pass the recipe\'s sections from get-catalog as sections, each with its copy.',
			[ 'status' => 400 ]
		);
	}

	if ( ! $has_post && ! $has_title ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_post_id',
			'Pass title to create a new draft page, or post_id of a draft whose content compose replaces.',
			[ 'status' => 400 ]
		);
	}

	if ( $has_recipe ) {
		$slug   = Cast\to_string( $input['recipe'] );
		$recipe = find_recipe( $slug );

		if ( null === $recipe ) {
			$slugs = array_column( recipes(), 'slug' );

			return new WP_Error(
				'voyager_open_blocks_invalid_recipe',
				sprintf( 'Nothing was saved: no recipe "%1$s". get-catalog lists the recipes under recipes: %2$s.', $slug, [] === $slugs ? 'none on this site' : implode( ', ', $slugs ) ),
				[ 'status' => 400 ]
			);
		}

		$sections = recipe_sections( $recipe );
	} else {
		$sections = sections_input( $input['sections'] ?? null );
	}

	if ( [] === $sections ) {
		return new WP_Error( 'voyager_open_blocks_invalid_sections', 'Pass sections, the page\'s sections, top to bottom, each a pattern get-catalog lists, or recipe, the slug of a page recipe get-catalog lists.', [ 'status' => 400 ] );
	}

	$post_id   = null;
	$post_type = 'page';

	if ( $has_post ) {
		$post_id = Cast\to_int( $input['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error( 'voyager_open_blocks_invalid_post_id', sprintf( 'Post %d does not exist.', $post_id ), [ 'status' => 404 ] );
		}

		// compose replaces the whole page, so it writes drafts only.
		if ( 'draft' !== $post->post_status ) {
			return new WP_Error(
				'voyager_open_blocks_invalid_post_id',
				sprintf( 'Nothing was changed: post %1$d is %2$s, not a draft, and compose replaces a whole page, so it writes drafts only. Change a live page with insert and configure, or pass title to build a new draft.', $post_id, $post->post_status ),
				[ 'status' => 400 ]
			);
		}

		// A title beside post_id must be the draft's own: compose never renames one.
		$refusal = $has_title ? title_refusal( $post_id, Cast\to_string( $input['title'] ), $post->post_title ) : '';
		if ( '' !== $refusal ) {
			return new WP_Error( 'voyager_open_blocks_invalid_post_id', $refusal, [ 'status' => 400 ] );
		}

		$post_type = $post->post_type;
	}

	// Each section's pattern, from its section and layout, or from a layout's
	// own name read as its section and layout. Every one that names no
	// pattern, or a layout that is not its own, is listed at once.
	$layouts  = section_layouts();
	$registry = \WP_Block_Patterns_Registry::get_instance();
	$patterns = [];
	$unknown  = [];

	foreach ( $sections as $index => $section ) {
		$named = layout_named( $layouts, $section['pattern'] );

		if ( null !== $named && null !== $section['layout'] && $named['layout'] !== $section['layout'] ) {
			$unknown[] = layout_disagrees( $index, $section['pattern'], $section['layout'], $named );
			continue;
		}

		$name = null === $named
			? resolve_layout( $layouts, $section['pattern'], $section['layout'] )
			: resolve_layout( $layouts, $named['section'], $named['layout'] );

		if ( null === $name || ! $registry->is_registered( $name ) ) {
			$unknown[] = unknown_layout( $index, $section['pattern'], $section['layout'], $layouts );
			continue;
		}

		$patterns[ $index ] = $name;
	}

	if ( [] !== $unknown ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_layout',
			'Nothing was saved: ' . implode( ' ', $unknown ) . ' get-catalog lists each pattern\'s name, section and layout. Pass a pattern\'s name, or its section with its layout; leave layout out for the first.',
			[ 'status' => 400 ]
		);
	}

	// Build the page, section by section, as insert builds a pattern copy,
	// with the same code: fill_pattern() runs repeat, the copy rules, omit,
	// then copy.
	$page   = [];
	$built  = [];
	$styles = [];

	foreach ( $sections as $index => $section ) {
		$name    = $patterns[ $index ];
		$pattern = $registry->get_registered( $name );
		$new     = BlockTree\parse( Cast\to_string( $pattern['content'] ?? '' ) );
		$copy    = copy_texts( $section['copy'] );
		$broken  = [];

		if ( [] !== $section['copy'] || [] !== $section['omit'] || [] !== $section['repeat'] ) {
			$broken = fill_pattern( $new, Patterns\intent_meta( $name )['copy_rules'], $section['copy'], $section['omit'], $section['repeat'] );
			if ( is_wp_error( $broken ) ) {
				return section_error( $index, $name, $broken );
			}
		}

		// plain sets none: the section keeps its group as the pattern ships it.
		if ( 'plain' !== $section['style'] && isset( $new[0] ) ) {
			$styled = SectionStyles\apply( $new[0], $section['style'] );
			if ( is_wp_error( $styled ) ) {
				return section_error( $index, $name, $styled );
			}
			$new[0] = $styled;
		}

		$styles[ $index ] = section_style( $new );
		$built[ $index ]  = [
			'first'  => count( $page ),
			'last'   => count( $page ) + count( $new ) - 1,
			'copy'   => $copy,
			'broken' => $broken,
		];
		array_push( $page, ...$new );
	}

	$markup = BlockTree\to_markup( $page );
	$report = validation( $page, $markup );

	if ( ! $report['registered_and_attributes'] || ! $report['round_trip'] ) {
		return new WP_Error( 'voyager_open_blocks_invalid_item', 'Nothing was saved: the page\'s markup did not pass validation: ' . implode( ' ', $report['errors'] ), [ 'status' => 400 ] );
	}

	// Every refused slot, section by section: insert's rows, each with the
	// section's index, then the section's style when it repeats the one
	// before it. Bound slots are named by their paths in the page.
	$rows     = BlockTree\describe( $page );
	$adjacent = [];
	$refused  = [];

	foreach ( adjacent_style_refusals( $styles ) as $row ) {
		$adjacent[ $row['section'] ] = $row;
	}

	foreach ( $built as $index => $at ) {
		$section_rows = array_values( array_filter( $rows, static fn ( array $row ): bool => BlockTree\in_range( $row['path'], '', $at['first'], $at['last'] ) ) );
		$bound        = [] === $at['copy'] ? [] : bound_copy_slots( $section_rows, $at['copy'] );

		array_push( $refused, ...section_refusals( $index, $bound, $at['broken'] ) );

		if ( isset( $adjacent[ $index ] ) ) {
			$refused[] = $adjacent[ $index ];
		}
	}

	$checked = validate(
		[
			'markup'    => $markup,
			'post_type' => $post_type,
		]
	);
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}

	$out = [
		'post_id'     => $post_id,
		'revision_id' => null,
		'preview_url' => null,
		'saved'       => false,
		'validation'  => $checked,
		'empty'       => empty_site_data( $rows ),
		'refused'     => $refused,
	];

	if ( [] !== $refused || $dry_run ) {
		return $out;
	}

	$created = false;

	if ( null === $post_id ) {
		$new_id = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => wp_slash( Cast\to_string( $input['title'] ) ),
				'post_author'  => get_current_user_id(),
				'post_content' => '',
			],
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$post_id = $new_id;
		$created = true;
	}

	$revision = save_blocks( $post_id, $page );
	if ( is_wp_error( $revision ) ) {
		// A draft created for this call holds nothing, so it goes too.
		if ( $created ) {
			wp_delete_post( $post_id, true );
		}
		return $revision;
	}

	return [
		'post_id'     => $post_id,
		'preview_url' => get_preview_post_link( $post_id ),
		'saved'       => true,
	] + $revision + $out;
}
