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
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SOURCE = 'voyager/site-data';

/**
 * Field definitions: binding key => label, value type and stored option.
 *
 * Keys without an option are derived from other fields or from core. A
 * `list` holds one item per line.
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
		'services'         => [ 'label' => __( 'Services', 'voyager-open-blocks' ), 'type' => 'list', 'option' => 'voyager_site_services' ],
		'service_area'     => [ 'label' => __( 'Service area', 'voyager-open-blocks' ), 'type' => 'list', 'option' => 'voyager_site_service_area' ],
		'credentials'      => [ 'label' => __( 'Credentials', 'voyager-open-blocks' ), 'type' => 'list', 'option' => 'voyager_site_credentials' ],
		'founded'          => [ 'label' => __( 'Year founded', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_founded' ],
		'license'          => [ 'label' => __( 'License number', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_license' ],
		'rating'           => [ 'label' => __( 'Rating', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_rating' ],
		'review_count'     => [ 'label' => __( 'Review count', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_review_count' ],
		'cta_label'        => [ 'label' => __( 'Main call to action text', 'voyager-open-blocks' ), 'type' => 'text', 'option' => 'voyager_site_cta_label' ],
		'cta_url'          => [ 'label' => __( 'Main call to action link', 'voyager-open-blocks' ), 'type' => 'url', 'option' => 'voyager_site_cta_url' ],
	];
}

/**
 * The keys shown under "Business profile" on Settings > Site Data, in order.
 */
const BUSINESS_PROFILE = [ 'services', 'service_area', 'credentials', 'founded', 'license', 'rating', 'review_count', 'cta_label', 'cta_url' ];

/**
 * Keys that values() composes from other keys: derived key => the keys it is made from.
 *
 * A pattern that binds `phone_link` shows nothing useful until the phone is
 * set, so the settings screen counts it as a use of the phone field.
 *
 * @return array<string, list<string>>
 */
function derived_from(): array {
	return [
		'phone_link'       => [ 'phone' ],
		'email_link'       => [ 'email' ],
		'address_locality' => [ 'address_city', 'address_state', 'address_zip' ],
		'copyright'        => [ 'company', 'year' ],
	];
}

/**
 * Which patterns read each key, directly or through a key derived from it.
 *
 * @param array<array{title: string, keys: list<string>}> $patterns Each pattern's title and the keys it binds, as Patterns\bound_keys() returns them.
 * @return array<string, list<string>> Every key of fields() => pattern titles. A key no pattern reads maps to [].
 */
function used_by( array $patterns ): array {
	$out = [];

	foreach ( array_keys( fields() ) as $key ) {
		$reads = [ $key ];
		foreach ( derived_from() as $derived => $parts ) {
			if ( in_array( $key, $parts, true ) ) {
				$reads[] = $derived;
			}
		}

		$titles = [];
		foreach ( $patterns as $pattern ) {
			if ( [] !== array_intersect( $reads, $pattern['keys'] ) ) {
				$titles[] = $pattern['title'];
			}
		}

		$out[ $key ] = $titles;
	}

	return $out;
}

/**
 * used_by() for the pack's own patterns, read from their intents once per request.
 *
 * @return array<string, list<string>>
 */
function usage(): array {
	/** @var array<string, list<string>>|null $usage */
	static $usage = null;

	if ( null === $usage ) {
		$usage = used_by( Patterns\bound_keys( SOURCE ) );
	}

	return $usage;
}

/**
 * The help line under a stored field on Settings > Site Data.
 *
 * @param string $key Binding key.
 */
