<?php
/**
 * Read and write a post's blocks by path.
 *
 * A path is the list of indices from the top level down, written "2/0/1":
 * the second child of the first child of the third top-level block. The
 * top level skips the whitespace-only freeform blocks parse_blocks() makes
 * between blocks, so indices match what an editor shows.
 *
 * The abilities in inc/abilities.php are thin adapters over these functions.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\BlockTree;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse markup into blocks, dropping whitespace-only freeform blocks at the top level.
 *
 * @param string $markup Block markup.
 * @return array<int, array<string, mixed>>
 */
function parse( string $markup ): array {
	return array_values(
		array_filter(
			parse_blocks( $markup ),
			static fn ( array $block ): bool => null !== $block['blockName'] || '' !== trim( (string) $block['innerHTML'] )
		)
	);
}

/**
 * Serialize top-level blocks with one blank line between them.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 */
function to_markup( array $blocks ): string {
	return implode( "\n\n", array_map( 'serialize_block', $blocks ) );
}

/**
 * Load a post's blocks.
 *
 * @param int $post_id Post ID.
 * @return array<int, array<string, mixed>>|WP_Error
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
 * @param int                              $post_id Post ID.
 * @param array<int, array<string, mixed>> $blocks  Blocks.
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
 * @param string|array<int, int|string> $path Path.
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
			return new WP_Error( 'voyager_open_blocks_invalid_path', sprintf( 'Invalid block path segment "%s".', (string) $part ), [ 'status' => 400 ] );
		}
		$ints[] = (int) $part;
	}

	return $ints;
}

/**
 * Get a reference to the block at a path.
 *
 * @param array<int, array<string, mixed>> $blocks Blocks, by reference.
 * @param int[]                            $path   Parsed path.
 * @return array<string, mixed>|null Reference to the block, or null.
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
		$current = &$current[ $index ]['innerBlocks'];
	}

	return $null;
}

/**
 * Describe a block tree: one row per block with its path, name and bindings.
 *
 * @param array<int, array<string, mixed>> $blocks Blocks.
 * @param string                           $prefix Path of the parent, '' at the top.
 * @return array<int, array<string, mixed>>
 */
function describe( array $blocks, string $prefix = '' ): array {
	$rows = [];

	foreach ( $blocks as $index => $block ) {
		$path = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$row  = [
			'path'  => $path,
			'block' => $block['blockName'] ?? 'core/freeform',
		];

		if ( ! empty( $block['attrs']['metadata']['name'] ) ) {
			$row['label'] = (string) $block['attrs']['metadata']['name'];
		}
		if ( ! empty( $block['attrs']['metadata']['bindings'] ) ) {
			$row['bindings'] = $block['attrs']['metadata']['bindings'];
		}
		if ( 'core/pattern' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['slug'] ) ) {
			$row['pattern'] = (string) $block['attrs']['slug'];
		}

		$rows[] = $row;

		if ( ! empty( $block['innerBlocks'] ) ) {
			array_push( $rows, ...describe( $block['innerBlocks'], $path ) );
		}
	}

	return $rows;
}

/**
 * Check what WordPress can check about blocks without a JavaScript runtime.
 *
 * Every block type must be registered, and every comment attribute the
 * server knows the schema of must be valid against it. Saved HTML is not
 * checked here: that needs the block's save() in JavaScript, which is what
 * `block-runner validate` runs.
 *
 * @param array<int, array<string, mixed>> $blocks Blocks.
 * @return array{valid: bool, errors: string[]}
 */
function check( array $blocks ): array {
	$errors   = [];
	$registry = \WP_Block_Type_Registry::get_instance();

	$walk = static function ( array $list, string $prefix ) use ( &$walk, &$errors, $registry ): void {
		foreach ( $list as $index => $block ) {
			$path = '' === $prefix ? (string) $index : $prefix . '/' . $index;
			$name = $block['blockName'] ?? null;

			if ( null === $name ) {
				continue;
			}

			$type = $registry->get_registered( $name );
			if ( ! $type ) {
				$errors[] = sprintf( '%s at %s is not a registered block type.', $name, $path );
				continue;
			}

			foreach ( (array) ( $block['attrs'] ?? [] ) as $attribute => $value ) {
				$schema = $type->attributes[ $attribute ] ?? null;
				if ( ! is_array( $schema ) || isset( $schema['source'] ) ) {
					continue;
				}
				$valid = rest_validate_value_from_schema( $value, array_intersect_key( $schema, array_flip( [ 'type', 'enum', 'items', 'properties' ] ) ), $attribute );
				if ( is_wp_error( $valid ) ) {
					$errors[] = sprintf( '%s at %s: %s', $name, $path, $valid->get_error_message() );
				}
			}

			$walk( $block['innerBlocks'] ?? [], $path );
		}
	};

	$walk( $blocks, '' );

	return [
		'valid'  => [] === $errors,
		'errors' => $errors,
	];
}
