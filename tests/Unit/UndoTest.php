<?php
/**
 * Undo: which revision holds the content a save replaced, and the dry_run and
 * revision_id schemas every saving ability shares.
 *
 * Revisions need WordPress, so scripts/e2e.mjs runs insert, configure, bind
 * and undo over REST. These tests cover the part that is plain logic.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use WP_Error;

require_once dirname( __DIR__ ) . '/revision-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class UndoTest extends TestCase {

	protected function setUp(): void {
		foreach ( [ 'posts', 'next_id', 'revisions_enabled', 'revisions_keep', 'current_user', 'authors', 'filters' ] as $name ) {
			unset( $GLOBALS[ 'voyager_test_' . $name ] );
		}
		$GLOBALS['voyager_test_authors'] = [
			1 => 'Agent',
			2 => 'Pat Person',
		];
	}

	/**
	 * A save through an ability: the state before it kept, the content saved,
	 * and revision_made()'s answer.
	 *
	 * @return array{revision_id: int|null, revision_note?: string}
	 */
	private static function ability_save( int $post_id, string $content ): array {
		$before = Abilities\keep_current_state( $post_id );
		voyager_test_save_post( $post_id, $content );
		return Abilities\revision_made( $post_id, $before );
	}

	private static function content( int $post_id ): string {
		return $GLOBALS['voyager_test_posts'][ $post_id ]->post_content;
	}

	public function test_a_save_returns_the_revision_wordpress_made_for_it(): void {
		$post  = voyager_test_insert_post( 'A' );
		$saved = self::ability_save( $post, 'B' );

		$this->assertIsInt( $saved['revision_id'] );
		$this->assertArrayNotHasKey( 'revision_note', $saved );
		$this->assertSame( 'B', $GLOBALS['voyager_test_posts'][ $saved['revision_id'] ]->post_content );

		// The content before the first save is stored too, though core makes
		// no revision when a post is created.
		$prior = Abilities\prior_revision( voyager_test_revisions_of( $post ), $saved['revision_id'], Abilities\is_autosave( ... ) );
		$this->assertIsInt( $prior );
		$this->assertSame( 'A', $GLOBALS['voyager_test_posts'][ $prior ]->post_content );
	}

	public function test_revisions_off_returns_null_and_says_so(): void {
		$GLOBALS['voyager_test_revisions_enabled'] = false;
		$post                                      = voyager_test_insert_post( 'A' );
		$saved                                     = self::ability_save( $post, 'B' );

		$this->assertNull( $saved['revision_id'] );
		$this->assertStringStartsWith( 'Revisions are off for this post', $saved['revision_note'] ?? '' );
		$this->assertSame( [], voyager_test_revisions_of( $post ) );
	}

	public function test_a_site_that_keeps_one_revision_returns_the_id_and_says_undo_cannot_restore(): void {
		$GLOBALS['voyager_test_revisions_keep'] = 1;
		$post                                   = voyager_test_insert_post( 'A' );
		$saved                                  = self::ability_save( $post, 'B' );

		$this->assertIsInt( $saved['revision_id'] );
		$this->assertStringStartsWith( 'WordPress kept no revision from before this save', $saved['revision_note'] ?? '' );
		$this->assertSame( [ $saved['revision_id'] ], voyager_test_revisions_of( $post ) );
	}

	public function test_an_auto_draft_gets_its_own_note_not_the_unchanged_one(): void {
		$post  = voyager_test_insert_post( 'A', 'auto-draft' );
		$saved = self::ability_save( $post, 'B' );

		$this->assertNull( $saved['revision_id'] );
		$this->assertStringStartsWith( 'This post is an auto-draft', $saved['revision_note'] ?? '' );
		$this->assertStringNotContainsString( 'did not change', $saved['revision_note'] ?? '' );
	}

	public function test_a_save_that_changes_nothing_returns_null(): void {
		$post = voyager_test_insert_post( 'A' );
		self::ability_save( $post, 'B' );
		$again = self::ability_save( $post, 'B' );

		$this->assertNull( $again['revision_id'] );
		$this->assertStringStartsWith( 'The content did not change', $again['revision_note'] ?? '' );
	}

	public function test_a_change_in_whitespace_only_still_gets_a_revision(): void {
		$post  = voyager_test_insert_post( "A  b\n" );
		$saved = self::ability_save( $post, 'A b' );

		$this->assertIsInt( $saved['revision_id'] );
		$this->assertSame( 'A b', $GLOBALS['voyager_test_posts'][ $saved['revision_id'] ]->post_content );
	}

	public function test_undo_restores_the_content_before_the_save(): void {
		$post  = voyager_test_insert_post( 'A' );
		$saved = self::ability_save( $post, 'B' );

		$undone = Abilities\undo( [ 'post_id' => $post, 'revision_id' => $saved['revision_id'] ] );

		$this->assertIsArray( $undone );
		$this->assertSame( 'A', self::content( $post ) );
		$this->assertTrue( $undone['matches'] );
		$this->assertSame( [ $saved['revision_id'] ], array_column( $undone['reversed'], 'revision_id' ) );
	}

	public function test_undo_refuses_when_someone_saved_after_the_revision_and_lists_that_save(): void {
		$post  = voyager_test_insert_post( 'A' );
		$saved = self::ability_save( $post, 'B' );

		$GLOBALS['voyager_test_current_user'] = 2;
		voyager_test_save_post( $post, 'B, then a person\'s edit' );
		$person    = voyager_test_revisions_of( $post )[0];
		$revisions = voyager_test_revisions_of( $post );

		$refused = Abilities\undo( [ 'post_id' => $post, 'revision_id' => $saved['revision_id'] ] );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_undo_conflict', $refused->get_error_code() );
		$this->assertStringContainsString( "revision {$person} by Pat Person", $refused->get_error_message() );
		$this->assertStringContainsString( '"force": true', $refused->get_error_message() );

		$data = $refused->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( [ $person ], array_column( $data['later'], 'revision_id' ) );
		$this->assertSame( 'Pat Person', $data['later'][0]['author'] );
		$this->assertSame( 2, $data['later'][0]['author_id'] );

		// Nothing changed: not the content, and no revision was added.
		$this->assertSame( 'B, then a person\'s edit', self::content( $post ) );
		$this->assertSame( $revisions, voyager_test_revisions_of( $post ) );
	}

	public function test_a_later_save_is_refused_even_when_the_content_is_back_where_it_was(): void {
		$post  = voyager_test_insert_post( 'A' );
		$saved = self::ability_save( $post, 'B' );

		// A person changes the page and changes it back. The content matches
		// the save's revision again, but restoring would still replace what
		// WordPress keeps with their revisions, such as footnotes.
		$GLOBALS['voyager_test_current_user'] = 2;
		voyager_test_save_post( $post, 'C' );
		voyager_test_save_post( $post, 'B' );
		$later = array_reverse( array_slice( voyager_test_revisions_of( $post ), 0, 2 ) );

		$refused = Abilities\undo( [ 'post_id' => $post, 'revision_id' => $saved['revision_id'] ] );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_undo_conflict', $refused->get_error_code() );
		$data = $refused->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( $later, array_column( $data['later'], 'revision_id' ) );
		$this->assertSame( 'B', self::content( $post ) );
	}

	public function test_force_reverses_the_later_save_too_and_undoing_that_brings_it_back(): void {
		$post  = voyager_test_insert_post( 'A' );
		$saved = self::ability_save( $post, 'B' );
		voyager_test_save_post( $post, 'C' );
		$person = voyager_test_revisions_of( $post )[0];

		$forced = Abilities\undo(
			[
				'post_id'     => $post,
				'revision_id' => $saved['revision_id'],
				'force'       => true,
			]
		);

		$this->assertIsArray( $forced );
		$this->assertSame( 'A', self::content( $post ) );
		$this->assertSame( [ $saved['revision_id'], $person ], array_column( $forced['reversed'], 'revision_id' ) );
		$this->assertIsInt( $forced['revision_id'] );

		$back = Abilities\undo( [ 'post_id' => $post, 'revision_id' => $forced['revision_id'] ] );
		$this->assertIsArray( $back );
		$this->assertSame( 'C', self::content( $post ) );
	}

	public function test_a_change_no_revision_records_is_refused_and_kept_when_forced(): void {
		$post  = voyager_test_insert_post( 'A' );
		$saved = self::ability_save( $post, 'B' );

		// Core compares after normalizing whitespace, so this save makes no
		// revision, yet the content differs from the save's revision.
		voyager_test_save_post( $post, "B\n" );
		$this->assertSame( [], Abilities\later_revisions( voyager_test_revisions_of( $post ), $saved['revision_id'], Abilities\is_autosave( ... ) ) );

		$refused = Abilities\undo( [ 'post_id' => $post, 'revision_id' => $saved['revision_id'] ] );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_undo_conflict', $refused->get_error_code() );
		$this->assertStringContainsString( 'no revision records that change', $refused->get_error_message() );
		$this->assertSame( "B\n", self::content( $post ) );

		$forced = Abilities\undo(
			[
				'post_id'     => $post,
				'revision_id' => $saved['revision_id'],
				'force'       => true,
			]
		);
		$this->assertIsArray( $forced );
		$this->assertSame( 'A', self::content( $post ) );
		$this->assertCount( 2, $forced['reversed'] );

		// The unrecorded change was stored before the restore, so it can come back.
		$this->assertSame( "B\n", $GLOBALS['voyager_test_posts'][ $forced['reversed'][1]['revision_id'] ]->post_content );
	}

	public function test_later_revisions_are_the_newer_saves_oldest_first_without_autosaves(): void {
		$autosaves = self::autosaves( [ 44 ] );

		$this->assertSame( [ 43, 45 ], Abilities\later_revisions( [ 45, 44, 43, 42, 41 ], 42, $autosaves ) );
		$this->assertSame( [], Abilities\later_revisions( [ 45, 44, 43 ], 45, $autosaves ) );
		$this->assertSame( [], Abilities\later_revisions( [ 45, 44, 43 ], 99, $autosaves ) );
	}

	/**
	 * An autosave check that knows the given revision IDs as autosaves.
	 *
	 * @param list<int> $autosaves Revision IDs that are autosaves.
	 * @return callable(int): bool
	 */
	private static function autosaves( array $autosaves = [] ): callable {
		return static fn ( int $id ): bool => in_array( $id, $autosaves, true );
	}

	public function test_the_content_a_save_replaced_is_in_the_next_older_revision(): void {
		$this->assertSame( 39, Abilities\prior_revision( [ 41, 40, 39, 38 ], 40, self::autosaves() ) );
		$this->assertSame( 40, Abilities\prior_revision( [ 41, 40, 39, 38 ], 41, self::autosaves() ) );
	}

	public function test_autosaves_are_skipped(): void {
		$this->assertSame( 38, Abilities\prior_revision( [ 41, 40, 39, 38 ], 40, self::autosaves( [ 39 ] ) ) );
	}

	public function test_the_oldest_revision_has_nothing_before_it(): void {
		$this->assertNull( Abilities\prior_revision( [ 41, 40 ], 40, self::autosaves() ) );
		$this->assertNull( Abilities\prior_revision( [ 41, 40, 39 ], 40, self::autosaves( [ 39 ] ) ) );
	}

	public function test_a_revision_not_in_the_list_has_nothing_before_it(): void {
		$this->assertNull( Abilities\prior_revision( [ 41, 40, 39 ], 99, self::autosaves() ) );
		$this->assertNull( Abilities\prior_revision( [], 40, self::autosaves() ) );
	}

	public function test_revision_id_may_be_null_in_the_output_of_every_saving_ability(): void {
		$schema = Abilities\revision_output_schema();

		// A dry run returns revision_id null, so a schema of integer alone would
		// fail the Abilities API's output validation on every trial run.
		$this->assertSame( [ 'integer', 'null' ], $schema['revision_id']['type'] );
		$this->assertSame( 'string', $schema['revision_note']['type'] );
	}

	public function test_dry_run_is_off_unless_asked_for(): void {
		$schema = Abilities\dry_run_schema();

		$this->assertSame( 'boolean', $schema['type'] );
		$this->assertFalse( $schema['default'] );
	}
}
