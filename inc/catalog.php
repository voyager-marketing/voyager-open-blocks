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
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PREFIX = 'voyager-open/';

/**
 * The binding sources whose fields the catalog lists and bind writes.
 */
const SOURCES = [ SiteData\SOURCE, PostMetaText\SOURCE, 'core/post-meta' ];

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
 * A named block whose text insert's `copy` input can replace carries `copy: true`.
 *
 * @return array<int, array<string, mixed>>
 */
function patterns(): array {
	$out = [];

	foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
		$name = Cast\to_string( $pattern['name'] ?? '' );

		if ( ! str_starts_with( $name, PREFIX ) ) {
			continue;
		}

		$out[] = [
			'name'        => $name,
			'title'       => $pattern['title'] ?? '',
			'description' => $pattern['description'] ?? '',
			'categories'  => $pattern['categories'] ?? [],
			'blocks'      => array_values(
				array_map(
					/**
					 * @param Row $row A named or bound block.
					 * @return Row
					 */
					static function ( array $row ): array {
						if ( isset( $row['label'], BlockTree\TEXT_ATTRIBUTES[ $row['block'] ] ) ) {
							$row['copy'] = true;
						}
						return $row;
					},
					array_filter(
						BlockTree\describe( BlockTree\parse( Cast\to_string( $pattern['content'] ?? '' ) ) ),
						static fn ( array $row ): bool => isset( $row['label'] ) || isset( $row['bindings'] )
					)
				)
			),
		];
	}

	return $out;
}

/**
 * Bindable fields of a source for a post type, as `{ label, args }`.
 *
 * When another plugin owns one of the pack's sources, the fields are that
 * plugin's own list (see owner_fields()). Null when the fields cannot be
 * listed on the server: an unknown source, or an owner that does not describe
 * its fields.
 *
 * @param string $source    Source name.
 * @param string $post_type Post type.
 * @return array<int, array{label: string, args: array<mixed>}>|null
 */
function fields( string $source, string $post_type ): ?array {
	$out = [];

	switch ( $source ) {
		case SiteData\SOURCE:
			if ( ! SiteData\owned() ) {
				return owner_fields( $source, $post_type );
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
				return owner_fields( $source, $post_type );
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
					'label' => Cast\to_string( $args['label'] ?? $key ),
					'args'  => [ 'key' => (string) $key ],
				];
			}
			return $out;
	}

	return null;
}

/**
 * Fields of a source another plugin registered, from that plugin's own list.
 *
 * voyager-blocks (and any plugin built on its registry) describes each source
 * it contributes through the `voyager_binding_sources` filter: a
 * `get_fields_list` callable or a static `fields` map of key => `{ label,
 * args?, post_types? }`. The owner's list is used as it is, so for
 * voyager/post-meta-text that is the owner's allowlist, not this plugin's.
 * A field's own `args` are used verbatim; without them the args are
 * `{ "key": "<field key>" }`, which is what the owner's resolver reads.
 *
 * Fields restricted to other post types (the field's `post_types`, else the
 * definition's) are left out.
 *
 * @param string $source    Source name.
 * @param string $post_type Post type.
 * @return array<int, array{label: string, args: array<mixed>}>|null Null when the source is not registered or no definition describes it.
 */
function owner_fields( string $source, string $post_type ): ?array {
	if ( null === get_block_bindings_source( $source ) ) {
		return null;
	}

	$definition = null;
	foreach ( (array) apply_filters( 'voyager_binding_sources', [] ) as $entry ) {
		if ( is_array( $entry ) && ( $entry['name'] ?? null ) === $source ) {
			$definition = $entry;
			break;
		}
	}

	if ( null === $definition ) {
		return null;
	}

	$raw = is_array( $definition['fields'] ?? null ) ? $definition['fields'] : [];
	if ( isset( $definition['get_fields_list'] ) && is_callable( $definition['get_fields_list'] ) ) {
		$listed = call_user_func( $definition['get_fields_list'] );
		if ( is_array( $listed ) ) {
			$raw = $listed;
		}
	}

	$out = [];
	foreach ( $raw as $key => $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$types = (array) ( $field['post_types'] ?? $definition['post_types'] ?? [ '*' ] );
		if ( ! in_array( '*', $types, true ) && ! in_array( $post_type, $types, true ) ) {
			continue;
		}

		$out[] = [
			'label' => Cast\to_string( $field['label'] ?? $key ),
			'args'  => is_array( $field['args'] ?? null ) && [] !== $field['args'] ? $field['args'] : [ 'key' => (string) $key ],
		];
	}

	return $out;
}

