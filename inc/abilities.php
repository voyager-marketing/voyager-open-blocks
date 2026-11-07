<?php
/**
 * Abilities for the pack: read the catalog, find a post, read its blocks,
 * insert, configure and bind, and validate markup without saving it.
 *
 * Semantic abilities with an `item` or `path` parameter, not one per block.
 * Each callback is a thin adapter over inc/block-tree.php and inc/catalog.php.
 * All are visible over REST (wp-abilities/v1) and to the WordPress MCP adapter.
 *
 * What "validated" means here: WordPress checks every block is registered,
 * every comment attribute it has a schema for is valid, and the markup
 * survives a parse and serialize round trip. bind and validate also check a
 * binding's attribute, source and args. Saved HTML is checked by
 * `block-runner validate`, which runs the blocks' JavaScript save(). Pattern
 * markup is validated that way when it is built (npm run check). An agent
 * with Node should pipe the returned `markup` through it too; one without
 * has the PHP checks as its floor.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CATEGORY = 'voyager-open-blocks';

/**
 * What every validation report says about saved HTML.
 */
const SAVE_OUTPUT = 'Not checked: comparing saved HTML with each block\'s save() needs the block\'s JavaScript, which WordPress cannot run in PHP. With Node, run: npx block-runner validate <file> --strict --json --context site.context.json';

/**
 * The most posts find-posts returns at once.
 */
const FIND_LIMIT = 20;

/**
 * Statuses find-posts searches: everything an editor works on, not the trash.
 */
const FIND_STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

/**
 * Post types that are block templates, found through get_block_templates().
 */
const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];

/**
 * Register the category.
 */
function register_category(): void {
	wp_register_ability_category(
		CATEGORY,
		[
			'label'       => __( 'Voyager Open Blocks', 'voyager-open-blocks' ),
			'description' => __( 'Place, configure and bind the blocks and patterns of the Voyager Open Blocks pack.', 'voyager-open-blocks' ),
		]
	);
}
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_category' );

/**
 * Shared meta for every ability.
 *
 * @param bool $readonly   Does not change the site.
 * @param bool $idempotent Repeating the call with the same input changes nothing more.
 * @return array<string, mixed>
 */
function meta( bool $readonly, bool $idempotent ): array {
	return [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'readonly'    => $readonly,
			// Nothing here deletes content. configure and bind overwrite one
			// attribute or binding, which is an update, not a destructive act.
			'destructive' => false,
			'idempotent'  => $idempotent,
		],
	];
}

/**
 * Permission: the user may edit the post named in the input.
 *
 * @param array<string, mixed>|null $input Ability input.
 */
function can_edit_post( $input = null ): bool {
	$post_id = is_array( $input ) && isset( $input['post_id'] ) ? Cast\to_int( $input['post_id'] ) : 0;

	return $post_id > 0 && current_user_can( 'edit_post', $post_id );
}

/**
 * Permission for validate: edit the post when it checks a saved post, else edit posts.
 *
 * @param array<string, mixed>|null $input Ability input.
 */
function can_validate( $input = null ): bool {
	if ( is_array( $input ) && isset( $input['post_id'] ) ) {
		return can_edit_post( $input );
	}

	return current_user_can( 'edit_posts' );
}

/**
 * A value as an array with string keys, [] when it is not an array.
 *
 * @param mixed $value Value.
 * @return array<string, mixed>
 */
function assoc( mixed $value ): array {
	$out = [];

	foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
		$out[ (string) $key ] = $item;
	}

	return $out;
}

/**
 * Names `insert` accepts: the pack's blocks and patterns.
 *
 * @return string[]
 */
function items(): array {
	$names = array_merge(
		array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ),
		array_column( \WP_Block_Patterns_Registry::get_instance()->get_all_registered(), 'name' )
	);

	$items = [];

	foreach ( $names as $name ) {
		if ( is_string( $name ) && str_starts_with( $name, Catalog\PREFIX ) ) {
			$items[] = $name;
		}
	}

	return $items;
}

/**
 * Register the abilities.
 */
