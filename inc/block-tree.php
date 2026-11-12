<?php
/**
 * Read and write a post's blocks by path.
 *
 * A path is the list of indices from the top level down, written "2/0/1":
 * the second child of the first child of the third top-level block. The
 * top level skips the whitespace-only freeform blocks parse_blocks() makes
 * between blocks, so indices match what an editor shows.
 *
 * The abilities in inc/abilities/ are thin adapters over these functions.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\BlockTree;

use VoyagerOpenBlocks\Cast;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks whose saved text set_copy() can replace, with the attribute that holds it.
 */
const TEXT_ATTRIBUTES = [
	'core/heading'   => 'content',
	'core/paragraph' => 'content',
	'core/button'    => 'text',
	'core/list-item' => 'content',
];

/**
 * Parse markup into blocks, dropping whitespace-only freeform blocks at the top level.
 *
 * @param string $markup Block markup.
 * @return array<int, Block>
 */
function parse( string $markup ): array {
	return array_values(
		array_filter(
			parse_blocks( $markup ),
			static fn ( array $block ): bool => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] )
		)
	);
}

/**
 * Serialize top-level blocks with one blank line between them.
 *
 * @param array<int, Block> $blocks Parsed blocks.
 */
function to_markup( array $blocks ): string {
	return implode( "\n\n", array_map( static fn ( array $block ): string => serialize_block( $block ), $blocks ) );
}

/**
 * A block's metadata (name, bindings), [] when it has none.
 *
 * @param Block $block Parsed block.
 * @return array<mixed>
 */
function metadata( array $block ): array {
	$metadata = $block['attrs']['metadata'] ?? null;

	return is_array( $metadata ) ? $metadata : [];
}

/**
 * Load a post's blocks.
 *
 * @param int $post_id Post ID.
 * @return array<int, Block>|WP_Error
 */
function load( int $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'voyager_open_blocks_invalid_post_id', sprintf( 'Post %d does not exist.', $post_id ), [ 'status' => 404 ] );
	}

	return parse( (string) $post->post_content );
}

/**
 * Save blocks as a post's content.
 *
 * @param int               $post_id Post ID.
 * @param array<int, Block> $blocks  Blocks.
 * @return true|WP_Error
 */
function save( int $post_id, array $blocks ) {
	$result = wp_update_post(
		[
			'ID'           => $post_id,
			'post_content' => wp_slash( to_markup( $blocks ) ),
		],
		true
	);

	return is_wp_error( $result ) ? $result : true;
}

/**
 * Turn "2/0/1" or [2, 0, 1] into a list of ints.
 *
 * @param string|array<mixed> $path Path.
 * @return int[]|WP_Error
 */
function parse_path( $path ) {
	$parts = is_array( $path ) ? $path : explode( '/', trim( (string) $path, '/' ) );

	if ( [] === $parts || [ '' ] === $parts ) {
		return new WP_Error( 'voyager_open_blocks_invalid_path', 'A block path is required, such as "0" or "2/0/1".', [ 'status' => 400 ] );
	}

	$ints = [];
	foreach ( $parts as $part ) {
		if ( ! is_numeric( $part ) || (int) $part < 0 ) {
			return new WP_Error( 'voyager_open_blocks_invalid_path', sprintf( 'Invalid block path segment "%s".', Cast\to_string( $part ) ), [ 'status' => 400 ] );
		}
		$ints[] = (int) $part;
	}

	return $ints;
}

/**
 * Get a reference to the block at a path.
 *
 * @param array<int, Block> $blocks Blocks, by reference.
 * @param int[]             $path   Parsed path.
 * @return Block|null Reference to the block, or null.
 */
function &find( array &$blocks, array $path ): ?array {
	$null    = null;
	$current = &$blocks;
	$last    = count( $path ) - 1;

	foreach ( $path as $depth => $index ) {
		if ( ! isset( $current[ $index ] ) ) {
			return $null;
		}
		if ( $depth === $last ) {
			return $current[ $index ];
		}
		/** @var array<int, Block> $current */
		$current = &$current[ $index ]['innerBlocks'];
	}

	return $null;
}

/**
 * The blocks a path runs through, outermost first, without the block at the path.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param int[]             $path   Parsed path.
 * @return array<int, Block> Empty for a top-level block, or a path that does not exist.
 */
function ancestors( array $blocks, array $path ): array {
	$chain = [];
	$level = $blocks;

	foreach ( array_slice( $path, 0, -1 ) as $index ) {
		if ( ! isset( $level[ $index ] ) ) {
			return [];
		}
		$chain[] = $level[ $index ];
		/** @var array<int, Block> $level */
		$level = $level[ $index ]['innerBlocks'];
	}

	return $chain;
}

/**
 * Is a path one of a parent's inner blocks from $first to $last, or inside one?
 *
 * @param string $path   Path such as "0/2/1".
 * @param string $parent Path of the parent, '' for the top level.
 * @param int    $first  Index of the first inner block.
 * @param int    $last   Index of the last inner block.
 */
function in_range( string $path, string $parent, int $first, int $last ): bool {
	if ( '' !== $parent ) {
		if ( ! str_starts_with( $path, $parent . '/' ) ) {
			return false;
		}
		$path = substr( $path, strlen( $parent ) + 1 );
	}

	$top = (int) strtok( $path, '/' );

	return $top >= $first && $top <= $last;
}

/**
 * A block's own text, without its inner blocks' text: the first 80 characters.
 *
 * Lets an agent tell two blocks of the same type apart, such as the call
 * button among a header's buttons.
 *
 * @param Block $block Parsed block.
 */
function own_text( array $block ): string {
	$html = implode( ' ', array_filter( $block['innerContent'], 'is_string' ) );
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, 80 );
}

