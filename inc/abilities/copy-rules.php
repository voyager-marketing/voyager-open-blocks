<?php
/**
 * Copy rules: the check insert runs, and compose will run, on the copy given
 * for a pattern. Contract 3 in docs/v2-contracts.md has the shapes.
 *
 * The rules are the copy_rules of the pattern's intent, read with
 * Patterns\intent_meta(). copy_rule_refusals() takes plain arrays and calls no
 * WordPress function, so the unit suite runs it as it is. Design writes the
 * rules; this file only enforces them.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\Cast;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema for one copy value: plain text, or the text and who gave it.
 *
 * @return array<string, mixed>
 */
function copy_value_schema(): array {
	return [
		'anyOf' => [
			[
				'type'      => 'string',
				'minLength' => 1,
			],
			[
				'type'                 => 'object',
				'description'          => 'Text the site owner gave you, such as a review in the customer\'s own words. The only value a claim slot takes.',
				'properties'           => [
					'text'   => [
						'type'      => 'string',
						'minLength' => 1,
					],
					'source' => [
						'type' => 'string',
						'enum' => [ 'user' ],
					],
				],
				'required'             => [ 'text', 'source' ],
				'additionalProperties' => false,
			],
		],
	];
}

/**
 * Copy input as label => its text, and whether the site owner gave it.
 *
 * A string is text the agent wrote. The object form, `{"text", "source": "user"}`,
 * is text the site owner gave. The input schema has refused anything else.
 *
 * @param mixed $value insert's copy, or a compose section's.
 * @return array<string, array{text: string, user: bool}>
 */
function copy_values( mixed $value ): array {
	$out = [];

	foreach ( assoc( $value ) as $label => $item ) {
		if ( is_object( $item ) ) {
			$item = get_object_vars( $item );
		}

		$out[ $label ] = is_array( $item )
			? [
				'text' => Cast\to_string( $item['text'] ?? '' ),
				'user' => 'user' === ( $item['source'] ?? null ),
			]
			: [
				'text' => Cast\to_string( $item ),
				'user' => false,
			];
	}

	return $out;
}

/**
 * The text of each copy value, label => text, as BlockTree\set_copy() takes it.
 *
 * @param array<string, array{text: string, user: bool}> $values From copy_values().
 * @return array<string, string>
 */
function copy_texts( array $values ): array {
	return array_map( static fn ( array $value ): string => $value['text'], $values );
}

/**
 * Copy rules with a rule for each label repeat makes: the rule of the label it copies.
 *
 * repeat gives each copy of a block a new label, and each named block inside
 * it the same start ("Review 3 quote" in a copy labelled "Review 4" becomes
 * "Review 4 quote"; see BlockTree\repeat()). A copy holds the same kind of
 * text as the block it copies, so it keeps that block's rule, and a fourth
 * review is as much a claim as the third.
 *
 * @param array<string, array<mixed>> $rules  Label => rule, from the intent's copy_rules.
 * @param array<string, string[]>     $repeat insert's repeat: label => labels of its copies.
 * @return array<string, array<mixed>>
 */
function repeated_rules( array $rules, array $repeat ): array {
	$out = $rules;

	foreach ( $repeat as $label => $new_labels ) {
		$label = (string) $label;

		foreach ( $rules as $ruled => $rule ) {
			$ruled = (string) $ruled;

			if ( $ruled !== $label && ! str_starts_with( $ruled, $label . ' ' ) ) {
				continue;
			}

			foreach ( $new_labels as $new_label ) {
				$copied         = $new_label . substr( $ruled, strlen( $label ) );
				$out[ $copied ] = $out[ $copied ] ?? $rule;
			}
		}
	}

	return $out;
}

/**
 * The copy and omit a pattern's copy rules refuse. Every refused slot, not only the first.
 *
 * Only slots that have a rule are checked, and only the copy and omit given,
 * never the text the pattern ships with. In the order of copy, then of omit:
 *
 * - too_long: the text, trimmed, is longer than the rule's max characters.
 *   Text under min is accepted: min is guidance.
 * - claim: a claim slot got a plain string. Copy never comes from Site Data,
 *   which reaches a page through a binding, so only text the site owner gave
 *   (the object form) fills it.
 * - required: omit names a required slot, or a block that holds one. A block
 *   whose label starts the slot's own and a space is the slot's item ("Service 3"
 *   for "Service 3 heading"): leaving out a whole item takes its slots with it,
 *   so it is not refused.
 *
 * @param array<string, array<mixed>>                    $rules Label => rule, from the intent's copy_rules.
 * @param array<string, array{text: string, user: bool}> $copy  From copy_values().
 * @param array<int, string>                             $omit  Labels to leave out.
 * @param array<int, Row>                                $rows  The pattern's blocks as BlockTree\describe() lists them, after repeat and before omit.
 * @return list<array{slot: string, reason: string, message: string, max?: int, length?: int, omitted?: string}>
 */
