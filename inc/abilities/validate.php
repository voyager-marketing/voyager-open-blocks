<?php
/**
 * The validate ability: block markup, or a saved post, checked without saving anything.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BindingsEditor;
use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
use VoyagerOpenBlocks\Patterns;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register validate.
 */
function register_validate(): void {
	$messages = [
		'type'  => 'array',
		'items' => [ 'type' => 'string' ],
	];

	wp_register_ability(
		CATEGORY . '/validate',
		[
			'label'               => __( 'Validate block markup', 'voyager-open-blocks' ),
			'description'         => __( 'Checks block markup, or a saved post, and saves nothing. For each block, by path: its type is registered, its comment attributes match block.json, its bindings name a bindable attribute and args get-catalog lists, and it survives a parse and serialize round trip. It cannot compare saved HTML with each block\'s save(), so where block-runner validate is not run, say the HTML was not checked. Warnings leave valid true. placeholder: each pack placeholder image, as an image, a media and text image or a cover\'s background, the contact section\'s form stand-in, and each text slot of a numbered item, such as "Credential 4", still holding the stand-in text the pack ships there, such as "Your fourth credential". An item\'s short first slot, such as a service\'s heading, is let off when every other slot holds the site\'s own text. missing_alt: any other image with no alt text; never a cover. stale_fallback: a block bound to a phone or email field whose own text looks like one, which visitors see when the field is empty. Tell the user to fix these in the editor before the page goes live, and to set each new cover or media and text image\'s focal point on its subject.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => [],
				'properties'           => [
					'markup'    => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Block markup to check, such as a section you were given. Not with post_id. Over REST, markup past the URL limit, often 8 KB, fails: check one section at a time, or pass post_id.',
					],
					'post_id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'A saved post or page to check.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'The post type binding args are checked for: by default the post\'s, else page. For a template, the type it shows.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'valid'       => [
						'type'        => 'boolean',
						'description' => 'True when no block has an error. Warnings do not change it.',
					],
					'post_id'     => [ 'type' => 'integer' ],
					'post_type'   => [
						'type'        => 'string',
						'description' => 'Post type the binding args were checked against.',
					],
					'round_trip'  => [
						'type'        => 'boolean',
						'description' => 'True when every block is written exactly as WordPress writes it back, apart from the whitespace between top-level blocks.',
					],
					'blocks'      => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'path'     => [
									'type'        => 'string',
									'description' => 'Block path, such as "2/0/1".',
								],
								'block'    => [
									'type'        => 'string',
									'description' => 'Block name. core/freeform is HTML outside any block.',
								],
								'label'    => [
									'type'        => 'string',
									'description' => 'metadata.name, when the block has one.',
								],
								'errors'   => $messages,
								'warnings' => $messages,
							],
						],
					],
					'errors'      => $messages,
					'warnings'    => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Every block\'s warnings. Each starts with its block, or with a code: stale_fallback (a bound block\'s fallback looks like a stale phone number or email address), placeholder (a placeholder the pack ships is still on the page, including a cover\'s background and the stand-in text of a numbered item, such as "Your fourth credential") or missing_alt (a core/image or core/media-text image has no alt text; a cover never gets one). A code is followed by the block and its path.',
					],
					'markup'      => [
						'type'        => 'string',
						'description' => 'The markup as WordPress would save it. Compare it with yours where round_trip failed.',
					],
					'save_output' => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\validate',
			'permission_callback' => __NAMESPACE__ . '\can_validate',
			'meta'                => meta( true, true ),
		]
	);
}

/**
 * Execute validate.
 *
 * @param array<string, mixed>|null $input Input: markup, or post_id; post_type optional.
 * @return array<string, mixed>|WP_Error
 */
