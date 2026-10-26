<?php
/**
 * Plugin Name:       Voyager Open Blocks
 * Plugin URI:        https://github.com/voyager-marketing/voyager-open-blocks
 * Description:       Agent-ready blocks, binding sources and patterns. Every item ships a SKILL.md so an AI agent can read the site, author native blocks and bind them to real data.
 * Version:           0.3.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Voyager Marketing
 * Author URI:        https://voyagermark.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       voyager-open-blocks
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VOYAGER_OPEN_BLOCKS_VERSION', '0.3.0' );
define( 'VOYAGER_OPEN_BLOCKS_FILE', __FILE__ );
define( 'VOYAGER_OPEN_BLOCKS_DIR', __DIR__ );

require_once __DIR__ . '/inc/cast.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/site-data.php';
require_once __DIR__ . '/inc/post-meta-text.php';
require_once __DIR__ . '/inc/bindings-editor.php';
require_once __DIR__ . '/inc/patterns.php';
require_once __DIR__ . '/inc/block-styles.php';
require_once __DIR__ . '/inc/block-tree.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/abilities.php';

/**
 * Register every block in build/ from the generated blocks manifest.
 *
 * `npm run build` (wp-scripts build --blocks-manifest) writes the manifest, so
 * a new block is a new folder under src/ and nothing here changes. This is
 * the @wordpress/create-block bootstrap, kept in that shape so
 * `block-runner plugin inspect` recognizes the plugin as a wp-scripts host.
 */
function voyager_open_blocks_register_blocks(): void {
	if ( function_exists( 'wp_register_block_types_from_metadata_collection' ) ) {
		wp_register_block_types_from_metadata_collection( __DIR__ . '/build', __DIR__ . '/build/blocks-manifest.php' );
		return;
	}

	wp_register_block_metadata_collection( __DIR__ . '/build', __DIR__ . '/build/blocks-manifest.php' );

	$build    = __DIR__ . '/build/';
	/** @var array<string, mixed> $manifest */
	$manifest = require __DIR__ . '/build/blocks-manifest.php';
	foreach ( array_keys( $manifest ) as $block_type ) {
		register_block_type( $build . $block_type );
	}
}
add_action( 'init', 'voyager_open_blocks_register_blocks' );

/**
 * Make a post meta field bindable through voyager/post-meta-text.
 *
 * Call on init. See inc/post-meta-text.php.
 *
 * @param string $post_type Post type, or '' for every post type.
 * @param string $key       Meta key, without a leading underscore or a dot.
 * @param string $label     Label shown in the editor's binding picker.
 */
function voyager_open_blocks_register_bindable_meta( string $post_type, string $key, string $label ): bool {
	return \VoyagerOpenBlocks\PostMetaText\register_field( $post_type, $key, $label );
}
