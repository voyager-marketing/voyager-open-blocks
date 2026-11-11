<?php
/**
 * WordPress revision stubs for UndoTest.
 *
 * Posts and their revisions are WP_Post objects in $GLOBALS['voyager_test_posts'].
 * wp_save_post_revision() keeps the core rules undo depends on: no revision
 * of an auto-draft or when revisions are off, none when the newest revision
 * matches once whitespace is normalized (unless the
 * wp_save_post_revision_check_for_changes filter turns the check off), and
 * the oldest revisions past the site's limit are deleted.
 *
 * - voyager_test_posts             ID => WP_Post, posts and revisions alike
 * - voyager_test_next_id           the next ID to hand out
 * - voyager_test_revisions_enabled false turns revisions off; unset leaves them on
 * - voyager_test_revisions_keep    how many revisions a post keeps; unset keeps all
 * - voyager_test_current_user      the user ID new saves are made by
 * - voyager_test_authors           user ID => display name
 *
 * voyager_test_insert_post() and voyager_test_save_post() stand in for
 * wp_insert_post() and wp_update_post(): core makes no revision when a post
 * is created, and one through wp_save_post_revision() when it is updated.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID             = 0;
		public string $post_author = '0';
		public string $post_date_gmt = '0000-00-00 00:00:00';
		public string $post_content  = '';
		public string $post_status   = 'publish';
		public string $post_name     = '';
		public string $post_type     = 'page';
		public int $post_parent      = 0;
	}
}

/**
 * Add a post or revision to the store and return its ID.
 *
 * @param array<string, mixed> $fields WP_Post properties.
 */
function voyager_test_new_post( array $fields ): int {
	$id   = $GLOBALS['voyager_test_next_id'] ?? 100;
	$post = new WP_Post();

	foreach ( $fields as $name => $value ) {
		$post->$name = $value;
	}
	$post->ID            = $id;
	$post->post_author   = (string) ( $GLOBALS['voyager_test_current_user'] ?? 1 );
	$post->post_date_gmt = sprintf( '2026-11-11 03:%02d:00', $id % 60 );

	$GLOBALS['voyager_test_posts'][ $id ] = $post;
	$GLOBALS['voyager_test_next_id']      = $id + 1;

	return $id;
}

/**
 * Create a post as wp_insert_post() does: no revision.
 */
function voyager_test_insert_post( string $content, string $status = 'publish' ): int {
	return voyager_test_new_post(
		[
			'post_content' => $content,
			'post_status'  => $status,
		]
	);
}

/**
 * Save a post's content as wp_update_post() does, revision included.
 */
function voyager_test_save_post( int $post_id, string $content ): void {
	$GLOBALS['voyager_test_posts'][ $post_id ]->post_content = $content;
	wp_save_post_revision( $post_id );
}

/**
 * A post's revisions, autosaves included, newest first.
 *
 * @return list<int>
 */
function voyager_test_revisions_of( int $post_id ): array {
	$ids = [];
	foreach ( $GLOBALS['voyager_test_posts'] ?? [] as $id => $post ) {
		if ( 'revision' === $post->post_type && $post->post_parent === $post_id ) {
			$ids[] = $id;
		}
	}
	rsort( $ids );

	return $ids;
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $post = null ): ?WP_Post {
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		return $GLOBALS['voyager_test_posts'][ (int) $post ] ?? null;
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id, string $context = 'display' ): string {
		$post = get_post( $post_id );
		return $post instanceof WP_Post ? (string) $post->$field : '';
	}
}

if ( ! function_exists( 'wp_revisions_enabled' ) ) {
	function wp_revisions_enabled( WP_Post $post ): bool {
		return false !== ( $GLOBALS['voyager_test_revisions_enabled'] ?? true );
	}
}

if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	/**
	 * @param array<string, mixed> $args Only fields => ids is honoured.
	 * @return array<int, int|WP_Post>
	 */
	function wp_get_post_revisions( int $post_id, array $args = [] ): array {
		$ids = voyager_test_revisions_of( $post_id );
		return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( 'get_post', $ids );
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( int $revision_id ): int|false {
		$revision = get_post( $revision_id );
		return $revision instanceof WP_Post && str_contains( $revision->post_name, 'autosave' ) ? $revision->post_parent : false;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['voyager_test_filters'][ $hook ][ $priority ] ?? [] as $at => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['voyager_test_filters'][ $hook ][ $priority ][ $at ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_save_post_revision' ) ) {
	function wp_save_post_revision( int $post_id ): ?int {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status || ! wp_revisions_enabled( $post ) ) {
			return null;
		}

		$latest = null;
		foreach ( voyager_test_revisions_of( $post_id ) as $id ) {
			if ( false === wp_is_post_autosave( $id ) ) {
				$latest = get_post( $id );
				break;
			}
		}

		// Core compares the revisioned fields after normalize_whitespace().
		$normalize = static fn ( string $text ): string => (string) preg_replace( [ '/\n+/', '/[ \t]+/' ], [ "\n", ' ' ], str_replace( "\r", "\n", trim( $text ) ) );
		if ( $latest instanceof WP_Post && apply_filters( 'wp_save_post_revision_check_for_changes', true, $latest, $post ) && $normalize( $latest->post_content ) === $normalize( $post->post_content ) ) {
			return null;
		}

		$revision_id = voyager_test_new_post(
			[
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $post_id,
				'post_name'    => "{$post_id}-revision-v1",
				'post_content' => $post->post_content,
			]
		);

		$keep = $GLOBALS['voyager_test_revisions_keep'] ?? -1;
		if ( $keep >= 0 ) {
			$saves = array_values( array_filter( voyager_test_revisions_of( $post_id ), static fn ( int $id ): bool => false === wp_is_post_autosave( $id ) ) );
			foreach ( array_slice( $saves, $keep ) as $old ) {
				unset( $GLOBALS['voyager_test_posts'][ $old ] );
			}
		}

		return $revision_id;
	}
}

if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	/**
	 * @param list<string>|null $fields Only post_content is honoured.
	 */
	function wp_restore_post_revision( int $revision_id, ?array $fields = null ): int|false|null {
		$revision = get_post( $revision_id );
		if ( ! $revision instanceof WP_Post ) {
			return null;
		}
		voyager_test_save_post( $revision->post_parent, $revision->post_content );
		return $revision->post_parent;
	}
}

if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( string $field = '', int|false $user_id = false ): string {
		return $GLOBALS['voyager_test_authors'][ (int) $user_id ] ?? '';
	}
}

if ( ! function_exists( 'mysql_to_rfc3339' ) ) {
	function mysql_to_rfc3339( string $date_string ): string {
		return str_replace( ' ', 'T', $date_string );
	}
}
