<?php
/**
 * Section styles: Panel, Inverted and Accent, block styles on core/group.
 *
 * Each is registered with `style_data`, so WordPress turns it into CSS the
 * way it does a theme.json section style, in the editor and on the front end.
 * Plain is no style at all. The colours come from six roles (surface,
 * on-surface, muted, accent, on-accent and line), each a colour preset slug of
 * the palette the site paints with: the one a style variation chosen in the
 * Site Editor saved, else the theme's (active_palette()):
 *
 * - a built-in map for Twenty Twenty-Five, Twenty Twenty-Four and the Voyager
 *   design system, chosen by which slugs that palette declares, or the
 *   theme's own palette when it matches none, then kept to the roles that
 *   palette can paint (choose());
 * - the `voyager_open_blocks_style_roles` filter, which can replace the map;
 * - for a role no map names, a fallback from the site's text and background
 *   colours (Global Styles saved in the Site Editor, else the theme's
 *   theme.json), mixed with color-mix() and, for the roles that sit on text,
 *   currentColor.
 *
 * A theme can still restyle a section in its theme.json under
 * styles.blocks.core/group.variations.<name>: WordPress lets that win over
 * `style_data` (WP_Theme_JSON_Resolver::inject_variations_from_block_styles_registry()).
 *
 * The palette is read from the theme's theme.json files and the saved Global
 * Styles post, not from global settings: computing global settings during
 * init caches the theme's data before every block style is registered, and
 * any style registered after that would lose its `style_data`
 * (user_styles()).
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\SectionStyles;

use VoyagerOpenBlocks\Cast;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The block the styles are registered on.
 */
const BLOCK = 'core/group';

/**
 * Style => label. The registered name is `voyager-open-<style>`.
 */
const STYLES = [
	'panel'    => 'Panel',
	'inverted' => 'Inverted',
	'accent'   => 'Accent',
];

/**
 * The colour roles, in the order DESIGN.md lists them.
 */
const ROLES = [ 'surface', 'on-surface', 'muted', 'accent', 'on-accent', 'line' ];

/**
 * The contrast a style's text must reach on its background: WCAG's 4.5:1 for
 * body text, plus 0.1 so the browser's rounding of a mixed colour cannot take
 * a pair the pack chose under 4.5:1.
 */
const TARGET = 4.6;

/**
 * How much of the text colour the fallback accent mixes into the background,
 * in percent, tried in this order until the background reads at TARGET on it.
 */
const ACCENT_MIX = [ 75, 80, 85, 90, 95, 100 ];

/**
 * Built-in role maps, role => palette slug, tried in this order.
 *
 * A map is chosen when the palette declares every slug it names. No two maps
 * match the same palette: Twenty Twenty-Four has no accent-6 and Twenty
 * Twenty-Five no contrast-2, and the Voyager slugs share none with either.
 */
const MAPS = [
	'voyager'          => [
		'surface'    => 'bg-canvas',
		'on-surface' => 'fg-2',
		'muted'      => 'fg-3',
		'accent'     => 'accent',
		'on-accent'  => 'accent-fg-on',
		'line'       => 'border-1',
	],
	'twentytwentyfour' => [
		'surface'    => 'base',
		'on-surface' => 'contrast',
		'muted'      => 'contrast-2',
		'accent'     => 'accent-3',
		'on-accent'  => 'contrast',
		'line'       => 'contrast-3',
	],
	'twentytwentyfive' => [
		'surface'    => 'base',
		'on-surface' => 'contrast',
		'muted'      => 'accent-4',
		'accent'     => 'accent-3',
		'on-accent'  => 'base',
		'line'       => 'accent-6',
	],
];

/**
 * The registered style name for a style.
 *
 * @param string $style panel, inverted or accent.
 */
function name( string $style ): string {
	return 'voyager-open-' . $style;
}

/**
 * The class a style puts on the group.
 *
 * @param string $style panel, inverted or accent.
 */
function class_name( string $style ): string {
	return 'is-style-' . name( $style );
}

/**
 * The active theme's theme.json files, the stylesheet's first, then the template's.
 *
 * @return list<array<mixed>> Decoded files; one that is missing or not JSON is left out.
 */
