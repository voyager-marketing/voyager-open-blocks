<?php
/**
 * The audit ability's checks (inc/abilities/audit.php), one code at a time.
 *
 * Parsing markup needs WordPress, so these build blocks the way
 * parse_blocks() returns them, and the pattern sections from their intents
 * (IntentBlocks). Site Data comes from the options the stubs hold.
 * scripts/e2e.mjs saves tests/fixtures/audit/planted.html and audits it over
 * REST, and audits a home page compose builds.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/bindings-editor-stubs.php';
require_once dirname( __DIR__ ) . '/validate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/facts.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/bindings-editor.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class AuditTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	private const SITE = [
		'phone'   => '(555) 010-0199',
		'email'   => 'hello@example.com',
		'address' => '42 Example Avenue',
	];

	private const NONE = [
		'phone'   => '',
		'email'   => '',
		'address' => '',
	];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']  = [];
		$GLOBALS['voyager_test_filters']  = [];
		$GLOBALS['voyager_test_bloginfo'] = [ 'name' => 'Example Co.' ];
	}

	/**
	 * A block as parse_blocks() returns it.
	 *
	 * @param string               $name  Block name.
	 * @param string               $html  Its own saved HTML.
	 * @param array<string, mixed> $attrs Comment attributes.
	 * @param array<array<mixed>>  $inner Inner blocks.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function block( string $name, string $html = '', array $attrs = [], array $inner = [] ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => array_merge( [ $html ], array_fill( 0, count( $inner ), null ) ),
		];
	}

	/**
	 * A paragraph holding a text.
	 *
	 * @param string               $text  Its text.
	 * @param array<string, mixed> $attrs Comment attributes.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function paragraph( string $text, array $attrs = [] ): array {
		return self::block( 'core/paragraph', "\n<p>" . htmlspecialchars( $text, ENT_QUOTES ) . "</p>\n", $attrs );
	}

	/**
	 * Attributes naming a block and binding it.
	 *
	 * @param string                $label    metadata.name, '' for none.
	 * @param array<string, string> $bindings Attribute => Site Data key.
	 * @return array<string, mixed>
	 */
	private static function meta( string $label, array $bindings = [] ): array {
		$metadata = '' === $label ? [] : [ 'name' => $label ];

		foreach ( $bindings as $attribute => $key ) {
			$metadata['bindings'][ $attribute ] = [
				'source' => SiteData\SOURCE,
				'args'   => [ 'key' => $key ],
			];
		}

		return [] === $metadata ? [] : [ 'metadata' => $metadata ];
	}

	/**
	 * Every pattern's blocks, built from its intents, as pack_pattern_blocks() lists them.
	 *
	 * @return array<int, array<mixed>>
	 */
	private static function pack(): array {
		$blocks = [];

		foreach ( glob( self::INTENTS . '/*.json' ) ?: [] as $file ) {
			array_push( $blocks, ...IntentBlocks::blocks( basename( $file, '.json' ) ) );
		}

		return $blocks;
	}

	/**
	 * The codes of some findings, in order.
	 *
	 * @param list<array{code: string, block: string, path: string, message: string}> $findings Findings.
	 * @return list<string>
	 */
	private static function codes( array $findings ): array {
		return array_column( $findings, 'code' );
	}

	// typed_contact.

	/**
	 * Phone numbers as people type them.
	 *
	 * @return array<string, array{string}>
	 */
	public static function phone_numbers(): array {
		return [
			'brackets'                => [ 'Call (555) 555-0142 today.' ],
			'hyphens'                 => [ 'Call 555-555-0142.' ],
			'dots'                    => [ '555.555.0142' ],
			'with the country code'   => [ 'Call 1-555-555-0142.' ],
			'international, spaces'   => [ 'Ring +44 20 7946 0958.' ],
			'international, brackets' => [ '+1 (555) 555-0142' ],
			'national, trunk 0'       => [ 'Ring 020 7946 0958.' ],
			'national, mobile'        => [ '0412 345 678' ],
		];
	}

	#[DataProvider( 'phone_numbers' )]
	public function test_a_typed_phone_number_is_phone_shaped( string $text ): void {
		$this->assertTrue( Abilities\phone_shaped( $text ), $text );
	}

	/**
	 * Digits that are not a phone number. looks_like() reads several of them
	 * as one, which is right only for a contact field's fallback.
	 *
	 * @return array<string, array{string}>
	 */
	public static function not_phone_numbers(): array {
		return [
			'a copyright year range'   => [ '© 2015-2026 Example Co.' ],
			'years with spaces'        => [ 'Serving the area 1998 - 2026.' ],
			'an ISO date'              => [ 'Updated 2026-11-14.' ],
			'a date with dots'         => [ 'Since 01.02.2026' ],
			'ZIP+4'                    => [ 'Springfield, ST 12345-6789' ],
			'a ZIP code'               => [ 'Springfield, ST 00042' ],
			'a licence number'         => [ 'License #1234567' ],
			'a licence with letters'   => [ 'Lic. PL-000123' ],
			'a PO box'                 => [ 'PO Box 1234567' ],
			'opening hours'            => [ 'Open 09.00-17.00, Monday to Friday' ],
			'a range of sizes'         => [ 'Homes of 250-1500 square feet' ],
			'a price'                  => [ 'Projects from $1,250,000' ],
			'a number with no area'    => [ 'Call 555-0142.' ],
			'a code starting with 1'   => [ 'Order 123-456-7890' ],
			'a percentage'             => [ 'Up +20% this year' ],
			'a licence before a phone' => [ 'Ref PL-555-555-0142' ],
		];
	}

	#[DataProvider( 'not_phone_numbers' )]
	public function test_other_digits_are_not_phone_shaped( string $text ): void {
		$this->assertFalse( Abilities\phone_shaped( $text ), $text );
	}

	public function test_the_site_phone_is_found_by_its_digits_however_it_is_written(): void {
		foreach ( [ '(555) 010-0199', '555.010.0199', '+1 555 010 0199', 'Call 5550100199', 'Call 010-0199' ] as $text ) {
			$this->assertTrue( Abilities\holds_phone( $text, self::SITE['phone'] ), $text );
		}

		$this->assertFalse( Abilities\holds_phone( '(555) 010-0198', self::SITE['phone'] ) );
		$this->assertFalse( Abilities\holds_phone( '© 2015-2026', self::SITE['phone'] ) );
		$this->assertFalse( Abilities\holds_phone( '0199', self::SITE['phone'] ), 'four digits are not a number' );
		$this->assertFalse( Abilities\holds_phone( '(555) 010-0199', '' ), 'no Site Data phone' );
	}

	public function test_typed_contact_names_each_key_and_whether_it_is_the_site_own(): void {
		$this->assertSame( [ 'phone' => true ], Abilities\typed_contact( 'Call (555) 010-0199.', self::SITE ) );
		$this->assertSame( [ 'phone' => false ], Abilities\typed_contact( 'Call (555) 555-0142.', self::SITE ) );
		$this->assertSame( [ 'email' => true ], Abilities\typed_contact( 'Write to Hello@Example.com.', self::SITE ) );
		$this->assertSame( [ 'email' => false ], Abilities\typed_contact( 'Write to jo@example.org.', self::SITE ) );
		$this->assertSame( [ 'address' => true ], Abilities\typed_contact( 'Visit us at 42  Example avenue, Springfield.', self::SITE ) );
		$this->assertSame(
			[
				'phone' => true,
				'email' => true,
			],
			Abilities\typed_contact( '(555) 010-0199 or hello@example.com', self::SITE )
		);
		$this->assertSame( [], Abilities\typed_contact( 'Visit us at 42 Example Avenue.', self::NONE ), 'an address counts only as the site\'s own' );
		$this->assertSame( [], Abilities\typed_contact( '© 2015-2026 Example Co.', self::SITE ) );
		$this->assertSame( [], Abilities\typed_contact( 'Call us', self::SITE ) );
		$this->assertSame( [], Abilities\typed_contact( 'Example', [ 'address' => 'Ex' ] + self::SITE ), 'a short address is not looked for' );
	}

	public function test_typed_contact_findings_check_text_that_is_the_block_own(): void {
		$blocks = [
			self::paragraph( 'Call (555) 010-0199 for a quote.' ),
			self::paragraph( '(555) 010-0199', self::meta( 'Phone', [ 'content' => 'phone' ] ) ),
			self::block(
				'core/buttons',
				'',
				[],
				[ self::block( 'core/button', '<div class="wp-block-button"><a class="wp-block-button__link" href="#">Email jo@example.org</a></div>', self::meta( 'Email button', [ 'url' => 'email_link' ] ) ) ]
			),
			self::paragraph( '© 2015-2026 Example Co.' ),
			self::block( 'core/image', '<figure><img src="https://example.com/555-555-0142.jpg" alt=""/></figure>' ),
		];

		$found = Abilities\typed_contact_findings( $blocks, self::SITE );

		$this->assertSame( [ 'typed_contact', 'typed_contact' ], self::codes( $found ) );
		$this->assertSame( [ '0', '2/0' ], array_column( $found, 'path' ) );
		$this->assertSame( [ 'core/paragraph', 'core/button' ], array_column( $found, 'block' ) );
		$this->assertStringContainsString( 'holds the site\'s phone number, typed in', $found[0]['message'] );
		$this->assertStringContainsString( 'bound to "phone" with bind', $found[0]['message'] );
		$this->assertStringContainsString( 'core/button "Email button" at 2/0 holds an email address', $found[1]['message'] );
		$this->assertStringContainsString( '"email"', $found[1]['message'] );
	}

	// default_copy.

	public function test_the_index_holds_each_label_text_outside_numbered_items(): void {
		$index = Abilities\default_copy_index( self::pack() );

		$this->assertContains( 'Say what you do and who it is for', $index['Heading'] ?? [] );
		$this->assertContains( 'How it works', $index['Heading'] ?? [] );
		$this->assertContains( 'Get a quote', $index['Primary button'] ?? [] );
		$this->assertArrayNotHasKey( 'Credential 1', $index, 'a numbered item is left to placeholder' );
		$this->assertArrayNotHasKey( 'Service 1 heading', $index );
		$this->assertArrayNotHasKey( 'Rating value', $index, 'a bound block holds a fallback' );
	}

	public function test_a_hero_heading_left_at_its_default_is_default_copy(): void {
		$hero  = IntentBlocks::blocks( 'hero' );
		$found = Abilities\default_copy_findings( $hero, Abilities\default_copy_index( self::pack() ) );
		$texts = array_column( $found, 'message' );

		$this->assertSame( array_fill( 0, 4, 'default_copy' ), self::codes( $found ), implode( "\n", $texts ) );
		$this->assertStringStartsWith( 'core/paragraph "Eyebrow" at 0/0/0/0/0 still holds "Your service and area", the text the pack ships there.', $texts[0] );
		$this->assertStringStartsWith( 'core/heading "Heading" at 0/0/0/0/1 still holds "Say what you do and who it is for"', $texts[1] );
		$this->assertStringContainsString( 'Replace it in the editor', $texts[1] );
	}

	public function test_default_copy_matches_the_label_and_the_whole_text(): void {
		$index  = [ 'Heading' => [ 'How it works' ] ];
		$blocks = [
			self::block( 'core/heading', '<h2>How it works</h2>', self::meta( 'Heading' ) ),
			self::block( 'core/heading', '<h2>How it works for you</h2>', self::meta( 'Heading' ) ),
			self::block( 'core/heading', '<h2>How it works</h2>', self::meta( 'Title' ) ),
			self::block( 'core/heading', '<h2>How it works</h2>' ),
			self::block( 'core/heading', '<h2>How it works</h2>', self::meta( 'Heading', [ 'content' => 'tagline' ] ) ),
		];

		$found = Abilities\default_copy_findings( $blocks, $index );

		$this->assertSame( [ '0' ], array_column( $found, 'path' ), 'only the block with the label and the whole text, unbound' );
	}

	public function test_numbered_items_are_left_to_the_placeholder_warning(): void {
		$strip = IntentBlocks::blocks( 'proof-strip' );

		$this->assertSame( [], Abilities\default_copy_findings( $strip, Abilities\default_copy_index( self::pack() ) ) );
		$this->assertNotSame( [], Abilities\stand_in_warnings( $strip, Abilities\stand_in_index( self::pack() ) ), 'the stand-ins are still found, as placeholder' );
	}

	// placeholder, missing_alt and stale_fallback: validate's own functions.

	public function test_validate_warnings_become_findings_with_their_code_and_block(): void {
		$blocks = [
			self::block( 'core/image', '<figure><img src="https://example.com/wp-content/plugins/voyager-open-blocks/assets/placeholders/image-4x3.svg" alt=""/></figure>', self::meta( 'Image' ) ),
			self::block( 'core/group', '', [], [ self::block( 'core/image', '<figure><img src="https://example.com/team.jpg" alt=""/></figure>' ) ] ),
			self::block( 'core/image', '<figure><img src="https://example.com/team.jpg" alt="Our team"/></figure>' ),
		];

		$found = Abilities\coded_findings( Abilities\image_warnings( $blocks, [ 'placeholders/image-4x3.svg' ] ), Abilities\block_names( $blocks ) );

		$this->assertSame( [ 'placeholder', 'missing_alt' ], self::codes( $found ) );
		$this->assertSame( [ '0', '1/0' ], array_column( $found, 'path' ) );
		$this->assertSame( [ 'core/image', 'core/image' ], array_column( $found, 'block' ) );
		$this->assertStringStartsWith( 'core/image "Image" at 0 shows the pack\'s placeholder image placeholders/image-4x3.svg.', $found[0]['message'] );
		$this->assertStringStartsWith( 'core/image at 1/0 has no alt text', $found[1]['message'] );
	}

	public function test_a_numbered_item_stand_in_is_a_placeholder_finding(): void {
		$strip = IntentBlocks::blocks( 'proof-strip' );
		$found = Abilities\coded_findings( Abilities\stand_in_warnings( $strip, Abilities\stand_in_index( self::pack() ) ), Abilities\block_names( $strip ) );

		$this->assertNotSame( [], $found );
		$this->assertSame( array_fill( 0, count( $found ), 'placeholder' ), self::codes( $found ) );
		$this->assertStringContainsString( 'the stand-in text the pack ships there', $found[0]['message'] );
	}

	public function test_a_stale_phone_fallback_is_a_finding_with_validate_message(): void {
		$blocks = [
			self::paragraph( '(555) 555-0199', self::meta( 'Phone', [ 'content' => 'phone' ] ) ),
			self::paragraph( 'Call us', self::meta( 'Phone', [ 'content' => 'phone' ] ) ),
		];

		$found = Abilities\stale_fallback_findings( Abilities\stale_fallbacks( $blocks ) );

		$this->assertSame( [ 'stale_fallback' ], self::codes( $found ) );
		$this->assertSame( '0', $found[0]['path'] );
		$this->assertStringStartsWith( 'core/paragraph "Phone" at 0, binding "content" (voyager/site-data, key "phone"): its fallback "(555) 555-0199" looks like a phone number.', $found[0]['message'] );
		$this->assertStringNotContainsString( 'stale_fallback:', $found[0]['message'] );
	}

	// contrast.

	public function test_white_text_on_a_light_background_fails(): void {
		$blocks = [
			self::paragraph(
				'Hello',
				[
					'style' => [
						'color' => [
							'text'       => '#ffffff',
							'background' => '#f5f5f5',
						],
					],
				]
			),
		];

		$found = Abilities\contrast_findings( $blocks, [], [ 255.0, 255.0, 255.0, 1.0 ] );

		$this->assertSame( [ 'contrast' ], self::codes( $found ) );
		$this->assertStringStartsWith( 'core/paragraph at 0 sets its text to #ffffff on #f5f5f5, which reads 1.09:1, under the 4.5:1 text needs.', $found[0]['message'] );
	}

	public function test_presets_are_read_through_the_palette(): void {
		$palette = [
			'base'     => '#FFFFFF',
			'contrast' => '#111111',
			'accent-4' => '#686868',
			'accent-6' => '#eeeeee',
		];
		$colored = static fn ( string $text, string $background ): array => self::paragraph(
			'Hello',
			[
				'textColor'       => $text,
				'backgroundColor' => $background,
			]
		);
		$blocks  = [
			$colored( 'contrast', 'base' ),
			$colored( 'base', 'accent-6' ),
			$colored( 'accent-4', 'base' ),
			$colored( 'base', 'contrast' ),
		];

		$found = Abilities\contrast_findings( $blocks, $palette, [ 255.0, 255.0, 255.0, 1.0 ] );

		$this->assertSame( [ '1' ], array_column( $found, 'path' ) );
		$this->assertStringContainsString( 'sets its text to the "base" preset on the "accent-6" preset', $found[0]['message'] );
	}

	public function test_a_custom_value_wins_over_a_preset_and_var_presets_are_read(): void {
		$block = self::paragraph(
			'Hello',
			[
				'textColor'       => 'contrast',
				'backgroundColor' => 'base',
				'style'           => [ 'color' => [ 'text' => 'var:preset|color|accent-6' ] ],
			]
		);

		$this->assertSame(
			[
				'text'       => 'var(--wp--preset--color--accent-6)',
				'background' => 'var(--wp--preset--color--base)',
			],
			Abilities\own_colors( $block )
		);
		$this->assertCount(
			1,
			Abilities\contrast_findings(
				[ $block ],
				[
					'base'     => '#ffffff',
					'accent-6' => '#eeeeee',
				],
				[ 255.0, 255.0, 255.0, 1.0 ]
			)
		);
	}

	public function test_a_colour_that_cannot_be_judged_is_not_reported(): void {
		$blocks = [
			self::paragraph( 'One colour', [ 'style' => [ 'color' => [ 'text' => '#ffffff' ] ] ] ),
			self::paragraph(
				'Unknown preset',
				[
					'textColor'       => 'white',
					'backgroundColor' => 'base',
				]
			),
			self::paragraph(
				'A mix',
				[
					'style' => [
						'color' => [
							'text'       => 'color-mix(in srgb, #fff 50%, #000)',
							'background' => '#ffffff',
						],
					],
				]
			),
			self::paragraph( 'A gradient', [ 'style' => [ 'color' => [ 'text' => '#ffffff', 'gradient' => 'linear-gradient(#fff,#eee)' ] ] ] ),
		];

		$this->assertSame( [], Abilities\contrast_findings( $blocks, [ 'base' => '#ffffff' ], [ 255.0, 255.0, 255.0, 1.0 ] ) );
	}

	public function test_contrast_finds_a_block_at_any_depth(): void {
		$blocks = [
			self::block(
				'core/group',
				'',
				[],
				[
					self::paragraph(
						'Deep',
						[
							'style' => [
								'color' => [
									'text'       => '#777777',
									'background' => '#888888',
								],
							],
						]
					),
				]
			),
		];

		$this->assertSame( [ '0/0' ], array_column( Abilities\contrast_findings( $blocks, [], [ 255.0, 255.0, 255.0, 1.0 ] ), 'path' ) );
	}

	// empty_field.

	public function test_each_empty_bound_field_is_one_finding_on_its_first_block(): void {
		$GLOBALS['voyager_test_options'] = [
			'voyager_site_phone' => '(555) 010-0199',
			'voyager_site_hours' => '',
		];

		$blocks = [
			self::paragraph( 'Our hours', self::meta( 'Hours', [ 'content' => 'hours' ] ) ),
			self::paragraph( 'Call us', self::meta( 'Phone', [ 'content' => 'phone' ] ) ),
			self::block( 'core/group', '', [], [ self::paragraph( 'Hours again', self::meta( '', [ 'content' => 'hours' ] ) ) ] ),
			self::paragraph( 'Licence', self::meta( '', [ 'content' => 'license' ] ) ),
		];

		$found = Abilities\empty_field_findings( BlockTree\describe( $blocks ) );

		$this->assertSame( [ 'empty_field', 'empty_field' ], self::codes( $found ) );
		$this->assertSame( [ '0', '3' ], array_column( $found, 'path' ) );
		$this->assertSame( 'Site Data\'s "Business hours" field (hours) is empty, so core/paragraph "Hours" at 0 shows its own text, and so does 1 more block bound to it. Fill it in under Settings > Site Data.', $found[0]['message'] );
		$this->assertStringStartsWith( 'Site Data\'s "License number" field (license) is empty, so core/paragraph at 3 shows its own text. Fill', $found[1]['message'] );
	}

	public function test_no_finding_when_every_bound_field_is_filled(): void {
		$GLOBALS['voyager_test_options'] = [ 'voyager_site_hours' => 'Monday to Friday' ];

		$this->assertSame( [], Abilities\empty_field_findings( BlockTree\describe( [ self::paragraph( 'Our hours', self::meta( 'Hours', [ 'content' => 'hours' ] ) ) ] ) ) );
	}

	// The report.

	public function test_counts_list_every_code_and_findings_sort_in_block_order(): void {
		$finding = static fn ( string $code, string $path ): array => [
			'code'    => $code,
			'block'   => 'core/paragraph',
			'path'    => $path,
			'message' => '',
		];

		$found = [ $finding( 'typed_contact', '2' ), $finding( 'default_copy', '0/1' ), $finding( 'contrast', '0/1' ), $finding( 'contrast', '10' ), $finding( 'empty_field', '1' ) ];

		$this->assertSame(
			[
				'typed_contact'  => 1,
				'default_copy'   => 1,
				'placeholder'    => 0,
				'missing_alt'    => 0,
				'stale_fallback' => 0,
				'contrast'       => 2,
				'empty_field'    => 1,
			],
			Abilities\audit_counts( $found )
		);
		$this->assertSame(
			[ '0/1 default_copy', '0/1 contrast', '1 empty_field', '2 typed_contact', '10 contrast' ],
			array_map( static fn ( array $f ): string => $f['path'] . ' ' . $f['code'], Abilities\in_block_order( $found ) )
		);
	}

	public function test_the_ability_reads_only_and_takes_post_id_alone(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/inc/abilities/audit.php' );

		$this->assertStringContainsString( "'meta'                => meta( true, true ),", $source );
		$this->assertStringContainsString( "'permission_callback' => __NAMESPACE__ . '\\can_edit_post',", $source );
		$this->assertDoesNotMatchRegularExpression( '/\b(wp_update_post|wp_insert_post|update_option|update_post_meta|save_blocks|BlockTree\\\\save|wp_save_post_revision)\s*\(/', $source, 'audit writes nothing' );
	}
}
