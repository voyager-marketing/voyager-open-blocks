<?php
/**
 * The audit ability: one read of a saved post that lists what to fix before
 * it goes live, and saves nothing.
 *
 * Seven codes. Three are validate's warnings, found by validate's own
 * functions (validate.php): placeholder (image_warnings(), stand_in_warnings()
 * and list_stand_in_warnings()), missing_alt (image_warnings()) and
 * stale_fallback (stale_fallbacks()). Four are audit's own: typed_contact,
 * default_copy, contrast and empty_field, which reads the page as compose's
 * `empty` does (empty_site_data()). A section style's colours are left to
 * design-check, which renders them.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\SectionStyles;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The codes audit reports, in the order it looks for them.
 */
const AUDIT_CODES = [ 'typed_contact', 'default_copy', 'placeholder', 'missing_alt', 'stale_fallback', 'contrast', 'empty_field' ];

/**
 * The contrast a block's own text must reach on its own background: WCAG's
 * 4.5:1 for body text.
 */
const CONTRAST_MIN = 4.5;

/**
 * Register audit.
 */
function register_audit(): void {
	$codes = [
		'type'       => 'object',
		'properties' => array_fill_keys( AUDIT_CODES, [ 'type' => 'integer' ] ),
	];

	wp_register_ability(
		CATEGORY . '/audit',
		[
			'label'               => __( 'Audit a page before it goes live', 'voyager-open-blocks' ),
			'description'         => __( 'Lists what to fix on a saved post before it goes live, and saves nothing. Each finding has a code, block, path and a message saying how to fix it. typed_contact: text holding a phone number, email or street address, where a Site Data binding belongs. default_copy: a named text block holding the pack\'s text, which can stay if you gave it as copy. placeholder, missing_alt, stale_fallback: as validate warns them. contrast: a block\'s own text and background colours under 4.5:1. empty_field: a bound Site Data field that is empty.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [ 'post_id' => post_id_schema() ],
				'required'             => [ 'post_id' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer' ],
					'findings' => [
						'type'        => 'array',
						'description' => 'In block order. Empty when there is nothing to fix.',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'code'    => [
									'type' => 'string',
									'enum' => AUDIT_CODES,
								],
								'block'   => [
									'type'        => 'string',
									'description' => 'Block name.',
								],
								'path'    => [
									'type'        => 'string',
									'description' => 'Block path, such as "2/0/1".',
								],
								'message' => [
									'type'        => 'string',
									'description' => 'What is wrong and how to fix it.',
								],
							],
						],
					],
					'counts'   => [ 'description' => 'Findings by code, every code listed, 0 included.' ] + $codes,
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\audit',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			'meta'                => meta( true, true ),
		]
	);
}

/**
 * Execute audit.
 *
 * Reads the post's content, the site's Site Data and the theme's palette,
 * and writes nothing: no revision, no option, no meta.
 *
 * @param array<string, mixed>|null $input Input: post_id.
 * @return array{post_id: int, findings: list<array{code: string, block: string, path: string, message: string}>, counts: array<string, int>}|WP_Error
 */
function audit( $input = null ) {
	$post_id = Cast\to_int( assoc( $input )['post_id'] ?? 0 );
	$blocks  = BlockTree\load( $post_id );

	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}

	$user     = SectionStyles\user_styles();
	$files    = SectionStyles\theme_json_files();
	$palette  = SectionStyles\active_palette( $user, $files );
	$root     = SectionStyles\root_colors( array_merge( $user, $files ) );
	$site     = SiteData\values();
	$names    = block_names( $blocks );
	$findings = array_merge(
		typed_contact_findings(
			$blocks,
			[
				'phone'   => $site['phone'],
				'email'   => $site['email'],
				'address' => $site['address'],
			]
		),
		default_copy_findings( $blocks, shipped_copy() ),
		coded_findings( image_warnings( $blocks, placeholder_names() ), $names ),
		coded_findings( stand_in_warnings( $blocks, shipped_stand_ins() ), $names ),
		coded_findings( list_stand_in_warnings( $blocks, shipped_list_stand_ins(), static fn ( array $block ): bool => items_fall_back( $block, $post_id ) ), $names ),
		stale_fallback_findings( stale_fallbacks( $blocks ) ),
		contrast_findings( $blocks, $palette, SectionStyles\rgba( $root['background'], $palette ) ?? [ 255.0, 255.0, 255.0, 1.0 ] ),
		empty_field_findings( BlockTree\describe( $blocks ) )
	);

	return [
		'post_id'  => $post_id,
		'findings' => in_block_order( $findings ),
		'counts'   => audit_counts( $findings ),
	];
}

