<?php
/**
 * A WP Super Cache plugin, for the cache check only: makes Playground report
 * PHP_SELF the way a web server does.
 *
 * Playground's CLI runs PHP with the SAPI name "cli" (worker-thread-v1.js in
 * @wp-playground/cli 3.1.60 boots it with sapiName: "cli"), and for a pretty
 * permalink it sets PHP_SELF to the request path, /cache-check/, while it
 * routes the request to /index.php. WP Super Cache 3.1.5's wpsc_is_backend()
 * (wp-cache-phase2.php) treats any request whose PHP_SELF is not index.php as
 * a command-line run when PHP_SAPI is "cli", so in Playground it would
 * neither store nor serve any page but the front page. Apache or nginx
 * routing the same permalink to WordPress reports PHP_SELF as /index.php,
 * which is what this sets.
 *
 * wp-cache-phase1.php requires every file in plugins/wp-super-cache-plugins/
 * before its first call to wpsc_is_backend(). setup.php copies this there.
 *
 * Test fixture. Never install it on a real site.
 *
 * @package VoyagerOpenBlocks
 */

if (
	isset( $_SERVER['SCRIPT_NAME'], $_SERVER['PHP_SELF'] )
	&& '/index.php' === $_SERVER['SCRIPT_NAME']
	&& '.php' !== substr( (string) $_SERVER['PHP_SELF'], -4 )
) {
	$_SERVER['PHP_SELF'] = '/index.php';
}
