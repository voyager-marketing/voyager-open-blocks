<?php
/**
 * The validate ability: block markup, or a saved post, checked without saving anything.
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
 * Register validate.
 */
function register_validate(): void {
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
