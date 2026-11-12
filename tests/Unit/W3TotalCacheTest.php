<?php
/**
 * The W3 Total Cache adapter (inc/cache/w3-total-cache.php): when it is
 * active, what it calls, that a purge reaches it, and that a W3 Total Cache
 * flush that throws leaves the rest of the purge alone.
 *
 * Every test runs in its own process. Loading W3 Total Cache means defining
 * its functions (tests/w3-total-cache-stubs.php), and a function cannot be
 * undefined, so the test that W3 Total Cache is missing must run where no
 * other test has loaded it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\W3_Total_Cache;
use VoyagerOpenBlocks\Purge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class W3TotalCacheTest extends TestCase {

	use PurgeRunner;

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']       = [];
		$GLOBALS['voyager_test_w3tc_calls']    = [];
		$GLOBALS['voyager_test_w3tc_settings'] = [];
		$GLOBALS['voyager_test_triggered']     = [];
		require_once dirname( __DIR__ ) . '/fail-soft-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/fail-soft.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/w3-total-cache.php';
	}

	/**
	 * Load W3 Total Cache, with Page Cache on, as General Settings saves it.
	 */
	private function load_w3_total_cache(): void {
		require_once dirname( __DIR__ ) . '/w3-total-cache-stubs.php';
		$GLOBALS['voyager_test_w3tc_settings']['pgcache.enabled'] = true;
	}

	/**
	 * The flushes W3 Total Cache got.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_w3tc_calls'];
	}

	public function test_it_names_itself_and_purges_single_pages(): void {
		$adapter = new W3_Total_Cache();

		$this->assertSame( 'w3-total-cache', $adapter->id() );
		$this->assertSame( 'W3 Total Cache', $adapter->label() );
		$this->assertTrue( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_and_calls_nothing_when_w3_total_cache_is_missing(): void {
		// Page Cache "on" with no plugin to flush it: only the missing functions say no.
		$GLOBALS['voyager_test_w3tc_settings']['pgcache.enabled'] = true;
		$adapter = new W3_Total_Cache();

		$this->assertFalse( function_exists( 'w3tc_flush_url' ), 'W3 Total Cache was loaded by an earlier test in this process.' );
		$this->assertFalse( $adapter->active() );

		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();

		$this->assertSame( [], $this->calls() );
	}

	public function test_it_is_inactive_while_its_page_cache_is_off(): void {
		require_once dirname( __DIR__ ) . '/w3-total-cache-stubs.php';
		$adapter = new W3_Total_Cache();

		$this->assertFalse( $adapter->active(), 'No pgcache.enabled: W3 Total Cache defaults it to false.' );

		$GLOBALS['voyager_test_w3tc_settings']['pgcache.enabled'] = false;
		$this->assertFalse( $adapter->active(), 'Page Cache off under General Settings.' );

		$GLOBALS['voyager_test_w3tc_settings']['pgcache.enabled'] = '1';
		$this->assertTrue( $adapter->active(), 'get_boolean() casts, as Config::get_boolean() does.' );

		$this->assertSame( [], $this->calls(), 'Deciding flushes nothing.' );
	}

	public function test_it_is_active_with_its_page_cache_on(): void {
		$this->load_w3_total_cache();

		$this->assertTrue( ( new W3_Total_Cache() )->active() );
	}

	public function test_purge_urls_flushes_each_url_once(): void {
		$this->load_w3_total_cache();

		( new W3_Total_Cache() )->purge_urls( self::URLS );

		$this->assertSame(
			[
				[ 'w3tc_flush_url', 'https://example.com/a/' ],
				[ 'w3tc_flush_url', 'https://example.com/b/' ],
				[ 'w3tc_flush_url', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_purge_all_flushes_the_page_cache_and_never_everything(): void {
		$this->load_w3_total_cache();

		( new W3_Total_Cache() )->purge_all();

		$this->assertSame( [ [ 'w3tc_flush_posts', null ] ], $this->calls(), 'w3tc_flush_all() would also empty the object, database and minify caches.' );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_w3_total_cache',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_w3_total_cache( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( W3_Total_Cache::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_w3_total_cache( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_w3_total_cache( 'not a list' ) );
	}

	public function test_a_purge_past_fifty_pages_flushes_every_page(): void {
		$this->load_w3_total_cache();
		$this->load_purge();

		$this->assertSame( 1, $this->change_phone_on( 51 ) );

		$this->assertSame( [ [ 'w3tc_flush_posts', null ] ], $this->calls(), 'Page 51 is reached only by purge_all, and never through w3tc_flush_all().' );
		$record = $this->last_purge();
		$this->assertTrue( $record['purge_all'] );
		$this->assertSame( [ 'w3-total-cache' ], $record['adapters'] );
		$this->assertSame( [], $record['failed'] );
	}

	public function test_a_purge_of_a_few_pages_flushes_each_page_and_the_front_page(): void {
		$this->load_w3_total_cache();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame(
			[
				[ 'w3tc_flush_url', 'https://example.com/page-1/' ],
				[ 'w3tc_flush_url', 'https://example.com/page-2/' ],
				[ 'w3tc_flush_url', 'https://example.com/' ],
			],
			$this->calls()
		);
		$this->assertSame( [ 'w3-total-cache' ], $this->last_purge()['adapters'] );
	}

	public function test_a_purge_skips_it_while_its_page_cache_is_off(): void {
		require_once dirname( __DIR__ ) . '/w3-total-cache-stubs.php';
		$this->load_purge();

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( [], $this->last_purge()['adapters'] );
	}

	public function test_a_page_w3_total_cache_fails_to_flush_leaves_the_rest_of_the_purge_alone(): void {
		$this->load_w3_total_cache();
		$this->load_purge();
		$bystander                          = $this->add_bystander();
		$GLOBALS['voyager_test_w3tc_throw'] = 'https://example.com/page-1/';

		$this->change_phone_on( 2 );

		$this->assertCount( 1, $this->purge_actions(), 'voyager_open_blocks_purge fired before any cache was told.' );
		$this->assertSame(
			[ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ],
			array_column( $this->calls(), 1 ),
			'The pages after the one that threw are still flushed.'
		);
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy(), 'The next adapter is still told.' );
		$record = $this->last_purge();
		$this->assertSame( [ 'w3-total-cache' ], $record['failed'] );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
		$this->assertCount( 1, $GLOBALS['voyager_test_triggered'] );
		$this->assertStringContainsString( 'W3 Total Cache could not purge https://example.com/page-1/', $GLOBALS['voyager_test_triggered'][0][0] );
		$this->assertSame( E_USER_WARNING, $GLOBALS['voyager_test_triggered'][0][1] );
	}

	public function test_a_page_cache_flush_that_throws_is_reported_and_recorded_as_failed(): void {
		$this->load_w3_total_cache();
		$this->load_purge();
		$bystander                          = $this->add_bystander();
		$GLOBALS['voyager_test_w3tc_throw'] = 'posts';

		$this->change_phone_on( 51 );

		$this->assertCount( 1, $this->purge_actions() );
		$this->assertSame( [ 'all' ], $bystander->getArrayCopy() );
		$this->assertSame( [ 'w3-total-cache' ], $this->last_purge()['failed'] );
		$this->assertStringContainsString( 'W3 Total Cache could not purge every page', $GLOBALS['voyager_test_triggered'][0][0] ?? '' );
	}

	public function test_settings_that_cannot_be_read_are_recorded_as_failed_not_hidden(): void {
		$this->load_w3_total_cache();
		$this->load_purge();
		$bystander                          = $this->add_bystander();
		$GLOBALS['voyager_test_w3tc_throw'] = 'config';

		$this->change_phone_on( 2 );

		$this->assertSame( [], $this->calls(), 'No flush without settings.' );
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy() );
		$record = $this->last_purge();
		$this->assertSame( [ 'w3-total-cache' ], $record['failed'], 'active() threw, and the purge saw it.' );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
	}

	public function test_a_site_can_remove_it_through_the_filter(): void {
		$this->load_w3_total_cache();
		$this->load_purge();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static fn ( $adapters ) => array_values(
				array_filter(
					(array) $adapters,
					static fn ( $adapter ): bool => ! ( $adapter instanceof Cache\Adapter && 'w3-total-cache' === $adapter->id() )
				)
			),
			20
		);

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
	}
}
