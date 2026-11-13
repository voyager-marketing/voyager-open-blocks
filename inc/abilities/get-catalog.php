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
			'description'         => __( 'Lists what the pack offers on this site. Call it first. blocks: the pack\'s blocks, with their attribute schema and bindable attributes. patterns: each pattern\'s named blocks and bindings, copy: true on the blocks whose text insert and compose take, its section and layout, and copy_rules, each copy label\'s purpose, min and max characters, and whether it is required or a claim. sources: the binding fields for post_type, each with the exact args bind takes. used_on: for each Site Data and bindable post meta field, keyed "<source>:<key>", how many published posts, templates and template parts show it; changing the value changes every one. styles: the section styles a core/group takes (Panel, Inverted and Accent; Plain is none), each with its class. recipes: one per page type, each with its slug, page_type and sections, top to bottom, each a section, layout, style and copy_guidance, a line per copy label on what it says on this page, and on some omit, the blocks the page does without, such as the contact page\'s hero button. A claim\'s line says where its fact comes from, and that is never you.', 'voyager-open-blocks' ),
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