function theme_json_files(): array {
	$theme = wp_get_theme();
	$dirs  = array_unique( [ $theme->get_stylesheet_directory(), $theme->get_template_directory() ] );
	$out   = [];

	foreach ( $dirs as $dir ) {
		$file = $dir . '/theme.json';
		if ( ! is_readable( $file ) ) {
			continue;
		}
		$data = wp_json_file_decode( $file, [ 'associative' => true ] );
		if ( is_array( $data ) ) {
			$out[] = $data;
		}
	}

	return $out;
}

/**
 * A value nested in theme.json data, or null when a key on the way is missing.
 *
 * @param array<mixed> $data Decoded theme.json data.
 * @param string       ...$keys The path.
 */
function dig( array $data, string ...$keys ): mixed {
	$value = $data;

	foreach ( $keys as $key ) {
		if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
			return null;
		}
		$value = $value[ $key ];
	}

	return $value;
}

/**
 * The palette the theme declares, as palette entries.
 *
 * The child theme's palette when it declares one, else the parent's, else the
 * classic `editor-color-palette` theme support.
 *
 * @param list<array<mixed>> $files From theme_json_files().
 * @return array<mixed>
 */
function theme_palette( array $files ): array {
	foreach ( $files as $data ) {
		$palette = dig( $data, 'settings', 'color', 'palette' );
		if ( is_array( $palette ) ) {
			return $palette;
		}
	}

	$support = get_theme_support( 'editor-color-palette' );

	return is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ? $support[0] : [];
}

/**
 * The palette slugs the theme declares.
 *
 * @param list<array<mixed>> $files From theme_json_files().
 * @return list<string>
 */
function palette_slugs( array $files ): array {
	return slugs_of( theme_palette( $files ) );
}

/**
 * The palette the site paints with: the one a style variation chosen in the
 * Site Editor saved, else the theme's.
 *
 * Choosing a variation saves its palette in the theme's Global Styles post
 * under settings.color.palette.theme, keyed by origin as
 * WP_Theme_JSON_Resolver::get_style_variations() returns it, and
 * WP_Theme_JSON::merge() replaces the theme's palette with that origin's, so
 * a slug the variation leaves out has no custom property on the page.
 * Colours added in the Site Editor are another origin (custom) and are left
 * out here, as the role maps never name them.
 *
 * @param list<array<mixed>> $user  From user_styles().
 * @param list<array<mixed>> $files From theme_json_files().
 * @return array<string, string> Slug => colour as the palette writes it.
 */
function active_palette( array $user, array $files ): array {
	foreach ( $user as $data ) {
		$palette = dig( $data, 'settings', 'color', 'palette', 'theme' );
		if ( is_array( $palette ) ) {
			return colors_of( $palette );
		}
	}

	return colors_of( theme_palette( $files ) );
}

/**
 * The colours in a list of palette entries, by slug.
 *
 * @param array<mixed> $palette Entries with a `slug` and a `color`.
 * @return array<string, string> Slug => colour; the first entry wins a repeated slug.
 */
function colors_of( array $palette ): array {
	$out = [];

	foreach ( $palette as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['slug'] ) || ! is_string( $entry['slug'] ) || '' === $entry['slug'] ) {
			continue;
		}
		if ( ! array_key_exists( $entry['slug'], $out ) ) {
			$out[ $entry['slug'] ] = Cast\to_string( $entry['color'] ?? '' );
		}
	}

	return $out;
}

/**
 * The slugs of a palette from active_palette().
 *
 * @param array<string, string> $palette Slug => colour.
 * @return list<string>
 */
function keys_of( array $palette ): array {
	return array_map( 'strval', array_keys( $palette ) );
}

/**
 * The slugs in a list of palette entries.
 *
 * @param array<mixed> $palette Entries with a `slug`.
 * @return list<string>
 */
function slugs_of( array $palette ): array {
	$out = [];

	foreach ( $palette as $entry ) {
		if ( is_array( $entry ) && isset( $entry['slug'] ) && is_string( $entry['slug'] ) && '' !== $entry['slug'] ) {
			$out[] = $entry['slug'];
		}
	}

	return array_values( array_unique( $out ) );
}