function validate( $input = null ) {
	$input     = assoc( $input );
	$has_post  = isset( $input['post_id'] );
	$post_type = Cast\to_string( $input['post_type'] ?? '' );
	$out       = [];

	if ( isset( $input['markup'] ) === $has_post ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_markup',
			$has_post ? 'Pass markup or post_id, not both.' : 'Pass markup (block markup to check) or post_id (a saved post to check).',
			[ 'status' => 400 ]
		);
	}

	if ( $has_post ) {
		$post_id = Cast\to_int( $input['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'voyager_open_blocks_invalid_post_id', sprintf( 'Post %d does not exist.', $post_id ), [ 'status' => 404 ] );
		}

		$markup    = (string) $post->post_content;
		$post_type = '' === $post_type ? $post->post_type : $post_type;
		$out       = [ 'post_id' => $post_id ];
	} else {
		$markup = Cast\to_string( $input['markup'] );
	}

	$blocks = BlockTree\parse( $markup );
	$report = BlockTree\validate_markup( $markup, '' === $post_type ? 'page' : $post_type );
	$report = with_stale_fallbacks( $report, stale_fallbacks( $blocks, shipped_fallbacks() ) );
	$report = with_warnings( $report, image_warnings( $blocks, placeholder_names() ) );
	$report = with_warnings( $report, stand_in_warnings( $blocks, shipped_stand_ins() ) );

	return $out + $report + [ 'save_output' => SAVE_OUTPUT ];
}

/**
 * Name parts that make a key a phone key or an email key, such as Site Data's
 * phone, phone_link, email and email_link, or post meta like office_phone,
 * mobile, fax, whatsapp, telefono or correo. Only their fallbacks can be stale
 * copies of a phone number or an email address. Words from other languages
 * are the common ones for a phone (telefon, telefono, telefone, movil) and an
 * email address (correo, courriel), and the messaging services a number is
 * given for (whatsapp, sms). Each counts only as a whole part, so telegram,
 * telefonica and correos name neither.
 */
const CONTACT_PARTS = [
	'phone' => [ 'phone', 'telephone', 'tel', 'mobile', 'cell', 'cellphone', 'fax', 'telefon', 'telefono', 'telefone', 'movil', 'whatsapp', 'sms' ],
	'email' => [ 'email', 'mail', 'correo', 'courriel' ],
];

/**
 * What a key names: a phone, an email address, both or neither.
 *
 * The key is split into parts at anything but a letter or a digit, between a
 * letter and a digit, and before a capital that follows a small letter, and
 * only a whole part counts: tel names a phone and hotel does not, and
 * mailing_address names neither.
 *
 * @param string $key Binding key.
 * @return list<'phone'|'email'>
 */
function contact_kinds( string $key ): array {
	$split = (string) preg_replace( [ '/([a-z])([A-Z])/', '/([a-zA-Z])([0-9])/', '/([0-9])([a-zA-Z])/' ], '$1 $2', $key );
	$parts = preg_split( '/[^a-z0-9]+/', strtolower( $split ), -1, PREG_SPLIT_NO_EMPTY );
	$parts = is_array( $parts ) ? $parts : [];
	$kinds = [];

	foreach ( CONTACT_PARTS as $kind => $names ) {
		if ( [] !== array_intersect( $parts, $names ) ) {
			$kinds[] = $kind;
		}
	}

	return $kinds;
}

/**
 * What a fallback looks like: 'email', 'phone', or null for neither.
 *
 * Only fallbacks of keys that contact_kinds() names are tested, so this tells
 * a phone number or an email address from a label such as "Call us", not
 * from a date or a year range. An email is anything shaped x@y.z. A phone number
 * is a run of digits and separators (spaces, dots, hyphens, parentheses, a
 * leading +) holding 7 to 15 digits, as a tel: link does too.
 *
 * @param string $text Fallback text.
 */
function looks_like( string $text ): ?string {
	if ( 1 === preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/', $text ) ) {
		return 'email';
	}

	preg_match_all( '/\+?\d[\d\s().-]*\d/', $text, $runs );

	foreach ( $runs[0] as $run ) {
		$digits = (int) preg_match_all( '/\d/', $run );

		if ( $digits >= 7 && $digits <= 15 ) {
			return 'phone';
		}
	}

	return null;
}

/**
 * Every bound attribute's fallback, by block path.
 *
 * The fallback is what WordPress renders when the source returns nothing:
 * the block's own text for a heading's, paragraph's or list item's content
 * or a button's text, else the attribute's value in the block comment. An
 * attribute read from other saved HTML, such as a button's link, is skipped.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param string            $prefix Path of the parent, '' at the top.
 * @return list<array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool}>
 */
