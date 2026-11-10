<?php
/**
 * The find-posts ability: the ID of the page, post, template or template part to change.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\Cast;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Register find-posts.
 */
function register_find_posts(): void {
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
