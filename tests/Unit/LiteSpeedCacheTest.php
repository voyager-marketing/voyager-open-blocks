<?php
/**
 * The LiteSpeed Cache adapter (inc/cache/litespeed-cache.php): when it is
 * active, what it tells LiteSpeed, that a purge reaches it, and that a
 * LiteSpeed purge that throws leaves the rest of the purge alone.
 *
 * Every test runs in its own process. LiteSpeed Cache says its page cache is
 * on with constants (tests/litespeed-cache-stubs.php), which cannot be
 * undefined.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\LiteSpeed_Cache;
use VoyagerOpenBlocks\Purge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class LiteSpeedCacheTest extends TestCase {

	use PurgeRunner;

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']         = [];
		$GLOBALS['voyager_test_actions']         = [];
		$GLOBALS['voyager_test_listeners']       = [];
		$GLOBALS['voyager_test_litespeed_calls'] = [];
		$GLOBALS['voyager_test_triggered']       = [];
		require_once dirname( __DIR__ ) . '/litespeed-cache-stubs.php';
		require_once dirname( __DIR__ ) . '/fail-soft-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/fail-soft.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/litespeed-cache.php';
		$this->assertStringEndsWith(
			'litespeed-cache-stubs.php',
			(string) ( new \ReflectionFunction( 'do_action' ) )->getFileName(),
			'do_action() must run listeners, or LiteSpeed never hears its actions here.'
		);
	}

	/**
	 * What LiteSpeed Cache heard.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_litespeed_calls'];
	}

	public function test_it_names_itself_and_purges_single_pages(): void {
		$adapter = new LiteSpeed_Cache();

		$this->assertSame( 'litespeed-cache', $adapter->id() );
		$this->assertSame( 'LiteSpeed Cache', $adapter->label() );
		$this->assertTrue( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_when_litespeed_cache_is_missing(): void {
		// The cache "on" with no plugin loaded: only the missing LSCWP_V says no.
		define( 'LITESPEED_ON', true );

		$this->assertFalse( ( new LiteSpeed_Cache() )->active() );
	}

	public function test_it_is_inactive_while_its_page_cache_is_off(): void {
		voyager_test_load_litespeed_cache( false );

		$this->assertFalse( ( new LiteSpeed_Cache() )->active(), 'No LITESPEED_ON: the server does not allow it, or Enable Cache is off.' );
	}

	public function test_it_is_active_while_its_page_cache_is_on(): void {
		voyager_test_load_litespeed_cache();

		$this->assertTrue( ( new LiteSpeed_Cache() )->active() );
	}

	public function test_it_is_inactive_while_disable_all_features_is_on(): void {
		voyager_test_load_litespeed_cache();
		define( 'LITESPEED_DISABLE_ALL', true );

		$this->assertFalse( ( new LiteSpeed_Cache() )->active() );
	}

	public function test_it_stays_active_when_disable_all_is_defined_false(): void {
		voyager_test_load_litespeed_cache();
		define( 'LITESPEED_DISABLE_ALL', false );

		$this->assertTrue( ( new LiteSpeed_Cache() )->active(), 'Core::init() bails only when it is true.' );
	}

	public function test_purge_urls_tells_litespeed_each_url_once(): void {
		voyager_test_load_litespeed_cache();

		( new LiteSpeed_Cache() )->purge_urls( self::URLS );

		$this->assertSame(
			[
				[ 'litespeed_purge_url', 'https://example.com/a/' ],
				[ 'litespeed_purge_url', 'https://example.com/b/' ],
				[ 'litespeed_purge_url', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_purge_all_tells_litespeed_to_purge_everything_and_why(): void {
		voyager_test_load_litespeed_cache();

		( new LiteSpeed_Cache() )->purge_all();

		$this->assertSame( [ [ 'litespeed_purge_all', LiteSpeed_Cache::REASON ] ], $this->calls() );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_litespeed_cache',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_litespeed_cache( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( LiteSpeed_Cache::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_litespeed_cache( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_litespeed_cache( 'not a list' ) );
	}

	public function test_a_purge_past_fifty_pages_purges_everything(): void {
		voyager_test_load_litespeed_cache();
		$this->load_purge();

		$this->assertSame( 1, $this->change_phone_on( 51 ) );

		$this->assertSame( [ [ 'litespeed_purge_all', LiteSpeed_Cache::REASON ] ], $this->calls(), 'Page 51 is reached only by purge_all.' );
		$record = $this->last_purge();
		$this->assertTrue( $record['purge_all'] );
		$this->assertSame( [ 'litespeed-cache' ], $record['adapters'] );
	}

	public function test_a_purge_of_a_few_pages_purges_each_page_and_the_front_page(): void {
		voyager_test_load_litespeed_cache();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame(
			[
				[ 'litespeed_purge_url', 'https://example.com/page-1/' ],
				[ 'litespeed_purge_url', 'https://example.com/page-2/' ],
				[ 'litespeed_purge_url', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_a_purge_skips_it_while_its_page_cache_is_off(): void {
		voyager_test_load_litespeed_cache( false );
		$this->load_purge();

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( [], $this->last_purge()['adapters'] );
	}

	public function test_a_page_litespeed_fails_to_purge_leaves_the_rest_of_the_purge_alone(): void {
		voyager_test_load_litespeed_cache();
		$this->load_purge();
		$bystander                               = $this->add_bystander();
		$GLOBALS['voyager_test_litespeed_throw'] = 'https://example.com/';

		$this->change_phone_on( 2 );

		$this->assertCount( 1, $this->purge_actions(), 'voyager_open_blocks_purge fired before any cache was told.' );
		$this->assertSame(
			[ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ],
			array_column( $this->calls(), 1 )
		);
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy(), 'The next adapter is still told.' );
		$record = $this->last_purge();
		$this->assertSame( [ 'litespeed-cache' ], $record['failed'] );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
		$this->assertCount( 1, $GLOBALS['voyager_test_triggered'] );
		$this->assertStringContainsString( 'LiteSpeed Cache could not purge https://example.com/', $GLOBALS['voyager_test_triggered'][0][0] );
	}

	public function test_a_purge_of_everything_that_throws_is_reported_and_recorded_as_failed(): void {
		voyager_test_load_litespeed_cache();
		$this->load_purge();
		$bystander                               = $this->add_bystander();
		$GLOBALS['voyager_test_litespeed_throw'] = 'all';

		$this->change_phone_on( 51 );

		$this->assertCount( 1, $this->purge_actions() );
		$this->assertSame( [ 'all' ], $bystander->getArrayCopy() );
		$this->assertSame( [ 'litespeed-cache' ], $this->last_purge()['failed'] );
		$this->assertStringContainsString( 'LiteSpeed Cache could not purge every page', $GLOBALS['voyager_test_triggered'][0][0] ?? '' );
	}

	public function test_a_site_can_remove_it_through_the_filter(): void {
		voyager_test_load_litespeed_cache();
		$this->load_purge();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static fn ( $adapters ) => array_values(
				array_filter(
					(array) $adapters,
					static fn ( $adapter ): bool => ! ( $adapter instanceof Cache\Adapter && 'litespeed-cache' === $adapter->id() )
				)
			),
			20
		);

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
	}
}
