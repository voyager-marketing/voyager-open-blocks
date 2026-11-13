<?php
/**
 * The undo ability: reverse a save made by insert, compose, configure or bind.
 *
 * Each of those returns revision_id, the revision WordPress made for its save.
 * That revision holds the post after the save, so undo restores the one
 * before it, the content the save replaced, with wp_restore_post_revision().
 * The restore is itself a save with a revision of its own, which undo returns,
 * so undoing that revision puts the change back.
 *
 * Restoring an older revision reverses every save after it too, so undo
 * refuses when the post was saved after revision_id, by a person or by
 * another agent, and lists those saves. force reverses them as well.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use VoyagerOpenBlocks\Cast;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register undo.
 */
function register_undo(): void {
	wp_register_ability(
		CATEGORY . '/undo',
		[
			'label'               => __( 'Undo a Voyager Open save', 'voyager-open-blocks' ),
			'description'         => __( 'Reverses a save made by insert, compose, configure or bind. Pass the post_id and the revision_id that save returned: undo restores the post\'s content as it was just before that save, with wp_restore_post_revision(). If the post was saved after that save, by a person or another agent, undo is refused, changes nothing and lists those saves; pass force: true to reverse them too. The title and excerpt are not restored. Like every revision restore in WordPress, it also replaces the post meta WordPress keeps in revisions (core\'s footnotes, and meta a plugin registers with revisions_enabled) with that revision\'s copy. Nothing is deleted: the content undo replaces is stored as a revision first, and the revision_id undo returns reverses the undo. Refused when the revision belongs to another post.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'     => post_id_schema(),
					'revision_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The revision_id that insert, compose, configure or bind returned for the save to reverse.',
					],
					'force'       => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Reverse the saves made after revision_id as well. Without it, undo is refused when the post was saved after revision_id, and the error lists those saves in later.',
					],
				],
				'required'             => [ 'post_id', 'revision_id' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer' ],
					'undone'   => [
						'type'        => 'integer',
						'description' => 'The revision_id given: the save that was reversed.',
					],
					'restored' => [
						'type'        => 'integer',
						'description' => 'The revision whose content the post now has.',
					],
					'reversed' => [
						'type'        => 'array',
						'description' => 'Every save this undo reversed, oldest first: the one revision_id names and, with force, each save after it.',
						'items'       => saved_revision_schema(),
					],
					'matches'  => [
						'type'        => 'boolean',
						'description' => 'The post\'s content now equals the restored revision\'s byte for byte. WordPress filters the HTML a user without the unfiltered_html capability saves, which can make it false.',
					],
				] + revision_output_schema(),
			],
			'execute_callback'    => __NAMESPACE__ . '\undo',
			'permission_callback' => __NAMESPACE__ . '\can_edit_post',
			// Not idempotent: a second call restores the same content again and
			// adds nothing, but undoing the undo's own revision puts the change
			// back. Not destructive: every state stays in the post's revisions.
			'meta'                => meta( false, false ),
		]
	);
}

/**
 * Schema for one save as undo reports it.
 *
 * @return array<string, mixed>
 */
function saved_revision_schema(): array {
	return [
		'type'       => 'object',
		'properties' => [
			'revision_id' => [ 'type' => 'integer' ],
			'author_id'   => [ 'type' => 'integer' ],
			'author'      => [
				'type'        => 'string',
				'description' => 'The display name of the user who saved it, empty when that user no longer exists.',
			],
			'date_gmt'    => [
				'type'        => 'string',
				'description' => 'When it was saved, in UTC, as YYYY-MM-DDTHH:MM:SS.',
			],
		],
	];
}

