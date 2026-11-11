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
use VoyagerOpenBlocks\SectionStyles;
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
			'description'         => __( 'Sets attributes on a block in a post, validated against its block.json. Only blocks that save no HTML, so every attribute lives in the block comment (such as voyager-open/icon). A null value resets an attribute to its default. An attribute the block binds is refused with voyager_open_blocks_bound_slot and nothing is saved, because its value comes from the binding; null still resets it. style sets a section style on a core/group instead (panel, inverted or accent from get-catalog\'s styles, or plain for none): its is-style-* class in the block comment and on the saved wrapper, replacing any other style.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'    => $post_id_schema,
					'path'       => $path_schema,
					'style'      => [
						'type'        => 'string',
						'enum'        => array_merge( [ 'plain' ], array_keys( SectionStyles\STYLES ) ),
						'description' => 'Section style for a core/group, in place of attributes. plain takes the style off.',
					],
					'attributes' => [
						'type'        => 'object',
						'description' => 'Attributes to set.',
					],
				],
				'required'             => [ 'post_id', 'path' ],
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

	if ( isset( $input['style'] ) ) {
		if ( isset( $input['attributes'] ) ) {
			return new WP_Error( 'voyager_open_blocks_invalid_style', 'Pass style or attributes, not both: a section style goes on a core/group, which takes no attributes here.', [ 'status' => 400 ] );
		}

		$styled = SectionStyles\apply( $block, Cast\to_string( $input['style'] ) );
		if ( is_wp_error( $styled ) ) {
			return $styled;
		}
		$block = $styled;

		return finish( Cast\to_int( $input['post_id'] ), $blocks, $block, implode( '/', $path ) );
	}

	if ( ! array_key_exists( 'attributes', $input ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_attributes', 'Pass attributes to set, or style to set a section style.', [ 'status' => 400 ] );
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