function bound_fallbacks( array $blocks, string $prefix = '' ): array {
	$out = [];

	foreach ( $blocks as $index => $block ) {
		$path     = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$name     = (string) $block['blockName'];
		$metadata = BlockTree\metadata( $block );

		foreach ( bound_attributes( $metadata['bindings'] ?? [] ) as $attribute => $binding ) {
			$in_html  = ( BlockTree\TEXT_ATTRIBUTES[ $name ] ?? null ) === $attribute;
			$value    = $block['attrs'][ $attribute ] ?? null;
			$fallback = $in_html ? BlockTree\own_text( $block ) : ( is_string( $value ) ? trim( $value ) : '' );

			if ( '' === $fallback ) {
				continue;
			}

			$out[] = [
				'path'      => $path,
				'block'     => $name,
				'label'     => isset( $metadata['name'] ) && is_string( $metadata['name'] ) ? $metadata['name'] : '',
				'attribute' => $attribute,
				'source'    => $binding['source'],
				'key'       => $binding['key'],
				'fallback'  => $fallback,
				'in_html'   => $in_html,
			];
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $out, ...bound_fallbacks( $inner, $path ) );
	}

	return $out;
}

/**
 * The fallbacks the pack's own patterns ship, by `<source>:<key>`.
 *
 * These are placeholders that were never a site's real value, such as the
 * contact section's sample phone number, so they are not stale.
 *
 * @return array<string, list<string>>
 */
function shipped_fallbacks(): array {
	/** @var array<string, list<string>>|null $shipped */
	static $shipped = null;

	if ( null === $shipped ) {
		$shipped = fallback_index( bound_fallbacks( pack_pattern_blocks() ) );
	}

	return $shipped;
}

/**
 * The blocks of every pattern the pack registers, parsed once per request.
 *
 * @return array<int, Block>
 */
function pack_pattern_blocks(): array {
	/** @var array<int, Block>|null $blocks */
	static $blocks = null;

	if ( null === $blocks ) {
		$blocks = [];

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( str_starts_with( Cast\to_string( $pattern['name'] ?? '' ), Catalog\PREFIX ) ) {
				array_push( $blocks, ...BlockTree\parse( Cast\to_string( $pattern['content'] ?? '' ) ) );
			}
		}
	}

	return $blocks;
}

/**
 * A label that numbers an item of a section, such as "Credential 4", "Review
 * 2", "Service 3" or "Step 1": its kind, a space and a number.
 */
const ITEM_LABEL = '/^(.*\S) ([0-9]+)$/';

/**
 * The most characters an item's title can hold and still be let off as one a
 * real item shares with the pack's (stand_in_warnings()): a heading, a
 * question or a rating, never a sentence of body text. The pack's heading,
 * name and rating slots allow 40 at most in their copy rules, and its lines,
 * answers and quotes ship longer.
 */
const TITLE_MAX = 40;

/**
 * The text slots of every numbered item in a block tree.
 *
 * An item is a named block whose label numbers it (ITEM_LABEL). Its text
 * slots are the item itself when it is a text block (BlockTree\TEXT_ATTRIBUTES),
 * as a proof strip's "Credential 4" is, and the named text blocks inside it
 * whose labels start with its own and a space, as repeat names them ("Review
 * 2 quote" inside "Review 2"). A slot whose text a binding supplies is left
 * out: its text is a fallback, which stale_fallbacks() checks.
 *
 * @param array<int, Block> $blocks Blocks.
 * @param string            $prefix Path of the parent, '' at the top.
 * @return list<array{item: string, item_path: string, kind: string, suffix: string, path: string, block: string, label: string, text: string}>
 */
function item_slots( array $blocks, string $prefix = '' ): array {
	$out = [];

	foreach ( $blocks as $index => $block ) {
		$path  = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$label = BlockTree\metadata( $block )['name'] ?? null;

		if ( is_string( $label ) && 1 === preg_match( ITEM_LABEL, $label, $match ) ) {
			foreach ( text_slots( $block, $path, $label ) as $slot ) {
				$out[] = [
					'item'      => $label,
					'item_path' => $path,
					'kind'      => $match[1],
					'suffix'    => substr( $slot['label'], strlen( $label ) ),
				] + $slot;
			}
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $out, ...item_slots( $inner, $path ) );
	}

	return $out;
}

/**
 * The unbound text blocks in a block and inside it whose label is an item's
 * own, or starts with it and a space.
 *
 * @param Block  $block The item's block, or a block inside it.
 * @param string $path  Its path.
 * @param string $item  The item's label.
 * @return list<array{path: string, block: string, label: string, text: string}>
 */
