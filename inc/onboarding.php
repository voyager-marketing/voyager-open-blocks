<?php
/**
 * First run: point a new site at Settings > Site Data.
 *
 * The pack's patterns read the phone, email and address from Site Data, and
 * show placeholder text until they are set. While every stored field is
 * empty, administrators see one notice on the Plugins screen and the
 * Dashboard that links to the settings page. There is no activation redirect.
 *
 * The notice stops for good once any field is set: the first value found
 * records a flag option, so clearing the fields later does not bring it back.
 * An administrator can also dismiss it for themselves. The dismissal is a user
 * option, which WordPress keys by site, so on multisite both the flag and the
 * dismissal are per site.
 *
 * The plugin's row on the Plugins screen also gets a Settings link.
 *
 * Both point at whichever plugin registered Settings > Site Data. When none
 * did (voyager-blocks owns the source and names its screen differently),
 * neither appears.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Onboarding;

use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\SiteData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Menu slug of Settings > Site Data. */
const PAGE = 'voyager-site-data';

/** Option set to '1' once any Site Data field has held a value on this site. */
const STARTED = 'voyager_open_blocks_site_data_started';

/** User option set to '1' when the user dismisses the notice on this site. */
const DISMISSED = 'voyager_open_blocks_site_data_notice_dismissed';

/** Nonce action, admin-post action and admin-ajax action of the dismissal. */
const ACTION = 'voyager_open_blocks_dismiss_site_data_notice';

/** Screens the notice shows on: the Plugins screen and the Dashboard. */
const SCREENS = [ 'plugins', 'dashboard' ];

/** HTML id of the notice. */
const NOTICE_ID = 'voyager-open-blocks-site-data-notice';

/**
 * The stored Site Data options, the voyager_site_* names.
 *
 * @return list<string>
 */
function option_names(): array {
	$out = [];

	foreach ( SiteData\fields() as $field ) {
		if ( isset( $field['option'] ) ) {
			$out[] = $field['option'];
		}
	}

	return $out;
}

/**
 * Whether any value is a non-blank scalar.
 *
 * @param array<mixed> $values Stored option values.
 */
