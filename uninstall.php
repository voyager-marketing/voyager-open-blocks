<?php
/**
 * Uninstall Voyager Open Blocks.
 *
 * Removes only the plugin's own interface state: the flag that records Site
 * Data was started, and each user's dismissal of the first-run notice.
 *
 * Kept on purpose:
 * - The Site Data values (`voyager_site_*`). The voyager-blocks plugin reads
 *   the same options, and they are the site owner's business details.
 * - The bindable post meta rows (`voyager_open_blocks_bindable_meta`) and the
 *   post meta itself. Both are the site's content, not plugin state.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the onboarding state from the current site.
 */
function voyager_open_blocks_uninstall_site(): void {
	global $wpdb;

	delete_option( 'voyager_open_blocks_site_data_started' );

	// The dismissal is a user option, stored per site under the site's table prefix.
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'voyager_open_blocks_site_data_notice_dismissed', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $voyager_open_blocks_site_id ) {
		switch_to_blog( (int) $voyager_open_blocks_site_id );
		voyager_open_blocks_uninstall_site();
		restore_current_blog();
	}
} else {
	voyager_open_blocks_uninstall_site();
}
