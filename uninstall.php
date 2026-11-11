<?php
/**
 * Uninstall Voyager Open Blocks.
 *
 * Removes only the plugin's own state: the flag that records Site Data was
 * started, each user's dismissal of the first-run notice, and the usage index
 * (the `_voyager_open_blocks_uses` and `_voyager_open_blocks_refs` post meta,
 * its options and its scheduled events), and the record of the last purge.
 * The index is read from the content, and activation builds it again.
 *
 * Kept on purpose:
 * - The Site Data values (`voyager_site_*`). The voyager-blocks plugin reads
 *   the same options, and they are the site owner's business details.
 * - The bindable post meta rows (`voyager_open_blocks_bindable_meta`) and the
 *   post meta those fields hold. Both are the site's content, not plugin state.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the onboarding state, the usage index and the last purge from the current site.
 */
function voyager_open_blocks_uninstall_site(): void {
	delete_option( 'voyager_open_blocks_site_data_started' );

	delete_post_meta_by_key( '_voyager_open_blocks_uses' );
	delete_post_meta_by_key( '_voyager_open_blocks_refs' );
	delete_option( 'voyager_open_blocks_theme_uses' );
	delete_option( 'voyager_open_blocks_usage_version' );
	delete_option( 'voyager_open_blocks_usage_cursor' );
	delete_option( 'voyager_open_blocks_usage_queue' );
	delete_option( 'voyager_open_blocks_last_purge' );
	wp_clear_scheduled_hook( 'voyager_open_blocks_usage_backfill' );
	wp_clear_scheduled_hook( 'voyager_open_blocks_usage_theme' );
	wp_clear_scheduled_hook( 'voyager_open_blocks_usage_queue' );

	// The dismissal is a user option, stored per site under the site's table prefix.
	$db = $GLOBALS['wpdb'] ?? null;
	if ( $db instanceof wpdb ) {
		delete_metadata( 'user', 0, $db->get_blog_prefix() . 'voyager_open_blocks_site_data_notice_dismissed', '', true );
	}
}

if ( is_multisite() ) {
	// With 'fields' => 'ids', get_sites() returns the site IDs as integers.
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $voyager_open_blocks_site_id ) {
		switch_to_blog( $voyager_open_blocks_site_id );
		voyager_open_blocks_uninstall_site();
		restore_current_blog();
	}
} else {
	voyager_open_blocks_uninstall_site();
}
