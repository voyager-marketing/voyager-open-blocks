<?php
/**
 * How the pack's cache adapters call a cache plugin: fail soft.
 *
 * A cache plugin's purge can throw, from a bug, a filesystem error or a
 * version the adapter was not written against. When it does, the adapter
 * reports it through wp_trigger_error(), which WordPress writes to the debug
 * log while WP_DEBUG is on, and goes on with the next URL, so one page that
 * fails does not leave the rest stale. Then it throws once, which purge() in
 * inc/freshness/purge.php catches: it records the adapter as failed, shows
 * that on Settings > Site Data, and still calls the other adapters. The
 * voyager_open_blocks_purge action has fired by then, so a site's own
 * listener hears the purge whatever a cache plugin does.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge each URL through a cache plugin. A URL whose purge throws is
 * reported, and the rest are still purged.
 *
 * @param Adapter                $adapter The adapter calling.
 * @param list<string>           $urls    Absolute URLs.
 * @param callable(string): void $purge   Purges one URL.
 * @throws \RuntimeException When any URL's purge threw, once, after all of them.
 */
function purge_each( Adapter $adapter, array $urls, callable $purge ): void {
	$failed = 0;
	$last   = null;

	foreach ( $urls as $url ) {
		try {
			$purge( $url );
		} catch ( \Throwable $error ) {
			++$failed;
			$last = $error;
			report( $adapter, $url, $error );
		}
	}

	if ( null !== $last ) {
		throw new \RuntimeException( sprintf( '%1$s failed to purge %2$d of %3$d URLs.', $adapter->id(), $failed, count( $urls ) ), 0, $last ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by the purge, never printed.
	}
}

/**
 * Purge everything through a cache plugin, reporting a failure.
 *
 * @param Adapter    $adapter The adapter calling.
 * @param callable(): void $purge Purges everything.
 * @throws \Throwable What the purge threw, after reporting it.
 */
function purge_once( Adapter $adapter, callable $purge ): void {
	try {
		$purge();
	} catch ( \Throwable $error ) {
		report( $adapter, 'every page', $error );
		throw $error;
	}
}

/**
 * Report a purge that threw, through WordPress's own wp_trigger_error().
 *
 * @param Adapter    $adapter The adapter calling.
 * @param string     $what    The URL, or "every page".
 * @param \Throwable $error   What the cache plugin threw.
 */
function report( Adapter $adapter, string $what, \Throwable $error ): void {
	wp_trigger_error(
		'',
		sprintf( 'Voyager Open Blocks: %1$s could not purge %2$s: %3$s', $adapter->label(), $what, $error->getMessage() ),
		E_USER_WARNING
	);
}
