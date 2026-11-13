<?php
/**
 * What every ability shares: the category, meta, the permission callbacks,
 * the input schemas, attribute checks, the validation report, locate() and
 * finish(), saving with a revision, and register(), which registers the
 * abilities in their order. Each ability's own registration and callback is
 * in its file beside this one.
 *
 * Semantic abilities with an `item` or `path` parameter, not one per block.
 * Each callback is a thin adapter over inc/block-tree.php and inc/catalog.php.
 * All are visible over REST (wp-abilities/v1) and to the WordPress MCP adapter.
 *
 * What "validated" means here: WordPress checks every block is registered,
 * every comment attribute it has a schema for is valid, and the markup
 * survives a parse and serialize round trip. bind and validate also check a
 * binding's attribute, source and args. Saved HTML is checked by
 * `block-runner validate`, which runs the blocks' JavaScript save(). Pattern
 * markup is validated that way when it is built (npm run check). An agent
 * with Node should pipe the returned `markup` through it too; one without
 * has the PHP checks as its floor.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CATEGORY = 'voyager-open-blocks';

/**
 * What every validation report says about saved HTML.
 */
const SAVE_OUTPUT = 'Not checked: comparing saved HTML with each block\'s save() needs the block\'s JavaScript, which WordPress cannot run in PHP. With Node, run: npx block-runner validate <file> --strict --json --context site.context.json';

/**
 * Register the category.
 */
function register_category(): void {
	wp_register_ability_category(
		CATEGORY,
		[
			'label'       => __( 'Voyager Open Blocks', 'voyager-open-blocks' ),
			'description' => __( 'Place, configure and bind the blocks and patterns of the Voyager Open Blocks pack.', 'voyager-open-blocks' ),
		]
	);
}
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_category' );

/**
 * Shared meta for every ability.
 *
 * @param bool $readonly   Does not change the site.
 * @param bool $idempotent Repeating the call with the same input changes nothing more.
 * @return array<string, mixed>
 */
function meta( bool $readonly, bool $idempotent ): array {
	return [
		'show_in_rest' => true,
		'mcp'          => [ 'public' => true ],
		'annotations'  => [
			'readonly'    => $readonly,
			// Nothing here deletes content. configure and bind overwrite one
			// attribute or binding, which is an update, not a destructive act,
			// and undo keeps the content it replaces in the post's revisions.
			'destructive' => false,
			'idempotent'  => $idempotent,
		],
	];
}

/**
 * Permission: the user may edit the post named in the input.
 *
 * @param array<string, mixed>|null $input Ability input.
 */
function can_edit_post( $input = null ): bool {
	$post_id = is_array( $input ) && isset( $input['post_id'] ) ? Cast\to_int( $input['post_id'] ) : 0;

	return $post_id > 0 && current_user_can( 'edit_post', $post_id );
}

/**
 * Permission for validate: edit the post when it checks a saved post, else edit posts.
 *
 * @param array<string, mixed>|null $input Ability input.
 */
function can_validate( $input = null ): bool {
	if ( is_array( $input ) && isset( $input['post_id'] ) ) {
		return can_edit_post( $input );
	}

	return current_user_can( 'edit_posts' );
}

/**
 * A value as an array with string keys, [] when it is not an array.
 *
 * @param mixed $value Value.
 * @return array<string, mixed>
 */
function assoc( mixed $value ): array {
	$out = [];

	foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
		$out[ (string) $key ] = $item;
	}

	return $out;
}

/**
 * Schema for post_id: the post or page an ability reads or changes.
 *
 * @return array<string, mixed>
 */
function post_id_schema(): array {
	return [
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => 'The post\'s ID, from find-posts.',
	];
}

/**
 * Schema for path: a block's place in a post, from get-post-blocks or insert.
 *
 * @return array<string, mixed>
 */
function path_schema(): array {
	return [
		'type'        => 'string',
		'pattern'     => '^\d+(/\d+)*$',
		'description' => 'Block path from get-post-blocks or insert, such as "2/0/1".',
	];
}

/**
 * Schema for dry_run, the same on every ability that saves.
 *
 * @return array<string, mixed>
 */
function dry_run_schema(): array {
	return [
		'type'        => 'boolean',
		'default'     => false,
		'description' => 'Run every check, refusals included, and save nothing.',
	];
}

/**
 * Output schema for revision_id and revision_note, the same on every ability that saves.
 *
 * @return array<string, array<string, mixed>>
 */
