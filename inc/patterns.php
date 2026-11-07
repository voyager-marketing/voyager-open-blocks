<?php
/**
 * Pattern registration.
 *
 * Each pattern is two files. patterns/intents/<name>.json holds the metadata
 * and the Block Runner intent tree; patterns/<name>.html is the block markup
 * `npm run patterns:build` assembles from it, validated before it is written.
 * Never edit the .html by hand: edit the intent and rebuild.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Patterns;

use VoyagerOpenBlocks\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder in pattern markup for this plugin's assets/ URL.
 *
 * Markup stays a plain, validatable file; the real URL is swapped in at
 * registration so the pack works from any plugin directory.
 */
const ASSETS_TOKEN = '/voyager-open-blocks-assets/';

/**
 * The string entries of an intent field, as a list.
 *
 * @param mixed $value A string or a list of strings from the intent file.
 * @return string[]
 */
function strings( mixed $value ): array {
	return array_values( array_filter( (array) $value, 'is_string' ) );
}

/**
 * The keys an intent tree binds through one source, at any depth.
 *
 * A binding is any object with `source` set to the source and a string
 * `args.key`, which is the shape of every `metadata.bindings` entry.
 *
 * @param mixed  $node   A decoded intent file, or any part of one.
 * @param string $source Binding source name.
 * @return list<string> Each key once, in the order first found.
 */
function keys_in( mixed $node, string $source ): array {
	if ( ! is_array( $node ) ) {
		return [];
	}

	$keys = [];
	$args = $node['args'] ?? null;

	if ( ( $node['source'] ?? null ) === $source && is_array( $args ) && isset( $args['key'] ) && is_string( $args['key'] ) ) {
		$keys[] = $args['key'];
	}

	foreach ( $node as $child ) {
		array_push( $keys, ...keys_in( $child, $source ) );
	}

	return array_values( array_unique( $keys ) );
}

/**
 * The keys of one source that each pattern binds, read from the intent files.
 *
 * The intents are the source of truth for the shipped patterns, so this works
 * before init and needs no block parser.
 *
 * @param string      $source Binding source name.
 * @param string|null $dir    Intents directory. Defaults to this plugin's patterns/intents.
 * @return array<string, array{title: string, keys: list<string>}> Pattern name => title and keys. Patterns that bind none of the source's keys are left out.
 */
function bound_keys( string $source, ?string $dir = null ): array {
	$dir = $dir ?? VOYAGER_OPEN_BLOCKS_DIR . '/patterns/intents';
	$out = [];

	foreach ( glob( $dir . '/*.json' ) ?: [] as $intent_file ) {
		$meta  = json_decode( (string) file_get_contents( $intent_file ), true );
		$title = is_array( $meta ) ? Cast\to_string( $meta['title'] ?? '' ) : '';

		if ( ! is_array( $meta ) || '' === $title ) {
			continue;
		}

		$keys = keys_in( $meta['intent'] ?? [], $source );
		if ( [] !== $keys ) {
			$out[ 'voyager-open/' . basename( $intent_file, '.json' ) ] = [
				'title' => $title,
				'keys'  => $keys,
			];
		}
	}

	return $out;
}

/**
 * Register the pattern category and every pattern.
 *
 * The category slug stays `voyager-open` so intents and saved content keep
 * working. The label says what the sections are for, since that is what a
 * newcomer looks for in the inserter.
 */
function register(): void {
	register_block_pattern_category(
		'voyager-open',
		[
			'label'       => __( 'Business sections (Open Blocks)', 'voyager-open-blocks' ),
			'description' => __( 'Ready-made sections that use your phone, email and address from Settings > Site Data.', 'voyager-open-blocks' ),
		]
	);

	$assets = plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE );

	foreach ( glob( VOYAGER_OPEN_BLOCKS_DIR . '/patterns/intents/*.json' ) ?: [] as $intent_file ) {
		$name   = basename( $intent_file, '.json' );
		$markup = VOYAGER_OPEN_BLOCKS_DIR . '/patterns/' . $name . '.html';
		$meta   = json_decode( (string) file_get_contents( $intent_file ), true );

		if ( ! is_array( $meta ) || ! is_readable( $markup ) || empty( $meta['title'] ) ) {
			continue;
		}

		register_block_pattern(
			'voyager-open/' . $name,
			[
				'title'         => Cast\to_string( $meta['title'] ),
				'description'   => Cast\to_string( $meta['description'] ?? '' ),
				'content'       => str_replace( ASSETS_TOKEN, $assets, (string) file_get_contents( $markup ) ),
				'categories'    => strings( $meta['categories'] ?? [ 'voyager-open' ] ),
				'keywords'      => strings( $meta['keywords'] ?? [] ),
				'blockTypes'    => strings( $meta['blockTypes'] ?? [] ),
				'viewportWidth' => Cast\to_int( $meta['viewportWidth'] ?? 1280 ),
			]
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\register' );
