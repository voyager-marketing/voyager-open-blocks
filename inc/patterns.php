<?php
/**
 * Pattern registration.
 *
 * Each pattern is two files. patterns/intents/<name>.json holds the metadata
 * and the Block Runner intent tree; patterns/<name>.html is the block markup
 * `npm run patterns:build` assembles from it, validated before it is written.
 * Never edit the .html by hand: edit the intent and rebuild.
 *
 * The same build writes inc/generated/patterns.php, one PHP array that holds
 * what each pattern's two files say. A request reads that array, which
 * OPcache keeps in memory, and no intent or markup file. Only a caller that
 * passes its own intents folder to bound_keys() or intent_meta(), as the
 * tests do, reads files.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Patterns;

use VoyagerOpenBlocks\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder in pattern markup for this plugin's assets/ URL.
 *
 * Markup stays a plain, validatable file; the real URL is swapped in at
 * registration so the pack works from any plugin directory.
 */
const ASSETS_TOKEN = '/voyager-open-blocks-assets/';

/**
 * The pack's patterns, as `npm run patterns:build` generates them from the
 * intents and their markup (inc/generated/patterns.php).
 *
 * Each entry holds the args register() registers, the content with
 * ASSETS_TOKEN still in it, the section, layout and copy rules intent_meta()
 * returns, and the keys bound_keys() returns for each binding source. The
 * file is a plain array, so OPcache keeps it in memory; the static keeps a
 * request from requiring it twice.
 *
 * @return array<string, array{args: array{title: string, description: string, content: string, categories: list<string>, keywords: list<string>, blockTypes: list<string>, viewportWidth: int}, section: string|null, layout: string|null, copy_rules: array<string, array<mixed>>, bindings: array<string, list<string>>}> Pattern name => what its intent and markup say.
 */
function generated(): array {
	/** @var array<string, array{args: array{title: string, description: string, content: string, categories: list<string>, keywords: list<string>, blockTypes: list<string>, viewportWidth: int}, section: string|null, layout: string|null, copy_rules: array<string, array<mixed>>, bindings: array<string, list<string>>}>|null $patterns */
	static $patterns = null;

	if ( null === $patterns ) {
		/** @var array<string, array{args: array{title: string, description: string, content: string, categories: list<string>, keywords: list<string>, blockTypes: list<string>, viewportWidth: int}, section: string|null, layout: string|null, copy_rules: array<string, array<mixed>>, bindings: array<string, list<string>>}> $loaded */
		$loaded   = require __DIR__ . '/generated/patterns.php';
		$patterns = $loaded;
	}

	return $patterns;
}

/**
 * The keys an intent tree binds through one source, at any depth.
 *
 * A binding is any object with `source` set to the source and a string
 * `args.key`, which is the shape of every `metadata.bindings` entry.
 *
 * @param mixed  $node   A decoded intent file, or any part of one.
 * @param string $source Binding source name.
 * @return list<string> Each key once, in the order first found.
 */
function keys_in( mixed $node, string $source ): array {
	if ( ! is_array( $node ) ) {
		return [];
	}

	$keys = [];
	$args = $node['args'] ?? null;

	if ( ( $node['source'] ?? null ) === $source && is_array( $args ) && isset( $args['key'] ) && is_string( $args['key'] ) ) {
		$keys[] = $args['key'];
	}

	foreach ( $node as $child ) {
		array_push( $keys, ...keys_in( $child, $source ) );
	}

	return array_values( array_unique( $keys ) );
}

/**
 * The keys of one source that each pattern binds, as the intents say.
 *
 * The intents are the source of truth for the shipped patterns. The build
 * reads them into the generated array (generated()), so this works before
 * init, needs no block parser and reads no file.
 *
 * @param string      $source Binding source name.
 * @param string|null $dir    Intents directory to read instead, such as a test's. Defaults to the generated array, built from this plugin's patterns/intents.
 * @return array<string, array{title: string, keys: list<string>}> Pattern name => title and keys. Patterns that bind none of the source's keys are left out.
 */
function bound_keys( string $source, ?string $dir = null ): array {
	if ( null !== $dir ) {
		return bound_keys_in( $source, $dir );
	}

	$out = [];

	foreach ( generated() as $name => $pattern ) {
		$keys = $pattern['bindings'][ $source ] ?? [];
		if ( [] !== $keys ) {
			$out[ $name ] = [
				'title' => $pattern['args']['title'],
				'keys'  => $keys,
			];
		}
	}

	return $out;
}

