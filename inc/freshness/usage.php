<?php
/**
 * The usage index: which posts and templates show each Site Data and post meta
 * field, so a change refreshes only the pages that show it. Contract 1 in
 * docs/v2-contracts.md; Settings > Site Data and get-catalog report it.
 *
 * - Post meta `_voyager_open_blocks_uses`: one non-unique row per
 *   `<source>:<key>` a post's blocks bind at any depth, such as
 *   `voyager/site-data:phone`, through synced and registered patterns too.
 * - Post meta `_voyager_open_blocks_refs` (internal): the synced pattern IDs a
 *   post shows, so a changed pattern re-indexes them (QUEUE_OPTION, reindex()).
 * - Option `voyager_open_blocks_theme_uses`: file-only theme templates and
 *   parts, keyed by template ID (rebuild_theme()).
 *
 * Posts are indexed on save (on_save()), by a WP-Cron backfill (rebuild()) and
 * by `wp voyager-open-blocks reindex`. Reads are plain selects (select()).
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Usage;

use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post meta: one row per `<source>:<key>` the post shows. */
const META = '_voyager_open_blocks_uses';

/** Post meta: one row per synced pattern ID the post shows. */
const REFS = '_voyager_open_blocks_refs';

/** Option: file-only theme templates and parts, keyed by template ID. */
const THEME_OPTION = 'voyager_open_blocks_theme_uses';

/** Option: the plugin version the index was last backfilled for. */
const VERSION_OPTION = 'voyager_open_blocks_usage_version';

/** Option: the last post ID the running backfill indexed. */
const CURSOR_OPTION = 'voyager_open_blocks_usage_cursor';

/** WP-Cron event that indexes the next batch of the backfill. */
const BACKFILL_HOOK = 'voyager_open_blocks_usage_backfill';

/** WP-Cron event that rebuilds the theme templates on the next request. */
const THEME_HOOK = 'voyager_open_blocks_usage_theme';

/** Option: IDs of posts to index again, past the batch a synced pattern's save indexes itself. */
const QUEUE_OPTION = 'voyager_open_blocks_usage_queue';

/** WP-Cron event that indexes the next batch of QUEUE_OPTION. */
const QUEUE_HOOK = 'voyager_open_blocks_usage_queue';

/** Posts per backfill batch, and most posts a synced pattern's save indexes again itself. */
const BATCH = 50;

/** The binding sources the index records. */
const SOURCES = [ SiteData\SOURCE, PostMetaText\SOURCE ];

/** Post types that are templates rather than content. */
const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];

/** Post statuses that keep no rows: trashed, never saved, and revisions. */
const NOT_INDEXED = [ 'trash', 'auto-draft', 'inherit' ];

/**
 * The row a binding records: `<source>:<key>`.
 *
 * A voyager/post-meta-text key may be a dot path into a field; the row names
 * the field, the part before the first dot.
 *
 * @param string $source Binding source.
 * @param string $key    Binding key.
 */
function row( string $source, string $key ): string {
	if ( PostMetaText\SOURCE === $source && str_contains( $key, '.' ) ) {
		$key = (string) strstr( $key, '.', true );
	}

	return $source . ':' . $key;
}

/**
 * Strings sorted, without duplicates.
 *
 * @param array<string> $values Values.
 * @return list<string>
 */
function tidy( array $values ): array {
	$values = array_values( array_unique( $values ) );
	sort( $values, SORT_STRING );

	return $values;
}

/**
 * Walk blocks at every depth and collect what they bind.
 *
 * @param array<mixed>                                                      $blocks     Parsed blocks.
 * @param callable(int): array<mixed>                                       $synced     The blocks of a synced pattern, [] when there is none.
 * @param callable(string): array{rows: list<string>, blocks: array<mixed>} $registered What a registered pattern binds: rows already known, and blocks still to walk.
 * @param list<string>                                                      $seen       Patterns being walked, so one that holds itself stops.
 * @return array{rows: list<string>, refs: list<int>} Rows sorted and unique; synced pattern IDs, nested ones included.
 */
