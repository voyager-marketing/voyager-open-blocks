<?php
/**
 * The purge: when a field changes, tell page caches which pages show it.
 * Contract 2 in docs/v2-contracts.md, built on the usage index (usage.php).
 *
 * - A `voyager_site_*` option added, updated or deleted refreshes the
 *   published pages that show that Site Data field, or a field made from it
 *   (a call button bound to phone_link shows the phone), plus the front page.
 *   A field a template or template part shows sets purge_all, because every
 *   page may show it. The Site Title and Tagline count too (company, tagline),
 *   and a daily WP-Cron check refreshes the pages that show the year once
 *   it changes.
 * - An allowlisted bindable post meta field added, updated or deleted
 *   refreshes that post only, when the post or one of the theme's templates
 *   shows the field.
 *
 * Changes are collected for the whole request, by site, and purged on
 * shutdown on the site they were made on, so a settings form that saves
 * eleven fields purges once. A purge runs the
 * `voyager_open_blocks_purge_urls` filter, cleans each post's object cache
 * (clean_post_cache(), which some page caches listen to), fires
 * `voyager_open_blocks_purge`, and calls each active adapter from
 * `voyager_open_blocks_cache_adapters` (inc/cache/adapter.php). The last one
 * is recorded in `voyager_open_blocks_last_purge` and shown on Settings > Site
 * Data.
 *
 * Bounded on a site of any size: a purge looks up, lists and cleans at most
 * MOST_POSTS posts. When more show or change a field, it sets purge_all.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Purge;

use VoyagerOpenBlocks\Cache\Adapter;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;
use VoyagerOpenBlocks\Usage;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The action fired with the URLs to purge and the context. */
const ACTION = 'voyager_open_blocks_purge';

/** The filter on the URLs, run before the action. */
const URLS_FILTER = 'voyager_open_blocks_purge_urls';

/** The filter that returns the cache adapters. */
const ADAPTERS_FILTER = 'voyager_open_blocks_cache_adapters';

/** Option: the last purge, shown on Settings > Site Data. */
const LAST_OPTION = 'voyager_open_blocks_last_purge';

/** Site Data options start with this; the rest of the name is the field's key. */
const PREFIX = 'voyager_site_';

/** Most posts one purge looks up and lists. When more show a field, the purge sets purge_all. */
const MOST_POSTS = 50;

/**
 * Core options that hold a Site Data field: option => key. Site Data reads
 * them through get_bloginfo( 'name' ) and get_bloginfo( 'description' ),
 * which return get_option( 'blogname' ) and get_option( 'blogdescription' )
 * (wp-includes/general-template.php).
 */
const CORE_OPTIONS = [
	'blogname'        => 'company',
	'blogdescription' => 'tagline',
];

/** Option: the year the last year check saw, four digits. */
const YEAR_OPTION = 'voyager_open_blocks_year';

/** WP-Cron event, daily: note `year` when the year has changed. */
const YEAR_HOOK = 'voyager_open_blocks_year_check';

/**
 * The changes noted in this request, by the ID of the site current when each
 * was noted, so a change made under switch_to_blog() is purged on its site.
 *
 * @return array<int, array{site_data: array<array-key, true>, post_meta: array<int, array<array-key, true>>}>
 */
function &noted(): array {
	/** @var array<int, array{site_data: array<array-key, true>, post_meta: array<int, array<array-key, true>>}> $noted */
	static $noted = [];

	return $noted;
}

/**
 * Note a change on one site, or take the changes noted on it in this request.
 *
 * @param int    $site    Site ID.
 * @param string $key     A Site Data key, or a post meta key with $post_id. '' notes nothing.
 * @param int    $post_id The post whose meta changed, 0 for Site Data.
 * @param bool   $take    Return the changes and forget them.
 * @return array{site_data: list<string>, post_meta: array<int, list<string>>} Keys sorted, without duplicates. Post meta by post ID.
 */
