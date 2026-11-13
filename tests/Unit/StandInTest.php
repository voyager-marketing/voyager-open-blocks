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
				$block['innerContent'][0] = (string) preg_replace( '/>[^<]*</', '>' . htmlspecialchars( $text ) . '<', $block['innerContent'][0], 1 );
				$block['innerHTML']       = $block['innerContent'][0];
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
