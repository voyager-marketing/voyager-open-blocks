<?php
/**
 * validate's placeholder: warning for the stand-in text the pack ships in a
 * section's numbered items (inc/abilities/validate.php): the proof strip's
 * "Your first credential", each part of a review, each service card and
 * each process step, left on a page.
 *
 * The stand-ins are read from the patterns, so these build each pattern's
 * blocks from its intent (IntentBlocks). scripts/e2e.mjs runs validate over
 * REST on the patterns as insert and compose save them.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BlockTree;

require_once dirname( __DIR__ ) . '/bindings-editor-stubs.php';
require_once dirname( __DIR__ ) . '/validate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/bindings-editor.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class StandInTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/**
	 * Every pattern's blocks, built from its intent, by intent name.
	 *
	 * @return array<string, array<int, array<mixed>>>
	 */
	private static function patterns(): array {
		$out = [];

		foreach ( glob( self::INTENTS . '/*.json' ) ?: [] as $file ) {
			$name         = basename( $file, '.json' );
			$out[ $name ] = IntentBlocks::blocks( $name );
		}

		return $out;
	}

	/**
	 * The stand-in index the pack's patterns give, as validate builds it from
	 * the registered patterns.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	private static function index(): array {
		return Abilities\stand_in_index( array_merge( ...array_values( self::patterns() ) ) );
	}

	/**
	 * The warnings validate gives a page, as "<path> <label>".
	 *
	 * @param array<int, array<mixed>> $blocks The page's blocks.
	 * @return list<string>
	 */
	private static function warned( array $blocks ): array {
		/** @var array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks */
		$texts = IntentBlocks::texts( $blocks );

		return array_map(
			static fn ( array $found ): string => $found['path'] . ' ' . ( $texts[ $found['path'] ]['label'] ?? '?' ),
			Abilities\stand_in_warnings( $blocks, self::index() )
		);
	}

	/**
	 * How many text blocks a pattern ships in its numbered items, counted by
	 * label alone: an unbound heading, paragraph, button or list item named
	 * like "Credential 4" or "Review 2 quote".
	 *
	 * @param array<int, array<mixed>> $blocks A pattern's blocks.
	 */
	private static function item_slots( array $blocks ): int {
		$count = 0;

		foreach ( $blocks as $block ) {
			$metadata  = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : [];
			$attribute = BlockTree\TEXT_ATTRIBUTES[ (string) $block['blockName'] ] ?? null;
			$label     = $metadata['name'] ?? null;

			if ( null !== $attribute && is_string( $label ) && ! isset( $metadata['bindings'][ $attribute ] ) && 1 === preg_match( '/^\D+ \d+(?: \D.*)?$/', $label ) ) {
				++$count;
			}

			/** @var array<int, array<mixed>> $inner */
			$inner  = $block['innerBlocks'];
			$count += self::item_slots( $inner );
		}

		return $count;
	}

	/**
	 * Replace one named block's text, as copy would.
	 *
	 * @param array<int, array<mixed>> $blocks Blocks, by reference.
	 * @param string                   $label  The block's label.
	 * @param string                   $text   Its new text.
	 */
	private static function fill( array &$blocks, string $label, string $text ): void {
		foreach ( $blocks as &$block ) {
			if ( ( $block['attrs']['metadata']['name'] ?? null ) === $label && is_string( $block['innerContent'][0] ?? null ) ) {
				/** @var array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>} $block */
				$old = htmlspecialchars( BlockTree\own_text( $block ), ENT_QUOTES );
				self::assertStringContainsString( $old, $block['innerContent'][0], "{$label}: its text is in its first chunk" );

				// The text's own element: a paragraph's p, a details block's summary.
				$block['innerContent'][0] = str_replace( $old, htmlspecialchars( $text, ENT_QUOTES ), $block['innerContent'][0] );
				$block['innerHTML']       = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
			}
			/** @var array<int, array<mixed>> $inner */
			$inner = $block['innerBlocks'];
			self::fill( $inner, $label, $text );
			$block['innerBlocks'] = $inner;
		}
		unset( $block );
	}

	public function test_the_index_holds_the_text_each_numbered_item_ships_in_each_slot(): void {
		$index = self::index();

		// A section added later adds its own kind; these four must stay.
		foreach ( [ 'Credential', 'Review', 'Service', 'Step' ] as $kind ) {
			$this->assertArrayHasKey( $kind, $index, "the kinds of numbered item the pack ships text in include {$kind}" );
		}
		$this->assertArrayNotHasKey( 'Review 1 star', $index, 'a star is an item with no text, so it is no kind' );
		$this->assertSame( [ '' ], array_keys( $index['Credential'] ), 'a credential is its own text slot' );
		$this->assertEqualsCanonicalizing( [ 'Your first credential', 'Your second credential', 'Your third credential', 'Your fourth credential' ], $index['Credential'][''] );
		$this->assertEqualsCanonicalizing( [ ' rating', ' quote', ' name', ' detail' ], array_keys( $index['Review'] ) );
		$this->assertSame( [ 'A real customer\'s words go here, as they wrote them, with their permission.' ], $index['Review'][' quote'] );
		$this->assertSame( [ 'Customer name' ], $index['Review'][' name'] );
		$this->assertSame( [ 'Rated 5 out of 5' ], $index['Review'][' rating'] );
		$this->assertEqualsCanonicalizing( [ 'Repairs', 'Installation', 'Maintenance' ], $index['Service'][' heading'] );
		$this->assertCount( 3, $index['Service'][' text'] );
		$this->assertCount( 3, $index['Step'][' heading'] );
		$this->assertCount( 3, $index['Step'][' text'] );
	}

	public function test_every_stand_in_a_numbered_item_ships_is_warned_on_a_page_holding_the_pattern_as_shipped(): void {
		$expected = [
			'proof-strip'        => 4,
			'reviews'            => 12,
			'reviews-single'     => 4,
			'services'           => 6,
			'services-list'      => 6,
			'process-steps'      => 6,
			'process-steps-list' => 6,
			// Each question's summary and its answer.
			'faq'                => 6,
			'faq-split'          => 6,
		];

		foreach ( self::patterns() as $name => $blocks ) {
			$found = Abilities\stand_in_warnings( $blocks, self::index() );
			$this->assertCount( $expected[ $name ] ?? self::item_slots( $blocks ), $found, "{$name} as shipped" );

			$texts = IntentBlocks::texts( $blocks );
			foreach ( $found as $row ) {
				$text = $texts[ $row['path'] ]['text'] ?? '';
				$this->assertStringStartsWith( 'placeholder: ', $row['warning'], $name );
				$this->assertStringContainsString( sprintf( 'holds "%s", the stand-in text the pack ships there', $text ), $row['warning'], $name );
			}
		}
	}

	public function test_the_warning_names_the_block_its_label_its_path_and_the_item_to_remove(): void {
		$found = Abilities\stand_in_warnings( IntentBlocks::blocks( 'proof-strip' ), self::index() );

		$this->assertSame(
			'placeholder: core/list-item "Credential 4" at 0/0/0/3 holds "Your fourth credential", the stand-in text the pack ships there. Replace it with the site\'s own words, or remove "Credential 4".',
			$found[3]['warning'] ?? null
		);

		$found = Abilities\stand_in_warnings( IntentBlocks::blocks( 'reviews-single' ), self::index() );
		$this->assertStringEndsWith( 'or remove "Review 1".', $found[0]['warning'] ?? '' );
	}

	public function test_a_filled_strip_is_not_warned_and_the_check_still_sees_the_one_stand_in_left(): void {
		$strip = IntentBlocks::blocks( 'proof-strip' );
		self::fill( $strip, 'Credential 1', 'Licensed in Ohio' );
		self::fill( $strip, 'Credential 2', 'Insured to $2M' );
		self::fill( $strip, 'Credential 3', 'Member of the guild' );

		$this->assertSame( [ '0/0/0/3 Credential 4' ], self::warned( $strip ) );

		$this->assertTrue( BlockTree\omit( $strip, [ 'Credential 4' ] ) );
		$this->assertSame( [], self::warned( $strip ) );
	}

	public function test_a_copy_repeat_made_is_warned_while_it_holds_the_text_it_copied(): void {
		$strip = IntentBlocks::blocks( 'proof-strip' );
		$this->assertTrue( BlockTree\repeat( $strip, [ 'Credential 4' => [ 'Credential 5' ] ] ) );

		$this->assertContains( '0/0/0/4 Credential 5', self::warned( $strip ) );
	}

	public function test_the_proof_strip_s_facts_layout_adds_no_stand_in_and_is_never_warned(): void {
		// Its stand-ins are the facts block's own items, which show only
		// while Settings > Site Data holds no credentials: a bound fallback,
		// which compose names in empty, not a numbered item's text. Markup
		// cannot say whether the field is filled, so a warning here would
		// fire on a strip that shows the owner's own list.
		$facts = IntentBlocks::blocks( 'proof-strip-facts' );
		$this->assertSame( [], Abilities\item_slots( $facts ), 'the facts layout holds no numbered item' );
		$this->assertSame( [], Abilities\stand_in_warnings( $facts, self::index() ), 'proof-strip-facts as shipped' );

		$others = array_diff_key( self::patterns(), [ 'proof-strip-facts' => true ] );
		$this->assertSame( self::index(), Abilities\stand_in_index( array_merge( ...array_values( $others ) ) ), 'the facts layout adds nothing to the stand-ins validate looks for' );

		// The typed-in strip beside it is still warned on its four stand-ins.
		$this->assertCount( 4, Abilities\stand_in_warnings( IntentBlocks::blocks( 'proof-strip' ), self::index() ) );
	}

	public function test_an_item_whose_other_slots_hold_the_site_s_own_text_is_filled(): void {
		// A real five-star review keeps the shipped "Rated 5 out of 5".
		$review = IntentBlocks::blocks( 'reviews-single' );
		self::fill( $review, 'Review 1 quote', 'They fixed the leak the same day.' );
		self::fill( $review, 'Review 1 name', 'Dana R.' );
		self::fill( $review, 'Review 1 detail', 'Leak repair' );
		$this->assertSame( [], self::warned( $review ), 'a five-star review the site owner gave' );

		// Two stand-ins left: both are warned, the rating too.
		self::fill( $review, 'Review 1 detail', 'The service, or the month and year' );
		$this->assertSame( [ 'Review 1 rating', 'Review 1 detail' ], array_map( static fn ( string $row ): string => (string) substr( $row, (int) strpos( $row, ' ' ) + 1 ), self::warned( $review ) ) );

		// A service the business does call Repairs.
		$services = IntentBlocks::blocks( 'services' );
		self::fill( $services, 'Service 1 text', 'Leaks, drips and burst pipes, fixed on the first visit.' );
		$this->assertNotContains( 'Service 1 heading', array_map( static fn ( string $row ): string => (string) substr( $row, (int) strpos( $row, ' ' ) + 1 ), self::warned( $services ) ) );
	}

	/**
	 * Two-slot items with one slot filled: the pattern, the slot given copy,
	 * its text, the other slot, and whether that one is still warned.
	 *
	 * The slot left is warned unless it is the item's title, its first text
	 * slot and a short one, which a real item can share word for word with
	 * the pack's: a service called Repairs, a step called "1. Get in touch",
	 * a question a business is often asked. A body, a step's line or an
	 * answer left at the pack's text is always warned.
	 *
	 * @return array<string, array{string, string, string, string, bool}>
	 */
	public static function one_of_two(): array {
		return [
			'services: the heading filled, the line left'   => [ 'services', 'Service 1 heading', 'Leak repairs', 'Service 1 text', true ],
			'services: the line filled, the heading left'   => [ 'services', 'Service 1 text', 'Dripping taps and burst pipes, found and fixed.', 'Service 1 heading', false ],
			'services list: the heading filled'             => [ 'services-list', 'Service 2 heading', 'Water heaters', 'Service 2 text', true ],
			'process steps: the heading filled, the line left' => [ 'process-steps', 'Step 1 heading', '1. Book a visit', 'Step 1 text', true ],
			'process steps: the line filled, the heading left' => [ 'process-steps', 'Step 1 text', 'Call or write, and we find a time that suits you.', 'Step 1 heading', false ],
			'process steps list: the heading filled'        => [ 'process-steps-list', 'Step 3 heading', '3. We fix it', 'Step 3 text', true ],
			'FAQ: the question filled, the answer left'     => [ 'faq', 'Question 1', 'Do you work weekends?', 'Question 1 answer', true ],
			'FAQ: the answer filled, the question left'     => [ 'faq', 'Question 1 answer', 'Yes: we cover the whole county and the towns around it.', 'Question 1', false ],
			'FAQ beside its heading: the question filled'   => [ 'faq-split', 'Question 2', 'Is a quote free?', 'Question 2 answer', true ],
		];
	}

	#[DataProvider( 'one_of_two' )]
	public function test_one_slot_of_a_two_slot_item_filled_leaves_the_other_warned_unless_it_is_a_short_title( string $pattern, string $filled, string $text, string $left, bool $warned ): void {
		$blocks = IntentBlocks::blocks( $pattern );
		self::fill( $blocks, $filled, $text );

		$labels = array_map( static fn ( string $row ): string => (string) substr( $row, (int) strpos( $row, ' ' ) + 1 ), self::warned( $blocks ) );

		$this->assertNotContains( $filled, $labels, "{$filled} holds the site's own text" );
		$this->assertSame( $warned, in_array( $left, $labels, true ), $warned ? "{$left} still holds the pack's text" : "{$left} is the item's short title, which a real item can share" );
	}

	public function test_a_review_with_only_its_name_left_is_warned_on_the_name(): void {
		$review = IntentBlocks::blocks( 'reviews-single' );
		self::fill( $review, 'Review 1 rating', 'Rated 4 out of 5' );
		self::fill( $review, 'Review 1 quote', 'They fixed the leak the same day.' );
		self::fill( $review, 'Review 1 detail', 'Leak repair' );

		$this->assertSame( [ 'Review 1 name' ], array_map( static fn ( string $row ): string => (string) substr( $row, (int) strpos( $row, ' ' ) + 1 ), self::warned( $review ) ) );
	}

	public function test_a_first_slot_longer_than_a_title_is_warned_when_it_is_the_only_one_left(): void {
		$quote = 'A real customer\'s words go here, as they wrote them, with their permission.';
		$this->assertGreaterThan( Abilities\TITLE_MAX, mb_strlen( $quote ) );

		$item = [
			'blockName'    => 'core/group',
			'attrs'        => [ 'metadata' => [ 'name' => 'Review 7' ] ],
			'innerBlocks'  => [
				IntentBlocks::block(
					[
						'block' => 'core/paragraph',
						'text'  => $quote,
						'attrs' => [ 'metadata' => [ 'name' => 'Review 7 quote' ] ],
					]
				),
				IntentBlocks::block(
					[
						'block' => 'core/paragraph',
						'text'  => 'Dana R.',
						'attrs' => [ 'metadata' => [ 'name' => 'Review 7 name' ] ],
					]
				),
			],
			'innerHTML'    => "\n<div>\n\n</div>\n",
			'innerContent' => [ "\n<div>", null, "\n\n", null, "</div>\n" ],
		];

		// The quote comes first here, and is no title: it is a sentence.
		$this->assertSame( [ '0/0 Review 7 quote' ], self::warned( [ $item ] ) );
	}

	public function test_only_a_named_slot_of_a_numbered_item_holding_a_whole_stand_in_is_warned(): void {
		$paragraph = static fn ( string $text, array $metadata = [] ): array => IntentBlocks::block(
			[
				'block' => 'core/paragraph',
				'text'  => $text,
				'attrs' => [] === $metadata ? [] : [ 'metadata' => $metadata ],
			]
		);
		$group     = static fn ( string $label, array $children ): array => [
			'blockName'    => 'core/group',
			'attrs'        => [ 'metadata' => [ 'name' => $label ] ],
			'innerBlocks'  => $children,
			'innerHTML'    => "\n<div></div>\n",
			'innerContent' => array_merge( [ "\n<div>" ], array_fill( 0, count( $children ), null ), [ "</div>\n" ] ),
		];

		$page = [
			// No label: text someone wrote, not a pattern's slot.
			$paragraph( 'Customer name' ),
			// A label of a kind the pack ships no item of.
			$group( 'Section 2', [ $paragraph( 'Customer name', [ 'name' => 'Section 2 name' ] ) ] ),
			// Part of a stand-in only.
			$group( 'Review 7', [ $paragraph( 'Customer name, Springfield', [ 'name' => 'Review 7 name' ] ) ] ),
			// Bound: its text is a fallback.
			$group(
				'Review 8',
				[
					$paragraph(
						'Customer name',
						[
							'name'     => 'Review 8 name',
							'bindings' => [ 'content' => [ 'source' => 'voyager/site-data', 'args' => [ 'key' => 'company' ] ] ],
						]
					),
				]
			),
			// The control: a whole stand-in in a review's name slot.
			$group( 'Review 9', [ $paragraph( 'Customer name', [ 'name' => 'Review 9 name' ] ) ] ),
		];

		$this->assertSame( [ '4/0 Review 9 name' ], self::warned( $page ) );
	}
}