/**
 * What the Site Editor saved as Global Styles for the active theme, as
 * theme.json data: one entry, or none when nothing is saved.
 *
 * Read with WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles(),
 * which queries the theme's wp_global_styles post and leaves the resolver's
 * cached data alone, unlike get_user_data() and get_merged_data()
 * (wp-includes/class-wp-theme-json-resolver.php).
 *
 * @return list<array<mixed>>
 */
function user_styles(): array {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return [];
	}

	return user_styles_from( \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() ) );
}

/**
 * The theme.json data in a Global Styles post.
 *
 * @param mixed $post The post's fields, as get_user_data_from_wp_global_styles() returns them.
 * @return list<array<mixed>> The decoded content, or nothing when there is none.
 */
function user_styles_from( mixed $post ): array {
	$content = is_array( $post ) ? Cast\to_string( $post['post_content'] ?? '' ) : '';
	$data    = '' === $content ? null : json_decode( $content, true );

	return is_array( $data ) ? [ $data ] : [];
}

/**
 * The site's text and background colours, as CSS.
 *
 * From `styles.color` in the first of these that sets each: the Global
 * Styles saved in the Site Editor (user_styles()), the child's theme.json,
 * the parent's. A colour none sets is the browser's: CanvasText and Canvas,
 * the system colours a page with no colours of its own is drawn in.
 *
 * @param list<array<mixed>> $sources Theme.json data, first wins: user_styles(), then theme_json_files().
 * @return array{text: string, background: string}
 */
function root_colors( array $sources ): array {
	$out = [
		'text'       => '',
		'background' => '',
	];

	foreach ( $sources as $data ) {
		$styles = is_array( $data['styles'] ?? null ) ? $data['styles'] : [];
		$color  = is_array( $styles['color'] ?? null ) ? $styles['color'] : [];
		foreach ( [ 'text', 'background' ] as $key ) {
			if ( '' === $out[ $key ] ) {
				$out[ $key ] = css_value( Cast\to_string( $color[ $key ] ?? '' ) );
			}
		}
	}

	return [
		'text'       => '' === $out['text'] ? 'CanvasText' : $out['text'],
		'background' => '' === $out['background'] ? 'Canvas' : $out['background'],
	];
}

/**
 * A theme.json colour as plain CSS, so it can sit inside color-mix().
 *
 * `var:preset|color|base` becomes `var(--wp--preset--color--base)`, as
 * WP_Theme_JSON does for a whole value. Anything with a character that could
 * end the declaration is dropped.
 *
 * @param string $value Colour from theme.json.
 */
function css_value( string $value ): string {
	$value = trim( $value );

	if ( str_starts_with( $value, 'var:' ) ) {
		$value = 'var(--wp--' . str_replace( '|', '--', substr( $value, 4 ) ) . ')';
	}

	return preg_match( '/[;{}<>]/', $value ) ? '' : $value;
}

/**
 * A CSS colour as red, green and blue from 0 to 255 and alpha from 0 to 1.
 *
 * Reads hex (#rgb, #rgba, #rrggbb, #rrggbbaa), rgb() and rgba() with number
 * channels, CanvasText and Canvas (the browser's black and white), and a
 * colour preset's custom property, through the palette. Anything else, such
 * as color-mix() or a named colour, is not read: the caller treats it as a
 * colour it cannot judge.
 *
 * @param string                $css     A colour.
 * @param array<string, string> $palette Slug => colour, for var(--wp--preset--color--<slug>).
 * @param int                   $depth   How many presets deep this is, to stop a loop.
 * @return array{float, float, float, float}|null
 */