function register(): void {
	$post_id_schema = [
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => 'ID of the post or page to change.',
	];
	$path_schema    = [
		'type'        => 'string',
		'pattern'     => '^\d+(/\d+)*$',
		'description' => 'Block path from get-post-blocks or insert, such as "2/0/1".',
	];

	wp_register_ability(
		CATEGORY . '/get-catalog',
		[
			'label'               => __( 'Get the Voyager Open Blocks catalog', 'voyager-open-blocks' ),
			'description'         => __( 'Lists the pack\'s blocks (attribute schema, bindable attributes), patterns (named blocks, their bindings, and copy: true on those insert can rewrite) and the binding fields available for a post type, each with the exact args to bind it.', 'voyager-open-blocks' ),
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
					'blocks'   => [ 'type' => 'array' ],
					'patterns' => [ 'type' => 'array' ],
					'sources'  => [ 'type' => 'array' ],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\get_catalog',
			'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			'meta'                => meta( true, true ),
		]
	);

	$text_schema = [ 'type' => 'string' ];

	wp_register_ability(
		CATEGORY . '/find-posts',
		[
			'label'               => __( 'Find posts and pages', 'voyager-open-blocks' ),
			'description'         => __( 'Finds the pages, posts, templates and template parts you can edit, by words in the title, by slug, or the home page, and returns each one\'s ID for the other abilities. It lists only what the current user can edit, at most 20 at a time, and returns no content.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => [],
				'properties'           => [
					'search'     => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Words in the title, such as "Services". For templates and template parts, words in the title or slug, such as "header".',
					],
					'slug'       => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'The exact slug, such as "services" or "header".',
					],
					'post_type'  => [
						'type'        => 'string',
						'default'     => 'page',
						'description' => 'What to search: page, post, another post type edited in the block editor, wp_template or wp_template_part.',
					],
					'front_page' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Return the home page and ignore the other filters. When the home page lists the latest posts (Settings > Reading), it returns the template that shows them.',
					],
					'limit'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => FIND_LIMIT,
						'default'     => 10,
						'description' => 'How many to return.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'posts'    => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'         => [
									'type'        => [ 'integer', 'null' ],
									'description' => 'The post_id the other abilities take. null for a template or template part that is still only a theme file, never saved in the Site Editor.',
								],
								'title'      => $text_schema,
								'slug'       => $text_schema,
								'post_type'  => $text_schema,
								'status'     => $text_schema,
								'link'       => [ 'type' => [ 'string', 'null' ] ],
								'front_page' => [
									'type'        => 'boolean',
									'description' => 'This is the home page.',
								],
								'area'       => [
									'type'        => 'string',
									'description' => 'Template parts only: header, footer or uncategorized.',
								],
								'source'     => [
									'type'        => 'string',
									'description' => 'Templates and template parts only: theme (the theme\'s file) or custom (saved in the Site Editor).',
								],
								'note'       => $text_schema,
							],
						],
					],
					'has_more' => [
						'type'        => 'boolean',
						'description' => 'More matched than were returned. Narrow the search.',
					],
					'note'     => $text_schema,
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\find_posts',
			'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			'meta'                => meta( true, true ),
		]
	);

	wp_register_ability(
		CATEGORY . '/get-post-blocks',
		[
			'label'               => __( 'Get a post\'s blocks', 'voyager-open-blocks' ),
			'description'         => __( 'Returns every block in a post with its path, block name, label (metadata.name), the start of its own text and its bindings. Each binding says whether its source returns a value now (resolution.status "resolved") or the block falls back to its authored text ("fell_back", with a reason). Paths are what insert (as parent), configure and bind take.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [ 'post_id' => $post_id_schema ],
				'required'             => [ 'post_id' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
					'blocks'  => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'path'     => [
									'type'        => 'string',
									'description' => 'Block path, such as "2/0/1".',
								],
								'block'    => [
									'type'        => 'string',
									'description' => 'Block name.',
								],
								'label'    => [
									'type'        => 'string',
									'description' => 'metadata.name, when the block has one.',
								],
								'text'     => [
									'type'        => 'string',
									'description' => 'The first 80 characters of the block\'s own text, without its inner blocks\' text, such as a button\'s label. Absent when it has none.',
								],
								'pattern'  => [
									'type'        => 'string',
									'description' => 'Slug of the pattern a core/pattern reference points at.',
								],
								'bindings' => [
									'type'                 => 'object',
									'description'          => 'Declared bindings by attribute.',
									'additionalProperties' => [
										'type'       => 'object',
										'properties' => [
											'source'     => [ 'type' => 'string' ],
											'args'       => [ 'type' => [ 'object', 'array' ] ],
											'resolution' => [
												'type'       => 'object',
												'properties' => [
													'status' => [
														'type' => 'string',
														'enum' => [ 'resolved', 'fell_back' ],
													],
													'reason' => [
														'type' => [ 'string', 'null' ],
														'enum' => [ null, 'source_unregistered', 'unknown_key', 'empty_value' ],
														'description' => 'Why it fell back; null when resolved.',
													],
													'value_preview' => [
														'type'        => [ 'string', 'null' ],
														'description' => 'First 80 characters of the resolved value; null when it fell back.',
													],
												],
											],
										],
									],
								],
							],
						],
					],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\get_post_blocks',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( true, true ),
		]
	);

	wp_register_ability(
		CATEGORY . '/insert',
		[
			'label'               => __( 'Insert a Voyager Open block or pattern', 'voyager-open-blocks' ),
			'description'         => __( 'Places a pack pattern (as a copy, or as a reference that tracks plugin updates) or the icon block into a post, at the top level or inside a block given as parent, and returns the inserted block markup with the paths of its blocks. Inside a parent, the parent\'s allowedBlocks and lock and each new block\'s parent and ancestor rules from block.json apply. A pattern inserted as a copy takes copy, plain text for its named headings, paragraphs, buttons and list items; omit, the labels of blocks to leave out; and repeat, new labels for copies of a named block.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'    => $post_id_schema,
					'item'       => [
						'type'        => 'string',
						'enum'        => items(),
						'description' => 'Block or pattern name from get-catalog.',
					],
					'parent'     => [
						'type'        => 'string',
						'pattern'     => '^\d+(/\d+)*$',
						'description' => 'Path of the block to insert into, from get-post-blocks, such as "0" for the row that holds a header\'s buttons. Leave it out to insert at the top level. The block must already hold at least one block, must allow the new one (allowedBlocks) and must not be locked (templateLock).',
					],
					'position'   => [
						'type'        => 'integer',
						'minimum'     => -1,
						'default'     => -1,
						'description' => 'Index to insert before: among the top-level blocks, or among parent\'s inner blocks when parent is given. -1 appends.',
					],
					'mode'       => [
						'type'        => 'string',
						'enum'        => [ 'copy', 'reference' ],
						'default'     => 'copy',
						'description' => 'Patterns only. copy inlines the blocks so they can be edited; reference inserts core/pattern, which renders the current pattern.',
					],
					'attributes' => [
						'type'        => 'object',
						'default'     => [],
						'description' => 'Blocks only. Attributes, validated against the block\'s block.json.',
					],
					'copy'       => [
						'type'                 => 'object',
						'default'              => [],
						'additionalProperties' => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'description'          => 'Patterns in copy mode only. Plain text by label (a block\'s metadata.name, listed by get-catalog with copy: true), such as {"Heading": "...", "Body": "..."}. Replaces the text of headings, paragraphs, button labels and list items, escaped. Bindings stay: on a bound block the text becomes its fallback.',
					],
					'omit'       => [
						'type'        => 'array',
						'default'     => [],
						'items'       => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'description' => 'Patterns in copy mode only. Labels of blocks to leave out, with everything inside them, such as ["Credential 4"] for a proof strip with three facts or ["Hours"] in the contact section. Not the label of the whole pattern.',
					],
					'repeat'     => [
						'type'                 => 'object',
						'default'              => [],
						'additionalProperties' => [
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 10,
							'items'    => [
								'type'      => 'string',
								'minLength' => 1,
							],
						],
						'description'          => 'Patterns in copy mode only. Adds copies of a named block right after it, one per new label, such as {"Credential 4": ["Credential 5", "Credential 6"]} for a proof strip with six facts. Fill the new labels with copy. Runs before omit and copy. Not for the whole pattern, a block that holds named blocks, or a label used twice.',
					],
					'dry_run'    => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Return the markup without saving the post.',
					],
				],
				'required'             => [ 'post_id', 'item' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'post_id'        => [ 'type' => 'integer' ],
					'item'           => [ 'type' => 'string' ],
					'parent'         => [
						'type'        => 'string',
						'description' => 'Path of the block the new blocks went into. Only when parent was given.',
					],
					'index'          => [ 'type' => 'integer' ],
					'saved'          => [ 'type' => 'boolean' ],
					'markup'         => [ 'type' => 'string' ],
					'section_markup' => [
						'type'        => 'string',
						'description' => 'The whole top-level block that now holds the new blocks. Only when parent was given. Check this one with block-runner validate.',
					],
					'blocks'         => [ 'type' => 'array' ],
					'validation'     => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\insert',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( false, false ),
		]
	);

	wp_register_ability(
		CATEGORY . '/configure',
		[
			'label'               => __( 'Configure a Voyager Open block', 'voyager-open-blocks' ),
			'description'         => __( 'Sets attributes on a block in a post, validated against its block.json. Only blocks that save no HTML, so every attribute lives in the block comment (such as voyager-open/icon). A null value resets an attribute to its default.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'    => $post_id_schema,
					'path'       => $path_schema,
					'attributes' => [
						'type'        => 'object',
						'description' => 'Attributes to set.',
					],
				],
				'required'             => [ 'post_id', 'path', 'attributes' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'execute_callback'    => __NAMESPACE__ . '\configure',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( false, true ),
		]
	);

	wp_register_ability(
		CATEGORY . '/bind',
		[
			'label'               => __( 'Bind a block attribute to a field', 'voyager-open-blocks' ),
			'description'         => __( 'Writes a block binding: the attribute must be bindable for that block, and args must match a field listed by get-catalog (or the Wesper manifest) exactly. Args are never built for you. In a template, pass post_type: the post type it shows.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'   => $post_id_schema,
					'path'      => $path_schema,
					'attribute' => [
						'type'        => 'string',
						'description' => 'Bindable attribute, such as content, text, url, label or linkUrl.',
					],
					'source'    => [
						'type' => 'string',
						'enum' => Catalog\SOURCES,
					],
					'args'      => [
						'type'        => 'object',
						'description' => 'A field\'s args, copied verbatim, such as {"key":"phone"}.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'Post type whose fields args are checked against. Defaults to the post\'s own type. In a template, template part or synced pattern, or inside a Query Loop, pass the post type the block shows, such as "post" for the single template, and copy args from get-catalog for that type.',
					],
				],
				'required'             => [ 'post_id', 'path', 'attribute', 'source', 'args' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'execute_callback'    => __NAMESPACE__ . '\bind',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( false, true ),
		]
	);

	$messages = [
		'type'  => 'array',
		'items' => [ 'type' => 'string' ],
	];

	wp_register_ability(
		CATEGORY . '/validate',
		[
			'label'               => __( 'Validate block markup', 'voyager-open-blocks' ),
			'description'         => __( 'Checks block markup, or a saved post, and changes nothing. For every block it reports, by path: the block type is registered, its comment attributes match block.json, each binding to voyager/site-data, voyager/post-meta-text or core/post-meta names a bindable attribute and args that get-catalog lists, and the markup is what WordPress writes back after a parse and serialize round trip. It does not compare saved HTML with each block\'s save() output; block-runner validate does that.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => [],
				'properties'           => [
					'markup'    => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Block markup to check, such as a section you wrote or were given. Pass markup or post_id, not both.',
					],
					'post_id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'ID of a saved post or page to check instead of markup.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'Post type whose fields binding args are checked against. Defaults to the type of post_id, else page. For a template, pass the post type it shows.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'valid'       => [
						'type'        => 'boolean',
						'description' => 'True when no block has an error. Warnings do not change it.',
					],
					'post_id'     => [ 'type' => 'integer' ],
					'post_type'   => [
						'type'        => 'string',
						'description' => 'Post type the binding args were checked against.',
					],
					'round_trip'  => [
						'type'        => 'boolean',
						'description' => 'True when every block is written exactly as WordPress writes it back, apart from the whitespace between top-level blocks.',
					],
					'blocks'      => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'path'     => [
									'type'        => 'string',
									'description' => 'Block path, such as "2/0/1".',
								],
								'block'    => [
									'type'        => 'string',
									'description' => 'Block name. core/freeform is HTML outside any block.',
								],
								'label'    => [
									'type'        => 'string',
									'description' => 'metadata.name, when the block has one.',
								],
								'errors'   => $messages,
								'warnings' => $messages,
							],
						],
					],
					'errors'      => $messages,
					'warnings'    => $messages,
					'markup'      => [
						'type'        => 'string',
						'description' => 'The markup as WordPress would save it. Compare it with yours where round_trip failed.',
					],
					'save_output' => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\validate',
			'permission_callback' => __NAMESPACE__ . '\can_validate',
			'meta'                => meta( true, true ),
		]
	);
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );

