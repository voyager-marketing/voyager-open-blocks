<?php
/**
 * The voyager/post-meta-text binding source.
 *
 * Reads an allowlisted post meta field for the post being rendered, with
 * dot-path access into JSON values (`stats.0.value`). Only fields that were
 * added under Settings > Site Data, or registered through
 * voyager_open_blocks_register_bindable_meta() or the
 * `voyager_post_meta_text_fields` filter, resolve; any other key returns
 * null, so a binding cannot be used to read arbitrary meta.
 *
 * Fields added on the screen are rows in the `voyager_open_blocks_bindable_meta`
 * option. Each row goes through register_field() on init:15, so it behaves
 * exactly like a helper call. A key registered in PHP wins over a row.
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

const OPTION = 'voyager_open_blocks_bindable_meta';

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
 * Fields registered from the option rows, in the same shape as registered().
 *
 * @param array<string, array<string, string>>|null $add Fields to merge in.
 * @return array<string, array<string, string>>
 */
function from_option( ?array $add = null ): array {
	static $fields = [];

	if ( null !== $add ) {
		$fields = array_replace_recursive( $fields, $add );
	}

	return $fields;
}

/**
 * Whether a meta key may be made bindable: non-empty, no leading underscore
 * (protected meta) and no dot (the dot starts a JSON path).
 *
 * @param string $key Meta key.
 */
function valid_key( string $key ): bool {
	return '' !== $key && ! str_starts_with( $key, '_' ) && ! str_contains( $key, '.' );
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
	if ( ! valid_key( $key ) ) {
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
 * Fields registered in PHP (the helper or the filter), without the option rows.
 *
 * @return array<string, array<string, string>>
 */
function code_fields(): array {
	$fields = allowed_fields();

	foreach ( from_option() as $post_type => $keys ) {
		foreach ( array_keys( $keys ) as $key ) {
			unset( $fields[ $post_type ][ $key ] );
		}
	}

	return array_filter( $fields );
}

/**
 * Whether a key is already allowlisted for a post type, directly or through '*'.
 *
 * @param string                               $post_type Post type.
 * @param string                               $key       Meta key.
 * @param array<string, array<string, string>> $fields    Allowlist to look in.
 */
function in_fields( string $post_type, string $key, array $fields ): bool {
	return isset( $fields['*'][ $key ] ) || isset( $fields[ $post_type ][ $key ] );
}

/**
 * Rows saved on Settings > Site Data.
 *
 * @return list<array{post_type: string, key: string, label: string}>
 */
function stored_rows(): array {
	$rows = [];

	foreach ( (array) get_option( OPTION, [] ) as $row ) {
		if ( ! is_array( $row ) || ! is_string( $row['post_type'] ?? null ) || ! is_string( $row['key'] ?? null ) ) {
			continue;
		}

		$label  = is_string( $row['label'] ?? null ) ? $row['label'] : '';
		$rows[] = [
			'post_type' => $row['post_type'],
			'key'       => $row['key'],
			'label'     => '' === $label ? $row['key'] : $label,
		];
	}

	return $rows;
}

/**
 * Register the stored rows, before the source registers at init:20.
 *
 * Skipped when another plugin already owns the source (voyager-blocks
 * registers it at init:5), because its own allowlist applies there. A row
 * whose key PHP already registered for that post type is skipped too: the
 * code is the developer's intent. A row that breaks the key rules is skipped
 * quietly, since the screen refused it when it was saved.
 */
function register_stored(): void {
	if ( null !== get_block_bindings_source( SOURCE ) ) {
		return;
	}

	$code = allowed_fields();

	foreach ( stored_rows() as $row ) {
		if ( ! valid_key( $row['key'] ) || in_fields( $row['post_type'], $row['key'], $code ) ) {
			continue;
		}

		if ( register_field( $row['post_type'], $row['key'], $row['label'] ) ) {
			from_option( [ $row['post_type'] => [ $row['key'] => $row['label'] ] ] );
		}
	}
}
add_action( 'init', __NAMESPACE__ . '\register_stored', 15 );

/**
 * Sanitize the rows posted from Settings > Site Data.
 *
 * Applies register_field()'s key rules and requires a registered post type.
 * A refused row is left out and reported as a settings error. A row with its
 * Remove box ticked, or with key and label both blank (the empty add row), is
 * dropped without a message.
 *
 * @param mixed $input Posted rows.
 * @return list<array{post_type: string, key: string, label: string}>
 */
function sanitize_rows( $input ): array {
	$text = static fn ( $value ): string => is_string( $value ) ? sanitize_text_field( $value ) : '';
	$code = code_fields();
	$rows = [];
	$seen = [];

	foreach ( is_array( $input ) ? $input : [] as $row ) {
		if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
			continue;
		}

		$post_type = sanitize_key( $text( $row['post_type'] ?? '' ) );
		$key       = $text( $row['key'] ?? '' );
		$label     = $text( $row['label'] ?? '' );

		if ( '' === $key && '' === $label ) {
			continue;
		}

		if ( ! valid_key( $key ) ) {
			/* translators: %s: meta key. */
			refuse( 'invalid_key', sprintf( __( 'The meta key "%s" was not saved. A bindable meta key must not be empty, start with an underscore or contain a dot.', 'voyager-open-blocks' ), $key ) );
			continue;
		}

		if ( ! post_type_exists( $post_type ) ) {
			/* translators: 1: meta key, 2: post type. */
			refuse( 'invalid_post_type', sprintf( __( 'The meta key "%1$s" was not saved. "%2$s" is not a registered post type.', 'voyager-open-blocks' ), $key, $post_type ) );
			continue;
		}

		if ( in_fields( $post_type, $key, $code ) ) {
			/* translators: %s: meta key. */
			refuse( 'registered_in_code', sprintf( __( 'The meta key "%s" was not saved. It is already registered in code for that post type, and the code registration applies.', 'voyager-open-blocks' ), $key ) );
			continue;
		}

		if ( isset( $seen[ "$post_type/$key" ] ) ) {
			continue;
		}

		$seen[ "$post_type/$key" ] = true;
		$rows[]                    = [
			'post_type' => $post_type,
			'key'       => $key,
			'label'     => '' === $label ? $key : $label,
		];
	}

	return $rows;
}

/**
 * Report a refused row on the settings screen.
 *
 * add_settings_error() is admin-only, so a write from WP-CLI or a front-end
 * request drops the row without a message.
 *
 * @param string $code    Error code.
 * @param string $message Message shown above the form.
 */
function refuse( string $code, string $message ): void {
	if ( function_exists( 'add_settings_error' ) ) {
		add_settings_error( OPTION, $code, $message );
	}
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

	// The rows behind Settings > Site Data's Bindable post meta section. Only
	// registered when this plugin owns the source, where the rows take effect.
	register_setting(
		'voyager_site_data',
		OPTION,
		[
			'type'              => 'array',
			'default'           => [],
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_rows',
			'show_in_rest'      => false,
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\register_source', 20 );
