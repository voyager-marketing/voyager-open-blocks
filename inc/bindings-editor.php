<?php
/**
 * Editor half of the two binding sources, and the editor hints.
 *
 * Server registration alone gives the editor a source name and label. This
 * adds the field list for the bindings picker and live values in the canvas,
 * and lets voyager/post-meta-text write a bound field back to post meta.
 * Plain script with wp.* globals, so there is no extra build entry.
 *
 * It also loads assets/editor/hints.js and hints.css, which tell an editor
 * what a bound block shows and what pattern content still needs replacing.
 * Both are editor only: saved markup and the front end never change.
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

const BINDINGS_HANDLE = 'voyager-open-blocks-bindings';

const HINTS_HANDLE = 'voyager-open-blocks-editor-hints';

/**
 * Block metadata names that mark the contact section's form slot.
 *
 * patterns/intents/contact-section.json names the column "Form slot" and the
 * group inside it, which holds an email fallback, "Form". While that group is
 * still in that column, the slot has not been given a form block.
 */
const FORM_SLOT = [
	'slot'     => 'Form slot',
	'fallback' => 'Form',
];

/**
 * Site Data keys that the screen does not store but derives from one it does.
 */
const DERIVED_FROM = [
	'phone_link'       => 'phone',
	'email_link'       => 'email',
	'address_locality' => 'address_city',
];

/**
 * Site Data keys that come from Settings > General, and that screen's field ID.
 */
const GENERAL_FIELDS = [
	'company' => 'blogname',
	'tagline' => 'blogdescription',
];

/**
 * Enqueue the editor scripts with their data.
 */
function enqueue(): void {
	$site_data = SiteData\owned();
	$post_meta = PostMetaText\owned();
	$deps      = [ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-core-data', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n' ];

	if ( $site_data || $post_meta ) {
		$fields = [];
		foreach ( SiteData\fields() as $key => $field ) {
			$fields[] = [
				'key'   => $key,
				'label' => $field['label'],
			];
		}

		wp_enqueue_script(
			BINDINGS_HANDLE,
			plugins_url( 'assets/bindings-editor.js', VOYAGER_OPEN_BLOCKS_FILE ),
			[ 'wp-blocks', 'wp-core-data', 'wp-block-editor' ],
			VOYAGER_OPEN_BLOCKS_VERSION,
			true
		);

		wp_add_inline_script(
			BINDINGS_HANDLE,
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

		// The hints read describe() from the bindings script, so it loads first.
		$deps[] = BINDINGS_HANDLE;
	}

	// The placeholder hints apply whoever owns the sources, so this always loads.
	wp_enqueue_script(
		HINTS_HANDLE,
		plugins_url( 'assets/editor/hints.js', VOYAGER_OPEN_BLOCKS_FILE ),
		$deps,
		VOYAGER_OPEN_BLOCKS_VERSION,
		true
	);

	wp_add_inline_script(
		HINTS_HANDLE,
		'window.voyagerOpenBlocksHints = ' . wp_json_encode( hints_data() ) . ';',
		'before'
	);

	wp_set_script_translations( HINTS_HANDLE, 'voyager-open-blocks' );
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue' );

/**
 * Enqueue the hint styles in the editor, including its canvas iframe.
 *
 * enqueue_block_assets is the hook that reaches the iframe. It fires on the
 * front end too, so the is_admin() check keeps the file off every public page.
 */
function enqueue_styles(): void {
	if ( ! is_admin() ) {
		return;
	}

	wp_enqueue_style(
		HINTS_HANDLE,
		plugins_url( 'assets/editor/hints.css', VOYAGER_OPEN_BLOCKS_FILE ),
		[],
		VOYAGER_OPEN_BLOCKS_VERSION
	);
}
add_action( 'enqueue_block_assets', __NAMESPACE__ . '\enqueue_styles' );

/**
 * Data for assets/editor/hints.js.
 *
 * @return array{canManageOptions: bool, siteDataLinks: array<string, array{screen: string, url: string}>, placeholderImages: list<string>, formSlot: array{slot: string, fallback: string}}
 */
function hints_data(): array {
	$links = [];

	if ( SiteData\owned() ) {
		foreach ( array_keys( SiteData\fields() ) as $key ) {
			$link = site_data_link( $key );
			if ( null !== $link ) {
				$links[ $key ] = $link;
			}
		}
	}

	return [
		'canManageOptions'  => current_user_can( 'manage_options' ),
		'siteDataLinks'     => $links,
		'placeholderImages' => placeholder_images(),
		'formSlot'          => FORM_SLOT,
	];
}

/**
 * Where a Site Data key is set: the settings screen and its field.
 *
 * Stored keys open Settings > Site Data at their field. Derived keys open the
 * field they come from. The company name and tagline are the site title and
 * tagline, so they open Settings > General. The year and the copyright line
 * always have a value, so they get no link.
 *
 * @param string $key Binding key.
 * @return array{screen: string, url: string}|null Null for an unknown key or one that is never empty.
 */
function site_data_link( string $key ): ?array {
	$fields = SiteData\fields();

	if ( ! isset( $fields[ $key ] ) ) {
		return null;
	}

	if ( isset( GENERAL_FIELDS[ $key ] ) ) {
		return [
			'screen' => 'general',
			'url'    => admin_url( 'options-general.php' ) . '#' . GENERAL_FIELDS[ $key ],
		];
	}

	$option = $fields[ DERIVED_FROM[ $key ] ?? $key ]['option'] ?? null;

	if ( null === $option ) {
		return null;
	}

	return [
		'screen' => 'site-data',
		'url'    => admin_url( 'options-general.php?page=voyager-site-data' ) . '#' . $option,
	];
}

/**
 * URLs of the pack's placeholder images, as the patterns insert them.
 *
 * inc/patterns.php swaps its assets token for plugins_url( 'assets/' ), so an
 * image the logo marquee inserted has one of these URLs. assets/editor/hints.js
 * matches an image by the end of the path (assets/placeholders/<file>), so a
 * copy made on another host or under another plugin folder still counts.
 *
 * @return list<string>
 */
function placeholder_images(): array {
	$base  = plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE );
	$files = glob( VOYAGER_OPEN_BLOCKS_DIR . '/assets/placeholders/*.svg' ) ?: [];
	$urls  = [];

	sort( $files );

	foreach ( $files as $file ) {
		$urls[] = $base . 'placeholders/' . basename( $file );
	}

	return $urls;
}
