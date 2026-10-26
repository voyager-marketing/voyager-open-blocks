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
 * Register the pattern category and every pattern.
 */
function register(): void {
	register_block_pattern_category(
		'voyager-open',
		[
			'label'       => __( 'Voyager Open', 'voyager-open-blocks' ),
			'description' => __( 'Agent-ready sections from the Voyager Open Blocks pack.', 'voyager-open-blocks' ),
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
