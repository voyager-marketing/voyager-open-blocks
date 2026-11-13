<?php
/**
 * voyager-open/contact-details: its render file, its parts, the bindable
 * attributes, and validate's stale_fallback warning on its own phone.
 *
 * WordPress applies a binding before it calls the render file
 * (WP_Block::render() in wp-includes/class-wp-block.php), so these tests
 * render with the bound values already in the attributes. scripts/e2e.mjs
 * binds the block on a running site and reads the published page.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\ContactDetails;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/facts-stubs.php';
require_once dirname( __DIR__ ) . '/contact-details-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/contact-details.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class ContactDetailsTest extends TestCase {

	private const WRAPPER = 'class="wp-block-voyager-open-contact-details"';
	private const C       = 'wp-block-voyager-open-contact-details';
	private const FIXTURE = __DIR__ . '/../fixtures/site-data-keys.json';

	/**
	 * Attribute => the Site Data key it binds to.
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

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']            = [];
		$GLOBALS['voyager_test_bloginfo']           = [];
		$GLOBALS['voyager_test_filters']            = [];
		$GLOBALS['voyager_test_wrapper_attributes'] = self::WRAPPER;
		add_filter( 'block_bindings_supported_attributes_voyager-open/contact-details', 'VoyagerOpenBlocks\ContactDetails\bindable_attributes' );
	}

	/**
	 * What src/contact-details/render.php prints for these attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes, bindings already applied.
	 */
	private function render( array $attributes ): string {
		ob_start();
		( static function ( array $attributes ): void {
			$content = '';
			$block   = null;
			require dirname( __DIR__, 2 ) . '/src/contact-details/render.php';
		} )( $attributes );

		return (string) ob_get_clean();
	}

	/**
	 * The fixture's resolved Site Data, by attribute: what the seven bindings
	 * hand the render file once the fixture's options are set.
	 *
	 * @return array<string, string>
	 */
	private static function fixture(): array {
		/** @var array{expected: array<string, string>} $fixture */
		$fixture = json_decode( (string) file_get_contents( self::FIXTURE ), true, 512, JSON_THROW_ON_ERROR );

		return array_map( static fn ( string $key ): string => $fixture['expected'][ $key ], self::KEYS );
	}

	private static function street( string $text ): string {
		return '<span class="' . self::C . '__street">' . $text . '</span>';
	}

	private static function locality( string $text ): string {
		return '<span class="' . self::C . '__locality">' . $text . '</span>';
	}

	private static function link( string $part, string $href, string $text ): string {
		return '<a class="' . self::C . '__' . $part . ' ' . self::C . '__link" href="' . $href . '">' . $text . '</a>';
	}

	private static function plain( string $part, string $text ): string {
		return '<span class="' . self::C . '__' . $part . '">' . $text . '</span>';
	}

	private static function hours( string $text ): string {
		return '<p class="' . self::C . '__hours">' . $text . '</p>';
	}

	private static function block( string $address, string $hours = '' ): string {
		return '<div ' . self::WRAPPER . '>'
			. ( '' === $address ? '' : '<address class="' . self::C . '__address">' . $address . '</address>' )
			. $hours . '</div>';
	}

	public function test_bound_to_the_fixture_it_prints_one_address_with_a_tel_and_a_mailto_link_then_the_hours(): void {
		$this->assertSame(
			self::block(
				self::street( '100 Example Street, Suite 4' )
					. self::locality( 'Springfield, IL 62701' )
					. self::link( 'phone', 'tel:+15550100123', '+1 (555) 010-0123' )
					. self::link( 'email', 'mailto:hello@example.com', 'hello@example.com' ),
				self::hours( 'Mon-Fri 8am-5pm' )
			),
			$this->render( self::fixture() )
		);
	}

	public function test_it_carries_the_wrapper_attributes(): void {
		$GLOBALS['voyager_test_wrapper_attributes'] = 'class="wp-block-voyager-open-contact-details has-text-color" id="contact"';

		$this->assertSame(
			'<div class="wp-block-voyager-open-contact-details has-text-color" id="contact">' . self::hours( 'Open daily' ) . '</div>',
			$this->render( [ 'hours' => 'Open daily' ] )
		);
	}

	/**
	 * Each part alone: the other six are empty.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function one_part(): array {
		return [
			'street'   => [ 'address', '100 Example Street', self::block( self::street( '100 Example Street' ) ) ],
			'locality' => [ 'addressLocality', 'Springfield, ST 00000', self::block( self::locality( 'Springfield, ST 00000' ) ) ],
			'phone'    => [ 'phone', '(555) 010-0100', self::block( self::plain( 'phone', '(555) 010-0100' ) ) ],
			'email'    => [ 'email', 'hello@example.com', self::block( self::plain( 'email', 'hello@example.com' ) ) ],
			'hours'    => [ 'hours', 'Mon-Fri 8am-5pm', self::block( '', self::hours( 'Mon-Fri 8am-5pm' ) ) ],
		];
	}

	#[DataProvider( 'one_part' )]
	public function test_each_part_prints_alone( string $attribute, string $value, string $expected ): void {
		$this->assertSame( $expected, $this->render( [ $attribute => $value ] ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function each_attribute(): array {
		return array_combine( array_keys( self::KEYS ), array_map( static fn ( string $name ): array => [ $name ], array_keys( self::KEYS ) ) );
	}

	#[DataProvider( 'each_attribute' )]
	public function test_with_one_part_empty_the_rest_still_print( string $attribute ): void {
		$all  = self::fixture();
		$html = $this->render( [ $attribute => '' ] + $all );

		$this->assertStringStartsWith( '<div ' . self::WRAPPER . '>', $html );
		foreach ( [ 'address' => 'street', 'addressLocality' => 'locality', 'phone' => 'phone', 'email' => 'email', 'hours' => 'hours' ] as $name => $part ) {
			if ( $name === $attribute ) {
				$this->assertStringNotContainsString( self::C . '__' . $part, $html, $name );
				$this->assertStringNotContainsString( '>' . $all[ $name ] . '<', $html, $name );
			} else {
				$this->assertStringContainsString( self::C . '__' . $part, $html, $name );
			}
		}
		if ( 'phoneLink' === $attribute ) {
			$this->assertStringNotContainsString( 'tel:', $html );
			$this->assertStringContainsString( self::plain( 'phone', $all['phone'] ), $html );
		}
		if ( 'emailLink' === $attribute ) {
			$this->assertStringNotContainsString( 'mailto:', $html );
			$this->assertStringContainsString( self::plain( 'email', $all['email'] ), $html );
		}
	}

	public function test_with_the_email_empty_no_email_line_and_no_mailto_link_render(): void {
		$html = $this->render( [ 'email' => '', 'emailLink' => '' ] + self::fixture() );

		$this->assertStringNotContainsString( 'mailto:', $html );
		$this->assertStringNotContainsString( '__email', $html );
		$this->assertStringContainsString( self::link( 'phone', 'tel:+15550100123', '+1 (555) 010-0123' ), $html );
		$this->assertSame( 1, substr_count( $html, '<address' ) );
		$this->assertStringContainsString( self::hours( 'Mon-Fri 8am-5pm' ), $html );
	}

	public function test_a_link_with_no_text_prints_nothing(): void {
		$this->assertSame( '', $this->render( [ 'phoneLink' => 'tel:+15550100123', 'emailLink' => 'mailto:hello@example.com' ] ) );
		$this->assertSame(
			self::block( self::street( 'One' ) ),
			$this->render( [ 'address' => 'One', 'phoneLink' => 'tel:+15550100123' ] )
		);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function nothing_set(): array {
		return [
			'no attributes'      => [ [] ],
			'all empty'          => [ array_fill_keys( array_keys( self::KEYS ), '' ) ],
			'only whitespace'    => [ array_fill_keys( array_keys( self::KEYS ), " \t\n " ) ],
			'not strings'        => [ array_fill_keys( array_keys( self::KEYS ), [ 'One' ] ) ],
			'nulls'              => [ array_fill_keys( array_keys( self::KEYS ), null ) ],
			'escaped whitespace' => [ [ 'phone' => '&#32;', 'hours' => '&#9;&#10;' ] ],
		];
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	#[DataProvider( 'nothing_set' )]
	public function test_it_prints_nothing_when_no_part_has_text( array $attributes ): void {
		$this->assertSame( '', $this->render( $attributes ) );
	}

	public function test_a_phone_or_email_with_no_link_prints_as_text(): void {
		$this->assertSame(
			self::block( self::plain( 'phone', '(555) 010-0100' ) . self::plain( 'email', 'hello@example.com' ) ),
			$this->render( [ 'phone' => '(555) 010-0100', 'email' => 'hello@example.com', 'phoneLink' => '', 'emailLink' => '  ' ] )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refused_links(): array {
		return [
			'javascript'          => [ 'javascript:alert(1)' ],
			'javascript, cased'   => [ 'JavaScript:alert(1)' ],
			'data'                => [ 'data:text/html,<script>alert(1)</script>' ],
			'vbscript'            => [ 'vbscript:msgbox(1)' ],
			'only bad characters' => [ '<>' ],
		];
	}

	#[DataProvider( 'refused_links' )]
	public function test_a_link_esc_url_refuses_prints_the_text_alone( string $href ): void {
		$this->assertSame(
			self::block( self::plain( 'phone', '(555) 010-0100' ) ),
			$this->render( [ 'phone' => '(555) 010-0100', 'phoneLink' => $href ] )
		);
	}

	public function test_hrefs_go_through_esc_url(): void {
		$html = $this->render(
			[
				'phone'     => 'Call',
				'phoneLink' => 'tel:+1 555 010 0100',
				'email'     => 'Write',
				'emailLink' => "mailto:o'neil&co@example.com\"onmouseover=\"alert(1)",
			]
		);

		$this->assertStringContainsString( 'href="tel:+1%20555%20010%200100"', $html );
		$this->assertStringContainsString( 'href="mailto:o&#039;neil&#038;co@example.comonmouseover=alert(1)"', $html );
		$this->assertStringNotContainsString( '" onmouseover', $html );
	}

	public function test_it_escapes_each_value(): void {
		$html = $this->render(
			[
				'address'         => '<script>alert(1)</script>',
				'addressLocality' => '<b>Bold</b> "quoted"',
				'phone'           => '</a><img src=x onerror=alert(1)>',
				'phoneLink'       => 'tel:5550100100',
				'email'           => "Joe's mail",
				'hours'           => '</p></div><img src=x>',
			]
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( self::street( '&lt;script&gt;alert(1)&lt;/script&gt;' ), $html );
		$this->assertStringContainsString( self::locality( '&lt;b&gt;Bold&lt;/b&gt; &quot;quoted&quot;' ), $html );
		$this->assertStringContainsString( self::plain( 'email', 'Joe&#039;s mail' ), $html );
		$this->assertSame( 1, substr_count( $html, '</a>' ) );
		$this->assertSame( 1, substr_count( $html, '</p>' ) );
		$this->assertSame( 1, substr_count( $html, '</div>' ) );
	}

	/**
	 * The same stored details, as each plugin's voyager/site-data hands them over.
	 *
	 * This plugin returns a value raw. voyager-blocks returns it through
	 * esc_html(), so its ampersands and quotes arrive as entities.
	 */
	public function test_a_value_prints_the_same_whether_it_arrives_raw_or_escaped(): void {
		$GLOBALS['voyager_test_options'] = [
			'voyager_site_phone'   => '+1 (555) 010-0123',
			'voyager_site_email'   => "o'neil&co@example.com",
			'voyager_site_address' => "Smith & Sons' Yard, Unit <4>",
			'voyager_site_hours'   => 'Mon & Tue, "by appointment"',
		];

		$raw = [];
		foreach ( self::KEYS as $attribute => $key ) {
			$value = SiteData\get_binding_value( [ 'key' => $key ], null, $attribute );
			if ( null !== $value ) {
				$raw[ $attribute ] = $value;
			}
		}
		$escaped = array_map( 'esc_html', $raw );

		$this->assertSame( "Smith & Sons' Yard, Unit <4>", $raw['address'] );
		$this->assertSame( 'Smith &amp; Sons&#039; Yard, Unit &lt;4&gt;', $escaped['address'] );

		$expected = self::block(
			self::street( 'Smith &amp; Sons&#039; Yard, Unit &lt;4&gt;' )
				. self::link( 'phone', 'tel:+15550100123', '+1 (555) 010-0123' )
				. self::link( 'email', 'mailto:o&#039;neil&#038;co@example.com', 'o&#039;neil&amp;co@example.com' ),
			self::hours( 'Mon &amp; Tue, &quot;by appointment&quot;' )
		);

		$this->assertSame( $expected, $this->render( $raw ), 'raw, as this plugin returns it' );
		$this->assertSame( $expected, $this->render( $escaped ), 'through esc_html(), as voyager-blocks returns it' );
	}

	public function test_text_decodes_once_and_trims(): void {
		$this->assertSame( 'Heating & cooling', ContactDetails\text( '  Heating &amp; cooling ' ) );
		$this->assertSame( 'Heating &amp; cooling', ContactDetails\text( 'Heating &amp;amp; cooling' ) );
		$this->assertSame( '', ContactDetails\text( [ 'not', 'a', 'string' ] ) );
		$this->assertSame( '5', ContactDetails\text( 5 ) );
	}

	public function test_markup_is_empty_with_no_parts(): void {
		$this->assertSame( '', ContactDetails\markup( [], self::WRAPPER ) );
	}

	public function test_every_attribute_is_bindable(): void {
		$all = array_keys( self::KEYS );

		$this->assertSame( ContactDetails\ATTRIBUTES, $all );
		$this->assertSame( $all, apply_filters( 'block_bindings_supported_attributes_voyager-open/contact-details', [] ) );
		$this->assertSame( $all, ContactDetails\bindable_attributes( $all ) );
		$this->assertSame( array_merge( [ 'content' ], $all ), ContactDetails\bindable_attributes( [ 'content' ] ) );
	}

	public function test_block_json_declares_each_attribute_as_an_empty_string(): void {
		/** @var array{name: string, apiVersion: int, attributes: array<string, array<string, mixed>>, render: string} $json */
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/contact-details/block.json' ), true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( 'voyager-open/contact-details', $json['name'] );
		$this->assertSame( 3, $json['apiVersion'] );
		$this->assertSame( 'file:./render.php', $json['render'] );
		$this->assertSame( ContactDetails\ATTRIBUTES, array_keys( $json['attributes'] ) );
		foreach ( $json['attributes'] as $name => $schema ) {
			$this->assertSame( 'string', $schema['type'] ?? null, $name );
			$this->assertSame( '', $schema['default'] ?? null, $name );
		}
	}

	/**
	 * The block as parse_blocks() returns it, bound to the phone, with its own phone.
	 *
	 * @param string $phone The block's own `phone`.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function bound_to_phone( string $phone ): array {
		return [
			'blockName'    => 'voyager-open/contact-details',
			'attrs'        => [
				'phone'    => $phone,
				'metadata' => [
					'bindings' => [
						'phone' => [
							'source' => 'voyager/site-data',
							'args'   => [ 'key' => 'phone' ],
						],
					],
				],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	public function test_validate_warns_stale_fallback_on_its_own_phone(): void {
		$stale = Abilities\stale_fallbacks( [ self::bound_to_phone( '(555) 010-0199' ) ], [] );

		$this->assertCount( 1, $stale );
		$this->assertSame( 'phone', $stale[0]['attribute'] );
		$this->assertSame( 'phone', $stale[0]['looks_like'] );
		$this->assertFalse( $stale[0]['in_html'] );

		$warning = Abilities\stale_fallback_warning( $stale[0] );

		$this->assertStringStartsWith( 'stale_fallback: voyager-open/contact-details at 0, binding "phone" (voyager/site-data, key "phone")', $warning );
		$this->assertStringEndsWith( 'Remove it with configure, passing null for "phone".', $warning );
	}

	public function test_validate_warns_nothing_when_its_own_phone_is_empty_or_a_label(): void {
		$this->assertSame( [], Abilities\stale_fallbacks( [ self::bound_to_phone( '' ) ], [] ) );
		$this->assertSame( [], Abilities\stale_fallbacks( [ self::bound_to_phone( 'Call us' ) ], [] ) );
	}
}