/**
 * Every block's own text by path, for the blocks that have any.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param string            $prefix Path of the parent, '' at the top.
 * @return array<int|string, string> Keyed by path. PHP turns a top-level path such as "2" into an int key.
 */
function texts( array $blocks, string $prefix = '' ): array {
	$out = [];

	foreach ( $blocks as $index => $block ) {
		$path = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$text = null === $block['blockName'] ? '' : own_text( $block );

		if ( '' !== $text ) {
			$out[ $path ] = $text;
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		$out  += texts( $inner, $path );
	}

	return $out;
}

/**
 * Where a block type may go, from its block.json: parent, ancestor and allowedBlocks.
 *
 * @param string $name Block name.
 * @return array{parent: string[]|null, ancestor: string[]|null, allowed_blocks: string[]|null}
 */
function block_rules( string $name ): array {
	$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

	return [
		'parent'         => $type ? names( $type->parent ) : null,
		'ancestor'       => $type ? names( $type->ancestor ) : null,
		'allowed_blocks' => $type ? names( $type->allowed_blocks ) : null,
	];
}

/**
 * A list of block names from block.json, null when the key is not set.
 *
 * @param mixed $value Value of parent, ancestor or allowedBlocks.
 * @return string[]|null
 */
function names( mixed $value ): ?array {
	return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : null;
}

/**
 * Why blocks cannot go where they are being put, [] when they can.
 *
 * The rules the editor's inserter applies, read from block.json: a container's
 * allowedBlocks (and its own allowedBlocks attribute, which some containers
 * take), and each block's parent (the block directly around it) and ancestor
 * (any block around it). They are checked for every block being placed and,
 * recursively, for the blocks inside it.
 *
 * @param array<int, Block> $blocks Blocks to place, with their inner blocks.
 * @param array<int, Block> $chain  The blocks they go inside, outermost first, so their parent is last. [] at the top level.
 * @param (callable(string): array{parent: string[]|null, ancestor: string[]|null, allowed_blocks: string[]|null})|null $rules Rules for a block name. Defaults to block_rules().
 * @return string[]
 */
function placement_errors( array $blocks, array $chain, ?callable $rules = null ): array {
	$rules  = $rules ?? static fn ( string $name ): array => block_rules( $name );
	$parent = [] === $chain ? null : $chain[ count( $chain ) - 1 ];
	$around = array_map( static fn ( array $block ): string => Cast\to_string( $block['blockName'] ), $chain );
	$errors = [];

	foreach ( $blocks as $block ) {
		$name = $block['blockName'];
		if ( null === $name ) {
			continue;
		}

		$own       = $rules( $name );
		$parents   = $own['parent'] ?? [];
		$ancestors = $own['ancestor'] ?? [];

		if ( null !== $parent ) {
			$parent_name = (string) $parent['blockName'];
			$allowed     = $rules( $parent_name )['allowed_blocks'];
			$attribute   = $parent['attrs']['allowedBlocks'] ?? null;

			if ( is_array( $allowed ) && ! in_array( $name, $allowed, true ) ) {
				$errors[] = sprintf( '%s cannot go inside %s, which allows only %s.', $name, $parent_name, $allowed ? implode( ', ', $allowed ) : 'no blocks' );
			} elseif ( is_array( $attribute ) && ! in_array( $name, $attribute, true ) ) {
				$errors[] = sprintf( '%s cannot go inside this %s: its allowedBlocks setting allows only %s.', $name, $parent_name, $attribute ? implode( ', ', array_map( static fn ( mixed $item ): string => Cast\to_string( $item ), $attribute ) ) : 'no blocks' );
			}
		}

		if ( [] !== $parents && ( null === $parent || ! in_array( (string) $parent['blockName'], $parents, true ) ) ) {
			$errors[] = sprintf( '%s can only go directly inside %s.', $name, implode( ' or ', $parents ) );
		}

		if ( [] !== $ancestors && [] === array_intersect( $around, $ancestors ) ) {
			$errors[] = sprintf( '%s can only go somewhere inside %s.', $name, implode( ' or ', $ancestors ) );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			/** @var array<int, Block> $inner */
			$inner  = $block['innerBlocks'];
			$errors = array_merge( $errors, placement_errors( $inner, [ ...$chain, $block ], $rules ) );
		}
	}

	return $errors;
}

/**
 * Why nothing can be inserted inside the last block of a chain, null when it can.
 *
 * templateLock "all", "insert" or "contentOnly" stops the editor from adding
 * blocks. A block without the attribute takes the lock of the nearest block
 * around it that sets one, and "contentOnly" anywhere around it holds.
 *
 * @param array<int, Block> $chain Blocks from the top level down to the container.
 */
function template_lock( array $chain ): ?string {
	$locks = [ 'all', 'insert', 'contentOnly' ];
	$found = null;

	foreach ( array_reverse( $chain ) as $block ) {
		if ( ! array_key_exists( 'templateLock', $block['attrs'] ) ) {
			continue;
		}

		$lock = $block['attrs']['templateLock'];

		if ( 'contentOnly' === $lock ) {
			return sprintf( '%s is locked (templateLock "contentOnly").', (string) $block['blockName'] );
		}

		if ( null === $found ) {
			$found = is_string( $lock ) && in_array( $lock, $locks, true )
				? sprintf( '%s is locked (templateLock "%s").', (string) $block['blockName'], $lock )
				: '';
		}
	}

	return '' === $found ? null : $found;
}

/**
 * Put blocks among a container's inner blocks, at an index.
 *
 * The container must already hold at least one block: its saved HTML then has
 * a slot for each inner block, and the new ones go into new slots beside them,
 * separated by the blank line the editor writes between sibling blocks. A
 * container with no inner blocks has no slot, and where its HTML would hold
 * them cannot be known without its JavaScript save().
 *
 * @param Block             $parent Container, by reference. Unchanged when false is returned.
 * @param int               $index  Index among its inner blocks to insert before. Its count appends.
 * @param array<int, Block> $blocks Blocks to insert.
 * @return bool False when the container holds no blocks or the index is out of range.
 */
function insert_inner( array &$parent, int $index, array $blocks ): bool {
	$content = array_values( $parent['innerContent'] );
	$slots   = array_keys( $content, null, true );
	$count   = count( $parent['innerBlocks'] );

	if ( 0 === $count || count( $slots ) !== $count || $index < 0 || $index > $count || [] === $blocks ) {
		return false;
	}

	// Before an inner block: a slot, then the gap to that block. Appended: the gap, then a slot.
	$pair   = $index < $count ? [ null, "\n\n" ] : [ "\n\n", null ];
	$pieces = array_merge( ...array_fill( 0, count( $blocks ), $pair ) );
	$at     = $index < $count ? $slots[ $index ] : $slots[ $count - 1 ] + 1;
	array_splice( $content, $at, 0, $pieces );

	$inner = $parent['innerBlocks'];
	array_splice( $inner, $index, 0, $blocks );

	$parent['innerBlocks']  = $inner;
	$parent['innerContent'] = $content;
	$parent['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

	return true;
}

/**
 * Describe a block tree: one row per block with its path, name and bindings.
 *
 * With a post, each binding also carries `resolution`: whether its source
 * returned a value now, or the block would fall back to its authored text.
 *
 * @param array<int, Block> $blocks  Blocks.
 * @param string            $prefix  Path of the parent, '' at the top.
 * @param int|null          $post_id Post the blocks belong to. Null skips resolution.
 * @return array<int, Row>
 */
function describe( array $blocks, string $prefix = '', ?int $post_id = null ): array {
	$rows = [];

	foreach ( $blocks as $index => $block ) {
		$path     = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$metadata = metadata( $block );
		$row      = [
			'path'  => $path,
			'block' => $block['blockName'] ?? 'core/freeform',
		];

		if ( ! empty( $metadata['name'] ) ) {
			$row['label'] = Cast\to_string( $metadata['name'] );
		}
		if ( ! empty( $metadata['bindings'] ) && is_array( $metadata['bindings'] ) ) {
			$row['bindings'] = null === $post_id
				? $metadata['bindings']
				: with_resolution( $block, $post_id );
		}
		if ( 'core/pattern' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['slug'] ) ) {
			$row['pattern'] = Cast\to_string( $block['attrs']['slug'] );
		}

		$rows[] = $row;

		if ( ! empty( $block['innerBlocks'] ) ) {
			/** @var array<int, Block> $inner */
			$inner = $block['innerBlocks'];
			array_push( $rows, ...describe( $inner, $path, $post_id ) );
		}
	}

	return $rows;
}

/**
 * A block's declared bindings, each with how it resolves now.
 *
 * The value comes from the source's own registered callback, called with a
 * WP_Block built from the parsed block, as core does when it renders. Null or
 * an empty string is a fall back: core then keeps the attribute as authored.
 *
 * `reason` says why, when it can be known:
 * - `source_unregistered`: no plugin registers the source on this site.
 * - `unknown_key`: the source's fields are listed (see Catalog\fields()) and
 *   none has these args.
 * - `empty_value`: anything else, including a source whose fields cannot be listed.
 *
 * @param Block $block   Parsed block with metadata.bindings.
 * @param int   $post_id Post the block belongs to.
 * @return array<array<mixed>>
 */
function with_resolution( array $block, int $post_id ): array {
	$post_type = (string) get_post_type( $post_id );
	$instance  = new \WP_Block( $block, [ 'postId' => $post_id, 'postType' => $post_type ] );
	$out       = [];

	foreach ( (array) ( metadata( $block )['bindings'] ?? [] ) as $attribute => $binding ) {
		$binding = (array) $binding;
		$name    = isset( $binding['source'] ) && is_string( $binding['source'] ) ? $binding['source'] : '';
		$args    = isset( $binding['args'] ) && is_array( $binding['args'] ) ? $binding['args'] : [];
		$source  = '' === $name ? null : get_block_bindings_source( $name );
		$status  = 'fell_back';
		$reason  = null;
		$preview = null;

		if ( null === $source ) {
			$reason = 'source_unregistered';
		} else {
			$value = $source->get_value( $args, $instance, (string) $attribute );
			$text  = null === $value ? '' : ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );

			if ( '' !== $text ) {
				$status  = 'resolved';
				$preview = mb_substr( $text, 0, 80 );
			} else {
				$match  = \VoyagerOpenBlocks\Catalog\match_args( $name, $post_type, $args );
				$reason = is_wp_error( $match ) && 'voyager_open_blocks_invalid_args' === $match->get_error_code() ? 'unknown_key' : 'empty_value';
			}
		}

		$out[ $attribute ] = $binding + [
			'resolution' => [
				'status'        => $status,
				'reason'        => $reason,
				'value_preview' => $preview,
			],
		];
	}

	return $out;
}