/**
 * bound_keys() for a folder of intent files, read from each file.
 *
 * @param string $source Binding source name.
 * @param string $dir    Intents directory.
 * @return array<string, array{title: string, keys: list<string>}> Pattern name => title and keys.
 */
function bound_keys_in( string $source, string $dir ): array {
	$out = [];

	foreach ( glob( $dir . '/*.json' ) ?: [] as $intent_file ) {
		$meta  = json_decode( (string) file_get_contents( $intent_file ), true );
		$title = is_array( $meta ) ? Cast\to_string( $meta['title'] ?? '' ) : '';

		if ( ! is_array( $meta ) || '' === $title ) {
			continue;
		}

		$keys = keys_in( $meta['intent'] ?? [], $source );
		if ( [] !== $keys ) {
			$out[ 'voyager-open/' . basename( $intent_file, '.json' ) ] = [
				'title' => $title,
				'keys'  => $keys,
			];
		}
	}

	return $out;
}

/**
 * What a pattern's intent says beside its blocks: its section and layout
 * (contract 5 in docs/v2-contracts.md) and its copy rules (contract 3).
 *
 * Read from the generated array, as bound_keys() reads the bindings, so it
 * holds what the pattern ships with. A section with one layout has no
 * `section` or `layout` key, so both are null. An intent with no copy rules
 * gives none, and so does a name the pack does not ship.
 *
 * @param string      $pattern Pattern name, such as voyager-open/hero.
 * @param string|null $dir     Intents directory to read instead, such as a test's. Defaults to the generated array, built from this plugin's patterns/intents.
 * @return array{section: string|null, layout: string|null, copy_rules: array<string, array<mixed>>}
 */
function intent_meta( string $pattern, ?string $dir = null ): array {
	if ( null !== $dir ) {
		return intent_meta_in( $pattern, $dir );
	}

	$entry = generated()[ $pattern ] ?? null;

	return [
		'section'    => $entry['section'] ?? null,
		'layout'     => $entry['layout'] ?? null,
		'copy_rules' => $entry['copy_rules'] ?? [],
	];
}

/**
 * intent_meta() for a folder of intent files, read from the pattern's file.
 *
 * @param string $pattern Pattern name, such as voyager-open/hero.
 * @param string $dir     Intents directory.
 * @return array{section: string|null, layout: string|null, copy_rules: array<string, array<mixed>>}
 */
function intent_meta_in( string $pattern, string $dir ): array {
	$out  = [
		'section'    => null,
		'layout'     => null,
		'copy_rules' => [],
	];
	$slug = str_starts_with( $pattern, 'voyager-open/' ) ? substr( $pattern, strlen( 'voyager-open/' ) ) : '';

	// The slug names a file, so it is only ever a plain intent name.
	if ( 1 !== preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
		return $out;
	}

	$file = $dir . '/' . $slug . '.json';
	$meta = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

	if ( ! is_array( $meta ) ) {
		return $out;
	}

	$out['section'] = is_string( $meta['section'] ?? null ) ? $meta['section'] : null;
	$out['layout']  = is_string( $meta['layout'] ?? null ) ? $meta['layout'] : null;

	foreach ( is_array( $meta['copy_rules'] ?? null ) ? $meta['copy_rules'] : [] as $label => $rule ) {
		if ( is_array( $rule ) ) {
			$out['copy_rules'][ (string) $label ] = $rule;
		}
	}

	return $out;
}

/**
 * Register the pattern category and every pattern.
 *
 * The category slug stays `voyager-open` so intents and saved content keep
 * working. The label says what the sections are for, since that is what a
 * newcomer looks for in the inserter.
 *
 * Each pattern's args come from the generated array, in the order of the
 * intents' file names, with this plugin's assets/ URL swapped in for
 * ASSETS_TOKEN.
 */
function register(): void {
	register_block_pattern_category(
		'voyager-open',
		[
			'label'       => __( 'Business sections (Open Blocks)', 'voyager-open-blocks' ),
			'description' => __( 'Ready-made sections that use your phone, email and address from Settings > Site Data.', 'voyager-open-blocks' ),
		]
	);

	$assets = plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE );

	foreach ( generated() as $name => $pattern ) {
		$args            = $pattern['args'];
		$args['content'] = str_replace( ASSETS_TOKEN, $assets, $args['content'] );

		register_block_pattern( $name, $args );
	}
}
add_action( 'init', __NAMESPACE__ . '\register' );
