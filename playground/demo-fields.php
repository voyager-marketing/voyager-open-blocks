<?php
/**
 * Plugin Name: Voyager Open Blocks demo fields
 * Description: Demo-only bindable post meta for the voyager/post-meta-text examples. Installed as an mu-plugin by playground/seed.php; never part of a real site.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'voyager_open_blocks_register_bindable_meta' ) ) {
			return;
		}

		voyager_open_blocks_register_bindable_meta( 'page', 'demo_eyebrow', 'Demo eyebrow' );
		voyager_open_blocks_register_bindable_meta( 'page', 'demo_summary', 'Demo summary' );
		voyager_open_blocks_register_bindable_meta( 'page', 'demo_stats', 'Demo stats (JSON)' );
	}
);