/**
 * Check what WordPress can check about blocks without a JavaScript runtime.
 *
 * Every block type must be registered, and every comment attribute the
 * server knows the schema of must be valid against it. Saved HTML is not
 * checked here: that needs the block's save() in JavaScript, which is what
 * `block-runner validate` runs.
 *
 * @param array<int, Block> $blocks Blocks.
 * @return array{valid: bool, errors: string[]}
 */
function check( array $blocks ): array {
	$errors = check_level( $blocks, '' );

	return [
		'valid'  => [] === $errors,
		'errors' => $errors,
	];
}

/**
 * The errors of one level of blocks and, recursively, of their inner blocks.
 *
 * @param array<int, Block> $list   Blocks at one level.
 * @param string            $prefix Path of the parent, '' at the top.
 * @return string[]
 */
function check_level( array $list, string $prefix ): array {
	$errors = [];

	foreach ( $list as $index => $block ) {
		$path = '' === $prefix ? (string) $index : $prefix . '/' . $index;

		if ( null === $block['blockName'] ) {
			continue;
		}

		$errors = array_merge( $errors, block_errors( $block, $path ) );

		/** @var array<int, Block> $inner */
		$inner  = $block['innerBlocks'];
		$errors = array_merge( $errors, check_level( $inner, $path ) );
	}

	return $errors;
}

