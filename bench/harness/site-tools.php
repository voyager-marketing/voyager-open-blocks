<?php
/**
 * Plugin Name: Bench site tools
 * Description: The page and business-details abilities both benchmark arms get through the MCP Adapter. Installed as an mu-plugin by bench/run.mjs; never part of the pack.
 *
 * WordPress core and the MCP Adapter register no ability that creates or
 * edits a page, so neither arm could build anything through MCP alone. These
 * six are the same in both arms. They take and return raw block markup and
 * plain option values, and check nothing, so they give neither arm an edge.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBench;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business details an agent can read and change: key => option name.
 *
 * @return array<string, string>
 */
function details(): array {
	return [
		'business_name' => 'blogname',
		'phone'         => 'voyager_site_phone',
		'email'         => 'voyager_site_email',
		'address'       => 'voyager_site_address',
		'address_city'  => 'voyager_site_address_city',
		'address_state' => 'voyager_site_address_state',
		'address_zip'   => 'voyager_site_address_zip',
		'hours'         => 'voyager_site_hours',
	];
}

/**
 * Ability meta: public over MCP.
 *
 * @param bool $readonly Does not change the site.
 * @return array<string, mixed>
 */
function meta( bool $readonly ): array {
	return [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'readonly'    => $readonly,
			'destructive' => false,
			'idempotent'  => $readonly,
		],
	];
}

/**
 * A page as the abilities return it.
 *
 * @param \WP_Post $post Page.
 * @return array<string, mixed>
 */
function page( \WP_Post $post ): array {
	return [
		'post_id' => $post->ID,
		'title'   => $post->post_title,
		'status'  => $post->post_status,
		'link'    => (string) get_permalink( $post ),
		'content' => $post->post_content,
	];
}

/**
 * The page named by post_id, or an error.
 *
 * @param array<string, mixed> $input Ability input.
 * @return \WP_Post|WP_Error
 */
function find( array $input ) {
	$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
	if ( ! $post || 'page' !== $post->post_type ) {
		return new WP_Error( 'site_tools_no_page', 'No page with that post_id.' );
	}
	return $post;
}

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'site',
			[
				'label'       => 'Site',
				'description' => 'Read and change pages and the business details.',
			]
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$can_edit   = static fn (): bool => current_user_can( 'edit_pages' );
		$can_manage = static fn (): bool => current_user_can( 'manage_options' );
		$id         = [
			'type'    => 'integer',
			'minimum' => 1,
		];
		$status     = [
			'type' => 'string',
			'enum' => [ 'draft', 'publish' ],
		];

		wp_register_ability(
			'site/list-pages',
			[
				'label'               => 'List pages',
				'description'         => 'Lists every page with its ID, title, status and link.',
				'category'            => 'site',
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [],
					'properties'           => [],
					'additionalProperties' => false,
				],
				'execute_callback'    => static function (): array {
					$posts = get_posts(
						[
							'post_type'   => 'page',
							'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
							'numberposts' => -1,
						]
					);
					return [
						'pages' => array_map(
							static function ( \WP_Post $p ): array {
								$out = page( $p );
								unset( $out['content'] );
								return $out;
							},
							$posts
						),
					];
				},
				'permission_callback' => $can_edit,
				'meta'                => meta( true ),
			]
		);

		wp_register_ability(
			'site/get-page',
			[
				'label'               => 'Get a page',
				'description'         => 'Returns a page with its raw post_content (block markup).',
				'category'            => 'site',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [ 'post_id' => $id ],
					'required'             => [ 'post_id' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => static function ( $input ) {
					$post = find( (array) $input );
					return is_wp_error( $post ) ? $post : page( $post );
				},
				'permission_callback' => $can_edit,
				'meta'                => meta( true ),
			]
		);

		wp_register_ability(
			'site/create-page',
			[
				'label'               => 'Create a page',
				'description'         => 'Creates a page. content is saved as post_content exactly as given (block markup).',
				'category'            => 'site',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'title'   => [ 'type' => 'string' ],
						'content' => [ 'type' => 'string' ],
						'status'  => $status,
					],
					'required'             => [ 'title' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => static function ( $input ) {
					$input = (array) $input;
					// Core unslashes post data, so slash it first or block JSON
					// loses its backslashes.
					$post_id = wp_insert_post(
						wp_slash(
							[
								'post_type'    => 'page',
								'post_title'   => (string) $input['title'],
								'post_content' => (string) ( $input['content'] ?? '' ),
								'post_status'  => (string) ( $input['status'] ?? 'draft' ),
							]
						),
						true
					);
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}
					return page( get_post( $post_id ) );
				},
				'permission_callback' => $can_edit,
				'meta'                => meta( false ),
			]
		);

		wp_register_ability(
			'site/update-page',
			[
				'label'               => 'Update a page',
				'description'         => 'Changes a page\'s title, content (block markup, saved exactly as given) or status. Fields left out are unchanged.',
				'category'            => 'site',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'post_id' => $id,
						'title'   => [ 'type' => 'string' ],
						'content' => [ 'type' => 'string' ],
						'status'  => $status,
					],
					'required'             => [ 'post_id' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => static function ( $input ) {
					$input = (array) $input;
					$post  = find( $input );
					if ( is_wp_error( $post ) ) {
						return $post;
					}
					$changes = [ 'ID' => $post->ID ];
					foreach ( [
						'title'   => 'post_title',
						'content' => 'post_content',
						'status'  => 'post_status',
					] as $field => $column ) {
						if ( isset( $input[ $field ] ) ) {
							$changes[ $column ] = (string) $input[ $field ];
						}
					}
					$result = wp_update_post( wp_slash( $changes ), true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return page( get_post( $post->ID ) );
				},
				'permission_callback' => $can_edit,
				'meta'                => meta( false ),
			]
		);

		$details_schema = [
			'type'       => 'object',
			'properties' => array_map( static fn (): array => [ 'type' => 'string' ], details() ),
		];

		wp_register_ability(
			'site/get-business-details',
			[
				'label'               => 'Get the business details',
				'description'         => 'Returns the site\'s business details: name, phone, email, address and hours.',
				'category'            => 'site',
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [],
					'properties'           => [],
					'additionalProperties' => false,
				],
				'output_schema'       => $details_schema,
				'execute_callback'    => static fn (): array => array_map( static fn ( string $o ): string => (string) get_option( $o, '' ), details() ),
				'permission_callback' => $can_edit,
				'meta'                => meta( true ),
			]
		);

		wp_register_ability(
			'site/update-business-details',
			[
				'label'               => 'Update the business details',
				'description'         => 'Changes one or more business details. Keys left out are unchanged. Returns every detail after the change.',
				'category'            => 'site',
				'input_schema'        => array_merge( $details_schema, [ 'additionalProperties' => false ] ),
				'output_schema'       => $details_schema,
				'execute_callback'    => static function ( $input ): array {
					foreach ( details() as $key => $option ) {
						if ( isset( $input[ $key ] ) ) {
							update_option( $option, (string) $input[ $key ] );
						}
					}
					return array_map( static fn ( string $o ): string => (string) get_option( $o, '' ), details() );
				},
				'permission_callback' => $can_manage,
				'meta'                => meta( false ),
			]
		);
	}
);
