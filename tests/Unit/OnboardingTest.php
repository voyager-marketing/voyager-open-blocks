<?php
/**
 * When the first-run notice shows.
 *
 * Only on the Plugins screen and the Dashboard, only for users who can manage
 * options, only while no Site Data field has ever been set, never after the
 * user dismissed it, and never when no plugin registered the settings page.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Onboarding;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__, 2 ) . '/inc/onboarding.php';

final class OnboardingTest extends TestCase {

	private const URL = 'https://example.com/wp-admin/options-general.php?page=voyager-site-data';

	public function test_option_names_are_the_stored_site_data_options(): void {
		$expected = [];
		foreach ( SiteData\fields() as $field ) {
			if ( isset( $field['option'] ) ) {
				$expected[] = $field['option'];
			}
		}

		$this->assertSame( $expected, Onboarding\option_names() );
		$this->assertCount( 20, Onboarding\option_names() );

		foreach ( Onboarding\option_names() as $name ) {
			$this->assertStringStartsWith( 'voyager_site_', $name );
		}
	}

	public function test_blank_values_are_not_filled(): void {
		$this->assertFalse( Onboarding\any_filled( [] ) );
		$this->assertFalse( Onboarding\any_filled( [ 'voyager_site_phone' => '', 'voyager_site_email' => "  \t" ] ) );
		$this->assertFalse( Onboarding\any_filled( [ null, false, [ 'not', 'scalar' ] ] ) );
	}

	public function test_one_value_is_filled(): void {
		$this->assertTrue( Onboarding\any_filled( [ 'voyager_site_phone' => '', 'voyager_site_hours' => 'Monday to Friday' ] ) );
	}

	public function test_started_by_the_flag_or_by_a_value(): void {
		$empty = array_fill_keys( Onboarding\option_names(), '' );

		$this->assertFalse( Onboarding\is_started( '', $empty ) );
		$this->assertFalse( Onboarding\is_started( false, $empty ) );
		$this->assertTrue( Onboarding\is_started( '1', $empty ), 'The flag keeps the notice off after the fields are cleared.' );
		$this->assertTrue( Onboarding\is_started( '', array_merge( $empty, [ 'voyager_site_email' => 'hello@example.com' ] ) ) );
	}

	public function test_a_value_written_to_a_site_data_option_starts_site_data(): void {
		$this->assertTrue( Onboarding\starts( 'voyager_site_phone', '(555) 555-0100' ) );
		$this->assertTrue( Onboarding\starts( 'voyager_site_social_x', 'https://example.com/' ) );
	}

	public function test_other_writes_do_not_start_site_data(): void {
		$this->assertFalse( Onboarding\starts( 'voyager_site_phone', '   ' ), 'A blank value' );
		$this->assertFalse( Onboarding\starts( 'blogname', 'Example' ), 'Another option' );
		$this->assertFalse( Onboarding\starts( 'voyager_site_unknown', 'x' ), 'An option Site Data does not store' );
		$this->assertFalse( Onboarding\starts( Onboarding\STARTED, '1' ), 'The flag itself' );
	}

	/**
	 * @return array<string, array{string, bool, bool, bool, string, bool}>
	 */
	public static function cases(): array {
		return [
			'plugins, admin, fresh site'        => [ 'plugins', true, false, false, self::URL, true ],
			'dashboard, admin, fresh site'      => [ 'dashboard', true, false, false, self::URL, true ],
			'another screen'                    => [ 'edit-post', true, false, false, self::URL, false ],
			'the settings page itself'          => [ 'settings_page_voyager-site-data', true, false, false, self::URL, false ],
			'network plugins screen'            => [ 'plugins-network', true, false, false, self::URL, false ],
			'network dashboard'                 => [ 'dashboard-network', true, false, false, self::URL, false ],
			'no screen'                         => [ '', true, false, false, self::URL, false ],
			'user cannot manage options'        => [ 'plugins', false, false, false, self::URL, false ],
			'user dismissed it on this site'    => [ 'dashboard', true, true, false, self::URL, false ],
			'a field is or was set'             => [ 'plugins', true, false, true, self::URL, false ],
			'no plugin registered the page'     => [ 'plugins', true, false, false, '', false ],
		];
	}

	#[DataProvider( 'cases' )]
	public function test_should_show( string $screen, bool $can_manage, bool $dismissed, bool $started, string $url, bool $expected ): void {
		$this->assertSame( $expected, Onboarding\should_show( $screen, $can_manage, $dismissed, $started, $url ) );
	}
}
