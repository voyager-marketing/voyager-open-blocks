<?php
/**
 * compose: how a section and layout find their pattern (contract 5 in
 * docs/v2-contracts.md), the adjacent-styles rule, the rows a refused section
 * gives, and empty, the Site Data keys a page binds whose value is empty.
 *
 * The sections and layouts come from patterns/intents/*.json, so these check
 * the patterns as they ship. scripts/e2e.mjs runs compose itself over REST.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;

require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class ComposeTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']  = [];
		$GLOBALS['voyager_test_bloginfo'] = [];
		$GLOBALS['voyager_test_filters']  = [];
	}

	/**
	 * The pattern a section and layout resolve to, against the shipped intents.
	 *
	 * @param string      $section Section, such as voyager-open/hero.
	 * @param string|null $layout  Layout name, or null for the first.
	 */
	private static function resolve( string $section, ?string $layout ): ?string {
		return Abilities\resolve_layout( Abilities\section_layouts( self::INTENTS ), $section, $layout );
	}

	/**
	 * A group block with a className, as parse_blocks() gives it.
	 *
	 * @param string $class_name className, '' for none.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<mixed>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function group( string $class_name ): array {
		return [
			'blockName'    => 'core/group',
			'attrs'        => '' === $class_name ? [] : [ 'className' => $class_name ],
			'innerBlocks'  => [],
			'innerHTML'    => '<section class="wp-block-group"></section>',
			'innerContent' => [ '<section class="wp-block-group"></section>' ],
		];
	}

	/**
	 * Each refused row as "section slot reason".
	 *
	 * @param list<array<mixed>> $rows Refused rows.
	 * @return list<string>
	 */
	private static function rows( array $rows ): array {
		return array_map(
			static fn ( array $row ): string => sprintf( '%s %s %s', (string) ( $row['section'] ?? '?' ), (string) ( $row['slot'] ?? '?' ), (string) ( $row['reason'] ?? '?' ) ),
			$rows
		);
	}

	public function test_no_layout_is_the_sections_first_layout_the_pattern_that_keeps_its_slug(): void {
		$this->assertSame( 'voyager-open/hero', self::resolve( 'voyager-open/hero', null ) );
		$this->assertSame( 'voyager-open/services', self::resolve( 'voyager-open/services', null ) );
		$this->assertSame( 'voyager-open/reviews', self::resolve( 'voyager-open/reviews', null ) );
		$this->assertSame( 'voyager-open/closing-cta', self::resolve( 'voyager-open/closing-cta', null ) );
	}

	public function test_a_layout_picks_the_intent_of_the_section_that_carries_it(): void {
		$this->assertSame( 'voyager-open/hero', self::resolve( 'voyager-open/hero', 'split' ) );
		$this->assertSame( 'voyager-open/hero-cover', self::resolve( 'voyager-open/hero', 'cover' ) );
		$this->assertSame( 'voyager-open/services-list', self::resolve( 'voyager-open/services', 'list' ) );
		$this->assertSame( 'voyager-open/services', self::resolve( 'voyager-open/services', 'grid' ) );
		$this->assertSame( 'voyager-open/reviews-single', self::resolve( 'voyager-open/reviews', 'single' ) );
	}

	public function test_a_layout_no_intent_of_the_section_carries_names_no_pattern(): void {
		$this->assertNull( self::resolve( 'voyager-open/hero', 'list' ) );
		$this->assertNull( self::resolve( 'voyager-open/services', 'cover' ) );
		// A section with one layout takes null only.
		$this->assertNull( self::resolve( 'voyager-open/closing-cta', 'split' ) );
		// A layout's own pattern is not a section: pass its section and the layout.
		$this->assertNull( self::resolve( 'voyager-open/hero-cover', null ) );
		$this->assertNull( self::resolve( 'voyager-open/no-such-section', null ) );
	}

	public function test_every_section_lists_its_first_layout_first_and_each_intent_once(): void {
		$layouts = Abilities\section_layouts( self::INTENTS );
		$files   = glob( self::INTENTS . '/*.json' );
		$this->assertIsArray( $files );

		foreach ( $layouts as $section => $entries ) {
			$this->assertNotSame( [], $entries, $section );
			$this->assertSame( $section, $entries[0]['pattern'], $section );
		}

		$this->assertSame( [ 'split', 'cover' ], array_column( $layouts['voyager-open/hero'] ?? [], 'layout' ) );
		$this->assertSame( [ 'grid', 'list' ], array_column( $layouts['voyager-open/services'] ?? [], 'layout' ) );
		$this->assertSame( [ null ], array_column( $layouts['voyager-open/closing-cta'] ?? [], 'layout' ) );
		$this->assertArrayNotHasKey( 'voyager-open/hero-cover', $layouts );
		$this->assertCount( count( $files ), array_merge( ...array_values( $layouts ) ) );
	}

	public function test_two_adjacent_sections_that_share_a_style_other_than_plain_are_refused_naming_both(): void {
		$refused = Abilities\adjacent_style_refusals( [ 'plain', 'accent', 'accent', 'plain' ] );

		$this->assertSame( [ '2 style adjacent_style' ], self::rows( $refused ) );
		$this->assertSame( [ 1, 2 ], $refused[0]['sections'] ?? null );
		$this->assertSame( 'accent', $refused[0]['style'] ?? null );
		$this->assertStringContainsString( 'Sections 1 and 2', $refused[0]['message'] ?? '' );
	}

	public function test_three_in_a_row_are_two_refusals_and_plain_or_alternating_styles_none(): void {
		$this->assertSame( [ '1 style adjacent_style', '2 style adjacent_style' ], self::rows( Abilities\adjacent_style_refusals( [ 'panel', 'panel', 'panel' ] ) ) );
		$this->assertSame( [], Abilities\adjacent_style_refusals( [ 'plain', 'plain', 'plain' ] ) );
		$this->assertSame( [], Abilities\adjacent_style_refusals( [ 'panel', 'accent', 'panel', 'inverted' ] ) );
		$this->assertSame( [], Abilities\adjacent_style_refusals( [ 'accent', 'plain', 'accent' ] ) );
		$this->assertSame( [], Abilities\adjacent_style_refusals( [ 'accent' ] ) );
	}

	public function test_a_sections_style_is_read_from_its_top_level_groups_class(): void {
		$this->assertSame( 'panel', Abilities\section_style( [ self::group( 'is-style-voyager-open-panel' ) ] ) );
		$this->assertSame( 'accent', Abilities\section_style( [ self::group( 'alignfull is-style-voyager-open-accent' ) ] ) );
		$this->assertSame( 'plain', Abilities\section_style( [ self::group( 'is-style-outline' ) ] ) );
		$this->assertSame( 'plain', Abilities\section_style( [ self::group( 'is-style-voyager-open-marquee' ) ] ) );
		$this->assertSame( 'plain', Abilities\section_style( [ self::group( '' ) ] ) );
		$this->assertSame( 'plain', Abilities\section_style( [] ) );
	}

	public function test_a_refused_section_lists_insert_rows_each_with_its_index_and_a_bound_row_says_where_to_change_it(): void {
		$bound   = [
			[
				'slot'      => 'Click to call',
				'reason'    => 'bound',
				'block'     => 'core/button',
				'path'      => '2/0/0/1/1',
				'attribute' => 'text',
				'source'    => 'voyager/site-data',
				'key'       => 'phone',
			],
		];
		$values  = Abilities\copy_values(
			[
				'Heading'       => str_repeat( 'a', 61 ),
				'Click to call' => str_repeat( 'b', 30 ),
			]
		);
		$rules   = \VoyagerOpenBlocks\Patterns\intent_meta( 'voyager-open/hero', self::INTENTS )['copy_rules'];
		$broken  = Abilities\copy_rule_refusals( $rules, $values, [], [] );
		$refused = Abilities\section_refusals( 2, $bound, $broken );

		// As insert lists them: the bound slot first, and not again for its length.
		$this->assertSame( [ '2 Click to call bound', '2 Heading too_long' ], self::rows( $refused ) );
		$this->assertSame( 'phone', $refused[0]['key'] ?? null );
		$this->assertSame( Abilities\bound_slot_line( $bound[0] ), $refused[0]['message'] ?? null );
		$this->assertSame( 61, $refused[1]['length'] ?? null );
		$this->assertSame( [], Abilities\section_refusals( 0, [], [] ) );
	}

	public function test_empty_lists_each_bound_site_data_key_whose_value_is_empty_once_in_order(): void {
		$GLOBALS['voyager_test_options'] = [
			'voyager_site_phone' => '',
			'voyager_site_email' => 'hello@example.com',
		];
		$rows                            = [
			[
				'path'     => '0/1',
				'block'    => 'core/button',
				'bindings' => [
					'text' => [
						'source' => 'voyager/site-data',
						'args'   => [ 'key' => 'phone' ],
					],
					'url'  => [
						'source' => 'voyager/site-data',
						'args'   => [ 'key' => 'phone_link' ],
					],
				],
			],
			[
				'path'     => '1/1',
				'block'    => 'core/button',
				'bindings' => [
					'url' => [
						'source' => 'voyager/site-data',
						'args'   => [ 'key' => 'email_link' ],
					],
				],
			],
			[
				'path'     => '2/0',
				'block'    => 'core/paragraph',
				'bindings' => [
					'content' => [
						'source' => 'voyager/site-data',
						'args'   => [ 'key' => 'phone' ],
					],
				],
			],
			[
				'path'     => '2/1',
				'block'    => 'core/paragraph',
				'bindings' => [
					'content' => [
						'source' => 'voyager/post-meta-text',
						'args'   => [ 'key' => 'phone' ],
					],
				],
			],
			[
				'path'     => '2/2',
				'block'    => 'core/paragraph',
				'bindings' => [
					'content' => [
						'source' => 'voyager/site-data',
						'args'   => [ 'key' => 'no_such_key' ],
					],
				],
			],
		];

		$this->assertSame( [ 'phone', 'phone_link' ], Abilities\empty_site_data( $rows ) );

		$GLOBALS['voyager_test_options']['voyager_site_phone'] = '(555) 010-0199';
		$this->assertSame( [], Abilities\empty_site_data( $rows ) );

		// Read the way the binding reads it, after the voyager_site_data_value filter.
		add_filter( 'voyager_site_data_value', static fn ( string $value, string $key ): string => 'email_link' === $key ? '' : $value, 10, 2 );
		$this->assertSame( [ 'email_link' ], Abilities\empty_site_data( $rows ) );
	}

	public function test_sections_input_defaults_to_the_first_layout_and_plain(): void {
		$sections = Abilities\sections_input(
			[
				[ 'pattern' => 'voyager-open/hero' ],
				[
					'pattern' => 'voyager-open/services',
					'layout'  => 'list',
					'style'   => 'panel',
					'copy'    => [
						'Heading' => 'What we fix',
						'Intro'   => [
							'text'   => 'Homes and shops across town.',
							'source' => 'user',
						],
					],
				],
			]
		);

		$this->assertSame(
			[
				'pattern' => 'voyager-open/hero',
				'layout'  => null,
				'style'   => 'plain',
				'copy'    => [],
			],
			$sections[0] ?? null
		);
		$this->assertSame( 'list', $sections[1]['layout'] ?? null );
		$this->assertSame( 'panel', $sections[1]['style'] ?? null );
		$this->assertSame(
			[
				'text' => 'Homes and shops across town.',
				'user' => true,
			],
			$sections[1]['copy']['Intro'] ?? null
		);
	}
}
