<?php
/**
 * Claim words in copy for a slot that is not a claim slot: insert and compose
 * refuse a claim the business has not given (contract 3 in
 * docs/v2-contracts.md, reason unsourced_claim).
 *
 * The business is kestovar-roofing from bench/briefs-v2.json, read as Site
 * Data would hold it. The refused sentences are the ones a benchmark agent
 * wrote for it, word for word: a free estimate, a free quote and a free
 * inspection it never offered, and certified materials where only its
 * installers are certified.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\Patterns;

require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class ClaimWordsTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';
	private const BRIEFS  = __DIR__ . '/../../bench/briefs-v2.json';

	/**
	 * Copy that makes no claim, though a looser list would call it one: years,
	 * a range, a date, a ZIP+4, a licence number and prices hold a digit, and
	 * "operating" and "preview" hold "rating" and "review" inside a word.
	 */
	private const FALSE_POSITIVES = [
		'We have re-roofed homes on this street in 2019, 2021 and 2024.',
		'Most repairs take 2-3 days, and a new roof 3 to 5.',
		'Book by June 30, 2027 for a start in July.',
		'We work across 43968-1234 and the towns nearby.',
		'Our contractor number, OH-123456, is on every invoice.',
		'Repairs from $350, and new roofs from $9,500.',
		'Operating hours are Monday to Friday, 7am to 5pm.',
		'Preview your new shingle colour before we start.',
	];

	/**
	 * kestovar-roofing's Site Data, as SiteData\values() resolves it: each
	 * list one item per line.
	 *
	 * @return array<string, string>
	 */
	private static function kestovar(): array {
		$briefs = json_decode( (string) file_get_contents( self::BRIEFS ), true );
		$brief  = [];

		foreach ( is_array( $briefs ) && is_array( $briefs['briefs'] ?? null ) ? $briefs['briefs'] : [] as $candidate ) {
			if ( is_array( $candidate ) && 'kestovar-roofing' === ( $candidate['id'] ?? null ) ) {
				$brief = $candidate;
			}
		}

		self::assertNotSame( [], $brief, 'bench/briefs-v2.json holds kestovar-roofing' );

		$list = static fn ( string $key ): string => implode( "\n", array_map( 'strval', is_array( $brief[ $key ] ?? null ) ? $brief[ $key ] : [] ) );

		return [
			'company'      => (string) $brief['business'],
			'credentials'  => $list( 'credentials' ),
			'services'     => $list( 'services' ),
			'service_area' => $list( 'service_area' ),
			'founded'      => (string) $brief['founded'],
			'rating'       => (string) $brief['rating'],
			'review_count' => (string) $brief['review_count'],
			'license'      => '',
		];
	}

	/**
	 * kestovar-roofing's credentials, in order.
	 *
	 * @return list<string>
	 */
	private static function credentials(): array {
		return explode( "\n", self::kestovar()['credentials'] );
	}

	/**
	 * What insert and compose refuse in a pattern's copy, against Site Data.
	 *
	 * @param string                    $slug      Intent name, such as cta-band.
	 * @param array<string, mixed>      $copy      Copy input.
	 * @param array<string, string>|null $site_data Site Data values; kestovar-roofing's when null.
	 * @return list<array<string, mixed>>
	 */
	private static function check( string $slug, array $copy, ?array $site_data = null ): array {
		$rules = Patterns\intent_meta( 'voyager-open/' . $slug, self::INTENTS )['copy_rules'];

		return Abilities\copy_rule_refusals( $rules, Abilities\copy_values( $copy ), [], [], $site_data ?? self::kestovar() );
	}

	/**
	 * Each refusal as "slot: reason".
	 *
	 * @param list<array<string, mixed>> $refused Refusals.
	 * @return list<string>
	 */
	private static function reasons( array $refused ): array {
		return array_map( static fn ( array $row ): string => (string) $row['slot'] . ': ' . (string) $row['reason'], $refused );
	}

	/**
	 * The five claims a benchmark agent made up for kestovar-roofing, each in
	 * every layout of its section: pattern, slot, text, and the claim word.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function invented(): array {
		$out = [];

		foreach ( [
			[ [ 'cta-band', 'cta-band-cover' ], 'Eyebrow', 'Free estimate', 'free' ],
			[ [ 'cta-band', 'cta-band-cover' ], 'Heading', 'Ready for a free roofing quote?', 'free' ],
			[ [ 'closing-cta', 'closing-cta-split' ], 'Body', 'Call or email us today and we will schedule your free estimate.', 'free' ],
			[ [ 'closing-cta', 'closing-cta-split' ], 'Heading', 'Ready for a free roofing estimate?', 'free' ],
			[ [ 'closing-cta', 'closing-cta-split' ], 'Body', 'Call or request a quote online, and we\'ll schedule your free inspection within two days.', 'free' ],
			[ [ 'services', 'services-list' ], 'Service 1 text', 'Full tear-off and reroofing with manufacturer-certified materials built to last for decades.', 'certified' ],
		] as [ $slugs, $slot, $text, $word ] ) {
			foreach ( $slugs as $slug ) {
				$out[ "{$slug} {$slot}: {$text}" ] = [ $slug, $slot, $text, $word ];
			}
		}

		return $out;
	}

	#[DataProvider( 'invented' )]
	public function test_a_claim_the_business_never_gave_is_refused_naming_the_word_and_the_credentials( string $slug, string $slot, string $text, string $word ): void {
		$refused = self::check( $slug, [ $slot => $text ] );

		$this->assertSame( [ "{$slot}: unsourced_claim" ], self::reasons( $refused ) );
		$this->assertSame( [ $word ], $refused[0]['words'] ?? null );
		$this->assertSame( self::credentials(), $refused[0]['credentials'] ?? null );

		$message = (string) ( $refused[0]['message'] ?? '' );
		$this->assertStringContainsString( "\"{$word}\"", $message );
		foreach ( self::credentials() as $credential ) {
			$this->assertStringContainsString( "\"{$credential}\"", $message, 'the message lists every credential the agent may use' );
		}
	}

	public function test_every_refused_slot_is_listed_at_once(): void {
		$refused = self::check(
			'closing-cta',
			[
				'Heading' => 'Ready for a free roofing estimate?',
				'Body'    => 'Call or request a quote online, and we\'ll schedule your free inspection within two days.',
			]
		);

		$this->assertSame( [ 'Heading: unsourced_claim', 'Body: unsourced_claim' ], self::reasons( $refused ) );
	}

	/**
	 * The business's own credentials said again in other words, and its
	 * figures with their values: pattern, slot, text.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function given(): array {
		$out = [];

		foreach ( [
			[ 'cta-band', 'Eyebrow', 'Free storm damage inspections' ],
			[ 'cta-band', 'Heading', 'Need a free storm-damage inspection?' ],
			[ 'cta-band', 'Body', 'Tell us about the job and we will send a written estimate within two days.' ],
			[ 'closing-cta', 'Body', 'Call us for a free inspection of your storm damage, and a written estimate in two days.' ],
			[ 'hero', 'Body', 'Licensed, insured roofers who put a written estimate in your hands within two days.' ],
			[ 'services', 'Service 1 text', 'New roofs fitted by installers the manufacturer certified, with a 10-year workmanship warranty.' ],
			[ 'hero', 'Body', 'Roofing for Kestovar homes since 1999, rated 4.8 from 276 reviews.' ],
			[ 'hero', 'Body', 'Highly rated by Kestovar homeowners, and fully licensed.' ],
			[ 'hero', 'Body', 'Highly rated, with 276 reviews from Kestovar homeowners.' ],
			[ 'stats', 'Rating label', 'Rating from 276 reviews' ],
		] as [ $slug, $slot, $text ] ) {
			$out[ "{$slug} {$slot}: {$text}" ] = [ $slug, $slot, $text ];
		}

		return $out;
	}

	#[DataProvider( 'given' )]
	public function test_a_claim_the_business_gave_passes_in_other_words( string $slug, string $slot, string $text ): void {
		$this->assertSame( [], self::reasons( self::check( $slug, [ $slot => $text ] ) ) );
	}

	public function test_a_claim_word_said_more_times_than_the_business_said_it_is_refused(): void {
		$this->assertSame( [ 'Body: unsourced_claim' ], self::reasons( self::check( 'cta-band', [ 'Body' => 'Free storm damage inspections, and free estimates on any roof.' ] ) ) );
	}

	public function test_a_figure_with_a_number_needs_its_site_data_value(): void {
		foreach ( [
			'Over 25 years of roofing in Kestovar.' => 'years',
			'Serving Kestovar homes since 1985.'    => 'since',
			'Rated 4.9 by Kestovar homeowners.'     => 'rated',
			'Five-star roofing for every home.'     => 'star',
		] as $text => $word ) {
			$refused = self::check( 'hero', [ 'Body' => $text ] );

			$this->assertSame( [ 'Body: unsourced_claim' ], self::reasons( $refused ), $text );
			$this->assertSame( [ $word ], $refused[0]['words'] ?? null, $text );
		}
	}

	public function test_a_rating_or_licence_word_needs_that_figure_set(): void {
		$none = [];

		$this->assertSame( [ 'Body: unsourced_claim' ], self::reasons( self::check( 'hero', [ 'Body' => 'Highly rated by homeowners across the county.' ], $none ) ) );
		$this->assertSame( [ 'Body: unsourced_claim' ], self::reasons( self::check( 'hero', [ 'Body' => 'Licensed roofers for every home in the county.' ], $none ) ) );
		$this->assertSame( [], self::reasons( self::check( 'hero', [ 'Body' => 'Licensed roofers for every home in the county.' ], [ 'license' => 'OH-123456' ] ) ) );
		$this->assertSame( [], self::reasons( self::check( 'hero', [ 'Body' => 'Highly rated by homeowners across the county.' ], [ 'rating' => '4.8' ] ) ) );
	}

	public function test_a_claim_slot_is_refused_as_before_and_never_as_unsourced(): void {
		$this->assertSame( [ 'Review 1 quote: claim' ], self::reasons( self::check( 'reviews', [ 'Review 1 quote' => 'They gave us a free estimate and the best roof on the street.' ] ) ) );
		$this->assertSame( [], self::reasons( self::check( 'reviews', [ 'Review 1 quote' => [ 'text' => 'They gave us a free estimate and the best roof on the street.', 'source' => 'user' ] ] ) ) );
	}

	public function test_text_the_site_owner_gave_is_not_checked(): void {
		$this->assertSame( [], self::reasons( self::check( 'cta-band', [ 'Eyebrow' => [ 'text' => 'Free estimate', 'source' => 'user' ] ] ) ) );
	}

	/**
	 * Copy that holds no claim word, or one that makes no claim, against no
	 * Site Data and against kestovar-roofing's: pattern, slot, text.
	 *
	 * @return array<string, array{string, string, string, array<string, string>|null}>
	 */
	public static function plain(): array {
		$out = [];

		foreach ( [
			// No claim word at all.
			[ 'cta-band', 'Body', 'Tell us about the job and we will reply within two days.' ],
			// Years, ranges, dates, ZIP+4, licence numbers, prices, and
			// words that only hold a claim word.
			...array_map( static fn ( string $text ): array => [ 'cta-band', 'Body', $text ], self::FALSE_POSITIVES ),
			[ 'cta-band', 'Body', 'Feel free to call with any roofing question.' ],
			// A word that names a figure, with no number: a stats label, and a year that is no claim.
			[ 'stats', 'Founded label', 'In business since' ],
			[ 'stats', 'Founded label', 'Year founded' ],
			[ 'stats', 'Rating label', 'Average rating' ],
			[ 'stats', 'Review count label', 'Customer reviews' ],
			[ 'services', 'Service 1 text', 'A full roof check each year, with any loose shingles fixed.' ],
		] as [ $slug, $slot, $text ] ) {
			$out[ "{$slug} {$slot}: {$text}, no Site Data" ]  = [ $slug, $slot, $text, [] ];
			$out[ "{$slug} {$slot}: {$text}, kestovar" ]      = [ $slug, $slot, $text, null ];
		}

		return $out;
	}

	/**
	 * @param array<string, string>|null $site_data Site Data, or null for kestovar-roofing's.
	 */
	#[DataProvider( 'plain' )]
	public function test_copy_that_makes_no_claim_is_not_refused( string $slug, string $slot, string $text, ?array $site_data ): void {
		$this->assertSame( [], self::reasons( self::check( $slug, [ $slot => $text ], $site_data ) ) );
	}

	public function test_each_false_positive_holds_what_the_list_leaves_out(): void {
		// The list default copy is held to (a digit, #, and "rating" and
		// "review" anywhere in a word) catches every line above, so each passes
		// only because the check leaves those out, and putting one back turns
		// its test red.
		$looser = '/' . str_replace( [ '\brating', '\breview' ], [ 'rating', 'review' ], Abilities\CLAIM_WORDS ) . '|#|\d/i';

		foreach ( self::FALSE_POSITIVES as $text ) {
			$this->assertMatchesRegularExpression( $looser, $text, $text );
		}
	}

	public function test_the_claim_words_are_one_list_that_every_part_of_the_check_reads(): void {
		$alternatives = Abilities\claim_alternatives();

		$this->assertSame( Abilities\CLAIM_WORDS, implode( '|', $alternatives ), 'no alternative holds a |, so the list splits into its words' );
		$this->assertCount( 20, $alternatives );

		foreach ( $alternatives as $alternative ) {
			$this->assertSame( 0, preg_match( '/' . $alternative . '/i', '' ), "{$alternative} compiles" );
		}

		foreach ( array_keys( Abilities\CLAIM_FIGURES ) as $alternative ) {
			$this->assertContains( $alternative, $alternatives, 'a figure is keyed by a claim word' );
		}

		foreach ( Abilities\FIGURE_NAMES as $alternative ) {
			$this->assertArrayHasKey( $alternative, Abilities\CLAIM_FIGURES, 'a word that names a figure has one' );
		}
	}

	public function test_the_hero_and_both_ctas_say_no_promise_in_every_layout(): void {
		$sentence = 'No price, rating, licence or promise unless the owner gave it.';

		foreach ( [
			'hero'              => [ 'Body' ],
			'hero-cover'        => [ 'Body' ],
			'cta-band'          => [ 'Eyebrow', 'Heading', 'Body' ],
			'cta-band-cover'    => [ 'Eyebrow', 'Heading', 'Body' ],
			'closing-cta'       => [ 'Heading', 'Body' ],
			'closing-cta-split' => [ 'Heading', 'Body' ],
			'services'          => [ 'Service 1 text', 'Service 2 text', 'Service 3 text' ],
		] as $slug => $slots ) {
			$rules = Patterns\intent_meta( 'voyager-open/' . $slug, self::INTENTS )['copy_rules'];

			foreach ( $slots as $slot ) {
				$this->assertStringEndsWith( $sentence, (string) ( $rules[ $slot ]['purpose'] ?? '' ), "{$slug} {$slot}" );
				$this->assertFalse( $rules[ $slot ]['claim'] ?? false, "{$slug} {$slot} stays a slot for the agent's own words" );
			}
		}
	}

	public function test_a_reworded_credential_keeps_its_own_words_in_any_form(): void {
		$this->assertSame( 'install', Abilities\claim_stem( 'installers' ) );
		$this->assertSame( 'install', Abilities\claim_stem( 'installation' ) );
		$this->assertSame( 'inspect', Abilities\claim_stem( 'inspections' ) );
		$this->assertSame( Abilities\claim_stem( 'damage' ), Abilities\claim_stem( 'damages' ) );
		$this->assertSame( Abilities\claim_stem( 'estimate' ), Abilities\claim_stem( 'estimates' ) );
		$this->assertSame( Abilities\claim_stem( 'business' ), Abilities\claim_stem( 'businesses' ) );
		$this->assertSame( 'day', Abilities\claim_stem( 'days' ) );
		$this->assertSame( [ '10', 'year', 'storm', 'damage' ], Abilities\claim_text_words( '10-year storm-damage' ) );
	}
}