function text_slots( array $block, string $path, string $item ): array {
	$out       = [];
	$name      = (string) $block['blockName'];
	$metadata  = BlockTree\metadata( $block );
	$label     = $metadata['name'] ?? null;
	$attribute = BlockTree\TEXT_ATTRIBUTES[ $name ] ?? null;
	$bindings  = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];

	if ( null !== $attribute && is_string( $label ) && ( $label === $item || str_starts_with( $label, $item . ' ' ) ) && ! isset( $bindings[ $attribute ] ) ) {
		$out[] = [
			'path'  => $path,
			'block' => $name,
			'label' => $label,
			'text'  => BlockTree\own_text( $block ),
		];
	}

	/** @var array<int, Block> $inner */
	$inner = $block['innerBlocks'];
	foreach ( $inner as $index => $child ) {
		array_push( $out, ...text_slots( $child, $path . '/' . $index, $item ) );
	}

	return $out;
}

/**
 * The text the pack ships in each slot of each kind of numbered item.
 *
 * @param array<int, Block> $blocks The pack's patterns' blocks, as pack_pattern_blocks() gives them.
 * @return array<string, array<string, list<string>>> Kind => slot => texts, such as "Review" => " quote" => [ "A real customer's words go here, …" ]. A slot that is the item itself is "".
 */
function stand_in_index( array $blocks ): array {
	$index = [];

	foreach ( item_slots( $blocks ) as $slot ) {
		if ( '' === $slot['text'] ) {
			continue;
		}

		$texts = $index[ $slot['kind'] ][ $slot['suffix'] ] ?? [];
		if ( ! in_array( $slot['text'], $texts, true ) ) {
			$texts[] = $slot['text'];
		}
		$index[ $slot['kind'] ][ $slot['suffix'] ] = $texts;
	}

	return $index;
}

/**
 * The stand-in index of the patterns this site registers, built once per request.
 *
 * @return array<string, array<string, list<string>>>
 */
function shipped_stand_ins(): array {
	/** @var array<string, array<string, list<string>>>|null $index */
	static $index = null;

	if ( null === $index ) {
		$index = stand_in_index( pack_pattern_blocks() );
	}

	return $index;
}

/**
 * The stand-in text left on a page, as placeholder warnings, each with the
 * path of its block.
 *
 * A slot of a numbered item (item_slots()) holds a stand-in when its whole
 * text is one the pack ships in that slot of that kind of item: "Your fourth
 * credential" in a "Credential 2", or "Customer name" in the "Review 4 name"
 * a repeat made. Slots are matched by kind and slot, never by text alone, so
 * the same words in another block are not flagged.
 *
 * One slot is let off: an item's title, when every other slot of the item
 * holds the site's own text. The title is the item's first text slot, and a
 * short one (TITLE_MAX), such as a service's heading, a step's heading, a
 * question or a review's rating, and a real item can share it word for word
 * with the pack's: a five-star review keeps "Rated 5 out of 5", and a
 * business's first service may be called Repairs. Any other slot left at the
 * pack's text is flagged however much of its item is filled: a service's or a
 * step's line, an answer, a quote, a customer's name.
 *
 * @param array<int, Block>                          $blocks The page's blocks.
 * @param array<string, array<string, list<string>>> $index  From shipped_stand_ins().
 * @return list<array{path: string, warning: string}>
 */
function stand_in_warnings( array $blocks, array $index ): array {
	$items = [];

	foreach ( item_slots( $blocks ) as $slot ) {
		$items[ $slot['item_path'] ][] = $slot + [ 'stand_in' => in_array( $slot['text'], $index[ $slot['kind'] ][ $slot['suffix'] ] ?? [], true ) ];
	}

	$out = [];

	foreach ( $items as $slots ) {
		$left = array_values( array_filter( $slots, static fn ( array $slot ): bool => $slot['stand_in'] ) );

		// item_slots() lists an item's slots in document order, so its title comes first.
		$title_alone = 1 === count( $left ) && count( $slots ) > 1
			&& $left[0]['path'] === $slots[0]['path']
			&& mb_strlen( $left[0]['text'] ) <= TITLE_MAX;

		if ( [] === $left || $title_alone ) {
			continue;
		}

		foreach ( $left as $slot ) {
			$out[] = [
				'path'    => $slot['path'],
				'warning' => sprintf(
					'placeholder: %1$s "%2$s" at %3$s holds "%4$s", the stand-in text the pack ships there. Replace it with the site\'s own words, or remove "%5$s".',
					$slot['block'],
					$slot['label'],
					$slot['path'],
					$slot['text'],
					$slot['item']
				),
			];
		}
	}

	return $out;
}