/**
 * Execute get-catalog.
 *
 * @param array<string, mixed>|null $input Input.
 * @return array<string, mixed>
 */
function get_catalog( $input = null ): array {
	$post_type = is_array( $input ) && ! empty( $input['post_type'] ) ? Cast\to_string( $input['post_type'] ) : 'page';

	return [
		'blocks'   => Catalog\blocks(),
		'patterns' => Catalog\patterns(),
		'sources'  => Catalog\sources( $post_type ),
	];
}

/**
 * Execute get-post-blocks.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function get_post_blocks( array $input ) {
	$post_id = Cast\to_int( $input['post_id'] );
	$blocks  = BlockTree\load( $post_id );

	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$texts = BlockTree\texts( $blocks );
	$rows  = [];

	foreach ( BlockTree\describe( $blocks, '', $post_id ) as $row ) {
		$text   = $texts[ $row['path'] ] ?? '';
		$rows[] = '' === $text ? $row : $row + [ 'text' => $text ];
	}

	return [
		'post_id' => $post_id,
		'blocks'  => $rows,
	];
}

/**
 * Execute find-posts.
 *
 * Only posts the current user can both edit and read are returned. The query
 * already asks for editable posts only (perm "editable"), and each result is
 * checked again with edit_post and read_post, so a private post of another
 * author stays out unless the user may edit it. Nothing of the content is
 * returned: an ID, a title, a slug, a status and a link.
 *
 * @param array<string, mixed>|null $input Input.
 * @return array<string, mixed>|WP_Error
 */
