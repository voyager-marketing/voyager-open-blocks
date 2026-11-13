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
	 * Words and marks of a factual claim or a promise: a credential, a year, a
	 * rating, an award, a price or a time. Default copy holds none of them
	 * (contract 3). "star", "top" and "free" are whole words, so "started",
	 * "stop" and "freeze" make no claim.
	 */
	private const CLAIMS = '/licen[cs]|insur|bonded|certif|accredit|award|rated|rating|review|\bstars?\b|years?\b|since|established|guarantee|warrant|best|\btop\b|leading|\bfree\b|same[- ]day|#|\d/i';

	/**
	 * How a step's heading starts: its number, a full stop and a space
	 * (DESIGN.md, Step). An ordered list cannot hold the steps, because a
	 * core/list-item holds only a nested core/list, not an h3 and a paragraph.
	 */
	private const STEP_NUMBER = '/^(\d+)\. \S/';

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
	private const CLAIM_SECTIONS = [ 'proof-strip', 'reviews', 'reviews-single', 'stats', 'stats-band' ];

	/** The stats section's two layouts. */
	private const STATS = [ 'stats', 'stats-band' ];

	/**
	 * The stats section's figures, in order, by the label of each figure's
	 * block: the Site Data key its value binds, the text the value shows while
	 * that field is empty, which names the field, and the label under it,
	 * which says what the figure is.
	 */
	private const FIGURES = [
		'Founded'      => [
			'key'      => 'founded',
			'fallback' => 'Founded',
			'label'    => 'Year founded',
		],
		'Rating'       => [
			'key'      => 'rating',
			'fallback' => 'Score',
			'label'    => 'Average rating',
		],
		'Review count' => [
			'key'      => 'review_count',
			'fallback' => 'Count',
			'label'    => 'Reviews',
		],
	];

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

	public function test_process_steps_comes_in_two_layouts_row_first_then_list(): void {
		$intents = self::intents();

		$this->assertSame( 'process-steps', $intents['process-steps']['section'] ?? null );
		$this->assertSame( 'row', $intents['process-steps']['layout'] ?? null );
		$this->assertSame( 'process-steps', $intents['process-steps-list']['section'] ?? null );
		$this->assertSame( 'list', $intents['process-steps-list']['layout'] ?? null );
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
	 * @param string               $word   What the items are called: "Service", "Review" or "Step".
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

	public function test_every_step_shares_one_structure_and_its_heading_starts_with_its_number(): void {
		$intents = self::intents();
		$insides = [];

		// In the row, each step is a column, so repeat adds a column and omit
		// takes one away. In the list, each step is a group.
		foreach ( [ 'process-steps' => 'core/column', 'process-steps-list' => 'core/group' ] as $name => $wrapper ) {
			$this->assertArrayHasKey( $name, $intents, "no intent for voyager-open/{$name}" );
			$steps = self::service_items( $intents[ $name ], 'Step' );
			$this->assertSame( [ 'Step 1', 'Step 2', 'Step 3' ], array_keys( $steps ), $name );

			$first = null;
			foreach ( $steps as $label => $step ) {
				$at    = "{$name} \"{$label}\"";
				$shape = self::shape( $step, $label );
				$first = $first ?? $shape;
				$this->assertSame( $first, $shape, "{$at} is built differently from \"Step 1\"" );
				$this->assertSame( $wrapper, $step['block'] ?? null, $at );

				// An h3 and one short line, and nothing else: no icon, no button, no card.
				$children = is_array( $step['children'] ?? null ) ? array_values( $step['children'] ) : [];
				$this->assertSame(
					[ [ 'core/heading', "{$label} heading" ], [ 'core/paragraph', "{$label} text" ] ],
					array_map( static fn ( mixed $child ): array => [ is_array( $child ) ? ( $child['block'] ?? null ) : null, is_array( $child ) ? ( $child['attrs']['metadata']['name'] ?? null ) : null ], $children ),
					"{$at} holds its heading, then one short line"
				);
				$this->assertSame( 3, $children[0]['level'] ?? null, "{$at}: a step's heading is an h3" );
				foreach ( self::nodes( $step ) as $row ) {
					$this->assertArrayNotHasKey( 'border', is_array( $row['node']['attrs']['style'] ?? null ) ? $row['node']['attrs']['style'] : [], "{$at}: a step is not a card, and holds none" );
				}

				// The number is in the heading's own text, so a screen reader
				// hears it with the heading and a sighted reader sees it.
				$heading = is_string( $children[0]['text'] ?? null ) ? $children[0]['text'] : '';
				$this->assertSame( 1, preg_match( self::STEP_NUMBER, $heading, $match ), "{$at}: the heading \"{$heading}\" starts with the step's number" );
				$this->assertSame( substr( (string) $label, strlen( 'Step ' ) ), $match[1], "{$at}: the heading's number is the step's own" );
			}

			$insides[ $name ] = array_map( static fn ( mixed $child ): mixed => is_array( $child ) ? self::shape( $child, 'Step 1' ) : $child, $steps['Step 1']['children'] ?? [] );
		}

		$this->assertSame( $insides['process-steps'], $insides['process-steps-list'], 'a step holds the same blocks in both layouts, with its number in its heading in both' );

		$row = null;
		foreach ( self::nodes( $intents['process-steps']['intent']['blocks'][0] ) as $found ) {
			if ( 'core/columns' === ( $found['node']['block'] ?? null ) ) {
				$row = $found['node'];
			}
		}
		$this->assertIsArray( $row, 'process-steps puts its steps in a core/columns' );
		$this->assertSame( 'wide', $row['attrs']['align'] ?? null, 'the row of steps is at wide width' );
		$this->assertNotSame( false, $row['attrs']['isStackedOnMobile'] ?? null, 'the steps stack on a phone' );
		$this->assertSame( [ 'Step 1', 'Step 2', 'Step 3' ], array_map( static fn ( mixed $column ): mixed => is_array( $column ) ? ( $column['attrs']['metadata']['name'] ?? null ) : null, is_array( $row['children'] ?? null ) ? $row['children'] : [] ), 'the row holds the three steps and nothing else' );
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

	/**
	 * The text a figure's label slot ships with, or null for a slot that is
	 * no figure's label.
	 *
	 * @param string $slot A copy label, such as "Rating label".
	 */
	private static function figure_label( string $slot ): ?string {
		foreach ( self::FIGURES as $figure => $want ) {
			if ( "{$figure} label" === $slot ) {
				return $want['label'];
			}
		}

		return null;
	}

	public function test_stats_comes_in_two_layouts_row_first_then_band(): void {
		$intents = self::intents();

		$this->assertSame( 'stats', $intents['stats']['section'] ?? null );
		$this->assertSame( 'row', $intents['stats']['layout'] ?? null );
		$this->assertSame( 'stats', $intents['stats-band']['section'] ?? null );
		$this->assertSame( 'band', $intents['stats-band']['layout'] ?? null );
	}

	public function test_every_figure_binds_its_value_to_site_data_and_no_default_text_is_a_figure(): void {
		foreach ( self::STATS as $name ) {
			$intent = self::intents()[ $name ] ?? null;
			$this->assertIsArray( $intent, "{$name} is not in patterns/intents/" );
			$rules = is_array( $intent['copy_rules'] ?? null ) ? $intent['copy_rules'] : [];
			$texts = self::text_blocks( $intent );
			$root  = $intent['intent']['blocks'][0] ?? null;
			$this->assertIsArray( $root, $name );

			$figures = [];
			foreach ( self::nodes( $root ) as $row ) {
				$label = $row['node']['attrs']['metadata']['name'] ?? null;
				if ( is_string( $label ) && isset( self::FIGURES[ $label ] ) ) {
					$figures[ $label ] = $row['node'];
				}
			}
			$this->assertSame( array_keys( self::FIGURES ), array_keys( $figures ), "{$name}: the figures are the year founded, the rating and the review count, in that order" );

			foreach ( self::FIGURES as $figure => $want ) {
				$at = "{$name} \"{$figure}\"";
				$this->assertSame( [ $figure, "{$figure} value", "{$figure} label" ], self::labels_in( $figures[ $figure ] ), "{$at} holds its value, then its label, and nothing else" );

				$value = self::labelled( $figures[ $figure ], "{$figure} value" );
				$label = self::labelled( $figures[ $figure ], "{$figure} label" );
				$this->assertIsArray( $value, $at );
				$this->assertIsArray( $label, $at );
				$this->assertSame( 'core/paragraph', $value['block'] ?? null, $at );
				$this->assertSame( 'core/paragraph', $label['block'] ?? null, $at );

				// The value: the figure role, bound to its Site Data key, a claim.
				$this->assertSame( 'x-large', $value['attrs']['fontSize'] ?? null, "{$at}: the value takes the figure role, x-large (DESIGN.md, Typography)" );
				$this->assertSame(
					[
						'content' => [
							'source' => 'voyager/site-data',
							'args'   => [ 'key' => $want['key'] ],
						],
					],
					$value['attrs']['metadata']['bindings'] ?? null,
					"{$at}: the value's content binds to Site Data's {$want['key']}, and nothing else of it binds"
				);
				$this->assertTrue( $rules[ "{$figure} value" ]['claim'] ?? null, "{$at}: the value is a claim, so no copy may fill it" );
				$this->assertTrue( $rules[ "{$figure} value" ]['required'] ?? null, "{$at}: the value goes only with its whole figure" );

				// Its own text shows while the field is empty: it names the
				// field and makes no claim. The no-claim test above skips a
				// bound slot, so this is what keeps the fallback honest.
				$this->assertSame( $want['fallback'], $texts[ "{$figure} value" ] ?? null, "{$at}: the value's own text names the field" );
				$this->assertDoesNotMatchRegularExpression( self::CLAIMS, $want['fallback'], "{$at}: the value's own text makes a claim" );

				// The label: what the figure is, its own text, body size, no claim slot.
				$this->assertSame( $want['label'], $texts[ "{$figure} label" ] ?? null, "{$at}: the label says what the figure is" );
				$this->assertArrayNotHasKey( 'bindings', $label['attrs']['metadata'] ?? [], "{$at}: the label is the block's own text" );
				$this->assertArrayNotHasKey( 'fontSize', $label['attrs'] ?? [], "{$at}: the label is body text" );
				$this->assertFalse( $rules[ "{$figure} label" ]['claim'] ?? null, "{$at}: the label is no claim" );
			}

			$this->assertSame( array_map( static fn ( string $figure ): string => "{$figure} value", array_keys( self::FIGURES ) ), self::bound_text_labels( $intent ), "{$name}: the three values are the only bound text" );
			foreach ( $texts as $label => $text ) {
				$this->assertDoesNotMatchRegularExpression( '/\d/', $text, "{$name} \"{$label}\" ships a figure of its own" );
			}
		}
	}

	public function test_a_figure_fallback_that_makes_a_claim_is_caught(): void {
		// The same pattern the stats test holds each fallback to.
		foreach ( [ '1998', '4.8', '127', 'Since', 'Five stars', 'Years', 'Rated' ] as $planted ) {
			$this->assertMatchesRegularExpression( self::CLAIMS, $planted, $planted );
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

	/**
	 * Copy with a step's own number taken off the front of its heading. In
	 * "Step 2 heading", a leading "2. " is the step's place in the order, not
	 * a claim (DESIGN.md, Step). Any other number, a number that is not the
	 * step's own, and every other label's text stay as they are, so the claim
	 * check still sees them.
	 *
	 * @param string $label A block's metadata.name.
	 * @param string $text  The text it ships.
	 */
	private static function without_step_number( string $label, string $text ): string {
		if ( 1 === preg_match( '/^Step (\d+) heading$/', $label, $match ) && str_starts_with( $text, $match[1] . '. ' ) ) {
			return substr( $text, strlen( $match[1] ) + 2 );
		}

		return $text;
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
					// validate's stale_fallback: warning checks it for a phone
					// or an email, and the stats test for a figure.
					continue;
				}
				if ( in_array( $name, self::STATS, true ) && self::figure_label( $label ) === $text ) {
					// A figure's label says what its bound value is ("Average
					// rating"), so it holds the words of the claim the value
					// makes. The stats test pins each label's text.
					continue;
				}
				if ( 1 === preg_match( self::CLAIMS, self::without_step_number( (string) $label, $text ) ) ) {
					$hits[] = "{$at}: \"{$text}\"";
				}
			}
		}

		$this->assertSame( [], $hits, 'default copy outside a claim slot or a bound slot makes a claim' );
	}

	public function test_the_claim_pattern_catches_a_planted_claim(): void {
		foreach ( [ 'Licensed and insured', 'Serving the county since 1998', '20 years in business', 'Rated 4.9 by our customers', 'Award-winning service', 'Five-star service', 'Top plumbers in the county', 'Free estimates', 'Same-day repairs', 'Same day visits' ] as $planted ) {
			$this->assertMatchesRegularExpression( self::CLAIMS, $planted, $planted );
		}
	}

	public function test_a_step_s_own_number_is_not_a_claim_but_what_follows_it_still_is(): void {
		foreach ( [ [ 'Step 1 heading', '1. Get in touch' ], [ 'Step 12 heading', '12. We do the work' ] ] as [ $label, $text ] ) {
			$this->assertDoesNotMatchRegularExpression( self::CLAIMS, self::without_step_number( $label, $text ), "{$label}: {$text}" );
		}

		foreach ( [
			[ 'Step 1 heading', '1. Same-day visits' ],
			[ 'Step 1 heading', '1. Free quotes' ],
			[ 'Step 1 heading', '1. A call back within 24 hours' ],
			[ 'Step 2 heading', '1. Get in touch' ],
			[ 'Step 1 heading', '1.Get in touch' ],
			[ 'Step 1 text', '1. Get in touch' ],
			[ 'Heading', '1. How it works' ],
		] as [ $label, $text ] ) {
			$this->assertMatchesRegularExpression( self::CLAIMS, self::without_step_number( $label, $text ), "{$label}: {$text}" );
		}
	}

	public function test_the_claim_pattern_passes_words_that_only_hold_star_top_or_free(): void {
		foreach ( [ 'Ready to get started?', 'Call, email or stop by during business hours.', 'Restart the boiler', 'Topics we cover', 'Pipes that freeze in winter' ] as $plain ) {
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