/**
 * The errors of one block itself, not of its inner blocks.
 *
 * Its type must be registered, and every comment attribute the server has a
 * schema for must be valid against it. An attribute read from the saved HTML
 * (`source` in block.json) is skipped: its value is in the HTML, not here.
 *
 * @param Block  $block Parsed block with a name.
 * @param string $path  Its path, for the messages.
 * @return string[]
 */
function block_errors( array $block, string $path ): array {
	$name = (string) $block['blockName'];
	$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

	if ( ! $type ) {
		return [ sprintf( '%s at %s is not a registered block type.', $name, $path ) ];
	}

	$errors = [];

	foreach ( $block['attrs'] as $attribute => $value ) {
		$schema = $type->attributes[ $attribute ] ?? null;
		if ( ! is_array( $schema ) || isset( $schema['source'] ) ) {
			continue;
		}
		$valid = rest_validate_value_from_schema( $value, array_intersect_key( $schema, array_flip( [ 'type', 'enum', 'items', 'properties' ] ) ), (string) $attribute );
		if ( is_wp_error( $valid ) ) {
			$errors[] = sprintf( '%s at %s: %s', $name, $path, $valid->get_error_message() );
		}
	}

	return $errors;
}

/**
 * Warnings for comment attributes that WordPress reads from the saved HTML.
 *
 * An attribute with a `source` in block.json, such as a paragraph's content
 * or a button's url, is parsed from the block's HTML. A value for it in the
 * block comment is ignored, and the editor drops it the next time it saves.
 *
 * @param Block  $block Parsed block with a name.
 * @param string $path  Its path, for the messages.
 * @return string[]
 */
function comment_warnings( array $block, string $path ): array {
	$name     = (string) $block['blockName'];
	$type     = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$warnings = [];

	if ( ! $type ) {
		return [];
	}

	foreach ( array_keys( $block['attrs'] ) as $attribute ) {
		$schema = $type->attributes[ $attribute ] ?? null;
		if ( is_array( $schema ) && isset( $schema['source'] ) ) {
			$warnings[] = sprintf( '%s at %s: "%s" is read from the block\'s HTML, so the value in the block comment is ignored. Put the text in the HTML.', $name, $path, $attribute );
		}
	}

	return $warnings;
}

/**
 * The first top-level block whose markup is not what WordPress writes back.
 *
 * Each top-level block must appear in the markup exactly as WordPress
 * serializes it, in order, with nothing but whitespace between blocks and
 * around the whole. That is what a parse and serialize round trip keeps, so
 * a mismatch means WordPress would save something other than what was given:
 * a missing closing comment, attributes that did not parse, a different
 * nesting, or harmless spelling such as JSON escapes.
 *
 * @param string   $markup Markup as given.
 * @param string[] $pieces Each top-level block as serialize_block() writes it, in order. A freeform block's piece is trimmed.
 * @return int|null Index of the first block that does not match, count( $pieces ) when text is left after the last block, or null when everything matches.
 */
function first_mismatch( string $markup, array $pieces ): ?int {
	$whitespace = " \t\n\r\0\x0B";
	$offset     = 0;

	foreach ( array_values( $pieces ) as $index => $piece ) {
		$offset += strspn( $markup, $whitespace, $offset );

		if ( substr( $markup, $offset, strlen( $piece ) ) !== $piece ) {
			return $index;
		}

		$offset += strlen( $piece );
	}

	return '' === trim( substr( $markup, $offset ), $whitespace ) ? null : count( $pieces );
}

/**
 * Spell every block comment delimiter the way serialize_block() does.
 *
 * Finds delimiters with WP_Block_Parser's own pattern, then writes each one
 * again: the name without `core/`, single spaces, and the attributes decoded
 * and encoded with serialize_block_attributes(). Two spellings the parser
 * reads the same way then compare equal, such as `&` and `\u0026` or `\/`
 * and `/` in the JSON. A delimiter whose JSON does not decode is left as it
 * is, so it still shows up as a mismatch.
 *
 * @param string $markup Block markup.
 */
function normalize_delimiters( string $markup ): string {
	$normalized = preg_replace_callback(
		'/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',
		static function ( array $match ): string {
			// A trailing group that did not take part is missing, not ''.
			$part      = static fn ( string $group ): string => Cast\to_string( $match[ $group ] ?? '' );
			$namespace = $part( 'namespace' );
			$name      = ( 'core/' === $namespace ? '' : $namespace ) . $part( 'name' );

			if ( '' !== $part( 'closer' ) ) {
				return sprintf( '<!-- /wp:%s -->', $name );
			}

			$attrs = '';
			if ( '' !== $part( 'attrs' ) ) {
				$decoded = json_decode( $part( 'attrs' ), true );
				if ( ! is_array( $decoded ) ) {
					return $part( '0' );
				}
				$attrs = [] === $decoded ? '' : serialize_block_attributes( $decoded ) . ' ';
			}

			return sprintf( '<!-- wp:%s %s%s-->', $name, $attrs, '' !== $part( 'void' ) ? '/' : '' );
		},
		$markup
	);

	if ( null === $normalized ) {
		return $markup;
	}

	// serialize_block() writes a block with no content as one void comment.
	// Normalized JSON never holds "-->", so the attributes end at the first "} -->".
	return preg_replace( '/<!-- wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?) ((?:\{.*?\} )?)--><!-- \/wp:\1 -->/s', '<!-- wp:$1 $2/-->', $normalized ) ?? $normalized;
}

