<?php
/**
 * The get-catalog ability: the blocks, patterns and binding fields the pack offers.
 *
 * Contract 8 in docs/v2-contracts.md has the shapes. With no options it
 * answers in brief, so the answer fits in one tool result: the section
 * styles, each pattern's name, description, section and layout, the binding
 * sources with whether each Site Data field is filled, and each recipe's
 * sections as compose takes them. recipe, patterns and include name detail,
 * and the answer then holds only what they name. include with all six keys
 * is the whole catalog.
 *
 * The parts that change only with the code, the theme or a setting are kept
 * between calls (catalog_answer()), and built again after any of
 * CATALOG_TRIGGERS. Whether a Site Data field is filled, and used_on, are
 * read on every call.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SectionStyles;
use VoyagerOpenBlocks\SiteData;
use VoyagerOpenBlocks\Usage;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalog's lists, in the order the whole catalog gives them. include
 * names any of them.
 */
const CATALOG_KEYS = [ 'styles', 'blocks', 'patterns', 'sources', 'used_on', 'recipes' ];

/**
 * The transient a locale's kept catalog parts are in, less the locale.
 */
const CATALOG_CACHE = 'voyager_open_blocks_catalog_';

/**
 * The option that counts the triggers that have fired (trigger_count()).
 * catalog_version() holds it, so each trigger makes the next call build
 * every part again.
 */
const CATALOG_VERSION = 'voyager_open_blocks_catalog_version';

/**
 * Seconds the kept parts last when no trigger fires. A transient that never
 * expires is autoloaded on every request, and this one holds the catalog.
 */
const CATALOG_TTL = 86400;

/**
 * The hooks after which the kept parts are built again: a plugin activated,
 * deactivated or upgraded (upgrader_process_complete also fires for a theme
 * or WordPress upgrade), another theme, a save of the Global Styles (a style
 * variation chosen in the Site Editor is one), and the bindable post meta
 * allowlist added, changed or deleted. The pack's own Version is in
 * catalog_version().
 */
const CATALOG_TRIGGERS = [
	'activated_plugin',
	'deactivated_plugin',
	'upgrader_process_complete',
	'switch_theme',
	'save_post_wp_global_styles',
	'add_option_' . PostMetaText\OPTION,
	'update_option_' . PostMetaText\OPTION,
	'delete_option_' . PostMetaText\OPTION,
];

/**
 * Register get-catalog.
 */
function register_get_catalog(): void {
	$slugs  = array_column( recipes(), 'slug' );
	$recipe = [
		'type'        => 'string',
		'description' => 'A recipe\'s slug, such as home: that recipe in full, each section with copy_guidance, a line per copy label on what it says on this page, and copy_rules, its pattern\'s rule per copy label: purpose, min and max characters, and whether it is required or a claim. A claim\'s line says where its fact comes from, and that is never you. compose takes its sections as they are.',
	];

	// JSON Schema refuses an empty enum, so with no recipe to read it is left out.
	if ( [] !== $slugs ) {
		$recipe['enum'] = $slugs;
	}

	wp_register_ability(
		CATEGORY . '/get-catalog',
		[
			'label'               => __( 'Get the Voyager Open Blocks catalog', 'voyager-open-blocks' ),
			'description'         => __( 'Lists what the pack offers on this site. Call it first. With no other input it answers in brief. styles: the section styles a core/group takes (Panel, Inverted and Accent; Plain is none), each with its class. patterns: each pattern\'s name, description, section and layout. sources: the binding fields for post_type, each with the exact args bind takes, and on Site Data filled, whether it has a value now. recipes: one per page type, each a slug, title and sections, top to bottom, each a pattern, layout, style and on some omit, as compose takes them. For more, pass recipe, patterns or include: the answer then holds only what they name.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => [],
				'properties'           => [
					'post_type' => [
						'type'        => 'string',
						'default'     => 'page',
						'description' => 'The post type whose fields to list. For a template, the type it shows; pass the same to bind.',
					],
					'recipe'    => $recipe,
					'patterns'  => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => array_keys( Patterns\generated() ),
						],
						'description' => 'Pattern names, such as voyager-open/hero-cover: each in full, with its title, categories, copy_rules and named blocks, their bindings, and copy: true on each whose text insert and compose take.',
					],
					'include'   => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => CATALOG_KEYS,
						],
						'description' => 'Lists to give whole and in full. blocks: the pack\'s blocks, each with its attribute schema and bindable attributes. used_on: for each Site Data and bindable post meta field, keyed "<source>:<key>", how many published posts, templates and template parts show it; changing the value changes every one. patterns, recipes, sources and styles: in full. All six is the whole catalog.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'styles'   => [ 'type' => 'object' ],
					'blocks'   => [ 'type' => 'array' ],
					'patterns' => [ 'type' => 'array' ],
					'sources'  => [ 'type' => 'array' ],
					'used_on'  => [ 'type' => 'object' ],
					'recipes'  => [ 'type' => 'array' ],
					'recipe'   => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\get_catalog',
			'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			'meta'                => meta( true, true ),
		]
	);
}

