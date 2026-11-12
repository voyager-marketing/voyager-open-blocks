<?php
/**
 * SectionStyles: which role map a palette gets, the filter that replaces it,
 * how a map follows the palette in use, the fallback, and the theme.json-like
 * data and CSS each style registers.
 *
 * The palette slugs are tests/fixtures/palette-slugs.json: the bundled themes'
 * theme.json and the Voyager design system's. The colours below are copied
 * from the theme.json and style variation files of WordPress 7.1.4's bundled
 * themes, and Ipsum's at the commit tests/fixtures/contrast.json pins.
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
	 * Twenty Twenty-Four's own palette.
	 */
	private const TT4 = [
		'base'       => '#f9f9f9',
		'base-2'     => '#ffffff',
		'contrast'   => '#111111',
		'contrast-2' => '#636363',
		'contrast-3' => '#A4A4A4',
		'accent'     => '#cfcabe',
		'accent-2'   => '#c2a990',
		'accent-3'   => '#d8613c',
		'accent-4'   => '#b1c5a4',
		'accent-5'   => '#b5bdbc',
	];

	/**
	 * Twenty Twenty-Four's Ember variation, which has no accent-3 or contrast-3.
	 */
	private const TT4_EMBER = [
		'base'       => '#F9F8F5',
		'contrast-2' => '#D73301',
		'contrast'   => '#000',
		'base-2'     => '#f6decd',
	];

	/**
	 * Twenty Twenty-Five's own palette.
	 */
	private const TT5 = [
		'base'     => '#FFFFFF',
		'contrast' => '#111111',
		'accent-1' => '#FFEE58',
		'accent-2' => '#F6CFF4',
		'accent-3' => '#503AA8',
		'accent-4' => '#686868',
		'accent-5' => '#FBFAF3',
		'accent-6' => 'color-mix(in srgb, currentColor 20%, transparent)',
	];

	/**
	 * Twenty Twenty-Five's Twilight variation: every slug, but accent-3 is dark on a dark base.
	 */
	private const TT5_TWILIGHT = [
		'base'     => '#131313',
		'contrast' => '#FFFFFF',
		'accent-1' => '#4B52FF',
		'accent-2' => '#FF7A5C',
		'accent-3' => '#252525',
		'accent-4' => '#FFFFFF',
		'accent-5' => '#252525',
		'accent-6' => '#FFFFFF33',
	];

	/**
	 * Twenty Twenty-Five's and Twenty Twenty-Four's root colours.
	 */
	private const ROOT = [
		'text'       => 'var(--wp--preset--color--contrast)',
		'background' => 'var(--wp--preset--color--base)',
	];

	/**
	 * The ratio a role pair reads at: $text on $background, on the page's background.
	 *
	 * @param array<string, string> $values  From values().
	 * @param array<string, string> $palette Slug => colour.
	 */
	private function ratio( array $values, string $text, string $background, array $palette ): float {
		$read = static function ( string $css ) use ( $palette ): array {
			if ( preg_match( '/^color-mix\(in srgb, (.+) (\d+)%, (.+)\)$/', $css, $m ) ) {
				$a = SectionStyles\rgba( $m[1], $palette );
				$b = SectionStyles\rgba( $m[3], $palette );
				self::assertNotNull( $a );
				self::assertNotNull( $b );
				return SectionStyles\mix( $a, (int) $m[2] / 100, $b );
			}
			$c = SectionStyles\rgba( $css, $palette );
			self::assertNotNull( $c, $css );
			return $c;
		};
		$page = $read( $values['surface'] );

		return SectionStyles\contrast( $read( $values[ $text ] ), $read( $values[ $background ] ), $page );
	}

	public function test_a_variation_palette_missing_a_mapped_slug_keeps_the_map_and_falls_back_for_that_pair(): void {
		$roles = SectionStyles\roles( $this->palette( 'twentytwentyfour' ), self::TT4_EMBER, self::ROOT );

		$this->assertSame( 'twentytwentyfour', $roles['source'], 'the theme\'s own palette picks the map when the variation\'s matches none' );
		$this->assertSame(
			[
				'surface'    => 'base',
				'on-surface' => 'contrast',
				'muted'      => 'contrast-2',
			],
			$roles['map'],
			'accent-3 is missing, so accent falls back, and on-accent with it although contrast is there; contrast-3 is missing, so line falls back'
		);

		$values = SectionStyles\values( $roles['map'], self::ROOT, self::TT4_EMBER );
		$this->assertSame( 'color-mix(in srgb, var(--wp--preset--color--contrast) 75%, var(--wp--preset--color--base))', $values['accent'] );
		$this->assertSame( 'var(--wp--preset--color--base)', $values['on-accent'] );
		$this->assertSame( 'color-mix(in srgb, currentColor 20%, transparent)', $values['line'] );
		$this->assertGreaterThanOrEqual( 4.5, $this->ratio( $values, 'on-accent', 'accent', self::TT4_EMBER ) );
	}

	public function test_the_themes_own_palette_keeps_every_mapped_slug(): void {
		$roles = SectionStyles\roles( $this->palette( 'twentytwentyfour' ), self::TT4, self::ROOT );

		$this->assertSame( 'twentytwentyfour', $roles['source'] );
		$this->assertSame( SectionStyles\MAPS['twentytwentyfour'], $roles['map'] );
	}

	public function test_the_palette_in_use_picks_the_map_before_the_theme_does(): void {
		$roles = SectionStyles\roles( $this->palette( 'twentytwentythree' ), self::TT5, self::ROOT );

		$this->assertSame( 'twentytwentyfive', $roles['source'] );
		$this->assertSame( SectionStyles\MAPS['twentytwentyfive'], $roles['map'] );
	}

	public function test_on_accent_moves_to_a_slug_that_reads_on_the_accent(): void {
		$base = SectionStyles\roles( $this->palette( 'twentytwentyfive' ), self::TT5, self::ROOT );
		$this->assertSame( 'base', $base['map']['on-accent'], 'base reads on accent-3 on the theme\'s own palette' );

		$twilight = SectionStyles\roles( $this->palette( 'twentytwentyfive' ), self::TT5_TWILIGHT, self::ROOT );
		$this->assertSame( 'twentytwentyfive', $twilight['source'] );
		$this->assertSame( 'accent-3', $twilight['map']['accent'] );
		$this->assertSame( 'contrast', $twilight['map']['on-accent'], 'base reads 1.21:1 on Twilight\'s accent-3, contrast reads' );

		$values = SectionStyles\values( $twilight['map'], self::ROOT, self::TT5_TWILIGHT );
		$this->assertGreaterThanOrEqual( 4.5, $this->ratio( $values, 'on-accent', 'accent', self::TT5_TWILIGHT ) );
	}

	public function test_accent_and_on_accent_fall_back_when_no_slug_reads_on_the_accent(): void {
		$grey = [
			'base'     => '#767676',
			'contrast' => '#808080',
			'accent-3' => '#7a7a7a',
			'accent-4' => '#686868',
			'accent-6' => '#00000033',
		];

		$roles = SectionStyles\roles( $this->palette( 'twentytwentyfive' ), $grey, self::ROOT );

		$this->assertSame( 'twentytwentyfive', $roles['source'] );
		$this->assertArrayNotHasKey( 'accent', $roles['map'] );
		$this->assertArrayNotHasKey( 'on-accent', $roles['map'] );
		$this->assertSame( 'base', $roles['map']['surface'] );
	}

	public function test_a_colour_the_pack_cannot_read_keeps_the_maps_slug(): void {
		$palette             = self::TT5_TWILIGHT;
		$palette['accent-3'] = 'color-mix(in srgb, #252525 90%, white)';

		$roles = SectionStyles\roles( $this->palette( 'twentytwentyfive' ), $palette, self::ROOT );

		$this->assertSame( 'accent-3', $roles['map']['accent'] );
		$this->assertSame( 'base', $roles['map']['on-accent'] );
	}

	public function test_a_map_the_filter_changed_is_used_as_it_returns_it(): void {
		$seen = [];
		add_filter(
			'voyager_open_blocks_style_roles',
			static function ( array $map, array $slugs ) use ( &$seen ): array {
				$seen = $slugs;
				return array_merge( $map, [ 'on-accent' => 'accent-3' ] );
			},
			10,
			2
		);

		$roles = SectionStyles\roles( $this->palette( 'twentytwentyfive' ), self::TT5_TWILIGHT, self::ROOT );

		$this->assertSame( array_keys( self::TT5_TWILIGHT ), $seen, 'the filter gets the slugs of the palette in use' );
		$this->assertSame( 'filter', $roles['source'] );
		$this->assertSame( 'accent-3', $roles['map']['on-accent'], 'a low-contrast pair the filter asks for is kept' );
	}

	public function test_the_palette_in_use_is_the_one_a_style_variation_saved(): void {
		$files   = [ [ 'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'base', 'color' => '#f9f9f9' ], [ 'slug' => 'accent-3', 'color' => '#d8613c' ] ] ] ] ] ];
		$chosen  = SectionStyles\user_styles_from( [ 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"settings":{"color":{"palette":{"theme":[{"slug":"base","color":"#F9F8F5"},{"slug":"contrast","color":"#000"}]}}}}' ] );
		$custom  = SectionStyles\user_styles_from( [ 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"settings":{"color":{"palette":{"custom":[{"slug":"custom-1","color":"#123456"}]}}}}' ] );
		$theme   = [ 'base' => '#f9f9f9', 'accent-3' => '#d8613c' ];
		$variant = [ 'base' => '#F9F8F5', 'contrast' => '#000' ];

		$this->assertSame( $variant, SectionStyles\active_palette( $chosen, $files ) );
		$this->assertSame( $theme, SectionStyles\active_palette( $custom, $files ), 'colours added in the Site Editor leave the theme\'s palette in place' );
		$this->assertSame( $theme, SectionStyles\active_palette( [], $files ) );
	}

	/**
	 * @return array<string, array{string, string, array<string, string>, int}>
	 */
	public static function fallbacks(): array {
		return [
			'black on white'                 => [ '#111111', '#ffffff', [], 75 ],
			'Twenty Twenty-Four Rust'        => [ '#A62B0C', '#F3F0E7', [], 85 ],
			'Twenty Twenty-Four Maelstrom'   => [ '#FFFFFF', '#38629F', [], 80 ],
			'Ipsum Blue Hour, through vars'  => [ 'var(--wp--preset--color--contrast)', 'var(--wp--preset--color--base)', [ 'base' => '#ecf7fe', 'contrast' => '#0d1b26bf' ], 85 ],
			'Ipsum After Hours'              => [ '#e26bbe', '#310c25', [], 90 ],
			'text that never reads'          => [ '#767676', '#808080', [], 100 ],
			'a colour that cannot be read'   => [ 'color-mix(in srgb, red 50%, blue)', '#ffffff', [], 75 ],
		];
	}

	/**
	 * @param array<string, string> $palette
	 */
	#[DataProvider( 'fallbacks' )]
	public function test_the_fallback_accent_mixes_in_more_text_until_the_background_reads_on_it( string $text, string $background, array $palette, int $share ): void {
		$accent = SectionStyles\values( [], [ 'text' => $text, 'background' => $background ], $palette )['accent'];

		$this->assertSame( 100 === $share ? $text : sprintf( 'color-mix(in srgb, %s %d%%, %s)', $text, $share, $background ), $accent );

		$t = SectionStyles\rgba( $text, $palette );
		$b = SectionStyles\rgba( $background, $palette );
		if ( null === $t || null === $b ) {
			return;
		}
		if ( $share < 100 ) {
			$this->assertGreaterThanOrEqual( SectionStyles\TARGET, SectionStyles\contrast( $b, SectionStyles\mix( $t, $share / 100, $b ), $b ) );
		}
		if ( $share > SectionStyles\ACCENT_MIX[0] ) {
			$this->assertLessThan( SectionStyles\TARGET, SectionStyles\contrast( $b, SectionStyles\mix( $t, ( $share - 5 ) / 100, $b ), $b ), 'and no less would do' );
		}
	}

	public function test_colours_are_read_from_hex_rgb_presets_and_system_colours(): void {
		$this->assertSame( [ 255.0, 255.0, 255.0, 1.0 ], SectionStyles\rgba( '#fff' ) );
		$this->assertSame( [ 17.0, 17.0, 17.0, 1.0 ], SectionStyles\rgba( ' #111111 ' ) );
		$this->assertEqualsWithDelta( [ 13.0, 27.0, 38.0, 0.749 ], SectionStyles\rgba( '#0d1b26bf' ), 0.001 );
		$this->assertEqualsWithDelta( [ 0.0, 0.0, 0.0, 0.533 ], SectionStyles\rgba( '#0008' ), 0.001 );
		$this->assertSame( [ 10.0, 20.0, 30.0, 0.5 ], SectionStyles\rgba( 'rgba(10, 20, 30, .5)' ) );
		$this->assertSame( [ 10.0, 20.0, 30.0, 0.25 ], SectionStyles\rgba( 'rgb(10 20 30 / 25%)' ) );
		$this->assertSame( [ 80.0, 58.0, 168.0, 1.0 ], SectionStyles\rgba( 'var(--wp--preset--color--accent-3)', self::TT5 ) );
		$this->assertSame( [ 0.0, 0.0, 0.0, 1.0 ], SectionStyles\rgba( 'CanvasText' ) );
		$this->assertSame( [ 255.0, 255.0, 255.0, 1.0 ], SectionStyles\rgba( 'Canvas' ) );

		$this->assertNull( SectionStyles\rgba( 'var(--wp--preset--color--accent-6)', self::TT5 ), 'a preset that is a color-mix()' );
		$this->assertNull( SectionStyles\rgba( 'var(--wp--preset--color--missing)', self::TT5 ) );
		$this->assertNull( SectionStyles\rgba( 'var(--wp--preset--color--a)', [ 'a' => 'var(--wp--preset--color--a)' ] ), 'a preset that names itself' );
		$this->assertNull( SectionStyles\rgba( 'rebeccapurple' ) );
		$this->assertNull( SectionStyles\rgba( '#12345' ) );
	}

	public function test_contrast_is_wcags_ratio_with_translucent_colours_painted_over_what_is_under_them(): void {
		$white = [ 255.0, 255.0, 255.0, 1.0 ];
		$black = [ 0.0, 0.0, 0.0, 1.0 ];

		$this->assertEqualsWithDelta( 21.0, SectionStyles\contrast( $black, $white, $white ), 0.001 );
		$this->assertEqualsWithDelta( 4.54, SectionStyles\contrast( [ 118.0, 118.0, 118.0, 1.0 ], $white, $white ), 0.01, '#767676 on white' );
		$this->assertEqualsWithDelta( 1.0, SectionStyles\contrast( $black, [ 0.0, 0.0, 0.0, 0.0 ], $black ), 0.001, 'a transparent background is the page' );
		$this->assertEqualsWithDelta( 10.23, SectionStyles\contrast( [ 252.0, 252.0, 252.0, 1.0 ], [ 0.0, 0.0, 0.0, 0.749 ], [ 252.0, 252.0, 252.0, 1.0 ] ), 0.05, 'Ipsum\'s Inverted, as the browser measures it' );
	}

	public function test_an_outline_button_keeps_its_colours_on_hover_and_focus_in_every_style(): void {
		foreach ( array_keys( SectionStyles\STYLES ) as $style ) {
			$css     = SectionStyles\inline_style( $style );
			$section = '.wp-block-group.is-style-voyager-open-' . $style;

			$this->assertStringContainsString( $section . ' .wp-block-button.is-style-outline>.wp-block-button__link:hover,', $css );
			$this->assertStringContainsString( $section . ' .wp-block-button__link.is-style-outline:focus{background-color:transparent;background-image:none;color:inherit}', $css );
			$this->assertStringEndsWith( $section . ' .wp-block-button__link.is-style-outline:hover{text-decoration:underline}', $css );
			$this->assertStringNotContainsString( ':where(', $css, 'a theme\'s outline hover is 0-1-0, so this must not be' );
		}
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
	public function test_each_style_paints_its_section_headings_links_quotes_and_buttons_by_role( string $style, string $background, string $text, string $button, string $button_text ): void {
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
		// A style variation can colour quotes for its own background (Twenty Twenty-Five's Dusk: black).
		$this->assertSame( [ 'text' => $text ], $data['blocks']['core/quote']['color'] ?? null, 'a quote takes the section text colour' );
		$this->assertSame( [ 'text' => $text ], $data['blocks']['core/quote']['elements']['cite']['color'] ?? null, 'and so does its citation' );
		$this->assertSame( [ 'background' => $button, 'text' => $button_text ], $data['elements']['button']['color'] );
		$this->assertSame( [ 'background' => $button, 'text' => $button_text ], $data['elements']['button'][':hover']['color'], 'hover keeps the colours' );
	}
}