/**
 * Validate block markup without saving it.
 *
 * Every block gets the checks insert, configure and bind run on the blocks
 * they write (block_errors()), and every declared binding gets bind's checks
 * (Catalog\binding_problems()). A top-level block that does not survive a
 * parse and serialize round trip (first_mismatch(), after
 * normalize_delimiters()) is an error too. HTML outside any block is an
 * error: WordPress keeps it as a Classic block.
 *
 * Saved HTML is not compared with what each block's save() would write. That
 * needs the block's JavaScript, which is what `block-runner validate` runs.
 *
 * @param string $markup    Block markup.
 * @param string $post_type Post type whose fields binding args are checked against.
 * @return array{valid: bool, post_type: string, round_trip: bool, blocks: array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>, errors: string[], warnings: string[], markup: string}
 */
function validate_markup( string $markup, string $post_type ): array {
	$blocks   = parse( $markup );
	$rows     = validate_level( $blocks, '', $post_type );
	$errors   = [];
	$warnings = [];

	if ( [] === $blocks ) {
		$errors[] = 'The markup holds no blocks.';
	}

	$mismatch = first_mismatch(
		normalize_delimiters( $markup ),
		array_map(
			static fn ( array $block ): string => null === $block['blockName'] ? trim( serialize_block( $block ) ) : serialize_block( $block ),
			$blocks
		)
	);

	if ( null !== $mismatch && [] !== $blocks ) {
		$top = (string) min( $mismatch, count( $blocks ) - 1 );

		foreach ( $rows as $index => $row ) {
			if ( $top === $row['path'] ) {
				$rows[ $index ]['errors'][] = sprintf( '%s at %s, or a block inside it, is not written the way WordPress writes it back after a parse and serialize round trip. Compare it with the same block in the returned markup.', $row['block'], $row['path'] );
				break;
			}
		}
	}

	foreach ( $rows as $row ) {
		$errors   = array_merge( $errors, $row['errors'] );
		$warnings = array_merge( $warnings, $row['warnings'] );
	}

	return [
		'valid'      => [] === $errors,
		'post_type'  => $post_type,
		'round_trip' => null === $mismatch,
		'blocks'     => $rows,
		'errors'     => $errors,
		'warnings'   => $warnings,
		'markup'     => to_markup( $blocks ),
	];
}

/**
 * Per-block results for one level of blocks and, recursively, their inner blocks.
 *
 * @param array<int, Block> $list      Blocks at one level.
 * @param string            $prefix    Path of the parent, '' at the top.
 * @param string            $post_type Post type whose fields binding args are checked against.
 * @return array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>
 */
function validate_level( array $list, string $prefix, string $post_type ): array {
	$rows = [];

	foreach ( $list as $index => $block ) {
		$path     = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$name     = $block['blockName'];
		$metadata = metadata( $block );
		$row      = [
			'path'     => $path,
			'block'    => $name ?? 'core/freeform',
			'errors'   => [],
			'warnings' => [],
		];

		if ( isset( $metadata['name'] ) && is_string( $metadata['name'] ) && '' !== $metadata['name'] ) {
			$row['label'] = $metadata['name'];
		}

		if ( null === $name ) {
			$row['errors'][] = sprintf( 'HTML outside any block at %s. WordPress keeps it as a Classic block. Put it in a block, or remove it.', $path );
		} else {
			$row['errors']   = block_errors( $block, $path );
			$row['warnings'] = comment_warnings( $block, $path );

			if ( array_key_exists( 'bindings', $metadata ) ) {
				$bindable        = array_values( array_filter( get_block_bindings_supported_attributes( $name ), 'is_string' ) );
				$found           = \VoyagerOpenBlocks\Catalog\binding_problems( $name, $path, $metadata['bindings'], $bindable, $post_type );
				$row['errors']   = array_merge( $row['errors'], $found['errors'] );
				$row['warnings'] = array_merge( $row['warnings'], $found['warnings'] );
			}
		}

		$rows[] = $row;

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $rows, ...validate_level( $inner, $path, $post_type ) );
	}

	return $rows;
}

/**
 * Labels (metadata.name) of the blocks whose text set_copy() can replace.
 *
 * @param array<int, Block> $blocks Blocks.
 * @return string[]
 */
function copy_labels( array $blocks ): array {
	$labels = [];

	foreach ( describe( $blocks ) as $row ) {
		if ( isset( $row['label'], TEXT_ATTRIBUTES[ $row['block'] ] ) ) {
			$labels[] = $row['label'];
		}
	}

	return array_values( array_unique( $labels ) );
}

/**
 * Replace the saved text of named blocks with plain text.
 *
 * Each key of $copy is a label (metadata.name), and every block carrying it
 * must be a heading, paragraph, button or list item. Attributes and bindings
 * are left alone, so a bound block keeps its binding and the new text becomes
 * its fallback.
 *
 * @param array<int, Block>     $blocks Blocks, by reference. Unchanged on error.
 * @param array<string, string> $copy   Label => plain text.
 * @return true|WP_Error
 */
