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