function changes( int $site, string $key = '', int $post_id = 0, bool $take = false ): array {
	$noted = &noted();
	$set   = $noted[ $site ] ?? [
		'site_data' => [],
		'post_meta' => [],
	];

	if ( '' !== $key ) {
		if ( $post_id > 0 ) {
			$set['post_meta'][ $post_id ][ $key ] = true;
		} else {
			$set['site_data'][ $key ] = true;
		}
	}

	ksort( $set['post_meta'] );

	if ( $take || ( [] === $set['site_data'] && [] === $set['post_meta'] ) ) {
		unset( $noted[ $site ] );
	} else {
		$noted[ $site ] = $set;
	}

	return [
		'site_data' => keys_of( $set['site_data'] ),
		'post_meta' => array_map( keys_of( ... ), $set['post_meta'] ),
	];
}

/**
 * Note a change on the current site, or take the changes noted on it in this request.
 *
 * @param string $key     A Site Data key, or a post meta key with $post_id. '' notes nothing.
 * @param int    $post_id The post whose meta changed, 0 for Site Data.
 * @param bool   $take    Return the changes and forget them.
 * @return array{site_data: list<string>, post_meta: array<int, list<string>>} Keys sorted, without duplicates. Post meta by post ID.
 */
function pending( string $key = '', int $post_id = 0, bool $take = false ): array {
	return changes( get_current_blog_id(), $key, $post_id, $take );
}

/**
 * The IDs of the sites with changes noted in this request, in order.
 *
 * @return list<int>
 */
function sites(): array {
	$ids = array_keys( noted() );
	sort( $ids );

	return $ids;
}

/**
 * The keys of a set, as strings, sorted.
 *
 * @param array<array-key, true> $set Key => true.
 * @return list<string>
 */
function keys_of( array $set ): array {
	return Usage\tidy( array_map( 'strval', array_keys( $set ) ) );
}

/**
 * Whether a purge is running, so changes it causes are not noted again.
 *
 * @param bool|null $set Mark it running or done.
 */
function running( ?bool $set = null ): bool {
	/** @var bool $running */
	static $running = false;

	if ( null !== $set ) {
		$running = $set;
	}

	return $running;
}

/**
 * Note a change for the purge on shutdown.
 *
 * @param string $key     Site Data key, or post meta key.
 * @param int    $post_id The post whose meta changed, 0 for Site Data.
 */
function note( string $key, int $post_id = 0 ): void {
	if ( ! running() ) {
		pending( $key, $post_id );
	}
}

/**
 * added_option, updated_option and deleted_option: note a Site Data field,
 * from its `voyager_site_*` option or, for the company and the tagline, from
 * the Site Title and Tagline (CORE_OPTIONS). The copyright line is made from
 * the company, so the purge's lookup counts it (Usage\site_data_rows()).
 *
 * Core fires updated_option only when the value really changed, so saving a
 * form with the same values notes nothing.
 *
 * @param mixed $option Option name.
 */
function on_option( mixed $option ): void {
	$option = Cast\to_string( $option );

	if ( isset( CORE_OPTIONS[ $option ] ) ) {
		note( CORE_OPTIONS[ $option ] );
	} elseif ( str_starts_with( $option, PREFIX ) && strlen( $option ) > strlen( PREFIX ) ) {
		note( substr( $option, strlen( PREFIX ) ) );
	}
}

/**
 * Note `year` when the year has changed since the last check, once.
 *
 * Site Data resolves the year as wp_date( 'Y' ), in the site's time zone, and
 * stores it nowhere, so no option change marks a new year. This check does,
 * from a daily WP-Cron event (schedule_year_check()), and stores the year it
 * saw in YEAR_OPTION. The first check only stores it. `year` refreshes the
 * pages that show the year or the copyright line, which is made from it.
 *
 * @param string|null $now The year now. Defaults to wp_date( 'Y' ).
 * @return bool Whether it noted `year`.
 */
