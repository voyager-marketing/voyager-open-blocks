<?php
/**
 * The WP Super Cache adapter (inc/cache/wp-super-cache.php): when it is
 * active, what it calls, and that a purge reaches it.
 *
 * Every test runs in its own process. Loading WP Super Cache means defining
 * its functions (tests/wp-super-cache-stubs.php), and a function cannot be
 * undefined, so the test that WP Super Cache is missing must run where no
 * other test has loaded it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\WP_Super_Cache;
use VoyagerOpenBlocks\Purge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WpSuperCacheTest extends TestCase {

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']    = [];
		$GLOBALS['voyager_test_wpsc_calls'] = [];
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/wp-super-cache.php';
	}

	/**
	 * Load WP Super Cache, with its caching on or off.
	 *
	 * @param mixed $enabled What wp-cache-config.php set $cache_enabled to.
	 */
	private function load_wp_super_cache( mixed $enabled = true ): void {
		require_once dirname( __DIR__ ) . '/wp-super-cache-stubs.php';
		$GLOBALS['cache_enabled'] = $enabled;
	}

	/**
	 * The calls WP Super Cache got.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_wpsc_calls'];
	}

	public function test_it_names_itself_and_purges_single_pages(): void {
		$adapter = new WP_Super_Cache();

		$this->assertSame( 'wp-super-cache', $adapter->id() );
		$this->assertSame( 'WP Super Cache', $adapter->label() );
		$this->assertTrue( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_and_calls_nothing_when_wp_super_cache_is_missing(): void {
		// Caching "on" with no plugin to do it: only the missing functions say no.
		$GLOBALS['cache_enabled'] = true;
		$adapter                  = new WP_Super_Cache();

		$this->assertFalse( function_exists( 'wpsc_delete_url_cache' ), 'WP Super Cache was loaded by an earlier test in this process.' );
		$this->assertFalse( $adapter->active() );

		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();

		$this->assertSame( [], $this->calls() );
	}

	public function test_it_is_inactive_when_caching_is_off(): void {
		$adapter = new WP_Super_Cache();

		foreach ( [ false, 0, '', null ] as $enabled ) {
			$this->load_wp_super_cache( $enabled );
			$this->assertFalse( $adapter->active(), 'cache_enabled ' . var_export( $enabled, true ) );
		}

		unset( $GLOBALS['cache_enabled'] );
		$this->assertFalse( $adapter->active(), 'cache_enabled never set' );

		$this->load_wp_super_cache( true );
		$this->assertTrue( $adapter->active() );
	}

	/**
	 * Values of WPLOCKDOWN, and whether the adapter is active with it. WP Super
	 * Cache 3.1.5 writes '1' or '0' from its settings page
	 * (wp_update_lock_down() in inc/settings-forms.php) and 1 or 0 from its
	 * REST settings, and treats Lock Down as on when the constant != '0'
	 * (wpsc_register_post_hooks() in wp-cache-phase2.php).
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function lock_down_values(): array {
		return [
			"'1', from its settings page" => [ '1', false ],
			'1, from its REST settings'   => [ 1, false ],
			'true'                        => [ true, false ],
			"'0', from its settings page" => [ '0', true ],
			'0, from its REST settings'   => [ 0, true ],
			'false'                       => [ false, true ],
		];
	}

	#[DataProvider( 'lock_down_values' )]
	public function test_lock_down_switches_it_off_as_it_switches_off_wp_super_caches_own_purge( mixed $value, bool $active ): void {
		$this->load_wp_super_cache();
		define( 'WPLOCKDOWN', $value );

		$this->assertSame( $active, ( new WP_Super_Cache() )->active() );
	}

	public function test_a_purge_during_lock_down_leaves_wp_super_cache_alone(): void {
		$this->load_wp_super_cache();
		$this->load_purge();
		define( 'WPLOCKDOWN', '1' );

		$this->change_phone_on( 51 );
		$this->change_phone_on( 2 );

		$this->assertSame( [], $this->calls(), 'Lock Down is for a traffic spike: neither purge_all() nor purge_urls() may reach WP Super Cache.' );
		$record = $GLOBALS['voyager_test_options'][ Purge\LAST_OPTION ];
		$this->assertIsArray( $record );
		$this->assertSame( [], $record['adapters'] );
	}

	public function test_purge_urls_deletes_each_url_once_and_clears_nothing(): void {
		$this->load_wp_super_cache();

		( new WP_Super_Cache() )->purge_urls( self::URLS );

		$this->assertSame(
			[
				[ 'wpsc_delete_url_cache', 'https://example.com/a/' ],
				[ 'wpsc_delete_url_cache', 'https://example.com/b/' ],
				[ 'wpsc_delete_url_cache', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_purge_all_clears_with_0_on_a_single_site(): void {
		$this->load_wp_super_cache();
		$GLOBALS['voyager_test_multisite'] = false;
		$GLOBALS['voyager_test_blog_id']   = 1;

		( new WP_Super_Cache() )->purge_all();

		$this->assertSame( [ [ 'wp_cache_clear_cache', 0 ] ], $this->calls() );
	}

	public function test_purge_all_clears_only_this_site_on_multisite(): void {
		$this->load_wp_super_cache();
		$GLOBALS['voyager_test_multisite'] = true;
		$GLOBALS['voyager_test_blog_id']   = 7;

		( new WP_Super_Cache() )->purge_all();

		$this->assertSame( [ [ 'wp_cache_clear_cache', 7 ] ], $this->calls(), 'Given 0, WP Super Cache would clear every site in the network.' );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_wp_super_cache',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_wp_super_cache( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( WP_Super_Cache::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_wp_super_cache( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_wp_super_cache( 'not a list' ) );
	}

	/**
	 * Load the purge, as tests/Unit/PurgeTest.php does, with the adapter's
	 * filter already added by setUp().
	 */
	private function load_purge(): void {
		require_once dirname( __DIR__ ) . '/usage-stubs.php';
		require_once dirname( __DIR__ ) . '/purge-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
		require_once dirname( __DIR__, 2 ) . '/inc/freshness/usage.php';
		require_once dirname( __DIR__, 2 ) . '/inc/freshness/purge.php';
		$GLOBALS['voyager_test_options']    = [];
		$GLOBALS['voyager_test_actions']    = [];
		$GLOBALS['voyager_test_cleaned']    = [];
		$GLOBALS['voyager_test_permalinks'] = [];
		for ( $id = 1; $id <= 60; $id++ ) {
			$GLOBALS['voyager_test_permalinks'][ $id ] = "https://example.com/page-{$id}/";
		}
	}

	/**
	 * Change the phone and run the purge over pages 1 to $count, all showing it.
	 *
	 * @param int $count Pages that show the phone.
	 */
	private function change_phone_on( int $count ): int {
		Purge\on_option( 'voyager_site_phone' );

		return Purge\run(
			static fn ( array $rows ): array => [
				'posts'     => range( 1, $count ),
				'templates' => [],
			]
		);
	}

	public function test_a_purge_past_fifty_pages_clears_wp_super_cache(): void {
		$this->load_wp_super_cache();
		$this->load_purge();

		$this->assertSame( 1, $this->change_phone_on( 51 ) );

		$this->assertSame( range( 1, 50 ), $GLOBALS['voyager_test_cleaned'], 'The first 50 are cleaned one by one, as before.' );
		$this->assertSame( [ [ 'wp_cache_clear_cache', 0 ] ], $this->calls(), 'Page 51 is reached only by purge_all.' );
		$record = $GLOBALS['voyager_test_options'][ Purge\LAST_OPTION ];
		$this->assertIsArray( $record );
		$this->assertTrue( $record['purge_all'] );
		$this->assertSame( [ 'wp-super-cache' ], $record['adapters'] );
	}

	public function test_a_purge_of_a_few_pages_deletes_each_page_and_the_front_page(): void {
		$this->load_wp_super_cache();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame(
			[
				[ 'wpsc_delete_url_cache', 'https://example.com/page-1/' ],
				[ 'wpsc_delete_url_cache', 'https://example.com/page-2/' ],
				[ 'wpsc_delete_url_cache', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_a_purge_skips_it_while_caching_is_off(): void {
		$this->load_wp_super_cache( false );
		$this->load_purge();

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
		$record = $GLOBALS['voyager_test_options'][ Purge\LAST_OPTION ];
		$this->assertIsArray( $record );
		$this->assertSame( [], $record['adapters'] );
	}

	public function test_a_site_can_remove_it_through_the_filter(): void {
		$this->load_wp_super_cache();
		$this->load_purge();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static fn ( $adapters ) => array_values(
				array_filter(
					(array) $adapters,
					static fn ( $adapter ): bool => ! ( $adapter instanceof Cache\Adapter && 'wp-super-cache' === $adapter->id() )
				)
			),
			20
		);

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
	}
}
