<?php
/**
 * Copy rules: the check insert runs on the copy and omit it is given, against
 * the copy_rules of the pattern's intent (contract 3 in docs/v2-contracts.md),
 * and what get-catalog reads from each intent beside its blocks.
 *
 * The rules and the block trees come from patterns/intents/*.json, so these
 * check the patterns as they ship. scripts/e2e.mjs runs the same refusals
 * over REST.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\Patterns;

require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class CopyRulesTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/**
	 * A pattern's copy rules, as insert reads them.
	 *
	 * @param string $slug Intent name, such as hero.
	 * @return array<string, array<mixed>>
	 */
	private static function rules( string $slug ): array {
		return Patterns\intent_meta( 'voyager-open/' . $slug, self::INTENTS )['copy_rules'];
	}

	/**
	 * A pattern's blocks as BlockTree\describe() lists them: path, block, label
	 * and bindings, built from the intent tree the pattern's markup is
	 * assembled from.
	 *
	 * @param string $slug Intent name.
	 * @return array<int, array{path: string, block: string, label?: string, bindings?: array<mixed>}>
	 */
	private static function rows( string $slug ): array {
		$intent = json_decode( (string) file_get_contents( self::INTENTS . '/' . $slug . '.json' ), true );
		self::assertIsArray( $intent );
		$rows = [];
		$walk = static function ( array $nodes, string $prefix ) use ( &$walk, &$rows ): void {
			foreach ( array_values( $nodes ) as $index => $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$path  = '' === $prefix ? (string) $index : $prefix . '/' . $index;
				$row   = [
					'path'  => $path,
					'block' => is_string( $node['block'] ?? null ) ? $node['block'] : '',
				];
				$label = $node['attrs']['metadata']['name'] ?? null;
				if ( is_string( $label ) ) {
					$row['label'] = $label;
				}
				$bindings = $node['attrs']['metadata']['bindings'] ?? null;
				if ( is_array( $bindings ) && [] !== $bindings ) {
					$row['bindings'] = $bindings;
				}
				$rows[] = $row;
				$walk( is_array( $node['children'] ?? null ) ? $node['children'] : [], $path );
			}
		};

		$blocks = $intent['intent']['blocks'] ?? [];
		$walk( is_array( $blocks ) ? $blocks : [], '' );

		return $rows;
	}

	/**
	 * Run the check on one pattern.
	 *
	 * @param string              $slug Intent name.
	 * @param array<string,mixed> $copy insert's copy input.
	 * @param string[]            $omit insert's omit input.
	 * @return list<array{slot: string, reason: string, message: string, max?: int, length?: int, omitted?: string}>
	 */
	private static function check( string $slug, array $copy, array $omit = [] ): array {
		return Abilities\copy_rule_refusals( self::rules( $slug ), Abilities\copy_values( $copy ), $omit, self::rows( $slug ) );
	}

	/**
	 * Each refusal as "slot: reason".
	 *
	 * @param list<array{slot: string, reason: string}> $refused Refusals.
	 * @return list<string>
	 */
	private static function reasons( array $refused ): array {
		return array_map( static fn ( array $row ): string => $row['slot'] . ': ' . $row['reason'], $refused );
	}

	public function test_get_catalog_reads_the_heros_section_layout_and_copy_rules(): void {
		$hero = Patterns\intent_meta( 'voyager-open/hero', self::INTENTS );

		$this->assertSame( 'hero', $hero['section'] );
		$this->assertSame( 'split', $hero['layout'] );
		$this->assertSame( 60, $hero['copy_rules']['Heading']['max'] ?? null );
		$this->assertSame( true, $hero['copy_rules']['Heading']['required'] ?? null );
	}

	public function test_a_section_with_one_layout_has_a_null_section_and_layout_and_its_own_copy_rules(): void {
		$band   = Patterns\intent_meta( 'voyager-open/cta-band', self::INTENTS );
		$intent = json_decode( (string) file_get_contents( self::INTENTS . '/cta-band.json' ), true );
		$this->assertIsArray( $intent );

		$this->assertNull( $band['section'] );
		$this->assertNull( $band['layout'] );
		// Whatever the intent holds: none yet, until Design writes the five original sections' rules.
		$this->assertSame( $intent['copy_rules'] ?? [], $band['copy_rules'] );
	}

	public function test_a_name_that_is_not_a_plain_intent_reads_nothing(): void {
		$none = [
			'section'    => null,
			'layout'     => null,
			'copy_rules' => [],
		];

		$this->assertSame( $none, Patterns\intent_meta( 'voyager-open/../composer', self::INTENTS ) );
		$this->assertSame( $none, Patterns\intent_meta( 'core/hero', self::INTENTS ) );
		$this->assertSame( $none, Patterns\intent_meta( 'voyager-open/no-such-section', self::INTENTS ) );
	}

	public function test_text_over_max_is_too_long_and_names_the_slot_its_max_and_length(): void {
		$refused = self::check( 'hero', [ 'Heading' => str_repeat( 'a', 61 ) ] );

		$this->assertSame( [ 'Heading: too_long' ], self::reasons( $refused ) );
		$this->assertSame( 60, $refused[0]['max'] ?? null );
		$this->assertSame( 61, $refused[0]['length'] ?? null );
		$this->assertStringContainsString( '"Heading" is 61 characters long, and its copy rule allows at most 60.', $refused[0]['message'] );
	}

	public function test_text_at_max_once_trimmed_fits_and_characters_are_counted_not_bytes(): void {
		$this->assertSame( [], self::check( 'hero', [ 'Heading' => '  ' . str_repeat( 'a', 60 ) . " \n" ] ) );
		$this->assertSame( [], self::check( 'hero', [ 'Heading' => str_repeat( 'é', 60 ) ] ) );
		$this->assertSame( [ 'Heading: too_long' ], self::reasons( self::check( 'hero', [ 'Heading' => str_repeat( 'é', 61 ) ] ) ) );
	}

	public function test_text_under_min_is_accepted(): void {
		$this->assertSame( [], self::check( 'hero', [ 'Heading' => 'Hi' ] ) );
	}

	public function test_a_plain_string_in_a_claim_slot_is_refused_and_the_object_form_fills_it(): void {
		$quote = 'They came the same day and fixed the leak for good.';

		$plain = self::check( 'reviews', [ 'Review 1 quote' => $quote ] );
		$this->assertSame( [ 'Review 1 quote: claim' ], self::reasons( $plain ) );
		$this->assertStringContainsString( '{"text": "...", "source": "user"}', $plain[0]['message'] );
		$this->assertStringContainsString( 'bind the block to that field', $plain[0]['message'] );

		$this->assertSame(
			[],
			self::check(
				'reviews',
				[
					'Review 1 quote' => [
						'text'   => $quote,
						'source' => 'user',
					],
				]
			)
		);
	}

	public function test_the_object_form_is_held_to_max_too(): void {
		$refused = self::check(
			'reviews',
			[
				'Review 1 quote' => [
					'text'   => str_repeat( 'a', 301 ),
					'source' => 'user',
				],
			]
		);

		$this->assertSame( [ 'Review 1 quote: too_long' ], self::reasons( $refused ) );
	}

	public function test_a_slot_that_is_no_claim_takes_a_plain_string(): void {
		$this->assertSame( [], self::check( 'reviews', [ 'Heading' => 'What customers say' ] ) );
	}

	public function test_omitting_a_required_slot_is_refused_and_an_optional_one_is_not(): void {
		$refused = self::check( 'hero', [], [ 'Heading' ] );

		$this->assertSame( [ 'Heading: required' ], self::reasons( $refused ) );
		$this->assertSame( 'Heading', $refused[0]['omitted'] ?? null );
		$this->assertSame( [], self::check( 'hero', [], [ 'Eyebrow' ] ) );
		$this->assertStringContainsString( 'give it copy if the shipped text does not fit', $refused[0]['message'] );
	}

	public function test_omitting_a_required_bound_slot_says_it_takes_no_copy(): void {
		// The hero's call button shows the Site Data phone, so copy for it is
		// refused as bound: the refusal must not tell the agent to give it copy.
		$refused = self::check( 'hero', [], [ 'Click to call' ] );

		$this->assertSame( [ 'Click to call: required' ], self::reasons( $refused ) );
		$this->assertStringContainsString( 'is bound to voyager/site-data, key "phone", so it takes no copy either', $refused[0]['message'] );
		$this->assertStringNotContainsString( 'give it copy', $refused[0]['message'] );

		// A bound value in the stats section reads the same.
		$value = self::check( 'stats', [], [ 'Rating value' ] );
		$this->assertSame( [ 'Rating value: required' ], self::reasons( $value ) );
		$this->assertStringContainsString( 'is bound to voyager/site-data, key "rating", so it takes no copy either', $value[0]['message'] );
	}

	public function test_omitting_a_block_that_holds_required_slots_names_each_of_them(): void {
		$refused = self::check( 'hero', [], [ 'Lead' ] );

		$this->assertSame( [ 'Heading: required', 'Body: required' ], self::reasons( $refused ) );
		$this->assertSame( [ 'Lead', 'Lead' ], array_column( $refused, 'omitted' ) );
		$this->assertStringContainsString( 'Leaving out "Lead" drops "Heading"', $refused[0]['message'] );

		$this->assertSame( [ 'Review 1 quote: required' ], self::reasons( self::check( 'reviews', [], [ 'Review 1 quote block' ] ) ) );
	}

	public function test_omitting_a_whole_item_takes_its_slots_with_it(): void {
		$this->assertSame( [], self::check( 'services', [], [ 'Service 3' ] ) );
		$this->assertSame( [], self::check( 'reviews', [], [ 'Review 3' ] ) );
		$this->assertSame( [ 'Service 3 heading: required' ], self::reasons( self::check( 'services', [], [ 'Service 3 heading' ] ) ) );
		$this->assertSame( [ 'Heading: required' ], self::reasons( self::check( 'services', [], [ 'Lead' ] ) ) );
	}

	public function test_omitting_a_question_takes_its_answer_and_an_answer_alone_cannot_go(): void {
		foreach ( [ 'faq', 'faq-split' ] as $slug ) {
			// The question is the item: its answer starts with its label and a space.
			$this->assertSame( [], self::check( $slug, [], [ 'Question 3' ] ), $slug );
			$this->assertSame( [ 'Question 3 answer: required' ], self::reasons( self::check( $slug, [], [ 'Question 3 answer' ] ) ), $slug );
			$this->assertSame( [], self::check( $slug, [], [ 'Intro' ] ), $slug );
			$this->assertSame( [ 'Heading: required' ], self::reasons( self::check( $slug, [], [ 'Heading' ] ) ), $slug );
			$this->assertSame(
				[ 'Question 1 answer: required', 'Question 2 answer: required', 'Question 3 answer: required' ],
				self::reasons( self::check( $slug, [], [ 'Questions' ] ) ),
				"{$slug}: leaving out every question leaves no FAQ"
			);
		}
	}

	public function test_a_question_is_held_to_its_max_and_a_fourth_keeps_the_thirds_rules(): void {
		$this->assertSame( [ 'Question 1: too_long' ], self::reasons( self::check( 'faq', [ 'Question 1' => str_repeat( 'a', 80 ) . '?' ] ) ) );
		$this->assertSame( [], self::check( 'faq', [ 'Question 1' => 'Do you work weekends?' ] ) );

		$rules = Abilities\repeated_rules( self::rules( 'faq' ), [ 'Question 3' => [ 'Question 4' ] ] );
		$this->assertSame( self::rules( 'faq' )['Question 3'], $rules['Question 4'] ?? null );
		$this->assertSame( self::rules( 'faq' )['Question 3 answer'], $rules['Question 4 answer'] ?? null );
		$this->assertSame(
			[ 'Question 4 answer: too_long' ],
			self::reasons( Abilities\copy_rule_refusals( $rules, Abilities\copy_values( [ 'Question 4 answer' => str_repeat( 'a', 301 ) ] ), [], self::rows( 'faq' ) ) )
		);
		// No question or answer is a claim: a plain string fills it. Its purpose says where a fact comes from.
		foreach ( self::rules( 'faq' ) as $label => $rule ) {
			$this->assertFalse( $rule['claim'], (string) $label );
		}
		$this->assertMatchesRegularExpression( '/site owner.*Site Data/', (string) self::rules( 'faq' )['Question 1 answer']['purpose'] );
	}

	public function test_a_slot_with_no_rule_is_not_checked(): void {
		$long = str_repeat( 'a', 500 );

		// The CTA band's intent may gain rules; an empty rule set cannot.
		$this->assertSame( [], Abilities\copy_rule_refusals( [], Abilities\copy_values( [ 'Heading' => $long ] ), [ 'Heading' ], self::rows( 'cta-band' ) ) );
		// A label the intent does not rule over.
		$this->assertSame( [], self::check( 'services', [ 'Service 4 heading' => $long ] ) );
	}

	public function test_a_copy_repeat_makes_keeps_the_rule_of_the_block_it_copies(): void {
		$rules = Abilities\repeated_rules( self::rules( 'reviews' ), [ 'Review 3' => [ 'Review 4', 'Review 5' ] ] );

		$this->assertSame( self::rules( 'reviews' )['Review 3 quote'], $rules['Review 4 quote'] ?? null );
		$this->assertSame( self::rules( 'reviews' )['Review 3 detail'], $rules['Review 5 detail'] ?? null );
		$this->assertArrayNotHasKey( 'Review 4', $rules );
		$this->assertSame( self::rules( 'reviews' ), array_intersect_key( $rules, self::rules( 'reviews' ) ) );

		// So a fourth review is a claim, and a fourth service is held to the third's length.
		$this->assertSame(
			[ 'Review 4 quote: claim' ],
			self::reasons( Abilities\copy_rule_refusals( $rules, Abilities\copy_values( [ 'Review 4 quote' => 'Typed in by the agent, not the customer.' ] ), [], self::rows( 'reviews' ) ) )
		);
		$this->assertSame(
			[ 'Service 4 heading: too_long' ],
			self::reasons( Abilities\copy_rule_refusals( Abilities\repeated_rules( self::rules( 'services' ), [ 'Service 3' => [ 'Service 4' ] ] ), Abilities\copy_values( [ 'Service 4 heading' => str_repeat( 'a', 41 ) ] ), [], self::rows( 'services' ) ) )
		);
		// A label that only starts the same way is not a copy of it.
		$this->assertSame(
			[ 'Credential 40' ],
			array_keys( Abilities\repeated_rules( [ 'Credential 40' => [ 'max' => 40 ] ], [ 'Credential 4' => [ 'Credential 5' ] ] ) )
		);
	}

	public function test_every_fault_is_listed_at_once_copy_first_then_omit(): void {
		$refused = self::check(
			'reviews',
			[
				'Heading'        => str_repeat( 'a', 61 ),
				'Review 1 quote' => 'Typed in by the agent.',
			],
			[ 'Review 2 name' ]
		);

		$this->assertSame( [ 'Heading: too_long', 'Review 1 quote: claim', 'Review 2 name: required' ], self::reasons( $refused ) );
	}

	public function test_a_long_plain_string_in_a_claim_slot_is_refused_for_both(): void {
		$this->assertSame( [ 'Review 1 quote: too_long', 'Review 1 quote: claim' ], self::reasons( self::check( 'reviews', [ 'Review 1 quote' => str_repeat( 'a', 301 ) ] ) ) );
	}

	public function test_copy_values_read_a_string_as_the_agents_and_the_object_form_as_the_owners(): void {
		$this->assertSame(
			[
				'Heading'        => [
					'text' => 'What customers say',
					'user' => false,
				],
				'Review 1 quote' => [
					'text' => 'Fixed it the same day.',
					'user' => true,
				],
			],
			Abilities\copy_values(
				[
					'Heading'        => 'What customers say',
					'Review 1 quote' => (object) [
						'text'   => 'Fixed it the same day.',
						'source' => 'user',
					],
				]
			)
		);
		$this->assertSame( [ 'Heading' => 'x' ], Abilities\copy_texts( Abilities\copy_values( [ 'Heading' => 'x' ] ) ) );
	}

	public function test_the_copy_value_schema_takes_a_string_or_text_from_the_user_and_nothing_else(): void {
		$schema = Abilities\copy_value_schema();
		$this->assertIsArray( $schema['anyOf'] ?? null );
		[ $string, $object ] = $schema['anyOf'];

		$this->assertSame( 'string', $string['type'] ?? null );
		$this->assertSame( 1, $string['minLength'] ?? null );
		$this->assertSame( 'object', $object['type'] ?? null );
		$this->assertSame( [ 'text', 'source' ], $object['required'] ?? null );
		$this->assertFalse( $object['additionalProperties'] ?? null );
		$this->assertSame( 1, $object['properties']['text']['minLength'] ?? null );
		$this->assertSame( [ 'user' ], $object['properties']['source']['enum'] ?? null );
	}

	public function test_bound_slots_alone_keep_their_code_and_with_a_copy_rule_refusal_share_one_list(): void {
		$bound = [
			[
				'slot'      => 'Click to call',
				'reason'    => 'bound',
				'block'     => 'core/button',
				'path'      => '0/0/0/1/1',
				'attribute' => 'text',
				'source'    => 'voyager/site-data',
				'key'       => 'phone',
			],
		];

		$alone = Abilities\copy_error( $bound, [] );
		$this->assertSame( 'voyager_open_blocks_bound_slot', $alone->get_error_code() );
		$this->assertSame( Abilities\bound_slot_error( 'copy', $bound )->get_error_message(), $alone->get_error_message() );

		$rules = self::check( 'hero', [ 'Heading' => str_repeat( 'a', 61 ), 'Click to call' => str_repeat( 'b', 30 ) ], [ 'Body' ] );
		$this->assertSame( [ 'Heading: too_long', 'Click to call: too_long', 'Body: required' ], self::reasons( $rules ) );

		$mixed = Abilities\copy_error( $bound, $rules );
		$data  = $mixed->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'voyager_open_blocks_copy_rules', $mixed->get_error_code() );
		$this->assertSame( 400, $data['status'] ?? null );
		$this->assertIsArray( $data['refused'] ?? null );
		// A bound slot takes no copy at all, so it is not refused again for its length.
		$this->assertSame( [ 'Click to call: bound', 'Heading: too_long', 'Body: required' ], self::reasons( $data['refused'] ) );
		$this->assertStringStartsWith( 'Nothing was saved: 3 slots were refused.', $mixed->get_error_message() );
		$this->assertStringContainsString( '"Click to call" (core/button text at 0/0/0/1/1) is bound to voyager/site-data, key "phone"', $mixed->get_error_message() );
	}
}
