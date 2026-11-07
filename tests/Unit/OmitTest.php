<?php
/**
 * BlockTree\omit(): insert's `omit` input, which leaves named blocks out of a
 * copied pattern, such as the fourth credential of a proof strip.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockTree;
use WP_Error;

final class OmitTest extends TestCase {

	/**
	 * A list item as parse_blocks() returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function item( string $label ): array {
		return [
			'blockName'    => 'core/list-item',
			'attrs'        => [ 'metadata' => [ 'name' => $label ] ],
			'innerBlocks'  => [],
			'innerHTML'    => "\n<li>{$label}</li>\n",
			'innerContent' => [ "\n<li>{$label}</li>\n" ],
		];
	}

	/**
	 * A section holding a list of three named items, shaped like the proof strip.
	 *
	 * @return array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}>
	 */
	private static function strip(): array {
		return [
			[
				'blockName'    => 'core/group',
				'attrs'        => [ 'metadata' => [ 'name' => 'Strip' ] ],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/list',
						'attrs'        => [ 'metadata' => [ 'name' => 'List' ] ],
						'innerBlocks'  => [ self::item( 'One' ), self::item( 'Two' ), self::item( 'Three' ) ],
						'innerHTML'    => "\n<ul>\n\n\n\n</ul>\n",
						'innerContent' => [ "\n<ul>", null, "\n\n", null, "\n\n", null, "</ul>\n" ],
					],
				],
				'innerHTML'    => "\n<div></div>\n",
				'innerContent' => [ "\n<div>", null, "</div>\n" ],
			],
		];
	}

	/**
	 * Omit labels from a fresh strip and return its list block.
	 *
	 * @param string[] $labels Labels to omit.
	 * @return array<mixed>
	 */
	private function list_after( array $labels ): array {
		$blocks = self::strip();

		$this->assertTrue( BlockTree\omit( $blocks, $labels ) );

		return $blocks[0]['innerBlocks'][0];
	}

	/**
	 * Labels of a block's inner blocks.
	 *
	 * @param array<mixed> $block Block.
	 * @return string[]
	 */
	private static function labels( array $block ): array {
		$labels = [];
		foreach ( (array) $block['innerBlocks'] as $inner ) {
			$labels[] = BlockTree\metadata( (array) $inner )['name'] ?? '';
		}
		return $labels;
	}

	public function test_the_last_item_goes_with_the_gap_before_it(): void {
		$list = $this->list_after( [ 'Three' ] );

		$this->assertSame( [ 'One', 'Two' ], self::labels( $list ) );
		$this->assertSame( [ "\n<ul>", null, "\n\n", null, "</ul>\n" ], $list['innerContent'] );
		$this->assertSame( "\n<ul>\n\n</ul>\n", $list['innerHTML'] );
	}

	public function test_the_first_item_goes_with_the_gap_after_it(): void {
		$list = $this->list_after( [ 'One' ] );

		$this->assertSame( [ 'Two', 'Three' ], self::labels( $list ) );
		$this->assertSame( [ "\n<ul>", null, "\n\n", null, "</ul>\n" ], $list['innerContent'] );
	}

	public function test_two_items_go_at_once(): void {
		$list = $this->list_after( [ 'One', 'Three' ] );

		$this->assertSame( [ 'Two' ], self::labels( $list ) );
		$this->assertSame( [ "\n<ul>", null, "</ul>\n" ], $list['innerContent'] );
	}

	public function test_every_item_can_go(): void {
		$list = $this->list_after( [ 'One', 'Two', 'Three' ] );

		$this->assertSame( [], $list['innerBlocks'] );
		$this->assertSame( [ "\n<ul>", "</ul>\n" ], $list['innerContent'] );
		$this->assertSame( "\n<ul></ul>\n", $list['innerHTML'] );
	}

	public function test_a_block_inside_an_omitted_block_goes_with_it(): void {
		$blocks = self::strip();

		$this->assertTrue( BlockTree\omit( $blocks, [ 'List', 'Two' ] ) );
		$this->assertSame( [], $blocks[0]['innerBlocks'] );
		$this->assertSame( [ "\n<div>", "</div>\n" ], $blocks[0]['innerContent'] );
	}

	public function test_the_whole_section_cannot_be_omitted(): void {
		$blocks = self::strip();
		$result = BlockTree\omit( $blocks, [ 'Strip' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_omit', $result->get_error_code() );
		$this->assertSame( self::strip(), $blocks );
	}

	public function test_an_unknown_label_is_refused_with_the_labels_that_can_go(): void {
		$blocks = self::strip();
		$result = BlockTree\omit( $blocks, [ 'Four' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_omit', $result->get_error_code() );
		$this->assertStringContainsString( '"List", "One", "Two", "Three"', $result->get_error_message() );
		$this->assertSame( self::strip(), $blocks );
	}

	public function test_paths_compare_in_document_order(): void {
		$this->assertGreaterThan( 0, BlockTree\compare_paths( '0/1', '0/0/5' ) );
		$this->assertLessThan( 0, BlockTree\compare_paths( '0/0', '0/0/1' ) );
		$this->assertLessThan( 0, BlockTree\compare_paths( '2', '10' ) );
		$this->assertSame( 0, BlockTree\compare_paths( '1/2', '1/2' ) );
	}
}
