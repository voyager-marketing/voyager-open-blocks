<?php
/**
 * The voyager/post-meta-text binding source.
 *
 * Reads an allowlisted post meta field for the post being rendered, with
 * dot-path access into JSON values (`stats.0.value`). Only fields that were
 * registered through voyager_open_blocks_register_bindable_meta() or the
 * `voyager_post_meta_text_fields` filter resolve; any other key returns null,
 * so a binding cannot be used to read arbitrary meta.
 *
 * Fields registered with the helper are also registered as REST-visible post
 * meta. That is what lets the editor read and write them in place, and what
 * makes them appear in a Wesper manifest under contentModel.postTypes[].fields.
 *
 * Like voyager/site-data, the source name matches voyager-blocks, and this
 * module stands aside when that plugin has already registered it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\PostMetaText;

use VoyagerOpenBlocks\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SOURCE = 'voyager/post-meta-text';

/**
 * Fields registered through the helper: post type ('*' for all) => key => label.
 *
 * @param array<string, array<string, string>>|null $add Fields to merge in.
 * @return array<string, array<string, string>>
 */
function registered( ?array $add = null ): array {
	/** @var array<string, array<string, string>> $fields */
	static $fields = [];

	if ( null !== $add ) {
		/** @var array<string, array<string, string>> $fields */
		$fields = array_replace_recursive( $fields, $add );
	}

	return $fields;
}

/**
 * Make a post meta field bindable.
 *
 * Call on init. Registers the meta as a single REST-visible string that
 * users who can edit the post may change, and adds it to the allowlist.
 *
 * @param string $post_type Post type, or '' for every post type.
 * @param string $key       Meta key. A leading underscore (protected meta) is refused.
 * @param string $label     Human label shown in the editor's binding picker.
 */
function register_field( string $post_type, string $key, string $label ): bool {
	if ( '' === $key || str_starts_with( $key, '_' ) || str_contains( $key, '.' ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Bindable meta keys must be non-empty, unprotected (no leading underscore) and contain no dot.', 'voyager-open-blocks' ), '0.1.0' );
		return false;
	}

	$registered = register_post_meta(
		$post_type,
		$key,
		[
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'label'             => $label,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_textarea_field',
			'auth_callback'     => static fn ( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', Cast\to_int( $post_id ) ),
		]
	);

	if ( $registered ) {
		registered( [ '' === $post_type ? '*' : $post_type => [ $key => $label ] ] );
	}

	return $registered;
}

/**
 * The allowlist: post type => key => label, with '*' meaning every type.
 *
 * @return array<string, array<string, string>>
 */
function allowed_fields(): array {
	/**
	 * Filter the fields voyager/post-meta-text may read.
	 *
	 * Add a field here only when it is safe to print on the front end.
	 * Prefer voyager_open_blocks_register_bindable_meta(), which also makes
	 * the field editable in place and visible to Wesper.
	 *
	 * @param array<string, array<string, string>> $fields Post type ('*' for all) => meta key => label.
	 */
	/** @var mixed $fields A callback may return anything. */
	$fields = apply_filters( 'voyager_post_meta_text_fields', registered() );

	if ( ! is_array( $fields ) ) {
		return [];
	}

	/** @var array<string, array<string, string>> $fields */
	return $fields;
}

/**
 * Fields that apply to one post type, including the '*' set.
 *
 * @param string $post_type Post type.
 * @return array<string, string> Meta key => label.
 */
function fields_for( string $post_type ): array {
	$all = allowed_fields();

	return array_merge( $all['*'] ?? [], $all[ $post_type ] ?? [] );
}

/**
 * Resolve a value, following a dot path into JSON when the key has one.
 *
 * @param int    $post_id Post ID.
 * @param string $key     `field` or `field.path.0.into.json`.
 */
function resolve( int $post_id, string $key ): ?string {
	$parts = explode( '.', $key );
	$base  = array_shift( $parts );

	if ( ! array_key_exists( $base, fields_for( (string) get_post_type( $post_id ) ) ) ) {
		return null;
	}

	$value = get_post_meta( $post_id, $base, true );

	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return null;
	}

	if ( [] === $parts ) {
		return $value;
	}

	$current = json_decode( $value, true );

	foreach ( $parts as $segment ) {
		if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
			return null;
		}
		$current = $current[ $segment ];
	}

	return is_scalar( $current ) && '' !== trim( (string) $current ) ? (string) $current : null;
}

/**
 * Block Bindings resolver.
 *
 * @param array<string, mixed> $source_args    Binding args, `{ "key": "eyebrow" }`.
 * @param \WP_Block|null       $block_instance Block being rendered.
 * @param string               $attribute_name Bound attribute.
 */
function get_binding_value( array $source_args, $block_instance, string $attribute_name ): ?string {
	$key = isset( $source_args['key'] ) && is_string( $source_args['key'] ) ? $source_args['key'] : '';

	if ( '' === $key ) {
		return null;
	}

	$post_id = Cast\to_int( $block_instance->context['postId'] ?? 0 );

	if ( ! $post_id ) {
		$post_id = (int) get_the_ID();
	}

	if ( ! $post_id || ( post_password_required( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) ) {
		return null;
	}

	return resolve( $post_id, $key );
}

/**
 * Whether this plugin registered the source on this request.
 *
 * False when another plugin (voyager-blocks) registered it first.
 *
 * @param bool $set Mark the source as registered here.
 */
function owned( bool $set = false ): bool {
	/** @var bool $owned */
	static $owned = false;

	if ( $set ) {
		$owned = true;
	}

	return $owned;
}

/**
 * Register the source unless another plugin already has.
 */
function register_source(): void {
	if ( null !== get_block_bindings_source( SOURCE ) ) {
		return;
	}

	register_block_bindings_source(
		SOURCE,
		[
			'label'              => __( 'Post meta (allowlisted)', 'voyager-open-blocks' ),
			'get_value_callback' => __NAMESPACE__ . '\get_binding_value',
			'uses_context'       => [ 'postId', 'postType' ],
		]
	);

	owned( true );
}
add_action( 'init', __NAMESPACE__ . '\register_source', 20 );
