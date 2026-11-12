<?php
/**
 * BlockTree\repeat(): insert's `repeat` input, which adds copies of a named
 * block right after it, such as a fifth and sixth credential in a proof strip.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockTree;
use WP_Error;

final class RepeatTest extends TestCase {

	/**
	 * A list item as parse_blocks() returns it.
	 *
	 * @param array<string, mixed> $attrs More attributes.
	 * @return array<string, mixed>
	 */
	private static function item( string $label, array $attrs = [] ): array {
		return [
			'blockName'    => 'core/list-item',
			'attrs'        => [ 'metadata' => [ 'name' => $label ] ] + $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => "\n<li>{$label}</li>\n",
			'innerContent' => [ "\n<li>{$label}</li>\n" ],
		];
	}

	/**
	 * A section holding a list of named items, shaped like the proof strip.
	 *
	 * @param array<int, array<string, mixed>>|null $items List items, three by default.
	 * @return array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}>
	 */
	private static function strip( ?array $items = null ): array {
		$items   = $items ?? [ self::item( 'One' ), self::item( 'Two' ), self::item( 'Three' ) ];
		$content = [ "\n<ul>" ];
		foreach ( array_keys( $items ) as $index ) {
			if ( $index > 0 ) {
				$content[] = "\n\n";
			}
			$content[] = null;
		}
		$content[] = "</ul>\n";

		return [
			[
				'blockName'    => 'core/group',
				'attrs'        => [ 'metadata' => [ 'name' => 'Strip' ] ],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/list',
						'attrs'        => [ 'metadata' => [ 'name' => 'List' ] ],
						'innerBlocks'  => $items,
						'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
						'innerContent' => $content,
					],
				],
				'innerHTML'    => "\n<div></div>\n",
				'innerContent' => [ "\n<div>", null, "</div>\n" ],
			],
		];
	}

	/**
	 * Labels of the list's items.
	 *
	 * @param array<int, array<string, mixed>> $blocks Strip.
	 * @return string[]
	 */
	private static function labels( array $blocks ): array {
		$labels = [];
		foreach ( (array) $blocks[0]['innerBlocks'][0]['innerBlocks'] as $inner ) {
			$labels[] = BlockTree\metadata( (array) $inner )['name'] ?? '';
		}
		return $labels;
	}

	private function refused( mixed $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_repeat', $result->get_error_code() );

		return $result->get_error_message();
	}

	public function test_copies_go_right_after_the_block_with_the_new_labels(): void {
		$blocks = self::strip();

		$this->assertTrue( BlockTree\repeat( $blocks, [ 'Three' => [ 'Four', 'Five' ] ] ) );
		$this->assertSame( [ 'One', 'Two', 'Three', 'Four', 'Five' ], self::labels( $blocks ) );
		$this->assertSame( self::strip( [ self::item( 'One' ), self::item( 'Two' ), self::item( 'Three' ), self::item( 'Four' ), self::item( 'Five' ) ] )[0]['innerBlocks'][0]['innerContent'], $blocks[0]['innerBlocks'][0]['innerContent'] );
	}

	public function test_a_copy_of_a_middle_block_keeps_the_order(): void {
		$blocks = self::strip();

		$this->assertTrue( BlockTree\repeat( $blocks, [ 'One' => [ 'One again' ] ] ) );
		$this->assertSame( [ 'One', 'One again', 'Two', 'Three' ], self::labels( $blocks ) );
	}

	public function test_a_copy_keeps_the_html_and_attributes_until_copy_replaces_its_text(): void {
		$blocks = self::strip( [ self::item( 'One', [ 'className' => 'is-bold' ] ) ] );

		$this->assertTrue( BlockTree\repeat( $blocks, [ 'One' => [ 'Two' ] ] ) );

		$copy = $blocks[0]['innerBlocks'][0]['innerBlocks'][1];
		$this->assertSame( "\n<li>One</li>\n", $copy['innerHTML'] );
		$this->assertSame( [ 'metadata' => [ 'name' => 'Two' ], 'className' => 'is-bold' ], $copy['attrs'] );
	}

	public function test_two_labels_repeat_in_one_call_and_omit_can_then_drop_the_original(): void {
		$blocks = self::strip();

		$this->assertTrue( BlockTree\repeat( $blocks, [ 'One' => [ 'Four' ], 'Three' => [ 'Five' ] ] ) );
		$this->assertTrue( BlockTree\omit( $blocks, [ 'Three' ] ) );
		$this->assertSame( [ 'One', 'Four', 'Two', 'Five' ], self::labels( $blocks ) );
	}

	public function test_an_unknown_label_is_refused_with_the_labels_that_can_repeat(): void {
		$blocks = self::strip();

		$this->assertStringContainsString( 'Labels you can repeat: "One", "Two", "Three".', $this->refused( BlockTree\repeat( $blocks, [ 'Four' => [ 'Five' ] ] ) ) );
		$this->assertSame( self::strip(), $blocks );
	}

	/**
	 * A block as parse_blocks() returns it, holding inner blocks in one slot each.
	 *
	 * @param array<string, mixed>             $attrs Attributes.
	 * @param array<int, array<string, mixed>> $inner Inner blocks.
	 * @return array<string, mixed>
	 */
	private static function holder( string $name, array $attrs, array $inner ): array {
		$content = [ "\n<div>" ];
		foreach ( array_keys( $inner ) as $index ) {
			if ( $index > 0 ) {
				$content[] = "\n\n";
			}
			$content[] = null;
		}
		$content[] = "</div>\n";

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
			'innerContent' => $content,
		];
	}

	/**
	 * A text block with a label.
	 *
	 * @param array<string, mixed> $attrs More attributes.
	 * @return array<string, mixed>
	 */
	private static function text( string $label, array $attrs = [] ): array {
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [ 'metadata' => [ 'name' => $label ] ] + $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => "\n<p>{$label}</p>\n",
			'innerContent' => [ "\n<p>{$label}</p>\n" ],
		];
	}

	/**
	 * A card in a column, shaped like the services grid: the column "Card N"
	 * holds the group "Card N box", which holds "Card N heading" and "Card N text".
	 *
	 * @param array<int, array<string, mixed>> $more More blocks in the box.
	 * @return array<string, mixed>
	 */
	private static function card( int $number, array $more = [] ): array {
		$label = "Card {$number}";

		return self::holder(
			'core/column',
			[ 'metadata' => [ 'name' => $label ] ],
			[ self::holder( 'core/group', [ 'metadata' => [ 'name' => "{$label} box" ] ], [ self::text( "{$label} heading" ), self::text( "{$label} text" ), ...$more ] ) ]
		);
	}

	/**
	 * A section holding a row of cards.
	 *
	 * @param array<int, array<string, mixed>>|null $cards Columns, three cards by default.
	 * @param array<int, array<string, mixed>>      $after Blocks after the row.
	 * @return array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}>
	 */
	private static function cards( ?array $cards = null, array $after = [] ): array {
		$cards = $cards ?? [ self::card( 1 ), self::card( 2 ), self::card( 3 ) ];
		/** @var array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>} $section */
		$section = self::holder( 'core/group', [ 'metadata' => [ 'name' => 'Section' ] ], [ self::holder( 'core/columns', [ 'metadata' => [ 'name' => 'Row' ] ], $cards ), ...$after ] );

		return [ $section ];
	}

	/**
	 * Every label in a tree, in document order.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return string[]
	 */
	private static function all_labels( array $blocks ): array {
		/** @var array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks */
		return array_values( array_column( array_filter( BlockTree\describe( $blocks ), static fn ( array $row ): bool => isset( $row['label'] ) ), 'label' ) );
	}

	public function test_a_card_repeats_with_the_labels_inside_it_under_the_new_label(): void {
		$blocks = self::cards();

		$this->assertContains( 'Card 3', BlockTree\repeatable_labels( $blocks ) );
		$this->assertTrue( BlockTree\repeat( $blocks, [ 'Card 3' => [ 'Card 4', 'Card 5' ] ] ) );
		$this->assertSame(
			[
				'Section',
				'Row',
				'Card 1', 'Card 1 box', 'Card 1 heading', 'Card 1 text',
				'Card 2', 'Card 2 box', 'Card 2 heading', 'Card 2 text',
				'Card 3', 'Card 3 box', 'Card 3 heading', 'Card 3 text',
				'Card 4', 'Card 4 box', 'Card 4 heading', 'Card 4 text',
				'Card 5', 'Card 5 box', 'Card 5 heading', 'Card 5 text',
			],
			self::all_labels( $blocks )
		);

		// A copy keeps the original's HTML; only the labels in its comments change.
		$row = $blocks[0]['innerBlocks'][0];
		$this->assertIsArray( $row );
		$this->assertSame( "\n<p>Card 3 heading</p>\n", $row['innerBlocks'][3]['innerBlocks'][0]['innerBlocks'][0]['innerHTML'] ?? null );
		$this->assertSame( self::holder( 'core/columns', [], array_fill( 0, 5, [] ) )['innerContent'], $row['innerContent'] );
	}

	public function test_a_card_and_omit_give_a_row_of_two(): void {
		$blocks = self::cards();

		$this->assertTrue( BlockTree\omit( $blocks, [ 'Card 2' ] ) );
		$this->assertSame( [ 'Section', 'Row', 'Card 1', 'Card 1 box', 'Card 1 heading', 'Card 1 text', 'Card 3', 'Card 3 box', 'Card 3 heading', 'Card 3 text' ], self::all_labels( $blocks ) );
	}

	public function test_a_block_holding_a_label_that_does_not_start_with_its_own_and_a_space_cannot_repeat(): void {
		$stray  = self::holder( 'core/column', [ 'metadata' => [ 'name' => 'Card 1' ] ], [ self::text( 'Heading' ) ] );
		$prefix = self::holder( 'core/column', [ 'metadata' => [ 'name' => 'Card 2' ] ], [ self::text( 'Card 20 heading' ) ] );
		$blocks = self::cards( [ $stray, $prefix, self::card( 3 ) ] );

		$this->assertStringContainsString( 'does not start with "Card 1 "', $this->refused( BlockTree\repeat( $blocks, [ 'Card 1' => [ 'Card 4' ] ] ) ) );
		$this->refused( BlockTree\repeat( $blocks, [ 'Card 2' => [ 'Card 4' ] ] ) );
		$this->assertNotContains( 'Card 1', BlockTree\repeatable_labels( $blocks ) );
		$this->assertNotContains( 'Card 2', BlockTree\repeatable_labels( $blocks ) );
		$this->assertContains( 'Card 3', BlockTree\repeatable_labels( $blocks ) );
		$this->assertSame( self::cards( [ $stray, $prefix, self::card( 3 ) ] ), $blocks );
	}

	public function test_a_label_a_copy_would_give_an_inner_block_must_be_unused(): void {
		$blocks = self::cards( null, [ self::text( 'Card 4 heading' ) ] );

		$this->assertStringContainsString( '"Card 4 heading" is taken', $this->refused( BlockTree\repeat( $blocks, [ 'Card 3' => [ 'Card 4' ] ] ) ) );
		$this->assertSame( self::cards( null, [ self::text( 'Card 4 heading' ) ] ), $blocks );
	}

	public function test_an_anchor_inside_a_block_stops_it_repeating(): void {
		$anchored = self::card( 2, [ self::text( 'Card 2 note', [ 'anchor' => 'note' ] ) ] );
		$blocks   = self::cards( [ self::card( 1 ), $anchored, self::card( 3 ) ] );

		$this->refused( BlockTree\repeat( $blocks, [ 'Card 2' => [ 'Card 4' ] ] ) );
		$this->assertNotContains( 'Card 2', BlockTree\repeatable_labels( $blocks ) );
		$this->assertContains( 'Card 1', BlockTree\repeatable_labels( $blocks ) );
	}

	public function test_the_whole_section_and_a_block_holding_named_blocks_cannot_repeat(): void {
		$blocks = self::strip();

		$this->refused( BlockTree\repeat( $blocks, [ 'Strip' => [ 'Strip 2' ] ] ) );
		$this->refused( BlockTree\repeat( $blocks, [ 'List' => [ 'List 2' ] ] ) );
		$this->assertSame( self::strip(), $blocks );
	}

	public function test_a_new_label_must_be_unused_and_unique(): void {
		$blocks = self::strip();

		$this->assertStringContainsString( '"Two" is taken', $this->refused( BlockTree\repeat( $blocks, [ 'Three' => [ 'Two' ] ] ) ) );
		$this->assertStringContainsString( 'need different labels', $this->refused( BlockTree\repeat( $blocks, [ 'Three' => [ 'Four', 'Four' ] ] ) ) );
		$this->refused( BlockTree\repeat( $blocks, [ 'One' => [ 'Four' ], 'Two' => [ 'Four' ] ] ) );
		$this->assertSame( self::strip(), $blocks );
	}

	public function test_a_block_with_an_anchor_cannot_repeat(): void {
		$blocks = self::strip( [ self::item( 'One', [ 'anchor' => 'first' ] ), self::item( 'Two' ) ] );

		$this->refused( BlockTree\repeat( $blocks, [ 'One' => [ 'Three' ] ] ) );
		$this->assertSame( [ 'Two' ], BlockTree\repeatable_labels( $blocks ) );
	}

	public function test_a_label_two_blocks_share_cannot_repeat(): void {
		$blocks = self::strip( [ self::item( 'One' ), self::item( 'One' ) ] );

		$this->assertStringContainsString( 'more than one block', $this->refused( BlockTree\repeat( $blocks, [ 'One' => [ 'Two' ] ] ) ) );
	}
}
