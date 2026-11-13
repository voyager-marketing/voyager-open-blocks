<?php
/**
 * The pattern intents against contracts 3 and 5 in docs/v2-contracts.md:
 * how a section's layouts are named, and the copy rules an intent carries
 * for its named text blocks.
 *
 * Reads patterns/intents/*.json only, so no WordPress is needed.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockTree;

final class PatternLayoutsTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/**
	 * Words and marks of a factual claim: a credential, a year, a rating or an
	 * award. Default copy holds none of them (contract 3). "star" and "top"
	 * are whole words, so "started" and "stop" make no claim.
	 */
	private const CLAIMS = '/licen[cs]|insur|bonded|certif|accredit|award|rated|rating|review|\bstars?\b|years?\b|since|established|guarantee|warrant|best|\btop\b|leading|#|\d/i';

	/**
	 * What each of the proof strip's items ships as: what goes there, never a
	 * credential.
	 */
	private const CREDENTIAL_PLACEHOLDERS = [
		'Credential 1' => 'Your first credential',
		'Credential 2' => 'Your second credential',
		'Credential 3' => 'Your third credential',
		'Credential 4' => 'Your fourth credential',
	];

	/**
	 * The intents whose copy_rules may hold a claim slot, each because a test
	 * below checks what its claim slots ship.
	 */
	private const CLAIM_SECTIONS = [ 'proof-strip', 'reviews', 'reviews-single' ];

	/**
	 * What each part of a review ships as: what goes there, never a review.
	 */
	private const REVIEW_PLACEHOLDERS = [
		'rating' => 'Rated 5 out of 5',
		'quote'  => "A real customer's words go here, as they wrote them, with their permission.",
		'name'   => 'Customer name',
		'detail' => 'The service, or the month and year',
	];

	/**
	 * Every intent file, by pattern name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function intents(): array {
		$out = [];

		foreach ( glob( self::INTENTS . '/*.json' ) ?: [] as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			self::assertIsArray( $data, basename( $file ) );
			/** @var array<string, mixed> $data */
			$out[ basename( $file, '.json' ) ] = $data;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The text blocks an intent names, label => the text it ships: the
	 * blocks whose text insert's copy replaces (BlockTree\TEXT_ATTRIBUTES).
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 * @return array<string, string>
	 */
	private static function text_blocks( array $intent ): array {
		$out  = [];
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$label = $node['attrs']['metadata']['name'] ?? null;
				if ( is_string( $label ) && isset( BlockTree\TEXT_ATTRIBUTES[ (string) ( $node['block'] ?? '' ) ] ) ) {
					$out[ $label ] = is_string( $node['text'] ?? null ) ? $node['text'] : '';
				}
				$walk( is_array( $node['children'] ?? null ) ? $node['children'] : [] );
			}
		};

		$blocks = $intent['intent']['blocks'] ?? [];
		$walk( is_array( $blocks ) ? $blocks : [] );

		return $out;
	}

	/**
	 * The named text blocks whose text is bound, the slots insert refuses copy
	 * for: their own text is a fallback, shown only while the source returns
	 * nothing. A button with only its url bound keeps its own text, so it is
	 * not one of them.
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 * @return list<string>
	 */
	private static function bound_text_labels( array $intent ): array {
		$out  = [];
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$label     = $node['attrs']['metadata']['name'] ?? null;
				$attribute = BlockTree\TEXT_ATTRIBUTES[ (string) ( $node['block'] ?? '' ) ] ?? null;
				if ( is_string( $label ) && null !== $attribute && isset( $node['attrs']['metadata']['bindings'][ $attribute ] ) ) {
					$out[] = $label;
				}
				$walk( is_array( $node['children'] ?? null ) ? $node['children'] : [] );
			}
		};

		$blocks = $intent['intent']['blocks'] ?? [];
		$walk( is_array( $blocks ) ? $blocks : [] );

		return $out;
	}

	public function test_every_layout_is_named_from_its_section_and_each_section_has_one_first_layout(): void {
		$sections = [];

		foreach ( self::intents() as $name => $intent ) {
			if ( ! array_key_exists( 'section', $intent ) ) {
				$this->assertArrayNotHasKey( 'layout', $intent, "{$name} has a layout but no section" );
				continue;
			}

			$section = $intent['section'];
			$layout  = $intent['layout'] ?? null;
			$this->assertIsString( $section, $name );
			$this->assertIsString( $layout, "{$name} has a section but no layout" );
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $section, $name );
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $layout, $name );
			$this->assertContains( $name, [ $section, $section . '-' . $layout ], "{$name} is named for neither its section nor its layout" );

			$sections[ $section ][ $layout ] = $name;
		}

		foreach ( $sections as $section => $layouts ) {
			$this->assertGreaterThan( 1, count( $layouts ), "{$section} has one layout, so its intent carries no section key" );
			$this->assertCount( 1, array_keys( $layouts, $section, true ), "{$section} needs exactly one layout that keeps the section's slug" );
		}
	}

	public function test_the_hero_comes_in_two_layouts_split_first_then_cover(): void {
		$intents = self::intents();

		$this->assertSame( 'hero', $intents['hero']['section'] ?? null );
		$this->assertSame( 'split', $intents['hero']['layout'] ?? null );
		$this->assertSame( 'hero', $intents['hero-cover']['section'] ?? null );
		$this->assertSame( 'cover', $intents['hero-cover']['layout'] ?? null );

		foreach ( [ 'cta-band', 'proof-strip', 'logo-marquee', 'closing-cta', 'contact-section' ] as $one ) {
			$this->assertArrayNotHasKey( 'section', $intents[ $one ], "{$one} has one layout" );
		}
	}

	public function test_services_comes_in_two_layouts_grid_first_then_list(): void {
		$intents = self::intents();

		$this->assertSame( 'services', $intents['services']['section'] ?? null );
		$this->assertSame( 'grid', $intents['services']['layout'] ?? null );
		$this->assertSame( 'services', $intents['services-list']['section'] ?? null );
		$this->assertSame( 'list', $intents['services-list']['layout'] ?? null );
	}

	public function test_reviews_comes_in_two_layouts_grid_first_then_single(): void {
		$intents = self::intents();

		$this->assertSame( 'reviews', $intents['reviews']['section'] ?? null );
		$this->assertSame( 'grid', $intents['reviews']['layout'] ?? null );
		$this->assertSame( 'reviews', $intents['reviews-single']['section'] ?? null );
		$this->assertSame( 'single', $intents['reviews-single']['layout'] ?? null );
	}

	public function test_the_faq_comes_in_two_layouts_list_first_then_split(): void {
		$intents = self::intents();

		$this->assertSame( 'faq', $intents['faq']['section'] ?? null );
		$this->assertSame( 'list', $intents['faq']['layout'] ?? null );
		$this->assertSame( 'faq', $intents['faq-split']['section'] ?? null );
		$this->assertSame( 'split', $intents['faq-split']['layout'] ?? null );
	}

	/**
	 * The rows of items in an intent, as the design checks' layout check finds
	 * them: core/columns, and groups with the grid layout.
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 * @return list<array<mixed>>
	 */
	private static function rows_of_items( array $intent ): array {
		$out = [];
		foreach ( is_array( $intent['intent']['blocks'] ?? null ) ? $intent['intent']['blocks'] : [] as $top ) {
			foreach ( self::nodes( is_array( $top ) ? $top : [] ) as $row ) {
				$node = $row['node'];
				if ( 'core/columns' === ( $node['block'] ?? null ) || 'grid' === ( $node['attrs']['layout']['type'] ?? null ) ) {
					$out[] = $node;
				}
			}
		}

		return $out;
	}

	public function test_every_question_is_a_details_block_holding_only_its_answer_and_only_the_first_ships_open(): void {
		foreach ( [ 'faq', 'faq-split' ] as $name ) {
			$intent = self::intents()[ $name ];
			$items  = self::service_items( $intent, 'Question' );
			$this->assertSame( [ 'Question 1', 'Question 2', 'Question 3' ], array_keys( $items ), $name );

			$first = null;
			foreach ( $items as $label => $item ) {
				$at = "{$name} \"{$label}\"";
				$this->assertSame( 'core/details', $item['block'] ?? null, "{$at}: a question is a core/details block, which opens and closes with no script" );
				$this->assertSame( 'is-style-voyager-open-question', $item['attrs']['className'] ?? null, "{$at}: the Question style makes its summary a 44px tap target" );
				$this->assertSame( 'Question 1' === $label, $item['attrs']['showContent'] ?? false, "{$at}: only the first question ships open" );
				$this->assertArrayNotHasKey( 'name', $item['attrs'], "{$at}: no exclusive accordion" );
				$this->assertMatchesRegularExpression( '/\?$/', (string) ( $item['text'] ?? '' ), "{$at}: the question is its summary, asked as a visitor asks it" );

				$answer = is_array( $item['children'] ?? null ) ? $item['children'] : [];
				$this->assertCount( 1, $answer, "{$at} holds its answer and nothing else" );
				$this->assertSame( 'core/paragraph', $answer[0]['block'] ?? null, $at );
				$this->assertSame( "{$label} answer", $answer[0]['attrs']['metadata']['name'] ?? null, "{$at}: the answer carries the question's label, so repeat and omit take it along" );

				$shape = self::shape( $item, $label );
				unset( $shape['attrs']['showContent'] );
				$first = $first ?? $shape;
				$this->assertSame( $first, $shape, "{$at} is built differently from \"Question 1\"" );
			}
		}
	}

	public function test_the_faq_list_holds_no_row_and_the_split_one_row_of_two_with_the_heading_beside_the_questions(): void {
		$intents = self::intents();

		// tests/fixtures/contrast.json's layout.columns lists the patterns that hold a row.
		$this->assertSame( [], self::rows_of_items( $intents['faq'] ), 'faq: the questions sit one under another at content width' );
		foreach ( array_slice( self::nodes( $intents['faq']['intent']['blocks'][0] ), 1 ) as $row ) {
			$this->assertArrayNotHasKey( 'align', $row['node']['attrs'] ?? [], 'faq: everything in the full-width section stays at content width' );
		}

		$rows = self::rows_of_items( $intents['faq-split'] );
		$this->assertCount( 1, $rows, 'faq-split holds one row' );
		$this->assertSame( 'wide', $rows[0]['attrs']['align'] ?? null, 'faq-split: the row is at wide width' );
		$columns = is_array( $rows[0]['children'] ?? null ) ? $rows[0]['children'] : [];
		$this->assertCount( 2, $columns, 'faq-split: the heading column, then the questions' );
		$this->assertSame( [ 'Lead', 'Heading', 'Intro' ], self::labels_in( $columns[0] ), 'faq-split: the heading and intro come first, so on a phone the questions stack under them' );
		$this->assertSame( 'Question 1', self::service_items( [ 'intent' => [ 'blocks' => [ $columns[1] ] ] ], 'Question' )['Question 1']['attrs']['metadata']['name'] ?? null );
		$this->assertNotSame( false, $rows[0]['attrs']['isStackedOnMobile'] ?? true, 'faq-split: the columns stack on a phone' );
	}

	/**
	 * The blocks labelled "Service <n>" in an intent, the items insert's
	 * repeat and omit work on, by label.
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 * @param string               $word   What the items are called: "Service" or "Review".
	 * @return array<string, array<string, mixed>>
	 */
	private static function service_items( array $intent, string $word = 'Service' ): array {
		$out  = [];
		$walk = static function ( array $nodes ) use ( &$walk, &$out, $word ): void {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$label = $node['attrs']['metadata']['name'] ?? null;
				if ( is_string( $label ) && 1 === preg_match( '/^' . preg_quote( $word, '/' ) . ' \d+$/', $label ) ) {
					/** @var array<string, mixed> $node */
					$out[ $label ] = $node;
					continue;
				}
				$walk( is_array( $node['children'] ?? null ) ? $node['children'] : [] );
			}
		};

		$blocks = $intent['intent']['blocks'] ?? [];
		$walk( is_array( $blocks ) ? $blocks : [] );

		return $out;
	}

	/**
	 * An item with what may differ between items taken out: the number in its
	 * labels, its text and which icon it shows.
	 *
	 * @param array<mixed> $node   An intent node.
	 * @param string       $prefix The item's label, such as "Service 2".
	 * @return array<mixed>
	 */
	private static function shape( array $node, string $prefix ): array {
		unset( $node['text'] );
		$label = $node['attrs']['metadata']['name'] ?? null;
		if ( is_string( $label ) && is_array( $node['attrs'] ) ) {
			$node['attrs']['metadata']['name'] = '#' . substr( $label, strlen( $prefix ) );
		}
		if ( 'voyager-open/icon' === ( $node['block'] ?? null ) && is_array( $node['attrs'] ) ) {
			unset( $node['attrs']['icon'] );
		}
		if ( is_array( $node['children'] ?? null ) ) {
			$node['children'] = array_map( static fn ( mixed $child ): mixed => is_array( $child ) ? self::shape( $child, $prefix ) : $child, $node['children'] );
		}

		return $node;
	}

	/**
	 * The nodes of a tree, flattened, each with the boxes (blocks with a
	 * border, such as a card) above it.
	 *
	 * @param array<mixed> $node  An intent node.
	 * @param int          $boxes Bordered groups above it.
	 * @return list<array{node: array<mixed>, boxes: int}>
	 */
	private static function nodes( array $node, int $boxes = 0 ): array {
		$out   = [ [ 'node' => $node, 'boxes' => $boxes ] ];
		$boxed = isset( $node['attrs']['style']['border'] );
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
			if ( is_array( $child ) ) {
				array_push( $out, ...self::nodes( $child, $boxes + ( $boxed ? 1 : 0 ) ) );
			}
		}

		return $out;
	}

	public function test_every_service_item_shares_one_structure_with_one_plain_icon_and_no_card_holds_a_card(): void {
		$sizes = [];

		foreach ( [ 'services', 'services-list' ] as $name ) {
			$items = self::service_items( self::intents()[ $name ] );
			$this->assertSame( [ 'Service 1', 'Service 2', 'Service 3' ], array_keys( $items ), $name );

			$first = null;
			$icons = [];
			foreach ( $items as $label => $item ) {
				$shape = self::shape( $item, $label );
				$first = $first ?? $shape;
				$this->assertSame( $first, $shape, "{$name} \"{$label}\" is built differently from \"Service 1\"" );

				$nodes = self::nodes( $item );
				$kinds = array_map( static fn ( array $row ): string => (string) ( $row['node']['block'] ?? '' ), $nodes );
				$this->assertSame( 1, count( array_keys( $kinds, 'voyager-open/icon', true ) ), "{$name} \"{$label}\" holds one icon" );
				$this->assertSame( 1, count( array_keys( $kinds, 'core/paragraph', true ) ), "{$name} \"{$label}\" holds one short line" );

				foreach ( $nodes as $row ) {
					$node = $row['node'];
					$this->assertLessThanOrEqual( 1, $row['boxes'] + ( isset( $node['attrs']['style']['border'] ) ? 1 : 0 ), "{$name} \"{$label}\": a card holds a card" );
					if ( 'core/heading' === ( $node['block'] ?? null ) ) {
						$this->assertSame( 3, $node['level'] ?? null, "{$name} \"{$label}\": an item's heading is an h3" );
					}
					if ( 'voyager-open/icon' === ( $node['block'] ?? null ) ) {
						$attrs = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];
						$this->assertSame( [ 'icon', 'metadata', 'size' ], self::sorted_keys( $attrs ), "{$name} \"{$label}\": the icon has no label, link or animation" );
						$icons[] = $attrs['icon'];
						$sizes[] = $attrs['size'];
					}
				}
			}

			$this->assertSame( [ 'wrench', 'home', 'cog' ], $icons, "{$name}: one icon per item, chosen for its copy" );
		}

		$this->assertCount( 1, array_unique( $sizes ), 'every icon is the same size, in both layouts' );
	}

	/**
	 * The one node in a tree carrying a label, or null.
	 *
	 * @param array<mixed> $node  An intent node.
	 * @param string       $label A metadata.name.
	 * @return array<mixed>|null
	 */
	private static function labelled( array $node, string $label ): ?array {
		foreach ( self::nodes( $node ) as $row ) {
			if ( $label === ( $row['node']['attrs']['metadata']['name'] ?? null ) ) {
				return $row['node'];
			}
		}

		return null;
	}

	/**
	 * Labels in a tree, in order.
	 *
	 * @param array<mixed> $node An intent node.
	 * @return list<string>
	 */
	private static function labels_in( array $node ): array {
		$out = [];
		foreach ( self::nodes( $node ) as $row ) {
			$label = $row['node']['attrs']['metadata']['name'] ?? null;
			if ( is_string( $label ) ) {
				$out[] = $label;
			}
		}

		return $out;
	}

	public function test_every_review_shows_five_hidden_stars_its_rating_in_words_a_quote_and_who_gave_it(): void {
		$sizes = [];

		foreach ( [ 'reviews' => 3, 'reviews-single' => 1 ] as $name => $count ) {
			$items = self::service_items( self::intents()[ $name ], 'Review' );
			$this->assertSame( array_map( static fn ( int $n ): string => "Review {$n}", range( 1, $count ) ), array_keys( $items ), $name );

			$first = null;
			foreach ( $items as $label => $item ) {
				$at    = "{$name} \"{$label}\"";
				$shape = self::shape( $item, $label );
				$first = $first ?? $shape;
				$this->assertSame( $first, $shape, "{$at} is built differently from \"Review 1\"" );

				// The rating row: the five stars together, then the rating in words.
				$row = self::labelled( $item, "{$label} rating row" );
				$this->assertIsArray( $row, "{$at} has no rating row" );
				$parts = is_array( $row['children'] ?? null ) ? $row['children'] : [];
				$this->assertCount( 2, $parts, "{$at}: the rating row is the stars, then the words" );
				$this->assertSame( "{$label} stars", $parts[0]['attrs']['metadata']['name'] ?? null, $at );
				$this->assertSame( 'core/paragraph', $parts[1]['block'] ?? null, $at );
				$this->assertSame( "{$label} rating", $parts[1]['attrs']['metadata']['name'] ?? null, $at );

				$stars = is_array( $parts[0]['children'] ?? null ) ? $parts[0]['children'] : [];
				$this->assertCount( 5, $stars, "{$at}: a star row has five stars" );
				foreach ( array_values( $stars ) as $index => $star ) {
					$this->assertSame( 'voyager-open/icon', $star['block'] ?? null, $at );
					$attrs = is_array( $star['attrs'] ?? null ) ? $star['attrs'] : [];
					// No label, so inc/icons.php renders it aria-hidden: the words carry the rating.
					$this->assertSame( [ 'icon', 'metadata', 'size' ], self::sorted_keys( $attrs ), "{$at}: a star has no label, link or animation" );
					$this->assertSame( 'star', $attrs['icon'], $at );
					$this->assertSame( "{$label} star " . ( $index + 1 ), $attrs['metadata']['name'] ?? null, $at );
					$sizes[] = $attrs['size'];
				}
				$kinds = array_map( static fn ( array $node_row ): string => (string) ( $node_row['node']['block'] ?? '' ), self::nodes( $item ) );
				$this->assertSame( 5, count( array_keys( $kinds, 'voyager-open/icon', true ) ), "{$at}: the stars are the only icons" );
				$this->assertSame( [], array_keys( $kinds, 'core/heading', true ), "{$at}: a review holds no heading" );

				// The quote holds the customer's words and nothing else. Who gave
				// it follows the quote, outside it, as HTML keeps attribution.
				$quotes = array_keys( $kinds, 'core/quote', true );
				$this->assertCount( 1, $quotes, "{$at} holds one quote" );
				$quote = self::labelled( $item, "{$label} quote block" );
				$this->assertIsArray( $quote, $at );
				$this->assertSame( 'is-style-plain', $quote['attrs']['className'] ?? null, "{$at}: the quote takes the theme's Plain quote, so no box sits in the card" );
				$this->assertSame( [ "{$label} quote block", "{$label} quote" ], self::labels_in( $quote ), "{$at}: the quote holds the customer's words only" );
				$order = self::labels_in( $item );
				foreach ( [ 'rating', 'quote', 'name', 'detail' ] as $part ) {
					$this->assertContains( "{$label} {$part}", $order, $at );
				}
				$this->assertLessThan( array_search( "{$label} name", $order, true ), array_search( "{$label} quote", $order, true ), "{$at}: the name follows the quote" );
				$this->assertLessThan( array_search( "{$label} detail", $order, true ), array_search( "{$label} name", $order, true ), "{$at}: the detail follows the name" );

				foreach ( self::nodes( $item ) as $node_row ) {
					$this->assertLessThanOrEqual( 1, $node_row['boxes'] + ( isset( $node_row['node']['attrs']['style']['border'] ) ? 1 : 0 ), "{$at}: a card holds a card" );
				}
			}
		}

		$this->assertCount( 1, array_unique( $sizes ), 'every star is the same size, in both layouts' );
	}

	public function test_each_part_of_a_review_is_a_claim_and_ships_as_a_placeholder_never_a_review(): void {
		foreach ( [ 'reviews' => 3, 'reviews-single' => 1 ] as $name => $count ) {
			$intent = self::intents()[ $name ];
			$texts  = self::text_blocks( $intent );
			$parts  = [];

			foreach ( $intent['copy_rules'] as $label => $rule ) {
				$at = "{$name} \"{$label}\"";
				if ( 1 !== preg_match( '/^Review (\d+) (rating|quote|name|detail)$/', (string) $label, $match ) ) {
					$this->assertFalse( $rule['claim'], $at );
					$this->assertDoesNotMatchRegularExpression( self::CLAIMS, $texts[ (string) $label ], $at );
					continue;
				}
				$parts[] = $match[2] . ' ' . $match[1];
				$this->assertTrue( $rule['claim'], "{$at} is part of a review, so its text must come from the site owner" );
				$this->assertSame( self::REVIEW_PLACEHOLDERS[ $match[2] ], $texts[ (string) $label ], "{$at} must say what goes there, not read as a review" );
			}

			$expected = [];
			foreach ( range( 1, $count ) as $n ) {
				foreach ( array_keys( self::REVIEW_PLACEHOLDERS ) as $part ) {
					$expected[] = "{$part} {$n}";
				}
			}
			$this->assertEqualsCanonicalizing( $expected, $parts, "{$name}: every review has a rating, a quote, a name and a detail" );
		}
	}

	public function test_each_credential_in_the_proof_strip_is_a_claim_and_ships_as_a_stand_in_never_a_credential(): void {
		$intent = self::intents()['proof-strip'];
		$texts  = self::text_blocks( $intent );
		$rules  = is_array( $intent['copy_rules'] ?? null ) ? $intent['copy_rules'] : [];

		$this->assertSame( array_keys( self::CREDENTIAL_PLACEHOLDERS ), array_keys( $texts ), 'proof-strip ships four items, "Credential 1" to "Credential 4", and no other text' );

		foreach ( self::CREDENTIAL_PLACEHOLDERS as $label => $stand_in ) {
			$at = "proof-strip \"{$label}\"";
			$this->assertTrue( $rules[ $label ]['claim'] ?? null, "{$at} is a credential, so its text must come from the site owner" );
			$this->assertSame( $stand_in, $texts[ $label ], "{$at} must say what goes there, not read as a credential" );
			$this->assertDoesNotMatchRegularExpression( self::CLAIMS, $texts[ $label ], "{$at}: the stand-in makes a claim" );
		}
	}

	public function test_copy_rules_cover_every_named_text_block_and_its_default_copy_fits(): void {
		$intents = self::intents();
		$this->assertNotSame( [], $intents, 'no intent files were read' );
		$without = array_keys( array_filter( $intents, static fn ( array $intent ): bool => ! array_key_exists( 'copy_rules', $intent ) ) );
		$this->assertSame( [], $without, 'every intent carries copy_rules' );

		foreach ( $intents as $name => $intent ) {
			$rules = $intent['copy_rules'];
			$texts = self::text_blocks( $intent );
			$this->assertIsArray( $rules, $name );
			$this->assertNotSame( [], $texts, $name );
			$this->assertEqualsCanonicalizing( array_keys( $texts ), array_keys( $rules ), "{$name}: copy_rules and the named text blocks differ" );

			foreach ( $rules as $label => $rule ) {
				$at = "{$name} \"{$label}\"";
				$this->assertIsArray( $rule, $at );
				$this->assertSame( [ 'claim', 'max', 'min', 'purpose', 'required' ], self::sorted_keys( $rule ), $at );
				$this->assertIsString( $rule['purpose'], $at );
				$this->assertNotSame( '', trim( $rule['purpose'] ), $at );
				$this->assertIsInt( $rule['min'], $at );
				$this->assertIsInt( $rule['max'], $at );
				$this->assertGreaterThanOrEqual( 0, $rule['min'], $at );
				$this->assertGreaterThanOrEqual( $rule['min'], $rule['max'], $at );
				$this->assertIsBool( $rule['required'], $at );
				$this->assertIsBool( $rule['claim'], $at );

				$length = mb_strlen( $texts[ (string) $label ] );
				$this->assertGreaterThanOrEqual( $rule['min'], $length, "{$at}: the shipped copy is shorter than min" );
				$this->assertLessThanOrEqual( $rule['max'], $length, "{$at}: the shipped copy is longer than max" );
			}
		}
	}

	public function test_no_default_copy_makes_a_claim_outside_a_claim_slot_or_a_bound_slot(): void {
		$hits = [];

		foreach ( self::intents() as $name => $intent ) {
			$rules = is_array( $intent['copy_rules'] ?? null ) ? $intent['copy_rules'] : [];
			$bound = self::bound_text_labels( $intent );

			foreach ( self::text_blocks( $intent ) as $label => $text ) {
				$at = "{$name} \"{$label}\"";
				if ( true === ( $rules[ $label ]['claim'] ?? null ) ) {
					// Its text comes from the site owner. The reviews and proof
					// strip tests check that it ships a stand-in.
					$this->assertContains( $name, self::CLAIM_SECTIONS, "{$at} is a claim slot, and no test checks what it ships" );
					continue;
				}
				if ( in_array( $label, $bound, true ) ) {
					// A fallback, shown only while the source returns nothing.
					// validate's stale_fallback: warning checks it.
					continue;
				}
				if ( 1 === preg_match( self::CLAIMS, $text ) ) {
					$hits[] = "{$at}: \"{$text}\"";
				}
			}
		}

		$this->assertSame( [], $hits, 'default copy outside a claim slot or a bound slot makes a claim' );
	}

	public function test_the_claim_pattern_catches_a_planted_claim(): void {
		foreach ( [ 'Licensed and insured', 'Serving the county since 1998', '20 years in business', 'Rated 4.9 by our customers', 'Award-winning service', 'Five-star service', 'Top plumbers in the county' ] as $planted ) {
			$this->assertMatchesRegularExpression( self::CLAIMS, $planted, $planted );
		}
	}

	public function test_the_claim_pattern_passes_words_that_only_hold_star_or_top(): void {
		foreach ( [ 'Ready to get started?', 'Call, email or stop by during business hours.', 'Restart the boiler', 'Topics we cover' ] as $plain ) {
			$this->assertDoesNotMatchRegularExpression( self::CLAIMS, $plain, $plain );
		}
	}

	/**
	 * @param array<mixed> $value An array.
	 * @return list<string> Its keys, sorted.
	 */
	private static function sorted_keys( array $value ): array {
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys );

		return $keys;
	}
}
