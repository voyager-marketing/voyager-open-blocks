<?php
/**
 * The insert ability: a pack pattern or block into a post, at the top level or inside a block.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\Patterns;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * Register insert.
 */
function register_insert(): void {
	$post_id_schema = post_id_schema();

	wp_register_ability(
		CATEGORY . '/insert',
		[
			'label'               => __( 'Insert a Voyager Open block or pattern', 'voyager-open-blocks' ),
			'description'         => __( 'Places one of the pack\'s patterns or blocks from get-catalog in a post, at the top level or inside the block at parent, and returns its markup and each new block\'s path and label. A pattern goes in as a copy, which takes copy, omit and repeat, or as a reference that follows plugin updates. For a whole draft page, use compose. Refused input saves nothing, and data.refused lists every refused slot. No ability adds a block that is not the pack\'s, changes an image or changes a static button\'s link: tell the user to do that in the editor. A save returns revision_id, which undo takes.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'    => $post_id_schema,
					'item'       => [
						'type'        => 'string',
						'enum'        => items(),
						'description' => 'A pattern or block name from get-catalog.',
					],
					'parent'     => [
						'type'        => 'string',
						'pattern'     => '^\d+(/\d+)*$',
						'description' => 'Path of the block to go inside, from get-post-blocks. Beside a header\'s call button, that is the block holding its core/buttons, such as "0", not core/buttons, which takes only buttons. It must already hold a block, allow the new one and not be locked. Left out, the top level.',
					],
					'position'   => [
						'type'        => 'integer',
						'minimum'     => -1,
						'default'     => -1,
						'description' => 'Index to insert before, among the top-level blocks or parent\'s inner blocks: 0 is first, -1 the end.',
					],
					'mode'       => [
						'type'        => 'string',
						'enum'        => [ 'copy', 'reference' ],
						'default'     => 'copy',
						'description' => 'Patterns only: copy, whose blocks can be edited, or reference, a core/pattern that renders the current pattern.',
					],
					'attributes' => [
						'type'        => 'object',
						'default'     => [],
						'description' => 'Blocks only, checked against block.json. Bindings go through bind.',
					],
					'copy'       => [
						'type'                 => 'object',
						'default'              => [],
						'additionalProperties' => copy_value_schema(),
						'description'          => 'Text by copy label, matched exactly, case and commas included: a block get-catalog marks copy: true, such as {"Heading": "Need a plumber today?"}. On an FAQ question, a details block, it sets the summary. Plain text, escaped, within the pattern\'s copy_rules, and longer than max is refused (too_long; min is guidance). A claim slot, such as a review or a credential, takes only the object form, with what the site owner gave you (claim). A bound slot, such as "Click to call", takes none: its value is set in Settings > Site Data (bound).',
					],
					'omit'       => omit_schema( 'Labels of blocks to leave out, with what they hold, such as ["Credential 4"] for three facts. A required slot, or a block holding one, is refused (required) unless the block is its whole item, such as "Service 3", and so is an omit that leaves nothing to show.' ),
					'repeat'     => repeat_schema( 'New labels for copies of a named block, placed right after it, such as {"Credential 4": ["Credential 5", "Credential 6"]} for six facts. Labels inside it that start with its own and a space take the new one: "Service 3 heading" becomes "Service 4 heading". Fill them with copy. Runs before omit and copy.' ),
					'dry_run'    => dry_run_schema(),
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
				] + revision_output_schema(),
			],
			'execute_callback'    => __NAMESPACE__ . '\insert',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( false, false ),
		]
	);
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
	$values     = copy_values( $input['copy'] ?? null );
	$copy       = copy_texts( $values );
	$omit       = omit_input( $input['omit'] ?? null );
	$repeat     = repeat_input( $input['repeat'] ?? null );
	$dry_run    = ! empty( $input['dry_run'] );
	$parent     = null;
	$broken     = [];

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

		// repeat, the copy rules, omit, then copy: fill_pattern(), which
		// compose fills each section with too. What the copy rules refuse
		// is returned with the bound slots below.
		if ( 'copy' === $mode && ( [] !== $values || [] !== $omit || [] !== $repeat ) ) {
			$broken = fill_pattern( $new, Patterns\intent_meta( $item )['copy_rules'], $values, $omit, $repeat );
			if ( is_wp_error( $broken ) ) {
				return $broken;
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

	// The new blocks as they now sit in the post, with the post's paths.
	$last = $index + count( $new ) - 1;
	$rows = array_values(
		array_filter(
			BlockTree\describe( $blocks ),
			static fn ( array $row ): bool => BlockTree\in_range( $row['path'], $prefix, $index, $last )
		)
	);

	// Copy for a bound slot, or copy or omit a copy rule refuses, refuses the
	// whole call before anything is saved, dry run or not, so a section is
	// never saved half filled. Bound slots are checked once the blocks are
	// placed, so each is named by its path in the post.
	$bound = $copy ? bound_copy_slots( $rows, $copy ) : [];
	if ( [] !== $bound || [] !== $broken ) {
		return copy_error( $bound, $broken );
	}

	$revision = [ 'revision_id' => null ];

	if ( ! $dry_run ) {
		$revision = save_blocks( $post_id, $blocks );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
	}

	return [
		'post_id'    => $post_id,
		'item'       => $item,
		'index'      => $index,
		'saved'      => ! $dry_run,
		'markup'     => $markup,
		'blocks'     => $rows,
		'validation' => $report,
	] + $out + $revision;
}

/**
 * Schema for omit, insert's and a compose section's: labels of blocks to leave out.
 *
 * @param string $description What it does where it is used.
 * @return array<string, mixed>
 */
function omit_schema( string $description ): array {
	return [
		'type'        => 'array',
		'default'     => [],
		'items'       => [
			'type'      => 'string',
			'minLength' => 1,
		],
		'description' => $description,
	];
}

/**
 * Schema for repeat, insert's and a compose section's: label => labels of its copies.
 *
 * @param string $description What it does where it is used.
 * @return array<string, mixed>
 */
function repeat_schema( string $description ): array {
	return [
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
		'description'          => $description,
	];
}

/**
 * insert's omit input, or a compose section's, as a list of labels.
 *
 * @param mixed $value Input.
 * @return list<string>
 */
function omit_input( mixed $value ): array {
	return array_values( array_filter( array_map( static fn ( mixed $label ): string => Cast\to_string( $label ), is_array( $value ) ? $value : [] ) ) );
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
