<?php
/**
 * The validate ability: block markup, or a saved post, checked without saving anything.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Catalog;
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
			'description'         => __( 'Checks block markup, or a saved post, and changes nothing. For every block it reports, by path: the block type is registered, its comment attributes match block.json, each binding to voyager/site-data, voyager/post-meta-text or core/post-meta names a bindable attribute and args that get-catalog lists, and the markup is what WordPress writes back after a parse and serialize round trip. A block bound to a phone or email field whose fallback looks like a phone number or an email address gets a warning starting stale_fallback: visitors see that fallback whenever the source returns nothing. It does not compare saved HTML with each block\'s save() output; block-runner validate does that.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'default'              => [],
				'properties'           => [
					'markup'    => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Block markup to check, such as a section you wrote or were given. Pass markup or post_id, not both.',
					],
					'post_id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'ID of a saved post or page to check instead of markup.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'Post type whose fields binding args are checked against. Defaults to the type of post_id, else page. For a template, pass the post type it shows.',
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
						'description' => 'Every block\'s warnings. Each starts with its block, or with a code such as stale_fallback.',
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

	$report = BlockTree\validate_markup( $markup, '' === $post_type ? 'page' : $post_type );
	$report = with_stale_fallbacks( $report, stale_fallbacks( BlockTree\parse( $markup ), shipped_fallbacks() ) );

	return $out + $report + [ 'save_output' => SAVE_OUTPUT ];
}

/**
 * Keys that name a phone number or an email address, such as Site Data's
 * phone, phone_link, email and email_link, or a post meta key like
 * office_phone. Only their fallbacks can be stale copies of a phone number or
 * an email address.
 */
const CONTACT_KEY = '/phone|mail/i';

/**
 * What a fallback looks like: 'email', 'phone', or null for neither.
 *
 * Only fallbacks of keys matching CONTACT_KEY are tested, so this tells a
 * phone number or an email address from a label such as "Call us", not from
 * a date or a year range. An email is anything shaped x@y.z. A phone number
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
		$blocks = [];

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( str_starts_with( Cast\to_string( $pattern['name'] ?? '' ), Catalog\PREFIX ) ) {
				array_push( $blocks, ...BlockTree\parse( Cast\to_string( $pattern['content'] ?? '' ) ) );
			}
		}

		$shipped = fallback_index( bound_fallbacks( $blocks ) );
	}

	return $shipped;
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
 * copy waiting to show. Only keys matching CONTACT_KEY are checked: a block
 * bound to any other key, such as copyright or founded, may hold digits that
 * look like a phone number ("© 2015-2026", "License #1234567") and is never
 * a stale copy of one. A fallback the pack's patterns ship for the same
 * source and key is a placeholder and is left out.
 *
 * @param array<int, Block>           $blocks  Blocks.
 * @param array<string, list<string>> $shipped From shipped_fallbacks().
 * @return list<array{path: string, block: string, label: string, attribute: string, source: string, key: string, fallback: string, in_html: bool, looks_like: string}>
 */
function stale_fallbacks( array $blocks, array $shipped ): array {
	$stale = [];

	foreach ( bound_fallbacks( $blocks ) as $fallback ) {
		if ( 1 !== preg_match( CONTACT_KEY, $fallback['key'] ) ) {
			continue;
		}

		$kind = looks_like( $fallback['fallback'] );

		if ( null === $kind || in_array( $fallback['fallback'], $shipped[ $fallback['source'] . ':' . $fallback['key'] ] ?? [], true ) ) {
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
	if ( [] === $stale ) {
		return $report;
	}

	$warnings = [];

	foreach ( $report['blocks'] as $index => $row ) {
		foreach ( $stale as $item ) {
			if ( $item['path'] === $row['path'] ) {
				$row['warnings'][] = stale_fallback_warning( $item );
			}
		}

		$report['blocks'][ $index ] = $row;
		$warnings                   = array_merge( $warnings, $row['warnings'] );
	}

	$report['warnings'] = $warnings;

	return $report;
}
