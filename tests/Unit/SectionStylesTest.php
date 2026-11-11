<?php
/**
 * SectionStyles: which role map a palette gets, the filter that replaces it,
 * the fallback, and the theme.json-like data each style registers.
 *
 * The palettes are tests/fixtures/palette-slugs.json: the bundled themes'
 * theme.json and the Voyager design system's.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\SectionStyles;

final class SectionStylesTest extends TestCase {

	/**
	 * @var array<string, list<string>>
	 */
	private static array $palettes = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters'] = [];

		if ( [] === self::$palettes ) {
			$decoded = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/palette-slugs.json' ), true );
			$this->assertIsArray( $decoded );
			unset( $decoded['$comment'] );
			self::$palettes = $decoded;
		}
	}

	/**
	 * @return list<string>
	 */
	private function palette( string $name ): array {
		$this->assertArrayHasKey( $name, self::$palettes );

		return self::$palettes[ $name ];
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function palettes(): array {
		return [
			'Twenty Twenty-Five'    => [ 'twentytwentyfive', 'twentytwentyfive' ],
			'Twenty Twenty-Four'    => [ 'twentytwentyfour', 'twentytwentyfour' ],
			'Voyager design system' => [ 'voyager', 'voyager' ],
			'Twenty Twenty-Three'   => [ 'twentytwentythree', 'fallback' ],
		];
	}

	#[DataProvider( 'palettes' )]
	public function test_each_palette_gets_its_own_map( string $palette, string $expected ): void {
		$this->assertSame( $expected, SectionStyles\detect( $this->palette( $palette ) )['name'] );
	}

	public function test_every_built_in_map_names_all_six_roles_and_only_slugs_its_theme_declares(): void {
		foreach ( SectionStyles\MAPS as $name => $map ) {
			$this->assertSame( SectionStyles\ROLES, array_keys( $map ), $name );
			$this->assertSame( [], array_values( array_diff( array_values( $map ), $this->palette( $name ) ) ), $name );
		}
	}

	public function test_no_built_in_map_fits_another_themes_palette(): void {
		foreach ( SectionStyles\MAPS as $name => $map ) {
			foreach ( self::$palettes as $palette => $slugs ) {
				if ( $palette === $name ) {
					continue;
				}
				$this->assertNotSame( [], array_diff( array_values( $map ), $slugs ), "$name fits $palette" );
			}
		}
	}

	public function test_a_theme_replaces_the_role_map_with_the_filter(): void {
		$seen = [];
		add_filter(
			'voyager_open_blocks_style_roles',
			static function ( array $map, array $slugs ) use ( &$seen ): array {
				$seen = [ $map, $slugs ];
				return [
					'surface'    => 'paper',
					'on-surface' => 'ink',
					'accent'     => 'brand',
					'on-accent'  => 'paper',
				];
			},
			10,
			2
		);

		$slugs = [ 'paper', 'ink', 'brand', 'base', 'contrast', 'accent-1', 'accent-2', 'accent-3', 'accent-4', 'accent-5', 'accent-6' ];
		$roles = SectionStyles\roles( $slugs );

		$this->assertSame( SectionStyles\MAPS['twentytwentyfive'], $seen[0], 'the filter gets the built-in map' );
		$this->assertSame( $slugs, $seen[1], 'and the theme\'s slugs' );
		$this->assertSame( 'filter', $roles['source'] );
		$this->assertSame(
			[
				'surface'    => 'paper',
				'on-surface' => 'ink',
				'accent'     => 'brand',
				'on-accent'  => 'paper',
			],
			$roles['map']
		);

		$values = SectionStyles\values( $roles['map'], [ 'text' => '#222222', 'background' => '#fefefe' ] );
		$this->assertSame( 'var(--wp--preset--color--brand)', $values['accent'] );
		$this->assertSame( 'var(--wp--preset--color--ink)', $values['on-surface'] );
		$this->assertSame( 'color-mix(in srgb, currentColor 20%, transparent)', $values['line'], 'a role the filter leaves out falls back' );

		$accent = SectionStyles\style_data( 'accent', $values );
		$this->assertSame( 'var(--wp--preset--color--brand)', $accent['color']['background'] );
		$this->assertSame( 'var(--wp--preset--color--paper)', $accent['color']['text'] );
	}

	public function test_a_filtered_slug_the_theme_does_not_declare_and_an_unknown_role_are_dropped(): void {
		add_filter(
			'voyager_open_blocks_style_roles',
			static fn ( array $map ): array => [
				'accent'  => 'not-a-slug',
				'surface' => 'base',
				'shadow'  => 'contrast',
				'line'    => [ 'contrast' ],
			]
		);

		$roles = SectionStyles\roles( $this->palette( 'twentytwentythree' ) );

		$this->assertSame( [ 'surface' => 'base' ], $roles['map'] );
		$this->assertSame( 'filter', $roles['source'] );
	}

	public function test_a_filter_that_returns_the_map_unchanged_keeps_its_name(): void {
		add_filter( 'voyager_open_blocks_style_roles', static fn ( array $map ): array => $map );

		$this->assertSame( 'twentytwentyfour', SectionStyles\roles( $this->palette( 'twentytwentyfour' ) )['source'] );
		$this->assertSame( 'fallback', SectionStyles\roles( $this->palette( 'twentytwentythree' ) )['source'] );
	}

	public function test_the_fallback_is_built_from_the_theme_colours(): void {
		$values = SectionStyles\values( [], [ 'text' => 'var(--wp--preset--color--contrast)', 'background' => '#ffffff' ] );

		$this->assertSame(
			[
				'surface'    => '#ffffff',
				'on-surface' => 'var(--wp--preset--color--contrast)',
				'muted'      => 'color-mix(in srgb, currentColor 65%, transparent)',
				'accent'     => 'color-mix(in srgb, var(--wp--preset--color--contrast) 75%, #ffffff)',
				'on-accent'  => '#ffffff',
				'line'       => 'color-mix(in srgb, currentColor 20%, transparent)',
			],
			$values
		);
	}

	public function test_accent_and_inverted_differ_on_the_fallback(): void {
		$values   = SectionStyles\values( [], [ 'text' => '#111111', 'background' => '#ffffff' ] );
		$accent   = SectionStyles\paint( 'accent', $values );
		$inverted = SectionStyles\paint( 'inverted', $values );

		$this->assertNotSame( $inverted['background'], $accent['background'] );
	}

	public function test_root_colours_come_from_the_child_then_the_parent_then_the_browser(): void {
		$child  = [ 'styles' => [ 'color' => [ 'text' => 'var:preset|color|ink' ] ] ];
		$parent = [ 'styles' => [ 'color' => [ 'text' => '#000000', 'background' => 'var(--wp--preset--color--base)' ] ] ];

		$this->assertSame(
			[
				'text'       => 'var(--wp--preset--color--ink)',
				'background' => 'var(--wp--preset--color--base)',
			],
			SectionStyles\root_colors( [ $child, $parent ] )
		);
		$this->assertSame(
			[
				'text'       => 'CanvasText',
				'background' => 'Canvas',
			],
			SectionStyles\root_colors( [] )
		);
	}

	public function test_root_colours_saved_in_the_site_editor_come_before_the_theme_files(): void {
		$user  = SectionStyles\user_styles_from( [ 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"var:preset|color|accent-2"}}}' ] );
		$child = [ 'styles' => [ 'color' => [ 'text' => '#111111', 'background' => '#ffffff' ] ] ];

		$this->assertSame(
			[
				'text'       => '#111111',
				'background' => 'var(--wp--preset--color--accent-2)',
			],
			SectionStyles\root_colors( [ ...$user, $child ] )
		);
	}

	public function test_no_saved_global_styles_add_nothing(): void {
		$this->assertSame( [], SectionStyles\user_styles_from( [] ) );
		$this->assertSame( [], SectionStyles\user_styles_from( null ) );
		$this->assertSame( [], SectionStyles\user_styles_from( [ 'post_content' => '' ] ) );
		$this->assertSame( [], SectionStyles\user_styles_from( [ 'post_content' => 'not JSON' ] ) );
	}

	public function test_a_colour_that_could_end_the_declaration_is_dropped(): void {
		$this->assertSame( '', SectionStyles\css_value( 'red;}body{display:none' ) );
		$this->assertSame( 'var(--wp--preset--color--base)', SectionStyles\css_value( ' var:preset|color|base ' ) );
		$this->assertSame( '#fff', SectionStyles\css_value( '#fff' ) );
	}

	public function test_the_palette_is_the_childs_when_it_declares_one(): void {
		$child  = [ 'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'ink' ], [ 'slug' => 'paper' ], [ 'slug' => 'ink' ], [ 'name' => 'no slug' ] ] ] ] ];
		$parent = [ 'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'base' ] ] ] ] ];

		$this->assertSame( [ 'ink', 'paper' ], SectionStyles\palette_slugs( [ $child, $parent ] ) );
		$this->assertSame( [ 'base' ], SectionStyles\palette_slugs( [ [ 'settings' => [] ], $parent ] ) );
	}

	/**
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public static function styles(): array {
		return [
			'Panel'    => [ 'panel', 'color-mix(in srgb, T 5%, S)', 'T', 'A', 'OA' ],
			'Inverted' => [ 'inverted', 'T', 'S', 'S', 'T' ],
			'Accent'   => [ 'accent', 'A', 'OA', 'OA', 'A' ],
		];
	}

	#[DataProvider( 'styles' )]
	public function test_each_style_paints_its_section_headings_links_and_buttons_by_role( string $style, string $background, string $text, string $button, string $button_text ): void {
		$values = [
			'surface'    => 'S',
			'on-surface' => 'T',
			'muted'      => 'M',
			'accent'     => 'A',
			'on-accent'  => 'OA',
			'line'       => 'L',
		];
		$data   = SectionStyles\style_data( $style, $values );

		$this->assertSame( [ 'background' => $background, 'text' => $text ], $data['color'] );
		$this->assertSame( [ 'text' => $text ], $data['elements']['heading']['color'] );
		$this->assertSame( [ 'text' => $text ], $data['elements']['link']['color'] );
		$this->assertSame( [ 'background' => $button, 'text' => $button_text ], $data['elements']['button']['color'] );
		$this->assertSame( [ 'background' => $button, 'text' => $button_text ], $data['elements']['button'][':hover']['color'], 'hover keeps the colours' );
	}
}