function check_year( ?string $now = null ): bool {
	$now  = $now ?? Cast\to_string( wp_date( 'Y' ) );
	$seen = Cast\to_string( get_option( YEAR_OPTION, '' ) );

	if ( '' === $now || $now === $seen ) {
		return false;
	}

	update_option( YEAR_OPTION, $now, false );

	if ( '' === $seen ) {
		return false;
	}

	note( 'year' );

	return true;
}

/**
 * The daily year event. What it notes is purged on shutdown, as any change is.
 */
function on_year_event(): void {
	check_year();
}

/**
 * init: schedule the daily year check, and store the year when none is stored.
 *
 * Once the event is scheduled, this asks wp_next_scheduled() only. That reads
 * the cron option, which WordPress saves with autoload on (_set_cron_array()
 * in wp-includes/cron.php), so a normal request runs no query for the check.
 * Core schedules its update checks on init the same way
 * (wp_schedule_update_checks() in wp-includes/update.php).
 *
 * The first check runs at 01:00 site time, so it falls soon after midnight,
 * and the hour keeps a daylight saving change from moving it before midnight.
 */
function schedule_year_check(): void {
	if ( false !== wp_next_scheduled( YEAR_HOOK ) ) {
		return;
	}

	wp_schedule_event( ( new \DateTimeImmutable( 'tomorrow 01:00', wp_timezone() ) )->getTimestamp(), 'daily', YEAR_HOOK );

	if ( '' === Cast\to_string( get_option( YEAR_OPTION, '' ) ) ) {
		update_option( YEAR_OPTION, Cast\to_string( wp_date( 'Y' ) ), false );
	}
}

/**
 * Deactivation: stop the year check.
 */
function stop(): void {
	wp_clear_scheduled_hook( YEAR_HOOK );
}

/**
 * added_post_meta, updated_post_meta and deleted_post_meta: note an
 * allowlisted bindable field.
 *
 * The allowlist decides, as it does for the binding source: a key the
 * `voyager_post_meta_text_fields` filter allows counts even with a leading
 * underscore, and the usage index's own rows never do.
 *
 * @param mixed $meta_id  Meta ID, or IDs when deleted.
 * @param mixed $post_id  Post ID.
 * @param mixed $meta_key Meta key.
 */
function on_meta( mixed $meta_id, mixed $post_id, mixed $meta_key ): void {
	$key = Cast\to_string( $meta_key );
	$id  = Cast\to_int( $post_id );

	if ( $id < 1 || '' === $key ) {
		return;
	}

	$type = get_post_type( $id );

	if ( ! is_string( $type ) || 'revision' === $type || ! array_key_exists( $key, PostMetaText\fields_for( $type ) ) ) {
		return;
	}

	note( $key, $id );
}

/**
 * IDs of published posts of the content types that show any of some rows,
 * oldest first, at most $limit.
 *
 * The same exact-match read as Usage\posts_using(), narrowed to the pages a
 * cache can hold and bounded, so a field shown on thousands of pages costs
 * one small query.
 *
 * @param list<string> $rows  Rows, such as `voyager/site-data:phone`.
 * @param int          $limit Most IDs.
 * @return list<int>
 */
function published_using( array $rows, int $limit ): array {
	$types = Usage\content_types();

	if ( [] === $rows || [] === $types || $limit < 1 ) {
		return [];
	}

	$found = Usage\select(
		[ 'postmeta', 'posts' ],
		'SELECT DISTINCT p.ID AS id FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = \'publish\''
			. ' AND pm.meta_value IN (' . implode( ', ', array_fill( 0, count( $rows ), '%s' ) ) . ')'
			. ' AND p.post_type IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')'
			. ' ORDER BY p.ID ASC LIMIT %d',
		Usage\META,
		...$rows,
		...$types,
		...[ $limit ]
	);

	return Usage\ids_of( array_column( $found, 'id' ) );
}

/**
 * What shows some rows: published posts, at most MOST_POSTS + 1 so a purge
 * can tell there are more, and the active theme's templates and parts.
 *
 * @param list<string> $rows Rows.
 * @return array{posts: list<int>, templates: list<string>}
 */