/**
 * Each block's name by its path.
 *
 * @param array<int, Block> $blocks Blocks.
 * @return array<string, string>
 */
function block_names( array $blocks ): array {
	$names = [];

	foreach ( BlockTree\describe( $blocks ) as $row ) {
		$names[ $row['path'] ] = $row['block'];
	}

	return $names;
}

/**
 * Findings sorted by their block's path, in document order. Findings on one
 * block keep the order of AUDIT_CODES, as usort() keeps equal items in order.
 *
 * @param list<array{code: string, block: string, path: string, message: string}> $findings Findings.
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function in_block_order( array $findings ): array {
	usort( $findings, static fn ( array $a, array $b ): int => BlockTree\compare_paths( $a['path'], $b['path'] ) );

	return $findings;
}

/**
 * How many findings carry each code, every code listed.
 *
 * @param list<array{code: string, block: string, path: string, message: string}> $findings Findings.
 * @return array<string, int>
 */
function audit_counts( array $findings ): array {
	$counts = array_fill_keys( AUDIT_CODES, 0 );

	foreach ( $findings as $finding ) {
		$counts[ $finding['code'] ] = ( $counts[ $finding['code'] ] ?? 0 ) + 1;
	}

	return $counts;
}

/**
 * validate's warnings as findings. Each warning starts with its code and ": ",
 * which becomes the finding's code; the rest is its message.
 *
 * @param list<array{path: string, warning: string}> $found Warnings and the path of each block, from image_warnings(), stand_in_warnings() or list_stand_in_warnings().
 * @param array<string, string>                      $names From block_names().
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function coded_findings( array $found, array $names ): array {
	$out = [];

	foreach ( $found as $item ) {
		$parts = explode( ': ', $item['warning'], 2 );

		$out[] = [
			'code'    => $parts[0],
			'block'   => $names[ $item['path'] ] ?? '',
			'path'    => $item['path'],
			'message' => $parts[1] ?? $item['warning'],
		];
	}

	return $out;
}

/**
 * Stale fallbacks as findings, each with the message validate warns.
 *
 * @param list<array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool, looks_like: string}> $stale From stale_fallbacks().
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function stale_fallback_findings( array $stale ): array {
	$out = [];

	foreach ( $stale as $item ) {
		$out[] = [
			'code'    => 'stale_fallback',
			'block'   => $item['block'],
			'path'    => $item['path'],
			'message' => substr( stale_fallback_warning( $item ), strlen( 'stale_fallback: ' ) ),
		];
	}

	return $out;
}

/**
 * The shapes of a phone number typed in text, each with the digits it holds.
 *
 * Stricter than looks_like(), which tells a phone number from a label in a
 * contact field's fallback but reads any run of 7 to 15 digits as one: in a
 * paragraph that would be "© 2015-2026", "2026-11-14", "12345-6789" or
 * "License 1234567". Each shape here is one only a phone number takes:
 *
 * - a leading + and a country code, 8 to 15 digits in all: +44 20 7946 0958;
 * - a North American number, its area code and exchange not starting with 0
 *   or 1, written with separators: (555) 555-0142, 555.555.0142,
 *   1-555-555-0142;
 * - a national number starting with its trunk 0, 10 or 11 digits in groups:
 *   020 7946 0958, 0412 345 678.
 *
 * A number written as one run of digits, and a local number with no area
 * code, are not read as phone numbers here. Typed as the site's own number,
 * typed_contact() still finds them by their digits.
 */