function set_copy( array &$blocks, array $copy ) {
	$rows    = describe( $blocks );
	$labels  = copy_labels( $blocks );
	$targets = [];

	foreach ( $copy as $label => $text ) {
		$label   = (string) $label;
		$matches = array_filter( $rows, static fn ( array $row ): bool => ( $row['label'] ?? null ) === $label );

		if ( [] === $matches ) {
			return invalid_copy( sprintf( 'No block is named "%s".', $label ), $labels );
		}

		foreach ( $matches as $row ) {
			if ( ! isset( TEXT_ATTRIBUTES[ $row['block'] ] ) ) {
				return invalid_copy( sprintf( '"%s" is a %s. copy replaces the text of a heading, paragraph, button or list item.', $label, $row['block'] ), $labels );
			}
			$targets[] = [ $row['path'], (string) $text ];
		}
	}

	$changed = $blocks;
	foreach ( $targets as [ $path, $text ] ) {
		$indices = parse_path( $path );
		if ( is_wp_error( $indices ) ) {
			return $indices;
		}

		$block = &find( $changed, $indices );
		if ( null === $block ) {
			return invalid_copy( sprintf( 'No block at path %s.', $path ), $labels );
		}

		$result = replace_text( $block, $text );
		unset( $block );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	$blocks = $changed;

	return true;
}

/**
 * Remove named blocks, such as the fourth credential of a proof strip.
 *
 * Each label is a metadata.name inside the blocks, never a top-level block:
 * leaving out a whole section is leaving out the pattern. Every block with the
 * label goes, with the whitespace that separated it from a sibling, so its
 * parent reads as if the block had never been there. The parent's own HTML is
 * kept. Core containers (group, list, buttons, columns) save their inner
 * blocks in one slot, so their HTML does not depend on how many they hold.
 *
 * @param array<int, Block> $blocks Blocks, by reference. Unchanged on error.
 * @param string[]          $labels Labels of the blocks to remove.
 * @return true|WP_Error
 */
function omit( array &$blocks, array $labels ) {
	$rows      = describe( $blocks );
	$removable = array_values(
		array_unique(
			array_column(
				array_filter( $rows, static fn ( array $row ): bool => isset( $row['label'] ) && str_contains( $row['path'], '/' ) ),
				'label'
			)
		)
	);
	$paths     = [];

	foreach ( $labels as $label ) {
		$matches = array_filter( $rows, static fn ( array $row ): bool => ( $row['label'] ?? null ) === $label );

		if ( [] === $matches ) {
			return invalid_omit( sprintf( 'No block is named "%s".', $label ), $removable );
		}

		foreach ( $matches as $row ) {
			if ( ! str_contains( $row['path'], '/' ) ) {
				return invalid_omit( sprintf( '"%s" is the whole section. Leave the pattern out instead.', $label ), $removable );
			}
			$paths[] = $row['path'];
		}
	}

	// A block inside another removed block goes with it.
	$paths = array_filter(
		array_unique( $paths ),
		static function ( string $path ) use ( $paths ): bool {
			foreach ( $paths as $other ) {
				if ( str_starts_with( $path, $other . '/' ) ) {
					return false;
				}
			}
			return true;
		}
	);

	// Last in document order first, so a removal never moves a path still to come.
	usort( $paths, static fn ( string $a, string $b ): int => compare_paths( $b, $a ) );

	$changed = $blocks;
	foreach ( $paths as $path ) {
		$indices = parse_path( $path );
		if ( is_wp_error( $indices ) ) {
			return $indices;
		}

		$index  = (int) array_pop( $indices );
		$parent = &find( $changed, $indices );
		if ( null === $parent || ! remove_inner( $parent, $index ) ) {
			return invalid_omit( sprintf( 'No block at path %s.', $path ), $removable );
		}
		unset( $parent );
	}

	$blocks = $changed;

	return true;
}

/**
 * Add copies of named blocks right after them, such as a fifth and sixth
 * credential, or a fourth service card.
 *
 * Each key of $repeat is a label (metadata.name) and each value the labels of
 * its copies, in order. The block must be the only one with that label, sit
 * inside the pattern rather than be all of it, and carry no HTML anchor, nor
 * any block inside it (an id must be unique). A named block inside it must
 * have a label that starts with the block's own and a space, such as
 * "Service 3 heading" inside "Service 3": in each copy that start becomes the
 * copy's label ("Service 4 heading"), so copy can fill it. A block holding any
 * other named block cannot repeat, since that name would repeat. A copy is the
 * same block with the new labels, so its text, attributes and bindings are the
 * original's until copy replaces its text. Like omit(), it relies on core
 * containers saving their inner blocks in one slot each.
 *
 * @param array<int, Block>       $blocks Blocks, by reference. Unchanged on error.
 * @param array<string, string[]> $repeat Label => labels of the copies.
 * @return true|WP_Error
 */
function repeat( array &$blocks, array $repeat ) {
	$changed = $blocks;

	foreach ( $repeat as $label => $new_labels ) {
		$label      = (string) $label;
		$rows       = describe( $changed );
		$used       = array_column( array_filter( $rows, static fn ( array $row ): bool => isset( $row['label'] ) ), 'label' );
		$repeatable = repeatable_labels( $changed );
		$matches    = array_values( array_filter( $rows, static fn ( array $row ): bool => ( $row['label'] ?? null ) === $label ) );

		if ( [] === $matches ) {
			return invalid_repeat( sprintf( 'No block is named "%s".', $label ), $repeatable );
		}
		if ( count( $matches ) > 1 ) {
			return invalid_repeat( sprintf( '"%s" names more than one block.', $label ), $repeatable );
		}
		if ( ! in_array( $label, $repeatable, true ) ) {
			return invalid_repeat( sprintf( '"%s" is the whole section, holds a named block whose label does not start with "%s ", or has an HTML anchor in it, so it cannot be repeated.', $label, $label ), $repeatable );
		}
		if ( count( array_unique( $new_labels ) ) !== count( $new_labels ) ) {
			return invalid_repeat( sprintf( 'The copies of "%s" need different labels.', $label ), $repeatable );
		}

		$indices = parse_path( $matches[0]['path'] );
		if ( is_wp_error( $indices ) ) {
			return $indices;
		}

		$index  = (int) array_pop( $indices );
		$parent = &find( $changed, $indices );
		if ( null === $parent || ! isset( $parent['innerBlocks'][ $index ] ) ) {
			return invalid_repeat( sprintf( 'No block at path %s.', $matches[0]['path'] ), $repeatable );
		}

		/** @var Block $source */
		$source = $parent['innerBlocks'][ $index ];
		$inner  = inner_labels( $source, $label ) ?? [];
		foreach ( $new_labels as $new_label ) {
			$wanted = [ $new_label ];
			foreach ( $inner as $inner_label ) {
				$wanted[] = $new_label . substr( $inner_label, strlen( $label ) );
			}
			foreach ( $wanted as $taken ) {
				if ( '' === $new_label || in_array( $taken, $used, true ) ) {
					return invalid_repeat( sprintf( 'A copy needs a label no block has yet, and "%s" is taken or empty.', $taken ), $repeatable );
				}
			}
		}

		$copies = [];
		foreach ( $new_labels as $new_label ) {
			$copy                              = relabel( $source, $label, $new_label );
			$copy['attrs']['metadata']         = metadata( $source );
			$copy['attrs']['metadata']['name'] = $new_label;
			$copies[]                          = $copy;
		}

		$inserted = insert_inner( $parent, $index + 1, $copies );
		unset( $parent );

		if ( ! $inserted ) {
			return invalid_repeat( sprintf( 'The block around "%s" has no room for copies.', $label ), $repeatable );
		}
	}

	$blocks = $changed;

	return true;
}

/**
 * Labels repeat() accepts: a named block inside a section that is the only
 * one with its label, has no HTML anchor in it, and holds no named block
 * whose label does not start with its own and a space.
 *
 * @param array<int, Block> $blocks Blocks.
 * @return string[]
 */
function repeatable_labels( array $blocks ): array {
	$rows   = describe( $blocks );
	$counts = array_count_values( array_column( array_filter( $rows, static fn ( array $row ): bool => isset( $row['label'] ) ), 'label' ) );
	$out    = [];

	foreach ( $rows as $row ) {
		$label = $row['label'] ?? null;
		if ( null === $label || ! str_contains( $row['path'], '/' ) || 1 !== ( $counts[ $label ] ?? 0 ) ) {
			continue;
		}

		$path = parse_path( $row['path'] );
		$copy = $blocks;
		$node = is_wp_error( $path ) ? null : find( $copy, $path );
		if ( null === $node || holds_anchor( $node ) ) {
			continue;
		}

		if ( null !== inner_labels( $node, $label ) ) {
			$out[] = $label;
		}
	}

	return $out;
}

/**
 * The labels of the named blocks inside a block, when each starts with the
 * block's own label and a space, so a copy can take them under its own.
 *
 * @param Block  $block Parsed block.
 * @param string $label Its label.
 * @return string[]|null The labels, [] when it holds no named block, null when one does not start with $label and a space.
 */
function inner_labels( array $block, string $label ): ?array {
	/** @var array<int, Block> $inner */
	$inner  = $block['innerBlocks'];
	$labels = [];

	foreach ( describe( $inner ) as $row ) {
		if ( ! isset( $row['label'] ) ) {
			continue;
		}
		if ( ! str_starts_with( $row['label'], $label . ' ' ) ) {
			return null;
		}
		$labels[] = $row['label'];
	}

	return $labels;
}

/**
 * Whether a block, or any block inside it, has an HTML anchor.
 *
 * @param Block $block Parsed block.
 */
function holds_anchor( array $block ): bool {
	if ( isset( $block['attrs']['anchor'] ) ) {
		return true;
	}

	/** @var array<int, Block> $inner */
	$inner = $block['innerBlocks'];
	foreach ( $inner as $child ) {
		if ( holds_anchor( $child ) ) {
			return true;
		}
	}

	return false;
}

/**
 * A copy of a block whose inner labels that start with $from and a space
 * start with $to instead: "Service 3 heading" becomes "Service 4 heading".
 * The block's own label is left for the caller.
 *
 * @param Block  $block Parsed block.
 * @param string $from  The label the inner labels start with.
 * @param string $to    The label they start with in the copy.
 * @return Block
 */
function relabel( array $block, string $from, string $to ): array {
	/** @var array<int, Block> $inner */
	$inner = $block['innerBlocks'];

	foreach ( $inner as $index => $child ) {
		$name = metadata( $child )['name'] ?? null;
		if ( is_string( $name ) && str_starts_with( $name, $from . ' ' ) ) {
			$child['attrs']['metadata']         = metadata( $child );
			$child['attrs']['metadata']['name'] = $to . substr( $name, strlen( $from ) );
		}
		$inner[ $index ] = relabel( $child, $from, $to );
	}
	$block['innerBlocks'] = $inner;

	return $block;
}

/**
 * The error for repeat that cannot be applied, listing the labels that can be repeated.
 *
 * @param string   $message What is wrong.
 * @param string[] $labels  Labels that can be repeated.
 */
function invalid_repeat( string $message, array $labels ): WP_Error {
	return new WP_Error(
		'voyager_open_blocks_invalid_repeat',
		sprintf( '%s Labels you can repeat: %s.', $message, $labels ? implode( ', ', array_map( 'wp_json_encode', $labels ) ) : 'none' ),
		[
			'status' => 400,
			'labels' => $labels,
		]
	);
}

/**
 * Compare two block paths in document order.
 *
 * @param string $a Path such as "0/1".
 * @param string $b Path such as "0/0/5".
 * @return int Negative when $a comes first, positive when $b does, 0 when equal.
 */
function compare_paths( string $a, string $b ): int {
	$left  = array_map( 'intval', explode( '/', $a ) );
	$right = array_map( 'intval', explode( '/', $b ) );

	foreach ( $left as $depth => $index ) {
		if ( ! isset( $right[ $depth ] ) ) {
			return 1;
		}
		if ( $index !== $right[ $depth ] ) {
			return $index <=> $right[ $depth ];
		}
	}

	return count( $left ) <=> count( $right );
}

/**
 * Remove one inner block and the whitespace that separated it from a sibling.
 *
 * @param Block $parent Parent block, by reference.
 * @param int   $index  Index of the inner block.
 * @return bool False when there is no inner block at that index.
 */
function remove_inner( array &$parent, int $index ): bool {
	$content = array_values( $parent['innerContent'] );
	$slots   = array_keys( $content, null, true );
	$at      = $slots[ $index ] ?? null;

	if ( null === $at || ! isset( $parent['innerBlocks'][ $index ] ) ) {
		return false;
	}

	$gap = static fn ( int $key ): bool => isset( $content[ $key ] ) && is_string( $content[ $key ] ) && '' === trim( $content[ $key ] );

	unset( $content[ $at ] );
	if ( $index > 0 && $gap( $at - 1 ) ) {
		unset( $content[ $at - 1 ] );
	} elseif ( isset( $slots[ $index + 1 ] ) && $gap( $at + 1 ) ) {
		unset( $content[ $at + 1 ] );
	}

	$inner = $parent['innerBlocks'];
	array_splice( $inner, $index, 1 );

	$parent['innerBlocks']  = $inner;
	$parent['innerContent'] = array_values( $content );
	$parent['innerHTML']    = implode( '', array_filter( $parent['innerContent'], 'is_string' ) );

	return true;
}

/**
 * The error for omit that cannot be applied, listing the labels that can go.
 *
 * @param string   $message What is wrong.
 * @param string[] $labels  Labels that can be omitted.
 */
function invalid_omit( string $message, array $labels ): WP_Error {
	return new WP_Error(
		'voyager_open_blocks_invalid_omit',
		sprintf( '%s Labels you can omit: %s.', $message, $labels ? implode( ', ', array_map( 'wp_json_encode', $labels ) ) : 'none' ),
		[
			'status' => 400,
			'labels' => $labels,
		]
	);
}

/**
 * The error for copy that cannot be applied, listing the labels that can.
 *
 * @param string   $message What is wrong.
 * @param string[] $labels  Valid labels.
 */
function invalid_copy( string $message, array $labels ): WP_Error {
	return new WP_Error(
		'voyager_open_blocks_invalid_copy',
		sprintf( '%s Valid labels: %s.', $message, $labels ? implode( ', ', array_map( 'wp_json_encode', $labels ) ) : 'none' ),
		[
			'status' => 400,
			'labels' => $labels,
		]
	);
}

/**
 * Replace one text block's saved text, escaped.
 *
 * The element is found with the block type's own selector for its text
 * attribute (h1-h6, p, a or button, li), as core does when it renders a
 * binding. Everything between the element's tags is replaced, so inline
 * formatting goes too. A list item's own text sits before its nested list,
 * so only the first chunk of its inner content is touched.
 *
 * @param Block  $block Block, by reference.
 * @param string $text  Plain text.
 * @return true|WP_Error
 */
function replace_text( array &$block, string $text ) {
	$name     = (string) $block['blockName'];
	$type     = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$schema   = $type ? ( $type->attributes[ TEXT_ATTRIBUTES[ $name ] ] ?? null ) : null;
	$selector = is_array( $schema ) ? Cast\to_string( $schema['selector'] ?? '' ) : '';
	$content  = $block['innerContent'];
	$first    = $content[0] ?? null;
	$range    = null;

	if ( '' !== $selector && is_string( $first ) ) {
		$tags      = array_map( static fn ( string $tag ): string => strtoupper( trim( $tag ) ), explode( ',', $selector ) );
		$processor = new class( $first ) extends \WP_HTML_Tag_Processor {
			/**
			 * Byte range between the first matching element's opener and its closer.
			 *
			 * @param string[] $tags       Upper-case tag names.
			 * @param bool     $open_ended An inner block follows, so the closer may be in a later chunk.
			 * @return array{0: int, 1: int}|null
			 */
			public function inner_range( array $tags, bool $open_ended ): ?array {
				do {
					if ( ! $this->next_tag() ) {
						return null;
					}
				} while ( ! in_array( $this->get_tag(), $tags, true ) );

				$tag = $this->get_tag();
				$this->set_bookmark( 'copy' );
				$start = $this->bookmarks['copy']->start + $this->bookmarks['copy']->length;
				$depth = 0;

				while ( $this->next_token() ) {
					if ( '#tag' !== $this->get_token_type() || $tag !== $this->get_tag() ) {
						continue;
					}
					if ( ! $this->is_tag_closer() ) {
						++$depth;
						continue;
					}
					if ( $depth-- > 0 ) {
						continue;
					}
					$this->set_bookmark( 'copy' );
					return [ $start, $this->bookmarks['copy']->start ];
				}

				return $open_ended ? [ $start, strlen( $this->html ) ] : null;
			}
		};
		$range     = $processor->inner_range( $tags, count( $content ) > 1 );
	}

	if ( null === $range || ! is_string( $first ) ) {
		$label = metadata( $block )['name'] ?? null;

		return new WP_Error(
			'voyager_open_blocks_invalid_copy',
			sprintf( 'The saved HTML of %s%s has no element to hold text.', $name, is_string( $label ) ? sprintf( ' "%s"', $label ) : '' ),
			[ 'status' => 400 ]
		);
	}

	[ $start, $end ]          = $range;
	$block['innerContent'][0] = substr( $first, 0, $start )
		. htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' )
		. substr( $first, $end );
	$block['innerHTML']       = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

	return true;
}
