<?php
/**
 * Plugin Name: Cache check: LiteSpeed server variable
 * Description: Test fixture for npm run cache-check -- --plugin litespeed-cache --spy. Never install it on a real site.
 *
 * LiteSpeed's web server sets the server variable X-LSCACHE when its page
 * cache is on. LiteSpeed Cache 7.9.3 reads it as it loads (litespeed-cache.php)
 * and only then may turn its own cache on: LITESPEED_ALLOWED, then
 * LITESPEED_ON in Conf::define_cache() (src/conf.cls.php) when its Enable
 * Cache setting is on. mu-plugins load before plugins, so this sets it on
 * every request, as the server would. See litespeed-cache/setup.php.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$_SERVER['X-LSCACHE'] = 'on';