function rgba( string $css, array $palette = [], int $depth = 0 ): ?array {
	$css = trim( $css );

	if ( preg_match( '/^var\(\s*--wp--preset--color--([A-Za-z0-9_-]+)\s*\)$/', $css, $preset ) ) {
		$value = $palette[ $preset[1] ] ?? null;

		return null === $value || $depth > 2 ? null : rgba( $value, $palette, $depth + 1 );
	}

	if ( preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $css, $hex ) ) {
		$digits = strlen( $hex[1] ) <= 4 ? preg_replace( '/(.)/', '$1$1', $hex[1] ) : $hex[1];
		$c      = array_map( 'hexdec', str_split( (string) $digits, 2 ) );

		return [ (float) $c[0], (float) $c[1], (float) $c[2], isset( $c[3] ) ? (float) $c[3] / 255 : 1.0 ];
	}

	if ( preg_match( '/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,\/]\s*([\d.]+)(%?)\s*)?\)$/i', $css, $rgb ) ) {
		$alpha = isset( $rgb[4] ) ? (float) $rgb[4] / ( '%' === ( $rgb[5] ?? '' ) ? 100 : 1 ) : 1.0;

		return [ min( 255.0, (float) $rgb[1] ), min( 255.0, (float) $rgb[2] ), min( 255.0, (float) $rgb[3] ), max( 0.0, min( 1.0, $alpha ) ) ];
	}

	return match ( strtolower( $css ) ) {
		'canvastext' => [ 0.0, 0.0, 0.0, 1.0 ],
		'canvas'     => [ 255.0, 255.0, 255.0, 1.0 ],
		default      => null,
	};
}

/**
 * A colour painted over an opaque one: what the eye sees.
 *
 * @param array{float, float, float, float} $top  The colour on top, alpha and all.
 * @param array{float, float, float, float} $base An opaque colour under it.
 * @return array{float, float, float, float} Opaque.
 */
function over( array $top, array $base ): array {
	$a = $top[3];

	return [ $top[0] * $a + $base[0] * ( 1 - $a ), $top[1] * $a + $base[1] * ( 1 - $a ), $top[2] * $a + $base[2] * ( 1 - $a ), 1.0 ];
}

/**
 * color-mix(in srgb, $a $share, $b), with alpha premultiplied as CSS mixes it.
 *
 * @param array{float, float, float, float} $a     The first colour.
 * @param float                             $share How much of it, from 0 to 1.
 * @param array{float, float, float, float} $b     The second colour.
 * @return array{float, float, float, float}
 */
function mix( array $a, float $share, array $b ): array {
	$rest  = 1 - $share;
	$alpha = $a[3] * $share + $b[3] * $rest;
	if ( $alpha <= 0 ) {
		return [ 0.0, 0.0, 0.0, 0.0 ];
	}

	$channel = static fn ( int $i ): float => ( $a[ $i ] * $a[3] * $share + $b[ $i ] * $b[3] * $rest ) / $alpha;

	return [ $channel( 0 ), $channel( 1 ), $channel( 2 ), $alpha ];
}

/**
 * WCAG's relative luminance of an opaque colour.
 *
 * @param array{float, float, float, float} $c Colour.
 */
function luminance( array $c ): float {
	$linear = static function ( float $v ): float {
		$v /= 255;
		return $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
	};

	return 0.2126 * $linear( $c[0] ) + 0.7152 * $linear( $c[1] ) + 0.0722 * $linear( $c[2] );
}

/**
 * The WCAG contrast of text on a background that sits on the page, each
 * translucent colour painted over what is under it, as the browser draws them.
 *
 * @param array{float, float, float, float} $text       Text colour.
 * @param array{float, float, float, float} $background Background colour.
 * @param array{float, float, float, float} $page       The page under the background; a translucent page sits on white.
 */
function contrast( array $text, array $background, array $page ): float {
	$under = over( $background, over( $page, [ 255.0, 255.0, 255.0, 1.0 ] ) );
	$x     = luminance( over( $text, $under ) );
	$y     = luminance( $under );

	return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
}

/**
 * The built-in map for a palette, and its name.
 *
 * @param list<string> $slugs Palette slugs.
 * @return array{name: string, map: array<string, string>} Name 'fallback' and an empty map when none matches.
 */
function detect( array $slugs ): array {
	foreach ( MAPS as $name => $map ) {
		if ( [] === array_diff( array_values( $map ), $slugs ) ) {
			return [
				'name' => $name,
				'map'  => $map,
			];
		}
	}

	return [
		'name' => 'fallback',
		'map'  => [],
	];
}

