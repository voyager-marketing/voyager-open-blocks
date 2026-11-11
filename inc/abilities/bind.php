<?php
/**
 * The bind ability: a block attribute bound to a field get-catalog lists.
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

/**
 * Register bind.
 */
function register_bind(): void {
	$post_id_schema = post_id_schema();
	$path_schema    = path_schema();

	wp_register_ability(
		CATEGORY . '/bind',
		[
			'label'               => __( 'Bind a block attribute to a field', 'voyager-open-blocks' ),
			'description'         => __( 'Writes a block binding: the attribute must be bindable for that block, and args must match a field listed by get-catalog (or the Wesper manifest) exactly. Args are never built for you. In a template, pass post_type: the post type it shows. dry_run runs every check and returns the markup without saving. A save returns revision_id, which undo takes to reverse it.', 'voyager-open-blocks' ),
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
					'dry_run'   => dry_run_schema(),
				],
				'required'             => [ 'post_id', 'path', 'attribute', 'source', 'args' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [ 'saved' => [ 'type' => 'boolean' ] ] + revision_output_schema(),
			],
			'execute_callback'    => __NAMESPACE__ . '\bind',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( false, true ),
		]
	);
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

	return finish( $post_id, $blocks, $block, implode( '/', $path ), ! empty( $input['dry_run'] ) );
}