function find_posts( $input = null ) {
	$input     = assoc( $input );
	$limit     = max( 1, min( FIND_LIMIT, Cast\to_int( $input['limit'] ?? 10, 10 ) ) );
	$search    = trim( Cast\to_string( $input['search'] ?? '' ) );
	$slug      = trim( Cast\to_string( $input['slug'] ?? '' ) );
	$post_type = Cast\to_string( $input['post_type'] ?? 'page', 'page' );

	if ( isset( $input['front_page'] ) && filter_var( $input['front_page'], FILTER_VALIDATE_BOOLEAN ) ) {
		return front_page();
	}

	$types = findable_post_types();
	if ( ! in_array( $post_type, $types, true ) ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_post_type',
			sprintf( 'Cannot search post type "%s". Searchable: %s.', $post_type, implode( ', ', $types ) ),
			[ 'status' => 400 ]
		);
	}

	if ( in_array( $post_type, TEMPLATE_TYPES, true ) ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return cannot_edit_type( $post_type );
		}

		$rows = [];
		foreach ( get_block_templates( '' === $slug ? [] : [ 'slug__in' => [ $slug ] ], $post_type ) as $template ) {
			if ( matches_words( Cast\to_string( $template->title ) . ' ' . Cast\to_string( $template->slug ), $search ) ) {
				$row = template_row( $template, $post_type );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
		}

		return [
			'posts'    => array_slice( $rows, 0, $limit ),
			'has_more' => count( $rows ) > $limit,
		];
	}

	$object = get_post_type_object( $post_type );
	$cap    = $object ? Cast\to_string( $object->cap->edit_posts ?? '' ) : '';
	if ( '' === $cap || ! current_user_can( $cap ) ) {
		return cannot_edit_type( $post_type );
	}

	$query = new \WP_Query( query_args( $post_type, $search, $slug, $limit ) );
	$rows  = [];

	foreach ( $query->posts ?? [] as $post ) {
		$row = post_row( $post instanceof \WP_Post ? $post->ID : Cast\to_int( $post ) );
		if ( null !== $row ) {
			$rows[] = $row;
		}
	}

	return [
		'posts'    => array_slice( $rows, 0, $limit ),
		'has_more' => count( $rows ) > $limit,
	];
}

/**
 * The error for a post type the user cannot edit.
 *
 * @param string $post_type Post type.
 */
function cannot_edit_type( string $post_type ): WP_Error {
	return new WP_Error( 'voyager_open_blocks_invalid_post_type', sprintf( 'You cannot edit %s posts.', $post_type ), [ 'status' => 403 ] );
}

/**
 * Post types find-posts searches: those shown in REST and edited in the block editor.
 *
 * @return string[]
 */
function findable_post_types(): array {
	$out = [];

	foreach ( get_post_types( [ 'show_in_rest' => true ] ) as $type ) {
		$name = Cast\to_string( $type );
		if ( '' !== $name && post_type_supports( $name, 'editor' ) ) {
			$out[] = $name;
		}
	}

	return $out;
}

/**
 * WP_Query arguments for find-posts.
 *
 * Asks for one more than the limit, so the caller can say there are more.
 * The search looks in titles only, and a slug must match exactly.
 *
 * @param string $post_type Post type.
 * @param string $search    Words in the title, '' for any.
 * @param string $slug      Exact slug, '' for any.
 * @param int    $limit     How many to return.
 * @return array<string, mixed>
 */
