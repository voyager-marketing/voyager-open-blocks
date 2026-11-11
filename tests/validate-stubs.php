<?php
/**
 * Stubs for tests/Unit/ImageWarningsTest.php, on top of tests/wp-stubs.php.
 *
 * Kept apart so the shared stubs stay as they are. Each is guarded, so a stub
 * that tests/wp-stubs.php adds later wins, and so does core's own class when
 * WordPress is loaded.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * As core for a full URL, a root-relative path and a protocol-relative URL,
	 * which PHP's parse_url() reads the same way.
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	/**
	 * The two calls validate's image checks make on core's class, and nothing else.
	 *
	 * next_tag() moves to the next opening tag, by name when the query gives
	 * `tag_name`, matched without regard to case, and skips comments.
	 * get_attribute() returns what core's does: null for an attribute the tag
	 * does not have, true for one written without a value, and otherwise the
	 * value with its character references decoded. The first of two
	 * attributes with one name wins, as in core. The e2e suite runs the real
	 * class on what the abilities save.
	 */
	class WP_HTML_Tag_Processor {

		private int $offset = 0;

		/**
		 * Attributes of the current tag, by lowercase name.
		 *
		 * @var array<string, string|true>|null
		 */
		private ?array $attributes = null;

		public function __construct( private string $html ) {}

		/**
		 * @param array{tag_name?: string}|string|null $query Tag to find, or null for any.
		 */
		public function next_tag( array|string|null $query = null ): bool {
			$want = is_array( $query ) ? ( $query['tag_name'] ?? null ) : $query;

			while ( 1 === preg_match( '/<!--.*?-->|<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $this->html, $match, PREG_OFFSET_CAPTURE, $this->offset ) ) {
				$this->offset = $match[0][1] + strlen( $match[0][0] );

				if ( ! isset( $match[1] ) || '' === $match[1][0] ) {
					continue;
				}

				if ( null === $want || 0 === strcasecmp( $want, $match[1][0] ) ) {
					$this->attributes = self::attributes( rtrim( $match[2][0], '/' ) );
					return true;
				}
			}

			$this->attributes = null;
			return false;
		}

		/**
		 * @param string $name Attribute name.
		 * @return string|true|null
		 */
		public function get_attribute( string $name ): string|bool|null {
			return $this->attributes[ strtolower( $name ) ] ?? null;
		}

		/**
		 * @param string $source A tag's text after its name.
		 * @return array<string, string|true>
		 */
		private static function attributes( string $source ): array {
			$attributes = [];

			preg_match_all( '/([^\s\/>"\'=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $source, $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );

			foreach ( $found as $attribute ) {
				$name  = strtolower( (string) $attribute[1] );
				$value = $attribute[2] ?? $attribute[3] ?? $attribute[4] ?? null;

				if ( ! isset( $attributes[ $name ] ) ) {
					$attributes[ $name ] = null === $value ? true : html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}

			return $attributes;
		}
	}
}
