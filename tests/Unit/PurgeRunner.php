<?php
/**
 * Runs the purge (inc/freshness/purge.php) the way tests/Unit/PurgeTest.php
 * does, for the cache adapter tests: a phone change over pages 1 to n, all
 * showing it, with https://example.com/page-<id>/ as their permalinks.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use VoyagerOpenBlocks\Cache\Adapter;
use VoyagerOpenBlocks\Purge;

trait PurgeRunner {

	/**
	 * Load the purge and its stubs, with the adapter's filter already added.
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
	 * @return int Purges run.
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

	/**
	 * The last purge, as Settings > Site Data reads it.
	 *
	 * @return array<mixed>
	 */
	private function last_purge(): array {
		$record = $GLOBALS['voyager_test_options'][ Purge\LAST_OPTION ] ?? null;
		$this->assertIsArray( $record, 'The purge recorded nothing.' );

		return $record;
	}

	/**
	 * The voyager_open_blocks_purge calls, each as [ urls, context ].
	 *
	 * @return list<array<mixed>>
	 */
	private function purge_actions(): array {
		$out = [];
		foreach ( $GLOBALS['voyager_test_actions'] ?? [] as $call ) {
			if ( is_array( $call ) && Purge\ACTION === ( $call[0] ?? null ) ) {
				$out[] = array_slice( $call, 1 );
			}
		}

		return $out;
	}

	/**
	 * Add an adapter, after the one under test, that records what it is told.
	 *
	 * @return \ArrayObject<int, string> What it was told: 'all', or each URL.
	 */
	private function add_bystander(): \ArrayObject {
		$told = new \ArrayObject();
		add_filter(
			Purge\ADAPTERS_FILTER,
			static function ( $adapters ) use ( $told ) {
				$adapters   = is_array( $adapters ) ? $adapters : [];
				$adapters[] = new class( $told ) implements Adapter {
					/**
					 * @param \ArrayObject<int, string> $told What it was told.
					 */
					public function __construct( private \ArrayObject $told ) {}

					public function id(): string {
						return 'bystander';
					}

					public function label(): string {
						return 'Bystander';
					}

					public function active(): bool {
						return true;
					}

					public function supports_urls(): bool {
						return true;
					}

					public function purge_urls( array $urls ): void {
						foreach ( $urls as $url ) {
							$this->told->append( $url );
						}
					}

					public function purge_all(): void {
						$this->told->append( 'all' );
					}
				};
				return $adapters;
			},
			20
		);

		return $told;
	}
}
