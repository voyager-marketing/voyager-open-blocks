<?php
/**
 * The configure ability: attributes on a block that saves no HTML.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register configure.
 */
function register_configure(): void {
	$post_id_schema = post_id_schema();
	$path_schema    = path_schema();

	wp_register_ability(
		CATEGORY . '/configure',
		[
			'label'               => __( 'Configure a Voyager Open block', 'voyager-open-blocks' ),
			'description'         => __( 'Sets attributes on a block in a post, validated against its block.json. Only blocks that save no HTML, so every attribute lives in the block comment (such as voyager-open/icon). A null value resets an attribute to its default. An attribute the block binds is refused with voyager_open_blocks_bound_slot and nothing is saved, because its value comes from the binding; null still resets it.', 'voyager-open-blocks' ),
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

	// Before the other checks, so a bound attribute is named as bound even on a
	// block configure cannot change, such as the CTA band's call button.
	$refused = bound_attribute_slots( $block, assoc( $input['attributes'] ?? null ), implode( '/', $path ) );
	if ( [] !== $refused ) {
		return bound_slot_error( 'attributes', $refused );
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