/**
 * Execute get-catalog.
 *
 * @param array<string, mixed>|null $input Input.
 * @return array<string, mixed>|WP_Error
 */
function get_catalog( $input = null ) {
	return catalog_answer( is_array( $input ) ? $input : [], catalog_builders() );
}

/**
 * What builds each part of the answer.
 *
 * catalog_answer() keeps what styles, blocks, patterns, brief_patterns,
 * recipes, brief_recipes and recipe build between calls. It calls sources,
 * filled and used_on on every call: whether a Site Data field holds a value,
 * and how many posts show it, change with the content, and the sources read
 * the post meta and binding sources other plugins register in code, which a
 * file deploy changes with no hook. The tests pass their own builders, to
 * count the calls.
 *
 * @return array{styles: callable(): array<mixed>, blocks: callable(): array<mixed>, patterns: callable(): array<mixed>, brief_patterns: callable(): array<mixed>, sources: callable(string): array<mixed>, recipes: callable(): array<mixed>, brief_recipes: callable(): array<mixed>, recipe: callable(string): (array<mixed>|null), filled: callable(array<mixed>): array<mixed>, used_on: callable(array<mixed>): array<mixed>}
 */
function catalog_builders(): array {
	return [
		'styles'         => static fn (): array => SectionStyles\catalog(),
		'blocks'         => static fn (): array => Catalog\blocks(),
		'patterns'       => static fn (): array => Catalog\patterns(),
		'brief_patterns' => static fn (): array => brief_patterns( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() ),
		'sources'        => static fn ( string $post_type ): array => Catalog\sources( $post_type ),
		'recipes'        => static fn (): array => recipe_list(),
		'brief_recipes'  => static fn (): array => brief_recipes( recipes() ),
		'recipe'         => static fn ( string $slug ): ?array => recipe_detail( $slug ),
		'filled'         => static fn ( array $sources ): array => with_filled( $sources ),
		'used_on'        => static fn ( array $sources ): array => Usage\used_on( $sources ),
	];
}

/**
 * get-catalog's answer for an input.
 *
 * The parts that change only when a trigger fires (CATALOG_TRIGGERS) are kept
 * in a transient for the request's locale, under catalog_version(), and a
 * call builds only the parts it needs and the transient does not hold. A
 * recipe is kept only when its slug names one, so what an input can add to
 * the transient is bounded. sources, filled and used_on are built on every
 * call that answers with them, the sources once.
 *
 * @param array<mixed> $input Input.
 * @param array{styles: callable(): array<mixed>, blocks: callable(): array<mixed>, patterns: callable(): array<mixed>, brief_patterns: callable(): array<mixed>, sources: callable(string): array<mixed>, recipes: callable(): array<mixed>, brief_recipes: callable(): array<mixed>, recipe: callable(string): (array<mixed>|null), filled: callable(array<mixed>): array<mixed>, used_on: callable(array<mixed>): array<mixed>} $build From catalog_builders().
 * @return array<string, mixed>|WP_Error
 */