function query_args( string $post_type, string $search, string $slug, int $limit ): array {
	$args = [
		'post_type'              => $post_type,
		'post_status'            => FIND_STATUSES,
		'perm'                   => 'editable',
		'posts_per_page'         => $limit + 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'orderby'                => 'title',
		'order'                  => 'ASC',
	];

	if ( '' !== $search ) {
		// Ordered by relevance: an exact title first.
		$args['s']              = $search;
		$args['search_columns'] = [ 'post_title' ];
		unset( $args['orderby'], $args['order'] );
	}

	if ( '' !== $slug ) {
		$args['post_name__in'] = [ $slug ];
	}

	return $args;
}

/**
 * Can the current user both edit and read a post?
 *
 * @param int $post_id Post ID.
 */
function can_see( int $post_id ): bool {
	return $post_id > 0 && current_user_can( 'edit_post', $post_id ) && current_user_can( 'read_post', $post_id );
}

/**
 * Does every word of a search appear in a text, ignoring case?
 *
 * @param string $text   Text, such as a template's title and slug.
 * @param string $search Words, '' matches everything.
 */
function matches_words( string $text, string $search ): bool {
	foreach ( preg_split( '/\s+/', trim( $search ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $word ) {
		if ( false === mb_stripos( $text, $word ) ) {
			return false;
		}
	}

	return true;
}

/**
 * One find-posts row for a post, null when the user cannot edit and read it.
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed>|null
 */
function post_row( int $post_id ): ?array {
	$post = can_see( $post_id ) ? get_post( $post_id ) : null;

	if ( ! $post ) {
		return null;
	}

	return [
		'id'         => $post->ID,
		'title'      => $post->post_title,
		'slug'       => $post->post_name,
		'post_type'  => $post->post_type,
		'status'     => $post->post_status,
		'link'       => Cast\to_string( get_permalink( $post ) ),
		'front_page' => 'page' === get_option( 'show_on_front' ) && Cast\to_int( get_option( 'page_on_front' ) ) === $post->ID,
	];
}

/**
 * One find-posts row for a template or template part.
 *
 * A template the Site Editor never saved is only a theme file: it has no post
 * ID, so no ability can change it. Null when it has a post the user cannot edit.
 *
 * @param \WP_Block_Template $template  Template.
 * @param string             $post_type wp_template or wp_template_part.
 * @return array<string, mixed>|null
 */
function template_row( \WP_Block_Template $template, string $post_type ): ?array {
	$id = is_int( $template->wp_id ) && $template->wp_id > 0 ? $template->wp_id : null;

	if ( null !== $id && ! can_see( $id ) ) {
		return null;
	}

	$row = [
		'id'         => $id,
		'title'      => Cast\to_string( $template->title ),
		'slug'       => Cast\to_string( $template->slug ),
		'post_type'  => $post_type,
		'status'     => Cast\to_string( $template->status ),
		'link'       => null,
		'front_page' => false,
		'source'     => Cast\to_string( $template->source ),
	];

	if ( 'wp_template_part' === $post_type ) {
		$row['area'] = Cast\to_string( $template->area, 'uncategorized' );
	}

	if ( null === $id ) {
		$row['note'] = 'Only a theme file so far: it has no post ID until someone saves it in the Site Editor. Ask the user to open it in the Site Editor and save it once, then call find-posts again.';
	}

	return $row;
}

/**
 * find-posts for the home page.
 *
 * When Settings > Reading shows a page, that page. When it shows the latest
 * posts, the template that renders them: front-page, else home, else index.
 *
 * @return array<string, mixed>
 */
function front_page(): array {
	$page_id = Cast\to_int( get_option( 'page_on_front' ) );

	if ( 'page' === get_option( 'show_on_front' ) && $page_id > 0 ) {
		$row = post_row( $page_id );

		return null === $row
			? [
				'posts'    => [],
				'has_more' => false,
				'note'     => 'A page is set as the home page, but you cannot edit it.',
			]
			: [
				'posts'    => [ $row ],
				'has_more' => false,
			];
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return [
			'posts'    => [],
			'has_more' => false,
			'note'     => 'The home page lists the latest posts, so its sections are in a template, and you cannot edit templates.',
		];
	}

	foreach ( [ 'front-page', 'home', 'index' ] as $slug ) {
		foreach ( get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template' ) as $template ) {
			$row = template_row( $template, 'wp_template' );
			if ( null === $row ) {
				continue;
			}

			$row['front_page'] = true;

			return [
				'posts'    => [ $row ],
				'has_more' => false,
				'note'     => sprintf( 'The home page lists the latest posts (Settings > Reading), so its sections are in the %s template, not in a page.', $slug ),
			];
		}
	}

	return [
		'posts'    => [],
		'has_more' => false,
		'note'     => 'The home page lists the latest posts, and the theme has no block template for it. Its sections are in PHP theme files that no ability can change.',
	];
}

/**
 * Validate attributes for a block whose attributes all live in the comment.
 *
 * That holds only for a block that saves no HTML. A render callback is not
 * enough: core/heading has one, yet its saved <h2> must still match `level`,
 * so changing the comment alone would make the block invalid.
 *
 * @param string               $name       Block name.
 * @param array<string, mixed> $attributes Attributes; null resets one.
 * @param Block|null           $block      The existing block, or null when inserting a pack block.
 * @return array<string, mixed>|WP_Error The attributes to apply.
 */
function check_attributes( string $name, array $attributes, ?array $block = null ) {
	$type      = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$saves_any = null === $block
		? ! str_starts_with( $name, Catalog\PREFIX )
		: '' !== trim( $block['innerHTML'] ) || ! empty( $block['innerBlocks'] );

	if ( ! $type || ! $type->is_dynamic() || $saves_any ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_item',
			sprintf( '%s saves HTML, so its attributes cannot be changed without re-rendering that HTML. Re-author it with block-runner assemble.', $name ),
			[ 'status' => 400 ]
		);
	}

	foreach ( $attributes as $attribute => $value ) {
		if ( 'metadata' === $attribute ) {
			return new WP_Error( 'voyager_open_blocks_invalid_attributes', 'Set bindings with the bind ability, not through attributes.', [ 'status' => 400 ] );
		}

		$schema = $type->attributes[ $attribute ] ?? null;
		if ( ! is_array( $schema ) ) {
			return new WP_Error(
				'voyager_open_blocks_invalid_attributes',
				sprintf( '%s has no attribute "%s". Valid attributes: %s.', $name, $attribute, implode( ', ', array_keys( (array) $type->attributes ) ) ),
				[ 'status' => 400 ]
			);
		}

		if ( null === $value ) {
			continue;
		}

		$valid = rest_validate_value_from_schema( $value, array_intersect_key( $schema, array_flip( [ 'type', 'enum', 'items', 'properties' ] ) ), $attribute );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'voyager_open_blocks_invalid_attributes', $valid->get_error_message(), [ 'status' => 400 ] );
		}
	}

	return $attributes;
}

