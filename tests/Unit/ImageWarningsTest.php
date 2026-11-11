<?php
/**
 * validate's placeholder and missing_alt warnings (inc/abilities/validate.php).
 *
 * Parsing markup needs WordPress, so these tests build blocks the way
 * parse_blocks() returns them, the pattern sections from their intents, which
 * is what patterns/<name>.html is built from. tests/validate-stubs.php stands
 * in for core's WP_HTML_Tag_Processor. scripts/e2e.mjs runs validate over
 * REST on the patterns as insert saves them, with the real class.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BindingsEditor;
use VoyagerOpenBlocks\Patterns;

require_once dirname( __DIR__ ) . '/bindings-editor-stubs.php';
require_once dirname( __DIR__ ) . '/validate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/bindings-editor.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class ImageWarningsTest extends TestCase {

	/**
	 * A pattern's blocks as parse_blocks() returns them, built from its intent.
	 *
	 * An image's HTML is what core/image saves for its url and alt. Every
	 * other block keeps its attributes and inner blocks, and no HTML.
	 *
	 * @param string                    $name    Pattern name, such as logo-marquee.
	 * @param callable(string): string $url_for Rewrites each image URL, such as the token swap inc/patterns.php makes.
	 * @return array<int, array<mixed>>
	 */
	private static function pattern( string $name, ?callable $url_for = null ): array {
		$json = file_get_contents( dirname( __DIR__, 2 ) . "/patterns/intents/{$name}.json" );
		self::assertIsString( $json );

		$intent = json_decode( $json, true );
		self::assertIsArray( $intent );
		self::assertIsArray( $intent['intent']['blocks'] ?? null );

		/** @var list<array<string, mixed>> $nodes */
		$nodes = $intent['intent']['blocks'];

		return array_map( static fn ( array $node ): array => self::from_intent( $node, $url_for ?? static fn ( string $url ): string => $url ), $nodes );
	}

	/**
	 * @param array<string, mixed>     $node    Intent node.
	 * @param callable(string): string $url_for Rewrites an image URL.
	 * @return array<mixed>
	 */
	private static function from_intent( array $node, callable $url_for ): array {
		/** @var list<array<string, mixed>> $children */
		$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
		$inner    = array_map( static fn ( array $child ): array => self::from_intent( $child, $url_for ), $children );
		$name     = (string) $node['block'];
		$html     = 'core/image' === $name
			? self::image_html( $url_for( (string) $node['url'] ), (string) $node['alt'] )
			: '';

		return [
			'blockName'    => $name,
			'attrs'        => is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [],
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => array_merge( [ $html ], array_fill( 0, count( $inner ), null ) ),
		];
	}

	private static function image_html( string $url, ?string $alt ): string {
		$alt = null === $alt ? '' : sprintf( ' alt="%s"', htmlspecialchars( $alt, ENT_QUOTES ) );

		return sprintf( "\n<figure class=\"wp-block-image\"><img src=\"%s\"%s/></figure>\n", htmlspecialchars( $url, ENT_QUOTES ), $alt );
	}

	/**
	 * A block as parse_blocks() returns it.
	 *
	 * @param string               $name  Block name.
	 * @param string               $html  Its own saved HTML.
	 * @param array<string, mixed> $attrs Comment attributes.
	 * @param array<array<mixed>>  $inner Inner blocks.
	 * @return array<mixed>
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
	 * A core/image block.
	 *
	 * @param string               $url   Its src.
	 * @param string|null          $alt   Its alt, null to leave the attribute out.
	 * @param array<string, mixed> $attrs Comment attributes.
	 * @return array<mixed>
	 */
	private static function image( string $url, ?string $alt, array $attrs = [] ): array {
		return self::block( 'core/image', self::image_html( $url, $alt ), $attrs );
	}

	/**
	 * Warnings for blocks, against the placeholders the pack ships.
	 *
	 * @param array<array<mixed>> $blocks Blocks.
	 * @return list<array{path: string, warning: string}>
	 */
	private static function warnings( array $blocks ): array {
		/** @var array<int, array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks */
		return Abilities\image_warnings( $blocks, Abilities\placeholder_names() );
	}

	/**
	 * @param list<array{path: string, warning: string}> $found From warnings().
	 * @param string                                     $code  placeholder or missing_alt.
	 * @return list<array{path: string, warning: string}>
	 */
	private static function coded( array $found, string $code ): array {
		return array_values( array_filter( $found, static fn ( array $item ): bool => str_starts_with( $item['warning'], $code . ': ' ) ) );
	}

	public function test_the_placeholder_names_are_the_files_the_pack_ships(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/assets/placeholders/*.svg' ) ?: [];
		sort( $files );

		$this->assertCount( 6, $files );
		$this->assertSame(
			array_map( static fn ( string $file ): string => 'placeholders/' . basename( $file ), $files ),
			Abilities\placeholder_names()
		);
	}

	public function test_the_logo_marquee_as_shipped_gets_one_placeholder_warning_per_logo_and_no_missing_alt(): void {
		$found = self::warnings( self::pattern( 'logo-marquee' ) );

		$this->assertCount( 6, $found );
		$this->assertCount( 6, self::coded( $found, 'placeholder' ) );
		$this->assertSame( [], self::coded( $found, 'missing_alt' ) );
		$this->assertSame( [ '0/1/0/0', '0/1/0/1', '0/1/0/2', '0/1/0/3', '0/1/0/4', '0/1/0/5' ], array_column( $found, 'path' ) );
		$this->assertSame(
			'placeholder: core/image at 0/1/0/0 shows the pack\'s placeholder image placeholders/logo-1.svg. Replace it in the editor with the site\'s own image, with alt text, or remove the block.',
			$found[0]['warning']
		);
	}

	public function test_the_intent_holds_the_logos_the_shipped_markup_does(): void {
		$html = file_get_contents( dirname( __DIR__, 2 ) . '/patterns/logo-marquee.html' );
		$this->assertIsString( $html );

		foreach ( Abilities\placeholder_names() as $name ) {
			$this->assertStringContainsString( sprintf( '<img src="%s%s" alt=""', Patterns\ASSETS_TOKEN, $name ), $html );
		}
	}

	public function test_the_logo_marquee_as_insert_saves_it_gets_the_same_warnings(): void {
		$assets = plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE );
		$found  = self::warnings( self::pattern( 'logo-marquee', static fn ( string $url ): string => str_replace( Patterns\ASSETS_TOKEN, $assets, $url ) ) );

		$this->assertCount( 6, self::coded( $found, 'placeholder' ) );
		$this->assertCount( 6, $found );
	}

	public function test_a_placeholder_is_matched_by_the_end_of_its_path_on_any_host(): void {
		$copies = [
			'https://cdn.example.org/wp-content/plugins/renamed-folder/assets/placeholders/logo-3.svg?ver=0.7.0',
			'//example.net/assets/placeholders/logo-4.svg',
			'/voyager-open-blocks-assets/placeholders/logo-5.svg',
		];

		foreach ( $copies as $url ) {
			$found = self::warnings( [ self::image( $url, '' ) ] );
			$this->assertCount( 1, self::coded( $found, 'placeholder' ), $url );
			$this->assertCount( 1, $found, $url );
		}
	}

	public function test_an_image_that_is_not_a_shipped_placeholder_is_checked_for_alt_text(): void {
		$others = [
			'https://example.com/wp-content/plugins/voyager-open-blocks/assets/placeholders/logo-99.svg',
			'https://example.com/wp-content/uploads/placeholders/logo-1.svg',
			'https://example.com/assets/placeholder/logo-1.svg',
			'https://example.com/assets/placeholders/logo-1.svg/',
			'https://example.com/logo-1.svg',
		];

		foreach ( $others as $url ) {
			$found = self::warnings( [ self::image( $url, '' ) ] );
			$this->assertSame( [], self::coded( $found, 'placeholder' ), $url );
			$this->assertCount( 1, self::coded( $found, 'missing_alt' ), $url );
		}
	}

	public function test_the_contact_section_as_shipped_gets_one_placeholder_warning_for_the_form_slot(): void {
		$found = self::warnings( self::pattern( 'contact-section' ) );

		$this->assertCount( 1, $found );
		$this->assertSame( '0/0/1/0', $found[0]['path'] );
		$this->assertSame(
			'placeholder: core/group "Form" at 0/0/1/0 is the stand-in for a form that the contact section ships, still in its "Form slot" column. Replace it with a form plugin\'s block in the editor, so visitors get a form.',
			$found[0]['warning']
		);
	}

	public function test_the_form_slot_is_filled_once_its_stand_in_is_replaced_or_moved(): void {
		$slot     = static fn ( array $inner ): array => self::block( 'core/column', '', [ 'metadata' => [ 'name' => BindingsEditor\FORM_SLOT['slot'] ] ], $inner );
		$stand_in = self::block( 'core/group', '', [ 'metadata' => [ 'name' => BindingsEditor\FORM_SLOT['fallback'] ] ] );
		$columns  = static fn ( array $inner ): array => self::block( 'core/columns', '', [], $inner );

		$this->assertCount( 1, self::warnings( [ $columns( [ $slot( [ $stand_in ] ) ] ) ] ) );
		$this->assertSame( [], self::warnings( [ $columns( [ $slot( [ self::block( 'example/contact-form' ) ] ) ] ) ] ) );
		$this->assertSame( [], self::warnings( [ $columns( [ $slot( [ self::block( 'core/group', '', [ 'metadata' => [ 'name' => 'Contact form' ] ], [ self::block( 'example/contact-form' ) ] ) ] ) ] ) ] ) );
		$this->assertSame( [], self::warnings( [ $columns( [ $slot( [ self::block( 'example/contact-form', '', [ 'metadata' => [ 'name' => BindingsEditor\FORM_SLOT['fallback'] ] ] ) ] ) ] ) ] ) );
		$this->assertSame( [], self::warnings( [ $columns( [ self::block( 'core/column', '', [ 'metadata' => [ 'name' => 'Contact details' ] ], [ $stand_in ] ) ] ) ] ) );
		$this->assertSame( [], self::warnings( [ self::block( 'core/group', '', [ 'metadata' => [ 'name' => BindingsEditor\FORM_SLOT['slot'] ] ], [ $stand_in ] ) ] ) );
		$this->assertSame( [], self::warnings( [ $stand_in ] ) );
	}

	public function test_an_image_with_empty_alt_outside_the_placeholders_gets_one_missing_alt_warning(): void {
		$found = self::warnings( [ self::image( 'https://example.com/wp-content/uploads/team.jpg', '' ) ] );

		$this->assertSame(
			[
				[
					'path'    => '0',
					'warning' => 'missing_alt: core/image at 0 has no alt text, so a screen reader cannot say what it shows. Add alt text in the editor that names what the image shows, such as the company\'s name for a logo. Leave it empty only for an image that is decoration.',
				],
			],
			$found
		);
	}

	public function test_an_image_with_alt_text_gets_none(): void {
		$this->assertSame( [], self::warnings( [ self::image( 'https://example.com/wp-content/uploads/team.jpg', 'Our team outside the clinic' ) ] ) );
		$this->assertSame( [], self::warnings( [ self::image( 'https://example.com/wp-content/uploads/logo.svg', 'Example &amp; Sons' ) ] ) );
	}

	public function test_missing_or_blank_alt_is_missing_alt(): void {
		$this->assertCount( 1, self::coded( self::warnings( [ self::image( 'https://example.com/a.jpg', null ) ] ), 'missing_alt' ) );
		$this->assertCount( 1, self::coded( self::warnings( [ self::image( 'https://example.com/a.jpg', '   ' ) ] ), 'missing_alt' ) );
		$this->assertCount( 1, self::coded( self::warnings( [ self::block( 'core/image', '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt/></figure>' ) ] ), 'missing_alt' ) );
	}

	public function test_an_image_with_no_url_or_bound_alt_text_is_not_checked(): void {
		$this->assertSame( [], self::warnings( [ self::block( 'core/image', '<figure class="wp-block-image"><img alt=""/></figure>' ) ] ) );

		$bound = self::image( 'https://example.com/a.jpg', '', [ 'metadata' => [ 'bindings' => [ 'alt' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'photo_alt' ] ] ] ] ] );
		$this->assertSame( [], self::warnings( [ $bound ] ) );

		// A synced pattern's override keeps the saved alt as every copy's default.
		$override = self::image( 'https://example.com/a.jpg', '', [ 'metadata' => [ 'bindings' => [ '__default' => [ 'source' => 'core/pattern-overrides' ] ] ] ] );
		$this->assertCount( 1, self::coded( self::warnings( [ $override ] ), 'missing_alt' ) );
	}

	public function test_media_and_text_is_checked_and_cover_is_not(): void {
		$media = static fn ( string $figure, array $attrs = [] ): array => self::block( 'core/media-text', "\n<div class=\"wp-block-media-text\"><figure class=\"wp-block-media-text__media\">{$figure}</figure><div class=\"wp-block-media-text__content\"></div></div>\n", $attrs, [ self::block( 'core/paragraph', "\n<p>Text</p>\n" ) ] );

		$found = self::warnings( [ $media( '<img src="https://example.com/wp-content/uploads/van.jpg" alt=""/>' ) ] );
		$this->assertCount( 1, self::coded( $found, 'missing_alt' ) );
		$this->assertStringStartsWith( 'missing_alt: core/media-text at 0 ', $found[0]['warning'] );

		$this->assertSame( [], self::warnings( [ $media( '<img src="https://example.com/wp-content/uploads/van.jpg" alt="Our van"/>' ) ] ) );
		$this->assertSame( [], self::warnings( [ $media( '<video controls src="https://example.com/tour.mp4"></video>' ) ] ) );
		// The featured image replaces whatever image the block saved, with its own alt text.
		$this->assertSame( [], self::warnings( [ $media( '<img src="https://example.com/wp-content/uploads/old.jpg" alt=""/>', [ 'useFeaturedImage' => true ] ) ] ) );
		$this->assertCount( 1, self::coded( self::warnings( [ $media( '<img src="https://example.com/x/assets/placeholders/logo-2.svg" alt=""/>' ) ] ), 'placeholder' ) );

		$cover = self::block( 'core/cover', "\n<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background\" alt=\"\" src=\"https://example.com/wp-content/uploads/hero.jpg\" data-object-fit=\"cover\"/><div class=\"wp-block-cover__inner-container\"></div></div>\n" );
		$this->assertSame( [], self::warnings( [ $cover ] ) );
	}

	public function test_an_image_inside_a_media_and_text_block_is_checked_on_its_own(): void {
		$media = self::block(
			'core/media-text',
			"\n<div class=\"wp-block-media-text\"><figure class=\"wp-block-media-text__media\"><img src=\"https://example.com/a.jpg\" alt=\"A van\"/></figure><div class=\"wp-block-media-text__content\"></div></div>\n",
			[],
			[ self::image( 'https://example.com/b.jpg', '' ) ]
		);

		$this->assertSame( [ '0/0' ], array_column( self::warnings( [ $media ] ), 'path' ) );
	}

	/**
	 * A report with one valid block or an invalid one, and a group around it.
	 *
	 * @param bool $valid Whether the report is valid.
	 * @return array{valid: bool, post_type: string, round_trip: bool, blocks: array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>, errors: string[], warnings: string[], markup: string}
	 */
	private static function report( bool $valid ): array {
		$error = $valid ? [] : [ 'core/image at 0/0: an error.' ];

		return [
			'valid'      => $valid,
			'post_type'  => 'page',
			'round_trip' => true,
			'blocks'     => [
				[
					'path'     => '0',
					'block'    => 'core/group',
					'errors'   => [],
					'warnings' => [ 'core/group at 0: an earlier warning.' ],
				],
				[
					'path'     => '0/0',
					'block'    => 'core/image',
					'errors'   => $error,
					'warnings' => [],
				],
			],
			'errors'     => $error,
			'warnings'   => [ 'core/group at 0: an earlier warning.' ],
			'markup'     => '',
		];
	}

	public function test_the_warnings_land_on_their_blocks_and_in_the_report_and_never_change_valid(): void {
		$blocks = [ self::block( 'core/group', '', [], [ self::image( 'https://example.com/a.jpg', '' ) ] ) ];

		foreach ( [ true, false ] as $valid ) {
			$report = self::report( $valid );
			$out    = Abilities\with_warnings( $report, self::warnings( $blocks ) );

			$this->assertSame( $valid, $out['valid'] );
			$this->assertSame( $report['errors'], $out['errors'] );
			$this->assertCount( 1, $out['blocks'][1]['warnings'] );
			$this->assertStringStartsWith( 'missing_alt: core/image at 0/0 ', $out['blocks'][1]['warnings'][0] );
			$this->assertSame( [ 'core/group at 0: an earlier warning.', $out['blocks'][1]['warnings'][0] ], $out['warnings'] );
			$this->assertSame( $report, Abilities\with_warnings( $report, [] ) );
		}
	}
}
