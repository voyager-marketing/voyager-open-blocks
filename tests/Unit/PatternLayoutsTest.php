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
	 * award. Default copy holds none of them (contract 3).
	 */
	private const CLAIMS = '/licen[cs]|insur|bonded|certif|accredit|award|rated|rating|review|star|years?\b|since|established|guarantee|warrant|best|top|leading|#|\d/i';

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

	public function test_copy_rules_cover_every_named_text_block_and_its_default_copy_fits(): void {
		$intents = self::intents();
		$this->assertArrayHasKey( 'copy_rules', $intents['hero'] );
		$this->assertArrayHasKey( 'copy_rules', $intents['hero-cover'] );

		foreach ( $intents as $name => $intent ) {
			if ( ! array_key_exists( 'copy_rules', $intent ) ) {
				continue;
			}

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

	public function test_the_hero_default_copy_makes_no_claim(): void {
		foreach ( [ 'hero', 'hero-cover' ] as $name ) {
			$intent = self::intents()[ $name ];

			foreach ( self::text_blocks( $intent ) as $label => $text ) {
				$this->assertDoesNotMatchRegularExpression( self::CLAIMS, $text, "{$name} \"{$label}\"" );
			}
			foreach ( $intent['copy_rules'] as $label => $rule ) {
				$this->assertFalse( $rule['claim'], "{$name} \"{$label}\" is a claim slot, so its text must come from Site Data" );
			}
		}
	}

	public function test_the_claim_pattern_catches_a_planted_claim(): void {
		foreach ( [ 'Licensed and insured', 'Serving the county since 1998', '20 years in business', 'Rated 4.9 by our customers', 'Award-winning service' ] as $planted ) {
			$this->assertMatchesRegularExpression( self::CLAIMS, $planted, $planted );
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