function revision_output_schema(): array {
	return [
		'revision_id'   => [
			'type'        => [ 'integer', 'null' ],
			'description' => 'The revision WordPress made for this save. Pass it to undo to reverse the save. null on a dry run, and when no revision was made; revision_note then says why.',
		],
		'revision_note' => [
			'type'        => 'string',
			'description' => 'Why revision_id is null, or why undo cannot reverse this save. Only when one of those holds.',
		],
	];
}

/**
 * Register the abilities.
 *
 * In this order, which is the order they are listed in. A new ability is a
 * file in inc/abilities/, a require in inc/abilities.php and a line here.
 */
function register(): void {
	register_get_catalog();
	register_find_posts();
	register_get_post_blocks();
	register_insert();
	register_compose();
	register_configure();
	register_bind();
	register_undo();
	register_validate();
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );

/**
 * Validate attributes for a block whose attributes all live in the comment.
 *
 * That holds only for a block that saves no HTML. A render callback is not
 * enough: core/heading has one, yet its saved <h2> must still match `level`,
 * so changing the comment alone would make the block invalid.
 *
 * @param string               $name       Block name.
 * @param array<string, mixed> $attributes Attributes; null resets one.
 * @param Block|null           $block      The existing block, or null when inserting a pack block.
 * @return array<string, mixed>|WP_Error The attributes to apply.
 */
function check_attributes( string $name, array $attributes, ?array $block = null ) {
	$type      = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$saves_any = null === $block
		? ! str_starts_with( $name, Catalog\PREFIX )
		: '' !== trim( $block['innerHTML'] ) || ! empty( $block['innerBlocks'] );

	if ( ! $type || ! $type->is_dynamic() || $saves_any ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_item',
			sprintf( '%s saves HTML, so its attributes cannot be changed without re-rendering that HTML. Re-author it with block-runner assemble.', $name ),
			[ 'status' => 400 ]
		);
	}

	foreach ( $attributes as $attribute => $value ) {
		if ( 'metadata' === $attribute ) {
			return new WP_Error( 'voyager_open_blocks_invalid_attributes', 'Set bindings with the bind ability, not through attributes.', [ 'status' => 400 ] );
		}

		$schema = $type->attributes[ $attribute ] ?? null;
		if ( ! is_array( $schema ) ) {
			return new WP_Error(
				'voyager_open_blocks_invalid_attributes',
				sprintf( '%s has no attribute "%s". Valid attributes: %s.', $name, $attribute, implode( ', ', array_keys( (array) $type->attributes ) ) ),
				[ 'status' => 400 ]
			);
		}

		if ( null === $value ) {
			continue;
		}

		$valid = rest_validate_value_from_schema( $value, array_intersect_key( $schema, array_flip( [ 'type', 'enum', 'items', 'properties' ] ) ), $attribute );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'voyager_open_blocks_invalid_attributes', $valid->get_error_message(), [ 'status' => 400 ] );
		}
	}

	return $attributes;
}

/**
 * Validation report for markup that is about to be written.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param string            $markup Their markup.
 * @return array{registered_and_attributes: bool, errors: string[], round_trip: bool, save_output: string}
 */
function validation( array $blocks, string $markup ): array {
	$check = BlockTree\check( $blocks );

	return [
		'registered_and_attributes' => $check['valid'],
		'errors'                    => $check['errors'],
		'round_trip'                => BlockTree\to_markup( BlockTree\parse( $markup ) ) === $markup,
		'save_output'               => SAVE_OUTPUT,
	];
}

/**
 * Load a post and the block at a path.
 *
 * @param array<string, mixed> $input Input with post_id and path.
 * @return array{0: array<int, Block>, 1: int[]}|WP_Error Blocks and parsed path.
 */
function locate( array $input ) {
	$blocks = BlockTree\load( Cast\to_int( $input['post_id'] ) );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$raw  = $input['path'] ?? '';
	$path = BlockTree\parse_path( is_string( $raw ) || is_array( $raw ) ? $raw : '' );
	if ( is_wp_error( $path ) ) {
		return $path;
	}

	if ( null === BlockTree\find( $blocks, $path ) ) {
		return no_block_at( $path );
	}

	return [ $blocks, $path ];
}

/**
 * The error for a path with no block behind it.
 *
 * @param int[] $path Parsed path.
 */