/**
 * Fallbacks grouped by `<source>:<key>`.
 *
 * @param list<array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool}> $fallbacks From bound_fallbacks().
 * @return array<string, list<string>>
 */
function fallback_index( array $fallbacks ): array {
	$index = [];

	foreach ( $fallbacks as $fallback ) {
		$index[ $fallback['source'] . ':' . $fallback['key'] ][] = $fallback['fallback'];
	}

	return $index;
}

/**
 * Blocks bound to a phone or email key whose fallback looks like one.
 *
 * A fallback stays in the post when the real value changes, and visitors see
 * it whenever the source returns nothing, so a phone number there is a stale
 * copy waiting to show. Only what a key names is checked (contact_kinds()): a
 * phone number under a phone key, an email address under an email key. A
 * block bound to any other key, such as copyright, founded or
 * mailing_address, may hold digits that look like a phone number
 * ("© 2015-2026", "License #1234567", "PO Box 1234567") and is never a stale
 * copy of one. A fallback the pack's patterns ship for the same source and
 * key is a placeholder and is left out.
 *
 * @param array<int, Block>           $blocks  Blocks.
 * @param array<string, list<string>> $shipped From shipped_fallbacks().
 * @return list<array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool, looks_like: string}>
 */
function stale_fallbacks( array $blocks, array $shipped ): array {
	$stale = [];

	foreach ( bound_fallbacks( $blocks ) as $fallback ) {
		$kinds = contact_kinds( $fallback['key'] );

		if ( [] === $kinds ) {
			continue;
		}

		$kind = looks_like( $fallback['fallback'] );

		if ( null === $kind || ! in_array( $kind, $kinds, true ) || in_array( $fallback['fallback'], $shipped[ $fallback['source'] . ':' . $fallback['key'] ] ?? [], true ) ) {
			continue;
		}

		$stale[] = $fallback + [ 'looks_like' => $kind ];
	}

	return $stale;
}

/**
 * The warning for one stale fallback. It starts with its code, stale_fallback.
 *
 * @param array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool, looks_like: string} $stale From stale_fallbacks().
 */
function stale_fallback_warning( array $stale ): string {
	$fix = $stale['in_html']
		? sprintf( 'Replace it in the editor with a label, such as "%s".', 'email' === $stale['looks_like'] ? 'Email us' : 'Call us' )
		: sprintf( 'Remove it with configure, passing null for "%s".', $stale['attribute'] );

	return sprintf(
		'stale_fallback: %s%s at %s, binding "%s" (%s%s): its fallback "%s" looks like %s. Visitors see the fallback whenever the source returns nothing, and it stays in the post when the value changes. %s',
		$stale['block'],
		'' === $stale['label'] ? '' : sprintf( ' "%s"', $stale['label'] ),
		$stale['path'],
		$stale['attribute'],
		$stale['source'],
		'' === $stale['key'] ? '' : sprintf( ', key "%s"', $stale['key'] ),
		$stale['fallback'],
		'email' === $stale['looks_like'] ? 'an email address' : 'a phone number',
		$fix
	);
}

/**
 * A validation report with each stale fallback warned at its block, and in the
 * report's warnings. Warnings do not change `valid`.
 *
 * @param array{valid: bool, post_type: string, round_trip: bool, blocks: array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>, errors: string[], warnings: string[], markup: string} $report From BlockTree\validate_markup().
 * @param list<array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool, looks_like: string}>     $stale  From stale_fallbacks().
 * @return array{valid: bool, post_type: string, round_trip: bool, blocks: array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>, errors: string[], warnings: string[], markup: string}
 */
function with_stale_fallbacks( array $report, array $stale ): array {
	$found = [];

	foreach ( $stale as $item ) {
		$found[] = [
			'path'    => $item['path'],
			'warning' => stale_fallback_warning( $item ),
		];
	}

	return with_warnings( $report, $found );
}

/**
 * A validation report with each warning added at its block, after the
 * block's own, and the report's warnings rebuilt from every block's, in
 * block order. Warnings do not change `valid`.
 *
 * @param array{valid: bool, post_type: string, round_trip: bool, blocks: array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>, errors: string[], warnings: string[], markup: string} $report From BlockTree\validate_markup().
 * @param list<array{path: string, warning: string}>                                                                                                                                                                 $found  Warnings and the path of the block each is about.
 * @return array{valid: bool, post_type: string, round_trip: bool, blocks: array<int, array{path: string, block: string, label?: string, errors: string[], warnings: string[]}>, errors: string[], warnings: string[], markup: string}
 */
