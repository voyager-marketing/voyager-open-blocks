<?php
/**
 * The purge: when a field changes, tell page caches which pages show it.
 * Contract 2 in docs/v2-contracts.md, built on the usage index (usage.php).
 *
 * - A `voyager_site_*` option added, updated or deleted refreshes the
 *   published pages that show that Site Data field, or a field made from it
 *   (a call button bound to phone_link shows the phone), plus the front page.
 *   A field a template or template part shows sets purge_all, because every
 *   page may show it.
 * - An allowlisted bindable post meta field added, updated or deleted
 *   refreshes that post only, when the post or one of the theme's templates
 *   shows the field.
 *
 * Changes are collected for the whole request and purged on shutdown, so a
 * settings form that saves eleven fields purges once. A purge runs the
 * `voyager_open_blocks_purge_urls` filter, cleans each post's object cache
 * (clean_post_cache(), which some page caches listen to), fires
 * `voyager_open_blocks_purge`, and calls each active adapter from
 * `voyager_open_blocks_cache_adapters` (inc/cache/adapter.php). The last one
 * is recorded in `voyager_open_blocks_last_purge` and shown on Settings > Site
 * Data.
 *
 * Bounded on a site of any size: a purge looks up and lists at most
 * MOST_POSTS posts. When more show a field, it sets purge_all instead.
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
 * Note a change, or take the changes noted in this request.
 *
 * @param string $key     A Site Data key, or a post meta key with $post_id. '' notes nothing.
 * @param int    $post_id The post whose meta changed, 0 for Site Data.
 * @param bool   $take    Return the changes and forget them.
 * @return array{site_data: list<string>, post_meta: array<int, list<string>>} Keys sorted, without duplicates. Post meta by post ID.
 */
function pending( string $key = '', int $post_id = 0, bool $take = false ): array {
	/** @var array<array-key, true> $site */
	static $site = [];
	/** @var array<int, array<array-key, true>> $meta */
	static $meta = [];

	if ( '' !== $key ) {
		if ( $post_id > 0 ) {
			$meta[ $post_id ][ $key ] = true;
		} else {
			$site[ $key ] = true;
		}
	}

	ksort( $meta );
	$out = [
		'site_data' => keys_of( $site ),
		'post_meta' => array_map( keys_of( ... ), $meta ),
	];

	if ( $take ) {
		$site = [];
		$meta = [];
	}

	return $out;
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
 * added_option, updated_option and deleted_option: note a Site Data field.
 *
 * Core fires updated_option only when the value really changed, so saving a
 * form with the same values notes nothing.
 *
 * @param mixed $option Option name.
 */
function on_option( mixed $option ): void {
	$option = Cast\to_string( $option );

	if ( str_starts_with( $option, PREFIX ) && strlen( $option ) > strlen( PREFIX ) ) {
		note( substr( $option, strlen( PREFIX ) ) );
	}
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
 * More than MOST_POSTS changed posts are not looked at one by one: the purge
 * sets purge_all, if any page or template shows one of the fields.
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

	if ( count( $changes ) > MOST_POSTS ) {
		return ( $any ?? shown_anywhere( ... ) )( $rows ) ? plan( $source, 'post-meta', $keys, [], [], false, true ) : null;
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

	return plan( $source, 'post-meta', Usage\tidy( $found ), $post_ids, [], false );
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
 * Purge what this request changed, once per reason.
 *
 * @param (callable(list<string>): array{posts: list<int>, templates: list<string>})|null $lookup What shows some rows, for site_data_plan().
 * @param (callable(int): list<string>)|null                                           $shown  The rows a post shows, for post_meta_plan().
 * @return int Purges run.
 */
function run( ?callable $lookup = null, ?callable $shown = null ): int {
	$changes = pending( '', 0, true );

	if ( [] === $changes['site_data'] && [] === $changes['post_meta'] ) {
		return 0;
	}

	running( true );
	$ran = 0;

	try {
		if ( [] !== $changes['site_data'] && purge( site_data_plan( $changes['site_data'], $lookup ) ) ) {
			++$ran;
		}

		if ( [] !== $changes['post_meta'] && purge( post_meta_plan( $changes['post_meta'], $shown ) ) ) {
			++$ran;
		}
	} finally {
		running( false );
	}

	return $ran;
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
 * The line on Settings > Site Data, such as "Last refreshed 6 pages, 2 mins ago."
 *
 * @param array{time: int, urls: int, purge_all: bool, adapters: list<string>, failed: list<string>}|null $last   From last().
 * @param int                                                                                          $now    The time now.
 * @param array<string, string>                                                                        $labels Adapter ID => label.
 */
function last_line( ?array $last, int $now, array $labels = [] ): string {
	if ( null === $last ) {
		return __( 'No pages refreshed yet. When a field changes, the pages that show it are refreshed.', 'voyager-open-blocks' );
	}

	/* translators: %s: how long ago, such as "2 mins". */
	$ago = sprintf( __( '%s ago', 'voyager-open-blocks' ), human_time_diff( $last['time'], max( $now, $last['time'] ) ) );

	$line = $last['purge_all']
		/* translators: %s: how long ago, such as "2 mins ago". */
		? sprintf( __( 'Last refreshed every page, %s.', 'voyager-open-blocks' ), $ago )
		/* translators: 1: number of pages, 2: how long ago, such as "2 mins ago". */
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
