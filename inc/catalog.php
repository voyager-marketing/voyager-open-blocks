<?php
/**
 * What the pack offers on this site, and which binding args are real.
 *
 * Read by the get-catalog ability and by bind, which accepts args only when
 * they match a field listed here exactly. Nothing here constructs args: every
 * field's `args` comes from the source's own field list.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Catalog;

use VoyagerOpenBlocks\BlockTree;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PREFIX = 'voyager-open/';

/**
 * The pack's block types.
 *
 * @return array<int, array<string, mixed>>
 */
function blocks(): array {
	$out = [];

	foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( ! str_starts_with( $name, PREFIX ) ) {
			continue;
		}

		$attributes = [];
		foreach ( (array) $type->attributes as $attribute => $schema ) {
			$attributes[ $attribute ] = array_intersect_key( (array) $schema, array_flip( [ 'type', 'enum', 'default', 'source' ] ) );
		}

		$out[] = [
			'name'        => $name,
			'title'       => (string) $type->title,
			'description' => (string) $type->description,
			'dynamic'     => $type->is_dynamic(),
			'attributes'  => $attributes,
			'bindable'    => array_values( get_block_bindings_supported_attributes( $name ) ),
		];
	}

	return $out;
}

/**
 * The pack's patterns, each with its named blocks and their bindings.
 *
 * @return array<int, array<string, mixed>>
 */
function patterns(): array {
	$out = [];

	foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
		if ( ! str_starts_with( (string) $pattern['name'], PREFIX ) ) {
			continue;
		}

		$out[] = [
			'name'        => $pattern['name'],
			'title'       => $pattern['title'],
			'description' => $pattern['description'] ?? '',
			'categories'  => $pattern['categories'] ?? [],
			'blocks'      => array_values(
				array_filter(
					BlockTree\describe( BlockTree\parse( (string) $pattern['content'] ) ),
					static fn ( array $row ): bool => isset( $row['label'] ) || isset( $row['bindings'] )
				)
			),
		];
	}

	return $out;
}

/**
 * Bindable fields of a source for a post type, as `{ label, args }`.
 *
 * Null when the source's fields cannot be listed on the server (an unknown
 * source, or one another plugin owns).
 *
 * @param string $source    Source name.
 * @param string $post_type Post type.
 * @return array<int, array{label: string, args: array<string, string>}>|null
 */
function fields( string $source, string $post_type ): ?array {
	$out = [];

	switch ( $source ) {
		case SiteData\SOURCE:
			if ( ! SiteData\owned() ) {
				return null;
			}
			foreach ( SiteData\fields() as $key => $field ) {
				$out[] = [
					'label' => $field['label'],
					'args'  => [ 'key' => $key ],
				];
			}
			return $out;

		case PostMetaText\SOURCE:
			if ( ! PostMetaText\owned() ) {
				return null;
			}
			foreach ( PostMetaText\fields_for( $post_type ) as $key => $label ) {
				$out[] = [
					'label' => $label,
					'args'  => [ 'key' => (string) $key ],
				];
			}
			return $out;

		case 'core/post-meta':
			$meta = array_merge( get_registered_meta_keys( 'post', '' ), get_registered_meta_keys( 'post', $post_type ) );
			foreach ( $meta as $key => $args ) {
				if ( empty( $args['show_in_rest'] ) || str_starts_with( (string) $key, '_' ) ) {
					continue;
				}
				$out[] = [
					'label' => (string) ( $args['label'] ?? $key ),
					'args'  => [ 'key' => (string) $key ],
				];
			}
			return $out;
	}

	return null;
}

/**
 * Every source with its fields for one post type.
 *
 * @param string $post_type Post type the fields are for.
 * @return array<int, array<string, mixed>>
 */
function sources( string $post_type ): array {
	$out = [];

	foreach ( [ SiteData\SOURCE, PostMetaText\SOURCE, 'core/post-meta' ] as $name ) {
		$source = get_block_bindings_source( $name );
		if ( null === $source ) {
			continue;
		}

		$fields = fields( $name, $post_type );
		$out[]  = [
			'name'   => $name,
			'label'  => (string) $source->label,
			'fields' => $fields,
			'note'   => null === $fields ? 'Another plugin registered this source; its fields cannot be listed here.' : null,
		];
	}

	return $out;
}

/**
 * Accept binding args only when they match a listed field exactly.
 *
 * voyager/post-meta-text also takes a dot path into a listed field
 * (`stats.0.value`); the part before the first dot must match.
 *
 * @param string               $source    Source name.
 * @param string               $post_type Post type of the post being edited.
 * @param array<string, mixed> $args      Args as given.
 * @return true|WP_Error
 */
function match_args( string $source, string $post_type, array $args ) {
	$fields = fields( $source, $post_type );

	if ( null === $fields ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_source',
			sprintf( 'Fields of %s cannot be listed on this site, so its args cannot be checked. Bind it in the editor instead.', $source ),
			[ 'status' => 400 ]
		);
	}

	$candidate = $args;
	if ( PostMetaText\SOURCE === $source && isset( $args['key'] ) && is_string( $args['key'] ) && str_contains( $args['key'], '.' ) ) {
		$candidate['key'] = strstr( $args['key'], '.', true );
	}

	foreach ( $fields as $field ) {
		if ( $field['args'] === $candidate ) {
			return true;
		}
	}

	return new WP_Error(
		'voyager_open_blocks_invalid_args',
		sprintf( 'No %s field for post type "%s" has args %s. Copy args from get-catalog or the Wesper manifest.', $source, $post_type, wp_json_encode( $args ) ),
		[ 'status' => 400 ]
	);
}
