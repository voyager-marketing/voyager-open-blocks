<?php
/**
 * Nginx Helper, as tests/Unit/NginxHelperTest.php needs it: the two globals
 * Nginx_Helper::define_admin_hooks() sets as the plugin loads (2.4.1),
 * $nginx_purger with Purger's purge_url( $url, $feed = true ) and
 * purge_all(), and $nginx_helper_admin with its settings in `options`.
 *
 * - voyager_test_nginx_calls  every purge: [ method, url or null, feed or null ]
 * - voyager_test_nginx_throw  a URL whose purge throws, or 'all'
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

/**
 * Load Nginx Helper, as its plugin file does.
 *
 * @param array<string, mixed> $options Settings, over the defaults Enable Purge off.
 */
function voyager_test_load_nginx_helper( array $options = [] ): void {
	$GLOBALS['nginx_helper_admin'] = new class( $options ) {
		/** @var array<string, mixed> */
		public array $options;

		/**
		 * @param array<string, mixed> $options Settings.
		 */
		public function __construct( array $options ) {
			$this->options = array_merge(
				[
					'enable_purge' => 0,
					'cache_method' => 'enable_fastcgi',
					'purge_method' => 'get_request',
					'purge_feeds'  => 1,
				],
				$options
			);
		}
	};

	$GLOBALS['nginx_purger'] = new class() {
		/**
		 * @param string $url  URL.
		 * @param bool   $feed Purge its feeds too.
		 */
		public function purge_url( $url, $feed = true ): void {
			$GLOBALS['voyager_test_nginx_calls'][] = [ 'purge_url', $url, $feed ];
			if ( ( $GLOBALS['voyager_test_nginx_throw'] ?? null ) === $url ) {
				throw new RuntimeException( "Could not purge {$url}." );
			}
		}

		public function purge_all(): void {
			$GLOBALS['voyager_test_nginx_calls'][] = [ 'purge_all', null, null ];
			if ( 'all' === ( $GLOBALS['voyager_test_nginx_throw'] ?? null ) ) {
				throw new RuntimeException( 'Could not empty the cache path.' );
			}
		}
	};
}
