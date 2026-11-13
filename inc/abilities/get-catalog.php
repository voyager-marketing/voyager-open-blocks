<?php
/**
 * The get-catalog ability: the blocks, patterns and binding fields the pack offers.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\SectionStyles;
use VoyagerOpenBlocks\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register get-catalog.
 */
function register_get_catalog(): void {
	wp_register_ability(
		CATEGORY . '/get-catalog',
		[
			'label'               => __( 'Get the Voyager Open Blocks catalog', 'voyager-open-blocks' ),
			'description'         => __( 'Lists the pack\'s blocks (attribute schema, bindable attributes), patterns (named blocks, their bindings, and copy: true on those insert can rewrite; section and layout, null for a section with one layout; and copy_rules, each copy label\'s purpose, min and max characters, and whether it is required or a claim the site owner must supply) and the binding fields available for a post type, each with the exact args to bind it. used_on counts, for each Site Data and bindable post meta field, the published posts, templates and template parts that show it, keyed "<source>:<key>". styles lists the section styles a core/group can take (Panel, Inverted and Accent; Plain is none), each with the class configure\'s style input sets, and which role map the theme\'s colours come from. recipes lists the page recipes, one per page type: each a whole page as data, with its slug, title, description, page_type and sections, top to bottom, each a section and layout (as patterns lists them), the style to put on it and copy_guidance, a line for each copy label on what it says on this page. Pass a recipe\'s slug to compose as recipe for a draft with each pattern\'s own text, or fill each section\'s copy from its copy_guidance, within the pattern\'s copy_rules, and pass the sections to compose as sections.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => [],
				'properties'           => [
					'post_type' => [
						'type'        => 'string',
						'default'     => 'page',
						'description' => 'Post type to list post meta fields for.',
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
 * @return array<string, mixed>
 */
function get_catalog( $input = null ): array {
	$post_type = is_array( $input ) && ! empty( $input['post_type'] ) ? Cast\to_string( $input['post_type'] ) : 'page';
	$sources   = Catalog\sources( $post_type );

	return [
		'styles'   => SectionStyles\catalog(),
		'blocks'   => Catalog\blocks(),
		'patterns' => Catalog\patterns(),
		'sources'  => $sources,
		'used_on'  => Usage\used_on( $sources ),
		'recipes'  => recipe_list(),
	];
}