/**
 * The role map in use: a built-in one, or the filter's.
 *
 * The built-in map is the one whose slugs the palette in use declares, or,
 * when it matches none, the one whose slugs the theme's own palette
 * declares: a style variation that recolours Twenty Twenty-Four and drops
 * accent-3 is still Twenty Twenty-Four. choose() then keeps it to what the
 * palette in use can paint.
 *
 * The filter gets that map, before choose() (empty on a theme with none),
 * and the slugs of the palette in use, and returns role => slug. A map the
 * filter changed is used as it returns it: a role it leaves out, and a slug
 * the palette in use does not declare, fall back, and nothing else is
 * checked.
 *
 * @param list<string>                                 $slugs   Palette slugs the theme declares (palette_slugs()).
 * @param array<string, string>|null                   $palette The palette in use, slug => colour (active_palette()). Null for the theme's slugs with no colours known.
 * @param array{text: string, background: string}|null $root    From root_colors(), the page under every style. Null for the browser's.
 * @return array{source: string, map: array<string, string>} Source: the built-in map's name, 'fallback', or 'filter' when the filter changed it.
 */
function roles( array $slugs, ?array $palette = null, ?array $root = null ): array {
	$palette ??= array_fill_keys( $slugs, '' );
	$active    = keys_of( $palette );
	$detected  = detect( $active );
	if ( 'fallback' === $detected['name'] ) {
		$detected = detect( $slugs );
	}

	/**
	 * Filters the section styles' role map.
	 *
	 * @param array<string, string> $map   Role => colour preset slug. Roles: surface, on-surface, muted, accent, on-accent, line.
	 * @param list<string>          $slugs The slugs of the palette in use: a chosen style variation's, else the theme's.
	 */
	$filtered = apply_filters( 'voyager_open_blocks_style_roles', $detected['map'], $active );

	if ( $filtered === $detected['map'] ) {
		return [
			'source' => $detected['name'],
			'map'    => choose( $detected['map'], $palette, $root ?? root_colors( [] ) ),
		];
	}

	// A filter can return anything: keep only known roles that name a slug in the palette.
	$map = [];
	foreach ( (array) $filtered as $role => $slug ) {
		$slug = Cast\to_string( $slug );
		if ( in_array( $role, ROLES, true ) && in_array( $slug, $active, true ) ) {
			$map[ (string) $role ] = $slug;
		}
	}

	return [
		'source' => 'filter',
		'map'    => $map,
	];
}

/**
 * A built-in map, kept to the roles the palette in use can paint.
 *
 * A role whose slug the palette lacks falls back, and so does the role
 * painted with it: surface with on-surface, accent with on-accent. on-accent
 * is the map's slug when it reads at TARGET on accent, else the map's surface
 * slug, else its on-surface slug, the first that does; when none does, accent
 * and on-accent fall back together. A colour rgba() cannot read, such as
 * color-mix(), is taken to pass, which is how the map is used when nothing
 * can be judged.
 *
 * @param array<string, string>                   $map     Role => slug.
 * @param array<string, string>                   $palette The palette in use, slug => colour.
 * @param array{text: string, background: string} $root    From root_colors(): the page the colours sit on.
 * @return array<string, string> Role => slug, in the order of ROLES.
 */
function choose( array $map, array $palette, array $root ): array {
	$has    = static fn ( string $role ): bool => isset( $map[ $role ] ) && array_key_exists( $map[ $role ], $palette );
	$chosen = [];

	if ( $has( 'surface' ) && $has( 'on-surface' ) ) {
		$chosen['surface']    = $map['surface'];
		$chosen['on-surface'] = $map['on-surface'];
	}
	foreach ( [ 'muted', 'line' ] as $role ) {
		if ( $has( $role ) ) {
			$chosen[ $role ] = $map[ $role ];
		}
	}

	if ( $has( 'accent' ) ) {
		$page   = rgba( $root['background'], $palette ) ?? [ 255.0, 255.0, 255.0, 1.0 ];
		$accent = rgba( $palette[ $map['accent'] ], $palette );
		$tried  = array_unique( [ $map['on-accent'] ?? '', $map['surface'] ?? '', $map['on-surface'] ?? '' ] );
		foreach ( $tried as $slug ) {
			if ( '' === $slug || ! array_key_exists( $slug, $palette ) ) {
				continue;
			}
			$text = rgba( $palette[ $slug ], $palette );
			if ( null === $accent || null === $text || contrast( $text, $accent, $page ) >= TARGET ) {
				$chosen['accent']    = $map['accent'];
				$chosen['on-accent'] = $slug;
				break;
			}
		}
	}

	$out = [];
	foreach ( ROLES as $role ) {
		if ( isset( $chosen[ $role ] ) ) {
			$out[ $role ] = $chosen[ $role ];
		}
	}

	return $out;
}

