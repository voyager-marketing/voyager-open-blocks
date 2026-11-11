<?php
/**
 * Plugin Name: Voyager Open Blocks purge recorder
 * Description: Test fixture for npm run e2e. Records every voyager_open_blocks_purge call. Never install it on a real site.
 *
 * blueprints/dev.json copies this into wp-content/mu-plugins of the dev site,
 * which lives in memory and is thrown away. It records each call of the
 * pack's purge action (contract 2 in docs/v2-contracts.md), with its URLs
 * and context, in the option voyager_open_blocks_test_purges. GET and DELETE
 * /wp-json/voyager-open-blocks-test/v1/purges read and clear the records,
 * for administrators only.
 *
 * @package VoyagerOpenBlocks
 */

namespace VoyagerOpenBlocksTest\PurgeRecorder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OPTION = 'voyager_open_blocks_test_purges';

add_action(
	'voyager_open_blocks_purge',
	static function ( $urls = [], $context = [] ): void {
		$records   = get_option( OPTION, [] );
		$records   = is_array( $records ) ? $records : [];
		$records[] = [
			'urls'    => is_array( $urls ) ? array_values( $urls ) : [],
			'context' => is_array( $context ) ? $context : [],
		];
		update_option( OPTION, array_slice( $records, -50 ), false );
	},
	PHP_INT_MAX,
	2
);

add_action(
	'rest_api_init',
	static function (): void {
		$admin = static function (): bool {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'voyager-open-blocks-test/v1',
			'/purges',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => static function (): array {
						$records = get_option( OPTION, [] );
						return [ 'records' => is_array( $records ) ? $records : [] ];
					},
				],
				[
					'methods'             => 'DELETE',
					'permission_callback' => $admin,
					'callback'            => static function (): array {
						delete_option( OPTION );
						return [ 'cleared' => true ];
					},
				],
			]
		);
	}
);
