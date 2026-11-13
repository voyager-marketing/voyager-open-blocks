<?php
/**
 * The copy examples in skills/voyager-open-blocks/examples/: a home page for
 * each of five kinds of business, each file the business it was written for
 * and the compose call that builds its page (contract 6 in
 * docs/v2-contracts.md).
 *
 * Each example's sections follow recipes/home.json in order, with its layouts
 * and styles. Its copy fills every slot a copy rule covers, except a bound
 * slot, which takes its text from Site Data. compose has no omit, so a slot
 * left out would show the pattern's own text. copy_rule_refusals(), the check
 * compose runs, refuses none of it: a claim slot holds the object form,
 * {"text", "source": "user"}, and no other slot does.
 *
 * The repository is public, so every example is fictional: a phone number is
 * in the 555-0100 to 555-0199 range, an email address is at example.com, and
 * no example names a business from bench/briefs.json, so the benchmark never
 * meets copy it has seen. scripts/e2e.mjs runs each example through compose
 * itself, with dry_run.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Patterns;

require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class CopyExamplesTest extends TestCase {

	private const EXAMPLES = __DIR__ . '/../../skills/voyager-open-blocks/examples';
	private const INTENTS  = __DIR__ . '/../../patterns/intents';
	private const RECIPE   = __DIR__ . '/../../recipes/home.json';
	private const BRIEFS   = __DIR__ . '/../../bench/briefs.json';

	/** One example per kind of business, by file name. */
	private const VERTICALS = [ 'health-and-vet', 'home-services', 'hospitality', 'legal', 'restaurants' ];

	/** An example's keys, sorted. */
	private const KEYS = [ 'business', 'compose' ];

	/** The keys of an example's compose input, sorted. */
	private const COMPOSE_KEYS = [ 'sections', 'title' ];

	/** A compose section's keys, sorted. */
	private const SECTION_KEYS = [ 'copy', 'layout', 'pattern', 'style' ];

	/**
	 * A phone number as people write one: an area code, three digits and four,
	 * apart. A digit or a hyphen right before or after it means it is part of
	 * something longer, such as a licence number, so it is not one.
	 */
	private const PHONE = '/(?<![\w-])(?:\+1[\s.-]?)?(?:\(\d{3}\)\s?|\d{3}[\s.-])\d{3}[\s.-]\d{4}(?![\w-])/';

	/** The numbers the North American plan keeps for fiction: 555-0100 to 555-0199. */
	private const FICTIONAL_PHONE = '/555[\s.-]01\d\d$/';

	/** An email address, with its domain captured. */
	private const EMAIL = '/[a-z0-9._%+-]+@([a-z0-9-]+(?:\.[a-z0-9-]+)+)/i';

	/**
	 * Every example file, by name, decoded as arrays.
	 *
	 * @return array<string, mixed>
	 */
	private static function examples(): array {
		$out = [];

		foreach ( glob( self::EXAMPLES . '/*.json' ) ?: [] as $file ) {
			$out[ basename( $file, '.json' ) ] = json_decode( (string) file_get_contents( $file ), true );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The home recipe's sections, each as pattern, layout and style.
	 *
	 * @return list<array{pattern: string, layout: string|null, style: string}>
	 */
	private static function recipe(): array {
		$recipe = json_decode( (string) file_get_contents( self::RECIPE ), true );
		$out    = [];

		foreach ( is_array( $recipe ) && is_array( $recipe['sections'] ?? null ) ? $recipe['sections'] : [] as $section ) {
			$section = is_array( $section ) ? $section : [];
			$out[]   = [
				'pattern' => is_string( $section['pattern'] ?? null ) ? $section['pattern'] : '',
				'layout'  => is_string( $section['layout'] ?? null ) ? $section['layout'] : null,
				'style'   => is_string( $section['style'] ?? null ) ? $section['style'] : '',
			];
		}

		return $out;
	}

	/**
	 * The business names and ids in bench/briefs.json.
	 *
	 * @return list<string>
	 */
	private static function bench_names(): array {
		$briefs = json_decode( (string) file_get_contents( self::BRIEFS ), true );
		$out    = [];

		foreach ( is_array( $briefs ) && is_array( $briefs['briefs'] ?? null ) ? $briefs['briefs'] : [] as $brief ) {
			foreach ( [ 'business', 'id' ] as $key ) {
				if ( is_array( $brief ) && is_string( $brief[ $key ] ?? null ) && '' !== $brief[ $key ] ) {
					$out[] = $brief[ $key ];
				}
			}
		}

		return $out;
	}

	/**
	 * A pattern's named text blocks, the ones copy replaces, and of those the
	 * ones whose text a binding supplies, read from its intent's block tree.
	 *
	 * @param string $pattern Pattern name, such as voyager-open/hero.
	 * @return array{text: list<string>, bound: list<string>}
	 */
	private static function slots( string $pattern ): array {
		$intent = json_decode( (string) file_get_contents( self::INTENTS . '/' . substr( $pattern, strlen( 'voyager-open/' ) ) . '.json' ), true );
		$blocks = is_array( $intent ) && is_array( $intent['intent']['blocks'] ?? null ) ? $intent['intent']['blocks'] : [];
		$out    = [
			'text'  => [],
			'bound' => [],
		];

		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$label     = $node['attrs']['metadata']['name'] ?? null;
				$attribute = BlockTree\TEXT_ATTRIBUTES[ (string) ( $node['block'] ?? '' ) ] ?? null;

				if ( is_string( $label ) && null !== $attribute ) {
					$out['text'][] = $label;
					if ( isset( $node['attrs']['metadata']['bindings'][ $attribute ] ) ) {
						$out['bound'][] = $label;
					}
				}

				$walk( is_array( $node['children'] ?? null ) ? $node['children'] : [] );
			}
		};
		$walk( $blocks );

		return $out;
	}

	/**
	 * Every string in a value, depth first.
	 *
	 * @param mixed $value A decoded example, or part of one.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		if ( is_string( $value ) ) {
			return [ $value ];
		}

		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			array_push( $out, ...self::strings( $item ) );
		}

		return $out;
	}

	/**
	 * The phone numbers in a text, as written.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	private static function phones( string $text ): array {
		preg_match_all( self::PHONE, $text, $matches );

		return $matches[0];
	}

	/**
	 * What an example's text shows that a public repository must not: a phone
	 * outside the fictional range, an email outside example.com, or the name of
	 * a business the benchmark uses.
	 *
	 * @param string $at      The example's name.
	 * @param mixed  $example The decoded example.
	 * @return list<string>
	 */
	private static function fiction_problems( string $at, mixed $example ): array {
		$problems = [];
		$text     = implode( "\n", self::strings( $example ) );

		foreach ( self::phones( $text ) as $phone ) {
			if ( 1 !== preg_match( self::FICTIONAL_PHONE, $phone ) ) {
				$problems[] = "{$at}: the phone {$phone} is outside 555-0100 to 555-0199, the range kept for fiction";
			}
		}

		preg_match_all( self::EMAIL, $text, $emails, PREG_SET_ORDER );
		foreach ( $emails as $email ) {
			$domain = strtolower( $email[1] );
			if ( 'example.com' !== $domain && ! str_ends_with( $domain, '.example.com' ) ) {
				$problems[] = "{$at}: the email {$email[0]} is not at example.com";
			}
		}

		foreach ( self::bench_names() as $name ) {
			if ( false !== stripos( $text, $name ) ) {
				$problems[] = "{$at}: names \"{$name}\", a business in bench/briefs.json, so the benchmark would meet copy it has seen";
			}
		}

		return $problems;
	}

	/**
	 * What is wrong with one section's copy, one line each.
	 *
	 * @param string $where   The section, for the message.
	 * @param string $pattern The pattern its section and layout name.
	 * @param mixed  $copy    The section's copy.
	 * @return list<string>
	 */
	private static function copy_problems( string $where, string $pattern, mixed $copy ): array {
		if ( ! is_array( $copy ) || array_is_list( $copy ) ) {
			return [ "{$where}: copy is not an object of slot => text" ];
		}

		$problems = [];
		$rules    = Patterns\intent_meta( $pattern, self::INTENTS )['copy_rules'];
		$slots    = self::slots( $pattern );

		foreach ( $copy as $slot => $value ) {
			$slot = (string) $slot;
			$rule = $rules[ $slot ] ?? null;

			if ( in_array( $slot, $slots['bound'], true ) ) {
				$problems[] = "{$where}: copy for \"{$slot}\", whose text a binding supplies from Site Data, so compose refuses it";
				continue;
			}
			if ( ! in_array( $slot, $slots['text'], true ) || ! is_array( $rule ) ) {
				$problems[] = "{$where}: copy for \"{$slot}\", which is not a slot with a copy rule in {$pattern}";
				continue;
			}

			$claim = true === ( $rule['claim'] ?? null );

			if ( is_string( $value ) ) {
				if ( '' === trim( $value ) ) {
					$problems[] = "{$where}: \"{$slot}\" is empty";
				} elseif ( ! $claim ) {
					continue;
				}
				// A claim given as a string: copy_rule_refusals() below names it.
			} elseif ( is_array( $value ) && [ 'source', 'text' ] === self::sorted_keys( $value ) && 'user' === $value['source'] && is_string( $value['text'] ) && '' !== trim( $value['text'] ) ) {
				if ( ! $claim ) {
					$problems[] = "{$where}: \"{$slot}\" is not a claim, so it takes plain text, not the object form, which says the site owner gave it";
				}
			} else {
				$problems[] = "{$where}: \"{$slot}\" is neither text nor {\"text\": \"...\", \"source\": \"user\"}";
			}
		}

		foreach ( $slots['text'] as $slot ) {
			if ( isset( $rules[ $slot ] ) && ! in_array( $slot, $slots['bound'], true ) && ! array_key_exists( $slot, $copy ) ) {
				$problems[] = "{$where}: no copy for \"{$slot}\", so the page would show the pattern's own text there";
			}
		}

		foreach ( Abilities\copy_rule_refusals( $rules, Abilities\copy_values( $copy ), [], [] ) as $row ) {
			$problems[] = "{$where}: {$row['reason']}: {$row['message']}";
		}

		return $problems;
	}

	/**
	 * An array's keys, as strings, sorted.
	 *
	 * @param array<mixed> $value An array.
	 * @return list<string>
	 */
	private static function sorted_keys( array $value ): array {
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * Every way the examples break the rules in this file's comment, one line each.
	 *
	 * @param array<string, mixed> $examples Name => the decoded file.
	 * @return list<string>
	 */
	private static function problems( array $examples ): array {
		$problems = [];
		$recipe   = self::recipe();
		$layouts  = Abilities\section_layouts( self::INTENTS );

		foreach ( array_diff( self::VERTICALS, array_keys( $examples ) ) as $missing ) {
			$problems[] = "no example {$missing}.json in skills/voyager-open-blocks/examples/";
		}
		foreach ( array_diff( array_keys( $examples ), self::VERTICALS ) as $extra ) {
			$problems[] = "{$extra}.json is not one of the five examples: " . implode( ', ', self::VERTICALS );
		}

		foreach ( $examples as $at => $example ) {
			$at = (string) $at;

			if ( ! is_array( $example ) || array_is_list( $example ) ) {
				$problems[] = "{$at}: the file is not a JSON object";
				continue;
			}
			if ( self::KEYS !== self::sorted_keys( $example ) ) {
				$problems[] = "{$at}: its keys are " . implode( ', ', self::sorted_keys( $example ) ) . ', not ' . implode( ', ', self::KEYS );
			}

			$business = $example['business'] ?? null;
			if ( ! is_string( $business ) || '' === trim( $business ) || str_contains( $business, "\n" ) ) {
				$problems[] = "{$at}: business is not one paragraph of text";
			}

			array_push( $problems, ...self::fiction_problems( $at, $example ) );

			$compose = $example['compose'] ?? null;
			if ( ! is_array( $compose ) || self::COMPOSE_KEYS !== self::sorted_keys( $compose ) ) {
				$problems[] = "{$at}: compose is not compose's input with title and sections alone";
				continue;
			}
			if ( ! is_string( $compose['title'] ) || '' === trim( $compose['title'] ) ) {
				$problems[] = "{$at}: compose's title is not text";
			}

			$sections = $compose['sections'];
			if ( ! is_array( $sections ) || ! array_is_list( $sections ) ) {
				$problems[] = "{$at}: compose's sections is not a list";
				continue;
			}
			if ( count( $sections ) !== count( $recipe ) ) {
				$problems[] = sprintf( '%s: holds %d sections, and recipes/home.json holds %d', $at, count( $sections ), count( $recipe ) );
			}

			foreach ( $sections as $index => $section ) {
				$where    = "{$at} section {$index}";
				$expected = $recipe[ $index ] ?? null;

				if ( ! is_array( $section ) || self::SECTION_KEYS !== self::sorted_keys( $section ) ) {
					$problems[] = "{$where}: its keys are not " . implode( ', ', self::SECTION_KEYS );
					continue;
				}

				$given = [
					'pattern' => $section['pattern'],
					'layout'  => $section['layout'],
					'style'   => $section['style'],
				];
				if ( $given !== $expected ) {
					$problems[] = sprintf( '%s: is %s, and recipes/home.json has %s there', $where, (string) json_encode( $given, JSON_UNESCAPED_SLASHES ), null === $expected ? 'no section' : (string) json_encode( $expected, JSON_UNESCAPED_SLASHES ) );
					continue;
				}

				$pattern = Abilities\resolve_layout( $layouts, $expected['pattern'], $expected['layout'] );
				if ( null === $pattern ) {
					$problems[] = "{$where}: names no pattern";
					continue;
				}

				array_push( $problems, ...self::copy_problems( "{$where} ({$pattern})", $pattern, $section['copy'] ) );
			}
		}

		return $problems;
	}

	public function test_there_are_five_examples_one_per_kind_of_business(): void {
		$this->assertSame( self::VERTICALS, array_keys( self::examples() ) );
	}

	public function test_every_example_follows_the_home_recipe_its_copy_rules_and_fiction(): void {
		$examples = self::examples();
		$this->assertNotSame( [], $examples, 'no example files were read' );
		$this->assertSame( [], self::problems( $examples ) );
	}

	public function test_every_example_fills_every_claim_slot_in_the_object_form(): void {
		$claims = 0;

		foreach ( self::examples() as $example ) {
			$this->assertIsArray( $example );
			foreach ( $example['compose']['sections'] ?? [] as $section ) {
				foreach ( $section['copy'] ?? [] as $value ) {
					$claims += is_array( $value ) ? 1 : 0;
				}
			}
		}

		// Four credentials and three reviews of four slots each, five times.
		$this->assertSame( 5 * ( 4 + 3 * 4 ), $claims );
	}

	/**
	 * Faults planted in the shipped examples, each with what its problem says.
	 *
	 * @return array<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}>
	 */
	public static function planted(): array {
		$copy = static fn ( int $section ): \Closure => static function ( array $examples, \Closure $change ) use ( $section ): array {
			$examples['legal']['compose']['sections'][ $section ]['copy'] = $change( $examples['legal']['compose']['sections'][ $section ]['copy'] );
			return $examples;
		};

		return [
			'a phone outside 555-01xx'                   => [
				static function ( array $examples ): array {
					$examples['legal']['business'] .= ' Its second line is (555) 555-0200.';
					return $examples;
				},
				'legal: the phone (555) 555-0200 is outside 555-0100 to 555-0199',
			],
			'a business named after a bench brief'       => [
				static function ( array $examples ): array {
					$examples['health-and-vet']['business'] = 'Lakeshore Family Dental is a dental practice for families.';
					return $examples;
				},
				'health-and-vet: names "Lakeshore Family Dental", a business in bench/briefs.json',
			],
			'a bench brief id in the copy'               => [
				static fn ( array $examples ): array => $copy( 0 )(
					$examples,
					static fn ( array $c ): array => [ 'Eyebrow' => 'Willow-vet clinic' ] + $c
				),
				'legal: names "willow-vet"',
			],
			'an email outside example.com'               => [
				static function ( array $examples ): array {
					$examples['hospitality']['business'] .= ' Write to stay@saltglass-inn.com.';
					return $examples;
				},
				'hospitality: the email stay@saltglass-inn.com is not at example.com',
			],
			'a claim given as a plain string'            => [
				static fn ( array $examples ): array => $copy( 1 )(
					$examples,
					static fn ( array $c ): array => [ 'Credential 1' => 'Admitted to the state bar in 2009' ] + $c
				),
				'legal section 1 (voyager-open/proof-strip): claim: "Credential 1" is a claim slot',
			],
			'the object form outside a claim slot'       => [
				static fn ( array $examples ): array => $copy( 0 )(
					$examples,
					static fn ( array $c ): array => [
						'Heading' => [
							'text'   => 'Help for workers who were fired or not paid',
							'source' => 'user',
						],
					] + $c
				),
				'legal section 0 (voyager-open/hero): "Heading" is not a claim, so it takes plain text',
			],
			'a heading over its max'                     => [
				static fn ( array $examples ): array => $copy( 0 )(
					$examples,
					static fn ( array $c ): array => [ 'Heading' => str_repeat( 'a', 61 ) ] + $c
				),
				'legal section 0 (voyager-open/hero): too_long: "Heading" is 61 characters long',
			],
			'a slot left out'                            => [
				static fn ( array $examples ): array => $copy( 3 )(
					$examples,
					static function ( array $c ): array {
						unset( $c['Eyebrow'] );
						return $c;
					}
				),
				'legal section 3 (voyager-open/cta-band): no copy for "Eyebrow", so the page would show the pattern\'s own text',
			],
			'copy for a bound slot'                      => [
				static fn ( array $examples ): array => $copy( 0 )(
					$examples,
					static fn ( array $c ): array => [ 'Click to call' => 'Call us' ] + $c
				),
				'legal section 0 (voyager-open/hero): copy for "Click to call", whose text a binding supplies',
			],
			'a label no slot carries'                    => [
				static fn ( array $examples ): array => $copy( 2 )(
					$examples,
					static fn ( array $c ): array => [ 'Service 4 heading' => 'Contract review' ] + $c
				),
				'legal section 2 (voyager-open/services): copy for "Service 4 heading", which is not a slot',
			],
			'sections out of the recipe\'s order'        => [
				static function ( array $examples ): array {
					$sections = $examples['restaurants']['compose']['sections'];
					$examples['restaurants']['compose']['sections'] = array_merge( [ $sections[1], $sections[0] ], array_slice( $sections, 2 ) );
					return $examples;
				},
				'restaurants section 0: is {"pattern":"voyager-open/proof-strip"',
			],
			'a section left out'                         => [
				static function ( array $examples ): array {
					array_pop( $examples['restaurants']['compose']['sections'] );
					return $examples;
				},
				'restaurants: holds 5 sections, and recipes/home.json holds 6',
			],
			'a style other than the recipe\'s'           => [
				static function ( array $examples ): array {
					$examples['home-services']['compose']['sections'][4]['style'] = 'panel';
					return $examples;
				},
				'home-services section 4: is {"pattern":"voyager-open/reviews","layout":"grid","style":"panel"}',
			],
			'a key compose does not take'                => [
				static function ( array $examples ): array {
					$examples['hospitality']['compose']['dry_run'] = true;
					return $examples;
				},
				'hospitality: compose is not compose\'s input with title and sections alone',
			],
			'a sixth example'                            => [
				static function ( array $examples ): array {
					$examples['nonprofits'] = $examples['legal'];
					return $examples;
				},
				'nonprofits.json is not one of the five examples',
			],
			'a missing example'                          => [
				static function ( array $examples ): array {
					unset( $examples['hospitality'] );
					return $examples;
				},
				'no example hospitality.json',
			],
		];
	}

	/**
	 * @param \Closure(array<string, mixed>): array<string, mixed> $plant  Returns the examples with a fault in them.
	 * @param string                                                $expect A part of the problem it must cause.
	 */
	#[DataProvider( 'planted' )]
	public function test_a_planted_fault_is_caught( \Closure $plant, string $expect ): void {
		$examples = self::examples();
		$this->assertSame( [], self::problems( $examples ), 'the shipped examples must pass before a fault is planted' );

		$problems = self::problems( $plant( $examples ) );

		$this->assertNotSame( [], $problems, 'the planted fault went unnoticed' );
		$this->assertStringContainsString( $expect, implode( "\n", $problems ) );
	}

	/**
	 * Text with and without phone numbers in it, and the numbers each holds.
	 *
	 * Years, ranges, dates, ZIP+4 codes, licence numbers and ratings are not
	 * phone numbers.
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public static function phone_text(): array {
		return [
			'parentheses'                   => [ 'Call (555) 555-0123 today.', [ '(555) 555-0123' ] ],
			'hyphens'                       => [ 'Call 555-555-0123.', [ '555-555-0123' ] ],
			'dots'                          => [ 'Call 555.555.0123.', [ '555.555.0123' ] ],
			'spaces and a country code'     => [ 'Call +1 555 555 0123.', [ '+1 555 555 0123' ] ],
			'the planted number'            => [ 'Its second line is (555) 555-0200.', [ '(555) 555-0200' ] ],
			'a real-looking number'         => [ 'Call (212) 736-5000.', [ '(212) 736-5000' ] ],
			'two in one line'               => [ 'Office (555) 555-0150, cell 555-555-0151.', [ '(555) 555-0150', '555-555-0151' ] ],
			'a year'                        => [ 'Serving the town since 2009.', [] ],
			'a range of years'              => [ 'Open every summer, 2009-2024.', [] ],
			'a date'                        => [ 'Reviewed 2026-11-12, and again on 11/12/2026.', [] ],
			'a ZIP+4 code'                  => [ '100 Main Street, 23001-1234.', [] ],
			'a licence number'              => [ 'Licence EL-104-882-1234, permit 4471-220.', [] ],
			'a long licence number'         => [ 'Licence 2041187733.', [] ],
			'hours'                         => [ 'Monday to Friday, 8am to 5pm.', [] ],
			'a rating'                      => [ 'Rated 5 out of 5.', [] ],
			'a seven-digit range'           => [ 'Lawns of 100-1500 square feet.', [] ],
		];
	}

	/**
	 * @param string       $text   Text.
	 * @param list<string> $phones The phone numbers in it.
	 */
	#[DataProvider( 'phone_text' )]
	public function test_phone_numbers_are_found_and_nothing_else_is( string $text, array $phones ): void {
		$this->assertSame( $phones, self::phones( $text ) );
	}

	public function test_a_phone_in_the_fictional_range_passes_and_one_outside_it_does_not(): void {
		$this->assertSame( [], self::fiction_problems( 'x', 'Call (555) 555-0100, 555-555-0199 or 555.555.0123.' ) );
		$this->assertCount( 3, self::fiction_problems( 'x', 'Call (555) 555-0200, (555) 555-0099 or (212) 736-5000.' ) );
	}

	public function test_an_email_at_example_com_passes_and_any_other_does_not(): void {
		$this->assertSame( [], self::fiction_problems( 'x', 'Write to hello@example.com or hello@saltglass-inn.example.com.' ) );
		$this->assertCount( 3, self::fiction_problems( 'x', 'Write to a@gmail.com, b@example.co or c@notexample.com.' ) );
	}
}