function lookup( array $rows ): array {
	$posts = published_using( $rows, MOST_POSTS + 1 );

	if ( [] !== $posts ) {
		_prime_post_caches( $posts, false, false );
	}

	return [
		'posts'     => $posts,
		'templates' => Usage\tidy( array_column( Usage\templates_with( $rows ), 'id' ) ),
	];
}

/**
 * Whether any published page, template or template part shows some rows.
 *
 * @param list<string> $rows Rows.
 */
function shown_anywhere( array $rows ): bool {
	return [] !== published_using( $rows, 1 ) || [] !== Usage\templates_with( $rows );
}

/**
 * The URLs and context of one purge, or null when nothing shows the change.
 *
 * Lists the permalinks of the first MOST_POSTS posts, and the front page when
 * asked. More posts than that, or a template, set purge_all.
 *
 * @param string       $source    Binding source.
 * @param string       $reason    `site-data` or `post-meta`.
 * @param list<string> $keys      The keys that changed.
 * @param list<int>    $post_ids  Posts that show the change, by ID.
 * @param list<string> $templates Templates and parts that show it.
 * @param bool         $front     Add the front page.
 * @param bool         $all       Purge everything whatever shows it.
 * @return array{urls: list<string>, context: array{source: string, keys: list<string>, post_ids: list<int>, templates: list<string>, reason: string, purge_all: bool}}|null
 */
function plan( string $source, string $reason, array $keys, array $post_ids, array $templates, bool $front, bool $all = false ): ?array {
	if ( [] === $post_ids && [] === $templates && ! $all ) {
		return null;
	}

	$over     = count( $post_ids ) > MOST_POSTS;
	$post_ids = array_slice( $post_ids, 0, MOST_POSTS );
	$urls     = [];

	foreach ( $post_ids as $id ) {
		$urls[] = Cast\to_string( get_permalink( $id ) );
	}

	if ( $front ) {
		$urls[] = home_url( '/' );
	}

	return [
		'urls'    => urls_of( $urls ),
		'context' => [
			'source'    => $source,
			'keys'      => $keys,
			'post_ids'  => $post_ids,
			'templates' => $templates,
			'reason'    => $reason,
			'purge_all' => $all || $over || [] !== $templates,
		],
	];
}

/**
 * The purge for Site Data keys that changed: the pages that show any of
 * them or a key made from them, plus the front page.
 *
 * @param list<string>                                                                $keys   Site Data keys.
 * @param (callable(list<string>): array{posts: list<int>, templates: list<string>})|null $lookup What shows some rows. Defaults to lookup().
 * @return array{urls: list<string>, context: array{source: string, keys: list<string>, post_ids: list<int>, templates: list<string>, reason: string, purge_all: bool}}|null
 */
function site_data_plan( array $keys, ?callable $lookup = null ): ?array {
	$rows = [];
	foreach ( $keys as $key ) {
		array_push( $rows, ...Usage\site_data_rows( $key ) );
	}

	$found = ( $lookup ?? lookup( ... ) )( Usage\tidy( $rows ) );

	return plan( SiteData\SOURCE, 'site-data', $keys, $found['posts'], $found['templates'], true );
}

/**
 * The purge for post meta that changed: each post that shows a field of its
 * own that changed, and nothing else. No front page, and never purge_all for
 * a template, because a template shows each post its own value.
 *
 * Past MOST_POSTS changed posts, as past MOST_POSTS pages for Site Data, the
 * first MOST_POSTS are looked at and cleaned, and the purge sets purge_all if
 * any page or template shows one of the fields. The rest are not looked at.
 *
 * @param array<int, list<string>>                $changes Post ID => meta keys that changed.
 * @param (callable(int): list<string>)|null      $shown   The rows a post shows, its own and its templates', [] unless it is published. Defaults to one built from the index.
 * @param (callable(list<string>): bool)|null     $any     Whether any published page or template shows some rows. Defaults to shown_anywhere().
 * @return array{urls: list<string>, context: array{source: string, keys: list<string>, post_ids: list<int>, templates: list<string>, reason: string, purge_all: bool}}|null
 */
