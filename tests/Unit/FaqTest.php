<?php
/**
 * The FAQ's questions, each a core/details block whose summary is the
 * question: insert's copy reaches the summary, repeat adds a question with its
 * answer, omit drops one with its answer, and the Question block style makes
 * each summary a 44px tap target.
 *
 * No WordPress is loaded, and BlockTree\replace_text() needs core's block type
 * registry and HTML API, so the summary itself is written and read back over
 * REST by scripts/e2e.mjs, as every other copy slot is. These cover what
 * decides whether copy may reach it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockStyles;
use VoyagerOpenBlocks\BlockTree;
use WP_Error;

require_once dirname( __DIR__ ) . '/validate-stubs.php';
require_once dirname( __DIR__ ) . '/block-styles-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/block-styles.php';

final class FaqTest extends TestCase {

	/**
	 * One question as parse_blocks() returns it: a details block whose summary
	 * is the question, holding its answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function question( int $number ): array {
		$label  = "Question {$number}";
		$answer = "\n<p>Answer {$number}</p>\n";
		$open   = "\n<details class=\"wp-block-details is-style-voyager-open-question\"><summary>Question {$number}?</summary>";

		return [
			'blockName'    => 'core/details',
			'attrs'        => [
				'metadata'  => [ 'name' => $label ],
				'className' => 'is-style-voyager-open-question',
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [ 'metadata' => [ 'name' => "{$label} answer" ] ],
					'innerBlocks'  => [],
					'innerHTML'    => $answer,
					'innerContent' => [ $answer ],
				],
			],
			'innerHTML'    => $open . "</details>\n",
			'innerContent' => [ $open, null, "</details>\n" ],
		];
	}

	/**
	 * A section holding a heading and three questions, shaped like the FAQ.
	 *
	 * @return array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}>
	 */
	private static function faq(): array {
		$heading = "\n<h2 class=\"wp-block-heading\">Common questions</h2>\n";

		return [
			[
				'blockName'    => 'core/group',
				'attrs'        => [ 'metadata' => [ 'name' => 'FAQ' ] ],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/heading',
						'attrs'        => [ 'metadata' => [ 'name' => 'Heading' ] ],
						'innerBlocks'  => [],
						'innerHTML'    => $heading,
						'innerContent' => [ $heading ],
					],
					[
						'blockName'    => 'core/group',
						'attrs'        => [ 'metadata' => [ 'name' => 'Questions' ] ],
						'innerBlocks'  => [ self::question( 1 ), self::question( 2 ), self::question( 3 ) ],
						'innerHTML'    => "\n<div>\n\n\n\n</div>\n",
						'innerContent' => [ "\n<div>", null, "\n\n", null, "\n\n", null, "</div>\n" ],
					],
				],
				'innerHTML'    => "\n<section>\n\n</section>\n",
				'innerContent' => [ "\n<section>", null, "\n\n", null, "</section>\n" ],
			],
		];
	}

	/**
	 * Every label in a tree, in document order.
	 *
	 * @param array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks Blocks.
	 * @return string[]
	 */
	private static function labels( array $blocks ): array {
		return array_values( array_column( array_filter( BlockTree\describe( $blocks ), static fn ( array $row ): bool => isset( $row['label'] ) ), 'label' ) );
	}

	public function test_a_details_block_takes_copy_on_its_summary(): void {
		$this->assertSame( 'summary', BlockTree\TEXT_ATTRIBUTES['core/details'] ?? null, 'insert\'s copy writes a details block\'s summary, the attribute core/details declares as rich text on <summary>' );
	}

	public function test_each_question_and_its_answer_are_copy_labels(): void {
		$this->assertSame(
			[ 'Heading', 'Question 1', 'Question 1 answer', 'Question 2', 'Question 2 answer', 'Question 3', 'Question 3 answer' ],
			BlockTree\copy_labels( self::faq() )
		);
	}

	public function test_a_questions_own_text_is_its_summary_without_its_answer(): void {
		$question = self::question( 2 );

		$this->assertSame( 'Question 2?', BlockTree\own_text( $question ) );
		$this->assertSame( 'Answer 2', BlockTree\own_text( $question['innerBlocks'][0] ) );
	}

	public function test_copy_for_a_block_that_is_not_text_names_the_details_summary_among_what_copy_takes(): void {
		$blocks = self::faq();
		$result = BlockTree\set_copy( $blocks, [ 'Questions' => 'x' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_copy', $result->get_error_code() );
		$this->assertStringContainsString( '"Questions" is a core/group. copy replaces the text of a heading, paragraph, button, list item or details summary.', $result->get_error_message() );
		$this->assertStringContainsString( '"Question 1"', $result->get_error_message(), 'the valid labels include each question' );
		$this->assertSame( self::faq(), $blocks );
	}

	public function test_a_fourth_question_repeats_with_its_answer_under_its_own_label(): void {
		$blocks = self::faq();

		$this->assertContains( 'Question 3', BlockTree\repeatable_labels( $blocks ) );
		$this->assertNotContains( 'Questions', BlockTree\repeatable_labels( $blocks ), 'the group of questions holds labels that do not start with its own' );
		$this->assertTrue( BlockTree\repeat( $blocks, [ 'Question 3' => [ 'Question 4' ] ] ) );
		$this->assertSame(
			[ 'FAQ', 'Heading', 'Questions', 'Question 1', 'Question 1 answer', 'Question 2', 'Question 2 answer', 'Question 3', 'Question 3 answer', 'Question 4', 'Question 4 answer' ],
			self::labels( $blocks )
		);
		$this->assertContains( 'Question 4', BlockTree\copy_labels( $blocks ) );
		$this->assertContains( 'Question 4 answer', BlockTree\copy_labels( $blocks ) );

		// The copy keeps the details block's HTML, summary and answer, until copy replaces them.
		$fourth = $blocks[0]['innerBlocks'][1]['innerBlocks'][3] ?? null;
		$this->assertIsArray( $fourth );
		$this->assertSame( self::question( 3 )['innerContent'], $fourth['innerContent'] );
		$this->assertSame( 'core/details', $fourth['blockName'] );
	}

	public function test_omitting_a_question_takes_its_answer_with_it(): void {
		$blocks = self::faq();

		$this->assertTrue( BlockTree\repeat( $blocks, [ 'Question 3' => [ 'Question 4' ] ] ) );
		$this->assertTrue( BlockTree\omit( $blocks, [ 'Question 2' ] ) );
		$this->assertSame(
			[ 'FAQ', 'Heading', 'Questions', 'Question 1', 'Question 1 answer', 'Question 3', 'Question 3 answer', 'Question 4', 'Question 4 answer' ],
			self::labels( $blocks )
		);
	}

	public function test_the_question_style_makes_each_summary_a_44px_tap_target_and_sets_no_colour_or_type(): void {
		$css     = BlockStyles\question_css();
		$details = '.wp-block-details.is-style-voyager-open-question';

		$this->assertMatchesRegularExpression( '/' . preg_quote( $details . '>summary{', '/' ) . '[^}]*\bmin-height:44px\b/', $css );
		$this->assertMatchesRegularExpression( '/' . preg_quote( $details . '>summary{', '/' ) . '[^}]*\bbox-sizing:border-box\b/', $css, 'the padding counts inside the 44px, so the height is the theme\'s line or 44px, whichever is taller' );
		$this->assertDoesNotMatchRegularExpression( '/(?<![-\w])(color|background|font[\w-]*|line-height|border-radius|box-shadow)\s*:/', $css, 'the theme sets the colour and the type' );
		$this->assertStringContainsString( $details . '{border-block-start:1px solid}', $css, 'a rule in the text colour above each question' );
	}
}
