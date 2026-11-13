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
		$this->assertSame( 'voyager-open/proof-strip', self::resolve( 'voyager-open/proof-strip', null ) );
	}

	public function test_a_layout_picks_the_intent_of_the_section_that_carries_it(): void {
		$this->assertSame( 'voyager-open/hero', self::resolve( 'voyager-open/hero', 'split' ) );
		$this->assertSame( 'voyager-open/hero-cover', self::resolve( 'voyager-open/hero', 'cover' ) );
		$this->assertSame( 'voyager-open/services-list', self::resolve( 'voyager-open/services', 'list' ) );
		$this->assertSame( 'voyager-open/services', self::resolve( 'voyager-open/services', 'grid' ) );
		$this->assertSame( 'voyager-open/reviews-single', self::resolve( 'voyager-open/reviews', 'single' ) );
		$this->assertSame( 'voyager-open/proof-strip', self::resolve( 'voyager-open/proof-strip', 'list' ) );
		$this->assertSame( 'voyager-open/proof-strip-facts', self::resolve( 'voyager-open/proof-strip', 'facts' ) );
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
		$this->assertSame( [ 'list', 'facts' ], array_column( $layouts['voyager-open/proof-strip'] ?? [], 'layout' ) );
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
				'omit'    => [],
				'repeat'  => [],
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

	public function test_sections_input_reads_omit_and_repeat_as_insert_reads_them(): void {
		$omit     = [ 'Credential 4', '' ];
		$repeat   = [
			'Credential 3' => [ 'Credential 5', '' ],
			'Credential 2' => [],
		];
		$sections = Abilities\sections_input(
			[
				[
					'pattern' => 'voyager-open/proof-strip',
					'omit'    => $omit,
					'repeat'  => $repeat,
				],
			]
		);

		$this->assertSame( [ 'Credential 4' ], $sections[0]['omit'] ?? null );
		$this->assertSame( [ 'Credential 3' => [ 'Credential 5' ] ], $sections[0]['repeat'] ?? null );
		$this->assertSame( Abilities\omit_input( $omit ), $sections[0]['omit'] ?? null, 'a section reads omit with insert\'s reader' );
		$this->assertSame( Abilities\repeat_input( $repeat ), $sections[0]['repeat'] ?? null, 'a section reads repeat with insert\'s reader' );
	}

	public function test_omit_and_repeat_have_one_input_schema_each_with_its_own_description(): void {
		$omit   = Abilities\omit_schema( 'Labels to leave out.' );
		$repeat = Abilities\repeat_schema( 'Copies to add.' );

		$this->assertSame( 'Labels to leave out.', $omit['description'] ?? null );
		$this->assertSame( 'array', $omit['type'] ?? null );
		$this->assertSame( 1, $omit['items']['minLength'] ?? null );
		$this->assertSame( 'Copies to add.', $repeat['description'] ?? null );
		$this->assertSame( 'object', $repeat['type'] ?? null );
		$this->assertSame( 10, $repeat['additionalProperties']['maxItems'] ?? null );
		$this->assertSame( array_diff_key( $omit, [ 'description' => true ] ), array_diff_key( Abilities\omit_schema( 'Other words.' ), [ 'description' => true ] ) );
	}

	/**
	 * The labels of a block tree's named blocks, in document order.
	 *
	 * @param array<int, array<mixed>> $blocks Blocks.
	 * @return list<string>
	 */
	private static function labels( array $blocks ): array {
		/** @var array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks */
		return array_values( array_filter( array_column( \VoyagerOpenBlocks\BlockTree\describe( $blocks ), 'label' ), 'is_string' ) );
	}

	/**
	 * A pattern's copy rules, as its intent holds them.
	 *
	 * @param string $name Pattern name less voyager-open/, such as proof-strip.
	 * @return array<string, array<mixed>>
	 */
	private static function rules( string $name ): array {
		return \VoyagerOpenBlocks\Patterns\intent_meta( 'voyager-open/' . $name, self::INTENTS )['copy_rules'];
	}

	public function test_fill_pattern_omits_the_fourth_credential_so_the_strip_holds_three(): void {
		$blocks  = IntentBlocks::blocks( 'proof-strip' );
		$refused = Abilities\fill_pattern( $blocks, self::rules( 'proof-strip' ), [], [ 'Credential 4' ], [] );

		$this->assertSame( [], $refused );
		$texts = array_column( IntentBlocks::texts( $blocks ), 'text', 'label' );
		$this->assertSame( [ 'Credential 1', 'Credential 2', 'Credential 3' ], array_keys( $texts ) );
		$this->assertNotContains( 'Your fourth credential', $texts );
	}

	public function test_fill_pattern_repeats_first_so_omit_can_drop_the_block_it_copied(): void {
		$blocks  = IntentBlocks::blocks( 'proof-strip' );
		$refused = Abilities\fill_pattern(
			$blocks,
			self::rules( 'proof-strip' ),
			[],
			[ 'Credential 4' ],
			[ 'Credential 4' => [ 'Credential 5', 'Credential 6' ] ]
		);

		$this->assertSame( [], $refused );
		$texts = array_column( IntentBlocks::texts( $blocks ), 'text', 'label' );
		$this->assertSame( [ 'Credential 1', 'Credential 2', 'Credential 3', 'Credential 5', 'Credential 6' ], array_keys( $texts ) );
		$this->assertSame( 'Your fourth credential', $texts['Credential 5'] ?? null, 'a copy holds the text of the block it copies until copy replaces it' );
	}

	public function test_fill_pattern_refuses_omit_of_a_required_slot_and_not_of_a_whole_item(): void {
		$strip   = IntentBlocks::blocks( 'proof-strip' );
		$refused = Abilities\fill_pattern( $strip, self::rules( 'proof-strip' ), [], [ 'Credential 1', 'Credential 4' ], [] );

		$this->assertSame( [ 'Credential 1 required' ], array_map( static fn ( array $row ): string => $row['slot'] . ' ' . $row['reason'], is_array( $refused ) ? $refused : [] ) );
		$this->assertSame( 'Credential 1', is_array( $refused ) ? ( $refused[0]['omitted'] ?? null ) : null );

		// "Service 3" holds "Service 3 heading" and "Service 3 text", both
		// required: leaving out the whole card takes them with it.
		$services = IntentBlocks::blocks( 'services' );
		$refused  = Abilities\fill_pattern( $services, self::rules( 'services' ), [], [ 'Service 3' ], [] );

		$this->assertSame( [], $refused );
		$this->assertSame( [], array_values( array_filter( self::labels( $services ), static fn ( string $label ): bool => str_starts_with( $label, 'Service 3' ) ) ) );

		// A copy repeat made is an item too, under the rule of the one it copies.
		$services = IntentBlocks::blocks( 'services' );
		$refused  = Abilities\fill_pattern( $services, self::rules( 'services' ), [], [ 'Service 4 heading' ], [ 'Service 3' => [ 'Service 4' ] ] );

		$this->assertSame( [ 'Service 4 heading required' ], array_map( static fn ( array $row ): string => $row['slot'] . ' ' . $row['reason'], is_array( $refused ) ? $refused : [] ) );
	}

	public function test_fill_pattern_returns_the_error_insert_returns_for_omit_or_repeat_it_cannot_apply(): void {
		foreach (
			[
				'voyager_open_blocks_invalid_omit'   => [ [ 'Credential 9' ], [] ],
				'voyager_open_blocks_invalid_omit '  => [ [ 'Proof strip' ], [] ],
				'voyager_open_blocks_invalid_repeat' => [ [], [ 'Credential 9' => [ 'Credential 10' ] ] ],
				'voyager_open_blocks_invalid_repeat ' => [ [], [ 'Credential 4' => [ 'Credential 3' ] ] ],
			] as $code => [ $omit, $repeat ]
		) {
			$blocks = IntentBlocks::blocks( 'proof-strip' );
			$before = $blocks;
			$result = Abilities\fill_pattern( $blocks, self::rules( 'proof-strip' ), [], $omit, $repeat );

			$this->assertInstanceOf( \WP_Error::class, $result, $code );
			$this->assertSame( trim( $code ), $result->get_error_code() );
			$this->assertSame( $before, $blocks, "{$code}: the blocks are left as they were" );
		}
	}
}
