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
			'description'         => __( 'Binds a block attribute to a field, so the block shows the site\'s value. The attribute must be bindable on that block: content on a paragraph or heading, text and url on a button, label and linkUrl on the icon, items on the facts block, and all seven on the contact details block; a refusal lists the ones that are. args must equal a field\'s args from get-catalog. The block\'s own text stays as the fallback, shown while the field is empty. A binding replaces the whole attribute: give a phone number inside a longer line a block of its own first. The site owner makes a post meta field bindable under Settings > Site Data. A save returns revision_id, which undo takes.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'   => $post_id_schema,
					'path'      => $path_schema,
					'attribute' => [
						'type'        => 'string',
						'description' => 'The attribute to bind.',
					],
					'source'    => [
						'type' => 'string',
						'enum' => Catalog\SOURCES,
					],
					'args'      => [
						'type'        => 'object',
						'description' => 'A field\'s args from get-catalog, verbatim, such as {"key":"phone"}.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'In a template, template part, synced pattern or Query Loop, the post type the block shows, such as "post" for the single template, with args from get-catalog for that type. Elsewhere, the post\'s own type.',
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