function walk( array $blocks, callable $synced, callable $registered, array $seen = [] ): array {
	$rows = [];
	$refs = [];

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$name     = $block['blockName'] ?? null;
		$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$metadata = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : [];
		$bindings = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : [];

		foreach ( $bindings as $binding ) {
			$source = is_array( $binding ) ? ( $binding['source'] ?? null ) : null;
			$args   = is_array( $binding ) ? ( $binding['args'] ?? null ) : null;
			$key    = is_array( $args ) ? ( $args['key'] ?? null ) : null;

			if ( is_string( $source ) && in_array( $source, SOURCES, true ) && is_string( $key ) && '' !== $key ) {
				$rows[] = row( $source, $key );
			}
		}

		$found = [];

		if ( 'core/block' === $name ) {
			$ref = Cast\to_int( $attrs['ref'] ?? 0 );
			if ( $ref > 0 ) {
				$refs[] = $ref;
				if ( ! in_array( 'ref:' . $ref, $seen, true ) ) {
					$found[] = walk( $synced( $ref ), $synced, $registered, [ ...$seen, 'ref:' . $ref ] );
				}
			}
		} elseif ( 'core/pattern' === $name ) {
			$slug = Cast\to_string( $attrs['slug'] ?? '' );
			if ( '' !== $slug && ! in_array( 'pattern:' . $slug, $seen, true ) ) {
				$pattern = $registered( $slug );
				array_push( $rows, ...$pattern['rows'] );
				$found[] = walk( $pattern['blocks'], $synced, $registered, [ ...$seen, 'pattern:' . $slug ] );
			}
		}

		$inner = $block['innerBlocks'] ?? null;
		if ( is_array( $inner ) && [] !== $inner ) {
			$found[] = walk( $inner, $synced, $registered, $seen );
		}

		foreach ( $found as $more ) {
			array_push( $rows, ...$more['rows'] );
			array_push( $refs, ...$more['refs'] );
		}
	}

	$refs = array_values( array_unique( $refs ) );
	sort( $refs );

	return [
		'rows' => tidy( $rows ),
		'refs' => $refs,
	];
}

/**
 * The blocks of a synced pattern, [] when the ID is not one that shows.
 *
 * @param int $ref Post ID from a core/block's ref.
 * @return array<mixed>
 */
function synced_blocks( int $ref ): array {
	$post = get_post( $ref );

	if ( ! $post instanceof WP_Post || 'wp_block' !== $post->post_type || in_array( $post->post_status, NOT_INDEXED, true ) ) {
		return [];
	}

	return parse_blocks( $post->post_content );
}

/**
 * The rows each of the pack's patterns binds, from the intents.
 *
 * @return array<string, list<string>> Pattern name => rows.
 */
function pack_rows(): array {
	/** @var array<string, list<string>>|null $rows */
	static $rows = null;

	if ( null === $rows ) {
		$rows = [];
		foreach ( SOURCES as $source ) {
			foreach ( Patterns\bound_keys( $source ) as $name => $pattern ) {
				$own = $rows[ $name ] ?? [];
				foreach ( $pattern['keys'] as $key ) {
					$own[] = row( $source, $key );
				}
				$rows[ $name ] = tidy( $own );
			}
		}
	}

	return $rows;
}

/**
 * What a registered pattern binds.
 *
 * The pack's own patterns are read from their intents. Any other pattern is
 * parsed from the registry, so a theme pattern that a template part holds
 * counts too.
 *
 * @param string $slug Pattern name.
 * @return array{rows: list<string>, blocks: array<mixed>}
 */
function registered_pattern( string $slug ): array {
	$pack = pack_rows();

	if ( isset( $pack[ $slug ] ) ) {
		return [
			'rows'   => $pack[ $slug ],
			'blocks' => [],
		];
	}

	$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
	$content = is_array( $pattern ) ? Cast\to_string( $pattern['content'] ?? '' ) : '';

	return [
		'rows'   => [],
		'blocks' => '' === $content ? [] : parse_blocks( $content ),
	];
}

/**
 * Walk markup with the site's own patterns.
 *
 * @param string $markup Block markup.
 * @return array{rows: list<string>, refs: list<int>}
 */
function walk_markup( string $markup ): array {
	if ( ! str_contains( $markup, '<!-- wp:' ) ) {
		return [
			'rows' => [],
			'refs' => [],
		];
	}

	return walk( parse_blocks( $markup ), synced_blocks( ... ), registered_pattern( ... ) );
}

/**
 * A post's rows of one meta key, sorted.
 *
 * @param int    $post_id Post ID.
 * @param string $key     META or REFS.
 * @return list<string>
 */
function stored( int $post_id, string $key ): array {
	$values = get_post_meta( $post_id, $key, false );

	return tidy( array_map( Cast\to_string( ... ), is_array( $values ) ? $values : [] ) );
}

/**
 * Replace a post's rows of one meta key, when they differ.
 *
 * @param int          $post_id Post ID.
 * @param string       $key     META or REFS.
 * @param list<string> $values  Rows, sorted.
 * @return bool Whether anything changed.
 */