function no_block_at( array $path ): WP_Error {
	return new WP_Error( 'voyager_open_blocks_invalid_path', sprintf( 'No block at path %s. Read paths with get-post-blocks.', implode( '/', $path ) ), [ 'status' => 400 ] );
}

/**
 * Validate, save and report a changed block.
 *
 * @param int               $post_id Post ID.
 * @param array<int, Block> $blocks  All blocks, already changed.
 * @param Block             $block   The changed block.
 * @param string            $path    Its path.
 * @param bool              $dry_run Check and report, but save nothing.
 * @return array<string, mixed>|WP_Error
 */
function finish( int $post_id, array $blocks, array $block, string $path, bool $dry_run = false ) {
	$markup = serialize_block( $block );
	$report = validation( [ $block ], $markup );

	if ( ! $report['registered_and_attributes'] || ! $report['round_trip'] ) {
		return new WP_Error( 'voyager_open_blocks_invalid_attributes', 'The block did not pass validation: ' . implode( ' ', $report['errors'] ), [ 'status' => 400 ] );
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
		'path'       => $path,
		'block'      => $block['blockName'],
		'attributes' => $block['attrs'],
		'saved'      => ! $dry_run,
		'markup'     => $markup,
		'validation' => $report,
	] + $revision;
}

/**
 * Save blocks as a post's content, and name the revision WordPress made for the save.
 *
 * The post as it stands is stored as a revision first, unless the newest one
 * already holds its content byte for byte. WordPress makes no revision when a
 * post is created, only when it is updated, and compares with
 * normalize_whitespace(), so without this the content before the first save
 * through an ability would be in no revision and undo could not restore it.
 *
 * @param int               $post_id Post ID.
 * @param array<int, Block> $blocks  Blocks.
 * @return array{revision_id: int|null, revision_note?: string}|WP_Error
 */
function save_blocks( int $post_id, array $blocks ) {
	$before = keep_current_state( $post_id );

	$saved = BlockTree\save( $post_id, $blocks );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return revision_made( $post_id, $before );
}

/**
 * The revision a save made, read straight after it, or null and the reason.
 *
 * @param int      $post_id Post ID.
 * @param int|null $before  The newest revision before the save, from keep_current_state().
 * @return array{revision_id: int|null, revision_note?: string}
 */
function revision_made( int $post_id, ?int $before ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post || ! wp_revisions_enabled( $post ) ) {
		return [
			'revision_id'   => null,
			'revision_note' => 'Revisions are off for this post (its post type does not support them, or the site keeps none), so WordPress kept no revision of this save and undo is unavailable.',
		];
	}

	// wp_save_post_revision() skips an auto-draft, a post created and never
	// saved, so neither this save nor the content before it is in a revision.
	if ( 'auto-draft' === $post->post_status ) {
		return [
			'revision_id'   => null,
			'revision_note' => 'This post is an auto-draft, and WordPress keeps no revisions of an auto-draft, so this save cannot be undone. Once the post is saved as a draft or published, each save returns a revision_id.',
		];
	}

	// The same check as before the save: a change WordPress compared away,
	// such as one in whitespace only, still gets a revision of its own.
	$after = keep_current_state( $post_id );

	if ( null === $after ) {
		return [
			'revision_id'   => null,
			'revision_note' => 'WordPress made no revision of this save, so undo is unavailable for it.',
		];
	}

	if ( $after === $before ) {
		return [
			'revision_id'   => null,
			'revision_note' => 'The content did not change, so WordPress made no revision and there is nothing to undo.',
		];
	}

	if ( null === prior_revision( revision_ids( $post_id ), $after, is_autosave( ... ) ) ) {
		return [
			'revision_id'   => $after,
			'revision_note' => 'WordPress kept no revision from before this save (the site limits how many it keeps), so undo cannot restore the content it replaced.',
		];
	}

	return [ 'revision_id' => $after ];
}

/**
 * Store the post as it stands as a revision, unless the newest one already holds its content.
 *
 * Only post_content is compared, byte for byte, because it is all the
 * abilities write and all undo restores.
 *
 * @param int $post_id Post ID.
 * @return int|null The newest revision that is not an autosave, null when revisions are off or there is none.
 */