function any_filled( array $values ): bool {
	foreach ( $values as $value ) {
		if ( '' !== trim( Cast\to_string( $value ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether Site Data has been started: the flag is set, or a field holds a value now.
 *
 * @param mixed        $flag   Stored STARTED option.
 * @param array<mixed> $values Stored Site Data option values.
 */
function is_started( mixed $flag, array $values ): bool {
	return '1' === Cast\to_string( $flag ) || any_filled( $values );
}

/**
 * Whether the notice shows.
 *
 * @param string $screen     Current admin screen id.
 * @param bool   $can_manage Whether the user can manage options.
 * @param bool   $dismissed  Whether the user dismissed the notice on this site.
 * @param bool   $started    Whether Site Data has been started (is_started()).
 * @param string $page_url   URL of Settings > Site Data, '' when no plugin registered it.
 */
function should_show( string $screen, bool $can_manage, bool $dismissed, bool $started, string $page_url ): bool {
	return in_array( $screen, SCREENS, true )
		&& $can_manage
		&& ! $dismissed
		&& ! $started
		&& '' !== $page_url;
}

/**
 * Whether a write to an option starts Site Data.
 *
 * @param string $option Option name.
 * @param mixed  $value  New value.
 */
function starts( string $option, mixed $value ): bool {
	return str_starts_with( $option, 'voyager_site_' )
		&& in_array( $option, option_names(), true )
		&& any_filled( [ $value ] );
}

/**
 * Site Data has been started on this site. Records the flag the first time a
 * value is found, which covers values set before this version.
 */
function started(): bool {
	$flag = get_option( STARTED, '' );

	if ( '1' === Cast\to_string( $flag ) ) {
		return true;
	}

	$values = [];
	foreach ( option_names() as $name ) {
		$values[ $name ] = get_option( $name, '' );
	}

	if ( ! is_started( $flag, $values ) ) {
		return false;
	}

	update_option( STARTED, '1' );
	return true;
}

/**
 * Record the flag as soon as a field is saved with a value, from the settings
 * screen, the REST API or code.
 *
 * @param string $option Option name.
 * @param mixed  $value  New value.
 */
function record( string $option, mixed $value ): void {
	if ( starts( $option, $value ) && '1' !== Cast\to_string( get_option( STARTED, '' ) ) ) {
		update_option( STARTED, '1' );
	}
}

/**
 * The added_option hook.
 *
 * @param string $option Option name.
 * @param mixed  $value  Value.
 */
function on_added( string $option, mixed $value ): void {
	record( $option, $value );
}
add_action( 'added_option', __NAMESPACE__ . '\on_added', 10, 2 );

/**
 * The updated_option hook.
 *
 * @param string $option    Option name.
 * @param mixed  $old_value Previous value.
 * @param mixed  $value     New value.
 */
function on_updated( string $option, mixed $old_value, mixed $value ): void {
	record( $option, $value );
}
add_action( 'updated_option', __NAMESPACE__ . '\on_updated', 10, 3 );

/**
 * URL of Settings > Site Data, or '' when no plugin registered the page for
 * this user.
 */
function page_url(): string {
	return function_exists( 'menu_page_url' ) ? menu_page_url( PAGE, false ) : '';
}

/**
 * Whether the current user dismissed the notice on this site.
 */
function dismissed(): bool {
	return '1' === Cast\to_string( get_user_option( DISMISSED ) );
}

/**
 * Print the notice where should_show() allows it.
 */
function render_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$id     = null === $screen ? '' : $screen->id;

	// The screen is checked first so nothing below runs on other screens.
	if ( ! in_array( $id, SCREENS, true ) ) {
		return;
	}

	$url = page_url();
	if ( ! should_show( $id, current_user_can( 'manage_options' ), dismissed(), started(), $url ) ) {
		return;
	}

	$dismiss_url = add_query_arg(
		[
			'action'   => ACTION,
			'_wpnonce' => wp_create_nonce( ACTION ),
		],
		admin_url( 'admin-post.php' )
	);

	printf(
		'<div id="%1$s" class="notice notice-info is-dismissible" data-nonce="%2$s"><p><strong>%3$s</strong> %4$s</p><p><a class="button button-primary" href="%5$s">%6$s</a><noscript> <a href="%7$s">%8$s</a></noscript></p></div>',
		esc_attr( NOTICE_ID ),
		esc_attr( wp_create_nonce( ACTION ) ),
		esc_html__( 'Voyager Open Blocks:', 'voyager-open-blocks' ),
		esc_html__( 'Your phone, email and address show up in the pack\'s buttons and sections. Add them once here.', 'voyager-open-blocks' ),
		esc_url( $url ),
		esc_html__( 'Add your business details', 'voyager-open-blocks' ),
		esc_url( $dismiss_url ),
		esc_html__( 'Dismiss', 'voyager-open-blocks' )
	);

	// Core's dismiss button only hides the notice. This saves the dismissal.
	wp_print_inline_script_tag(
		sprintf(
			'document.addEventListener("click",function(e){var b=e.target instanceof Element&&e.target.closest("#%1$s .notice-dismiss");if(!b||!window.ajaxurl){return;}var d=new FormData();d.append("action",%2$s);d.append("_ajax_nonce",b.closest(".notice").dataset.nonce||"");fetch(window.ajaxurl,{method:"POST",credentials:"same-origin",body:d});});',
			NOTICE_ID,
			(string) wp_json_encode( ACTION )
		)
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\render_notice' );

/**
 * Save the dismissal for the current user on this site.
 */
function dismiss(): void {
	if ( current_user_can( 'manage_options' ) ) {
		update_user_option( get_current_user_id(), DISMISSED, '1' );
	}
}

/**
 * The notice's dismiss button (admin-ajax).
 */
function dismiss_from_button(): void {
	check_ajax_referer( ACTION );
	dismiss();
	wp_send_json_success();
}
add_action( 'wp_ajax_' . ACTION, __NAMESPACE__ . '\dismiss_from_button' );

/**
 * The notice's Dismiss link, shown without JavaScript (admin-post).
 */
function dismiss_from_link(): void {
	check_admin_referer( ACTION );
	dismiss();
	wp_safe_redirect( wp_get_referer() ?: admin_url() );
	exit;
}
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\dismiss_from_link' );

/**
 * Put a Settings link first in this plugin's row on the Plugins screen.
 *
 * @param array<int|string, string> $actions     Row action links.
 * @param string                    $plugin_file Plugin path relative to the plugins directory.
 * @return array<int|string, string>
 */
function action_links( array $actions, string $plugin_file ): array {
	if ( plugin_basename( VOYAGER_OPEN_BLOCKS_FILE ) !== $plugin_file ) {
		return $actions;
	}

	$url = page_url();
	if ( '' === $url || ! current_user_can( 'manage_options' ) ) {
		return $actions;
	}

	return array_merge(
		[ 'settings' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Settings', 'voyager-open-blocks' ) ) ],
		$actions
	);
}
add_filter( 'plugin_action_links', __NAMESPACE__ . '\action_links', 10, 2 );