function store( int $post_id, string $key, array $values ): bool {
	if ( stored( $post_id, $key ) === $values ) {
		return false;
	}

	delete_post_meta( $post_id, $key );
	foreach ( $values as $value ) {
		add_post_meta( $post_id, $key, $value, false );
	}

	return true;
}

/**
 * Index one post: the rows its blocks bind and the synced patterns it shows.
 *
 * A trashed or never-saved post keeps no rows.
 *
 * @param int $post_id Post ID.
 * @return bool Whether its rows or references changed.
 */
function index_post( int $post_id ): bool {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
		return false;
	}

	$found = in_array( $post->post_status, NOT_INDEXED, true )
		? [
			'rows' => [],
			'refs' => [],
		]
		: walk_markup( $post->post_content );

	$rows_changed = store( $post_id, META, $found['rows'] );
	$refs_changed = store( $post_id, REFS, tidy( array_map( 'strval', $found['refs'] ) ) );

	return $rows_changed || $refs_changed;
}

/**
 * The rows a post shows, sorted and without duplicates.
 *
 * @param int $post_id Post ID.
 * @return list<string>
 */
function uses( int $post_id ): array {
	return stored( $post_id, META );
}

/**
 * Post IDs from a column of query results, without zeros.
 *
 * @param array<mixed> $ids IDs as the database returns them.
 * @return list<int>
 */
function ids_of( array $ids ): array {
	return array_values( array_filter( array_map( Cast\to_int( ... ), $ids ) ) );
}

/**
 * Rows of a read query on the index, run directly rather than through WP_Query.
 *
 * WP_Query runs every plugin's query filters, and a multilingual plugin's
 * narrow a result to one language, which would hide pages that show a field.
 * The index is exact rows in postmeta, so these are plain selects, and
 * cheaper than WP_Query too.
 *
 * @param list<'posts'|'postmeta'|'term_relationships'|'term_taxonomy'|'terms'> $tables Tables for the query's first %i placeholders, in order.
 * @param literal-string                                                        $query  SQL with %i, %s and %d placeholders. Alias every column it selects.
 * @param mixed                                                                 ...$args Values for the placeholders after the tables.
 * @return list<array<mixed>>
 */