function post_meta_plan( array $changes, ?callable $shown = null, ?callable $any = null ): ?array {
	$source = PostMetaText\SOURCE;
	$keys   = [];

	foreach ( $changes as $changed ) {
		array_push( $keys, ...$changed );
	}

	$keys = Usage\tidy( $keys );
	$rows = array_map( static fn ( string $key ): string => Usage\row( $source, $key ), $keys );
	$over = count( $changes ) > MOST_POSTS;

	if ( $over ) {
		$changes = array_slice( $changes, 0, MOST_POSTS, true );
	}

	if ( null === $shown ) {
		$in_templates = [];
		foreach ( Usage\templates_with( $rows ) as $template ) {
			array_push( $in_templates, ...$template['uses'] );
		}

		_prime_post_caches( array_keys( $changes ), false, true );
		$types = Usage\content_types();

		$shown = static function ( int $post_id ) use ( $in_templates, $types ): array {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, $types, true ) ) {
				return [];
			}

			return Usage\tidy( [ ...Usage\uses( $post_id ), ...$in_templates ] );
		};
	}

	$post_ids = [];
	$found    = [];

	foreach ( $changes as $post_id => $changed ) {
		$hit = array_values( array_intersect( array_map( static fn ( string $key ): string => Usage\row( $source, $key ), $changed ), $shown( $post_id ) ) );

		if ( [] !== $hit ) {
			$post_ids[] = $post_id;
			array_push( $found, ...$changed );
		}
	}

	if ( ! $over ) {
		return plan( $source, 'post-meta', Usage\tidy( $found ), $post_ids, [], false );
	}

	if ( [] === $post_ids && ! ( $any ?? shown_anywhere( ... ) )( $rows ) ) {
		return null;
	}

	return plan( $source, 'post-meta', $keys, $post_ids, [], false, true );
}

/**
 * Strings that are URLs, each once, in order.
 *
 * @param mixed $urls What the filter returned.
 * @return list<string>
 */
function urls_of( mixed $urls ): array {
	$out = [];

	foreach ( is_array( $urls ) ? $urls : [] as $url ) {
		if ( is_string( $url ) && '' !== $url && ! in_array( $url, $out, true ) ) {
			$out[] = $url;
		}
	}

	return $out;
}

/**
 * The cache adapters offered through the filter.
 *
 * @return list<Adapter>
 */
function adapters(): array {
	$found = apply_filters( ADAPTERS_FILTER, [] );
	$out   = [];

	foreach ( is_array( $found ) ? $found : [] as $adapter ) {
		if ( $adapter instanceof Adapter ) {
			$out[] = $adapter;
		}
	}

	return $out;
}

/**
 * Run one purge and record it.
 *
 * Runs the URL filter, cleans each post's object cache, fires the action and
 * calls every active adapter: purge_all() when the purge is purge_all or the
 * cache cannot purge single pages, purge_urls() otherwise. An adapter that
 * throws is recorded as failed, and the others still run.
 *
 * When the filter leaves no URLs and the purge is not purge_all, nothing
 * happens at all, so the filter can switch the purge off.
 *
 * @param array{urls: list<string>, context: array{source: string, keys: list<string>, post_ids: list<int>, templates: list<string>, reason: string, purge_all: bool}}|null $plan From plan().
 * @return bool Whether it ran.
 */