function copy_rule_refusals( array $rules, array $copy, array $omit, array $rows ): array {
	$refused = [];

	foreach ( $copy as $label => $value ) {
		$rule = $rules[ $label ] ?? null;

		if ( null === $rule ) {
			continue;
		}

		$max    = isset( $rule['max'] ) && is_numeric( $rule['max'] ) ? (int) $rule['max'] : null;
		$length = mb_strlen( trim( $value['text'] ) );

		if ( null !== $max && $length > $max ) {
			$refused[] = [
				'slot'    => $label,
				'reason'  => 'too_long',
				'message' => sprintf( '"%1$s" is %2$d characters long, and its copy rule allows at most %3$d. Shorten it.%4$s', $label, $length, $max, rule_purpose( $rule ) ),
				'max'     => $max,
				'length'  => $length,
			];
		}

		if ( true === ( $rule['claim'] ?? false ) && ! $value['user'] ) {
			$refused[] = [
				'slot'    => $label,
				'reason'  => 'claim',
				'message' => sprintf( '"%1$s" is a claim slot: its text comes from the site owner, never from you, so a plain string is refused, even one that matches a Site Data value. If the site owner gave you this text, pass it as {"text": "...", "source": "user"}. If a Site Data field holds it, leave "%1$s" out of copy and bind the block to that field (get-catalog lists the fields).', $label ),
			];
		}
	}

	$dropped = [];

	foreach ( $omit as $label ) {
		foreach ( held_labels( $rows, $label ) as $held ) {
			$rule = $rules[ $held ] ?? null;

			if ( null === $rule || true !== ( $rule['required'] ?? false ) || isset( $dropped[ $held ] ) ) {
				continue;
			}

			// The slot's own item goes with it.
			if ( $held !== $label && str_starts_with( $held, $label . ' ' ) ) {
				continue;
			}

			$dropped[ $held ] = [
				'slot'    => $held,
				'reason'  => 'required',
				'message' => $held === $label
					? sprintf( '"%s" is required by its copy rule, so omit cannot leave it out. Keep it, and give it copy if the shipped text does not fit.', $held )
					: sprintf( 'Leaving out "%1$s" drops "%2$s", which its copy rule requires. Keep "%1$s", and omit only the blocks beside "%2$s" that you have no copy for.', $label, $held ),
				'omitted' => $label,
			];
		}
	}

	return array_merge( $refused, array_values( $dropped ) );
}

/**
 * A rule's purpose as a sentence to add to a refusal, '' when it has none.
 *
 * @param array<mixed> $rule A copy rule.
 */
function rule_purpose( array $rule ): string {
	$purpose = Cast\to_string( $rule['purpose'] ?? '' );

	return '' === $purpose ? '' : ' What goes there: ' . $purpose;
}

/**
 * The labels a label's blocks hold, the label itself included, each once.
 *
 * @param array<int, Row> $rows  Rows from BlockTree\describe().
 * @param string          $label A label, such as one omit names.
 * @return list<string>
 */
function held_labels( array $rows, string $label ): array {
	$paths = [];

	foreach ( $rows as $row ) {
		if ( ( $row['label'] ?? null ) === $label ) {
			$paths[] = $row['path'];
		}
	}

	$held = [];

	foreach ( $rows as $row ) {
		if ( ! isset( $row['label'] ) ) {
			continue;
		}

		foreach ( $paths as $path ) {
			if ( $row['path'] === $path || str_starts_with( $row['path'], $path . '/' ) ) {
				$held[] = $row['label'];
				break;
			}
		}
	}

	return array_values( array_unique( $held ) );
}

/**
 * Every slot one insert refuses, or one compose section: bound slots, then the copy rules'.
 *
 * A bound slot is not listed again under its copy rule. It takes no copy at
 * all, so whether the copy fits its rule does not matter.
 *
 * @param list<array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string}> $bound From bound_copy_slots().
 * @param list<array{slot: string, reason: string, message: string, max?: int, length?: int, omitted?: string}>                  $rules From copy_rule_refusals().
 * @return list<array{slot: string, reason: string, message: string, max?: int, length?: int, omitted?: string}> The copy rules' refusals that remain.
 */
function rule_refusals_beside( array $bound, array $rules ): array {
	$slots = array_column( $bound, 'slot' );

	return array_values( array_filter( $rules, static fn ( array $row ): bool => ! in_array( $row['slot'], $slots, true ) ) );
}

/**
 * The error for refused copy, listing every refused slot in data.refused.
 *
 * When every refused slot is bound, this is bound_slot_error(), code and
 * message as they were before copy rules. Otherwise the code is
 * voyager_open_blocks_copy_rules, and data.refused holds the bound slots, then
 * the copy rules' refusals.
 *
 * @param list<array{slot: string, reason: string, block: string, path: string, attribute: string, source: string, key: string}> $bound From bound_copy_slots().
 * @param list<array{slot: string, reason: string, message: string, max?: int, length?: int, omitted?: string}>                  $rules From copy_rule_refusals().
 */
function copy_error( array $bound, array $rules ): WP_Error {
	$rules = rule_refusals_beside( $bound, $rules );

	if ( [] === $rules ) {
		return bound_slot_error( 'copy', $bound );
	}

	$refused = array_merge( $bound, $rules );
	$lines   = [];

	foreach ( $bound as $slot ) {
		$lines[] = bound_slot_line( $slot );
	}
	foreach ( $rules as $row ) {
		$lines[] = $row['message'];
	}

	return new WP_Error(
		'voyager_open_blocks_copy_rules',
		sprintf(
			'Nothing was saved: %1$s. get-catalog lists each pattern\'s copy_rules, and data.refused lists every slot to fix. %2$s',
			1 === count( $refused ) ? 'one slot was refused' : sprintf( '%d slots were refused', count( $refused ) ),
			implode( ' ', $lines )
		),
		[
			'status'  => 400,
			'refused' => $refused,
		]
	);
}
