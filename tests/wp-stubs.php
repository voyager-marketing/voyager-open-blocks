<?php
/**
 * Minimal WordPress stubs for the unit suite.
 *
 * Plain global functions behind function_exists guards. State lives in
 * $GLOBALS['voyager_test_*'] and is reset by each test's setUp().
 *
 * - voyager_test_options        option name => value
 * - voyager_test_bloginfo       get_bloginfo() show => value
 * - voyager_test_post_meta      post ID => meta key => value
 * - voyager_test_post_types     post ID => post type
 * - voyager_test_filters        hook => priority => callbacks
 * - voyager_test_doing_it_wrong function names reported through _doing_it_wrong()
 * - voyager_test_registered_meta  keys registered through register_post_meta()
 * - voyager_test_binding_sources  source name => true, read by get_block_bindings_source()
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['voyager_test_filters'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$callbacks = $GLOBALS['voyager_test_filters'][ $hook ] ?? [];
		ksort( $callbacks );
		foreach ( $callbacks as $group ) {
			foreach ( $group as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['voyager_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return $GLOBALS['voyager_test_bloginfo'][ $show ] ?? '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ): string|false {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * An allowed protocol survives, any other yields '', and a space becomes %20 as in esc_url().
	 */
	function esc_url_raw( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $m )
			&& ! in_array( strtolower( $m[1] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ) {
			return '';
		}
		return str_replace( ' ', '%20', $url );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['voyager_test_doing_it_wrong'][] = $function_name;
	}
}

if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( string $post_type, string $meta_key, array $args = [] ): bool {
		$GLOBALS['voyager_test_registered_meta'][] = [ $post_type, $meta_key ];
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		return true;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ): string|false {
		return $GLOBALS['voyager_test_post_types'][ $post_id ] ?? false;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		return $GLOBALS['voyager_test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'get_block_bindings_source' ) ) {
	/**
	 * A stand-in source object for a name a test registered, null otherwise.
	 */
	function get_block_bindings_source( string $name ): ?object {
		return isset( $GLOBALS['voyager_test_binding_sources'][ $name ] ) ? (object) [ 'label' => $name ] : null;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/**
		 * @param string $code    Error code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( private string $code = '', private string $message = '', private mixed $data = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