function catalog_answer( array $input, array $build ) {
	$post_type = ! empty( $input['post_type'] ) ? Cast\to_string( $input['post_type'] ) : 'page';
	$include   = catalog_list( $input['include'] ?? null );
	$names     = catalog_list( $input['patterns'] ?? null );
	$slug      = Cast\to_string( $input['recipe'] ?? '' );

	$name    = catalog_transient();
	$version = catalog_version();
	$parts   = cached_parts( $name, $version );
	$built   = false;

	/**
	 * A part from the transient, or built and kept.
	 *
	 * @param callable(): mixed $make
	 */
	$part = static function ( string $key, callable $make ) use ( &$parts, &$built ): mixed {
		if ( ! array_key_exists( $key, $parts ) ) {
			$parts[ $key ] = $make();
			$built         = true;
		}

		return $parts[ $key ];
	};

	/** @var array<mixed>|null $read */
	$read    = null;
	$sources = static function () use ( &$read, $build, $post_type ): array {
		$read ??= $build['sources']( $post_type );

		return $read;
	};

	if ( [] === $include && [] === $names && '' === $slug ) {
		$out = [
			'styles'   => $part( 'styles', $build['styles'] ),
			'patterns' => $part( 'brief_patterns', $build['brief_patterns'] ),
			'sources'  => $build['filled']( $sources() ),
			'recipes'  => $part( 'brief_recipes', $build['brief_recipes'] ),
		];
	} else {
		$out = [];

		foreach ( CATALOG_KEYS as $key ) {
			$all = in_array( $key, $include, true );

			if ( ! $all && ( 'patterns' !== $key || [] === $names ) ) {
				continue;
			}

			$out[ $key ] = match ( $key ) {
				'styles'   => $part( 'styles', $build['styles'] ),
				'blocks'   => $part( 'blocks', $build['blocks'] ),
				'patterns' => named_patterns( $part( 'patterns', $build['patterns'] ), $all ? null : $names ),
				'sources'  => $build['filled']( $sources() ),
				'used_on'  => $build['used_on']( $sources() ),
				default    => $part( 'recipes', $build['recipes'] ),
			};
		}

		if ( '' !== $slug ) {
			$key    = 'recipe:' . $slug;
			$recipe = $parts[ $key ] ?? $build['recipe']( $slug );

			if ( null === $recipe ) {
				$brief = $part( 'brief_recipes', $build['brief_recipes'] );
				$slugs = array_filter( array_column( is_array( $brief ) ? array_filter( $brief, 'is_array' ) : [], 'slug' ), 'is_string' );
				$out   = new WP_Error(
					'voyager_open_blocks_invalid_recipe',
					sprintf( 'No recipe "%1$s". get-catalog with no input lists the recipes: %2$s.', $slug, [] === $slugs ? 'none on this site' : implode( ', ', $slugs ) ),
					[ 'status' => 400 ]
				);
			} else {
				if ( ! array_key_exists( $key, $parts ) ) {
					$parts[ $key ] = $recipe;
					$built         = true;
				}

				$out['recipe'] = $recipe;
			}
		}
	}

	if ( $built ) {
		set_transient(
			$name,
			[
				'version' => $version,
				'parts'   => $parts,
			],
			CATALOG_TTL
		);
	}

	return $out;
}

/**
 * The patterns get-catalog's patterns input names, from the whole list, in
 * its order: what Catalog\patterns() gives for those names.
 *
 * @param mixed             $patterns Every pattern, from Catalog\patterns().
 * @param list<string>|null $names    The names, or null for every pattern.
 * @return list<mixed>
 */
function named_patterns( mixed $patterns, ?array $names ): array {
	$patterns = is_array( $patterns ) ? array_values( $patterns ) : [];

	if ( null === $names ) {
		return $patterns;
	}

	return array_values( array_filter( $patterns, static fn ( mixed $row ): bool => is_array( $row ) && in_array( $row['name'] ?? null, $names, true ) ) );
}

/**
 * The transient that holds the kept parts for this request's locale, since
 * the labels, titles and descriptions in them are translated.
 */
