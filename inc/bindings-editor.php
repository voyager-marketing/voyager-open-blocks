<?php
/**
 * Editor half of the two binding sources.
 *
 * Server registration alone gives the editor a source name and label. This
 * adds the field list for the bindings picker and live values in the canvas,
 * and lets voyager/post-meta-text write a bound field back to post meta.
 * Plain script with wp.* globals, so there is no extra build entry.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\BindingsEditor;

use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the editor script with its data.
 */
function enqueue(): void {
	$site_data = SiteData\owned();
	$post_meta = PostMetaText\owned();

	if ( ! $site_data && ! $post_meta ) {
		return;
	}

	$fields = [];
	foreach ( SiteData\fields() as $key => $field ) {
		$fields[] = [
			'key'   => $key,
			'label' => $field['label'],
		];
	}

	wp_enqueue_script(
		'voyager-open-blocks-bindings',
		plugins_url( 'assets/bindings-editor.js', VOYAGER_OPEN_BLOCKS_FILE ),
		[ 'wp-blocks', 'wp-core-data', 'wp-block-editor' ],
		VOYAGER_OPEN_BLOCKS_VERSION,
		true
	);

	wp_add_inline_script(
		'voyager-open-blocks-bindings',
		'window.voyagerOpenBlocksBindings = ' . wp_json_encode(
			[
				'siteData' => $site_data ? [
					'fields' => $fields,
					'values' => SiteData\values(),
				] : null,
				'postMeta' => $post_meta ? [
					'fields' => PostMetaText\allowed_fields(),
				] : null,
			]
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue' );
