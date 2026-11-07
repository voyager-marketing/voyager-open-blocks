<?php
/**
 * insert's parent input: putting blocks inside a container, the rules from
 * block.json that decide whether they may go there, and the lock that stops it.
 *
 * Reading the rules from the block registry needs WordPress, so these tests
 * pass the rules in. scripts/e2e.mjs covers the registry against a real site.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockTree;

final class NestedInsertTest extends TestCase {

	private const ICON = [
		'blockName'    => 'voyager-open/icon',
		'attrs'        => [ 'icon' => 'phone' ],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	/**
	 * A button as parse_blocks() returns it.
	 *
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function button( string $text ): array {
		$html = "\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"tel:+15550100\">{$text}</a></div>\n";

		return [
			'blockName'    => 'core/button',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * A header row: a group holding a site title and a buttons block with one button.
	 *
	 * @param array<string, mixed> $attrs Attributes of the row group.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function row( array $attrs = [] ): array {
		return [
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerBlocks'  => [
				[
					'blockName'    => 'core/site-title',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [],
					'innerBlocks'  => [ self::button( 'Call us' ) ],
					'innerHTML'    => "\n<div class=\"wp-block-buttons\"></div>\n",
					'innerContent' => [ "\n<div class=\"wp-block-buttons\">", null, "</div>\n" ],
				],
			],
			'innerHTML'    => "\n<div class=\"wp-block-group\">\n\n</div>\n",
			'innerContent' => [ "\n<div class=\"wp-block-group\">", null, "\n\n", null, "</div>\n" ],
		];
	}

	/**
	 * Rules shaped like core's block.json files.
	 *
	 * @return callable(string): array{parent: string[]|null, ancestor: string[]|null, allowed_blocks: string[]|null}
	 */
	private static function rules(): callable {
		$table = [
			'core/buttons'      => [ 'allowed_blocks' => [ 'core/button' ] ],
			'core/button'       => [ 'parent' => [ 'core/buttons' ] ],
			'core/list'         => [ 'allowed_blocks' => [ 'core/list-item' ] ],
			'core/list-item'    => [ 'parent' => [ 'core/list' ] ],
			'core/post-title'   => [],
			'example/in-query'  => [ 'ancestor' => [ 'core/query' ] ],
		];

		return static function ( string $name ) use ( $table ): array {
			$rule = $table[ $name ] ?? [];

			return [
				'parent'         => $rule['parent'] ?? null,
				'ancestor'       => $rule['ancestor'] ?? null,
				'allowed_blocks' => $rule['allowed_blocks'] ?? null,
			];
		};
	}

	/**
	 * A block with no inner blocks.
	 *
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function leaf( string $name ): array {
		return [
			'blockName'    => $name,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	public function test_a_block_goes_before_an_inner_block_with_a_blank_line_after_it(): void {
		$row = self::row();

		$this->assertTrue( BlockTree\insert_inner( $row, 1, [ self::ICON ] ) );
		$this->assertSame( [ 'core/site-title', 'voyager-open/icon', 'core/buttons' ], array_column( $row['innerBlocks'], 'blockName' ) );
		$this->assertSame( [ "\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "</div>\n" ], $row['innerContent'] );
		$this->assertSame( "\n<div class=\"wp-block-group\">\n\n\n\n</div>\n", $row['innerHTML'] );
	}

	public function test_a_block_goes_first(): void {
		$row = self::row();

		$this->assertTrue( BlockTree\insert_inner( $row, 0, [ self::ICON ] ) );
		$this->assertSame( 'voyager-open/icon', $row['innerBlocks'][0]['blockName'] );
		$this->assertSame( [ "\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "</div>\n" ], $row['innerContent'] );
	}

	public function test_a_block_is_appended_after_a_blank_line(): void {
		$row = self::row();

		$this->assertTrue( BlockTree\insert_inner( $row, 2, [ self::ICON, self::ICON ] ) );
		$this->assertSame( [ 'core/site-title', 'core/buttons', 'voyager-open/icon', 'voyager-open/icon' ], array_column( $row['innerBlocks'], 'blockName' ) );
		$this->assertSame( [ "\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "\n\n", null, "</div>\n" ], $row['innerContent'] );
	}

	public function test_a_container_with_no_inner_blocks_or_an_index_past_the_end_is_refused(): void {
		$empty = self::leaf( 'core/group' );
		$row   = self::row();

		$this->assertFalse( BlockTree\insert_inner( $empty, 0, [ self::ICON ] ) );
		$this->assertFalse( BlockTree\insert_inner( $row, 3, [ self::ICON ] ) );
		$this->assertSame( self::row(), $row );
	}

	public function test_a_container_allows_only_its_allowed_blocks(): void {
		$row     = self::row();
		$buttons = (array) $row['innerBlocks'][1];

		$errors = BlockTree\placement_errors( [ self::ICON ], [ $row, $buttons ], self::rules() );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'voyager-open/icon cannot go inside core/buttons, which allows only core/button', $errors[0] );
		$this->assertSame( [], BlockTree\placement_errors( [ self::ICON ], [ $row ], self::rules() ) );
		$this->assertSame( [], BlockTree\placement_errors( [ self::button( 'Email' ) ], [ $row, $buttons ], self::rules() ) );
	}

	public function test_a_containers_allowed_blocks_attribute_is_respected(): void {
		$row = self::row( [ 'allowedBlocks' => [ 'core/paragraph' ] ] );

		$errors = BlockTree\placement_errors( [ self::ICON ], [ $row ], self::rules() );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'allowedBlocks setting allows only core/paragraph', $errors[0] );
	}

	public function test_a_block_with_a_parent_rule_needs_that_parent(): void {
		$button = self::button( 'Call us' );

		$this->assertStringContainsString( 'core/button can only go directly inside core/buttons', BlockTree\placement_errors( [ $button ], [], self::rules() )[0] );
		$this->assertStringContainsString( 'core/button can only go directly inside core/buttons', BlockTree\placement_errors( [ $button ], [ self::row() ], self::rules() )[0] );
	}

	public function test_an_ancestor_rule_is_met_by_any_block_around(): void {
		$block = self::leaf( 'example/in-query' );

		$this->assertSame( [], BlockTree\placement_errors( [ $block ], [ self::leaf( 'core/query' ), self::row() ], self::rules() ) );
		$this->assertStringContainsString( 'can only go somewhere inside core/query', BlockTree\placement_errors( [ $block ], [ self::row() ], self::rules() )[0] );
	}

	public function test_blocks_inside_the_new_blocks_are_checked_too(): void {
		$list = self::leaf( 'core/list' );

		$list['innerBlocks'] = [ self::leaf( 'core/list-item' ) ];
		$this->assertSame( [], BlockTree\placement_errors( [ $list ], [], self::rules() ) );

		$list['innerBlocks'] = [ self::leaf( 'core/list-item' ), self::ICON ];
		$errors              = BlockTree\placement_errors( [ $list ], [], self::rules() );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'voyager-open/icon cannot go inside core/list', $errors[0] );
	}

	public function test_template_lock_comes_from_the_nearest_block_that_sets_it(): void {
		$open    = self::row();
		$all     = self::row( [ 'templateLock' => 'all' ] );
		$insert  = self::row( [ 'templateLock' => 'insert' ] );
		$unlock  = self::row( [ 'templateLock' => false ] );
		$content = self::row( [ 'templateLock' => 'contentOnly' ] );

		$this->assertNull( BlockTree\template_lock( [ $open ] ) );
		$this->assertSame( 'core/group is locked (templateLock "all").', BlockTree\template_lock( [ $all, $open ] ) );
		$this->assertSame( 'core/group is locked (templateLock "insert").', BlockTree\template_lock( [ $insert ] ) );
		$this->assertNull( BlockTree\template_lock( [ $all, $unlock ] ) );
		$this->assertSame( 'core/group is locked (templateLock "contentOnly").', BlockTree\template_lock( [ $content, $unlock ] ) );
	}

	public function test_ancestors_are_the_blocks_a_path_runs_through(): void {
		$blocks = [ self::row() ];

		$this->assertSame( [], BlockTree\ancestors( $blocks, [ 0 ] ) );
		$this->assertSame( [ 'core/group' ], array_column( BlockTree\ancestors( $blocks, [ 0, 1 ] ), 'blockName' ) );
		$this->assertSame( [ 'core/group', 'core/buttons' ], array_column( BlockTree\ancestors( $blocks, [ 0, 1, 0 ] ), 'blockName' ) );
		$this->assertSame( [], BlockTree\ancestors( $blocks, [ 4, 0 ] ) );
	}

	public function test_in_range_matches_inserted_blocks_and_their_inner_blocks(): void {
		$this->assertTrue( BlockTree\in_range( '2', '', 2, 2 ) );
		$this->assertTrue( BlockTree\in_range( '2/0/1', '', 2, 3 ) );
		$this->assertFalse( BlockTree\in_range( '4', '', 2, 3 ) );
		$this->assertTrue( BlockTree\in_range( '0/1', '0', 1, 1 ) );
		$this->assertTrue( BlockTree\in_range( '0/1/0', '0', 1, 1 ) );
		$this->assertFalse( BlockTree\in_range( '0/2', '0', 1, 1 ) );
		$this->assertFalse( BlockTree\in_range( '1/1', '0', 1, 1 ) );
		$this->assertFalse( BlockTree\in_range( '0', '0', 0, 0 ) );
	}

	public function test_own_text_is_the_blocks_text_without_its_inner_blocks(): void {
		$row = self::row();

		$this->assertSame( 'Call us', BlockTree\own_text( self::button( 'Call us' ) ) );
		$this->assertSame( '', BlockTree\own_text( $row ) );
		$this->assertSame( 'Fish & chips', BlockTree\own_text( self::button( 'Fish &amp; <strong>chips</strong>' ) ) );
		$this->assertSame( [ '0/1/0' => 'Call us' ], BlockTree\texts( [ $row ] ) );
	}
}
