<?php
/**
 * The undo ability: reverse a save made by insert, configure or bind.
 *
 * Each of those returns revision_id, the revision WordPress made for its save.
 * That revision holds the post after the save, so undo restores the one
 * before it, the content the save replaced, with wp_restore_post_revision().
 * The restore is itself a save with a revision of its own, which undo returns,
 * so undoing that revision puts the change back.
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
			'description'         => __( 'Reverses a save made by insert, configure or bind. Pass the post_id and the revision_id that save returned: undo restores the post\'s content as it was just before that save, with wp_restore_post_revision(). Saves made after it are reversed too. Only the content is restored, not the title or excerpt. Nothing is deleted: the undone content stays in the post\'s revisions, and the revision_id undo returns reverses the undo. Refused when the revision belongs to another post.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'     => post_id_schema(),
					'revision_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The revision_id that insert, configure or bind returned for the save to reverse.',
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
 * Execute undo.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function undo( array $input ) {
	$post_id     = Cast\to_int( $input['post_id'] );
	$revision_id = Cast\to_int( $input['revision_id'] );

	if ( ! get_post( $post_id ) instanceof \WP_Post ) {
		return new WP_Error( 'voyager_open_blocks_invalid_post_id', sprintf( 'Post %d does not exist.', $post_id ), [ 'status' => 404 ] );
	}

	// The permission callback checked the user may edit post_id. The revision
	// must belong to that post, or it would let a user restore content into a
	// post from one they cannot edit.
	$revision = revision( $revision_id );
	if ( null === $revision || Cast\to_int( $revision->post_parent ) !== $post_id ) {
		return invalid_revision( sprintf( 'Revision %1$d is not a revision of post %2$d. Pass the revision_id that insert, configure or bind returned when it saved post %2$d.', $revision_id, $post_id ) );
	}

	if ( is_autosave( $revision_id ) ) {
		return invalid_revision( sprintf( 'Revision %d is an autosave, not a save. Pass the revision_id that insert, configure or bind returned.', $revision_id ) );
	}

	$prior = prior_revision( revision_ids( $post_id ), $revision_id, is_autosave( ... ) );
	$from  = null === $prior ? null : revision( $prior );
	if ( null === $from ) {
		return invalid_revision( sprintf( 'Post %1$d has no revision older than %2$d, so its content before that save was never stored and cannot be restored.', $post_id, $revision_id ) );
	}

	// Keep what the post holds now, so undo deletes nothing even when the
	// newest revision does not hold it.
	$before = keep_current_state( $post_id );

	$restored = wp_restore_post_revision( $from->ID, [ 'post_content' ] );
	if ( ! $restored || is_wp_error( $restored ) ) {
		return new WP_Error(
			'voyager_open_blocks_undo_failed',
			sprintf( 'WordPress did not restore revision %d: %s Nothing was changed.', $from->ID, is_wp_error( $restored ) ? $restored->get_error_message() : 'the update failed.' ),
			[ 'status' => 500 ]
		);
	}

	$post = get_post( $post_id );

	return [
		'post_id'  => $post_id,
		'undone'   => $revision_id,
		'restored' => $from->ID,
		'matches'  => $post instanceof \WP_Post && $post->post_content === $from->post_content,
	] + revision_made( $post_id, $before );
}

/**
 * The error for a revision undo cannot use.
 *
 * @param string $message What is wrong.
 */
function invalid_revision( string $message ): WP_Error {
	return new WP_Error( 'voyager_open_blocks_invalid_revision', $message, [ 'status' => 400 ] );
}