function select( array $tables, string $query, mixed ...$args ): array {
	global $wpdb;

	if ( ! $wpdb instanceof \wpdb ) {
		return [];
	}

	$names = [];
	foreach ( $tables as $table ) {
		$names[] = match ( $table ) {
			'posts'              => $wpdb->posts,
			'postmeta'           => $wpdb->postmeta,
			'term_relationships' => $wpdb->term_relationships,
			'term_taxonomy'      => $wpdb->term_taxonomy,
			'terms'              => $wpdb->terms,
		};
	}

	$sql = $wpdb->prepare( $query, ...$names, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- every caller passes a literal query with placeholders, which PHPStan checks.
	if ( ! is_string( $sql ) ) {
		return [];
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; the docblock says why not WP_Query.
	$found = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $found ) ? $found : [];
}

/**
 * IDs of every post, of any type and any indexed status, with one meta row.
 *
 * @param string $key   META or REFS.
 * @param string $value The row, matched exactly.
 * @return list<int>
 */
function posts_with( string $key, string $value ): array {
	$found = select(
		[ 'postmeta', 'posts' ],
		"SELECT DISTINCT pm.post_id AS post_id FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type <> 'revision' AND p.post_status NOT IN ('trash', 'auto-draft') ORDER BY pm.post_id ASC",
		$key,
		$value
	);

	return ids_of( array_column( $found, 'post_id' ) );
}

/**
 * Posts that show a field: an exact match on the row, never LIKE.
 *
 * Every post type and every status but trash, templates and synced patterns
 * included.
 *
 * @param string $source Binding source.
 * @param string $key    Binding key.
 * @return list<int>
 */
function posts_using( string $source, string $key ): array {
	return posts_with( META, row( $source, $key ) );
}

/**
 * Posts that show a synced pattern, directly or through another one.
 *
 * @param int $block_id Synced pattern (wp_block) ID.
 * @return list<int>
 */
function referrers( int $block_id ): array {
	return posts_with( REFS, (string) $block_id );
}

/**
 * The file-only theme templates and parts, as stored.
 *
 * @return array<string, array{type: string, title: string, uses: list<string>}>
 */
function theme_index(): array {
	$stored = get_option( THEME_OPTION, [] );
	$out    = [];

	foreach ( is_array( $stored ) ? $stored : [] as $id => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$out[ (string) $id ] = [
			'type'  => Cast\to_string( $entry['type'] ?? '' ),
			'title' => Cast\to_string( $entry['title'] ?? '' ),
			'uses'  => tidy( array_map( Cast\to_string( ... ), is_array( $entry['uses'] ?? null ) ? $entry['uses'] : [] ) ),
		];
	}

	return $out;
}

/**
 * Index the active theme's file-only templates and parts into THEME_OPTION.
 *
 * A template saved in the Site Editor is a post and is indexed as one. Runs on
 * switch_theme, after a theme update, when a template post is saved or
 * deleted, and at the start of each rebuild() pass.
 *
 * @return int How many use a field.
 */
function rebuild_theme(): int {
	$out = [];

	foreach ( TEMPLATE_TYPES as $type ) {
		foreach ( get_block_templates( [], $type ) as $template ) {
			if ( ! empty( $template->wp_id ) ) {
				continue;
			}

			$rows = walk_markup( Cast\to_string( $template->content ) )['rows'];
			if ( [] === $rows ) {
				continue;
			}

			$title = Cast\to_string( $template->title );

			$out[ Cast\to_string( $template->id ) ] = [
				'type'  => $type,
				'title' => '' !== $title ? $title : Cast\to_string( $template->slug ),
				'uses'  => $rows,
			];
		}
	}

	ksort( $out );
	update_option( THEME_OPTION, $out, false );

	return count( $out );
}

/**
 * The active theme's templates and parts that show any of some rows, files and saved ones.
 *
 * A saved template replaces the file with its ID, so its rows win.
 *
 * @param list<string> $rows Rows.
 * @return list<array{id: string, title: string, type: string, uses: list<string>}> By template ID. `uses` holds the matching rows.
 */
function templates_with( array $rows ): array {
	if ( [] === $rows ) {
		return [];
	}

	$files = [];
	foreach ( theme_index() as $id => $entry ) {
		$files[ $id ] = [
			'id'    => $id,
			'title' => $entry['title'],
			'type'  => $entry['type'],
			'uses'  => $entry['uses'],
		];
	}

	$theme = get_stylesheet();
	$saved = [];
	$found = select(
		[ 'posts', 'postmeta', 'term_relationships', 'term_taxonomy', 'terms' ],
		"SELECT p.post_name AS slug, p.post_title AS title, p.post_type AS post_type, pm.meta_value AS use_row FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID INNER JOIN %i tr ON tr.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i t ON t.term_id = tt.term_id WHERE pm.meta_key = %s AND tt.taxonomy = 'wp_theme' AND t.name = %s AND p.post_type IN ('wp_template', 'wp_template_part') AND p.post_status = 'publish'",
		META,
		$theme
	);

	foreach ( $found as $row ) {
		$slug  = Cast\to_string( $row['slug'] ?? '' );
		$title = Cast\to_string( $row['title'] ?? '' );
		$id    = $theme . '//' . $slug;
		$uses  = $saved[ $id ]['uses'] ?? [];

		$uses[]       = Cast\to_string( $row['use_row'] ?? '' );
		$saved[ $id ] = [
			'id'    => $id,
			'title' => '' !== $title ? $title : $slug,
			'type'  => Cast\to_string( $row['post_type'] ?? '' ),
			'uses'  => $uses,
		];
	}

	$out = [];
	foreach ( array_replace( $files, $saved ) as $id => $template ) {
		$hit = array_values( array_intersect( tidy( $template['uses'] ), $rows ) );
		if ( [] !== $hit ) {
			$out[ (string) $id ] = [
				'id'    => (string) $id,
				'title' => $template['title'],
				'type'  => $template['type'],
				'uses'  => $hit,
			];
		}
	}

	ksort( $out );

	return array_values( $out );
}

/**
 * IDs of the active theme's templates and template parts that show a field.
 *
 * File-only ones from THEME_OPTION, and ones saved in the Site Editor from
 * their post meta, each once, as `<theme>//<slug>`.
 *
 * @param string $source Binding source.
 * @param string $key    Binding key.
 * @return list<string>
 */
function templates_using( string $source, string $key ): array {
	return array_column( templates_with( [ row( $source, $key ) ] ), 'id' );
}

/**
 * Post types whose posts are pages a visitor reads.
 *
 * @return list<string>
 */
function content_types(): array {
	return array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
}

/**
 * How many published posts, templates and template parts show each row.
 *
 * Posts are counted with one grouped query, by row and post type.
 *
 * @param list<string> $rows Rows.
 * @return array<string, array{posts: int, templates: int, template_parts: int}> Row => counts, every row given.
 */
function counts( array $rows ): array {
	$out = [];
	foreach ( $rows as $row ) {
		$out[ $row ] = [
			'posts'          => 0,
			'templates'      => 0,
			'template_parts' => 0,
		];
	}

	if ( [] === $out ) {
		return $out;
	}

	$types = content_types();
	$found = select(
		[ 'postmeta', 'posts' ],
		"SELECT pm.meta_value AS use_row, p.post_type AS post_type, COUNT(DISTINCT p.ID) AS total FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = 'publish' GROUP BY pm.meta_value, p.post_type",
		META
	);

	foreach ( $found as $group ) {
		$row = Cast\to_string( $group['use_row'] ?? '' );
		if ( isset( $out[ $row ] ) && in_array( Cast\to_string( $group['post_type'] ?? '' ), $types, true ) ) {
			$out[ $row ]['posts'] += Cast\to_int( $group['total'] ?? 0 );
		}
	}

	foreach ( templates_with( $rows ) as $template ) {
		$field = 'wp_template_part' === $template['type'] ? 'template_parts' : 'templates';
		foreach ( $template['uses'] as $row ) {
			if ( isset( $out[ $row ] ) ) {
				++$out[ $row ][ $field ];
			}
		}
	}

	return $out;
}

/**
 * get-catalog's used_on: for each field it lists from an indexed source, how
 * many published posts, templates and template parts show it.
 *
 * @param array<mixed> $sources The catalog's sources, as Catalog\sources() returns them.
 * @return array<string, array{posts: int, templates: int, template_parts: int}> Keyed by row, such as `voyager/site-data:phone`.
 */
function used_on( array $sources ): array {
	$rows = [];

	foreach ( $sources as $source ) {
		$name   = is_array( $source ) ? ( $source['name'] ?? null ) : null;
		$fields = is_array( $source ) ? ( $source['fields'] ?? null ) : null;

		if ( ! is_string( $name ) || ! in_array( $name, SOURCES, true ) || ! is_array( $fields ) ) {
			continue;
		}

		foreach ( $fields as $field ) {
			$args = is_array( $field ) ? ( $field['args'] ?? null ) : null;
			$key  = is_array( $args ) ? ( $args['key'] ?? null ) : null;

			if ( is_string( $key ) && '' !== $key ) {
				$rows[] = row( $name, $key );
			}
		}
	}

	return counts( tidy( $rows ) );
}

/**
 * The rows that show a Site Data field: its own key, and every key made from
 * it, since a call button bound to phone_link shows the phone.
 *
 * @param string $key Site Data key.
 * @return list<string>
 */
function site_data_rows( string $key ): array {
	$rows = [ row( SiteData\SOURCE, $key ) ];

	foreach ( SiteData\derived_from() as $derived => $parts ) {
		if ( in_array( $key, $parts, true ) ) {
			$rows[] = row( SiteData\SOURCE, $derived );
		}
	}

	return tidy( $rows );
}

/**
 * Where some rows are shown: up to $limit published posts by title, how many
 * there are, and the templates.
 *
 * Two queries whatever the number of rows: a distinct count, so a page
 * showing both phone and phone_link counts once (per-row counts() would count
 * it twice), and the first $limit titles.
 *
 * Titles come in the database's order, ORDER BY post_title, so "Page 10"
 * sorts before "Page 2". A natural sort would need every title in PHP, and
 * this runs on Settings > Site Data for every field, so the query stays at
 * $limit rows. npm run check:usage-index pins the order.
 *
 * @param list<string> $rows  Rows.
 * @param int          $limit Most posts to return.
 * @return array{total: int, posts: list<WP_Post>, templates: list<array{id: string, title: string, type: string, uses: list<string>}>}
 */
function shown_on( array $rows, int $limit = 10 ): array {
	$types = content_types();
	$out   = [
		'total'     => 0,
		'posts'     => [],
		'templates' => templates_with( $rows ),
	];

	if ( [] === $rows || [] === $types ) {
		return $out;
	}

	$where = ' FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = \'publish\''
		. ' AND pm.meta_value IN (' . implode( ', ', array_fill( 0, count( $rows ), '%s' ) ) . ')'
		. ' AND p.post_type IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')';

	$total        = select( [ 'postmeta', 'posts' ], 'SELECT COUNT(DISTINCT p.ID) AS total' . $where, META, ...$rows, ...$types );
	$out['total'] = Cast\to_int( $total[0]['total'] ?? 0 );

	if ( 0 === $out['total'] || $limit < 1 ) {
		return $out;
	}

	$found = select( [ 'postmeta', 'posts' ], 'SELECT DISTINCT p.ID AS id, p.post_title AS title' . $where . ' ORDER BY p.post_title ASC, p.ID ASC LIMIT %d', META, ...$rows, ...$types, ...[ $limit ] );
	$ids   = ids_of( array_column( $found, 'id' ) );

	if ( [] !== $ids ) {
		_prime_post_caches( $ids, false, false );
	}

	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post instanceof WP_Post ) {
			$out['posts'][] = $post;
		}
	}

	return $out;
}

