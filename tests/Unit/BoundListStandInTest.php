<?php
/**
 * validate's placeholder: warning for a facts block that shows visitors the
 * stand-in items its pattern ships (inc/abilities/validate.php): the proof
 * strip's facts layout bound to credentials, and both service area layouts
 * bound to service_area, while that Site Data field is empty.
 *
 * The stand-ins are read from the patterns, so these build each pattern's
 * blocks from its intent (IntentBlocks). Whether a binding falls back is the
 * one part that needs WordPress: validate asks BlockTree\with_resolution(),
 * and these ask SiteData\get_binding_value(), the callback with_resolution()
 * reaches on a site where this plugin owns the source, over the options the
 * stubs hold. scripts/e2e.mjs runs validate over REST on saved pages.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/bindings-editor-stubs.php';
require_once dirname( __DIR__ ) . '/validate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/facts.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/bindings-editor.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class BoundListStandInTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	private const TOWNS = [ 'Your first town', 'Your second town', 'Your third town' ];

	private const CREDENTIALS = [ 'Your first credential', 'Your second credential', 'Your third credential' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']  = [];
		$GLOBALS['voyager_test_filters']  = [];
		$GLOBALS['voyager_test_bloginfo'] = [];
	}

	/**
	 * The list stand-ins the pack's patterns give, as validate builds them from
	 * the registered patterns.
	 *
	 * @return array<string, list<list<string>>>
	 */
	private static function index(): array {
		$blocks = [];

		foreach ( glob( self::INTENTS . '/*.json' ) ?: [] as $file ) {
			array_push( $blocks, ...IntentBlocks::blocks( basename( $file, '.json' ) ) );
		}

		return Abilities\list_stand_in_index( $blocks );
	}

	/**
	 * Whether a block's items binding falls back now: its Site Data field is
	 * empty, so the source returns null.
	 *
	 * @param array<mixed> $block Parsed block.
	 */
	private static function falls_back( array $block ): bool {
		$args = $block['attrs']['metadata']['bindings']['items']['args'] ?? [];

		return null === SiteData\get_binding_value( is_array( $args ) ? $args : [], null, 'items' );
	}

	/**
	 * The warnings validate gives a page.
	 *
	 * @param array<int, array<mixed>> $blocks The page's blocks.
	 * @return list<array{path: string, warning: string}>
	 */
	private static function warned( array $blocks ): array {
		/** @var array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks */
		return Abilities\list_stand_in_warnings( $blocks, self::index(), static fn ( array $block ): bool => self::falls_back( $block ) );
	}

	/**
	 * Set a facts block's own items, by its label.
	 *
	 * @param array<int, array<mixed>> $blocks Blocks, by reference.
	 * @param string                   $label  The block's label.
	 * @param string                   $items  Its new items.
	 */
	private static function set_items( array &$blocks, string $label, string $items ): void {
		foreach ( $blocks as &$block ) {
			if ( 'voyager-open/facts' === $block['blockName'] && ( $block['attrs']['metadata']['name'] ?? null ) === $label ) {
				$block['attrs']['items'] = $items;
			}
			/** @var array<int, array<mixed>> $inner */
			$inner = $block['innerBlocks'];
			self::set_items( $inner, $label, $items );
			$block['innerBlocks'] = $inner;
		}
		unset( $block );
	}

	/**
	 * The three patterns whose facts block binds a Site Data list: the
	 * pattern, the field, its option, the block's label and the field's label
	 * on Settings > Site Data.
	 *
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public static function bound_lists(): array {
		return [
			'the proof strip, facts layout' => [ 'proof-strip-facts', 'credentials', 'voyager_site_credentials', 'Credentials', 'Credentials' ],
			'the service area'              => [ 'service-area', 'service_area', 'voyager_site_service_area', 'Places', 'Service area' ],
			'the service area, split'       => [ 'service-area-split', 'service_area', 'voyager_site_service_area', 'Places', 'Service area' ],
		];
	}

	public function test_the_index_holds_each_facts_block_s_stand_in_items_by_its_label(): void {
		$index = self::index();

		// A section added later adds its own label; these two must stay.
		$this->assertSame( [ self::CREDENTIALS ], $index['Credentials'] ?? null, 'the proof strip\'s facts layout' );
		$this->assertSame( [ self::TOWNS ], $index['Places'] ?? null, 'both service area layouts ship the same towns, held once' );
	}

	#[DataProvider( 'bound_lists' )]
	public function test_a_bound_list_showing_its_stand_ins_while_its_field_is_empty_is_warned_once( string $pattern, string $key, string $option, string $label, string $field ): void {
		$found = self::warned( IntentBlocks::blocks( $pattern ) );

		$this->assertCount( 1, $found, "{$pattern} with {$key} empty" );
		$this->assertStringStartsWith( sprintf( 'placeholder: voyager-open/facts "%s" at %s shows the stand-in items', $label, $found[0]['path'] ), $found[0]['warning'] );
		$this->assertStringContainsString( sprintf( 'Site Data\'s "%s" field (%s) is empty', $field, $key ), $found[0]['warning'], 'it names the field to fill' );
		$this->assertStringEndsWith( sprintf( 'or remove "%s".', $label ), $found[0]['warning'] );
	}

	#[DataProvider( 'bound_lists' )]
	public function test_a_bound_list_whose_field_holds_a_list_is_not_warned( string $pattern, string $key, string $option ): void {
		$GLOBALS['voyager_test_options'][ $option ] = "Springfield\nRiverside";

		$this->assertSame( [], self::warned( IntentBlocks::blocks( $pattern ) ), "{$pattern} with {$key} filled: visitors see that list" );
	}

	#[DataProvider( 'bound_lists' )]
	public function test_a_bound_list_holding_the_site_s_own_items_is_not_warned( string $pattern, string $key, string $option, string $label ): void {
		$blocks = IntentBlocks::blocks( $pattern );
		self::set_items( $blocks, $label, "Licensed in Ohio\nFamily owned" );
		$this->assertSame( [], self::warned( $blocks ), 'items someone wrote' );

		// One stand-in changed, or one left out, is the site's own list too.
		$shipped = Abilities\list_stand_in_index( IntentBlocks::blocks( $pattern ) )[ $label ][0] ?? [];
		$this->assertCount( 3, $shipped, "{$pattern} ships three stand-ins" );

		self::set_items( $blocks, $label, implode( "\n", [ 'Springfield', ...array_slice( $shipped, 1 ) ] ) );
		$this->assertSame( [], self::warned( $blocks ), 'the first item changed' );

		self::set_items( $blocks, $label, implode( "\n", array_slice( $shipped, 0, 2 ) ) );
		$this->assertSame( [], self::warned( $blocks ), 'the third item removed' );

		self::set_items( $blocks, $label, implode( "\n", $shipped ) );
		$this->assertCount( 1, self::warned( $blocks ), 'the control: the shipped items put back' );
	}

	public function test_the_warning_names_the_block_its_path_the_field_and_where_to_fill_it(): void {
		$this->assertSame(
			[
				[
					'path'    => '0/1/0',
					'warning' => 'placeholder: voyager-open/facts "Places" at 0/1/0 shows the stand-in items the pack ships there, because Site Data\'s "Service area" field (service_area) is empty. Fill it in under Settings > Site Data, or remove "Places".',
				],
			],
			self::warned( IntentBlocks::blocks( 'service-area' ) )
		);
	}

	public function test_a_page_holding_all_three_is_warned_once_per_facts_block_until_both_fields_are_filled(): void {
		$page = array_merge( IntentBlocks::blocks( 'proof-strip-facts' ), IntentBlocks::blocks( 'service-area' ), IntentBlocks::blocks( 'service-area-split' ) );
		$at   = static fn (): array => array_map( static fn ( array $found ): string => (string) strstr( $found['warning'], ' shows', true ), self::warned( $page ) );

		$this->assertSame(
			[
				'placeholder: voyager-open/facts "Credentials" at 0/0/0',
				'placeholder: voyager-open/facts "Places" at 1/1/0',
				'placeholder: voyager-open/facts "Places" at 2/0/0/1/0',
			],
			$at(),
			'both fields empty'
		);

		$GLOBALS['voyager_test_options']['voyager_site_credentials'] = 'Licensed in Ohio';
		$this->assertCount( 2, $at(), 'the credentials filled, the service area still empty' );

		$GLOBALS['voyager_test_options']['voyager_site_service_area'] = 'Springfield';
		$this->assertSame( [], $at(), 'both fields filled' );
	}

	public function test_items_are_compared_line_by_line_as_visitors_see_them(): void {
		$blocks = IntentBlocks::blocks( 'service-area' );
		self::set_items( $blocks, 'Places', "  Your first town \r\nYour second town\r\n\r\nYour third town\n" );

		$this->assertCount( 1, self::warned( $blocks ), 'each line trimmed and empty lines dropped, as Facts\items() shows them' );
	}

	public function test_only_a_labelled_facts_block_bound_to_site_data_holding_its_label_s_stand_ins_is_warned(): void {
		$facts = static fn ( array $attrs ): array => [
			'blockName'    => 'voyager-open/facts',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
		$bound = static fn ( string $source, string $key ): array => [ 'items' => [ 'source' => $source, 'args' => [ 'key' => $key ] ] ];
		$towns = implode( "\n", self::TOWNS );

		$page = [
			// No label: a facts block someone added and typed into.
			$facts( [ 'items' => $towns, 'metadata' => [ 'bindings' => $bound( 'voyager/site-data', 'service_area' ) ] ] ),
			// Another label's stand-ins: matched by label, never by text alone.
			$facts( [ 'items' => implode( "\n", self::CREDENTIALS ), 'metadata' => [ 'name' => 'Places', 'bindings' => $bound( 'voyager/site-data', 'service_area' ) ] ] ),
			// Bound to post meta, which this check leaves alone.
			$facts( [ 'items' => $towns, 'metadata' => [ 'name' => 'Places', 'bindings' => $bound( 'core/post-meta', 'service_area' ) ] ] ),
			// Not bound: no field to fill.
			$facts( [ 'items' => $towns, 'metadata' => [ 'name' => 'Places' ] ] ),
			// The control.
			$facts( [ 'items' => $towns, 'metadata' => [ 'name' => 'Places', 'bindings' => $bound( 'voyager/site-data', 'service_area' ) ] ] ),
		];

		$this->assertSame( [ '4' ], array_column( self::warned( $page ), 'path' ) );
	}

	public function test_a_binding_is_resolved_only_for_a_block_that_holds_a_stand_in(): void {
		$page = array_merge( IntentBlocks::blocks( 'proof-strip' ), IntentBlocks::blocks( 'service-area' ), IntentBlocks::blocks( 'contact-section' ) );
		self::set_items( $page, 'Places', 'Springfield' );
		$asked = 0;

		$found = Abilities\list_stand_in_warnings(
			$page,
			self::index(),
			static function () use ( &$asked ): bool {
				++$asked;
				return true;
			}
		);

		$this->assertSame( [], $found );
		$this->assertSame( 0, $asked, 'no source is asked about a block that shows the site\'s own items' );
	}
}