/**
 * Execute undo.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function undo( array $input ) {
	$post_id     = Cast\to_int( $input['post_id'] );
	$revision_id = Cast\to_int( $input['revision_id'] );
	$force       = true === ( $input['force'] ?? false );

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return new WP_Error( 'voyager_open_blocks_invalid_post_id', sprintf( 'Post %d does not exist.', $post_id ), [ 'status' => 404 ] );
	}

	// The permission callback checked the user may edit post_id. The revision
	// must belong to that post, or it would let a user restore content into a
	// post from one they cannot edit.
	$revision = revision( $revision_id );
	if ( null === $revision || Cast\to_int( $revision->post_parent ) !== $post_id ) {
		return invalid_revision( sprintf( 'Revision %1$d is not a revision of post %2$d. Pass the revision_id that insert, compose, configure or bind returned when it saved post %2$d.', $revision_id, $post_id ) );
	}

	if ( is_autosave( $revision_id ) ) {
		return invalid_revision( sprintf( 'Revision %d is an autosave, not a save. Pass the revision_id that insert, compose, configure or bind returned.', $revision_id ) );
	}

	$prior = prior_revision( revision_ids( $post_id ), $revision_id, is_autosave( ... ) );
	$from  = null === $prior ? null : revision( $prior );
	if ( null === $from ) {
		return invalid_revision( sprintf( 'Post %1$d has no revision older than %2$d, so its content before that save was never stored and cannot be restored.', $post_id, $revision_id ) );
	}

	// Restoring the revision before this save reverses every save after it as
	// well. A later save shows as a newer revision, or, when WordPress made
	// none for it, as content that differs from this revision's.
	$later = later_revisions( revision_ids( $post_id ), $revision_id, is_autosave( ... ) );
	if ( ! $force && ( [] !== $later || $post->post_content !== $revision->post_content ) ) {
		return undo_conflict( $post_id, $revision_id, $later );
	}

	// Keep what the post holds now, so undo deletes nothing even when the
	// newest revision does not hold it.
	$before = keep_current_state( $post_id );

	// Read again: keep_current_state() may have just stored content no
	// revision held, and that change is reversed too.
	$reversed = array_merge( [ $revision_id ], later_revisions( revision_ids( $post_id ), $revision_id, is_autosave( ... ) ) );

	// wp_restore_post_revision() updates the post without asking for a
	// WP_Error, so a failed update comes back as 0 or false.
	if ( ! wp_restore_post_revision( $from->ID, [ 'post_content' ] ) ) {
		return new WP_Error(
			'voyager_open_blocks_undo_failed',
			sprintf( 'WordPress did not restore revision %d, so the content was not changed.', $from->ID ),
			[ 'status' => 500 ]
		);
	}

	return [
		'post_id'  => $post_id,
		'undone'   => $revision_id,
		'restored' => $from->ID,
		'reversed' => describe_revisions( $reversed ),
		'matches'  => get_post_field( 'post_content', $post_id, 'raw' ) === $from->post_content,
	] + revision_made( $post_id, $before );
}

/**
 * The saves after a given one: the revisions newer than it, autosaves skipped.
 *
 * @param list<int>          $ids         Revision IDs, newest first, from revision_ids().
 * @param int                $revision_id The revision a save made.
 * @param callable(int):bool $autosave    Whether a revision is an autosave.
 * @return list<int> Oldest first. Empty when $revision_id is the newest save, or is not in the list.
 */
function later_revisions( array $ids, int $revision_id, callable $autosave ): array {
	$at = array_search( $revision_id, $ids, true );

	if ( ! is_int( $at ) ) {
		return [];
	}

	$later = [];
	foreach ( array_reverse( array_slice( $ids, 0, $at ) ) as $id ) {
		if ( ! $autosave( $id ) ) {
			$later[] = $id;
		}
	}

	return $later;
}

/**
 * Revisions as undo reports them: who saved each and when.
 *
 * @param list<int> $revision_ids Revision IDs.
 * @return list<array{revision_id: int, author_id: int, author: string, date_gmt: string}>
 */
function describe_revisions( array $revision_ids ): array {
	$rows = [];

	foreach ( $revision_ids as $id ) {
		$revision = revision( $id );
		if ( null === $revision ) {
			continue;
		}

		$author_id = Cast\to_int( $revision->post_author );
		$rows[]    = [
			'revision_id' => $id,
			'author_id'   => $author_id,
			'author'      => $author_id > 0 ? get_the_author_meta( 'display_name', $author_id ) : '',
			'date_gmt'    => mysql_to_rfc3339( $revision->post_date_gmt ),
		];
	}

	return $rows;
}

/**
 * The refusal for a save that later saves would be reversed with.
 *
 * @param int       $post_id     Post ID.
 * @param int       $revision_id The revision given.
 * @param list<int> $later       Revisions newer than it, oldest first.
 */
function undo_conflict( int $post_id, int $revision_id, array $later ): WP_Error {
	$rows = describe_revisions( $later );

	if ( [] === $rows ) {
		$message = sprintf(
			'Nothing was changed: post %1$d\'s content has changed since revision %2$d, and no revision records that change. Undo would reverse it too. Read the post with get-post-blocks, and if that change should go as well, call undo again with "force": true.',
			$post_id,
			$revision_id
		);
	} else {
		$listed = [];
		foreach ( array_slice( $rows, 0, 5 ) as $row ) {
			$listed[] = sprintf( 'revision %1$d by %2$s at %3$s UTC', $row['revision_id'], '' !== $row['author'] ? $row['author'] : 'user ' . $row['author_id'], $row['date_gmt'] );
		}
		if ( count( $rows ) > 5 ) {
			$listed[] = sprintf( 'and %d more, in later', count( $rows ) - 5 );
		}

		$message = sprintf(
			'Nothing was changed: post %1$d was saved after revision %2$d (%3$s), and undo would reverse those saves too. If every one of them is yours to reverse, call undo again with "force": true. If not, someone else has edited the post since: leave it, or ask them.',
			$post_id,
			$revision_id,
			implode( '; ', $listed )
		);
	}

	return new WP_Error(
		'voyager_open_blocks_undo_conflict',
		$message,
		[
			'status' => 409,
			'later'  => $rows,
		]
	);
}

/**
 * The error for a revision undo cannot use.
 *
 * @param string $message What is wrong.
 */
function invalid_revision( string $message ): WP_Error {
	return new WP_Error( 'voyager_open_blocks_invalid_revision', $message, [ 'status' => 400 ] );
}
