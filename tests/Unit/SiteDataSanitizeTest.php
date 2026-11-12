<?php
/**
 * The business profile keys: how they resolve past the shared fixture, and
 * how Settings > Site Data and /wp/v2/settings sanitize what is saved.
 *
 * The stubs for sanitize_text_field() and sanitize_textarea_field() follow
 * core's _sanitize_text_fields(): the first joins lines into one, the second
 * keeps them. So a list routed through the text sanitizer fails here.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\SiteData;

final class SiteDataSanitizeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']         = [];
		$GLOBALS['voyager_test_settings']        = [];
		$GLOBALS['voyager_test_settings_errors'] = [];
	}

	public function test_a_list_keeps_its_lines(): void {
		$saved = SiteData\sanitize( 'services', "  Drain cleaning\r\n\r\n<b>Leak</b> detection  " );

		$this->assertSame( "Drain cleaning\r\n\r\nLeak detection", $saved );
		$this->assertSame( "Drain cleaning\nLeak detection", SiteData\resolve_list( $saved ) );
	}

	public function test_the_text_sanitizer_would_join_a_list_into_one_line(): void {
		// The control for the test above: what the lists were saved with before.
		$this->assertSame( 'Drain cleaning Leak detection', sanitize_text_field( "Drain cleaning\r\nLeak detection" ) );
	}

	public function test_every_list_is_a_textarea_and_keeps_its_lines(): void {
		foreach ( [ 'services', 'service_area', 'credentials' ] as $key ) {
			$this->assertSame( 'textarea', SiteData\control( $key ), $key );
			$this->assertSame( "One\nTwo", SiteData\sanitize( $key, "One\nTwo" ), $key );
		}
	}

	/**
	 * Each stored option registers with show_in_rest, so /wp/v2/settings sets it,
	 * and through the same callback the Settings form uses.
	 */
	public function test_every_business_profile_option_is_in_rest_with_its_sanitizer(): void {
		SiteData\register_options();

		foreach ( SiteData\BUSINESS_PROFILE as $key ) {
			$option = 'voyager_site_' . $key;
			$this->assertArrayHasKey( $option, $GLOBALS['voyager_test_settings'], $option );

			$args = $GLOBALS['voyager_test_settings'][ $option ];
			$this->assertSame( 'voyager_site_data', $args['group'] );
			$this->assertSame( 'string', $args['type'] );
			$this->assertSame( '', $args['default'] );
			$this->assertTrue( $args['show_in_rest'] );
			$this->assertIsCallable( $args['sanitize_callback'] );
		}

		$list = $GLOBALS['voyager_test_settings']['voyager_site_service_area']['sanitize_callback'];
		$this->assertSame( "Springfield\nChatham", $list( "Springfield\nChatham" ) );

		$year = $GLOBALS['voyager_test_settings']['voyager_site_founded']['sanitize_callback'];
		$this->assertSame( '1998', $year( ' 1998 ' ) );
	}

	public function test_the_existing_options_keep_their_sanitizers(): void {
		SiteData\register_options();
		$sanitize = static fn ( string $option, string $value ): mixed => $GLOBALS['voyager_test_settings'][ $option ]['sanitize_callback']( $value );

		$this->assertSame( '100 Main Street', $sanitize( 'voyager_site_address', "  100 Main\nStreet  " ) );
		$this->assertSame( 'hello@example.com', $sanitize( 'voyager_site_email', ' hello@example.com ' ) );
		$this->assertSame( '', $sanitize( 'voyager_site_social_x', 'javascript:alert(1)' ) );
		// A trailing space, as a REST client may send it, would become %20 in esc_url_raw().
		$this->assertSame( 'https://x.com/example', $sanitize( 'voyager_site_social_x', ' https://x.com/example ' ) );
		$this->assertCount( 20, $GLOBALS['voyager_test_settings'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function years(): array {
		return [
			'four digits'         => [ '1998', '1998' ],
			'spaces around'       => [ '  1998  ', '1998' ],
			'empty'               => [ '', '' ],
			'only spaces'         => [ '   ', '' ],
			'two digits'          => [ '98', '' ],
			'five digits'         => [ '19985', '' ],
			'a range'             => [ '1998-2001', '' ],
			'words'               => [ 'since 1998', '' ],
			'full-width digits'   => [ '１９９８', '' ],
			'a newline inside'    => [ "19\n98", '' ],
		];
	}

	#[DataProvider( 'years' )]
	public function test_year( string $value, string $expected ): void {
		$this->assertSame( $expected, SiteData\resolve_year( $value ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function ratings(): array {
		return [
			'one decimal'         => [ '4.8', '4.8' ],
			'two decimals'        => [ '4.80', '4.8' ],
			'rounded'             => [ '4.86', '4.9' ],
			'whole'               => [ '5', '5.0' ],
			'zero'                => [ '0', '0.0' ],
			'minus zero'          => [ '-0', '0.0' ],
			'spaces around'       => [ ' 4.8 ', '4.8' ],
			'empty'               => [ '', '' ],
			'over five'           => [ '5.1', '' ],
			'negative'            => [ '-1', '' ],
			'decimal comma'       => [ '4,8', '' ],
			'out of five'         => [ '4.8/5', '' ],
			'stars'               => [ '4.8 stars', '' ],
			'a word'              => [ 'great', '' ],
		];
	}

	#[DataProvider( 'ratings' )]
	public function test_rating( string $value, string $expected ): void {
		$this->assertSame( $expected, SiteData\resolve_rating( $value ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function counts(): array {
		return [
			'digits'              => [ '127', '127' ],
			'leading zero'        => [ '0127', '127' ],
			'zero'                => [ '0', '0' ],
			'spaces around'       => [ ' 127 ', '127' ],
			'empty'               => [ '', '' ],
			'thousands comma'     => [ '1,234', '' ],
			'a decimal'           => [ '12.5', '' ],
			'negative'            => [ '-3', '' ],
			'a plus'              => [ '+3', '' ],
			'words'               => [ '127 reviews', '' ],
			'an exponent'         => [ '1e3', '' ],
		];
	}

	#[DataProvider( 'counts' )]
	public function test_count( string $value, string $expected ): void {
		$this->assertSame( $expected, SiteData\resolve_count( $value ) );
	}

	public function test_a_list_resolves_to_its_trimmed_lines(): void {
		$this->assertSame( "a\nb\nc", SiteData\resolve_list( "  a  \r\n\n  b\r  \t \nc" ) );
		$this->assertSame( '', SiteData\resolve_list( " \n \r\n " ) );
		$this->assertSame( 'one', SiteData\resolve_list( 'one' ) );
	}

	public function test_a_year_rating_or_count_saves_in_its_resolved_form(): void {
		$this->assertSame( '1998', SiteData\sanitize( 'founded', ' 1998 ' ) );
		$this->assertSame( '4.8', SiteData\sanitize( 'rating', '4.80' ) );
		$this->assertSame( '127', SiteData\sanitize( 'review_count', '0127' ) );
		$this->assertSame( [], $GLOBALS['voyager_test_settings_errors'] );
	}

	public function test_an_empty_year_rating_or_count_clears_it(): void {
		$GLOBALS['voyager_test_options'] = [
			'voyager_site_founded'      => '1998',
			'voyager_site_rating'       => '4.8',
			'voyager_site_review_count' => '127',
		];

		$this->assertSame( '', SiteData\sanitize( 'founded', '  ' ) );
		$this->assertSame( '', SiteData\sanitize( 'rating', '' ) );
		$this->assertSame( '', SiteData\sanitize( 'review_count', '' ) );
		$this->assertSame( [], $GLOBALS['voyager_test_settings_errors'] );
	}

	/**
	 * As core's sanitize_option() refuses a bad value: the previous one stays, with an error for the Settings screen.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function refused(): array {
		return [
			'year'   => [ 'founded', 'since 1998', '1990' ],
			'rating' => [ 'rating', '4.8/5', '4.5' ],
			'count'  => [ 'review_count', '1,234', '900' ],
		];
	}

	#[DataProvider( 'refused' )]
	public function test_a_bad_value_keeps_the_previous_one( string $key, string $value, string $previous ): void {
		$option                                      = 'voyager_site_' . $key;
		$GLOBALS['voyager_test_options'][ $option ] = $previous;

		$this->assertSame( $previous, SiteData\sanitize( $key, $value ) );
		$this->assertCount( 1, $GLOBALS['voyager_test_settings_errors'] );
		$this->assertSame( $option, $GLOBALS['voyager_test_settings_errors'][0]['setting'] );
		$this->assertSame( "invalid_{$option}", $GLOBALS['voyager_test_settings_errors'][0]['code'] );
		$this->assertSame( 'error', $GLOBALS['voyager_test_settings_errors'][0]['type'] );
		$this->assertStringContainsString( 'previous value was kept', $GLOBALS['voyager_test_settings_errors'][0]['message'] );
	}

	public function test_a_bad_value_with_nothing_stored_saves_nothing(): void {
		$this->assertSame( '', SiteData\sanitize( 'rating', 'great' ) );
		$this->assertCount( 1, $GLOBALS['voyager_test_settings_errors'] );
	}

	public function test_the_call_to_action_link_is_a_url(): void {
		$this->assertSame( 'url', SiteData\control( 'cta_url' ) );
		$this->assertSame( 'https://example.com/book%20now', SiteData\sanitize( 'cta_url', ' https://example.com/book now ' ) );
		$this->assertSame( 'tel:5550100', SiteData\sanitize( 'cta_url', 'tel:5550100' ) );
		$this->assertSame( '', SiteData\sanitize( 'cta_url', 'javascript:alert(1)' ) );
	}

	public function test_text_keys_are_one_plain_line(): void {
		$this->assertSame( 'PL-000123', SiteData\sanitize( 'license', "  <b>PL-000123</b>\n" ) );
		$this->assertSame( 'Book a visit', SiteData\sanitize( 'cta_label', "Book a\nvisit" ) );
		$this->assertSame( 'text', SiteData\control( 'license' ) );
		$this->assertSame( 'email', SiteData\control( 'email' ) );
	}

	public function test_business_profile_lists_every_new_stored_key_in_fields_order(): void {
		$fields = SiteData\fields();
		$stored = array_keys( array_filter( $fields, static fn ( array $field ): bool => isset( $field['option'] ) ) );

		$this->assertSame( SiteData\BUSINESS_PROFILE, array_slice( $stored, -9 ) );
		foreach ( SiteData\BUSINESS_PROFILE as $key ) {
			$this->assertSame( 'voyager_site_' . $key, $fields[ $key ]['option'] ?? null );
			$this->assertNotSame( '', SiteData\help( $key ), $key );
		}
	}
}
