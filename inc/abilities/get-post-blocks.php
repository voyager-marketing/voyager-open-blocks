<?php
/**
 * The get-post-blocks ability: a post's blocks by path, with their bindings and whether each resolves.
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
 * Register get-post-blocks.
 */
function register_get_post_blocks(): void {
	$post_id_schema = post_id_schema();

	wp_register_ability(
		CATEGORY . '/get-post-blocks',
		[
			'label'               => __( 'Get a post\'s blocks', 'voyager-open-blocks' ),
			'description'         => __( 'Returns every block in a post with its path, block name, label (metadata.name), the start of its own text and its bindings. A path with no / is a top-level section, and text tells blocks of one type apart, such as the call button among a header\'s buttons. Each binding\'s resolution.status is "resolved", with value_preview, or "fell_back" to the block\'s own text, with a reason: empty_value means its field is empty, so tell the user which Site Data field to fill. In a template, a post meta binding always falls back, as there is no post: check it with validate and post_type.', 'voyager-open-blocks' ),
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
