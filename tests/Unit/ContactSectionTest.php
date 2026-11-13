<?php
/**
 * The contact section shows its details through the contact details block.
 *
 * Its details are one voyager-open/contact-details named "Details", with its
 * seven attributes bound to Site Data, so the phone dials and the email opens
 * a new message. Its own phone and email are the labels "Phone" and "Email",
 * and its links are empty, so with Site Data empty the section shows the two
 * labels as text and no link. No pattern ships a fallback that looks like a
 * phone number or an email address, so validate's stale_fallback: needs no
 * list of the pack's own fallbacks, and warns on a section saved with the
 * sample number an earlier release shipped.
 *
 * The blocks are built from the intent (IntentBlocks), which is what
 * patterns/contact-section.html is built from. scripts/e2e.mjs publishes the
 * section on a running site and counts its links.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\ContactDetails;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/facts-stubs.php';
require_once dirname( __DIR__ ) . '/contact-details-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/contact-details.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';
require_once __DIR__ . '/IntentBlocks.php';

final class ContactSectionTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';
	private const FIXTURE = __DIR__ . '/../fixtures/site-data-keys.json';
	private const WRAPPER = 'class="wp-block-voyager-open-contact-details"';
	private const C       = 'wp-block-voyager-open-contact-details';

	/**
	 * The block's attribute => the Site Data key the section binds it to.
	 */
	private const KEYS = [
		'phone'           => 'phone',
		'phoneLink'       => 'phone_link',
		'email'           => 'email',
		'emailLink'       => 'email_link',
		'address'         => 'address',
		'addressLocality' => 'address_locality',
		'hours'           => 'hours',
	];

	/**
	 * The Site Data fields the details show, as get-catalog's used_on and the
	 * purge name them.
	 */
	private const SHOWN = [ 'phone', 'email', 'address', 'address_locality', 'hours' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']            = [];
		$GLOBALS['voyager_test_bloginfo']           = [];
		$GLOBALS['voyager_test_filters']            = [];
		$GLOBALS['voyager_test_wrapper_attributes'] = self::WRAPPER;
	}

	/**
	 * Every node of an intent tree, depth first.
	 *
	 * @param mixed $nodes Intent nodes.
	 * @return list<array<string, mixed>>
	 */
	private static function nodes( mixed $nodes ): array {
		$out = [];

		foreach ( is_array( $nodes ) ? $nodes : [] as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			/** @var array<string, mixed> $node */
			$out[] = $node;
			array_push( $out, ...self::nodes( $node['children'] ?? [] ) );
		}

		return $out;
	}

	/**
	 * The contact section's nodes, depth first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function section(): array {
		$intent = IntentBlocks::intent( 'contact-section' )['intent'] ?? [];

		return self::nodes( is_array( $intent ) ? ( $intent['blocks'] ?? [] ) : [] );
	}

	/**
	 * A node's attributes.
	 *
	 * @param array<string, mixed> $node Intent node.
	 * @return array<string, mixed>
	 */
	private static function attrs( array $node ): array {
		/** @var array<string, mixed> $attrs */
		$attrs = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];

		return $attrs;
	}

	/**
	 * A node's bindings, attribute => binding.
	 *
	 * @param array<string, mixed> $node Intent node.
	 * @return array<string, mixed>
	 */
	private static function bindings( array $node ): array {
		$metadata = self::attrs( $node )['metadata'] ?? null;
		/** @var array<string, mixed> $bindings */
		$bindings = is_array( $metadata ) && is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];

		return $bindings;
	}

	/**
	 * The section's contact details block, as its intent node.
	 *
	 * @return array<string, mixed>
	 */
	private static function details(): array {
		$found = array_values( array_filter( self::section(), static fn ( array $node ): bool => 'voyager-open/contact-details' === ( $node['block'] ?? null ) ) );
		self::assertCount( 1, $found, 'the contact section holds one contact details block' );

		return $found[0];
	}

	/**
	 * The block's own attributes as the render file gets them when every
	 * binding's source returns nothing: core keeps the stored value.
	 *
	 * @param array<string, mixed> $node   Intent node.
	 * @param array<string, mixed> $values Resolved values by attribute, for the bindings that return one.
	 * @return array<string, mixed>
	 */
	private static function rendered( array $node, array $values = [] ): array {
		$attrs = self::attrs( $node );
		unset( $attrs['metadata'] );

		return array_merge( $attrs, $values );
	}

	/**
	 * What each binding of the block hands the render file, with the Site
	 * Data the test has set. A binding whose source returns null is left out.
	 *
	 * @param array<string, mixed> $node Intent node.
	 * @return array<string, string>
	 */
	private static function resolve( array $node ): array {
		$out = [];

		foreach ( self::bindings( $node ) as $attribute => $binding ) {
			$args  = is_array( $binding ) && is_array( $binding['args'] ?? null ) ? $binding['args'] : [];
			$value = SiteData\get_binding_value( $args, null, $attribute );
			if ( null !== $value ) {
				$out[ $attribute ] = $value;
			}
		}

		return $out;
	}

	public function test_the_details_are_one_contact_details_block_named_details_with_its_seven_attributes_bound(): void {
		$details  = self::details();
		$metadata = self::attrs( $details )['metadata'] ?? null;

		$this->assertIsArray( $metadata );
		$this->assertSame( 'Details', $metadata['name'] ?? null );
		$this->assertSame( array_keys( self::KEYS ), array_keys( self::bindings( $details ) ), 'every attribute, in block.json order' );
		$this->assertSame( ContactDetails\ATTRIBUTES, array_keys( self::KEYS ) );

		foreach ( self::KEYS as $attribute => $key ) {
			$this->assertSame(
				[
					'source' => SiteData\SOURCE,
					'args'   => [ 'key' => $key ],
				],
				self::bindings( $details )[ $attribute ] ?? null,
				$attribute
			);
		}
	}

	public function test_no_text_block_in_the_section_is_bound_and_only_the_details_show_the_contact_fields(): void {
		$bound_text = [];
		$shown_by   = [];

		foreach ( self::section() as $node ) {
			$name     = is_string( $node['block'] ?? null ) ? $node['block'] : '';
			$bindings = self::bindings( $node );
			$metadata = self::attrs( $node )['metadata'] ?? null;
			$label    = is_array( $metadata ) && is_string( $metadata['name'] ?? null ) ? $metadata['name'] : $name;

			if ( isset( BlockTree\TEXT_ATTRIBUTES[ $name ] ) && isset( $bindings[ BlockTree\TEXT_ATTRIBUTES[ $name ] ] ) ) {
				$bound_text[] = $label;
			}

			foreach ( $bindings as $binding ) {
				$key = is_array( $binding ) && is_array( $binding['args'] ?? null ) ? ( $binding['args']['key'] ?? null ) : null;
				if ( in_array( $key, self::SHOWN, true ) ) {
					$shown_by[] = $label;
				}
			}
		}

		$this->assertSame( [], $bound_text, 'no paragraph, heading or button text is bound: the details block shows the fields' );
		$this->assertSame( array_fill( 0, count( self::SHOWN ), 'Details' ), $shown_by, 'the phone, email, address, city line and hours are bound in the details block alone' );
	}

	public function test_its_phone_and_email_ship_as_labels_and_its_links_ship_empty(): void {
		$attrs = self::attrs( self::details() );

		$this->assertSame( 'Phone', $attrs['phone'] ?? null );
		$this->assertSame( 'Email', $attrs['email'] ?? null );
		$this->assertSame( '', $attrs['phoneLink'] ?? '' );
		$this->assertSame( '', $attrs['emailLink'] ?? '' );

		foreach ( array_keys( self::KEYS ) as $attribute ) {
			$value = $attrs[ $attribute ] ?? '';
			$this->assertIsString( $value, $attribute );
			$this->assertNull( Abilities\looks_like( $value ), "{$attribute} ships \"{$value}\", which looks like a phone number or an email address" );
		}
	}

	public function test_with_site_data_empty_the_section_shows_phone_and_email_as_text_and_no_link(): void {
		$details = self::details();

		$this->assertSame( [], self::resolve( $details ), 'with every field empty, no binding returns a value' );

		$html = ContactDetails\markup( self::rendered( $details ), self::WRAPPER );

		$this->assertStringContainsString( '<span class="' . self::C . '__phone">Phone</span>', $html );
		$this->assertStringContainsString( '<span class="' . self::C . '__email">Email</span>', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'tel:', $html );
		$this->assertStringNotContainsString( 'mailto:', $html );
	}

	public function test_with_site_data_filled_the_phone_dials_and_the_email_opens_a_new_message(): void {
		/** @var array{options: array<string, string>, expected: array<string, string>} $fixture */
		$fixture                         = json_decode( (string) file_get_contents( self::FIXTURE ), true, 512, JSON_THROW_ON_ERROR );
		$GLOBALS['voyager_test_options'] = $fixture['options'];
		$details                         = self::details();
		$values                          = self::resolve( $details );

		$this->assertSame( array_keys( self::KEYS ), array_keys( $values ), 'every binding returns a value' );

		$html = ContactDetails\markup( self::rendered( $details, $values ), self::WRAPPER );

		preg_match_all( '/<a\b[^>]*\bhref="([a-z]+):[^"]*"[^>]*>([^<]*)<\/a>/', $html, $links, PREG_SET_ORDER );
		$this->assertSame(
			[
				[ 'tel', $fixture['expected']['phone'] ],
				[ 'mailto', $fixture['expected']['email'] ],
			],
			array_map( static fn ( array $link ): array => [ $link[1], $link[2] ], $links ),
			'one tel: link reading the phone and one mailto: link reading the email'
		);
		$this->assertStringContainsString( 'href="' . $fixture['expected']['phone_link'] . '"', $html );
		$this->assertStringContainsString( 'href="' . $fixture['expected']['email_link'] . '"', $html );
	}

	public function test_validate_on_the_shipped_section_reports_no_stale_fallback(): void {
		$blocks    = IntentBlocks::blocks( 'contact-section' );
		$fallbacks = array_values( array_filter( Abilities\bound_fallbacks( $blocks ), static fn ( array $fallback ): bool => 'voyager-open/contact-details' === $fallback['block'] ) );

		$this->assertSame( [], Abilities\stale_fallbacks( $blocks ) );
		$this->assertSame(
			[
				'phone' => 'Phone',
				'email' => 'Email',
			],
			array_intersect_key( array_column( $fallbacks, 'fallback', 'key' ), [ 'phone' => 0, 'email' => 0 ] ),
			'the phone and the email fall back to their labels'
		);
	}

	public function test_no_pattern_ships_a_fallback_that_looks_like_a_phone_number_or_an_email_address(): void {
		$files = glob( self::INTENTS . '/*.json' ) ?: [];
		$this->assertNotSame( [], $files, 'no intent files were read' );

		foreach ( $files as $file ) {
			$name = basename( $file, '.json' );
			$this->assertSame( [], Abilities\stale_fallbacks( IntentBlocks::blocks( $name ) ), $name );
		}
	}

	public function test_a_contact_section_saved_with_the_sample_number_warns(): void {
		// As an earlier release saved the section: a paragraph bound to the
		// phone holding the sample number, and one bound to the email holding
		// the sample address.
		$paragraph = static fn ( string $label, string $text, string $key ): array => IntentBlocks::block(
			[
				'block' => 'core/paragraph',
				'text'  => $text,
				'attrs' => [
					'metadata' => [
						'name'     => $label,
						'bindings' => [
							'content' => [
								'source' => SiteData\SOURCE,
								'args'   => [ 'key' => $key ],
							],
						],
					],
				],
			]
		);
		$saved     = [ $paragraph( 'Phone', '(555) 010-0100', 'phone' ), $paragraph( 'Email', 'hello@example.com', 'email' ) ];

		$this->assertSame( [ 'phone', 'email' ], array_column( Abilities\stale_fallbacks( $saved ), 'looks_like' ) );

		// The contact details block with the same samples as its own phone and email.
		$details                   = self::details();
		$details['attrs']          = self::attrs( $details );
		$details['attrs']['phone'] = '(555) 010-0100';
		$details['attrs']['email'] = 'hello@example.com';
		$stale                     = Abilities\stale_fallbacks( [ IntentBlocks::block( $details ) ] );

		$this->assertSame(
			[
				'phone' => 'phone',
				'email' => 'email',
			],
			array_column( $stale, 'looks_like', 'attribute' )
		);
		$this->assertStringStartsWith(
			'stale_fallback: voyager-open/contact-details "Details" at 0, binding "phone" (voyager/site-data, key "phone"): its fallback "(555) 010-0100" looks like a phone number.',
			Abilities\stale_fallback_warning( $stale[0] )
		);
	}

	public function test_validate_keeps_no_list_of_the_packs_own_fallbacks(): void {
		$this->assertFalse( function_exists( 'VoyagerOpenBlocks\Abilities\shipped_fallbacks' ) );
	}

	public function test_the_section_binds_each_contact_field_so_used_on_and_the_purge_list_its_pages(): void {
		$patterns = Patterns\bound_keys( SiteData\SOURCE, self::INTENTS );
		$keys     = $patterns['voyager-open/contact-section']['keys'] ?? [];

		foreach ( array_values( self::KEYS ) as $key ) {
			$this->assertContains( $key, $keys );
		}

		// Settings > Site Data names the section under each field it shows.
		$used = SiteData\used_by( $patterns );
		foreach ( [ 'phone', 'email', 'address', 'address_city', 'address_state', 'address_zip', 'hours' ] as $field ) {
			$this->assertContains( 'Contact section', $used[ $field ], $field );
		}
	}

	public function test_compose_names_each_field_the_details_bind_while_it_is_empty(): void {
		$rows = [];
		foreach ( self::section() as $node ) {
			if ( [] !== self::bindings( $node ) ) {
				$rows[] = [
					'path'     => '',
					'block'    => is_string( $node['block'] ?? null ) ? $node['block'] : '',
					'bindings' => self::bindings( $node ),
				];
			}
		}

		$this->assertSame( array_values( self::KEYS ), Abilities\empty_site_data( $rows ) );

		$GLOBALS['voyager_test_options'] = [ 'voyager_site_phone' => '(555) 010-0199' ];
		$this->assertSame( [ 'email', 'email_link', 'address', 'address_locality', 'hours' ], Abilities\empty_site_data( $rows ) );
	}
}
