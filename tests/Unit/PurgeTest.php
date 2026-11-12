<?php
/**
 * The purge (inc/freshness/purge.php): which pages a change refreshes, once
 * per request, and what the action, the adapters and Settings > Site Data get.
 *
 * The index is replaced by a lookup the test gives: post ID => the rows it
 * shows. Permalinks come from voyager_test_permalinks, the front page is
 * https://example.com/, and do_action() calls are recorded.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cache\Adapter;
use VoyagerOpenBlocks\Purge;

require_once dirname( __DIR__ ) . '/usage-stubs.php';
require_once dirname( __DIR__ ) . '/purge-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/freshness/usage.php';
require_once dirname( __DIR__, 2 ) . '/inc/cache/adapter.php';
require_once dirname( __DIR__, 2 ) . '/inc/freshness/purge.php';

/**
 * An adapter that logs what it was told.
 */
final class SpyAdapter implements Adapter {

	/** @var list<string> */
	public array $calls = [];

	public function __construct(
		private string $name,
		private bool $on = true,
		private bool $urls = true,
		private bool $throws = false
	) {}

	public function id(): string {
		return $this->name;
	}

	public function label(): string {
		return ucfirst( $this->name ) . ' cache';
	}

	public function active(): bool {
		return $this->on;
	}

	public function supports_urls(): bool {
		return $this->urls;
	}

	public function purge_urls( array $urls ): void {
		if ( $this->throws ) {
			throw new \RuntimeException( 'The cache is down.' );
		}
		$this->calls[] = 'urls ' . implode( ' ', $urls );
	}

	public function purge_all(): void {
		if ( $this->throws ) {
			throw new \RuntimeException( 'The cache is down.' );
		}
		$this->calls[] = 'all';
	}
}

/**
 * Options that record each name read or written, for code that must touch none.
 *
 * @extends \ArrayObject<string, mixed>
 */
final class RecordingOptions extends \ArrayObject {

	/** @var list<string> */
	public array $touched = [];

	public function offsetExists( mixed $key ): bool {
		$this->touched[] = (string) $key;
		return parent::offsetExists( $key );
	}

	public function offsetGet( mixed $key ): mixed {
		$this->touched[] = (string) $key;
		return parent::offsetGet( $key );
	}

	public function offsetSet( mixed $key, mixed $value ): void {
		$this->touched[] = (string) $key;
		parent::offsetSet( $key, $value );
	}

	public function offsetUnset( mixed $key ): void {
		$this->touched[] = (string) $key;
		parent::offsetUnset( $key );
	}
}

final class PurgeTest extends TestCase {

	private const FRONT = 'https://example.com/';