function purge( ?array $plan ): bool {
	if ( null === $plan ) {
		return false;
	}

	$context = $plan['context'];
	$urls    = urls_of( apply_filters( URLS_FILTER, $plan['urls'], $context ) );

	if ( [] === $urls && ! $context['purge_all'] ) {
		return false;
	}

	foreach ( $context['post_ids'] as $post_id ) {
		clean_post_cache( $post_id );
	}

	do_action( ACTION, $urls, $context );

	$told   = [];
	$failed = [];

	foreach ( adapters() as $adapter ) {
		$id = null;

		try {
			$id = $adapter->id();

			if ( ! $adapter->active() ) {
				continue;
			}

			if ( $context['purge_all'] || ! $adapter->supports_urls() ) {
				$adapter->purge_all();
			} else {
				$adapter->purge_urls( $urls );
			}

			$told[] = $id;
		} catch ( \Throwable $error ) {
			$failed[] = $id ?? get_class( $adapter );
		}
	}

	update_option(
		LAST_OPTION,
		[
			'time'      => time(),
			'source'    => $context['source'],
			'reason'    => $context['reason'],
			'keys'      => $context['keys'],
			'urls'      => count( $urls ),
			'purge_all' => $context['purge_all'],
			'adapters'  => $told,
			'failed'    => $failed,
		],
		false
	);

	return true;
}

/**
 * Purge what this request changed, on each site it changed, once per reason
 * on each.
 *
 * A site other than the current one is purged inside switch_to_blog(), so
 * its index, templates, permalinks, adapters and last purge are its own.
 * switch_to_blog() moves the database tables, the object cache and the
 * current site ID (wp-includes/ms-blogs.php); the plugins and hooks loaded
 * stay this request's.
 *
 * @param (callable(list<string>): array{posts: list<int>, templates: list<string>})|null $lookup What shows some rows, for site_data_plan().
 * @param (callable(int): list<string>)|null                                           $shown  The rows a post shows, for post_meta_plan().
 * @return int Purges run.
 */
function run( ?callable $lookup = null, ?callable $shown = null ): int {
	$sites = sites();

	if ( [] === $sites ) {
		return 0;
	}

	running( true );
	$ran = 0;

	try {
		foreach ( $sites as $site ) {
			$changes = changes( $site, '', 0, true );
			$ran    += on_site( $site, static fn (): int => purge_changes( $changes, $lookup, $shown ) );
		}
	} finally {
		running( false );
	}

	return $ran;
}

/**
 * Purge one site's changes, once per reason.
 *
 * @param array{site_data: list<string>, post_meta: array<int, list<string>>}                $changes From changes().
 * @param (callable(list<string>): array{posts: list<int>, templates: list<string>})|null $lookup  What shows some rows, for site_data_plan().
 * @param (callable(int): list<string>)|null                                           $shown   The rows a post shows, for post_meta_plan().
 * @return int Purges run.
 */
function purge_changes( array $changes, ?callable $lookup, ?callable $shown ): int {
	$ran = 0;

	if ( [] !== $changes['site_data'] && purge( site_data_plan( $changes['site_data'], $lookup ) ) ) {
		++$ran;
	}

	if ( [] !== $changes['post_meta'] && purge( post_meta_plan( $changes['post_meta'], $shown ) ) ) {
		++$ran;
	}

	return $ran;
}

/**
 * Run a callback with a site current: inside switch_to_blog() when it is not
 * the current one, which only a multisite network has.
 *
 * @param int             $site     Site ID.
 * @param callable(): int $callback What to run.
 * @return int What the callback returned.
 */
function on_site( int $site, callable $callback ): int {
	if ( get_current_blog_id() === $site || ! function_exists( 'switch_to_blog' ) ) {
		return $callback();
	}

	switch_to_blog( $site );

	try {
		return $callback();
	} finally {
		restore_current_blog();
	}
}

/**
 * shutdown: run the purge. Core passes the hook an empty string, which run()
 * must not take for a lookup.
 */
function on_shutdown(): void {
	run();
}

/**
 * The last purge, as stored, or null when there has been none.
 *
 * @return array{time: int, urls: int, purge_all: bool, adapters: list<string>, failed: list<string>}|null
 */