/**
 * Validation report for markup that is about to be written.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param string            $markup Their markup.
 * @return array{registered_and_attributes: bool, errors: string[], round_trip: bool, save_output: string}
 */
function validation( array $blocks, string $markup ): array {
	$check = BlockTree\check( $blocks );

	return [
		'registered_and_attributes' => $check['valid'],
		'errors'                    => $check['errors'],
		'round_trip'                => BlockTree\to_markup( BlockTree\parse( $markup ) ) === $markup,
		'save_output'               => SAVE_OUTPUT,
	];
}

/**
 * Execute insert.
 *
 * At the top level, or inside the block at `parent`. Either way the new blocks
 * must be allowed where they go (BlockTree\placement_errors()), pass the same
 * validation as before, and leave the section around them able to survive a
 * parse and serialize round trip.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function insert( array $input ) {
	$post_id    = Cast\to_int( $input['post_id'] );
	$item       = Cast\to_string( $input['item'] );
	$position   = isset( $input['position'] ) ? Cast\to_int( $input['position'] ) : -1;
	$mode       = isset( $input['mode'] ) ? Cast\to_string( $input['mode'] ) : 'copy';
	$attributes = assoc( $input['attributes'] ?? null );
	$copy       = array_map( static fn ( mixed $text ): string => Cast\to_string( $text ), assoc( $input['copy'] ?? null ) );
	$omit       = array_values( array_filter( array_map( static fn ( mixed $label ): string => Cast\to_string( $label ), (array) ( $input['omit'] ?? [] ) ) ) );
	$repeat     = repeat_input( $input['repeat'] ?? null );
	$dry_run    = ! empty( $input['dry_run'] );
	$parent     = null;

	if ( isset( $input['parent'] ) ) {
		$parent = BlockTree\parse_path( Cast\to_string( $input['parent'] ) );
		if ( is_wp_error( $parent ) ) {
			return invalid_parent( $parent->get_error_message() );
		}
	}

	$blocks = BlockTree\load( $post_id );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $item );

	foreach ( [
		'copy'   => [] !== $copy,
		'omit'   => [] !== $omit,
		'repeat' => [] !== $repeat,
	] as $field => $given ) {
		if ( $given && ( ! $pattern || 'reference' === $mode ) ) {
			return new WP_Error(
				'voyager_open_blocks_invalid_' . $field,
				sprintf( '%s works only on a pattern inserted with mode "copy". A reference renders the pattern as shipped.', $field ),
				[ 'status' => 400 ]
			);
		}
	}

	if ( $pattern ) {
		$new = 'reference' === $mode
			? [
				[
					'blockName'    => 'core/pattern',
					'attrs'        => [ 'slug' => $item ],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
			]
			: BlockTree\parse( Cast\to_string( $pattern['content'] ?? '' ) );

		// repeat first, so copy can fill the new labels and omit can drop the original.
		if ( $repeat ) {
			$repeated = BlockTree\repeat( $new, $repeat );
			if ( is_wp_error( $repeated ) ) {
				return $repeated;
			}
		}

		if ( $omit ) {
			$omitted = BlockTree\omit( $new, $omit );
			if ( is_wp_error( $omitted ) ) {
				return $omitted;
			}
		}

		if ( $copy ) {
			$copied = BlockTree\set_copy( $new, $copy );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}
	} else {
		$attributes = check_attributes( $item, $attributes );
		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}
		$new = [
			[
				'blockName'    => $item,
				'attrs'        => array_filter( $attributes, static fn ( $value ): bool => null !== $value ),
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
		];
	}

	$markup = BlockTree\to_markup( $new );
	$report = validation( $new, $markup );

	if ( ! $report['registered_and_attributes'] || ! $report['round_trip'] ) {
		return new WP_Error( 'voyager_open_blocks_invalid_item', 'The block markup did not pass validation: ' . implode( ' ', $report['errors'] ), [ 'status' => 400 ] );
	}

	$out = [];

	if ( null === $parent ) {
		$errors = BlockTree\placement_errors( $new, [] );
		if ( $errors ) {
			return invalid_parent( implode( ' ', $errors ) . ' Pass parent: the path of a block it can go in.' );
		}

		$prefix = '';
		$index  = ( $position < 0 || $position > count( $blocks ) ) ? count( $blocks ) : $position;
		array_splice( $blocks, $index, 0, $new );
	} else {
		$index = place_inside( $blocks, $parent, $position, $new );
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$prefix  = implode( '/', $parent );
		$section = $blocks[ $parent[0] ] ?? null;
		if ( null === $section ) {
			return invalid_parent( sprintf( 'No block at path %s.', $prefix ) );
		}

		$section_markup               = serialize_block( $section );
		$report['section_round_trip'] = BlockTree\to_markup( BlockTree\parse( $section_markup ) ) === $section_markup;

		if ( ! $report['section_round_trip'] ) {
			return invalid_parent( sprintf( 'The section at %d would not be written back the same way after the insert, so nothing was saved.', $parent[0] ) );
		}

		$out = [
			'parent'         => $prefix,
			'section_markup' => $section_markup,
		];
	}

	if ( ! $dry_run ) {
		$saved = BlockTree\save( $post_id, $blocks );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}

	$last = $index + count( $new ) - 1;

	return [
		'post_id'    => $post_id,
		'item'       => $item,
		'index'      => $index,
		'saved'      => ! $dry_run,
		'markup'     => $markup,
		'blocks'     => array_values(
			array_filter(
				BlockTree\describe( $blocks ),
				static fn ( array $row ): bool => BlockTree\in_range( $row['path'], $prefix, $index, $last )
			)
		),
		'validation' => $report,
	] + $out;
}

/**
 * insert's repeat input as label => labels of the copies.
 *
 * @param mixed $value Input.
 * @return array<string, string[]>
 */
