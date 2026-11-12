<?php
/**
 * Stubs for tests/Unit/ContactDetailsTest.php, on top of tests/wp-stubs.php
 * and tests/facts-stubs.php (esc_html, get_block_wrapper_attributes).
 *
 * Each is guarded, so a stub that tests/wp-stubs.php adds later wins.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * As core's esc_url() in WordPress 7.1 (wp-includes/formatting.php), for
	 * the URLs these tests pass: a space becomes %20, characters a URL cannot
	 * hold are dropped, a value with no scheme that is not relative gets
	 * http://, an ampersand becomes &#038; and a single quote &#039;, and a
	 * scheme outside wp_allowed_protocols() returns ''.
	 */
	function esc_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$url = str_replace( ' ', '%20', ltrim( $url ) );
		$url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url );

		if ( '' === $url ) {
			return '';
		}

		if ( ! str_contains( $url, ':' ) && ! in_array( $url[0], [ '/', '#', '?' ], true ) ) {
			$url = 'http://' . $url;
		}

		$url = (string) preg_replace( '/&(?!#?[a-z0-9]+;)/i', '&amp;', $url );
		$url = str_replace( [ '&amp;', "'" ], [ '&#038;', '&#039;' ], $url );

		$allowed = [ 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' ];

		if ( '/' !== $url[0] && 1 === preg_match( '/^([^:\/?#]+):/', $url, $scheme ) && ! in_array( strtolower( $scheme[1] ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}
}
