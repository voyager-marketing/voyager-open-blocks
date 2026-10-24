<?php
/**
 * Plugin Name: Foreign voyager/site-data owner (test fixture)
 * Description: Stands in for voyager-blocks. Owns voyager/site-data and describes its fields through the voyager_binding_sources filter, the way voyager-blocks' binding registry does.
 *
 * An mu-plugin for a throwaway local site only. scripts/foreign-owner-check.mjs
 * copies it into wp-content/mu-plugins, runs get-catalog and bind, and removes
 * it. The keys are not the pack's own, so a listed key can only have come from
 * this owner.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\ForeignSiteData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SOURCE = 'voyager/site-data';

/**
 * Field list, in voyager-blocks' shape: key => { label, type, args? }.
 *
 * One field leaves its args to the default `{ "key": "<key>" }`; the other
 * declares its own, which a consumer must use verbatim (VOY-477).
 *
 * @return array<string, array<string, mixed>>
 */
function get_fields_list(): array {
	return [
		'fixture_phone' => [
			'label' => 'Fixture phone',
			'type'  => 'text',
		],
		'fixture_hours' => [
			'label' => 'Fixture hours',
			'type'  => 'text',
			'args'  => [
				'key'    => 'fixture_hours',
				'format' => 'short',
			],
		],
	];
}

/**
 * Resolver. Fictional values only.
 *
 * @param array<string, mixed> $source_args Binding args.
 */
function get_binding_value( array $source_args ): ?string {
	$values = [
		'fixture_phone' => '(555) 010-0142',
		'fixture_hours' => 'Mon to Fri, 9 to 5',
	];

	return $values[ $source_args['key'] ?? '' ] ?? null;
}

/**
 * Contribute the definition, as voyager-blocks' sources do.
 *
 * @param array<int, array<string, mixed>> $sources Existing definitions.
 * @return array<int, array<string, mixed>>
 */
function contribute_source( array $sources ): array {
	$sources[] = [
		'name'               => SOURCE,
		'label'              => 'Fixture Site Data',
		'get_value_callback' => __NAMESPACE__ . '\get_binding_value',
		'get_fields_list'    => __NAMESPACE__ . '\get_fields_list',
		'origin'             => 'core',
	];

	return $sources;
}
add_filter( 'voyager_binding_sources', __NAMESPACE__ . '\contribute_source' );

/**
 * Register the source on init:5, as voyager-blocks' registry does, so the
 * pack finds it taken at init:20 and stands aside.
 */
function register_source(): void {
	register_block_bindings_source(
		SOURCE,
		[
			'label'              => 'Fixture Site Data',
			'get_value_callback' => __NAMESPACE__ . '\get_binding_value',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\register_source', 5 );
