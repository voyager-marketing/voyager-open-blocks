<?php
/**
 * PHPUnit bootstrap for the unit suite.
 *
 * No WordPress is loaded. wp-stubs.php defines the few core functions the
 * modules call, backed by arrays in $GLOBALS['voyager_test_*'].
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/inc/cast.php';
require_once dirname( __DIR__ ) . '/inc/site-data.php';
require_once dirname( __DIR__ ) . '/inc/post-meta-text.php';
require_once dirname( __DIR__ ) . '/inc/block-tree.php';
require_once dirname( __DIR__ ) . '/inc/catalog.php';
