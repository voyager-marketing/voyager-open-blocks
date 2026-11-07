<?php
/**
 * The parts of the validate ability that run without WordPress: where markup
 * stops round-tripping, how delimiters are normalized, and the binding checks
 * validate shares with bind.
 *
 * The rest (block types, attribute schemas, parse and serialize) needs a real
 * WordPress and is covered by scripts/e2e.mjs.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

final class ValidateTest extends TestCase {

	private const PARAGRAPH = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->";

	private const ICON = '<!-- wp:voyager-open/icon /-->';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']         = [];
		$GLOBALS['voyager_test_binding_sources'] = [];
	}

	public function test_markup_that_is_exactly_the_serialized_blocks_matches(): void {
		$this->assertNull( BlockTree\first_mismatch( self::PARAGRAPH . "\n\n" . self::ICON, [ self::PARAGRAPH, self::ICON ] ) );
	}

	public function test_whitespace_between_and_around_top_level_blocks_is_allowed(): void {
		$this->assertNull( BlockTree\first_mismatch( "\n  " . self::PARAGRAPH . "\n" . self::ICON . "\n\n\t", [ self::PARAGRAPH, self::ICON ] ) );
	}

	public function test_no_blocks_and_only_whitespace_matches(): void {
		$this->assertNull( BlockTree\first_mismatch( " \n", [] ) );
	}

	public function test_the_first_block_that_differs_is_reported(): void {
		$second = '<!-- wp:voyager-open/icon {"icon":"home"} /-->';

		$this->assertSame( 0, BlockTree\first_mismatch( '<!-- wp:paragraph -->x', [ self::PARAGRAPH ] ) );
		$this->assertSame( 1, BlockTree\first_mismatch( self::PARAGRAPH . "\n\n" . self::ICON, [ self::PARAGRAPH, $second ] ) );
	}

	public function test_blocks_out_of_order_do_not_match(): void {
		$this->assertSame( 0, BlockTree\first_mismatch( self::ICON . "\n\n" . self::PARAGRAPH, [ self::PARAGRAPH, self::ICON ] ) );
	}

	public function test_text_after_the_last_block_is_reported_past_the_end(): void {
		$this->assertSame( 1, BlockTree\first_mismatch( self::ICON . "\n<!-- /wp:group -->", [ self::ICON ] ) );
	}

	public function test_normalize_spells_names_and_spacing_as_serialize_block_does(): void {
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->",
			BlockTree\normalize_delimiters( "<!--   wp:core/paragraph   -->\n<p>One</p>\n<!--\t/wp:core/paragraph  -->" )
		);
		$this->assertSame( '<!-- wp:voyager-open/icon /-->', BlockTree\normalize_delimiters( '<!-- wp:voyager-open/icon  /-->' ) );
	}

	public function test_normalize_writes_an_empty_block_as_a_void_comment(): void {
		$this->assertSame( '<!-- wp:voyager-open/icon /-->', BlockTree\normalize_delimiters( '<!-- wp:voyager-open/icon --><!-- /wp:voyager-open/icon -->' ) );
		$this->assertSame( '<!-- wp:paragraph /-->', BlockTree\normalize_delimiters( '<!-- wp:paragraph {} --><!-- /wp:paragraph -->' ) );
	}

	public function test_normalize_leaves_a_delimiter_whose_json_does_not_decode(): void {
		$broken = "<!-- wp:paragraph {\"x\":} -->\n<p>One</p>\n<!-- /wp:paragraph -->";

		$this->assertSame( $broken, BlockTree\normalize_delimiters( $broken ) );
		$this->assertSame( 0, BlockTree\first_mismatch( BlockTree\normalize_delimiters( $broken ), [ self::PARAGRAPH ] ) );
	}

	public function test_check_binding_refuses_an_attribute_the_block_cannot_bind(): void {
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;

		$result = Catalog\check_binding( 'core/heading', 'url', SiteData\SOURCE, [ 'key' => 'phone' ], [ 'content' ], 'page' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_attribute', $result->get_error_code() );
	}

	public function test_check_binding_refuses_an_unregistered_source(): void {
		$result = Catalog\check_binding( 'core/paragraph', 'content', SiteData\SOURCE, [ 'key' => 'phone' ], [ 'content' ], 'page' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_source', $result->get_error_code() );
	}

	// SiteData\owned() is a static that nothing resets, so the pack-owned
	// cases run in their own process, as in CatalogTest.

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_binding_with_listed_args_has_no_problems(): void {
		SiteData\owned( true );
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;

		$this->assertSame(
			[
				'errors'   => [],
				'warnings' => [],
			],
			Catalog\binding_problems( 'core/paragraph', '0', $this->bindings( SiteData\SOURCE, [ 'key' => 'phone' ] ), [ 'content' ], 'page' )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invented_args_are_an_error_at_the_block_path(): void {
		SiteData\owned( true );
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;

		$found = Catalog\binding_problems( 'core/paragraph', '0/1', $this->bindings( SiteData\SOURCE, [ 'key' => 'telephone' ] ), [ 'content' ], 'page' );

		$this->assertCount( 1, $found['errors'] );
		$this->assertStringStartsWith( 'core/paragraph at 0/1, binding "content": ', $found['errors'][0] );
		$this->assertStringContainsString( 'telephone', $found['errors'][0] );
		$this->assertSame( [], $found['warnings'] );
	}

	public function test_an_unbindable_attribute_is_an_error(): void {
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;

		$found = Catalog\binding_problems( 'core/heading', '2', [ 'url' => [ 'source' => SiteData\SOURCE, 'args' => [ 'key' => 'phone_link' ] ] ], [ 'content' ], 'page' );

		$this->assertCount( 1, $found['errors'] );
		$this->assertStringContainsString( 'is not bindable on core/heading', $found['errors'][0] );
	}

	public function test_a_pack_source_nobody_registered_is_an_error(): void {
		$found = Catalog\binding_problems( 'core/paragraph', '0', $this->bindings( SiteData\SOURCE, [ 'key' => 'phone' ] ), [ 'content' ], 'page' );

		$this->assertCount( 1, $found['errors'] );
		$this->assertStringContainsString( 'is not registered on this site', $found['errors'][0] );
	}

	public function test_a_foreign_owner_that_lists_no_fields_is_a_warning(): void {
		// Registered, but not by this pack, and with no field list to check against.
		$GLOBALS['voyager_test_binding_sources'][ SiteData\SOURCE ] = true;

		$found = Catalog\binding_problems( 'core/paragraph', '0', $this->bindings( SiteData\SOURCE, [ 'key' => 'phone' ] ), [ 'content' ], 'page' );

		$this->assertSame( [], $found['errors'] );
		$this->assertCount( 1, $found['warnings'] );
		$this->assertStringContainsString( 'cannot be listed', $found['warnings'][0] );
	}

	public function test_another_registered_source_is_a_warning_and_an_unregistered_one_an_error(): void {
		$GLOBALS['voyager_test_binding_sources']['core/pattern-overrides'] = true;

		$found = Catalog\binding_problems(
			'core/paragraph',
			'0',
			[
				'__default' => [ 'source' => 'core/pattern-overrides' ],
				'content'   => [ 'source' => 'example/unknown' ],
			],
			[ 'content' ],
			'page'
		);

		$this->assertCount( 1, $found['warnings'] );
		$this->assertStringContainsString( 'core/pattern-overrides', $found['warnings'][0] );
		$this->assertCount( 1, $found['errors'] );
		$this->assertStringContainsString( 'example/unknown is not registered', $found['errors'][0] );
	}

	public function test_malformed_bindings_are_errors(): void {
		$this->assertCount( 1, Catalog\binding_problems( 'core/paragraph', '0', 'content', [ 'content' ], 'page' )['errors'] );

		$found = Catalog\binding_problems( 'core/paragraph', '0', [ 'content' => [ 'args' => [ 'key' => 'phone' ] ] ], [ 'content' ], 'page' );
		$this->assertCount( 1, $found['errors'] );
		$this->assertStringContainsString( 'names no source', $found['errors'][0] );
	}

	/**
	 * One content binding.
	 *
	 * @param string       $source Source name.
	 * @param array<mixed> $args   Args.
	 * @return array<string, array<string, mixed>>
	 */
	private function bindings( string $source, array $args ): array {
		return [
			'content' => [
				'source' => $source,
				'args'   => $args,
			],
		];
	}
}