function help( string $key ): string {
	switch ( $key ) {
		case 'phone':
			return __( 'Shown the way you type it, for example (555) 555-0100. Call buttons keep only the digits and +, so that number dials tel:5555550100.', 'voyager-open-blocks' );
		case 'email':
			return __( 'Shown as typed. Email buttons open a new message to it (mailto:) once it is a valid address, for example hello@example.com.', 'voyager-open-blocks' );
		case 'address':
			return __( 'Street and number, for example 100 Main Street, Suite 200.', 'voyager-open-blocks' );
		case 'address_city':
			return __( 'City, state and postal code also make one line, for example Springfield, ST 00000.', 'voyager-open-blocks' );
		case 'address_state':
			return __( 'A state, province or region. A short form such as ST reads best on the city line.', 'voyager-open-blocks' );
		case 'address_zip':
			return __( 'Also called a ZIP code. A blank part is left out of the city line, with its comma.', 'voyager-open-blocks' );
		case 'hours':
			return __( 'One line, written the way you want it shown, for example Monday to Friday, 9am to 5pm.', 'voyager-open-blocks' );
		case 'services':
			return __( 'One service per line, for example Drain cleaning. Blank lines are skipped.', 'voyager-open-blocks' );
		case 'service_area':
			return __( 'One town, county or area per line, for example Springfield. Blank lines are skipped.', 'voyager-open-blocks' );
		case 'credentials':
			return __( 'One per line: licenses, insurance, certifications and memberships you can show proof of, for example Licensed and insured.', 'voyager-open-blocks' );
		case 'founded':
			return __( 'The four-digit year the business started, for example 1998.', 'voyager-open-blocks' );
		case 'license':
			return __( 'As it is printed on the license, for example PL-000123.', 'voyager-open-blocks' );
		case 'rating':
			return __( 'Your average rating from 0 to 5, for example 4.8. Type it by hand from your own review profile and update it when it changes: the pack calls no review service.', 'voyager-open-blocks' );
		case 'review_count':
			return __( 'How many reviews that rating comes from, as a whole number with no commas, for example 127. Typed by hand from the same review profile: the pack calls no review service.', 'voyager-open-blocks' );
		case 'cta_label':
			return __( 'The words on the button you most want visitors to press, for example Book a visit.', 'voyager-open-blocks' );
		case 'cta_url':
			return __( 'Where that button goes: a full address starting with https://, for example https://example.com/contact/, or a tel: link.', 'voyager-open-blocks' );
	}

	return str_starts_with( $key, 'social_' )
		? __( 'The full profile address, starting with https://.', 'voyager-open-blocks' )
		: '';
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
		'services'         => resolve_list( $opt( 'voyager_site_services' ) ),
		'service_area'     => resolve_list( $opt( 'voyager_site_service_area' ) ),
		'credentials'      => resolve_list( $opt( 'voyager_site_credentials' ) ),
		'founded'          => resolve_year( $opt( 'voyager_site_founded' ) ),
		'license'          => $opt( 'voyager_site_license' ),
		'rating'           => resolve_rating( $opt( 'voyager_site_rating' ) ),
		'review_count'     => resolve_count( $opt( 'voyager_site_review_count' ) ),
		'cta_label'        => $opt( 'voyager_site_cta_label' ),
		'cta_url'          => esc_url_raw( $opt( 'voyager_site_cta_url' ) ),
	];
}

/**
 * A list: one item per line, each line trimmed, empty lines dropped, joined with "\n".
 *
 * Splits on \r\n, \r and \n, so a list saved from a form (which sends \r\n) reads the same.
 *
 * @param string $value Stored value.
 */
function resolve_list( string $value ): string {
	$lines = array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ?: [] );

	return implode( "\n", array_filter( $lines, static fn ( string $line ): bool => '' !== $line ) );
}

/**
 * A year: the trimmed value when it is four digits, else ''.
 *
 * @param string $value Stored value.
 */
function resolve_year( string $value ): string {
	$value = trim( $value );

	return 1 === preg_match( '/^[0-9]{4}$/D', $value ) ? $value : '';
}

/**
 * A rating: a number from 0 to 5 with one decimal, such as 4.8, else ''.
 *
 * @param string $value Stored value.
 */
function resolve_rating( string $value ): string {
	$value = trim( $value );

	if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 5 ) {
		return '';
	}

	return number_format( (float) $value, 1, '.', '' );
}

/**
 * A count: the trimmed value when it is digits only, as an integer string, else ''.
 *
 * @param string $value Stored value.
 */
function resolve_count( string $value ): string {
	$value = trim( $value );

	return 1 === preg_match( '/^[0-9]+$/D', $value ) ? (string) (int) $value : '';
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
				'sanitize_callback' => static fn ( mixed $value ): string => sanitize( $key, $value ),
				'show_in_rest'      => true,
			]
		);
	}
}

/**
 * Sanitize a value for a stored key, on the Settings form and over REST alike.
 *
 * URLs are trimmed and go through esc_url_raw(), the email through
 * sanitize_email() and a list through sanitize_textarea_field(), which keeps
 * its line breaks. sanitize_text_field() would join the lines into one. The
 * other text goes through sanitize_text_field(). The year, rating and
 * count are saved in the form values() resolves them to. A value that is not
 * a year, a rating or a count is refused as core refuses a bad option in
 * sanitize_option(): the option keeps its previous value, and the Settings
 * screen says why.
 *
 * @param string $key   Binding key of a stored field.
 * @param mixed  $value Submitted value.
 */
function sanitize( string $key, mixed $value ): string {
	$type  = fields()[ $key ]['type'] ?? 'text';
	$value = Cast\to_string( $value );

	if ( 'url' === $type ) {
		// esc_url() trims only the left, and turns a trailing space into %20.
		return esc_url_raw( trim( $value ) );
	}

	if ( 'list' === $type ) {
		return sanitize_textarea_field( $value );
	}

	if ( 'email' === $key ) {
		return sanitize_email( $value );
	}

	$value = sanitize_text_field( $value );

	switch ( $key ) {
		case 'founded':
			$clean = resolve_year( $value );
			$error = __( 'Year founded must be a four-digit year, such as 1998. The previous value was kept.', 'voyager-open-blocks' );
			break;
		case 'rating':
			$clean = resolve_rating( $value );
			$error = __( 'Rating must be a number from 0 to 5, such as 4.8. The previous value was kept.', 'voyager-open-blocks' );
			break;
		case 'review_count':
			$clean = resolve_count( $value );
			$error = __( 'Review count must be a whole number with no commas, such as 127. The previous value was kept.', 'voyager-open-blocks' );
			break;
		default:
			return $value;
	}

	if ( '' === $value || '' !== $clean ) {
		return $clean;
	}

	$option = 'voyager_site_' . $key;

	// add_settings_error() is admin-only, so a REST request keeps the value without a message.
	if ( function_exists( 'add_settings_error' ) ) {
		add_settings_error( $option, "invalid_{$option}", $error );
	}

	return Cast\to_string( get_option( $option, '' ) );
}