/**
 * The "Shown on" line under a field on Settings > Site Data.
 *
 * @param string $key Site Data key.
 * @param string $id  The field's input ID; the line's ID is `<id>-shown`.
 */
function render_shown_on( string $key, string $id ): void {
	$shown = shown_on( site_data_rows( $key ) );
	$parts = [];

	if ( $shown['total'] > 0 ) {
		$links = [];
		foreach ( $shown['posts'] as $post ) {
			$title   = get_the_title( $post );
			$links[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( (string) get_permalink( $post ) ),
				esc_html( '' !== $title ? $title : __( '(no title)', 'voyager-open-blocks' ) )
			);
		}

		$more = $shown['total'] - count( $shown['posts'] );
		if ( $more > 0 ) {
			/* translators: %d: number of pages not listed. */
			$links[] = esc_html( sprintf( _n( 'and %d more', 'and %d more', $more, 'voyager-open-blocks' ), $more ) );
		}

		/* translators: %d: number of pages. */
		$parts[] = esc_html( sprintf( _n( 'Shown on %d page:', 'Shown on %d pages:', $shown['total'], 'voyager-open-blocks' ), $shown['total'] ) ) . ' ' . implode( ', ', $links ) . '.';
	} else {
		$parts[] = esc_html__( 'Not shown on any published page yet.', 'voyager-open-blocks' );
	}

	if ( [] !== $shown['templates'] ) {
		$names = [];
		foreach ( $shown['templates'] as $template ) {
			$names[] = esc_html(
				sprintf(
					'wp_template_part' === $template['type']
						/* translators: %s: template part name. */
						? __( '%s (template part)', 'voyager-open-blocks' )
						/* translators: %s: template name. */
						: __( '%s (template)', 'voyager-open-blocks' ),
					$template['title']
				)
			);
		}

		$parts[] = esc_html__( 'Templates:', 'voyager-open-blocks' ) . ' ' . implode( ', ', $names ) . '.';
	}

	printf(
		'<p class="description" id="%1$s">%2$s</p>',
		esc_attr( $id . '-shown' ),
		wp_kses( implode( ' ', $parts ), [ 'a' => [ 'href' => true ] ] )
	);
}

