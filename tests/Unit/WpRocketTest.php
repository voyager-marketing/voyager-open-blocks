<?php
/**
 * The WP Rocket adapter (inc/cache/wp-rocket.php): when it is active, what
 * it calls, that a purge reaches it, and that a WP Rocket purge that throws
 * leaves the rest of the purge alone.
 *
 * Every test runs in its own process. Loading WP Rocket means defining its
 * functions (tests/wp-rocket-stubs.php), and a function cannot be undefined,
 * so the test that WP Rocket is missing must run where no other test has
 * loaded it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\WP_Rocket;
use VoyagerOpenBlocks\Purge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WpRocketTest extends TestCase {

	use PurgeRunner;

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']      = [];
		$GLOBALS['voyager_test_rocket_calls'] = [];
		$GLOBALS['voyager_test_triggered']    = [];
		require_once dirname( __DIR__ ) . '/fail-soft-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/fail-soft.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/wp-rocket.php';
	}

	private function load_wp_rocket(): void {
		require_once dirname( __DIR__ ) . '/wp-rocket-stubs.php';
	}

	/**
	 * The calls WP Rocket got.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_rocket_calls'];
	}

	public function test_it_names_itself_and_purges_single_pages(): void {
		$adapter = new WP_Rocket();

		$this->assertSame( 'wp-rocket', $adapter->id() );
		$this->assertSame( 'WP Rocket', $adapter->label() );
		$this->assertTrue( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_and_calls_nothing_when_wp_rocket_is_missing(): void {
		$adapter = new WP_Rocket();

		$this->assertFalse( function_exists( 'rocket_clean_files' ), 'WP Rocket was loaded by an earlier test in this process.' );
		$this->assertFalse( $adapter->active() );

		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();

		$this->assertSame( [], $this->calls() );
	}

	public function test_it_is_active_once_wp_rocket_is_loaded(): void {
		$this->load_wp_rocket();

		$this->assertTrue( ( new WP_Rocket() )->active() );
	}

	public function test_purge_urls_passes_the_urls_through_unchanged_in_one_call(): void {
		$this->load_wp_rocket();

		( new WP_Rocket() )->purge_urls( self::URLS );

		$this->assertSame( [ [ 'rocket_clean_files', self::URLS ] ], $this->calls() );
	}

	public function test_purge_urls_with_no_urls_calls_nothing(): void {
		$this->load_wp_rocket();

		( new WP_Rocket() )->purge_urls( [] );

		$this->assertSame( [], $this->calls() );
	}

	public function test_purge_all_cleans_the_domain_in_every_language(): void {
		$this->load_wp_rocket();

		( new WP_Rocket() )->purge_all();

		$this->assertSame( [ [ 'rocket_clean_domain', '' ] ], $this->calls(), 'No language: WP Rocket cleans every language\'s home.' );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_wp_rocket',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_wp_rocket( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( WP_Rocket::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_wp_rocket( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_wp_rocket( 'not a list' ) );
	}

	public function test_a_purge_of_a_few_pages_cleans_those_pages_and_the_front_page(): void {
		$this->load_wp_rocket();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame(
			[ [ 'rocket_clean_files', [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ] ] ],
			$this->calls()
		);
		$this->assertSame( [ 'wp-rocket' ], $this->last_purge()['adapters'] );
	}

	public function test_a_purge_past_fifty_pages_cleans_the_domain(): void {
		$this->load_wp_rocket();
		$this->load_purge();

		$this->assertSame( 1, $this->change_phone_on( 51 ) );

		$this->assertSame( [ [ 'rocket_clean_domain', '' ] ], $this->calls(), 'Page 51 is reached only by purge_all.' );
		$this->assertTrue( $this->last_purge()['purge_all'] );
	}

	public function test_a_wp_rocket_purge_that_throws_is_reported_and_leaves_the_rest_of_the_purge_alone(): void {
		$this->load_wp_rocket();
		$this->load_purge();
		$bystander                            = $this->add_bystander();
		$GLOBALS['voyager_test_rocket_throw'] = 'files';

		$this->change_phone_on( 2 );

		$this->assertCount( 1, $this->purge_actions(), 'voyager_open_blocks_purge fired before any cache was told.' );
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy(), 'The next adapter is still told.' );
		$record = $this->last_purge();
		$this->assertSame( [ 'wp-rocket' ], $record['failed'] );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
		$this->assertCount( 1, $GLOBALS['voyager_test_triggered'] );
		$this->assertStringContainsString( 'WP Rocket could not purge 3 pages', $GLOBALS['voyager_test_triggered'][0][0] );
		$this->assertSame( E_USER_WARNING, $GLOBALS['voyager_test_triggered'][0][1] );
	}

	public function test_a_domain_clean_that_throws_is_reported_and_recorded_as_failed(): void {
		$this->load_wp_rocket();
		$this->load_purge();
		$bystander                            = $this->add_bystander();
		$GLOBALS['voyager_test_rocket_throw'] = 'domain';

		$this->change_phone_on( 51 );

		$this->assertSame( [ 'all' ], $bystander->getArrayCopy() );
		$this->assertSame( [ 'wp-rocket' ], $this->last_purge()['failed'] );
		$this->assertStringContainsString( 'WP Rocket could not purge every page', $GLOBALS['voyager_test_triggered'][0][0] ?? '' );
	}

	public function test_a_site_can_remove_it_through_the_filter(): void {
		$this->load_wp_rocket();
		$this->load_purge();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static fn ( $adapters ) => array_values(
				array_filter(
					(array) $adapters,
					static fn ( $adapter ): bool => ! ( $adapter instanceof Cache\Adapter && 'wp-rocket' === $adapter->id() )
				)
			),
			20
		);

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
	}
}
