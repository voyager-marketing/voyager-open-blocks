<?php
/**
 * Section styles: Panel, Inverted and Accent, block styles on core/group.
 *
 * Each is registered with `style_data`, so WordPress turns it into CSS the
 * way it does a theme.json section style, in the editor and on the front end.
 * Plain is no style at all. The colours come from six roles (surface,
 * on-surface, muted, accent, on-accent and line), each a colour preset slug of
 * the active theme:
 *
 * - a built-in map for Twenty Twenty-Five, Twenty Twenty-Four and the Voyager
 *   design system, chosen by which palette slugs the theme declares;
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
 * The palette is read from the theme's theme.json files, not from global
 * settings: computing global settings during init caches the theme's data
 * before every block style is registered, and any style registered after that
 * would lose its `style_data`. The Site Editor's Global Styles are read from
 * their post for the same reason (user_styles()).
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
 * Built-in role maps, role => palette slug, tried in this order.
 *
 * A map is chosen when the theme declares every slug it names. No two maps
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
 * The palette slugs the theme declares.
 *
 * The child theme's palette when it declares one, else the parent's, else the
 * classic `editor-color-palette` theme support.
 *
 * @param list<array<mixed>> $files From theme_json_files().
 * @return list<string>
 */
function palette_slugs( array $files ): array {
	foreach ( $files as $data ) {
		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : [];
		$color    = is_array( $settings['color'] ?? null ) ? $settings['color'] : [];
		if ( isset( $color['palette'] ) && is_array( $color['palette'] ) ) {
			return slugs_of( $color['palette'] );
		}
	}

	$support = get_theme_support( 'editor-color-palette' );

	return is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ? slugs_of( $support[0] ) : [];
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
 * The built-in map for a palette, and its name.
 *
 * @param list<string> $slugs Palette slugs the theme declares.
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
 * The role map in use: the built-in one, after the filter.
 *
 * The filter gets the built-in map (empty on a theme with none) and the
 * theme's palette slugs, and returns role => slug. A role it leaves out, and
 * a slug the theme does not declare, fall back.
 *
 * @param list<string> $slugs Palette slugs the theme declares.
 * @return array{source: string, map: array<string, string>} Source: the built-in map's name, 'fallback', or 'filter' when the filter changed it.
 */
function roles( array $slugs ): array {
	$detected = detect( $slugs );

	/**
	 * Filters the section styles' role map.
	 *
	 * @param array<string, string> $map   Role => colour preset slug. Roles: surface, on-surface, muted, accent, on-accent, line.
	 * @param list<string>          $slugs The palette slugs the theme declares.
	 */
	$filtered = apply_filters( 'voyager_open_blocks_style_roles', $detected['map'], $slugs );

	// A filter can return anything: keep only known roles that name a declared slug.
	$map = [];
	foreach ( (array) $filtered as $role => $slug ) {
		$slug = Cast\to_string( $slug );
		if ( in_array( $role, ROLES, true ) && in_array( $slug, $slugs, true ) ) {
			$map[ (string) $role ] = $slug;
		}
	}

	return [
		'source' => $map === $detected['map'] ? $detected['name'] : 'filter',
		'map'    => $map,
	];
}

/**
 * Every role as a CSS colour.
 *
 * A role in the map is its preset's custom property. A role not in it falls
 * back to the theme's text (T) and background (B): surface B, on-surface T,
 * accent 75% T in B, on-accent B. muted and line sit on text, so they mix
 * currentColor into transparent: 65% and 20%, which on a plain section are
 * 65% and 20% T in B.
 *
 * @param array<string, string>                  $map  Role => slug, from roles().
 * @param array{text: string, background: string} $root From root_colors().
 * @return array<string, string> Role => CSS colour, for every role.
 */
function values( array $map, array $root ): array {
	$t = $root['text'];
	$b = $root['background'];

	$fallback = [
		'surface'    => $b,
		'on-surface' => $t,
		'muted'      => 'color-mix(in srgb, currentColor 65%, transparent)',
		'accent'     => sprintf( 'color-mix(in srgb, %s 75%%, %s)', $t, $b ),
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
 * dark text on Inverted. The fill button takes the style's button colours at
 * rest, on hover and on focus, where hover underlines the label rather than
 * changing a colour the theme chose for another background. An outline
 * button needs nothing here: core's outline style draws it in currentColor
 * with no fill, so it takes the section's text colour, and its hover stays
 * the theme's own.
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
	];
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
	$files = theme_json_files();
	$roles = roles( palette_slugs( $files ) );

	return [
		'source' => $roles['source'],
		'map'    => $roles['map'],
		'values' => values( $roles['map'], root_colors( [ ...user_styles(), ...$files ] ) ),
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
				'name'       => name( $style ),
				'label'      => $label,
				'style_data' => style_data( $style, $values ),
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