/**
 * wp_after_insert_post: index the saved post, then what shows it.
 *
 * That hook fires after the post's meta and terms are saved. Revisions and
 * autosaves are skipped.
 *
 * @param int   $post_id Post ID.
 * @param mixed $post    The post.
 */
function on_save( int $post_id, mixed $post ): void {
	if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	after_change( $post, index_post( $post_id ) );
}

/**
 * after_delete_post: what showed a deleted synced pattern or template changes too.
 *
 * Runs after the post cache is cleaned, so the deleted post no longer resolves.
 *
 * @param int   $post_id Post ID.
 * @param mixed $post    The deleted post.
 */
function on_delete( int $post_id, mixed $post ): void {
	if ( $post instanceof WP_Post && 'revision' !== $post->post_type ) {
		after_change( $post, true );
	}
}

/**
 * After a post's rows change: index the posts that show it, if it is a synced
 * pattern, and rebuild the theme index, if it is a template.
 *
 * A template saved in the Site Editor replaces the file it came from, and
 * deleting it brings the file back, so either changes which files count.
 *
 * @param WP_Post $post    The post.
 * @param bool    $changed Whether its rows or references changed.
 */
function after_change( WP_Post $post, bool $changed ): void {
	if ( 'wp_block' === $post->post_type && $changed ) {
		reindex( array_values( array_diff( referrers( $post->ID ), [ $post->ID ] ) ) );
	}

	if ( in_array( $post->post_type, TEMPLATE_TYPES, true ) ) {
		rebuild_theme();
	}
}

/**
 * Index posts again: up to BATCH now, and the rest through WP-Cron, BATCH per run.
 *
 * A synced pattern can be on thousands of pages; saving it must not index
 * them all in the same request.
 *
 * @param list<int>                   $ids   Post IDs.
 * @param (callable(int): mixed)|null $index Indexes one post. Defaults to index_post().
 * @return list<int> The IDs left to WP-Cron.
 */