function last(): ?array {
	$stored = get_option( LAST_OPTION, null );

	if ( ! is_array( $stored ) || Cast\to_int( $stored['time'] ?? 0 ) < 1 ) {
		return null;
	}

	$list = static fn ( mixed $value ): array => array_values( array_map( Cast\to_string( ... ), is_array( $value ) ? $value : [] ) );

	return [
		'time'      => Cast\to_int( $stored['time'] ),
		'urls'      => Cast\to_int( $stored['urls'] ?? 0 ),
		'purge_all' => true === ( $stored['purge_all'] ?? false ),
		'adapters'  => $list( $stored['adapters'] ?? [] ),
		'failed'    => $list( $stored['failed'] ?? [] ),
	];
}

/**
 * The line on Settings > Site Data, such as "Last refreshed 6 pages, 2 minutes ago."
 *
 * @param array{time: int, urls: int, purge_all: bool, adapters: list<string>, failed: list<string>}|null $last   From last().
 * @param int                                                                                          $now    The time now.
 * @param array<string, string>                                                                        $labels Adapter ID => label.
 */
function last_line( ?array $last, int $now, array $labels = [] ): string {
	if ( null === $last ) {
		return __( 'No pages refreshed yet. When a field changes, the pages that show it are refreshed.', 'voyager-open-blocks' );
	}

	/* translators: %s: how long ago, such as "2 minutes". */
	$ago = sprintf( __( '%s ago', 'voyager-open-blocks' ), human_time_diff( $last['time'], max( $now, $last['time'] ) ) );

	$line = $last['purge_all']
		/* translators: %s: how long ago, such as "2 minutes ago". */
		? sprintf( __( 'Last refreshed every page, %s.', 'voyager-open-blocks' ), $ago )
		/* translators: 1: number of pages, 2: how long ago, such as "2 minutes ago". */
		: sprintf( _n( 'Last refreshed %1$d page, %2$s.', 'Last refreshed %1$d pages, %2$s.', $last['urls'], 'voyager-open-blocks' ), $last['urls'], $ago );

	$name = static fn ( string $id ): string => $labels[ $id ] ?? $id;

	if ( [] !== $last['adapters'] ) {
		/* translators: %s: cache names, separated by commas. */
		$line .= ' ' . sprintf( __( 'Caches told: %s.', 'voyager-open-blocks' ), implode( ', ', array_map( $name, $last['adapters'] ) ) );
	}

	if ( [] !== $last['failed'] ) {
		/* translators: %s: cache names, separated by commas. */
		$line .= ' ' . sprintf( __( 'These caches failed: %s.', 'voyager-open-blocks' ), implode( ', ', array_map( $name, $last['failed'] ) ) );
	}

	return $line;
}

/**
 * The last refresh at the top of Settings > Site Data.
 */
function render_last(): void {
	$labels = [];
	foreach ( adapters() as $adapter ) {
		$labels[ $adapter->id() ] = $adapter->label();
	}

	printf(
		'<p id="voyager-open-blocks-last-purge">%s</p>',
		esc_html( last_line( last(), time(), $labels ) )
	);
}

/**
 * admin_init, before Settings > Site Data registers its own section: add the
 * last refresh above it. Only when this plugin owns that screen.
 */
function add_section(): void {
	if ( SiteData\owned() ) {
		add_settings_section( 'voyager_open_blocks_last_purge', '', __NAMESPACE__ . '\render_last', 'voyager-site-data' );
	}
}

add_action( 'added_option', __NAMESPACE__ . '\on_option' );
add_action( 'updated_option', __NAMESPACE__ . '\on_option' );
add_action( 'deleted_option', __NAMESPACE__ . '\on_option' );
add_action( 'added_post_meta', __NAMESPACE__ . '\on_meta', 10, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\on_meta', 10, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\on_meta', 10, 3 );
add_action( 'shutdown', __NAMESPACE__ . '\on_shutdown', 0 );
add_action( 'admin_init', __NAMESPACE__ . '\add_section', 9 );
add_action( 'init', __NAMESPACE__ . '\schedule_year_check' );
add_action( YEAR_HOOK, __NAMESPACE__ . '\on_year_event' );
register_deactivation_hook( VOYAGER_OPEN_BLOCKS_FILE, __NAMESPACE__ . '\stop' );
