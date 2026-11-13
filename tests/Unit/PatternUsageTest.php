<?php
/**
 * Which patterns use each Site Data field.
 *
 * Patterns\keys_in() and Patterns\bound_keys() read the bindings out of the
 * pattern intents. SiteData\used_by() turns them into the "Used by" line under
 * each field on Settings > Site Data, counting a derived key such as
 * phone_link as a use of the field it is made from.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';

final class PatternUsageTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/voyager-open-intents-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * A block's metadata.bindings entry.
	 *
	 * @return array<string, mixed>
	 */
	private function binding( string $key, string $source = SiteData\SOURCE ): array {
		return [
			'source' => $source,
			'args'   => [ 'key' => $key ],
		];
	}

	/**
	 * An intent node with one bound attribute and optional children.
	 *
	 * @param array<string, mixed>       $binding  A binding().
	 * @param array<array<string, mixed>> $children Child nodes.
	 * @return array<string, mixed>
	 */
	private function node( array $binding, array $children = [] ): array {
		return [
			'block'    => 'core/paragraph',
			'attrs'    => [ 'metadata' => [ 'bindings' => [ 'content' => $binding ] ] ],
			'children' => $children,
		];
	}

	/**
	 * Write an intent file to the temporary intents directory.
	 */
	private function write( string $name, string $json ): void {
		file_put_contents( $this->dir . '/' . $name . '.json', $json );
	}

	public function test_keys_in_finds_bindings_at_any_depth_once_each(): void {
		$tree = [
			'blocks' => [
				$this->node(
					$this->binding( 'phone' ),
					[ $this->node( $this->binding( 'phone_link' ), [ $this->node( $this->binding( 'phone' ) ) ] ) ]
				),
			],
		];

		$this->assertSame( [ 'phone', 'phone_link' ], Patterns\keys_in( $tree, SiteData\SOURCE ) );
	}

	public function test_keys_in_ignores_other_sources_and_keys_that_are_not_strings(): void {
		$tree = [
			$this->node( $this->binding( 'subtitle', 'voyager/post-meta-text' ) ),
			$this->node( [ 'source' => SiteData\SOURCE, 'args' => [ 'key' => 7 ] ] ),
			$this->node( [ 'source' => SiteData\SOURCE ] ),
			'a string',
		];

		$this->assertSame( [], Patterns\keys_in( $tree, SiteData\SOURCE ) );
		$this->assertSame( [], Patterns\keys_in( 'not a tree', SiteData\SOURCE ) );
	}

	public function test_bound_keys_reads_each_intent_file(): void {
		$this->write(
			'cta-band',
			(string) json_encode(
				[
					'title'  => 'CTA band',
					'intent' => [ 'blocks' => [ $this->node( $this->binding( 'phone_link' ) ) ] ],
				]
			)
		);
		$this->write( 'no-bindings', (string) json_encode( [ 'title' => 'No bindings', 'intent' => [ 'blocks' => [] ] ] ) );
		$this->write( 'no-title', (string) json_encode( [ 'intent' => [ 'blocks' => [ $this->node( $this->binding( 'email' ) ) ] ] ] ) );
		$this->write( 'broken', '{ not json' );

		$this->assertSame(
			[
				'voyager-open/cta-band' => [
					'title' => 'CTA band',
					'keys'  => [ 'phone_link' ],
				],
			],
			Patterns\bound_keys( SiteData\SOURCE, $this->dir )
		);
	}

	public function test_bound_keys_is_empty_for_a_directory_with_no_intents(): void {
		$this->assertSame( [], Patterns\bound_keys( SiteData\SOURCE, $this->dir ) );
	}

	public function test_used_by_counts_a_derived_key_as_a_use_of_its_fields(): void {
		$used = SiteData\used_by(
			[
				[ 'title' => 'Closing CTA', 'keys' => [ 'email_link' ] ],
				[ 'title' => 'Contact section', 'keys' => [ 'phone', 'address_locality', 'hours' ] ],
				[ 'title' => 'CTA band', 'keys' => [ 'phone', 'phone_link' ] ],
			]
		);

		$this->assertSame( array_keys( SiteData\fields() ), array_keys( $used ) );
		$this->assertSame( [ 'Contact section', 'CTA band' ], $used['phone'] );
		$this->assertSame( [ 'CTA band' ], $used['phone_link'] );
		$this->assertSame( [ 'Closing CTA' ], $used['email'] );
		$this->assertSame( [ 'Contact section' ], $used['address_city'] );
		$this->assertSame( [ 'Contact section' ], $used['address_state'] );
		$this->assertSame( [ 'Contact section' ], $used['address_zip'] );
		$this->assertSame( [ 'Contact section' ], $used['hours'] );
		$this->assertSame( [], $used['address'] );
		$this->assertSame( [], $used['social_x'] );
	}

	public function test_used_by_with_no_patterns_marks_every_key_unused(): void {
		$used = SiteData\used_by( [] );

		$this->assertSame( array_fill_keys( array_keys( SiteData\fields() ), [] ), $used );
	}

	public function test_every_derived_key_is_made_from_listed_keys(): void {
		$keys = array_keys( SiteData\fields() );

		foreach ( SiteData\derived_from() as $derived => $parts ) {
			$this->assertContains( $derived, $keys );
			foreach ( $parts as $part ) {
				$this->assertContains( $part, $keys, "{$derived} is made from an unknown key" );
			}
		}
	}

	public function test_the_shipped_patterns_bind_only_site_data_keys_that_exist(): void {
		$patterns = Patterns\bound_keys( SiteData\SOURCE, self::INTENTS );
		$keys     = array_keys( SiteData\fields() );

		$this->assertNotSame( [], $patterns, 'No shipped pattern binds voyager/site-data.' );

		foreach ( $patterns as $name => $pattern ) {
			$this->assertNotSame( '', $pattern['title'] );
			foreach ( $pattern['keys'] as $key ) {
				$this->assertContains( $key, $keys, "{$name} binds an unknown site-data key" );
			}
		}
	}

	public function test_the_stats_section_binds_the_year_founded_the_rating_and_the_review_count(): void {
		$patterns = Patterns\bound_keys( SiteData\SOURCE, self::INTENTS );

		foreach ( [ 'voyager-open/stats' => 'Stats', 'voyager-open/stats-band' => 'Stats beside a heading' ] as $name => $title ) {
			$this->assertSame(
				[
					'title' => $title,
					'keys'  => [ 'founded', 'rating', 'review_count' ],
				],
				$patterns[ $name ] ?? null,
				"{$name} binds the three business profile figures, in order"
			);
		}

		// So Settings > Site Data shows the stats section under each of the three fields.
		$used = SiteData\used_by( $patterns );
		foreach ( [ 'founded', 'rating', 'review_count' ] as $key ) {
			$this->assertEqualsCanonicalizing( [ 'Stats', 'Stats beside a heading' ], $used[ $key ], "{$key} is shown by the stats section, and by no other pattern" );
		}
	}

	public function test_every_stored_field_gets_a_help_line(): void {
		foreach ( SiteData\fields() as $key => $field ) {
			if ( isset( $field['option'] ) ) {
				$this->assertNotSame( '', SiteData\help( $key ), "{$key} has no help line" );
			}
		}
	}
}
