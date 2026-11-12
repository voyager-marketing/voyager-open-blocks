<?php
/**
 * The Cloudflare adapter (inc/cache/cloudflare.php): when it is active, that
 * it purges everything whatever the purge lists, and that a Cloudflare purge
 * that throws leaves the rest of the purge alone.
 *
 * Every test runs in its own process. Loading the Cloudflare plugin means
 * declaring its Hooks class (tests/cloudflare-stubs.php), and a class cannot
 * be undeclared.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\Cloudflare;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CloudflareTest extends TestCase {

	use PurgeRunner;

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']          = [];
		$GLOBALS['voyager_test_cloudflare_calls'] = [];
		$GLOBALS['voyager_test_triggered']        = [];
		unset( $GLOBALS['cloudflareHooks'] );
		require_once dirname( __DIR__ ) . '/fail-soft-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/fail-soft.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/cloudflare.php';
	}

	/**
	 * Load the plugin as its loader does: one Hooks object, as a global.
	 */
	private function load_cloudflare(): void {
		require_once dirname( __DIR__ ) . '/cloudflare-stubs.php';
		$GLOBALS['cloudflareHooks'] = new \Cloudflare\APO\WordPress\Hooks();
	}

	/**
	 * The calls the plugin got.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_cloudflare_calls'];
	}

	public function test_it_names_itself_and_cannot_purge_single_pages(): void {
		$adapter = new Cloudflare();

		$this->assertSame( 'cloudflare', $adapter->id() );
		$this->assertSame( 'Cloudflare', $adapter->label() );
		$this->assertFalse( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_and_calls_nothing_when_the_plugin_is_missing(): void {
		$adapter = new Cloudflare();

		$this->assertFalse( class_exists( 'Cloudflare\APO\WordPress\Hooks', false ), 'The plugin was loaded by an earlier test in this process.' );
		$this->assertFalse( $adapter->active() );

		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();

		$this->assertSame( [], $this->calls() );
	}

	public function test_it_is_inactive_when_the_global_is_something_else(): void {
		require_once dirname( __DIR__ ) . '/cloudflare-stubs.php';

		$lookalike = new class() {
			public function purgeCacheEverything(): void {
				$GLOBALS['voyager_test_cloudflare_calls'][] = [ 'lookalike', null ];
			}
		};

		foreach ( [ new \stdClass(), $lookalike, 'Cloudflare\APO\WordPress\Hooks', null ] as $other ) {
			$GLOBALS['cloudflareHooks'] = $other;
			$this->assertFalse( ( new Cloudflare() )->active(), get_debug_type( $other ) );
		}
	}

	public function test_it_is_active_once_the_plugin_is_loaded(): void {
		$this->load_cloudflare();

		$this->assertTrue( ( new Cloudflare() )->active() );
	}

	public function test_purge_all_purges_everything_through_the_plugin(): void {
		$this->load_cloudflare();

		( new Cloudflare() )->purge_all();

		$this->assertSame( [ [ 'purgeCacheEverything', null ] ], $this->calls() );
	}

	public function test_purge_urls_purges_everything_too(): void {
		$this->load_cloudflare();
		$adapter = new Cloudflare();

		$adapter->purge_urls( self::URLS );
		$this->assertSame( [ [ 'purgeCacheEverything', null ] ], $this->calls(), 'The plugin cannot purge these URLs alone.' );

		$adapter->purge_urls( [] );
		$this->assertCount( 1, $this->calls(), 'No URLs, no purge.' );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_cloudflare',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_cloudflare( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( Cloudflare::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_cloudflare( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_cloudflare( 'not a list' ) );
	}

	public function test_a_purge_of_a_few_pages_purges_everything_once(): void {
		$this->load_cloudflare();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame( [ [ 'purgeCacheEverything', null ] ], $this->calls(), 'Never by post ID.' );
		$record = $this->last_purge();
		$this->assertFalse( $record['purge_all'], 'The purge listed the pages; the adapter purged everything because it has no purge by URL.' );
		$this->assertSame( [ 'cloudflare' ], $record['adapters'] );
	}

	public function test_a_purge_past_fifty_pages_purges_everything_once(): void {
		$this->load_cloudflare();
		$this->load_purge();

		$this->change_phone_on( 51 );

		$this->assertSame( [ [ 'purgeCacheEverything', null ] ], $this->calls() );
		$this->assertTrue( $this->last_purge()['purge_all'] );
	}

	public function test_a_cloudflare_purge_that_throws_is_reported_and_leaves_the_rest_of_the_purge_alone(): void {
		$this->load_cloudflare();
		$this->load_purge();
		$bystander                                = $this->add_bystander();
		$GLOBALS['voyager_test_cloudflare_throw'] = true;

		$this->change_phone_on( 2 );

		$this->assertCount( 1, $this->purge_actions(), 'voyager_open_blocks_purge fired before any cache was told.' );
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy(), 'The next adapter is still told.' );
		$record = $this->last_purge();
		$this->assertSame( [ 'cloudflare' ], $record['failed'] );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
		$this->assertStringContainsString( 'Cloudflare could not purge every page: Cloudflare answered 500.', $GLOBALS['voyager_test_triggered'][0][0] ?? '' );
	}
}
