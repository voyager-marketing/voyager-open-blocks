<?php
/**
 * The voyager/site-data binding source.
 *
 * Site-wide business details (name, phone, email, address, hours, social
 * links) that any bindable block attribute can read. Values live in plain
 * `voyager_site_*` options, set under Settings > Site Data.
 *
 * The source name and option names match the voyager-blocks plugin on
 * purpose: a pattern bound here resolves the same way on a site running
 * voyager-blocks. When that plugin is active it registers this source first,
 * and this module stands aside rather than registering it twice.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\SiteData;

use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\PostMetaText;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SOURCE = 'voyager/site-data';

/**
 * Field definitions: binding key => label, value type and stored option.
 *
 * Keys without an option are derived from other fields or from core.
 *
 * @return array<string, array{label: string, type: string, option?: string}>
 */
function fields(): array {
	return [
		'company'          => [ 'label' => __( 'Company name', 'voyager-open-blocks' ), 'type' => 'text' ],
		'tagline'          => [ 'label' => __( 'Tagline', 'voyager-open-blocks' ), 'type' => 'text' ],
		'phone'            => [ 'label' => __( 'Phone number', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_phone' ],
		'phone_link'       => [ 'label' => __( 'Phone link (tel:)', 'voyager-open-blocks' ), 'type' => 'url' ],
		'email'            => [ 'label' => __( 'Email address', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_email' ],
		'email_link'       => [ 'label' => __( 'Email link (mailto:)', 'voyager-open-blocks' ), 'type' => 'url' ],
		'address'          => [ 'label' => __( 'Street address', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_address' ],
		'address_city'     => [ 'label' => __( 'City', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_address_city' ],
		'address_state'    => [ 'label' => __( 'State or region', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_address_state' ],
		'address_zip'      => [ 'label' => __( 'Postal code', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_address_zip' ],
		'address_locality' => [ 'label' => __( 'City, State Postal code', 'voyager-open-blocks' ), 'type' => 'text' ],
		'hours'            => [ 'label' => __( 'Business hours', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_hours' ],
		'year'             => [ 'label' => __( 'Current year', 'voyager-open-blocks' ), 'type' => 'text' ],
		'copyright'        => [ 'label' => __( 'Copyright line', 'voyager-open-blocks' ), 'type' => 'text' ],
		'social_facebook'  => [ 'label' => __( 'Facebook URL', 'voyager-open-blocks' ), 'type' => 'url', 'option' => 'voyager_site_social_facebook' ],
		'social_instagram' => [ 'label' => __( 'Instagram URL', 'voyager-open-blocks' ), 'type' => 'url', 'option' => 'voyager_site_social_instagram' ],
		'social_linkedin'  => [ 'label' => __( 'LinkedIn URL', 'voyager-open-blocks' ), 'type' => 'url', 'option' => 'voyager_site_social_linkedin' ],
		'social_x'         => [ 'label' => __( 'X URL', 'voyager-open-blocks' ), 'type' => 'url', 'option' => 'voyager_site_social_x' ],
	];
}

/**
 * Resolve every key to its current value. Unset values are ''.
 *
 * Values are raw. Core escapes them at the point of use: rich text goes
 * through wp_kses_post() and attributes through the HTML API, so escaping
 * here would double-encode an ampersand in an attribute.
 *
 * @return array<string, string>
 */
function values(): array {
	$opt = static fn ( string $name ): string => trim( Cast\to_string( get_option( $name, '' ) ) );

	$company = (string) get_bloginfo( 'name' );
	$phone   = $opt( 'voyager_site_phone' );
	$email   = $opt( 'voyager_site_email' );
	$city    = $opt( 'voyager_site_address_city' );
	$state   = $opt( 'voyager_site_address_state' );
	$zip     = $opt( 'voyager_site_address_zip' );
	$year    = (string) wp_date( 'Y' );
	$digits  = preg_replace( '/[^0-9+]/', '', $phone ) ?? '';

	return [
		'company'          => $company,
		'tagline'          => (string) get_bloginfo( 'description' ),
		'phone'            => $phone,
		'phone_link'       => '' !== $digits ? 'tel:' . $digits : '',
		'email'            => $email,
		'email_link'       => is_email( $email ) ? 'mailto:' . $email : '',
		'address'          => $opt( 'voyager_site_address' ),
		'address_city'     => $city,
		'address_state'    => $state,
		'address_zip'      => $zip,
		'address_locality' => locality( $city, $state, $zip ),
		'hours'            => $opt( 'voyager_site_hours' ),
		'year'             => $year,
		'copyright'        => "\u{00A9} {$year} {$company}",
		'social_facebook'  => esc_url_raw( $opt( 'voyager_site_social_facebook' ) ),
		'social_instagram' => esc_url_raw( $opt( 'voyager_site_social_instagram' ) ),
		'social_linkedin'  => esc_url_raw( $opt( 'voyager_site_social_linkedin' ) ),
		'social_x'         => esc_url_raw( $opt( 'voyager_site_social_x' ) ),
	];
}

/**
 * Compose "City, ST 12345" without stranded punctuation when a part is empty.
 *
 * @param string $city  City.
 * @param string $state State or region.
 * @param string $zip   Postal code.
 */
function locality( string $city, string $state, string $zip ): string {
	$region = trim( $state . ' ' . $zip );

	if ( '' === $city ) {
		return $region;
	}

	return '' === $region ? $city : $city . ', ' . $region;
}

/**
 * Block Bindings resolver.
 *
 * An unknown key or an empty value returns null. Core then leaves the
 * attribute as authored, so a pattern keeps its placeholder copy on a site
 * whose details are not filled in yet instead of rendering an empty element.
 *
 * @param array<string, mixed> $source_args    Binding args, `{ "key": "phone" }`.
 * @param \WP_Block|null       $block_instance Block being rendered.
 * @param string               $attribute_name Bound attribute.
 */
function get_binding_value( array $source_args, $block_instance, string $attribute_name ): ?string {
	$key = isset( $source_args['key'] ) && is_string( $source_args['key'] ) ? $source_args['key'] : '';
	$all = values();

	if ( ! array_key_exists( $key, $all ) ) {
		return null;
	}

	/**
	 * Filter a resolved site-data value.
	 *
	 * @param string               $value          Resolved value.
	 * @param string               $key            Binding key.
	 * @param array<string, mixed> $source_args    Binding args.
	 * @param string               $attribute_name Bound attribute.
	 */
	$value = (string) apply_filters( 'voyager_site_data_value', $all[ $key ], $key, $source_args, $attribute_name );

	return '' === trim( $value ) ? null : $value;
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
 *
 * Runs at init:20, after voyager-blocks registers its sources (init:5).
 */
function register_source(): void {
	if ( null !== get_block_bindings_source( SOURCE ) ) {
		return;
	}

	register_block_bindings_source(
		SOURCE,
		[
			'label'              => __( 'Site data', 'voyager-open-blocks' ),
			'get_value_callback' => __NAMESPACE__ . '\get_binding_value',
		]
	);

	owned( true );

	// voyager-blocks registers the same options and screen when it owns the
	// source, so these only run when this plugin does.
	register_options();
	add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
	add_action( 'admin_menu', __NAMESPACE__ . '\add_settings_page' );
}
add_action( 'init', __NAMESPACE__ . '\register_source', 20 );

/**
 * Register one string option per stored field.
 *
 * Registered on init too (not only admin_init) with show_in_rest, so the
 * values are readable at /wp/v2/settings and settable over the REST API.
 */
function register_options(): void {
	foreach ( fields() as $key => $field ) {
		if ( ! isset( $field['option'] ) ) {
			continue;
		}

		register_setting(
			'voyager_site_data',
			$field['option'],
			[
				'type'              => 'string',
				'description'       => $field['label'],
				'default'           => '',
				'sanitize_callback' => 'url' === $field['type'] ? 'esc_url_raw' : ( 'email' === $key ? 'sanitize_email' : 'sanitize_text_field' ),
				'show_in_rest'      => true,
			]
		);
	}
}

/**
 * Settings sections and fields for Settings > Site Data.
 */
function register_settings(): void {
	add_settings_section(
		'voyager_site_data',
		'',
		static function (): void {
			echo '<p>' . esc_html__( 'Blocks bound to the voyager/site-data source show these values. A blank field leaves the pattern\'s own placeholder text in place.', 'voyager-open-blocks' ) . '</p>';
		},
		'voyager-site-data'
	);

	foreach ( fields() as $key => $field ) {
		if ( ! isset( $field['option'] ) ) {
			continue;
		}

		add_settings_field(
			$field['option'],
			$field['label'],
			__NAMESPACE__ . '\render_field',
			'voyager-site-data',
			'voyager_site_data',
			[
				'label_for' => $field['option'],
				'key'       => $key,
				'type'      => 'url' === $field['type'] ? 'url' : ( 'email' === $key ? 'email' : 'text' ),
			]
		);
	}

	if ( PostMetaText\owned() ) {
		add_settings_section(
			'voyager_open_blocks_bindable_meta',
			__( 'Bindable post meta', 'voyager-open-blocks' ),
			__NAMESPACE__ . '\render_bindable_meta',
			'voyager-site-data'
		);
	}
}

/**
 * The Bindable post meta section: a row per field stored in the
 * voyager_open_blocks_bindable_meta option, an empty row to add one, and the
 * fields registered in code, read-only. Saved with the rest of the form.
 */
function render_bindable_meta(): void {
	$rows  = PostMetaText\stored_rows();
	$code  = PostMetaText\code_fields();
	$types = [];

	foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
		$types[ $type->name ] = Cast\to_string( $type->labels->singular_name, $type->name );
	}

	echo '<p>' . esc_html__( 'Post meta listed here can be bound to blocks with the voyager/post-meta-text source and edited in place in the block editor. Text fields only. Keys must not start with an underscore or contain a dot.', 'voyager-open-blocks' ) . '</p>';
	echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Post type', 'voyager-open-blocks' ) . '</th><th scope="col">' . esc_html__( 'Meta key', 'voyager-open-blocks' ) . '</th><th scope="col">' . esc_html__( 'Label', 'voyager-open-blocks' ) . '</th><th scope="col">' . esc_html__( 'Remove', 'voyager-open-blocks' ) . '</th></tr></thead><tbody>';

	foreach ( $rows as $index => $row ) {
		$note = PostMetaText\in_fields( $row['post_type'], $row['key'], $code ) ? __( 'Registered in code, which applies. This row is dropped on save.', 'voyager-open-blocks' ) : '';
		render_meta_row( $index, $row, $types, $note );
	}

	render_meta_row( count( $rows ), null, $types, '' );

	foreach ( $code as $post_type => $keys ) {
		foreach ( $keys as $key => $label ) {
			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code><p class="description"><code>%3$s</code></p></td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( '*' === $post_type ? __( 'All post types', 'voyager-open-blocks' ) : ( $types[ $post_type ] ?? $post_type ) ),
				esc_html( (string) $key ),
				esc_html( binding_json( (string) $key ) ),
				esc_html( $label ),
				esc_html__( 'Registered in code', 'voyager-open-blocks' )
			);
		}
	}

	echo '</tbody></table>';
}

/**
 * One editable row of the Bindable post meta table.
 *
 * @param int                                                     $index Row index in the posted array.
 * @param array{post_type: string, key: string, label: string}|null $row   Stored row, or null for the empty add row.
 * @param array<string, string>                                   $types Public post types: name => label.
 * @param string                                                  $note  Shown under the row's remove box.
 */
function render_meta_row( int $index, ?array $row, array $types, string $note ): void {
	$name = static fn ( string $part ): string => PostMetaText\OPTION . "[{$index}][{$part}]";
	$type = $row['post_type'] ?? '';

	if ( '' !== $type && ! isset( $types[ $type ] ) ) {
		$types[ $type ] = $type;
	}

	echo '<tr><td><select name="' . esc_attr( $name( 'post_type' ) ) . '" aria-label="' . esc_attr__( 'Post type', 'voyager-open-blocks' ) . '">';
	foreach ( $types as $value => $label ) {
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $type, $value, false ), esc_html( $label ) );
	}
	echo '</select></td>';

	printf(
		'<td><input type="text" name="%1$s" value="%2$s" class="regular-text" aria-label="%3$s" placeholder="%4$s" />%5$s</td>',
		esc_attr( $name( 'key' ) ),
		esc_attr( $row['key'] ?? '' ),
		esc_attr__( 'Meta key', 'voyager-open-blocks' ),
		null === $row ? esc_attr__( 'Add a meta key', 'voyager-open-blocks' ) : '',
		null === $row ? '' : '<p class="description"><code>' . esc_html( binding_json( $row['key'] ) ) . '</code></p>'
	);

	printf(
		'<td><input type="text" name="%1$s" value="%2$s" class="regular-text" aria-label="%3$s" /></td>',
		esc_attr( $name( 'label' ) ),
		esc_attr( $row['label'] ?? '' ),
		esc_attr__( 'Label', 'voyager-open-blocks' )
	);

	if ( null === $row ) {
		echo '<td></td></tr>';
		return;
	}

	printf(
		'<td><input type="checkbox" name="%1$s" value="1" aria-label="%2$s" />%3$s</td></tr>',
		esc_attr( $name( 'remove' ) ),
		/* translators: %s: meta key. */
		esc_attr( sprintf( __( 'Remove %s', 'voyager-open-blocks' ), $row['key'] ) ),
		'' === $note ? '' : '<p class="description">' . esc_html( $note ) . '</p>'
	);
}

/**
 * The binding a meta key gives, as shown under each row.
 *
 * @param string $key Meta key.
 */
function binding_json( string $key ): string {
	return (string) wp_json_encode( [ 'source' => PostMetaText\SOURCE, 'args' => [ 'key' => $key ] ], JSON_UNESCAPED_SLASHES );
}

/**
 * Render one settings input with its binding key underneath.
 *
 * @param array{label_for: string, key: string, type: string} $args Field args.
 */
function render_field( array $args ): void {
	printf(
		'<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text" /><p class="description"><code>%4$s</code></p>',
		esc_attr( $args['type'] ),
		esc_attr( $args['label_for'] ),
		esc_attr( Cast\to_string( get_option( $args['label_for'], '' ) ) ),
		esc_html( '{"source":"voyager/site-data","args":{"key":"' . $args['key'] . '"}}' )
	);
}

/**
 * Add Settings > Site Data.
 */
function add_settings_page(): void {
	add_options_page(
		__( 'Site Data', 'voyager-open-blocks' ),
		__( 'Site Data', 'voyager-open-blocks' ),
		'manage_options',
		'voyager-site-data',
		static function (): void {
			echo '<div class="wrap"><h1>' . esc_html__( 'Site Data', 'voyager-open-blocks' ) . '</h1><form method="post" action="options.php">';
			settings_fields( 'voyager_site_data' );
			do_settings_sections( 'voyager-site-data' );
			submit_button();
			echo '</form></div>';
		}
	);
}