function keep_current_state( int $post_id ): ?int {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post || ! wp_revisions_enabled( $post ) ) {
		return null;
	}

	$latest = latest_revision( $post_id );

	if ( null !== $latest && $latest->post_content === $post->post_content ) {
		return $latest->ID;
	}

	// wp_save_post_revision() skips a post that matches its newest revision
	// once whitespace is normalized. Ask it to save regardless, for this call only.
	$always = static fn (): bool => false;
	add_filter( 'wp_save_post_revision_check_for_changes', $always );
	wp_save_post_revision( $post_id );
	remove_filter( 'wp_save_post_revision_check_for_changes', $always );

	return latest_revision( $post_id )?->ID;
}

/**
 * A post's newest revision that is not an autosave.
 *
 * @param int $post_id Post ID.
 */
function latest_revision( int $post_id ): ?\WP_Post {
	foreach ( revision_ids( $post_id ) as $id ) {
		if ( ! is_autosave( $id ) ) {
			return revision( $id );
		}
	}

	return null;
}

/**
 * A revision by ID, null when the ID names no revision.
 *
 * get_post() rather than wp_get_post_revision(), which takes its argument by reference.
 *
 * @param int $revision_id Revision ID.
 */
function revision( int $revision_id ): ?\WP_Post {
	$revision = get_post( $revision_id );

	return $revision instanceof \WP_Post && 'revision' === $revision->post_type ? $revision : null;
}

/**
 * A post's revision IDs, autosaves included, newest first.
 *
 * In wp_get_post_revisions()'s order (date, then ID), which is the order the
 * revisions screen shows. Read whether or not revisions are on now, so a
 * revision made while they were on can still be found.
 *
 * @param int $post_id Post ID.
 * @return list<int>
 */
function revision_ids( int $post_id ): array {
	$ids = wp_get_post_revisions(
		$post_id,
		[
			'fields'        => 'ids',
			'check_enabled' => false,
		]
	);

	return array_values( array_map( static fn ( mixed $id ): int => Cast\to_int( $id instanceof \WP_Post ? $id->ID : $id ), $ids ) );
}

/**
 * Whether a revision is an autosave, which no ability makes and undo skips.
 *
 * @param int $revision_id Revision ID.
 */
function is_autosave( int $revision_id ): bool {
	return false !== wp_is_post_autosave( $revision_id );
}

/**
 * The revision before a given one: the content a save replaced.
 *
 * @param list<int>          $ids         Revision IDs, newest first, from revision_ids().
 * @param int                $revision_id The revision a save made.
 * @param callable(int):bool $autosave    Whether a revision is an autosave. Autosaves are skipped.
 * @return int|null Null when $revision_id is not in the list, or no older revision is kept.
 */
function prior_revision( array $ids, int $revision_id, callable $autosave ): ?int {
	$at = array_search( $revision_id, $ids, true );

	if ( false === $at ) {
		return null;
	}

	foreach ( array_slice( $ids, $at + 1 ) as $id ) {
		if ( ! $autosave( $id ) ) {
			return $id;
		}
	}

	return null;
}

/**
 * The bindings on a block whose values belong to their source.
 *
 * Every attribute in metadata.bindings that names a source, except one bound
 * to core/pattern-overrides: that source keeps the block's own text as the
 * default each copy of a synced pattern may override, so text written there
 * is the default, not a fallback.
 *
 * @param mixed $bindings A block's metadata.bindings.
 * @return array<string, array{source: string, key: string}> Attribute => its source and key ('' when the args name none).
 */
function bound_attributes( mixed $bindings ): array {
	$out = [];

	foreach ( is_array( $bindings ) ? $bindings : [] as $attribute => $binding ) {
		$source = is_array( $binding ) && isset( $binding['source'] ) && is_string( $binding['source'] ) ? $binding['source'] : '';
		$args   = is_array( $binding ) && isset( $binding['args'] ) && is_array( $binding['args'] ) ? $binding['args'] : [];

		if ( '' === $source || 'core/pattern-overrides' === $source ) {
			continue;
		}

		$out[ (string) $attribute ] = [
			'source' => $source,
			'key'    => isset( $args['key'] ) && is_string( $args['key'] ) ? $args['key'] : '',
		];
	}

	return $out;
}

/**
 * insert's copy labels that would write text a binding owns.
 *
 * A label is refused when a block carrying it is bound on the attribute copy
 * writes: a heading's, paragraph's or list item's content, or a button's text.
 * The text would be saved only as the binding's fallback, which stays in the
 * post after the value changes. Each label is listed once.
 *
 * @param array<int, Row>     $rows The new blocks as BlockTree\describe() lists them, with their paths in the post.
 * @param array<mixed, mixed> $copy Label => text.
 * @return list<array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string}>
 */