function reindex( array $ids, ?callable $index = null ): array {
	$index = $index ?? index_post( ... );
	$later = array_slice( $ids, BATCH );

	foreach ( array_slice( $ids, 0, BATCH ) as $id ) {
		$index( $id );
	}

	if ( [] !== $later ) {
		enqueue( $later );
	}

	return $later;
}

/**
 * The post IDs waiting in QUEUE_OPTION, sorted and without duplicates.
 *
 * @return list<int>
 */
function queued(): array {
	$stored = get_option( QUEUE_OPTION, [] );
	$ids    = array_values( array_unique( array_filter( array_map( Cast\to_int( ... ), is_array( $stored ) ? $stored : [] ), static fn ( int $id ): bool => $id > 0 ) ) );
	sort( $ids );

	return $ids;
}

/**
 * Add post IDs to the queue and make sure the queue event will run.
 *
 * Reads the queue right before it writes, so IDs another request queued
 * are kept.
 *
 * @param list<int> $ids Post IDs.
 */
function enqueue( array $ids ): void {
	save_queue( [ ...queued(), ...$ids ] );
	schedule_queue();
}

/**
 * Store the queue sorted and without duplicates, or delete it when empty.
 *
 * @param array<int> $ids Post IDs.
 */
function save_queue( array $ids ): void {
	$ids = array_values( array_unique( $ids ) );
	sort( $ids );

	if ( [] === $ids ) {
		delete_option( QUEUE_OPTION );
	} else {
		update_option( QUEUE_OPTION, $ids, false );
	}
}

/**
 * Schedule the queue event, unless it is scheduled already.
 */
function schedule_queue(): void {
	if ( false === wp_next_scheduled( QUEUE_HOOK ) ) {
		wp_schedule_single_event( time(), QUEUE_HOOK );
	}
}

/**
 * Index the next BATCH queued posts, and run again while any are left.
 *
 * The next run is scheduled before the batch starts, and the batch leaves the
 * queue only once it is indexed, so a request that dies mid-batch loses
 * nothing: the next run indexes the batch again. Then the queue is read again
 * and only the indexed IDs are removed, so IDs queued during the batch stay.
 *
 * @param (callable(int): mixed)|null $index Indexes one post. Defaults to index_post().
 */
function drain_queue( ?callable $index = null ): void {
	$index = $index ?? index_post( ... );
	$batch = array_slice( queued(), 0, BATCH );

	if ( [] === $batch ) {
		delete_option( QUEUE_OPTION );
		return;
	}

	schedule_queue();

	foreach ( $batch as $id ) {
		$index( $id );
	}

	$left = array_values( array_diff( queued(), $batch ) );
	save_queue( $left );

	if ( [] === $left ) {
		wp_clear_scheduled_hook( QUEUE_HOOK );
	}
}

/**
 * The queue event.
 */
function queue_event(): void {
	drain_queue();
}

/**
 * Index the next batch of posts, by ID, continuing the pass in CURSOR_OPTION.
 *
 * A pass starts by rebuilding the theme index. When a batch comes back short,
 * the pass is over and the next call starts a new one.
 *
 * @param int $batch Posts per call.
 * @return int Posts indexed by this call. Fewer than $batch means the pass finished.
 */
function rebuild( int $batch = BATCH ): int {
	$batch = max( 1, $batch );
	$after = max( 0, Cast\to_int( get_option( CURSOR_OPTION, 0 ) ) );

	if ( 0 === $after ) {
		rebuild_theme();
	}

	$ids  = next_batch( $after, $batch );
	$last = $after;

	if ( [] !== $ids ) {
		_prime_post_caches( $ids, false, true );
	}

	foreach ( $ids as $id ) {
		index_post( $id );
		$last = $id;
	}

	if ( count( $ids ) < $batch ) {
		delete_option( CURSOR_OPTION );
	} else {
		update_option( CURSOR_OPTION, $last, false );
	}

	return count( $ids );
}

/**
 * IDs of the next posts to index, after one ID, oldest first.
 *
 * @param int $after Last ID indexed, 0 to start.
 * @param int $batch How many.
 * @return list<int>
 */
function next_batch( int $after, int $batch ): array {
	$found = select(
		[ 'posts' ],
		"SELECT ID AS id FROM %i WHERE ID > %d AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY ID ASC LIMIT %d",
		$after,
		$batch
	);

	return ids_of( array_column( $found, 'id' ) );
}