const PHONE_SHAPES = [
	[ '/(?<![\w+])\+\d[\d\s().-]*\d/', 8, 15 ],
	[ '/(?<![\w.-])(?:1[\s.-]?)?(?:\([2-9]\d{2}\)\s?|[2-9]\d{2}[\s.-])[2-9]\d{2}[\s.-]\d{4}(?![\w-])/', 10, 11 ],
	[ '/(?<![\w.-])0\d+(?:[\s.-]\d+)+(?![\w-])/', 10, 11 ],
];

/**
 * Whether a text holds a phone number in one of PHONE_SHAPES.
 *
 * @param string $text Text.
 */
function phone_shaped( string $text ): bool {
	foreach ( PHONE_SHAPES as [ $pattern, $min, $max ] ) {
		preg_match_all( $pattern, $text, $found );

		foreach ( $found[0] as $match ) {
			$digits = strlen( (string) preg_replace( '/\D/', '', $match ) );

			if ( $digits >= $min && $digits <= $max ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Whether a text holds a phone number whose digits are the site's.
 *
 * Each run of digits and separators, as looks_like() reads one, is compared
 * by its digits alone, so "(555) 010-0199", "555.010.0199" and
 * "+1 555 010 0199" are one number. A run matches when it holds the site's
 * digits after a country code, or the last seven or more of them, a number
 * typed without its area code.
 *
 * @param string $text  Text.
 * @param string $phone The site's phone number, from Site Data.
 */
function holds_phone( string $text, string $phone ): bool {
	$site = (string) preg_replace( '/\D/', '', $phone );

	if ( strlen( $site ) < 7 ) {
		return false;
	}

	preg_match_all( '/\+?\d[\d\s().-]*\d/', $text, $runs );

	foreach ( $runs[0] as $run ) {
		$digits = (string) preg_replace( '/\D/', '', $run );

		if ( strlen( $digits ) >= 7 && ( str_ends_with( $digits, $site ) || str_ends_with( $site, $digits ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * A text with its whitespace collapsed and its letters lowercased, for comparing two.
 *
 * @param string $text Text.
 */
function folded( string $text ): string {
	return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
}

/**
 * The contact details typed into a text, as the Site Data keys to bind instead.
 *
 * The site's own phone number (holds_phone()), email address or street
 * address, from Site Data, or any phone number (phone_shaped()) or email
 * address (looks_like()). A street address only counts as the site's own:
 * no shape tells an address from other words. A street address under six
 * characters is not looked for, so a stray digit or word cannot match it.
 *
 * @param string                                             $text Text.
 * @param array{phone: string, email: string, address: string} $site The site's phone, email and street address, '' when empty.
 * @return array<'phone'|'email'|'address', bool> Key => whether it is the site's own value. Empty when the text holds none.
 */
function typed_contact( string $text, array $site ): array {
	$found = [];

	if ( holds_phone( $text, $site['phone'] ) ) {
		$found['phone'] = true;
	} elseif ( phone_shaped( $text ) ) {
		$found['phone'] = false;
	}

	$email = folded( $site['email'] );

	if ( '' !== $email && str_contains( folded( $text ), $email ) ) {
		$found['email'] = true;
	} elseif ( 'email' === looks_like( $text ) ) {
		$found['email'] = false;
	}

	$address = folded( $site['address'] );

	if ( mb_strlen( $address ) >= 6 && str_contains( folded( $text ), $address ) ) {
		$found['address'] = true;
	}

	return $found;
}

/**
 * Text blocks whose own text holds a phone number, an email address or the
 * site's street address, typed in where a binding belongs, as findings.
 *
 * Only a heading, paragraph, button, list item or details block whose text
 * is its own: one bound on that text is left to stale_fallbacks(), as its
 * text is then a fallback. A block bound on anything else, such as a
 * button bound to phone_link, still shows its own text, so it is checked.
 *
 * @param array<int, Block>                                    $blocks Blocks.
 * @param array{phone: string, email: string, address: string} $site   The site's phone, email and street address, from Site Data.
 * @param string                                               $prefix Path of the parent, '' at the top.
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function typed_contact_findings( array $blocks, array $site, string $prefix = '' ): array {
	$out   = [];
	$words = [
		'phone'   => [ 'a phone number', 'the site\'s phone number' ],
		'email'   => [ 'an email address', 'the site\'s email address' ],
		'address' => [ 'a street address', 'the site\'s street address' ],
	];

	foreach ( $blocks as $index => $block ) {
		$path      = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$name      = (string) $block['blockName'];
		$attribute = BlockTree\TEXT_ATTRIBUTES[ $name ] ?? null;
		$found     = null === $attribute || isset( bound_attributes( BlockTree\metadata( $block )['bindings'] ?? null )[ $attribute ] )
			? []
			: typed_contact( BlockTree\own_text( $block ), $site );

		if ( [] !== $found ) {
			$what = [];
			$keys = [];

			foreach ( $found as $key => $own ) {
				$what[] = $words[ $key ][ $own ? 1 : 0 ];
				$keys[] = sprintf( '"%s"', $key );
			}

			$out[] = [
				'code'    => 'typed_contact',
				'block'   => $name,
				'path'    => $path,
				'message' => sprintf(
					'%1$s%2$s at %3$s holds %4$s, typed in, which stays as it is when the details change. Show it from Site Data instead: a block bound to %5$s with bind, such as the contact details block, and set the value under Settings > Site Data.',
					$name,
					label_of( $block ),
					$path,
					implode( ' and ', $what ),
					implode( ' and ', $keys )
				),
			];
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $out, ...typed_contact_findings( $inner, $site, $path ) );
	}

	return $out;
}

/**
 * A block's label as it follows its name in a message: ` "Heading"`, or ''.
 *
 * @param Block $block Parsed block.
 */
function label_of( array $block ): string {
	$label = BlockTree\metadata( $block )['name'] ?? null;

	return is_string( $label ) && '' !== $label ? sprintf( ' "%s"', $label ) : '';
}

/**
 * The named text blocks in a block tree whose text is their own, outside
 * numbered items.
 *
 * A numbered item's slots (item_slots()) are left to stand_in_warnings(),
 * which lets an item's title off when the rest of it is filled, and a block
 * bound on its text is left to stale_fallbacks().
 *
 * @param array<int, Block> $blocks Blocks.
 * @return list<array{path: string, block: string, label: string, text: string}>
 */
function named_texts( array $blocks ): array {
	$slots = array_flip( array_column( item_slots( $blocks ), 'path' ) );
	$out   = [];

	foreach ( named_texts_in( $blocks ) as $row ) {
		if ( ! isset( $slots[ $row['path'] ] ) ) {
			$out[] = $row;
		}
	}

	return $out;
}

/**
 * Every named text block whose text is its own and not empty, at any depth.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param string            $prefix Path of the parent, '' at the top.
 * @return list<array{path: string, block: string, label: string, text: string}>
 */
function named_texts_in( array $blocks, string $prefix = '' ): array {
	$out = [];

	foreach ( $blocks as $index => $block ) {
		$path      = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$name      = (string) $block['blockName'];
		$metadata  = BlockTree\metadata( $block );
		$label     = $metadata['name'] ?? null;
		$attribute = BlockTree\TEXT_ATTRIBUTES[ $name ] ?? null;
		$text      = BlockTree\own_text( $block );

		if ( null !== $attribute && is_string( $label ) && '' !== $label && '' !== $text && ! isset( bound_attributes( $metadata['bindings'] ?? null )[ $attribute ] ) ) {
			$out[] = [
				'path'  => $path,
				'block' => $name,
				'label' => $label,
				'text'  => $text,
			];
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $out, ...named_texts_in( $inner, $path ) );
	}

	return $out;
}

/**
 * The text the pack ships in each named text block outside numbered items,
 * by label.
 *
 * @param array<int, Block> $blocks The pack's patterns' blocks, as pack_pattern_blocks() gives them.
 * @return array<string, list<string>> Label => texts, such as "Heading" => [ "Say what you do and who it is for", … ].
 */
function default_copy_index( array $blocks ): array {
	$index = [];

	foreach ( named_texts( $blocks ) as $row ) {
		if ( ! in_array( $row['text'], $index[ $row['label'] ] ?? [], true ) ) {
			$index[ $row['label'] ][] = $row['text'];
		}
	}

	return $index;
}

/**
 * The default copy index of the patterns this site registers, built once per request.
 *
 * @return array<string, list<string>>
 */
function shipped_copy(): array {
	/** @var array<string, list<string>>|null $index */
	static $index = null;

	if ( null === $index ) {
		$index = default_copy_index( pack_pattern_blocks() );
	}

	return $index;
}

/**
 * Named text blocks still holding the text the pack ships under their label,
 * as findings.
 *
 * A block matches by its label and its whole text, never by its text alone,
 * so the same words under another label are not flagged.
 *
 * audit reads the saved post and nothing else, and insert and compose write
 * copy as plain text (BlockTree\set_copy()), so the pack's words left in
 * place and the same words given as copy read alike. Some copy rules even
 * suggest them, such as "Call us" for the contact section's call button.
 * The finding is kept for both, and its message says how the caller tells
 * them apart: text it gave as copy for that label can stay.
 *
 * @param array<int, Block>           $blocks The page's blocks.
 * @param array<string, list<string>> $index  From shipped_copy().
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function default_copy_findings( array $blocks, array $index ): array {
	$out = [];

	foreach ( named_texts( $blocks ) as $row ) {
		if ( ! in_array( $row['text'], $index[ $row['label'] ] ?? [], true ) ) {
			continue;
		}

		$out[] = [
			'code'    => 'default_copy',
			'block'   => $row['block'],
			'path'    => $row['path'],
			'message' => sprintf( '%1$s "%2$s" at %3$s holds "%4$s", the text the pack ships there. If you gave that text as copy for "%2$s", it is your choice and can stay. If not, it was left in place: replace it in the editor with the site\'s own words, adding no claim the business has not given, such as a free estimate it never offered, or remove the block.', $row['block'], $row['label'], $row['path'], $row['text'] ),
		];
	}

	return $out;
}

/**
 * The text and background colours a block sets itself, as CSS.
 *
 * A preset (textColor, backgroundColor) is its custom property; a custom
 * value (style.color.text, style.color.background) is read as
 * SectionStyles\css_value() reads a theme.json colour, and wins over a
 * preset, as its inline style does on the page. A gradient is not a colour.
 *
 * @param Block $block Parsed block.
 * @return array{text: string, background: string} '' for a colour the block does not set.
 */
function own_colors( array $block ): array {
	$attrs  = $block['attrs'];
	$style  = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : [];
	$color  = is_array( $style['color'] ?? null ) ? $style['color'] : [];
	$preset = static fn ( mixed $slug ): string => is_string( $slug ) && '' !== $slug ? 'var(--wp--preset--color--' . $slug . ')' : '';
	$custom = static fn ( mixed $value ): string => is_string( $value ) ? SectionStyles\css_value( $value ) : '';
	$text   = $custom( $color['text'] ?? null );
	$back   = $custom( $color['background'] ?? null );

	return [
		'text'       => '' === $text ? $preset( $attrs['textColor'] ?? null ) : $text,
		'background' => '' === $back ? $preset( $attrs['backgroundColor'] ?? null ) : $back,
	];
}

/**
 * A colour as a message names it: a preset by its slug, a custom value as written.
 *
 * @param string $css A colour from own_colors().
 */
function color_name( string $css ): string {
	return 1 === preg_match( '/^var\(--wp--preset--color--(.+)\)$/', $css, $slug ) ? sprintf( 'the "%s" preset', $slug[1] ) : $css;
}

/**
 * Blocks that set both their own text and background colour, reading under
 * CONTRAST_MIN, as findings.
 *
 * Measured with SectionStyles\rgba() and contrast(), the background on the
 * page's. A colour rgba() cannot read, such as a preset the palette does not
 * hold or a color-mix(), is not judged, and neither is a block that sets
 * one colour only: it takes the other from its section, whose style
 * design-check renders.
 *
 * @param array<int, Block>                 $blocks  Blocks.
 * @param array<string, string>             $palette Slug => colour, from SectionStyles\active_palette().
 * @param array{float, float, float, float} $page    The page's background.
 * @param string                            $prefix  Path of the parent, '' at the top.
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function contrast_findings( array $blocks, array $palette, array $page, string $prefix = '' ): array {
	$out = [];

	foreach ( $blocks as $index => $block ) {
		$path       = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$colors     = own_colors( $block );
		$text       = '' === $colors['text'] ? null : SectionStyles\rgba( $colors['text'], $palette );
		$background = '' === $colors['background'] ? null : SectionStyles\rgba( $colors['background'], $palette );

		if ( null !== $text && null !== $background ) {
			$ratio = SectionStyles\contrast( $text, $background, $page );

			if ( $ratio < CONTRAST_MIN ) {
				$out[] = [
					'code'    => 'contrast',
					'block'   => (string) $block['blockName'],
					'path'    => $path,
					'message' => sprintf(
						'%1$s%2$s at %3$s sets its text to %4$s on %5$s, which reads %6$s:1, under the 4.5:1 text needs. Change either colour in the editor, or clear both so the block takes its section\'s.',
						(string) $block['blockName'],
						label_of( $block ),
						$path,
						color_name( $colors['text'] ),
						color_name( $colors['background'] ),
						number_format( floor( $ratio * 100 ) / 100, 2 )
					),
				];
			}
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $out, ...contrast_findings( $inner, $palette, $page, $path ) );
	}

	return $out;
}

/**
 * Each Site Data field the page binds that is empty now, as a finding on the
 * first block bound to it.
 *
 * The fields are the ones compose returns in `empty` (empty_site_data()), read
 * as the binding reads them.
 *
 * @param array<int, Row> $rows The page's blocks as BlockTree\describe() lists them.
 * @return list<array{code: string, block: string, path: string, message: string}>
 */
function empty_field_findings( array $rows ): array {
	$empty = empty_site_data( $rows );
	$bound = [];

	foreach ( $rows as $row ) {
		foreach ( (array) ( $row['bindings'] ?? [] ) as $binding ) {
			$binding = is_array( $binding ) ? $binding : [];
			$args    = is_array( $binding['args'] ?? null ) ? $binding['args'] : [];
			$key     = Cast\to_string( $args['key'] ?? '' );

			if ( SiteData\SOURCE === ( $binding['source'] ?? null ) && in_array( $key, $empty, true ) && ! in_array( $row, $bound[ $key ] ?? [], true ) ) {
				$bound[ $key ][] = $row;
			}
		}
	}

	$out = [];

	foreach ( $empty as $key ) {
		$rows_for = $bound[ $key ] ?? [];

		if ( [] === $rows_for ) {
			continue;
		}

		$first  = $rows_for[0];
		$others = count( $rows_for ) - 1;
		$out[]  = [
			'code'    => 'empty_field',
			'block'   => $first['block'],
			'path'    => $first['path'],
			'message' => sprintf(
				'Site Data\'s "%1$s" field (%2$s) is empty, so %3$s%4$s at %5$s shows its own text%6$s. Fill it in under Settings > Site Data.',
				SiteData\fields()[ $key ]['label'] ?? $key,
				$key,
				$first['block'],
				isset( $first['label'] ) ? sprintf( ' "%s"', $first['label'] ) : '',
				$first['path'],
				0 === $others ? '' : sprintf( ', and so %1$s %2$d more %3$s bound to it', 1 === $others ? 'does' : 'do', $others, 1 === $others ? 'block' : 'blocks' )
			),
		];
	}

	return $out;
}