function catalog_transient(): string {
	return CATALOG_CACHE . (string) preg_replace( '/[^A-Za-z0-9_]/', '', determine_locale() );
}

/**
 * The kept parts, when the transient holds them under this version, or none.
 *
 * @param string $name    Transient name, from catalog_transient().
 * @param string $version From catalog_version().
 * @return array<mixed>
 */
function cached_parts( string $name, string $version ): array {
	$kept = get_transient( $name );

	if ( ! is_array( $kept ) || $version !== ( $kept['version'] ?? null ) || ! is_array( $kept['parts'] ?? null ) ) {
		return [];
	}

	return $kept['parts'];
}

/**
 * The version the kept parts are stored under: the pack's Version, how many
 * triggers have fired, the active theme, the pack's data files
 * (pack_stamp()) and the theme's theme.json files (theme_stamp()). A part
 * kept under any other version is built again.
 */
function catalog_version(): string {
	return implode(
		'|',
		[
			VOYAGER_OPEN_BLOCKS_VERSION,
			(string) trigger_count(),
			get_stylesheet(),
			pack_stamp(),
			theme_stamp(),
		]
	);
}

/**
 * How many triggers have fired.
 *
 * On a multisite network the count is the network's, since a plugin is
 * activated network-wide and upgraded once for every site, from the network
 * admin. A trigger on one site then builds every site's parts again, which
 * costs one build each. On a single site it is an autoloaded option, so
 * reading it costs no query.
 */
function trigger_count(): int {
	return Cast\to_int( is_multisite() ? get_site_option( CATALOG_VERSION, 0 ) : get_option( CATALOG_VERSION, 0 ) );
}

/**
 * When the files the kept parts are read from last changed: the generated
 * patterns array, the blocks manifest and each recipe.
 *
 * A release changes the Version as well, but a checkout changes these files
 * with no new Version: after `npm run patterns:build`, `npm run build` or an
 * edit to a recipe, the next call builds again.
 *
 * @param string|null $dir Plugin directory. Defaults to this plugin's.
 */
function pack_stamp( ?string $dir = null ): string {
	$dir = $dir ?? VOYAGER_OPEN_BLOCKS_DIR;

	return files_stamp( [ $dir . '/inc/generated/patterns.php', $dir . '/build/blocks-manifest.php', ...( glob( $dir . '/recipes/*.json' ) ?: [] ) ] );
}

/**
 * When the active theme's theme.json files last changed: the child's and the
 * parent's, the two files the styles part reads (SectionStyles\theme_json_files()).
 *
 * A theme is shipped by a deploy that writes its files and fires no hook, so
 * a new palette in theme.json shows on the next call only because its mtime
 * is here. A style variation's file in styles/ is not read: choosing one
 * saves its palette in the Global Styles post, which save_post_wp_global_styles
 * covers (SectionStyles\active_palette()).
 */
function theme_stamp(): string {
	return files_stamp( array_unique( [ get_stylesheet_directory() . '/theme.json', get_template_directory() . '/theme.json' ] ) );
}

/**
 * A hash of each file's path and mtime, 0 for a file that is not there.
 *
 * @param array<string> $files Paths.
 */
function files_stamp( array $files ): string {
	$stamp = [];

	foreach ( $files as $file ) {
		$stamp[] = $file . ':' . ( is_file( $file ) ? (int) filemtime( $file ) : 0 );
	}

	return md5( implode( ',', $stamp ) );
}

/**
 * Add one to the count of triggers (trigger_count()), so the next call builds
 * every part again.
 */
function bump_catalog(): void {
	if ( is_multisite() ) {
		update_site_option( CATALOG_VERSION, trigger_count() + 1 );
		return;
	}

	update_option( CATALOG_VERSION, trigger_count() + 1, true );
}

/**
 * Hook bump_catalog() to each of CATALOG_TRIGGERS.
 */
function catalog_triggers(): void {
	foreach ( CATALOG_TRIGGERS as $hook ) {
		add_action( $hook, __NAMESPACE__ . '\bump_catalog', 10, 0 );
	}
}
catalog_triggers();

