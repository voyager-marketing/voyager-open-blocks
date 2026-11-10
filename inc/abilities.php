<?php
/**
 * The abilities: inc/abilities/shared.php, then one file per ability. Start in shared.php.
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abilities/shared.php';
require_once __DIR__ . '/abilities/get-catalog.php';
require_once __DIR__ . '/abilities/find-posts.php';
require_once __DIR__ . '/abilities/get-post-blocks.php';
require_once __DIR__ . '/abilities/insert.php';
require_once __DIR__ . '/abilities/configure.php';
require_once __DIR__ . '/abilities/bind.php';
require_once __DIR__ . '/abilities/validate.php';
