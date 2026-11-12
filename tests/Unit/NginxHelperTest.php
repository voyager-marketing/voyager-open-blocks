<?php
/**
 * The Nginx Helper adapter (inc/cache/nginx-helper.php): when it is active,
 * what it calls, that a purge reaches it, and that an Nginx Helper purge
 * that throws leaves the rest of the purge alone.
 *
 * Every test runs in its own process, as the other adapter tests do: the
 * purge and the stubs define functions that cannot be undone.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache;
use VoyagerOpenBlocks\Cache\Nginx_Helper;
use VoyagerOpenBlocks\Purge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NginxHelperTest extends TestCase {

	use PurgeRunner;

	private const URLS = [ 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']     = [];
		$GLOBALS['voyager_test_nginx_calls'] = [];
		$GLOBALS['voyager_test_triggered']   = [];
		unset( $GLOBALS['nginx_purger'], $GLOBALS['nginx_helper_admin'] );
		require_once dirname( __DIR__ ) . '/fail-soft-stubs.php';
		require_once dirname( __DIR__ ) . '/nginx-helper-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/fail-soft.php';
		require_once dirname( __DIR__, 2 ) . '/inc/cache/nginx-helper.php';
	}

	/**
	 * Load Nginx Helper with Enable Purge on, as Settings > Nginx Helper saves it.
	 */
	private function load_nginx_helper(): void {
		voyager_test_load_nginx_helper( [ 'enable_purge' => 1 ] );
	}

	/**
	 * The purges Nginx Helper got.
	 *
	 * @return list<array{0: string, 1: mixed, 2: mixed}>
	 */
	private function calls(): array {
		return $GLOBALS['voyager_test_nginx_calls'];
	}

	public function test_it_names_itself_and_purges_single_pages(): void {
		$adapter = new Nginx_Helper();

		$this->assertSame( 'nginx-helper', $adapter->id() );
		$this->assertSame( 'Nginx Helper', $adapter->label() );
		$this->assertTrue( $adapter->supports_urls() );
	}

	public function test_it_is_inactive_and_calls_nothing_when_nginx_helper_is_missing(): void {
		$adapter = new Nginx_Helper();

		$this->assertFalse( $adapter->active() );

		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();

		$this->assertSame( [], $this->calls() );

		// Its settings with Enable Purge on, and no purger.
		$this->load_nginx_helper();
		unset( $GLOBALS['nginx_purger'] );
		$this->assertFalse( $adapter->active() );
		$GLOBALS['nginx_purger'] = new \stdClass();
		$this->assertFalse( $adapter->active(), 'A purger without purge_url() and purge_all() is not one.' );
		$adapter->purge_urls( self::URLS );
		$adapter->purge_all();
		$this->assertSame( [], $this->calls() );
	}

	public function test_it_is_inactive_while_enable_purge_is_off(): void {
		$adapter = new Nginx_Helper();

		foreach ( [ 0, '0', '', null, false ] as $off ) {
			voyager_test_load_nginx_helper( [ 'enable_purge' => $off ] );
			$this->assertFalse( $adapter->active(), 'enable_purge ' . var_export( $off, true ) );
		}

		voyager_test_load_nginx_helper();
		$this->assertFalse( $adapter->active(), 'Off by default, as Nginx Helper ships it.' );

		$this->load_nginx_helper();
		$GLOBALS['nginx_helper_admin'] = (object) [ 'options' => 'not settings' ];
		$this->assertFalse( $adapter->active() );
		unset( $GLOBALS['nginx_helper_admin'] );
		$this->assertFalse( $adapter->active(), 'A purger and no settings.' );

		$this->load_nginx_helper();
		$this->assertTrue( $adapter->active() );
	}

	public function test_purge_urls_purges_each_url_once_without_its_feeds(): void {
		$this->load_nginx_helper();

		( new Nginx_Helper() )->purge_urls( self::URLS );

		$this->assertSame(
			[
				[ 'purge_url', 'https://example.com/a/', false ],
				[ 'purge_url', 'https://example.com/b/', false ],
				[ 'purge_url', 'https://example.com/', false ],
			],
			$this->calls()
		);
	}

	public function test_purge_all_purges_everything(): void {
		$this->load_nginx_helper();

		( new Nginx_Helper() )->purge_all();

		$this->assertSame( [ [ 'purge_all', null, null ] ], $this->calls() );
	}

	public function test_the_pack_offers_it_through_the_filter(): void {
		$this->assertContains(
			'VoyagerOpenBlocks\Cache\add_nginx_helper',
			$GLOBALS['voyager_test_filters']['voyager_open_blocks_cache_adapters'][10] ?? [],
			'Loading the file adds the filter.'
		);

		$offered = Cache\add_nginx_helper( [] );
		$this->assertCount( 1, $offered );
		$this->assertInstanceOf( Nginx_Helper::class, $offered[0] );

		$other = new \stdClass();
		$this->assertSame( $other, Cache\add_nginx_helper( [ $other ] )[0], 'Adapters offered earlier are kept.' );
		$this->assertCount( 1, Cache\add_nginx_helper( 'not a list' ) );
	}

	public function test_a_purge_past_fifty_pages_purges_everything(): void {
		$this->load_nginx_helper();
		$this->load_purge();

		$this->assertSame( 1, $this->change_phone_on( 51 ) );

		$this->assertSame( [ [ 'purge_all', null, null ] ], $this->calls(), 'Page 51 is reached only by purge_all.' );
		$record = $this->last_purge();
		$this->assertTrue( $record['purge_all'] );
		$this->assertSame( [ 'nginx-helper' ], $record['adapters'] );
	}

	public function test_a_purge_of_a_few_pages_purges_each_page_and_the_front_page(): void {
		$this->load_nginx_helper();
		$this->load_purge();

		$this->change_phone_on( 2 );

		$this->assertSame(
			[
				[ 'purge_url', 'https://example.com/page-1/', false ],
				[ 'purge_url', 'https://example.com/page-2/', false ],
				[ 'purge_url', 'https://example.com/', false ],
			],
			$this->calls()
		);
	}

	public function test_a_purge_skips_it_while_enable_purge_is_off(): void {
		voyager_test_load_nginx_helper();
		$this->load_purge();

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
		$this->assertSame( [], $this->last_purge()['adapters'] );
	}

	public function test_a_page_nginx_helper_fails_to_purge_leaves_the_rest_of_the_purge_alone(): void {
		$this->load_nginx_helper();
		$this->load_purge();
		$bystander                           = $this->add_bystander();
		$GLOBALS['voyager_test_nginx_throw'] = 'https://example.com/page-2/';

		$this->change_phone_on( 2 );

		$this->assertCount( 1, $this->purge_actions(), 'voyager_open_blocks_purge fired before any cache was told.' );
		$this->assertSame(
			[ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ],
			array_column( $this->calls(), 1 ),
			'The pages after the one that threw are still purged.'
		);
		$this->assertSame( [ 'https://example.com/page-1/', 'https://example.com/page-2/', 'https://example.com/' ], $bystander->getArrayCopy(), 'The next adapter is still told.' );
		$record = $this->last_purge();
		$this->assertSame( [ 'nginx-helper' ], $record['failed'] );
		$this->assertSame( [ 'bystander' ], $record['adapters'] );
		$this->assertCount( 1, $GLOBALS['voyager_test_triggered'] );
		$this->assertStringContainsString( 'Nginx Helper could not purge https://example.com/page-2/', $GLOBALS['voyager_test_triggered'][0][0] );
	}

	public function test_a_purge_of_everything_that_throws_is_reported_and_recorded_as_failed(): void {
		$this->load_nginx_helper();
		$this->load_purge();
		$bystander                           = $this->add_bystander();
		$GLOBALS['voyager_test_nginx_throw'] = 'all';

		$this->change_phone_on( 51 );

		$this->assertCount( 1, $this->purge_actions() );
		$this->assertSame( [ 'all' ], $bystander->getArrayCopy() );
		$this->assertSame( [ 'nginx-helper' ], $this->last_purge()['failed'] );
		$this->assertStringContainsString( 'Nginx Helper could not purge every page', $GLOBALS['voyager_test_triggered'][0][0] ?? '' );
	}

	public function test_a_site_can_remove_it_through_the_filter(): void {
		$this->load_nginx_helper();
		$this->load_purge();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static fn ( $adapters ) => array_values(
				array_filter(
					(array) $adapters,
					static fn ( $adapter ): bool => ! ( $adapter instanceof Cache\Adapter && 'nginx-helper' === $adapter->id() )
				)
			),
			20
		);

		$this->change_phone_on( 51 );

		$this->assertSame( [], $this->calls() );
	}
}