function repeat_input( mixed $value ): array {
	$out = [];

	foreach ( assoc( $value ) as $label => $labels ) {
		$names = array_values(
			array_filter(
				array_map( static fn ( mixed $name ): string => Cast\to_string( $name ), is_array( $labels ) ? $labels : [] ),
				static fn ( string $name ): bool => '' !== $name
			)
		);

		if ( [] !== $names ) {
			$out[ $label ] = $names;
		}
	}

	return $out;
}

/**
 * Put new blocks inside the block at a path, among its inner blocks.
 *
 * Refused, with nothing changed, when the container holds no blocks yet, is
 * locked, does not allow the new blocks, or a new block's parent or ancestor
 * rule is not met there.
 *
 * @param array<int, Block> $blocks   All blocks, by reference.
 * @param int[]             $path     Path of the container.
 * @param int               $position Index among its inner blocks to insert before. -1, or past the end, appends.
 * @param array<int, Block> $new      Blocks to insert.
 * @return int|WP_Error Index of the first new block among the container's inner blocks.
 */
function place_inside( array &$blocks, array $path, int $position, array $new ) {
	$chain     = BlockTree\ancestors( $blocks, $path );
	$container = &BlockTree\find( $blocks, $path );

	if ( null === $container ) {
		return invalid_parent( sprintf( 'No block at path %s. Read paths with get-post-blocks.', implode( '/', $path ) ) );
	}

	$where = sprintf( '%s at %s', (string) $container['blockName'], implode( '/', $path ) );
	$count = count( $container['innerBlocks'] );

	if ( 0 === $count ) {
		return invalid_parent( sprintf( '%s holds no blocks. %s', $where, empty_container_hint( $container ) ) );
	}

	$chain[] = $container;
	$lock    = BlockTree\template_lock( $chain );
	if ( null !== $lock ) {
		return invalid_parent( sprintf( 'Cannot insert into %s: %s Unlock it in the editor, or pick another parent.', $where, $lock ) );
	}

	$errors = BlockTree\placement_errors( $new, $chain );
	if ( $errors ) {
		return invalid_parent( implode( ' ', $errors ) . sprintf( ' Pick another parent than %s.', $where ) );
	}

	$index = ( $position < 0 || $position > $count ) ? $count : $position;
	if ( ! BlockTree\insert_inner( $container, $index, $new ) ) {
		return invalid_parent( sprintf( 'The saved HTML of %s has no place for more blocks.', $where ) );
	}

	return $index;
}

/**
 * What to do when the chosen parent holds no blocks.
 *
 * @param Block $block The empty container.
 */
function empty_container_hint( array $block ): string {
	$name = (string) $block['blockName'];
	$slug = Cast\to_string( $block['attrs']['slug'] ?? '' );
	$ref  = Cast\to_int( $block['attrs']['ref'] ?? 0 );

	if ( 'core/template-part' === $name && '' !== $slug ) {
		return sprintf( 'It shows the template part "%1$s", which is a post of its own: find it with find-posts, {"post_type": "wp_template_part", "slug": "%1$s"}, and insert into that post.', $slug );
	}

	if ( 'core/block' === $name && $ref > 0 ) {
		return sprintf( 'It shows synced pattern %1$d, which is a post of its own: insert with post_id %1$d.', $ref );
	}

	if ( 'core/pattern' === $name ) {
		return 'It is a reference that renders a pattern as shipped, so nothing can go inside it.';
	}

	return 'Where its HTML holds inner blocks cannot be known until it holds one. Insert beside it instead, or add a block inside it in the editor first.';
}

/**
 * The error for a parent the new blocks cannot go into.
 *
 * @param string $message What is wrong.
 */
function invalid_parent( string $message ): WP_Error {
	return new WP_Error( 'voyager_open_blocks_invalid_parent', $message, [ 'status' => 400 ] );
}

/**
 * Load a post and the block at a path.
 *
 * @param array<string, mixed> $input Input with post_id and path.
 * @return array{0: array<int, Block>, 1: int[]}|WP_Error Blocks and parsed path.
 */
function locate( array $input ) {
	$blocks = BlockTree\load( Cast\to_int( $input['post_id'] ) );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$raw  = $input['path'] ?? '';
	$path = BlockTree\parse_path( is_string( $raw ) || is_array( $raw ) ? $raw : '' );
	if ( is_wp_error( $path ) ) {
		return $path;
	}

	if ( null === BlockTree\find( $blocks, $path ) ) {
		return no_block_at( $path );
	}

	return [ $blocks, $path ];
}