/**
 * Settings sections and fields for Settings > Site Data.
 */
function register_settings(): void {
	add_settings_section(
		'voyager_site_data',
		'',
		__NAMESPACE__ . '\render_intro',
		'voyager-site-data'
	);

	add_settings_section(
		'voyager_site_business',
		__( 'Business profile', 'voyager-open-blocks' ),
		__NAMESPACE__ . '\render_business_intro',
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
			in_array( $key, BUSINESS_PROFILE, true ) ? 'voyager_site_business' : 'voyager_site_data',
			[
				'label_for' => $field['option'],
				'key'       => $key,
				'type'      => control( $key ),
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
 * The intro at the top of Settings > Site Data, for someone who has never
 * bound a block.
 */
function render_intro(): void {
	echo '<p>' . esc_html__( 'Site Data keeps your business details in one place. Enter your phone, email, address and hours once. The pack\'s buttons and sections show them, and they change everywhere when you change them here.', 'voyager-open-blocks' ) . '</p>';

	echo '<p>';
	printf(
		/* translators: %s: link to Settings > General. */
		esc_html__( 'A blank field leaves the section\'s placeholder text in place. Your company name and tagline come from %s.', 'voyager-open-blocks' ),
		'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'voyager-open-blocks' ) . '</a>'
	);
	echo '</p>';

	echo '<p>' . esc_html__( 'Under each field: the patterns that use it, the published pages and the templates that show it, and the binding that shows it in your own blocks.', 'voyager-open-blocks' ) . '</p>';
}

/**
 * The intro under the Business profile heading on Settings > Site Data.
 */
function render_business_intro(): void {
	echo '<p>' . esc_html__( 'What your pages can say about the business: what it does, where, since when, and what backs it up. Fill in only what you can show proof of. A blank field leaves the section\'s placeholder text in place.', 'voyager-open-blocks' ) . '</p>';
}

/**
 * The form control for a stored key on Settings > Site Data: an input type, or `textarea` for a list.
 *
 * @param string $key Binding key.
 */
function control( string $key ): string {
	$type = fields()[ $key ]['type'] ?? 'text';

	if ( 'list' === $type ) {
		return 'textarea';
	}

	if ( 'url' === $type ) {
		return 'url';
	}

	return 'email' === $key ? 'email' : 'text';
}

/**
 * Render one settings input with its help, the patterns that use it, the
 * pages and templates that show it (inc/freshness/usage.php) and its binding
 * underneath.
 *
 * @param array{label_for: string, key: string, type: string} $args Field args.
 */
function render_field( array $args ): void {
	$id     = $args['label_for'];
	$titles = usage()[ $args['key'] ] ?? [];
	$used   = __( 'Used by: no pattern in the pack. Available for your own blocks.', 'voyager-open-blocks' );

	if ( [] !== $titles ) {
		/* translators: %s: pattern names, separated by commas. */
		$used = sprintf( __( 'Used by: %s', 'voyager-open-blocks' ), implode( ', ', $titles ) );
	}

	$value = Cast\to_string( get_option( $id, '' ) );

	if ( 'textarea' === $args['type'] ) {
		printf(
			'<textarea id="%1$s" name="%1$s" rows="5" class="large-text" aria-describedby="%1$s-help %1$s-used %1$s-shown">%2$s</textarea>',
			esc_attr( $id ),
			esc_textarea( $value )
		);
	} else {
		$mode = [
			'founded'      => 'numeric',
			'rating'       => 'decimal',
			'review_count' => 'numeric',
		][ $args['key'] ] ?? '';

		printf(
			'<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text"%4$s aria-describedby="%2$s-help %2$s-used %2$s-shown" />',
			esc_attr( $args['type'] ),
			esc_attr( $id ),
			esc_attr( $value ),
			'' === $mode ? '' : ' inputmode="' . esc_attr( $mode ) . '"'
		);
	}

	printf(
		'<p class="description" id="%1$s-help">%2$s</p><p class="description" id="%1$s-used">%3$s</p>',
		esc_attr( $id ),
		esc_html( help( $args['key'] ) ),
		esc_html( $used )
	);

	Usage\render_shown_on( $args['key'], $id );

	printf(
		'<p class="description"><code>%s</code></p>',
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
