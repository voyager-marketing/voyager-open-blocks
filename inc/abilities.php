<?php
/**
 * Abilities for the pack: read the catalog and a post's blocks, then insert,
 * configure and bind.
 *
 * Semantic abilities with an `item` or `path` parameter, not one per block.
 * Each callback is a thin adapter over inc/block-tree.php and inc/catalog.php.
 * All are visible over REST (wp-abilities/v1) and to the WordPress MCP adapter.
 *
 * What "validated" means here: WordPress checks every block is registered,
 * every comment attribute it has a schema for is valid, and the markup
 * survives a parse and serialize round trip. Saved HTML is checked by
 * `block-runner validate`, which runs the blocks' JavaScript save(). Pattern
 * markup is validated that way when it is built (npm run check), and an agent
 * should pipe the returned `markup` through it too.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Catalog;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CATEGORY = 'voyager-open-blocks';

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
	$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	return $post_id > 0 && current_user_can( 'edit_post', $post_id );
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

	return array_values( array_filter( $names, static fn ( string $name ): bool => str_starts_with( $name, Catalog\PREFIX ) ) );
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
			'description'         => __( 'Lists the pack\'s blocks (attribute schema, bindable attributes), patterns (named blocks and their bindings) and the binding fields available for a post type, each with the exact args to bind it.', 'voyager-open-blocks' ),
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

	wp_register_ability(
		CATEGORY . '/get-post-blocks',
		[
			'label'               => __( 'Get a post\'s blocks', 'voyager-open-blocks' ),
			'description'         => __( 'Returns every block in a post with its path, block name, label (metadata.name) and bindings. Paths are what configure and bind take.', 'voyager-open-blocks' ),
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
					'blocks'  => [ 'type' => 'array' ],
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
			'description'         => __( 'Places a pack pattern (as a copy, or as a reference that tracks plugin updates) or the icon block into a post at a top-level position, and returns the inserted block markup with the paths of its blocks.', 'voyager-open-blocks' ),
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
					'position'   => [
						'type'        => 'integer',
						'minimum'     => -1,
						'default'     => -1,
						'description' => 'Top-level index to insert before. -1 appends.',
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
					'post_id'    => [ 'type' => 'integer' ],
					'item'       => [ 'type' => 'string' ],
					'index'      => [ 'type' => 'integer' ],
					'saved'      => [ 'type' => 'boolean' ],
					'markup'     => [ 'type' => 'string' ],
					'blocks'     => [ 'type' => 'array' ],
					'validation' => [ 'type' => 'object' ],
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
			'description'         => __( 'Writes a block binding: the attribute must be bindable for that block, and args must match a field listed by get-catalog (or the Wesper manifest) exactly. Args are never built for you.', 'voyager-open-blocks' ),
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
						'enum' => [ 'voyager/site-data', 'voyager/post-meta-text', 'core/post-meta' ],
					],
					'args'      => [
						'type'        => 'object',
						'description' => 'A field\'s args, copied verbatim, such as {"key":"phone"}.',
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
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );

/**
 * Execute get-catalog.
 *
 * @param array<string, mixed>|null $input Input.
 * @return array<string, mixed>
 */
function get_catalog( $input = null ): array {
	$post_type = is_array( $input ) && ! empty( $input['post_type'] ) ? (string) $input['post_type'] : 'page';

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
	$post_id = (int) $input['post_id'];
	$blocks  = BlockTree\load( $post_id );

	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	return [
		'post_id' => $post_id,
		'blocks'  => BlockTree\describe( $blocks ),
	];
}

/**
 * Validate attributes for a block whose attributes all live in the comment.
 *
 * That holds only for a block that saves no HTML. A render callback is not
 * enough: core/heading has one, yet its saved <h2> must still match `level`,
 * so changing the comment alone would make the block invalid.
 *
 * @param string                    $name       Block name.
 * @param array<string, mixed>      $attributes Attributes; null resets one.
 * @param array<string, mixed>|null $block      The existing block, or null when inserting a pack block.
 * @return array<string, mixed>|WP_Error The attributes to apply.
 */