/**
 * The error for a path with no block behind it.
 *
 * @param int[] $path Parsed path.
 */
function no_block_at( array $path ): WP_Error {
	return new WP_Error( 'voyager_open_blocks_invalid_path', sprintf( 'No block at path %s. Read paths with get-post-blocks.', implode( '/', $path ) ), [ 'status' => 400 ] );
}

/**
 * Execute configure.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function configure( array $input ) {
	$located = locate( $input );
	if ( is_wp_error( $located ) ) {
		return $located;
	}
	[ $blocks, $path ] = $located;

	$block = &BlockTree\find( $blocks, $path );
	if ( null === $block ) {
		return no_block_at( $path );
	}

	$attributes = check_attributes( (string) $block['blockName'], assoc( $input['attributes'] ?? null ), $block );
	if ( is_wp_error( $attributes ) ) {
		return $attributes;
	}

	foreach ( $attributes as $attribute => $value ) {
		if ( null === $value ) {
			unset( $block['attrs'][ $attribute ] );
		} else {
			$block['attrs'][ $attribute ] = $value;
		}
	}

	return finish( Cast\to_int( $input['post_id'] ), $blocks, $block, implode( '/', $path ) );
}

/**
 * Execute bind.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function bind( array $input ) {
	$located = locate( $input );
	if ( is_wp_error( $located ) ) {
		return $located;
	}
	[ $blocks, $path ] = $located;

	$post_id = Cast\to_int( $input['post_id'] );
	$own     = (string) get_post_type( $post_id );
	$given   = Cast\to_string( $input['post_type'] ?? '' );
	$in_loop = in_array( 'core/post-template', array_map( static fn ( array $around ): string => Cast\to_string( $around['blockName'] ), BlockTree\ancestors( $blocks, $path ) ), true );

	if ( '' !== $given && ! post_type_exists( $given ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_post_type', sprintf( 'There is no post type "%s".', $given ), [ 'status' => 400 ] );
	}

	$post_type = Catalog\binding_post_type( $own, $given, $in_loop );
	if ( is_wp_error( $post_type ) ) {
		return $post_type;
	}

	$block = &BlockTree\find( $blocks, $path );
	if ( null === $block ) {
		return no_block_at( $path );
	}

	$name      = (string) $block['blockName'];
	$attribute = Cast\to_string( $input['attribute'] ?? '' );
	$source    = Cast\to_string( $input['source'] ?? '' );
	$args      = (array) ( $input['args'] ?? [] );
	$bindable  = array_values( array_filter( get_block_bindings_supported_attributes( $name ), 'is_string' ) );

	$checked = Catalog\check_binding( $name, $attribute, $source, $args, $bindable, $post_type );
	if ( is_wp_error( $checked ) ) {
		return Catalog\explain_post_type( $checked, $own, $given );
	}

	$metadata = BlockTree\metadata( $block );
	$bindings = isset( $metadata['bindings'] ) && is_array( $metadata['bindings'] ) ? $metadata['bindings'] : [];

	$bindings[ $attribute ]     = [
		'source' => $source,
		'args'   => $args,
	];
	$metadata['bindings']       = $bindings;
	$block['attrs']['metadata'] = $metadata;

	return finish( $post_id, $blocks, $block, implode( '/', $path ) );
}

/**
 * Validate, save and report a changed block.
 *
 * @param int               $post_id Post ID.
 * @param array<int, Block> $blocks  All blocks, already changed.
 * @param Block             $block   The changed block.
 * @param string            $path    Its path.
 * @return array<string, mixed>|WP_Error
 */
function finish( int $post_id, array $blocks, array $block, string $path ) {
	$markup = serialize_block( $block );
	$report = validation( [ $block ], $markup );

	if ( ! $report['registered_and_attributes'] || ! $report['round_trip'] ) {
		return new WP_Error( 'voyager_open_blocks_invalid_attributes', 'The block did not pass validation: ' . implode( ' ', $report['errors'] ), [ 'status' => 400 ] );
	}

	$saved = BlockTree\save( $post_id, $blocks );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return [
		'post_id'    => $post_id,
		'path'       => $path,
		'block'      => $block['blockName'],
		'attributes' => $block['attrs'],
		'markup'     => $markup,
		'validation' => $report,
	];
}

/**
 * Execute validate.
 *
 * @param array<string, mixed>|null $input Input: markup, or post_id; post_type optional.
 * @return array<string, mixed>|WP_Error
 */
function validate( $input = null ) {
	$input     = assoc( $input );
	$has_post  = isset( $input['post_id'] );
	$post_type = Cast\to_string( $input['post_type'] ?? '' );
	$out       = [];

	if ( isset( $input['markup'] ) === $has_post ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_markup',
			$has_post ? 'Pass markup or post_id, not both.' : 'Pass markup (block markup to check) or post_id (a saved post to check).',
			[ 'status' => 400 ]
		);
	}

	if ( $has_post ) {
		$post_id = Cast\to_int( $input['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'voyager_open_blocks_invalid_post_id', sprintf( 'Post %d does not exist.', $post_id ), [ 'status' => 404 ] );
		}

		$markup    = (string) $post->post_content;
		$post_type = '' === $post_type ? $post->post_type : $post_type;
		$out       = [ 'post_id' => $post_id ];
	} else {
		$markup = Cast\to_string( $input['markup'] );
	}

	return $out + BlockTree\validate_markup( $markup, '' === $post_type ? 'page' : $post_type ) + [ 'save_output' => SAVE_OUTPUT ];
}