/**
 * The fallback accent: the text colour mixed into the background.
 *
 * 75% of the text, or more when the background, which is the accent's text,
 * would read under TARGET on it: 80%, 85% and so on to the text colour
 * itself. When either colour is one rgba() cannot read, 75%.
 *
 * @param string                $text       The site's text colour, as CSS.
 * @param string                $background The site's background colour, as CSS.
 * @param array<string, string> $palette    The palette in use, to read a preset's colour.
 */
function fallback_accent( string $text, string $background, array $palette ): string {
	$t     = rgba( $text, $palette );
	$b     = rgba( $background, $palette );
	$share = ACCENT_MIX[0];

	if ( null !== $t && null !== $b ) {
		foreach ( ACCENT_MIX as $share ) {
			if ( contrast( $b, mix( $t, $share / 100, $b ), $b ) >= TARGET ) {
				break;
			}
		}
	}

	return 100 === $share ? $text : sprintf( 'color-mix(in srgb, %s %d%%, %s)', $text, $share, $background );
}

/**
 * Every role as a CSS colour.
 *
 * A role in the map is its preset's custom property. A role not in it falls
 * back to the theme's text (T) and background (B): surface B, on-surface T,
 * accent 75% T in B (more where B would read under TARGET on it:
 * fallback_accent()), on-accent B. muted and line sit on text, so they mix
 * currentColor into transparent: 65% and 20%, which on a plain section are
 * 65% and 20% T in B.
 *
 * @param array<string, string>                   $map     Role => slug, from roles().
 * @param array{text: string, background: string} $root    From root_colors().
 * @param array<string, string>                   $palette The palette in use, slug => colour, to judge the fallback accent.
 * @return array<string, string> Role => CSS colour, for every role.
 */
function values( array $map, array $root, array $palette = [] ): array {
	$t = $root['text'];
	$b = $root['background'];

	$fallback = [
		'surface'    => $b,
		'on-surface' => $t,
		'muted'      => 'color-mix(in srgb, currentColor 65%, transparent)',
		'accent'     => fallback_accent( $t, $b, $palette ),
		'on-accent'  => $b,
		'line'       => 'color-mix(in srgb, currentColor 20%, transparent)',
	];

	$out = [];
	foreach ( ROLES as $role ) {
		$out[ $role ] = isset( $map[ $role ] ) ? 'var(--wp--preset--color--' . $map[ $role ] . ')' : $fallback[ $role ];
	}

	return $out;
}

/**
 * The colours a style paints: the section, its text, and its primary button.
 *
 * Panel is `surface` with 5% of `on-surface` mixed in (DESIGN.md: panel is not
 * a role, so it follows the map).
 *
 * @param string                $style  panel, inverted or accent.
 * @param array<string, string> $values From values().
 * @return array{background: string, text: string, button: string, button_text: string}
 */
function paint( string $style, array $values ): array {
	switch ( $style ) {
		case 'inverted':
			return [
				'background'  => $values['on-surface'],
				'text'        => $values['surface'],
				'button'      => $values['surface'],
				'button_text' => $values['on-surface'],
			];
		case 'accent':
			return [
				'background'  => $values['accent'],
				'text'        => $values['on-accent'],
				'button'      => $values['on-accent'],
				'button_text' => $values['accent'],
			];
		default:
			return [
				'background'  => sprintf( 'color-mix(in srgb, %s 5%%, %s)', $values['on-surface'], $values['surface'] ),
				'text'        => $values['on-surface'],
				'button'      => $values['accent'],
				'button_text' => $values['on-accent'],
			];
	}
}