/**
 * Start a backfill pass from the first post, run by WP-Cron.
 *
 * Runs on activation and when the plugin version changes.
 */
function start_backfill(): void {
	delete_option( CURSOR_OPTION );
	update_option( VERSION_OPTION, VOYAGER_OPEN_BLOCKS_VERSION );

	if ( false === wp_next_scheduled( BACKFILL_HOOK ) ) {
		wp_schedule_single_event( time(), BACKFILL_HOOK );
	}
}

/**
 * On init: start a backfill when this version of the plugin has not run one.
 *
 * Covers an update, which no hook of the new version sees, and a network
 * activation, which activates only the main site's hook.
 */
function maybe_start_backfill(): void {
	if ( VOYAGER_OPEN_BLOCKS_VERSION !== get_option( VERSION_OPTION ) ) {
		start_backfill();
	}
}

/**
 * The backfill event: one batch, then the next one if there may be more.
 */
function backfill(): void {
	if ( rebuild( BATCH ) >= BATCH ) {
		wp_schedule_single_event( time(), BACKFILL_HOOK );
	}
}

/**
 * The theme event: rebuild the theme index.
 */
function theme_event(): void {
	rebuild_theme();
}

/**
 * switch_theme: rebuild now, and again on the next request.
 *
 * The new theme's patterns are registered only from the next request on, so
 * a template part holding one is read correctly then.
 */
function on_switch_theme(): void {
	rebuild_theme();

	if ( false === wp_next_scheduled( THEME_HOOK ) ) {
		wp_schedule_single_event( time(), THEME_HOOK );
	}
}

/**
 * upgrader_process_complete: a theme update can change its template files.
 *
 * @param mixed        $upgrader The upgrader.
 * @param array<mixed> $options  What was upgraded.
 */
function on_upgrade( mixed $upgrader, array $options ): void {
	if ( 'theme' === ( $options['type'] ?? null ) && false === wp_next_scheduled( THEME_HOOK ) ) {
		wp_schedule_single_event( time(), THEME_HOOK );
	}
}

/**
 * Deactivation: stop the scheduled events.
 */
function stop(): void {
	wp_clear_scheduled_hook( BACKFILL_HOOK );
	wp_clear_scheduled_hook( THEME_HOOK );
	wp_clear_scheduled_hook( QUEUE_HOOK );
}

/**
 * Register `wp voyager-open-blocks reindex`.
 */
function register_cli(): void {
	if ( \class_exists( 'WP_CLI' ) ) {
		\WP_CLI::add_command( 'voyager-open-blocks reindex', __NAMESPACE__ . '\cli_reindex' );
	}
}

/**
 * Index every post and the theme's templates now.
 *
 * ## EXAMPLES
 *
 *     wp voyager-open-blocks reindex
 */
function cli_reindex(): void {
	if ( ! \class_exists( 'WP_CLI' ) ) {
		return;
	}

	// This pass covers everything, so a backfill or queue event would only repeat it.
	wp_clear_scheduled_hook( BACKFILL_HOOK );
	wp_clear_scheduled_hook( QUEUE_HOOK );
	delete_option( CURSOR_OPTION );
	delete_option( QUEUE_OPTION );
	$total = 0;

	do {
		$count  = rebuild( BATCH );
		$total += $count;
		\WP_CLI::log( sprintf( 'Indexed %d posts.', $total ) );
		wp_cache_flush_runtime();
	} while ( $count >= BATCH );

	\WP_CLI::success( sprintf( 'Indexed %d posts, and %d theme templates and template parts that use a field.', $total, count( theme_index() ) ) );
}

add_action( 'wp_after_insert_post', __NAMESPACE__ . '\on_save', 10, 2 );
add_action( 'after_delete_post', __NAMESPACE__ . '\on_delete', 10, 2 );
add_action( 'switch_theme', __NAMESPACE__ . '\on_switch_theme' );
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\on_upgrade', 10, 2 );
add_action( BACKFILL_HOOK, __NAMESPACE__ . '\backfill' );
add_action( THEME_HOOK, __NAMESPACE__ . '\theme_event' );
add_action( QUEUE_HOOK, __NAMESPACE__ . '\queue_event' );
add_action( 'init', __NAMESPACE__ . '\maybe_start_backfill' );
add_action( 'cli_init', __NAMESPACE__ . '\register_cli' );
register_activation_hook( VOYAGER_OPEN_BLOCKS_FILE, __NAMESPACE__ . '\start_backfill' );
register_deactivation_hook( VOYAGER_OPEN_BLOCKS_FILE, __NAMESPACE__ . '\stop' );
