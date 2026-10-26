<?php
/**
 * Catalog\match_args(): the checks behind the reasons get-post-blocks reports.
 *
 * with_resolution() maps a fall back to `unknown_key` when match_args() returns
 * the invalid-args error, and to `empty_value` for everything else: a listed
 * key whose value is empty, or a source whose fields cannot be listed.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

final class CatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']         = [];
		$GLOBALS['voyager_test_binding_sources'] = [];
	}

	private function code( mixed $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );

		return $result->get_error_code();
	}

	// The two pack-owned tests run in their own process: SiteData\owned() is a
	// static that nothing resets.

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_key_the_source_lists_matches(): void {
		SiteData\owned( true );

		$this->assertTrue( Catalog\match_args( SiteData\SOURCE, 'page', [ 'key' => 'phone' ] ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_key_the_source_does_not_list_is_unknown_key(): void {
		SiteData\owned( true );

		$this->assertSame(
			'voyager_open_blocks_invalid_args',
			$this->code( Catalog\match_args( SiteData\SOURCE, 'page', [ 'key' => 'phne' ] ) )
		);
	}

	public function test_an_unknown_source_cannot_be_checked(): void {
		$this->assertSame(
			'voyager_open_blocks_invalid_source',
			$this->code( Catalog\match_args( 'example/unknown', 'page', [ 'key' => 'phone' ] ) )
		);
	}

	public function test_a_foreign_owner_that_lists_no_fields_cannot_be_checked(): void {
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;

		$this->assertNull( Catalog\fields( SiteData\SOURCE, 'page' ) );
		$this->assertSame(
			'voyager_open_blocks_invalid_source',
			$this->code( Catalog\match_args( SiteData\SOURCE, 'page', [ 'key' => 'phone' ] ) )
		);
	}

	public function test_a_foreign_owner_decides_which_keys_are_listed(): void {
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;
		add_filter(
			'voyager_binding_sources',
			static fn ( array $sources ): array => array_merge(
				$sources,
				[
					[
						'name'   => SiteData\SOURCE,
						'fields' => [
							'owner_key' => [ 'label' => 'Owner key' ],
						],
					],
				]
			)
		);

		$this->assertTrue( Catalog\match_args( SiteData\SOURCE, 'page', [ 'key' => 'owner_key' ] ) );
		// The pack's own `company` key is not in the owner's list.
		$this->assertSame(
			'voyager_open_blocks_invalid_args',
			$this->code( Catalog\match_args( SiteData\SOURCE, 'page', [ 'key' => 'company' ] ) )
		);
	}

	public function test_a_foreign_owner_field_restricted_to_another_post_type_is_left_out(): void {
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;
		add_filter(
			'voyager_binding_sources',
			static fn ( array $sources ): array => array_merge(
				$sources,
				[
					[
						'name'   => SiteData\SOURCE,
						'fields' => [
							'page_only' => [
								'label'      => 'Page only',
								'post_types' => [ 'page' ],
							],
						],
					],
				]
			)
		);

		$this->assertTrue( Catalog\match_args( SiteData\SOURCE, 'page', [ 'key' => 'page_only' ] ) );
		$this->assertSame(
			'voyager_open_blocks_invalid_args',
			$this->code( Catalog\match_args( SiteData\SOURCE, 'post', [ 'key' => 'page_only' ] ) )
		);
	}
}