/**
 * A list input, each string once, in the order given.
 *
 * Over MCP or a JSON body it is an array. A query string can carry it as
 * `input[include][0]=used_on`, which PHP reads as an array, or as
 * `input[include]=blocks,used_on`, a string the schema accepts as a list and
 * the REST run controller of WordPress 6.9 and 7.0 passes on as it is
 * (wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php),
 * so a string is split on commas and spaces here, as wp_parse_list() splits it.
 *
 * @param mixed $value Input.
 * @return list<string>
 */
function catalog_list( mixed $value ): array {
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
	}

	$out = [];

	foreach ( is_array( $value ) ? $value : [] as $item ) {
		if ( is_string( $item ) && '' !== $item && ! in_array( $item, $out, true ) ) {
			$out[] = $item;
		}
	}

	return $out;
}

/**
 * Each pack pattern in brief: its name, description, section and layout, as
 * its whole row (Catalog\patterns()) gives them, in the registry's order.
 * Enough to pick a pattern for a section without its blocks and copy rules,
 * which recipe and patterns give.
 *
 * @param array<mixed> $registered Patterns as WP_Block_Patterns_Registry::get_all_registered() lists them.
 * @return list<array{name: string, description: string, section: string|null, layout: string|null}>
 */
function brief_patterns( array $registered ): array {
	$out = [];

	foreach ( $registered as $pattern ) {
		$pattern = is_array( $pattern ) ? $pattern : [];
		$name    = Cast\to_string( $pattern['name'] ?? '' );

		if ( ! str_starts_with( $name, Catalog\PREFIX ) ) {
			continue;
		}

		$intent = Patterns\intent_meta( $name );

		$out[] = [
			'name'        => $name,
			'description' => Cast\to_string( $pattern['description'] ?? '' ),
			'section'     => $intent['section'],
			'layout'      => $intent['layout'],
		];
	}

	return $out;
}

/**
 * Each recipe in brief: its slug, title and sections, each section as compose
 * takes it, its pattern, layout, style and any omit, less its copy_guidance.
 *
 * @param list<array{slug: string, title: string, description: string, page_type: string, sections: list<array{pattern: string, layout: string|null, style: string, copy_guidance: array<string, string>, omit?: list<string>}>}> $recipes From recipes().
 * @return list<array{slug: string, title: string, sections: list<array{pattern: string, layout: string|null, style: string, omit?: list<string>}>}>
 */
function brief_recipes( array $recipes ): array {
	$out = [];

	foreach ( $recipes as $recipe ) {
		$sections = [];

		foreach ( $recipe['sections'] as $section ) {
			$brief = [
				'pattern' => $section['pattern'],
				'layout'  => $section['layout'],
				'style'   => $section['style'],
			];

			if ( isset( $section['omit'] ) ) {
				$brief['omit'] = $section['omit'];
			}

			$sections[] = $brief;
		}

		$out[] = [
			'slug'     => $recipe['slug'],
			'title'    => $recipe['title'],
			'sections' => $sections,
		];
	}

	return $out;
}

/**
 * The sources, each Site Data field with filled: whether it holds a value
 * now, read as the pack's binding reads it (SiteData\get_binding_value(),
 * which compose's empty reads too).
 *
 * Only when this plugin owns Site Data. Another owner's values are its own,
 * so its fields carry no filled.
 *
 * @param array<mixed> $sources From Catalog\sources().
 * @return array<mixed>
 */
function with_filled( array $sources ): array {
	if ( ! SiteData\owned() ) {
		return $sources;
	}

	foreach ( $sources as $index => $source ) {
		if ( ! is_array( $source ) || SiteData\SOURCE !== ( $source['name'] ?? null ) || ! is_array( $source['fields'] ?? null ) ) {
			continue;
		}

		$fields = [];
		foreach ( $source['fields'] as $field ) {
			$field = is_array( $field ) ? $field : [];

			$field['filled'] = null !== SiteData\get_binding_value( assoc( $field['args'] ?? null ), null, 'content' );
			$fields[]        = $field;
		}

		$source['fields']  = $fields;
		$sources[ $index ] = $source;
	}

	return $sources;
}
