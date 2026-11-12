<?php
/**
 * The SpinupWP adapter (inc/cache/spinupwp.php): when it is active, what it
 * calls, that a purge reaches it, and that a SpinupWP purge that throws
 * leaves the rest of the purge alone.
 *
 * Every test runs in its own process. Loading SpinupWP means defining its
 * functions (tests/spinupwp-stubs.php), and turning its page cache on means
 * defining a constant, and neither can be undone.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\SpinupWP;
use VoyagerOpenBlocks\Purge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SpinupWpTest extends TestCase {

	use PurgeRunner;

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']        = [];
		$GLOBALS['voyager_test_spinupwp_calls'] = [];
		$GLOBALS['voyager_test_triggered']      = [];
		putenv( 'SPINUPWP_CACHE_PATH' );
		require_once dirname( __DIR__ ) . '/fail-soft-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/fail-soft.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/spinupwp.php';
	}

	protected function tearDown(): void {
		putenv( 'SPINUPWP_CACHE_PATH' );
		parent::tearDown();
	}

	/**
	 * Load SpinupWP, with its page cache on, as a SpinupWP site has it.
	 */
	private function load_spinupwp(): void {
		require_once dirname( __DIR__ ) . '/spinupwp-stubs.php';
		define( 'SPINUPWP_CACHE_PATH', '/cache/example.com' );
	}

	/**
	 * The purges SpinupWP got.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_spinupwp_calls'];
	}

	public function test_it_names_itself_and_purges_single_pages(): void {
		$adapter = new SpinupWP();

		$this->assertSame( 'spinupwp', $adapter->id() );
		$this->assertSame( 'SpinupWP', $adapter->label() );
		$this->assertTrue( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_and_calls_nothing_when_spinupwp_is_missing(): void {
		// The page cache "on" with no plugin to purge it: only the missing functions say no.
		define( 'SPINUPWP_CACHE_PATH', '/cache/example.com' );
		$adapter = new SpinupWP();

		$this->assertFalse( function_exists( 'spinupwp_purge_url' ), 'SpinupWP was loaded by an earlier test in this process.' );
		$this->assertFalse( $adapter->active() );

		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();

		$this->assertSame( [], $this->calls() );
	}

	public function test_it_is_inactive_while_spinupwp_says_its_page_cache_is_off(): void {
		require_once dirname( __DIR__ ) . '/spinupwp-stubs.php';
		$adapter = new SpinupWP();

		$this->assertFalse( $adapter->active(), 'No SPINUPWP_CACHE_PATH, as a constant or in the environment.' );

		putenv( 'SPINUPWP_CACHE_PATH=/cache/example.com' );
		$this->assertTrue( $adapter->active(), 'SpinupWP reads the path from the environment too.' );
	}

	public function test_it_is_active_with_the_cache_path_constant(): void {
		$this->load_spinupwp();

		$this->assertTrue( ( new SpinupWP() )->active() );
	}

	public function test_purge_urls_purges_each_url_once(): void {
		$this->load_spinupwp();

		( new SpinupWP() )->purge_urls( self::URLS );

		$this->assertSame(
			[
				[ 'spinupwp_purge_url', 'https://example.com/a/' ],
				[ 'spinupwp_purge_url', 'https://example.com/b/' ],
				[ 'spinupwp_purge_url', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_purge_all_purges_the_site(): void {
		$this->load_spinupwp();

		( new SpinupWP() )->purge_all();

		$this->assertSame( [ [ 'spinupwp_purge_site', null ] ], $this->calls() );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_spinupwp',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_spinupwp( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( SpinupWP::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_spinupwp( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_spinupwp( 'not a list' ) );
	}

	public function test_a_purge_past_fifty_pages_purges_the_site(): void {
		$this->load_spinupwp();
		$this->load_purge();

		$this->assertSame( 1, $this->change_phone_on( 51 ) );

		$this->assertSame( [ [ 'spinupwp_purge_site', null ] ], $this->calls(), 'Page 51 is reached only by purge_all.' );
		$record = $this->last_purge();
		$this->assertTrue( $record['purge_all'] );
		$this->assertSame( [ 'spinupwp' ], $record['adapters'] );
	}

	public function test_a_purge_of_a_few_pages_purges_each_page_and_the_front_page(): void {
		$this->load_spinupwp();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame(
			[
				[ 'spinupwp_purge_url', 'https://example.com/page-1/' ],
				[ 'spinupwp_purge_url', 'https://example.com/page-2/' ],
				[ 'spinupwp_purge_url', 'https://example.com/' ],
			],
			$this->calls()
		);
	}

	public function test_a_purge_skips_it_while_its_page_cache_is_off(): void {
		require_once dirname( __DIR__ ) . '/spinupwp-stubs.php';
		$this->load_purge();

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( [], $this->last_purge()['adapters'] );
	}

	public function test_a_page_spinupwp_fails_to_purge_leaves_the_rest_of_the_purge_alone(): void {
		$this->load_spinupwp();
		$this->load_purge();
		$bystander                              = $this->add_bystander();
		$GLOBALS['voyager_test_spinupwp_throw'] = 'https://example.com/page-1/';

		$this->change_phone_on( 2 );

		$this->assertCount( 1, $this->purge_actions(), 'voyager_open_blocks_purge fired before any cache was told.' );
		$this->assertSame(
			[ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ],
			array_column( $this->calls(), 1 ),
			'The pages after the one that threw are still purged.'
		);
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy(), 'The next adapter is still told.' );
		$record = $this->last_purge();
		$this->assertSame( [ 'spinupwp' ], $record['failed'] );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
		$this->assertCount( 1, $GLOBALS['voyager_test_triggered'] );
		$this->assertStringContainsString( 'SpinupWP could not purge https://example.com/page-1/', $GLOBALS['voyager_test_triggered'][0][0] );
		$this->assertSame( E_USER_WARNING, $GLOBALS['voyager_test_triggered'][0][1] );
	}

	public function test_a_site_purge_that_throws_is_reported_and_recorded_as_failed(): void {
		$this->load_spinupwp();
		$this->load_purge();
		$bystander                              = $this->add_bystander();
		$GLOBALS['voyager_test_spinupwp_throw'] = 'site';

		$this->change_phone_on( 51 );

		$this->assertCount( 1, $this->purge_actions() );
		$this->assertSame( [ 'all' ], $bystander->getArrayCopy() );
		$this->assertSame( [ 'spinupwp' ], $this->last_purge()['failed'] );
		$this->assertStringContainsString( 'SpinupWP could not purge every page', $GLOBALS['voyager_test_triggered'][0][0] ?? '' );
	}

	public function test_a_site_can_remove_it_through_the_filter(): void {
		$this->load_spinupwp();
		$this->load_purge();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static fn ( $adapters ) => array_values(
				array_filter(
					(array) $adapters,
					static fn ( $adapter ): bool => ! ( $adapter instanceof Cache\Adapter && 'spinupwp' === $adapter->id() )
				)
			),
			20
		);

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
	}
}
