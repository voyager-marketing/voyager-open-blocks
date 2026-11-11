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

require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class UndoTest extends TestCase {

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