	/** @var list<list<string>> Rows each lookup was given. */
	private array $looked_up = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']    = [];
		$GLOBALS['voyager_test_events']     = [];
		$GLOBALS['voyager_test_filters']    = [];
		$GLOBALS['voyager_test_actions']    = [];
		$GLOBALS['voyager_test_cleaned']    = [];
		$GLOBALS['voyager_test_post_types'] = [];
		$GLOBALS['voyager_test_permalinks'] = [
			5  => 'https://example.com/a/',
			12 => 'https://example.com/b/',
			30 => 'https://example.com/c/',
		];
		$GLOBALS['voyager_test_blog_id']      = 1;
		$GLOBALS['voyager_test_switch_stack'] = [];
		$GLOBALS['voyager_test_switches']     = [];
		$GLOBALS['voyager_test_recurrence']   = [];
		$this->looked_up                      = [];
		Purge\pending( '', 0, true );
		Purge\running( false );
	}

	/**
	 * A lookup over a pretend index, recording the rows it was given.
	 *
	 * @param array<int, list<string>>    $index     Post ID => rows it shows.
	 * @param array<string, list<string>> $templates Template ID => rows it shows.
	 * @return callable(list<string>): array{posts: list<int>, templates: list<string>}
	 */
	private function lookup( array $index, array $templates = [] ): callable {
		return function ( array $rows ) use ( $index, $templates ): array {
			$this->looked_up[] = $rows;
			$posts             = [];
			foreach ( $index as $id => $uses ) {
				if ( [] !== array_intersect( $rows, $uses ) ) {
					$posts[] = $id;
				}
			}
			$found = [];
			foreach ( $templates as $id => $uses ) {
				if ( [] !== array_intersect( $rows, $uses ) ) {
					$found[] = $id;
				}
			}
			sort( $posts );
			return [
				'posts'     => $posts,
				'templates' => $found,
			];
		};
	}

	/**
	 * The voyager_open_blocks_purge calls: [ urls, context ] each.
	 *
	 * @return list<array{0: mixed, 1: mixed}>
	 */
	private function purges(): array {
		$out = [];
		foreach ( $GLOBALS['voyager_test_actions'] as $call ) {
			if ( Purge\ACTION === $call[0] ) {
				$out[] = [ $call[1], $call[2] ];
			}
		}
		return $out;
	}

	/**
	 * Offer adapters through the filter.
	 *
	 * @param list<object> $adapters Adapters.
	 */
	private function offer( array $adapters ): void {
		add_filter( Purge\ADAPTERS_FILTER, static fn ( $found ) => [ ...( is_array( $found ) ? $found : [] ), ...$adapters ] );
	}

	public function test_changing_the_phone_fires_once_with_the_pages_using_it_and_the_front_page(): void {
		Purge\on_option( 'voyager_site_phone' );
		Purge\on_option( 'voyager_site_phone' );

		$ran = Purge\run(
			$this->lookup(
				[
					5  => [ 'voyager/site-data:phone' ],
					12 => [ 'voyager/site-data:phone_link' ],
					30 => [ 'voyager/site-data:email' ],
				]
			)
		);

		$this->assertSame( 1, $ran );
		$this->assertSame( [ [ 'voyager/site-data:phone', 'voyager/site-data:phone_link' ] ], $this->looked_up, 'A call button bound to phone_link shows the phone too.' );
		$this->assertSame(
			[
				[
					[ 'https://example.com/a/', 'https://example.com/b/', self::FRONT ],
					[
						'source'    => 'voyager/site-data',
						'keys'      => [ 'phone' ],
						'post_ids'  => [ 5, 12 ],
						'templates' => [],
						'reason'    => 'site-data',
						'purge_all' => false,
					],
				],
			],
			$this->purges()
		);
		$this->assertSame( [ 5, 12 ], $GLOBALS['voyager_test_cleaned'] );
	}

	public function test_a_template_part_that_shows_the_field_sets_purge_all(): void {
		$all = new SpyAdapter( 'spy' );
		$this->offer( [ $all ] );
		Purge\on_option( 'voyager_site_phone' );

		Purge\run( $this->lookup( [ 5 => [ 'voyager/site-data:phone' ] ], [ 'theme//footer' => [ 'voyager/site-data:phone' ] ] ) );

		$purges = $this->purges();
		$this->assertCount( 1, $purges );
		$this->assertTrue( $purges[0][1]['purge_all'] );
		$this->assertSame( [ 'theme//footer' ], $purges[0][1]['templates'] );
		$this->assertSame( [ 'all' ], $all->calls, 'purge_all reaches the adapter as purge_all().' );
	}

	public function test_a_template_part_alone_still_purges_with_the_front_page(): void {
		Purge\on_option( 'voyager_site_hours' );

		Purge\run( $this->lookup( [], [ 'theme//footer' => [ 'voyager/site-data:hours' ] ] ) );

		$purges = $this->purges();
		$this->assertCount( 1, $purges );
		$this->assertSame( [ self::FRONT ], $purges[0][0] );
		$this->assertTrue( $purges[0][1]['purge_all'] );
	}

	public function test_a_field_no_page_or_template_shows_fires_nothing(): void {
		$spy = new SpyAdapter( 'spy' );
		$this->offer( [ $spy ] );
		Purge\on_option( 'voyager_site_social_x' );

		$ran = Purge\run( $this->lookup( [ 5 => [ 'voyager/site-data:phone' ] ] ) );

		$this->assertSame( 0, $ran );
		$this->assertSame( [], $this->purges() );
		$this->assertSame( [], $GLOBALS['voyager_test_cleaned'] );
		$this->assertSame( [], $spy->calls );
		$this->assertArrayNotHasKey( Purge\LAST_OPTION, $GLOBALS['voyager_test_options'] );
	}

	public function test_only_site_data_options_are_noted(): void {
		Purge\on_option( 'admin_email' );
		Purge\on_option( 'siteurl' );
		Purge\on_option( 'voyager_site_' );
		Purge\on_option( 'voyager_open_blocks_last_purge' );
		Purge\on_option( [ 'not', 'a', 'name' ] );

		$this->assertSame( [], Purge\pending()['site_data'] );

		Purge\on_option( 'voyager_site_address_city' );
		$this->assertSame( [ 'address_city' ], Purge\pending()['site_data'] );
	}

	/**
	 * Pages A to C: one shows the company, one the copyright line, one the tagline.
	 *
	 * @return array<int, list<string>>
	 */
	private function site_title_pages(): array {
		return [
			5  => [ 'voyager/site-data:company' ],
			12 => [ 'voyager/site-data:copyright' ],
			30 => [ 'voyager/site-data:tagline' ],
		];
	}

	public function test_changing_the_site_title_refreshes_the_pages_that_show_the_company_or_the_copyright(): void {
		Purge\on_option( 'blogname' );

		$ran = Purge\run( $this->lookup( $this->site_title_pages() ) );

		$this->assertSame( 1, $ran );
		$this->assertSame( [ [ 'voyager/site-data:company', 'voyager/site-data:copyright' ] ], $this->looked_up, 'The copyright line is made from the company.' );
		$this->assertSame(
			[
				[
					[ 'https://example.com/a/', 'https://example.com/b/', self::FRONT ],
					[
						'source'    => 'voyager/site-data',
						'keys'      => [ 'company' ],
						'post_ids'  => [ 5, 12 ],
						'templates' => [],
						'reason'    => 'site-data',
						'purge_all' => false,
					],
				],
			],
			$this->purges(),
			'Page C shows the tagline only.'
		);
	}

	public function test_changing_the_tagline_refreshes_the_pages_that_show_it(): void {
		Purge\on_option( 'blogdescription' );

		$ran = Purge\run( $this->lookup( $this->site_title_pages() ) );

		$this->assertSame( 1, $ran );
		$this->assertSame( [ [ 'voyager/site-data:tagline' ] ], $this->looked_up );
		$this->assertSame(
			[
				[
					[ 'https://example.com/c/', self::FRONT ],
					[
						'source'    => 'voyager/site-data',
						'keys'      => [ 'tagline' ],
						'post_ids'  => [ 30 ],
						'templates' => [],
						'reason'    => 'site-data',
						'purge_all' => false,
					],
				],
			],
			$this->purges()
		);
	}

	public function test_a_settings_form_that_saves_the_title_tagline_and_phone_purges_once(): void {
		Purge\on_option( 'blogname' );
		Purge\on_option( 'blogdescription' );
		Purge\on_option( 'voyager_site_phone' );

		$this->assertSame( 1, Purge\run( $this->lookup( $this->site_title_pages() ) ) );
		$this->assertCount( 1, $this->looked_up );
		$this->assertSame( [ 'company', 'phone', 'tagline' ], $this->purges()[0][1]['keys'] );
	}

	public function test_a_new_year_refreshes_the_pages_that_show_the_year_or_the_copyright_once(): void {
		$GLOBALS['voyager_test_options'][ Purge\YEAR_OPTION ] = '2026';
		$pages = [
			5  => [ 'voyager/site-data:year' ],
			12 => [ 'voyager/site-data:copyright' ],
			30 => [ 'voyager/site-data:company' ],
		];

		$this->assertFalse( Purge\check_year( '2026' ), 'The same year: nothing to refresh.' );
		$this->assertSame( [], Purge\pending()['site_data'] );

		$this->assertTrue( Purge\check_year( '2027' ) );
		$this->assertSame( '2027', $GLOBALS['voyager_test_options'][ Purge\YEAR_OPTION ] );
		$this->assertSame( 1, Purge\run( $this->lookup( $pages ) ) );
		$this->assertSame( [ [ 'voyager/site-data:copyright', 'voyager/site-data:year' ] ], $this->looked_up );
		$this->assertSame(
			[
				[
					[ 'https://example.com/a/', 'https://example.com/b/', self::FRONT ],
					[
						'source'    => 'voyager/site-data',
						'keys'      => [ 'year' ],
						'post_ids'  => [ 5, 12 ],
						'templates' => [],
						'reason'    => 'site-data',
						'purge_all' => false,
					],
				],
			],
			$this->purges()
		);

		$this->assertFalse( Purge\check_year( '2027' ), 'Once: the next daily check that year refreshes nothing.' );
		$this->assertSame( 0, Purge\run( $this->lookup( $pages ) ) );
		$this->assertCount( 1, $this->purges() );
	}

	public function test_the_year_event_compares_the_year_now_with_the_one_it_saw(): void {
		$GLOBALS['voyager_test_options'][ Purge\YEAR_OPTION ] = '1999';

		Purge\on_year_event();

		$this->assertSame( [ 'year' ], Purge\pending()['site_data'] );
		$this->assertSame( wp_date( 'Y' ), $GLOBALS['voyager_test_options'][ Purge\YEAR_OPTION ] );
	}

	public function test_the_first_year_check_stores_the_year_and_refreshes_nothing(): void {
		$this->assertFalse( Purge\check_year( '2026' ) );

		$this->assertSame( '2026', $GLOBALS['voyager_test_options'][ Purge\YEAR_OPTION ] );
		$this->assertSame( [], Purge\pending()['site_data'] );
	}

	public function test_the_year_check_runs_daily_and_a_normal_request_reads_no_option_for_it(): void {
		Purge\schedule_year_check();

		$first = $GLOBALS['voyager_test_events'][ Purge\YEAR_HOOK ] ?? 0;
		$this->assertSame( 'daily', $GLOBALS['voyager_test_recurrence'][ Purge\YEAR_HOOK ] ?? null );
		$this->assertGreaterThan( time(), $first );
		$this->assertLessThanOrEqual( time() + 2 * 86400, $first );
		$this->assertSame( '01:00', gmdate( 'H:i', $first ), 'Just after midnight, site time, which is UTC here.' );
		$this->assertSame( gmdate( 'Y' ), $GLOBALS['voyager_test_options'][ Purge\YEAR_OPTION ] ?? null, 'Scheduling stores the year, so the first check can tell a new one.' );

		$options                         = new RecordingOptions( $GLOBALS['voyager_test_options'] );
		$GLOBALS['voyager_test_options'] = $options;

		Purge\schedule_year_check();

		$this->assertSame( [], $options->touched, 'Once scheduled, init asks wp_next_scheduled() only, which reads the autoloaded cron option.' );
		$this->assertSame( $first, $GLOBALS['voyager_test_events'][ Purge\YEAR_HOOK ] );
	}

	public function test_a_change_made_under_switch_to_blog_purges_on_its_own_site(): void {
		Purge\on_option( 'voyager_site_email' );
		switch_to_blog( 3 );
		Purge\on_option( 'voyager_site_phone' );
		restore_current_blog();
		$GLOBALS['voyager_test_switches'] = [];

		$sites  = new \ArrayObject();
		$spy    = new class( $sites ) implements Adapter {
			/**
			 * @param \ArrayObject<int, string> $sites Where it was told, and what.
			 */
			public function __construct( private \ArrayObject $sites ) {}
			public function id(): string {
				return 'site-spy';
			}
			public function label(): string {
				return 'Site spy';
			}
			public function active(): bool {
				return true;
			}
			public function supports_urls(): bool {
				return true;
			}
			public function purge_urls( array $urls ): void {
				$this->sites->append( get_current_blog_id() . ' ' . implode( ' ', $urls ) );
			}
			public function purge_all(): void {
				$this->sites->append( get_current_blog_id() . ' all' );
			}
		};
		$looked = [];
		$lookup = $this->lookup(
			[
				5  => [ 'voyager/site-data:email' ],
				12 => [ 'voyager/site-data:phone' ],
			]
		);
		$this->offer( [ $spy ] );

		$ran = Purge\run(
			static function ( array $rows ) use ( $lookup, &$looked ): array {
				$looked[] = get_current_blog_id() . ' ' . implode( ' ', $rows );
				return $lookup( $rows );
			}
		);

		$this->assertSame( 2, $ran, 'One purge for each site.' );
		$this->assertSame(
			[
				'1 voyager/site-data:email voyager/site-data:email_link',
				'3 voyager/site-data:phone voyager/site-data:phone_link',
			],
			$looked,
			'Each site looks up its own change, on that site.'
		);
		$this->assertSame( [ 'switch 3', 'restore' ], $GLOBALS['voyager_test_switches'] );
		$this->assertSame( 1, get_current_blog_id(), 'The purge goes back to the site it started on.' );
		$this->assertSame( [ '1 https://example.com/a/ ' . self::FRONT, '3 https://example.com/b/ ' . self::FRONT ], $sites->getArrayCopy(), 'Adapters are told on the site that changed.' );
		$this->assertSame( [ [ 'email' ], [ 'phone' ] ], array_map( static fn ( array $purge ): mixed => $purge[1]['keys'], $this->purges() ) );
		$this->assertSame( [], Purge\pending()['site_data'] );
		switch_to_blog( 3 );
		$this->assertSame( [], Purge\pending()['site_data'], 'Site 3 is purged too, so nothing is left.' );
		restore_current_blog();
	}

	public function test_a_form_that_saves_several_fields_purges_once_and_lists_each_page_once(): void {
		$GLOBALS['voyager_test_permalinks'][7] = self::FRONT;
		Purge\on_option( 'voyager_site_phone' );
		Purge\on_option( 'voyager_site_email' );
		Purge\on_option( 'voyager_site_phone' );

		Purge\run(
			$this->lookup(
				[
					5 => [ 'voyager/site-data:email', 'voyager/site-data:phone' ],
					7 => [ 'voyager/site-data:email' ],
				]
			)
		);

		$this->assertCount( 1, $this->looked_up, 'Every field changed in the request is looked up together.' );
		$purges = $this->purges();
		$this->assertCount( 1, $purges );
		$this->assertSame( [ 'https://example.com/a/', self::FRONT ], $purges[0][0], 'Page 7 is the static front page: its permalink is listed once.' );
		$this->assertSame( [ 'email', 'phone' ], $purges[0][1]['keys'] );
		$this->assertSame( [], Purge\pending()['site_data'], 'The purge takes what it ran.' );
	}

	public function test_more_than_fifty_pages_lists_fifty_and_sets_purge_all(): void {
		$index = [];
		for ( $id = 1; $id <= 51; $id++ ) {
			$index[ $id ]                               = [ 'voyager/site-data:phone' ];
			$GLOBALS['voyager_test_permalinks'][ $id ] = "https://example.com/p{$id}/";
		}
		Purge\on_option( 'voyager_site_phone' );

		Purge\run( $this->lookup( $index ) );

		$purges = $this->purges();
		$this->assertCount( 1, $purges );
		$this->assertCount( 51, $purges[0][0], 'Fifty pages and the front page.' );
		$this->assertCount( 50, $purges[0][1]['post_ids'] );
		$this->assertTrue( $purges[0][1]['purge_all'] );
		$this->assertSame( range( 1, 50 ), $GLOBALS['voyager_test_cleaned'], 'The first fifty are cleaned. Page 51 keeps its cached copy until the cache expires it.' );
		$this->assertSame( [], $GLOBALS['voyager_test_events'], 'Nothing is left to WP-Cron.' );
	}

	public function test_past_fifty_changed_posts_the_first_fifty_that_show_it_are_cleaned_as_pages_are(): void {
		$changes = [];
		for ( $id = 1; $id <= 55; $id++ ) {
			$changes[ $id ]                             = [ 'price' ];
			$GLOBALS['voyager_test_permalinks'][ $id ] = "https://example.com/p{$id}/";
		}
		$shown = static fn ( int $post_id ): array => 0 === $post_id % 2 ? [ 'voyager/post-meta-text:price' ] : [];

		$ran = Purge\purge( Purge\post_meta_plan( $changes, $shown, static fn (): bool => true ) );

		$even = range( 2, 50, 2 );
		$this->assertTrue( $ran );
		$this->assertSame( $even, $GLOBALS['voyager_test_cleaned'], 'Of the first fifty, the ones that show the price are cleaned, as the page branch cleans its first fifty.' );
		$purges = $this->purges();
		$this->assertCount( 1, $purges );
		$this->assertSame( $even, $purges[0][1]['post_ids'] );
		$this->assertCount( 25, $purges[0][0] );
		$this->assertTrue( $purges[0][1]['purge_all'], 'Posts 51 to 55 were not looked at, so adapters purge everything.' );
		$this->assertSame( [], $GLOBALS['voyager_test_events'] );
	}

	public function test_bindable_post_meta_purges_only_its_own_post(): void {
		add_filter( 'voyager_post_meta_text_fields', static fn () => [ 'page' => [ 'price' => 'Price' ] ] );
		$GLOBALS['voyager_test_post_types'] = [
			5  => 'page',
			12 => 'page',
		];
		Purge\on_meta( 1, 5, 'price' );
		Purge\on_meta( 2, 12, 'price' );
		Purge\on_meta( 3, 5, 'price' );

		$shows = [
			5  => [ 'voyager/post-meta-text:price' ],
			12 => [],
		];
		$ran   = Purge\run( null, static fn ( int $post_id ): array => $shows[ $post_id ] ?? [] );

		$this->assertSame( 1, $ran );
		$this->assertSame(
			[
				[
					[ 'https://example.com/a/' ],
					[
						'source'    => 'voyager/post-meta-text',
						'keys'      => [ 'price' ],
						'post_ids'  => [ 5 ],
						'templates' => [],
						'reason'    => 'post-meta',
						'purge_all' => false,
					],
				],
			],
			$this->purges(),
			'Page 12 changed price too, and does not show it. No front page.'
		);
	}

	public function test_only_allowlisted_meta_on_content_is_noted(): void {
		add_filter(
			'voyager_post_meta_text_fields',
			static fn () => [
				'*'    => [ 'price' => 'Price' ],
				'page' => [ '_listed' => 'Listed by a filter' ],
			]
		);
		$GLOBALS['voyager_test_post_types'] = [
			5 => 'page',
			9 => 'revision',
		];

		Purge\on_meta( 1, 5, '_voyager_open_blocks_uses' );
		Purge\on_meta( 1, 5, 'secret' );
		Purge\on_meta( 1, 5, 'price.0' );
		Purge\on_meta( 1, 9, 'price' );
		Purge\on_meta( 1, 404, 'price' );
		$this->assertSame( [], Purge\pending()['post_meta'] );

		Purge\on_meta( 1, 5, 'price' );
		Purge\on_meta( 1, 5, '_listed' );
		$this->assertSame( [ 5 => [ '_listed', 'price' ] ], Purge\pending()['post_meta'], 'A key the filter allows counts, as it resolves.' );
	}

	public function test_post_meta_on_more_than_fifty_posts_looks_at_the_first_fifty_only(): void {
		$changes = [];
		for ( $id = 1; $id <= 51; $id++ ) {
			$changes[ $id ] = [ 'price' ];
		}

		$looked = [];
		$shown  = static function ( int $post_id ) use ( &$looked ): array {
			$looked[] = $post_id;
			return [];
		};
		$plan   = Purge\post_meta_plan( $changes, $shown, static fn ( array $rows ): bool => [ 'voyager/post-meta-text:price' ] === $rows );

		$this->assertSame( range( 1, 50 ), $looked, 'Only the first fifty are looked at in the request.' );
		$this->assertNotNull( $plan );
		$this->assertSame( [], $plan['urls'] );
		$this->assertSame( [], $plan['context']['post_ids'] );
		$this->assertTrue( $plan['context']['purge_all'], 'A page or template shows the field somewhere.' );
		$this->assertNull( Purge\post_meta_plan( $changes, $shown, static fn (): bool => false ), 'Nothing shows the field: nothing to purge.' );
	}

	public function test_a_filter_that_empties_the_urls_switches_the_purge_off(): void {
		$spy = new SpyAdapter( 'spy' );
		$this->offer( [ $spy ] );
		add_filter( Purge\URLS_FILTER, static fn () => [] );
		Purge\on_option( 'voyager_site_phone' );

		$ran = Purge\run( $this->lookup( [ 5 => [ 'voyager/site-data:phone' ] ] ) );

		$this->assertSame( 0, $ran );
		$this->assertSame( [], $this->purges() );
		$this->assertSame( [], $GLOBALS['voyager_test_cleaned'], 'No clean_post_cache() either: page caches listen to it.' );
		$this->assertSame( [], $spy->calls );
		$this->assertArrayNotHasKey( Purge\LAST_OPTION, $GLOBALS['voyager_test_options'] );
	}

	public function test_each_active_adapter_is_told_once_and_a_failing_one_does_not_stop_the_rest(): void {
		$urls     = new SpyAdapter( 'urls' );
		$off      = new SpyAdapter( 'off', false );
		$whole    = new SpyAdapter( 'whole', true, false );
		$broken   = new SpyAdapter( 'broken', true, true, true );
		$last     = new SpyAdapter( 'last' );
		$this->offer( [ $urls, $off, $broken, $whole, new \stdClass(), $last ] );
		Purge\on_option( 'voyager_site_phone' );

		Purge\run( $this->lookup( [ 5 => [ 'voyager/site-data:phone' ] ] ) );

		$this->assertSame( [ 'urls https://example.com/a/ ' . self::FRONT ], $urls->calls );
		$this->assertSame( [], $off->calls );
		$this->assertSame( [ 'all' ], $whole->calls, 'A cache that cannot purge one page purges everything.' );
		$this->assertSame( [ 'urls https://example.com/a/ ' . self::FRONT ], $last->calls );

		$record = $GLOBALS['voyager_test_options'][ Purge\LAST_OPTION ];
		$this->assertIsArray( $record );
		$this->assertSame( [ 'urls', 'whole', 'last' ], $record['adapters'] );
		$this->assertSame( [ 'broken' ], $record['failed'] );
		$this->assertSame( 2, $record['urls'] );
		$this->assertSame( [ 'phone' ], $record['keys'] );
		$this->assertFalse( $record['purge_all'] );
	}

	public function test_a_change_made_while_purging_is_not_noted_again(): void {
		$loop = new class() implements Adapter {
			public function id(): string {
				return 'loop';
			}
			public function label(): string {
				return 'Loop';
			}
			public function active(): bool {
				return true;
			}
			public function supports_urls(): bool {
				return true;
			}
			public function purge_urls( array $urls ): void {
				Purge\on_option( 'voyager_site_phone' );
			}
			public function purge_all(): void {}
		};
		$this->offer( [ $loop ] );
		Purge\on_option( 'voyager_site_phone' );

		Purge\run( $this->lookup( [ 5 => [ 'voyager/site-data:phone' ] ] ) );

		$this->assertSame( [], Purge\pending()['site_data'] );
		$this->assertFalse( Purge\running() );
	}

	public function test_the_last_refresh_line(): void {
		$now = 1_800_000_000;

		$this->assertStringStartsWith( 'No pages refreshed yet.', Purge\last_line( null, $now ) );

		$record = [
			'time'      => $now - 120,
			'urls'      => 6,
			'purge_all' => false,
			'adapters'  => [],
			'failed'    => [],
		];
		$this->assertSame( 'Last refreshed 6 pages, 2 minutes ago.', Purge\last_line( $record, $now ) );
		$this->assertSame( 'Last refreshed 1 page, 2 minutes ago.', Purge\last_line( [ 'urls' => 1 ] + $record, $now ) );
		$this->assertSame( 'Last refreshed every page, 2 minutes ago.', Purge\last_line( [ 'purge_all' => true ] + $record, $now ) );
		$this->assertSame(
			'Last refreshed 6 pages, 2 minutes ago. Caches told: Dummy cache, other. These caches failed: broken.',
			Purge\last_line(
				[
					'adapters' => [ 'dummy', 'other' ],
					'failed'   => [ 'broken' ],
				] + $record,
				$now,
				[ 'dummy' => 'Dummy cache' ]
			)
		);
	}

	public function test_the_last_refresh_is_read_back_from_what_a_purge_stored(): void {
		$this->assertNull( Purge\last() );

		Purge\on_option( 'voyager_site_phone' );
		Purge\run( $this->lookup( [ 5 => [ 'voyager/site-data:phone' ] ] ) );

		$last = Purge\last();
		$this->assertNotNull( $last );
		$this->assertSame( 2, $last['urls'] );
		$this->assertFalse( $last['purge_all'] );
		$this->assertStringStartsWith( 'Last refreshed 2 pages, ', Purge\last_line( $last, time() ) );
	}
}
