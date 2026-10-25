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

/**
 * Labels (metadata.name) of the blocks whose text set_copy() can replace.
 *
 * @param array<int, array<string, mixed>> $blocks Blocks.
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
 * @param array<int, array<string, mixed>> $blocks Blocks, by reference. Unchanged on error.
 * @param array<string, string>            $copy   Label => plain text.
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
		$block  = &find( $changed, (array) parse_path( $path ) );
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
 * @param array<string, mixed> $block Block, by reference.
 * @param string               $text  Plain text.
 * @return true|WP_Error
 */
function replace_text( array &$block, string $text ) {
	$name     = (string) $block['blockName'];
	$type     = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$selector = $type ? (string) ( $type->attributes[ TEXT_ATTRIBUTES[ $name ] ]['selector'] ?? '' ) : '';
	$content  = (array) ( $block['innerContent'] ?? [] );
	$range    = null;

	if ( '' !== $selector && isset( $content[0] ) && is_string( $content[0] ) ) {
		$tags      = array_map( static fn ( string $tag ): string => strtoupper( trim( $tag ) ), explode( ',', $selector ) );
		$processor = new class( $content[0] ) extends \WP_HTML_Tag_Processor {
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

	if ( null === $range ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_copy',
			sprintf( 'The saved HTML of %s%s has no element to hold text.', $name, isset( $block['attrs']['metadata']['name'] ) ? sprintf( ' "%s"', $block['attrs']['metadata']['name'] ) : '' ),
			[ 'status' => 400 ]
		);
	}

	[ $start, $end ]          = $range;
	$block['innerContent'][0] = substr( $content[0], 0, $start )
		. htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' )
		. substr( $content[0], $end );
	$block['innerHTML']       = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

	return true;
}