function check_attributes( string $name, array $attributes, ?array $block = null ) {
	$type      = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$saves_any = null === $block
		? ! str_starts_with( $name, Catalog\PREFIX )
		: '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) || ! empty( $block['innerBlocks'] );

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
 * @param array<int, array<string, mixed>> $blocks Blocks.
 * @param string                           $markup Their markup.
 * @return array<string, mixed>
 */
function validation( array $blocks, string $markup ): array {
	$check = BlockTree\check( $blocks );

	return [
		'registered_and_attributes' => $check['valid'],
		'errors'                    => $check['errors'],
		'round_trip'                => BlockTree\to_markup( BlockTree\parse( $markup ) ) === $markup,
		'save_output'               => 'Not checked by WordPress PHP. Run: npx block-runner validate <file> --strict --json --context site.context.json',
	];
}

/**
 * Execute insert.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function insert( array $input ) {
	$post_id    = (int) $input['post_id'];
	$item       = (string) $input['item'];
	$position   = isset( $input['position'] ) ? (int) $input['position'] : -1;
	$mode       = isset( $input['mode'] ) ? (string) $input['mode'] : 'copy';
	$attributes = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : [];
	$dry_run    = ! empty( $input['dry_run'] );

	$blocks = BlockTree\load( $post_id );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $item );

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
			: BlockTree\parse( (string) $pattern['content'] );
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

	$index = ( $position < 0 || $position > count( $blocks ) ) ? count( $blocks ) : $position;
	array_splice( $blocks, $index, 0, $new );

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
				static function ( array $row ) use ( $index, $last ): bool {
					$top = (int) strtok( $row['path'], '/' );
					return $top >= $index && $top <= $last;
				}
			)
		),
		'validation' => $report,
	];
}

/**
 * Load a post and the block at a path.
 *
 * @param array<string, mixed> $input Input with post_id and path.
 * @return array{0: array<int, array<string, mixed>>, 1: int[]}|WP_Error Blocks and parsed path.
 */
function locate( array $input ) {
	$blocks = BlockTree\load( (int) $input['post_id'] );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$path = BlockTree\parse_path( $input['path'] ?? '' );
	if ( is_wp_error( $path ) ) {
		return $path;
	}

	if ( null === BlockTree\find( $blocks, $path ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_path', sprintf( 'No block at path %s. Read paths with get-post-blocks.', implode( '/', $path ) ), [ 'status' => 400 ] );
	}

	return [ $blocks, $path ];
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

	$block      = &BlockTree\find( $blocks, $path );
	$attributes = check_attributes( (string) $block['blockName'], (array) $input['attributes'], $block );
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

	return finish( (int) $input['post_id'], $blocks, $block, implode( '/', $path ) );
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

	$block     = &BlockTree\find( $blocks, $path );
	$name      = (string) $block['blockName'];
	$attribute = (string) $input['attribute'];
	$source    = (string) $input['source'];
	$args      = (array) $input['args'];
	$bindable  = get_block_bindings_supported_attributes( $name );

	if ( ! in_array( $attribute, $bindable, true ) ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_attribute',
			sprintf( '"%s" is not bindable on %s. Bindable: %s.', $attribute, $name, $bindable ? implode( ', ', $bindable ) : 'none' ),
			[ 'status' => 400 ]
		);
	}

	if ( null === get_block_bindings_source( $source ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_source', sprintf( 'The binding source %s is not registered on this site.', $source ), [ 'status' => 400 ] );
	}

	$matched = Catalog\match_args( $source, (string) get_post_type( (int) $input['post_id'] ), $args );
	if ( is_wp_error( $matched ) ) {
		return $matched;
	}

	$block['attrs']['metadata']['bindings'][ $attribute ] = [
		'source' => $source,
		'args'   => $args,
	];

	return finish( (int) $input['post_id'], $blocks, $block, implode( '/', $path ) );
}

/**
 * Validate, save and report a changed block.
 *
 * @param int                              $post_id Post ID.
 * @param array<int, array<string, mixed>> $blocks  All blocks, already changed.
 * @param array<string, mixed>             $block   The changed block.
 * @param string                           $path    Its path.
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
