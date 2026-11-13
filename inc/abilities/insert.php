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
			'description'         => __( 'Places a pack pattern (as a copy, or as a reference that tracks plugin updates) or one of the pack\'s blocks (get-catalog\'s blocks) into a post, at the top level or inside a block given as parent, and returns the inserted block markup with the paths of its blocks. Inside a parent, the parent\'s allowedBlocks and lock and each new block\'s parent and ancestor rules from block.json apply. A pattern inserted as a copy takes copy, the text of the named blocks get-catalog marks copy: true, each within the pattern\'s copy_rules; omit, the labels of blocks to leave out; and repeat, new labels for copies of a named block. Copy or omit that breaks a copy rule, or copy for a bound slot, refuses the whole call: nothing is saved, and data.refused lists every refused slot. dry_run runs every check and saves nothing. A save returns revision_id, which undo takes to reverse it.', 'voyager-open-blocks' ),
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
					'parent'     => [
						'type'        => 'string',
						'pattern'     => '^\d+(/\d+)*$',
						'description' => 'Path of the block to insert into, from get-post-blocks, such as "0" for the row that holds a header\'s buttons. Leave it out to insert at the top level. The block must already hold at least one block, must allow the new one (allowedBlocks) and must not be locked (templateLock).',
					],
					'position'   => [
						'type'        => 'integer',
						'minimum'     => -1,
						'default'     => -1,
						'description' => 'Index to insert before: among the top-level blocks, or among parent\'s inner blocks when parent is given. -1 appends.',
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
					'copy'       => [
						'type'                 => 'object',
						'default'              => [],
						'additionalProperties' => copy_value_schema(),
						'description'          => 'Patterns in copy mode only. Text by label: the metadata.name of a block get-catalog lists with copy: true in the pattern\'s blocks, such as {"Heading": "...", "Body": "..."}. Each value is plain text, or {"text": "...", "source": "user"} for text the site owner gave you. It replaces the block\'s text, escaped. get-catalog\'s copy_rules give each label its purpose, min, max, required and claim. Refused, with nothing saved and every refused slot in data.refused: text longer than max once trimmed (too_long; min is guidance only); a plain string for a claim slot (claim), whose text comes from the site owner, as the object form, or from a binding to the Site Data field that holds it; and a label whose text is bound, such as "Click to call" (bound): its text comes from the binding, so change the value in Settings > Site Data instead. The code is voyager_open_blocks_bound_slot when every refused slot is bound, else voyager_open_blocks_copy_rules.',
					],
					'omit'       => [
						'type'        => 'array',
						'default'     => [],
						'items'       => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'description' => 'Patterns in copy mode only. Labels of blocks to leave out, with everything inside them, such as ["Credential 4"] for a proof strip with three facts or ["Hours"] in the contact section. Not the label of the whole pattern. A slot whose copy rule is required, or a block that holds one, is refused (required), unless the block is the slot\'s whole item, such as "Service 3" for "Service 3 heading".',
					],
					'repeat'     => [
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
						'description'          => 'Patterns in copy mode only. Adds copies of a named block right after it, one per new label, such as {"Credential 4": ["Credential 5", "Credential 6"]} for a proof strip with six facts. A block that holds named blocks repeats when their labels start with its own and a space, such as "Service 3 heading" inside "Service 3": in each copy they start with its new label ("Service 4 heading"). Fill the new labels with copy. Runs before omit and copy. Not for the whole pattern, a block holding any other named block or an HTML anchor, or a label used twice.',
					],
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
	$omit       = array_values( array_filter( array_map( static fn ( mixed $label ): string => Cast\to_string( $label ), (array) ( $input['omit'] ?? [] ) ) ) );
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

		// repeat first, so copy can fill the new labels and omit can drop the original.
		if ( $repeat ) {
			$repeated = BlockTree\repeat( $new, $repeat );
			if ( is_wp_error( $repeated ) ) {
				return $repeated;
			}
		}

		// The copy rules, against the blocks as repeat left them, before omit
		// drops any. A copy repeat made keeps the rule of the block it copies.
		// What they refuse is returned with the bound slots below.
		if ( $values || $omit ) {
			$rules  = repeated_rules( Patterns\intent_meta( $item )['copy_rules'], $repeat );
			$broken = copy_rule_refusals( $rules, $values, $omit, BlockTree\describe( $new ) );
		}

		if ( $omit ) {
			$omitted = BlockTree\omit( $new, $omit );
			if ( is_wp_error( $omitted ) ) {
				return $omitted;
			}
		}

		if ( $copy ) {
			$copied = BlockTree\set_copy( $new, $copy );
			if ( is_wp_error( $copied ) ) {
				return $copied;
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
