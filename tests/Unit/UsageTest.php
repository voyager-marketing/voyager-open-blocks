<?php
/**
 * The usage index's block walk (inc/freshness/usage.php).
 *
 * Usage\walk() reads what a post's blocks bind, at any depth, and through
 * the synced and registered patterns it holds. These tests give it parsed
 * blocks and resolvers directly, so no WordPress is needed; the registered
 * case also runs through the pack's own intents.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;
use VoyagerOpenBlocks\Usage;

require_once dirname( __DIR__ ) . '/usage-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/freshness/usage.php';

final class UsageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']   = [];
		$GLOBALS['voyager_test_events']    = [];
		$GLOBALS['voyager_test_post_meta'] = [];
	}

	/**
	 * A parsed block, as parse_blocks() returns it.
	 *
	 * @param array<string, mixed>       $attrs Attributes.
	 * @param array<array<string, mixed>> $inner Inner blocks.
	 * @return array<string, mixed>
	 */
	private function block( string $name, array $attrs = [], array $inner = [] ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * A block with one attribute bound to a key.
	 *
	 * @param array<array<string, mixed>> $inner Inner blocks.
	 * @return array<string, mixed>
	 */
	private function bound( string $key, string $source = SiteData\SOURCE, string $name = 'core/paragraph', array $inner = [] ): array {
		return $this->block(
			$name,
			[
				'metadata' => [
					'bindings' => [
						'content' => [
							'source' => $source,
							'args'   => [ 'key' => $key ],
						],
					],
				],
			],
			$inner
		);
	}

	/**
	 * Walk with resolvers that know no patterns, or the ones given.
	 *
	 * @param array<array<string, mixed>>               $blocks   Blocks.
	 * @param array<int, array<array<string, mixed>>>   $synced   Synced pattern ID => its blocks.
	 * @param array<string, array<array<string, mixed>>> $registered Registered pattern name => its blocks.
	 * @return array{rows: list<string>, refs: list<int>}
	 */
	private function walk( array $blocks, array $synced = [], array $registered = [] ): array {
		return Usage\walk(
			$blocks,
			static fn ( int $ref ): array => $synced[ $ref ] ?? [],
			static fn ( string $slug ): array => [
				'rows'   => [],
				'blocks' => $registered[ $slug ] ?? [],
			]
		);
	}

	public function test_a_post_that_binds_nothing_has_no_rows(): void {
		$found = $this->walk(
			[
				$this->block( 'core/paragraph' ),
				$this->block( 'core/group', [], [ $this->block( 'core/heading' ) ] ),
				$this->bound( 'title', 'core/post-meta' ),
				$this->block( 'core/paragraph', [ 'metadata' => [ 'bindings' => [ 'content' => [ 'source' => SiteData\SOURCE ] ] ] ] ),
			]
		);

		$this->assertSame(
			[
				'rows'  => [],
				'lists' => [],
				'refs'  => [],
			],
			$found
		);
	}

	/**
	 * A Query Loop whose post template holds the given blocks.
	 *
	 * @param array<array<string, mixed>> $template Blocks inside core/post-template.
	 * @param array<array<string, mixed>> $around   Blocks inside core/query but outside its post template.
	 * @return array<string, mixed>
	 */
	private function query_loop( array $template, array $around = [] ): array {
		return $this->block(
			'core/query',
			[
				'queryId' => 1,
				'query'   => [ 'postType' => 'service' ],
			],
			[ $this->block( 'core/post-template', [], $template ), ...$around ]
		);
	}

	public function test_a_binding_in_a_post_template_is_a_list_row_and_a_row(): void {
		$found = $this->walk(
			[
				$this->query_loop(
					[ $this->block( 'core/group', [], [ $this->block( 'core/columns', [], [ $this->bound( 'price.amount', PostMetaText\SOURCE ) ] ) ] ) ],
					[ $this->block( 'core/query-no-results', [], [ $this->bound( 'subtitle', PostMetaText\SOURCE ) ] ) ]
				),
			]
		);

		$this->assertSame( [ 'voyager/post-meta-text:price' ], $found['lists'], 'At any depth inside the post template, and by field, as rows are.' );
		$this->assertSame( [ 'voyager/post-meta-text:price', 'voyager/post-meta-text:subtitle' ], $found['rows'], 'The page shows both. Outside the post template the binding reads the page, so subtitle is not a list row.' );
	}

	public function test_a_page_that_shows_its_own_value_lists_nothing(): void {
		$found = $this->walk(
			[
				$this->bound( 'price', PostMetaText\SOURCE ),
				$this->block( 'core/group', [], [ $this->bound( 'phone' ) ] ),
			]
		);

		$this->assertSame( [ 'voyager/post-meta-text:price', 'voyager/site-data:phone' ], $found['rows'] );
		$this->assertSame( [], $found['lists'] );
	}

	public function test_patterns_inside_a_post_template_list_everything_they_bind(): void {
		$found = Usage\walk(
			[
				$this->query_loop(
					[
						$this->block( 'core/block', [ 'ref' => 12 ] ),
						$this->block( 'core/pattern', [ 'slug' => 'theme/price-row' ] ),
						$this->block( 'core/pattern', [ 'slug' => 'voyager-open/known' ] ),
					]
				),
				$this->block( 'core/pattern', [ 'slug' => 'voyager-open/outside' ] ),
			],
			fn ( int $ref ): array => 12 === $ref ? [ $this->bound( 'stats.0.value', PostMetaText\SOURCE ) ] : [],
			fn ( string $slug ): array => match ( $slug ) {
				'theme/price-row'      => [
					'rows'   => [],
					'blocks' => [ $this->bound( 'price', PostMetaText\SOURCE ) ],
				],
				'voyager-open/known'   => [
					'rows'   => [ 'voyager/site-data:phone' ],
					'blocks' => [],
				],
				'voyager-open/outside' => [
					'rows'   => [ 'voyager/site-data:email' ],
					'blocks' => [],
				],
				default                => [
					'rows'   => [],
					'blocks' => [],
				],
			}
		);

		$this->assertSame( [ 'voyager/post-meta-text:price', 'voyager/post-meta-text:stats', 'voyager/site-data:phone' ], $found['lists'], 'A synced pattern, a theme pattern and a pack pattern with rows from its intents, all inside the post template.' );
		$this->assertSame( [ 'voyager/post-meta-text:price', 'voyager/post-meta-text:stats', 'voyager/site-data:email', 'voyager/site-data:phone' ], $found['rows'] );
		$this->assertSame( [ 12 ], $found['refs'] );
	}

	public function test_a_synced_pattern_that_holds_a_query_loop_passes_its_list_rows_on(): void {
		$found = $this->walk(
			[ $this->block( 'core/group', [], [ $this->block( 'core/block', [ 'ref' => 7 ] ) ] ) ],
			[ 7 => [ $this->bound( 'phone' ), $this->query_loop( [ $this->bound( 'price', PostMetaText\SOURCE ) ] ) ] ]
		);

		$this->assertSame( [ 'voyager/post-meta-text:price' ], $found['lists'] );
		$this->assertSame( [ 'voyager/post-meta-text:price', 'voyager/site-data:phone' ], $found['rows'] );
		$this->assertSame( [ 7 ], $found['refs'] );
	}

	public function test_a_synced_pattern_that_holds_itself_inside_a_post_template_stops(): void {
		$found = $this->walk(
			[ $this->query_loop( [ $this->block( 'core/block', [ 'ref' => 7 ] ) ] ) ],
			[ 7 => [ $this->bound( 'price', PostMetaText\SOURCE ), $this->block( 'core/block', [ 'ref' => 7 ] ) ] ]
		);

		$this->assertSame( [ 'voyager/post-meta-text:price' ], $found['lists'] );
		$this->assertSame( [ 7 ], $found['refs'] );
	}

	public function test_no_pack_pattern_holds_a_post_template(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/patterns/*.html' );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString(
				'<!-- wp:post-template',
				(string) file_get_contents( $file ),
				basename( $file ) . ': the index reads a pack pattern\'s rows from its intent, which does not say what sits in a post template. Teach registered_pattern() to walk this one before adding a Query Loop to it.'
			);
		}
	}

	public function test_a_posts_list_rows_are_read_sorted_and_once(): void {
		$GLOBALS['voyager_test_post_meta'] = [
			20 => [
				Usage\LISTS => [ 'voyager/post-meta-text:price', 'voyager/post-meta-text:area', 'voyager/post-meta-text:price' ],
				Usage\META  => [ 'voyager/site-data:phone' ],
			],
		];

		$this->assertSame( '_voyager_open_blocks_lists', Usage\LISTS );
		$this->assertSame( [ 'voyager/post-meta-text:area', 'voyager/post-meta-text:price' ], Usage\lists( 20 ) );
		$this->assertSame( [ 'voyager/site-data:phone' ], Usage\uses( 20 ) );
		$this->assertSame( [], Usage\lists( 21 ) );
	}

	public function test_the_theme_index_keeps_list_rows_and_templates_are_matched_on_them(): void {
		$GLOBALS['voyager_test_options'][ Usage\THEME_OPTION ] = [
			'theme//archive-service' => [
				'type'  => 'wp_template',
				'title' => 'Services archive',
				'uses'  => [ 'voyager/post-meta-text:price' ],
				'lists' => [ 'voyager/post-meta-text:price' ],
			],
			'theme//single-service'  => [
				'type'  => 'wp_template',
				'title' => 'Single service',
				'uses'  => [ 'voyager/post-meta-text:price' ],
			],
		];

		$index = Usage\theme_index();
		$this->assertSame( [ 'voyager/post-meta-text:price' ], $index['theme//archive-service']['lists'] );
		$this->assertSame( [], $index['theme//single-service']['lists'], 'An entry stored before list rows existed reads as listing nothing.' );

		$rows = [ 'voyager/post-meta-text:price' ];
		$this->assertSame( [ 'theme//archive-service', 'theme//single-service' ], array_column( Usage\templates_with( $rows ), 'id' ) );
		$this->assertSame( [ 'theme//archive-service' ], array_column( Usage\templates_with( $rows, Usage\LISTS ), 'id' ), 'The single template shows each service its own price; only the archive lists it.' );
	}

	public function test_bindings_are_found_at_any_depth_once_each_and_sorted(): void {
		$found = $this->walk(
			[
				$this->bound( 'phone' ),
				$this->block(
					'core/group',
					[],
					[
						$this->block(
							'core/buttons',
							[],
							[ $this->bound( 'phone_link', SiteData\SOURCE, 'core/button' ), $this->bound( 'phone' ) ]
						),
						$this->bound( 'stats.0.value', PostMetaText\SOURCE ),
					]
				),
			]
		);

		$this->assertSame( [ 'voyager/post-meta-text:stats', 'voyager/site-data:phone', 'voyager/site-data:phone_link' ], $found['rows'] );
		$this->assertSame( [], $found['refs'] );
	}

	public function test_a_synced_pattern_adds_what_it_binds_and_its_id(): void {
		$found = $this->walk(
			[
				$this->bound( 'phone' ),
				$this->block( 'core/group', [], [ $this->block( 'core/block', [ 'ref' => 12 ] ) ] ),
			],
			[
				12 => [ $this->bound( 'email' ), $this->block( 'core/block', [ 'ref' => 13 ] ) ],
				13 => [ $this->bound( 'hours' ) ],
			]
		);

		$this->assertSame( [ 'voyager/site-data:email', 'voyager/site-data:hours', 'voyager/site-data:phone' ], $found['rows'] );
		$this->assertSame( [ 12, 13 ], $found['refs'], 'A nested synced pattern is a reference too, so changing it reaches this post.' );
	}

	public function test_a_synced_pattern_that_holds_itself_stops(): void {
		$found = $this->walk(
			[ $this->block( 'core/block', [ 'ref' => 7 ] ) ],
			[ 7 => [ $this->bound( 'address' ), $this->block( 'core/block', [ 'ref' => 7 ] ) ] ]
		);

		$this->assertSame( [ 'voyager/site-data:address' ], $found['rows'] );
		$this->assertSame( [ 7 ], $found['refs'] );
	}

	public function test_a_missing_synced_pattern_adds_only_its_id(): void {
		$found = $this->walk( [ $this->block( 'core/block', [ 'ref' => 99 ] ), $this->block( 'core/block' ) ] );

		$this->assertSame( [], $found['rows'] );
		$this->assertSame( [ 99 ], $found['refs'] );
	}

	public function test_a_registered_pattern_adds_what_it_binds(): void {
		$found = $this->walk(
			[ $this->block( 'core/pattern', [ 'slug' => 'theme/footer' ] ) ],
			[],
			[ 'theme/footer' => [ $this->block( 'core/group', [], [ $this->bound( 'email_link', SiteData\SOURCE, 'core/button' ) ] ) ] ]
		);

		$this->assertSame( [ 'voyager/site-data:email_link' ], $found['rows'] );
	}

	public function test_the_packs_registered_patterns_resolve_through_their_intents(): void {
		$found = Usage\walk(
			[ $this->block( 'core/pattern', [ 'slug' => 'voyager-open/cta-band' ] ) ],
			static fn ( int $ref ): array => [],
			Usage\registered_pattern( ... )
		);

		$this->assertContains( 'voyager/site-data:phone', $found['rows'] );
		$this->assertContains( 'voyager/site-data:phone_link', $found['rows'] );
		$this->assertSame( [], $found['refs'] );
	}

	public function test_pack_rows_covers_every_pattern_that_binds_site_data(): void {
		$rows = Usage\pack_rows();

		$this->assertEqualsCanonicalizing(
			array_keys( Patterns\bound_keys( SiteData\SOURCE ) + Patterns\bound_keys( PostMetaText\SOURCE ) ),
			array_keys( $rows )
		);
		$this->assertContains( 'voyager-open/cta-band', array_keys( $rows ) );
		foreach ( $rows as $name => $list ) {
			$this->assertNotSame( [], $list, $name );
			$this->assertSame( Usage\tidy( $list ), $list, $name . ' rows are sorted and unique' );
		}
	}

	public function test_a_post_meta_text_row_names_the_field_not_the_path(): void {
		$this->assertSame( 'voyager/post-meta-text:stats', Usage\row( PostMetaText\SOURCE, 'stats.0.value' ) );
		$this->assertSame( 'voyager/post-meta-text:price', Usage\row( PostMetaText\SOURCE, 'price' ) );
		$this->assertSame( 'voyager/site-data:phone', Usage\row( SiteData\SOURCE, 'phone' ) );
	}

	public function test_a_site_data_field_is_shown_by_the_keys_made_from_it(): void {
		$this->assertSame( [ 'voyager/site-data:phone', 'voyager/site-data:phone_link' ], Usage\site_data_rows( 'phone' ) );
		$this->assertSame( [ 'voyager/site-data:address_city', 'voyager/site-data:address_locality' ], Usage\site_data_rows( 'address_city' ) );
		$this->assertSame( [ 'voyager/site-data:hours' ], Usage\site_data_rows( 'hours' ) );
	}

	/**
	 * An indexer that records the IDs it is given.
	 *
	 * @param list<int> $seen Filled with each ID, in order.
	 * @return \Closure(int): bool
	 */
	private function recorder( array &$seen ): \Closure {
		return static function ( int $id ) use ( &$seen ): bool {
			$seen[] = $id;
			return true;
		};
	}

	public function test_a_synced_pattern_on_a_batch_of_pages_or_fewer_reindexes_them_all_now(): void {
		$seen  = [];
		$later = Usage\reindex( range( 1, Usage\BATCH ), $this->recorder( $seen ) );

		$this->assertSame( range( 1, Usage\BATCH ), $seen );
		$this->assertSame( [], $later );
		$this->assertSame( [], Usage\queued() );
		$this->assertArrayNotHasKey( Usage\QUEUE_HOOK, $GLOBALS['voyager_test_events'] );
	}

	public function test_a_synced_pattern_on_more_pages_reindexes_one_batch_now_and_queues_the_rest(): void {
		$seen  = [];
		$later = Usage\reindex( range( 1, 120 ), $this->recorder( $seen ) );

		$this->assertSame( range( 1, 50 ), $seen, 'The save indexes one batch and no more.' );
		$this->assertSame( range( 51, 120 ), $later );
		$this->assertSame( range( 51, 120 ), Usage\queued() );
		$this->assertArrayHasKey( Usage\QUEUE_HOOK, $GLOBALS['voyager_test_events'], 'WP-Cron takes the rest.' );
	}

	public function test_ids_queued_twice_are_kept_once_and_in_order(): void {
		Usage\enqueue( [ 90, 70, 80 ] );
		Usage\enqueue( [ 80, 60 ] );

		$this->assertSame( [ 60, 70, 80, 90 ], Usage\queued() );
	}

	public function test_the_queue_event_indexes_a_batch_at_a_time_until_the_queue_is_empty(): void {
		Usage\reindex( range( 1, 120 ), static fn ( int $id ): bool => true );

		// WP-Cron unschedules an event before it runs it.
		unset( $GLOBALS['voyager_test_events'][ Usage\QUEUE_HOOK ] );
		$seen = [];
		Usage\drain_queue( $this->recorder( $seen ) );

		$this->assertSame( range( 51, 100 ), $seen );
		$this->assertSame( range( 101, 120 ), Usage\queued() );
		$this->assertArrayHasKey( Usage\QUEUE_HOOK, $GLOBALS['voyager_test_events'], 'It runs again while IDs are left.' );

		unset( $GLOBALS['voyager_test_events'][ Usage\QUEUE_HOOK ] );
		$seen = [];
		Usage\drain_queue( $this->recorder( $seen ) );

		$this->assertSame( range( 101, 120 ), $seen );
		$this->assertSame( [], Usage\queued() );
		$this->assertArrayNotHasKey( Usage\QUEUE_OPTION, $GLOBALS['voyager_test_options'] );
		$this->assertArrayNotHasKey( Usage\QUEUE_HOOK, $GLOBALS['voyager_test_events'], 'It stops once the queue is empty.' );
	}

	public function test_a_run_that_dies_mid_batch_keeps_the_ids_it_had_not_indexed(): void {
		Usage\reindex( range( 1, 120 ), static fn ( int $id ): bool => true );
		unset( $GLOBALS['voyager_test_events'][ Usage\QUEUE_HOOK ] );

		$seen = [];
		try {
			Usage\drain_queue(
				static function ( int $id ) use ( &$seen ): bool {
					if ( 61 === $id ) {
						throw new \RuntimeException( 'The request died.' );
					}
					$seen[] = $id;
					return true;
				}
			);
			$this->fail( 'The indexer should have thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The request died.', $e->getMessage() );
		}

		$this->assertSame( range( 51, 60 ), $seen );
		$this->assertSame( range( 51, 120 ), Usage\queued(), 'Nothing leaves the queue until its batch is indexed.' );
		$this->assertArrayHasKey( Usage\QUEUE_HOOK, $GLOBALS['voyager_test_events'], 'The next run was scheduled before the batch started.' );
	}

	public function test_ids_queued_while_a_batch_runs_are_kept(): void {
		Usage\reindex( range( 1, 120 ), static fn ( int $id ): bool => true );
		unset( $GLOBALS['voyager_test_events'][ Usage\QUEUE_HOOK ] );

		Usage\drain_queue(
			static function ( int $id ): bool {
				if ( 51 === $id ) {
					Usage\enqueue( [ 500, 75 ] );
				}
				return true;
			}
		);

		$this->assertSame( [ ...range( 101, 120 ), 500 ], Usage\queued(), 'Only the indexed batch leaves the queue.' );
		$this->assertArrayHasKey( Usage\QUEUE_HOOK, $GLOBALS['voyager_test_events'] );
	}
}
