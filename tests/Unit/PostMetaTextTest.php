<?php
/**
 * voyager/post-meta-text: field registration, the allowlist and resolve().
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\PostMetaText;

final class PostMetaTextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']         = [];
		$GLOBALS['voyager_test_doing_it_wrong']  = [];
		$GLOBALS['voyager_test_registered_meta'] = [];
		$GLOBALS['voyager_test_post_meta']       = [];
		$GLOBALS['voyager_test_post_types']      = [ 10 => 'page' ];
	}

	/**
	 * Allow fields through the filter, as a site would.
	 *
	 * @param array<string, array<string, string>> $fields Post type => key => label.
	 */
	private function allow( array $fields ): void {
		add_filter(
			'voyager_post_meta_text_fields',
			static fn ( $current ) => array_replace_recursive( is_array( $current ) ? $current : [], $fields )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refused_keys(): array {
		return [
			'empty key'          => [ '' ],
			'leading underscore' => [ '_private' ],
			'dot'                => [ 'stats.value' ],
		];
	}

	#[DataProvider( 'refused_keys' )]
	public function test_register_field_refuses_an_unsafe_key( string $key ): void {
		$this->assertFalse( PostMetaText\register_field( 'page', $key, 'Label' ) );
		$this->assertSame( [ 'VoyagerOpenBlocks\PostMetaText\register_field' ], $GLOBALS['voyager_test_doing_it_wrong'] );
		$this->assertSame( [], $GLOBALS['voyager_test_registered_meta'] );
	}

	public function test_register_field_accepts_a_plain_key_and_allowlists_it(): void {
		$this->assertTrue( PostMetaText\register_field( 'page', 'eyebrow', 'Eyebrow' ) );
		$this->assertSame( [ [ 'page', 'eyebrow' ] ], $GLOBALS['voyager_test_registered_meta'] );
		$this->assertSame( 'Eyebrow', PostMetaText\allowed_fields()['page']['eyebrow'] ?? null );
	}

	public function test_allowed_fields_honours_the_filter(): void {
		$this->allow( [ 'page' => [ 'tagline_override' => 'Tagline override' ] ] );

		$this->assertSame( 'Tagline override', PostMetaText\allowed_fields()['page']['tagline_override'] ?? null );
		$this->assertSame( 'Tagline override', PostMetaText\fields_for( 'page' )['tagline_override'] ?? null );
	}

	public function test_allowed_fields_ignores_a_filter_that_returns_a_non_array(): void {
		add_filter( 'voyager_post_meta_text_fields', static fn () => 'not an array' );

		$this->assertSame( [], PostMetaText\allowed_fields() );
	}

	public function test_fields_for_merges_the_every_type_set_with_the_post_type(): void {
		$this->allow(
			[
				'*'    => [ 'shared' => 'Shared' ],
				'page' => [ 'own' => 'Own' ],
				'post' => [ 'other' => 'Other' ],
			]
		);

		$fields = PostMetaText\fields_for( 'page' );

		$this->assertArrayHasKey( 'shared', $fields );
		$this->assertArrayHasKey( 'own', $fields );
		$this->assertArrayNotHasKey( 'other', $fields );
	}

	public function test_resolve_returns_a_plain_key(): void {
		$this->allow( [ 'page' => [ 'eyebrow' => 'Eyebrow' ] ] );
		$GLOBALS['voyager_test_post_meta'][10]['eyebrow'] = 'Licensed and insured';

		$this->assertSame( 'Licensed and insured', PostMetaText\resolve( 10, 'eyebrow' ) );
	}

	public function test_resolve_refuses_a_key_that_is_not_allowlisted(): void {
		$GLOBALS['voyager_test_post_meta'][10]['secret'] = 'hidden';

		$this->assertNull( PostMetaText\resolve( 10, 'secret' ) );
	}

	public function test_resolve_returns_null_for_an_empty_value(): void {
		$this->allow( [ 'page' => [ 'eyebrow' => 'Eyebrow' ] ] );
		$GLOBALS['voyager_test_post_meta'][10]['eyebrow'] = '   ';

		$this->assertNull( PostMetaText\resolve( 10, 'eyebrow' ) );
	}

	public function test_resolve_follows_a_json_dot_path(): void {
		$this->allow( [ 'page' => [ 'stats' => 'Stats' ] ] );
		$GLOBALS['voyager_test_post_meta'][10]['stats'] = '[{"value":"42","label":"Years"},{"value":"7"}]';

		$this->assertSame( '42', PostMetaText\resolve( 10, 'stats.0.value' ) );
		$this->assertSame( '7', PostMetaText\resolve( 10, 'stats.1.value' ) );
	}

	public function test_resolve_returns_null_when_a_json_path_misses_or_is_not_a_scalar(): void {
		$this->allow( [ 'page' => [ 'stats' => 'Stats' ] ] );
		$GLOBALS['voyager_test_post_meta'][10]['stats'] = '[{"value":"42"}]';

		$this->assertNull( PostMetaText\resolve( 10, 'stats.2.value' ) );
		$this->assertNull( PostMetaText\resolve( 10, 'stats.0' ) );
	}
}