function bound_copy_slots( array $rows, array $copy ): array {
	$refused = [];

	foreach ( $rows as $row ) {
		$label     = $row['label'] ?? null;
		$attribute = BlockTree\TEXT_ATTRIBUTES[ $row['block'] ] ?? null;

		if ( null === $label || null === $attribute || ! array_key_exists( $label, $copy ) || isset( $refused[ $label ] ) ) {
			continue;
		}

		$bound = bound_attributes( $row['bindings'] ?? [] );

		if ( isset( $bound[ $attribute ] ) ) {
			$refused[ $label ] = [
				'slot'      => $label,
				'reason'    => 'bound',
				'block'     => $row['block'],
				'path'      => $row['path'],
				'attribute' => $attribute,
				'source'    => $bound[ $attribute ]['source'],
				'key'       => $bound[ $attribute ]['key'],
			];
		}
	}

	return array_values( $refused );
}

/**
 * configure's attributes that a binding on the block owns.
 *
 * Null is not refused: it removes the attribute, which clears a stale fallback
 * rather than writing one.
 *
 * @param Block                $block      The block at the path.
 * @param array<string, mixed> $attributes Attributes to set; null resets one.
 * @param string               $path       The block's path.
 * @return list<array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string}>
 */
function bound_attribute_slots( array $block, array $attributes, string $path ): array {
	$bound   = bound_attributes( BlockTree\metadata( $block )['bindings'] ?? [] );
	$refused = [];

	foreach ( $attributes as $attribute => $value ) {
		$attribute = (string) $attribute;

		if ( null === $value || ! isset( $bound[ $attribute ] ) ) {
			continue;
		}

		$refused[] = [
			'slot'      => $attribute,
			'reason'    => 'bound',
			'block'     => (string) $block['blockName'],
			'path'      => $path,
			'attribute' => $attribute,
			'source'    => $bound[ $attribute ]['source'],
			'key'       => $bound[ $attribute ]['key'],
		];
	}

	return $refused;
}

/**
 * Where the value behind a binding is changed instead.
 *
 * @param string $source Binding source.
 * @param string $key    Its key, '' when the args name none.
 */
function where_to_change( string $source, string $key ): string {
	if ( SiteData\SOURCE === $source ) {
		return 'change the value in Settings > Site Data instead';
	}

	if ( ( PostMetaText\SOURCE === $source || 'core/post-meta' === $source ) && '' !== $key ) {
		return sprintf( 'change the post\'s "%s" field instead', $key );
	}

	return sprintf( 'change the value where %s keeps it instead', $source );
}

/**
 * One bound slot as a sentence: where it is, what it is bound to, and where to change the value.
 *
 * @param array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string} $slot A refused slot, from bound_copy_slots() or bound_attribute_slots().
 */
function bound_slot_line( array $slot ): string {
	return sprintf(
		'"%s" (%s %s at %s) is bound to %s%s, so %s.',
		$slot['slot'],
		$slot['block'],
		$slot['attribute'],
		$slot['path'],
		$slot['source'],
		'' === $slot['key'] ? '' : sprintf( ', key "%s"', $slot['key'] ),
		where_to_change( $slot['source'], $slot['key'] )
	);
}

/**
 * The error for copy or attributes aimed at bound slots. Lists every one.
 *
 * @param string $field   The input that named them: copy or attributes.
 * @param list<array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string}> $refused Refused slots, from bound_copy_slots() or bound_attribute_slots().
 */
function bound_slot_error( string $field, array $refused ): WP_Error {
	$slots = [];

	foreach ( $refused as $slot ) {
		$slots[] = bound_slot_line( $slot );
	}

	return new WP_Error(
		'voyager_open_blocks_bound_slot',
		sprintf(
			'Nothing was saved: %1$s names %2$s whose value comes from a binding. A value passed here would be stored only as the fallback, which stays in the post after the real value changes and shows whenever the source returns nothing. Leave %3$s out of %1$s. %4$s',
			$field,
			1 === count( $refused ) ? 'a slot' : 'slots',
			1 === count( $refused ) ? 'it' : 'them',
			implode( ' ', $slots )
		),
		[
			'status'  => 400,
			'refused' => $refused,
		]
	);
}
