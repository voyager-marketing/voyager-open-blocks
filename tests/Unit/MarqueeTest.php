<?php
/**
 * The Marquee's copy of its logo row, made at render, and the reveal's
 * stylesheet: when it loads, and that its motion sits behind the
 * reduced-motion and support queries.
 *
 * render() below walks a block the way WP_Block::render() does in WordPress
 * 7.1.4 (wp-includes/class-wp-block.php): each inner block goes through
 * render_block_data with the block it sits in, is rendered, and the result
 * goes through render_block_core/group when it is a group.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockStyles;

require_once dirname( __DIR__ ) . '/validate-stubs.php';
require_once dirname( __DIR__ ) . '/block-styles-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/block-styles.php';

final class MarqueeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_enqueued_styles'] = [];
	}

	/**
	 * A parsed block, as parse_blocks() returns it.
	 *
	 * @param string             $name    Block name.
	 * @param array<mixed>       $attrs   Attributes.
	 * @param list<array<mixed>> $inner   Inner blocks.
	 * @param string             $open    HTML before the inner blocks.
	 * @param string             $close   HTML after them.
	 * @return array<mixed>
	 */
	private static function block( string $name, array $attrs = [], array $inner = [], string $open = '', string $close = '' ): array {
		$content = [ $open ];
		foreach ( $inner as $ignored ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		];
	}

	/**
	 * A logo, with an id on its image to check the copy drops ids.
	 *
	 * @param int $n Logo number.
	 * @return array<mixed>
	 */
	private static function logo( int $n ): array {
		return self::block( 'core/image', [], [], '<figure class="wp-block-image"><img id="logo-' . $n . '" src="/logo-' . $n . '.svg" alt=""/></figure>' );
	}

	/**
	 * A logo row: a flex group of three logos.
	 *
	 * @param string $id An id on the row's own tag, or none.
	 * @return array<mixed>
	 */
	private static function row( string $id = '' ): array {
		$attribute = '' === $id ? '' : ' id="' . $id . '"';

		return self::block(
			'core/group',
			[ 'layout' => [ 'type' => 'flex' ] ],
			[ self::logo( 1 ), self::logo( 2 ), self::logo( 3 ) ],
			'<div' . $attribute . ' class="wp-block-group is-layout-flex">',
			'</div>'
		);
	}

	/**
	 * A group holding these blocks, with this className.
	 *
	 * @param string             $class_name className, or none.
	 * @param list<array<mixed>> $inner      Inner blocks.
	 * @return array<mixed>
	 */
	private static function group( string $class_name, array $inner ): array {
		$attrs = '' === $class_name ? [] : [ 'className' => $class_name ];

		return self::block( 'core/group', $attrs, $inner, '<div class="wp-block-group ' . $class_name . '">', '</div>' );
	}

	/**
	 * Render a top-level block as core does, with the pack's filters.
	 *
	 * @param array<mixed> $parsed A parsed block.
	 */
	private static function render( array $parsed ): string {
		$marked = BlockStyles\mark_marquee_row( $parsed, $parsed, null );
		self::assertIsArray( $marked );

		return self::render_instance( new \WP_Block( $marked ) );
	}

	private static function render_instance( \WP_Block $block ): string {
		$html  = '';
		$index = 0;
		$parts = $block->parsed_block['innerContent'] ?? [];
		self::assertIsArray( $parts );
		foreach ( $parts as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
				continue;
			}
			$inner  = $block->inner_blocks[ $index ];
			$source = $inner->parsed_block;
			$marked = BlockStyles\mark_marquee_row( $inner->parsed_block, $source, $block );
			self::assertIsArray( $marked );
			$inner->parsed_block = $marked;
			$html               .= self::render_instance( $inner );
			++$index;
		}

		if ( 'core/group' !== ( $block->parsed_block['blockName'] ?? null ) ) {
			return $html;
		}
		$filtered = BlockStyles\copy_marquee_row( $html, $block->parsed_block );
		self::assertIsString( $filtered );

		return $filtered;
	}

	/**
	 * The opening tags of a group's direct child elements, in order.
	 *
	 * @param string $html  Rendered HTML.
	 * @param string $class A class on the group's own opening tag.
	 * @return list<string>
	 */
	private static function children( string $html, string $class ): array {
		$at = strpos( $html, $class );
		self::assertIsInt( $at, "no {$class} in {$html}" );
		$at = (int) strpos( $html, '>', $at ) + 1;

		preg_match_all( '/<(\/?)([a-z][a-z0-9-]*)\b[^>]*?(\/?)>/', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $at );
		$depth    = 0;
		$children = [];
		foreach ( $tags as $tag ) {
			$closing = '/' === $tag[1][0];
			$void    = '/' === $tag[3][0] || in_array( $tag[2][0], [ 'img', 'br', 'hr', 'input', 'source' ], true );
			if ( $closing ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
				continue;
			}
			if ( 0 === $depth ) {
				$children[] = $tag[0][0];
			}
			if ( ! $void ) {
				++$depth;
			}
		}

		return $children;
	}

	public function test_the_row_is_followed_by_one_copy_hidden_from_screen_readers_and_inert(): void {
		$html = self::render( self::group( 'alignwide is-style-voyager-open-marquee', [ self::row( 'logos' ) ] ) );
		$rows = self::children( $html, 'is-style-voyager-open-marquee' );

		$this->assertCount( 2, $rows, $html );
		$this->assertStringNotContainsString( 'aria-hidden', $rows[0] );
		$this->assertStringNotContainsString( 'inert', $rows[0] );
		$this->assertMatchesRegularExpression( '/\saria-hidden="true"/', $rows[1] );
		$this->assertMatchesRegularExpression( '/\sinert[\s>]/', $rows[1] );
		$this->assertStringContainsString( BlockStyles\MARQUEE_COPY_CLASS, $rows[1] );
		$this->assertStringContainsString( 'wp-block-group is-layout-flex', $rows[1] );

		$copy = substr( $html, (int) strpos( $html, $rows[1] ) );
		$this->assertSame( 3, substr_count( $copy, '<img ' ), 'the copy holds each logo once' );
		$this->assertDoesNotMatchRegularExpression( '/\sid=/', $copy, 'no id is left in the copy' );
		$this->assertSame( 1, substr_count( $html, 'id="logos"' ), 'the row keeps its own id' );
		$this->assertSame( 1, substr_count( $html, 'id="logo-1"' ), 'the logos keep theirs' );
	}

	public function test_the_copy_is_made_once(): void {
		// A second row, the same as the first: only the first is copied.
		$html = self::render( self::group( 'is-style-voyager-open-marquee', [ self::row(), self::row() ] ) );
		$rows = self::children( $html, 'is-style-voyager-open-marquee' );

		$this->assertCount( 3, $rows, $html );
		$this->assertSame( 1, substr_count( $html, 'aria-hidden="true"' ) );
		$this->assertSame( 1, substr_count( $html, BlockStyles\MARQUEE_COPY_CLASS ) );
		$this->assertStringContainsString( BlockStyles\MARQUEE_COPY_CLASS, $rows[1] );

		// The row's filter run on HTML that already holds a copy adds no other.
		$marked = BlockStyles\mark_marquee_row( self::row(), self::row(), new \WP_Block( self::group( 'is-style-voyager-open-marquee', [ self::row() ] ) ) );
		$this->assertIsArray( $marked );
		$this->assertTrue( $marked[ BlockStyles\MARQUEE_ROW_KEY ] ?? null );
		$once = BlockStyles\copy_marquee_row( '<div class="wp-block-group"></div>', $marked );
		$this->assertSame( '<div class="wp-block-group"></div><div class="wp-block-group voyager-open-marquee-copy" aria-hidden="true" inert></div>', $once );
	}

	public function test_nothing_is_added_outside_a_marquee_group(): void {
		$cases = [
			'a plain group'                          => self::group( 'alignwide', [ self::row() ] ),
			'a group with a longer class name'       => self::group( 'is-style-voyager-open-marquee-wide', [ self::row() ] ),
			'a group whose class name is a prefix'   => self::group( 'is-style-voyager-open', [ self::row() ] ),
			'a marquee whose first block is a logo'  => self::group( 'is-style-voyager-open-marquee', [ self::logo( 9 ), self::row() ] ),
			'groups in groups, none a marquee'       => self::group( 'section', [ self::group( 'plain', [ self::row() ] ) ] ),
		];

		foreach ( $cases as $label => $parsed ) {
			$html = self::render( $parsed );
			$this->assertStringNotContainsString( 'aria-hidden', $html, $label );
			$this->assertStringNotContainsString( 'inert', $html, $label );
			$this->assertStringNotContainsString( BlockStyles\MARQUEE_COPY_CLASS, $html, $label );
		}

		// A row in a group inside a marquee: the marquee's first group is
		// copied once, and the row inside it, a level down, is not.
		$html = self::render( self::group( 'is-style-voyager-open-marquee', [ self::group( 'inner', [ self::row() ] ) ] ) );
		$this->assertSame( 1, substr_count( $html, 'aria-hidden="true"' ), $html );
		$this->assertCount( 2, self::children( $html, 'is-style-voyager-open-marquee' ) );
		$this->assertCount( 1, self::children( $html, 'wp-block-group inner"' ) );

		// A top-level group, which has no block around it, and a marquee
		// group itself, are never marked.
		$top = BlockStyles\mark_marquee_row( self::row(), self::row(), null );
		$this->assertIsArray( $top );
		$this->assertArrayNotHasKey( BlockStyles\MARQUEE_ROW_KEY, $top );
		$this->assertSame( 'unchanged', BlockStyles\copy_marquee_row( 'unchanged', self::row() ) );
	}

	public function test_the_marquee_rules_still_everything_with_reduced_motion(): void {
		$css    = BlockStyles\marquee_css();
		$reduce = '@media (prefers-reduced-motion:reduce){';
		$still  = substr( $css, (int) strpos( $css, $reduce ) );

		$this->assertStringContainsString( 'linear infinite', $css );
		$this->assertStringNotContainsString( 'alternate', $css );
		$this->assertStringContainsString( $reduce, $css );
		$this->assertStringContainsString( '>.wp-block-group{animation:none;flex-wrap:wrap;width:auto}', $still );
		$this->assertStringContainsString( '>.' . BlockStyles\MARQUEE_COPY_CLASS . '{display:none}', $still );
	}

	public function test_the_reveal_stylesheet_loads_only_for_a_block_with_the_class(): void {
		$with = [
			'blockName' => 'core/columns',
			'attrs'     => [ 'className' => 'alignwide voyager-open-reveal' ],
		];
		$this->assertSame( '<div></div>', BlockStyles\enqueue_reveal( '<div></div>', $with ) );
		$this->assertSame( [ BlockStyles\REVEAL_HANDLE ], $GLOBALS['voyager_test_enqueued_styles'] );

		$GLOBALS['voyager_test_enqueued_styles'] = [];
		foreach ( [ [], [ 'className' => 'voyager-open-reveal-x' ], [ 'className' => 'is-style-voyager-open-marquee' ], [ 'className' => [ 'voyager-open-reveal' ] ] ] as $attrs ) {
			BlockStyles\enqueue_reveal( '', [ 'blockName' => 'core/group', 'attrs' => $attrs ] );
		}
		BlockStyles\enqueue_reveal( '', null );
		$this->assertSame( [], $GLOBALS['voyager_test_enqueued_styles'] );
	}

	public function test_the_reveal_moves_only_with_motion_on_and_view_timelines_supported(): void {
		$css    = BlockStyles\reveal_css();
		$guard  = '@media (prefers-reduced-motion:no-preference){@supports (animation-timeline:view()) and (animation-composition:add){';
		$inside = substr( $css, strlen( $guard ), (int) strpos( $css, '}}@keyframes' ) - strlen( $guard ) );

		$this->assertStringStartsWith( $guard, $css );
		$this->assertStringContainsString( '.voyager-open-reveal{', $inside );
		$this->assertStringContainsString( 'animation-timeline:view(),scroll()', $inside );
		// Outside the guards there are only the keyframes, which do nothing
		// until a rule inside them names one.
		$this->assertDoesNotMatchRegularExpression( '/[{;]animation[:-]/', substr( $css, strlen( $guard ) + strlen( $inside ) ) );
		// No fill before the range on the reveal itself: a block below the
		// first screen shows at full opacity until it scrolls into view.
		$this->assertStringContainsString( 'animation:voyager-open-reveal 1ms linear,', $inside );
		$this->assertStringNotContainsString( 'voyager-open-reveal 1ms linear both', $inside );
		$this->assertStringNotContainsString( 'backwards', $css );
	}
}