/**
 * The theme.json-like data WordPress builds a style's CSS from.
 *
 * Headings and links take the section's text colour, so a theme that colours
 * them itself (Twenty Twenty-Four sets every heading to contrast) cannot put
 * dark text on Inverted. So do quotes and their citations: a style variation
 * can colour core/quote for its own background (Twenty Twenty-Five's Dusk
 * sets it to black, Sunrise to accent-2), which reads 1.19:1 on Dusk's
 * Accent. The fill button takes the style's button colours at
 * rest, on hover and on focus, where hover underlines the label rather than
 * changing a colour the theme chose for another background. An outline
 * button needs nothing here at rest: core's outline style draws it in
 * currentColor with no fill, so it takes the section's text colour. Its
 * hover and focus are in inline_style(), which style_data cannot reach.
 *
 * @param string                $style  panel, inverted or accent.
 * @param array<string, string> $values From values().
 * @return array<string, mixed>
 */
function style_data( string $style, array $values ): array {
	$paint  = paint( $style, $values );
	$text   = [ 'text' => $paint['text'] ];
	$button = [
		'background' => $paint['button'],
		'text'       => $paint['button_text'],
	];

	return [
		'color'    => [
			'background' => $paint['background'],
			'text'       => $paint['text'],
		],
		'elements' => [
			'heading' => [ 'color' => $text ],
			'link'    => [
				'color'  => $text,
				':hover' => [ 'color' => $text ],
			],
			'button'  => [
				'color'  => $button,
				':hover' => [
					'color'      => $button,
					'typography' => [ 'textDecoration' => 'underline' ],
				],
				':focus' => [ 'color' => $button ],
			],
		],
		'blocks'   => [
			'core/quote' => [
				'color'    => $text,
				'elements' => [
					'cite' => [ 'color' => $text ],
				],
			],
		],
	];
}

/**
 * The CSS a style adds beside its style_data: an outline button keeps its
 * colours on hover and focus, as the fill button does, and hover underlines
 * its label.
 *
 * style_data cannot say this. core/button's outline style is a block style
 * variation too, so WordPress prints each outline button's own styles after
 * the section's, both at 0-1-0 specificity, and a theme's outline :hover
 * there wins (wp_render_block_style_variation_support_styles() in
 * wp-includes/block-supports/block-style-variations.php). Ipsum's paints the
 * label in its accent-3 on a near-transparent fill, which reads 1.94:1 on
 * Inverted. These selectors are more specific than any of those, and inherit
 * keeps the label in the section's text colour, as it is at rest.
 *
 * @param string $style panel, inverted or accent.
 */
function inline_style( string $style ): string {
	$section = '.wp-block-group.' . class_name( $style );
	$links   = static fn ( string $state ): string => sprintf(
		'%1$s .wp-block-button.is-style-outline>.wp-block-button__link%2$s,%1$s .wp-block-button__link.is-style-outline%2$s',
		$section,
		$state
	);

	return $links( ':hover' ) . ',' . $links( ':focus' ) . '{background-color:transparent;background-image:none;color:inherit}'
		. $links( ':hover' ) . '{text-decoration:underline}';
}

/**
 * What the styles resolve to on this site.
 *
 * Reads the theme's theme.json files and the saved Global Styles, so call it
 * once where it is needed: on init to register, and in get-catalog.
 *
 * @return array{source: string, map: array<string, string>, values: array<string, string>}
 */
function resolved(): array {
	$files   = theme_json_files();
	$user    = user_styles();
	$palette = active_palette( $user, $files );
	$root    = root_colors( [ ...$user, ...$files ] );
	$roles   = roles( palette_slugs( $files ), $palette, $root );

	return [
		'source' => $roles['source'],
		'map'    => $roles['map'],
		'values' => values( $roles['map'], $root, $palette ),
	];
}

/**
 * The styles' labels, translated.
 *
 * @return array<string, string> Style => label, in the order of STYLES.
 */
