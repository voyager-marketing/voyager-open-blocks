<?php
/**
 * Stubs for tests/Unit/ImageWarningsTest.php and tests/Unit/MarqueeTest.php,
 * on top of tests/wp-stubs.php.
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
	 * The calls validate's image checks and the Marquee's row copy make on
	 * core's class, and nothing else.
	 *
	 * next_tag() moves to the next opening tag, by name when the query gives
	 * `tag_name`, matched without regard to case, and skips comments.
	 * get_attribute() returns what core's does: null for an attribute the tag
	 * does not have, true for one written without a value, and otherwise the
	 * value with its character references decoded. The first of two
	 * attributes with one name wins, as in core. set_attribute(),
	 * remove_attribute() and add_class() change the current tag, which is
	 * then written again with each value double-quoted and escaped, and true
	 * as a name alone; get_updated_html() returns the HTML with every change.
	 * The e2e suite runs the real class on what the abilities save and on the
	 * rendered marquee.
	 */
	class WP_HTML_Tag_Processor {

		private int $offset = 0;

		/**
		 * Attributes of the current tag, by lowercase name.
		 *
		 * @var array<string, string|true>|null
		 */
		private ?array $attributes = null;

		/**
		 * The current tag: where it starts, how long it is, its name, and
		 * whether it ends in `/>`.
		 *
		 * @var array{start: int, length: int, name: string, void: bool}|null
		 */
		private ?array $tag = null;

		/**
		 * Rewritten tags: start => [length, new tag].
		 *
		 * @var array<int, array{int, string}>
		 */
		private array $edits = [];

		public function __construct( private string $html ) {}

		public function set_attribute( string $name, string|bool $value ): bool {
			if ( null === $this->attributes ) {
				return false;
			}
			if ( false === $value ) {
				return $this->remove_attribute( $name );
			}
			$this->attributes[ strtolower( $name ) ] = $value;
			$this->rewrite();
			return true;
		}

		public function remove_attribute( string $name ): bool {
			if ( null === $this->attributes || ! isset( $this->attributes[ strtolower( $name ) ] ) ) {
				return false;
			}
			unset( $this->attributes[ strtolower( $name ) ] );
			$this->rewrite();
			return true;
		}

		public function add_class( string $class_name ): bool {
			$classes = $this->attributes['class'] ?? '';
			$classes = is_string( $classes ) ? preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY ) : [];
			if ( false === $classes ) {
				return false;
			}
			if ( in_array( $class_name, $classes, true ) ) {
				return null !== $this->attributes;
			}
			return $this->set_attribute( 'class', implode( ' ', [ ...$classes, $class_name ] ) );
		}

		public function get_updated_html(): string {
			$html = $this->html;
			krsort( $this->edits );
			foreach ( $this->edits as $start => [ $length, $tag ] ) {
				$html = substr_replace( $html, $tag, $start, $length );
			}
			return $html;
		}

		/**
		 * Write the current tag again from its attributes.
		 */
		private function rewrite(): void {
			if ( null === $this->tag || null === $this->attributes ) {
				return;
			}
			$tag = '<' . $this->tag['name'];
			foreach ( $this->attributes as $name => $value ) {
				$tag .= true === $value ? " {$name}" : " {$name}=\"" . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '"';
			}
			$this->edits[ $this->tag['start'] ] = [ $this->tag['length'], $tag . ( $this->tag['void'] ? ' />' : '>' ) ];
		}

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
					$this->tag        = [
						'start'  => $match[0][1],
						'length' => strlen( $match[0][0] ),
						'name'   => $match[1][0],
						'void'   => str_ends_with( rtrim( $match[2][0] ), '/' ),
					];
					return true;
				}
			}

			$this->attributes = null;
			$this->tag        = null;
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