function with_warnings( array $report, array $found ): array {
	if ( [] === $found ) {
		return $report;
	}

	$by_path = [];

	foreach ( $found as $item ) {
		$by_path[ $item['path'] ][] = $item['warning'];
	}

	$warnings = [];

	foreach ( $report['blocks'] as $index => $row ) {
		$row['warnings']            = array_merge( $row['warnings'], $by_path[ $row['path'] ] ?? [] );
		$report['blocks'][ $index ] = $row;
		$warnings                   = array_merge( $warnings, $row['warnings'] );
	}

	$report['warnings'] = $warnings;

	return $report;
}

/**
 * Blocks whose image validate checks for alt text and for the pack's
 * placeholders. core/cover is not one: its image is a background, which is
 * decoration, so it is checked for the placeholders alone (cover_url()).
 */
const IMAGE_BLOCKS = [ 'core/image', 'core/media-text' ];

/**
 * The pack's placeholder images by the part of their URL the checks match,
 * `placeholders/<file>`: one for each URL that
 * BindingsEditor\placeholder_images() gives the editor hints.
 *
 * @return list<string>
 */
function placeholder_names(): array {
	$names = [];

	foreach ( BindingsEditor\placeholder_images() as $url ) {
		$name = placeholder_name( $url );

		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * The part of a URL that can name one of the pack's placeholder images, read
 * as placeholderName() in assets/editor/hints.js reads it: `placeholders/<file>`
 * below a folder called `assets`, or below the token raw pattern markup
 * carries (Patterns\ASSETS_TOKEN, which inc/patterns.php swaps for the
 * plugin's assets URL when it registers a pattern). Only the path counts, so
 * a copy saved on another host, under another plugin folder or with a query
 * string still matches.
 *
 * @param string $url Image URL.
 * @return string `placeholders/<file>`, or '' when the URL cannot name one.
 */
function placeholder_name( string $url ): string {
	$path     = wp_parse_url( $url, PHP_URL_PATH );
	$segments = is_string( $path ) ? explode( '/', $path ) : [];

	if ( count( $segments ) < 3 ) {
		return '';
	}

	[ $folder, $placeholders, $file ] = array_slice( $segments, -3 );

	return 'placeholders' === $placeholders && '' !== $file && in_array( $folder, [ 'assets', trim( Patterns\ASSETS_TOKEN, '/' ) ], true )
		? $placeholders . '/' . $file
		: '';
}

/**
 * The image an image block shows, read from its own saved HTML the way the
 * editor reads it: block.json takes core/image's url and alt, and
 * core/media-text's mediaUrl and mediaAlt, from the img tag's src and alt.
 *
 * A block with no image URL has nothing to check: an empty image block, or a
 * media and text block holding a video or nothing. Nor does a media and text
 * block set to show the post's featured image, which renders that image with
 * its own alt text from the media library.
 *
 * @param Block $block Parsed block.
 * @return array{url: string, alt: string}|null Null when the block shows no image of its own.
 */
function image_of( array $block ): ?array {
	$name = (string) $block['blockName'];

	if ( ! in_array( $name, IMAGE_BLOCKS, true ) || ( 'core/media-text' === $name && true === ( $block['attrs']['useFeaturedImage'] ?? false ) ) ) {
		return null;
	}

	$tags = new \WP_HTML_Tag_Processor( $block['innerHTML'] );

	if ( ! $tags->next_tag( [ 'tag_name' => 'img' ] ) ) {
		return null;
	}

	$src = $tags->get_attribute( 'src' );
	$alt = $tags->get_attribute( 'alt' );
	$url = is_string( $src ) ? trim( $src ) : '';

	return '' === $url ? null : [
		'url' => $url,
		'alt' => is_string( $alt ) ? trim( $alt ) : '',
	];
}

/**
 * The background image a cover block shows, read from its block comment:
 * block.json gives core/cover's url no source, so core keeps it there and
 * not in the saved HTML.
 *
 * A cover set to show the post's featured image shows that image instead,
 * so it has none of its own to check, as image_of() treats a media and text
 * block set the same way.
 *
 * @param Block $block Parsed block.
 * @return string The URL, or '' for any other block and a cover with no image of its own.
 */
function cover_url( array $block ): string {
	if ( 'core/cover' !== $block['blockName'] || true === ( $block['attrs']['useFeaturedImage'] ?? false ) ) {
		return '';
	}

	$url = $block['attrs']['url'] ?? '';

	return is_string( $url ) ? trim( $url ) : '';
}

/**
 * Whether a block is the contact section's stand-in for a form, still in place.
 *
 * The same test assets/editor/hints.js makes, from the same names
 * (BindingsEditor\FORM_SLOT): a core/group named "Form" whose parent is the
 * core/column named "Form slot". Once a form block replaces the group, or the
 * group is moved out of that column, it is not.
 *
 * @param Block      $block  Parsed block.
 * @param Block|null $parent Its parent, null at the top level.
 */
function is_form_fallback( array $block, ?array $parent ): bool {
	return null !== $parent
		&& 'core/group' === $block['blockName']
		&& BindingsEditor\FORM_SLOT['fallback'] === ( BlockTree\metadata( $block )['name'] ?? null )
		&& 'core/column' === $parent['blockName']
		&& BindingsEditor\FORM_SLOT['slot'] === ( BlockTree\metadata( $parent )['name'] ?? null );
}

/**
 * Placeholders left in place and images with no alt text, as warnings, each
 * with the path of its block.
 *
 * A placeholder is one of the pack's placeholder images (an image, or a
 * cover's background (cover_url()), whose URL placeholder_name() reads as one
 * of $placeholders), or the contact section's form stand-in
 * (is_form_fallback()). All are found from data, as the editor hints find
 * them, never from the text on screen. A placeholder image gets a placeholder
 * warning only, although it ships with empty alt text. Every other image
 * (image_of()) whose alt text is empty or missing gets a missing_alt warning,
 * unless its alt is bound to a source, which then gives the text. A cover
 * never does: its background is decoration.
 *
 * @param array<int, Block> $blocks       Blocks.
 * @param list<string>      $placeholders From placeholder_names().
 * @param string            $prefix       Path of the parent, '' at the top.
 * @param Block|null        $parent       The parent block, null at the top.
 * @return list<array{path: string, warning: string}>
 */
function image_warnings( array $blocks, array $placeholders, string $prefix = '', ?array $parent = null ): array {
	$out = [];

	foreach ( $blocks as $index => $block ) {
		$path    = '' === $prefix ? (string) $index : $prefix . '/' . $index;
		$label   = BlockTree\metadata( $block )['name'] ?? '';
		$where   = sprintf( '%s%s at %s', (string) $block['blockName'], is_string( $label ) && '' !== $label ? sprintf( ' "%s"', $label ) : '', $path );
		$image   = image_of( $block );
		$cover   = cover_url( $block );
		$name    = placeholder_name( null === $image ? $cover : $image['url'] );
		$warning = null;

		if ( is_form_fallback( $block, $parent ) ) {
			$warning = sprintf( 'placeholder: %s is the stand-in for a form that the contact section ships, still in its "%s" column. Replace it with a form plugin\'s block in the editor, so visitors get a form.', $where, BindingsEditor\FORM_SLOT['slot'] );
		} elseif ( '' !== $cover && '' !== $name && in_array( $name, $placeholders, true ) ) {
			$warning = sprintf( 'placeholder: %s shows the pack\'s placeholder image %s as its background. Replace it in the editor with the site\'s own image and set its focal point on the subject, or remove the block.', $where, $name );
		} elseif ( '' !== $name && in_array( $name, $placeholders, true ) ) {
			$warning = sprintf( 'placeholder: %s shows the pack\'s placeholder image %s. Replace it in the editor with the site\'s own image, with alt text, or remove the block.', $where, $name );
		} elseif ( null !== $image && '' === $image['alt'] && ! isset( bound_attributes( BlockTree\metadata( $block )['bindings'] ?? null )['alt'] ) ) {
			$warning = sprintf( 'missing_alt: %s has no alt text, so a screen reader cannot say what it shows. Add alt text in the editor that names what the image shows, such as the company\'s name for a logo. Leave it empty only for an image that is decoration.', $where );
		}

		if ( null !== $warning ) {
			$out[] = [
				'path'    => $path,
				'warning' => $warning,
			];
		}

		/** @var array<int, Block> $inner */
		$inner = $block['innerBlocks'];
		array_push( $out, ...image_warnings( $inner, $placeholders, $path, $block ) );
	}

	return $out;
}