function labels(): array {
	return [
		'panel'    => __( 'Panel', 'voyager-open-blocks' ),
		'inverted' => __( 'Inverted', 'voyager-open-blocks' ),
		'accent'   => __( 'Accent', 'voyager-open-blocks' ),
	];
}

/**
 * Register the three styles.
 */
function register(): void {
	$values = resolved()['values'];

	foreach ( labels() as $style => $label ) {
		register_block_style(
			BLOCK,
			[
				'name'         => name( $style ),
				'label'        => $label,
				'style_data'   => style_data( $style, $values ),
				'inline_style' => inline_style( $style ),
			]
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\register' );

/**
 * The styles as get-catalog lists them.
 *
 * @return array{block: string, roles: string, styles: list<array{style: string, name: string, label: string, class: string, strength: string}>}
 */
function catalog(): array {
	$styles = [];

	foreach ( labels() as $style => $label ) {
		$styles[] = [
			'style'    => $style,
			'name'     => name( $style ),
			'label'    => $label,
			'class'    => class_name( $style ),
			'strength' => 'panel' === $style ? 'quiet' : 'strong',
		];
	}

	return [
		'block'  => BLOCK,
		'roles'  => resolved()['source'],
		'styles' => $styles,
	];
}

/**
 * Put one section style on a group, or take it off.
 *
 * Writes the class where the editor would: the group's className and the
 * class attribute of its wrapper in the saved HTML. Any other is-style-*
 * class goes, as the editor's Styles panel swaps one style for another.
 *
 * @param Block       $block A core/group block.
 * @param string|null $style panel, inverted, accent, or plain or null for none.
 * @return Block|WP_Error
 */
function apply( array $block, ?string $style ) {
	if ( BLOCK !== $block['blockName'] ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_style',
			sprintf( 'Section styles go on a core/group, and this block is %s. Read the section\'s path with get-post-blocks.', (string) $block['blockName'] ),
			[ 'status' => 400 ]
		);
	}

	$style = null === $style ? 'plain' : $style;
	if ( 'plain' !== $style && ! isset( STYLES[ $style ] ) ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_style',
			sprintf( 'No section style "%s". Styles: plain, %s.', $style, implode( ', ', array_keys( STYLES ) ) ),
			[ 'status' => 400 ]
		);
	}

	$add = 'plain' === $style ? '' : class_name( $style );

	$split   = preg_split( '/\s+/', Cast\to_string( $block['attrs']['className'] ?? '' ) );
	$classes = array_filter(
		false === $split ? [] : $split,
		static fn ( string $name ): bool => '' !== $name && ! str_starts_with( $name, 'is-style-' )
	);
	if ( '' !== $add ) {
		$classes[] = $add;
	}
	if ( [] === $classes ) {
		unset( $block['attrs']['className'] );
	} else {
		$block['attrs']['className'] = implode( ' ', $classes );
	}

	$first = $block['innerContent'][0] ?? null;
	if ( ! is_string( $first ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_style', 'This group saves no wrapper element, so it has no class to set.', [ 'status' => 400 ] );
	}

	$wrapper = restyle_wrapper( $first, $add );
	if ( null === $wrapper ) {
		return new WP_Error( 'voyager_open_blocks_invalid_style', 'The group\'s saved HTML has no wrapper element, so it has no class to set.', [ 'status' => 400 ] );
	}

	$block['innerContent'][0] = $wrapper;
	$block['innerHTML']       = (string) restyle_wrapper( $block['innerHTML'], $add );

	return $block;
}

/**
 * The first tag of some HTML with its is-style-* classes swapped for one.
 *
 * @param string $html The block's saved HTML, or its first chunk.
 * @param string $add  The class to add, '' for none.
 * @return string|null Null when the HTML has no tag.
 */
function restyle_wrapper( string $html, string $add ): ?string {
	$tags = new \WP_HTML_Tag_Processor( $html );
	if ( ! $tags->next_tag() ) {
		return null;
	}

	foreach ( iterator_to_array( $tags->class_list(), false ) as $name ) {
		if ( str_starts_with( $name, 'is-style-' ) ) {
			$tags->remove_class( $name );
		}
	}
	if ( '' !== $add ) {
		$tags->add_class( $add );
	}

	return $tags->get_updated_html();
}