/**
 * Every source with its fields for one post type.
 *
 * @param string $post_type Post type the fields are for.
 * @return array<int, array<string, mixed>>
 */
function sources( string $post_type ): array {
	$out = [];

	foreach ( SOURCES as $name ) {
		$source = get_block_bindings_source( $name );
		if ( null === $source ) {
			continue;
		}

		$fields = fields( $name, $post_type );
		$out[]  = [
			'name'   => $name,
			'label'  => (string) $source->label,
			'fields' => $fields,
			'note'   => null === $fields ? 'Another plugin registered this source without describing its fields, so they cannot be listed here.' : null,
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
 * @param string       $source    Source name.
 * @param string       $post_type Post type of the post being edited.
 * @param array<mixed> $args      Args as given.
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

/**
 * The checks bind makes before it writes a binding.
 *
 * The attribute must be bindable on the block, the source must be registered,
 * and the args must match a listed field (match_args()).
 *
 * @param string       $name      Block name.
 * @param string       $attribute Attribute to bind.
 * @param string       $source    Source name.
 * @param array<mixed> $args      Args as given.
 * @param string[]     $bindable  Attributes the block can bind, from get_block_bindings_supported_attributes().
 * @param string       $post_type Post type whose fields the args are checked against.
 * @return true|WP_Error
 */
function check_binding( string $name, string $attribute, string $source, array $args, array $bindable, string $post_type ) {
	if ( ! in_array( $attribute, $bindable, true ) ) {
		return new WP_Error(
			'voyager_open_blocks_invalid_attribute',
			sprintf( '"%s" is not bindable on %s. Bindable: %s.', $attribute, $name, $bindable ? implode( ', ', $bindable ) : 'none' ),
			[ 'status' => 400 ]
		);
	}

	if ( null === get_block_bindings_source( $source ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_source', sprintf( 'The binding source %s is not registered on this site.', $source ), [ 'status' => 400 ] );
	}

	return match_args( $source, $post_type, $args );
}

/**
 * Errors and warnings for the bindings a block declares, as validate reports them.
 *
 * A binding to one of SOURCES gets bind's checks (check_binding()). When the
 * source is registered but its fields cannot be listed (another plugin owns
 * it and does not describe them), the args cannot be checked: a warning, not
 * an error. A binding to any other registered source, such as
 * core/pattern-overrides, is not checked here either: a warning. A binding to
 * a source no plugin registers never resolves, so it is an error.
 *
 * @param string   $name      Block name.
 * @param string   $path      Block path, for the messages.
 * @param mixed    $bindings  metadata.bindings as the block declares it.
 * @param string[] $bindable  Attributes the block can bind, from get_block_bindings_supported_attributes().
 * @param string   $post_type Post type whose fields the args are checked against.
 * @return array{errors: string[], warnings: string[]}
 */
function binding_problems( string $name, string $path, mixed $bindings, array $bindable, string $post_type ): array {
	$errors   = [];
	$warnings = [];

	if ( ! is_array( $bindings ) ) {
		return [
			'errors'   => [ sprintf( '%s at %s: metadata.bindings must be an object of attribute => { "source", "args" }.', $name, $path ) ],
			'warnings' => [],
		];
	}

	foreach ( $bindings as $attribute => $binding ) {
		$at     = sprintf( '%s at %s, binding "%s": ', $name, $path, (string) $attribute );
		$source = is_array( $binding ) && isset( $binding['source'] ) && is_string( $binding['source'] ) ? $binding['source'] : '';
		$args   = is_array( $binding ) && isset( $binding['args'] ) && is_array( $binding['args'] ) ? $binding['args'] : [];

		if ( '' === $source ) {
			$errors[] = $at . 'it names no source.';
			continue;
		}

		$registered = null !== get_block_bindings_source( $source );

		if ( ! in_array( $source, SOURCES, true ) ) {
			if ( $registered ) {
				$warnings[] = $at . sprintf( 'the source %s is registered, but its args cannot be checked here.', $source );
			} else {
				$errors[] = $at . sprintf( 'the binding source %s is not registered on this site, so the block always shows its authored text.', $source );
			}
			continue;
		}

		$checked = check_binding( $name, (string) $attribute, $source, $args, $bindable, $post_type );

		if ( true === $checked ) {
			continue;
		}

		if ( $registered && 'voyager_open_blocks_invalid_source' === $checked->get_error_code() ) {
			$warnings[] = $at . $checked->get_error_message();
		} else {
			$errors[] = $at . $checked->get_error_message();
		}
	}

	return [
		'errors'   => $errors,
		'warnings' => $warnings,
	];
}
