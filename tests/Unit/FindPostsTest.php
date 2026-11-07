<?php
/**
 * The parts of find-posts that run without WordPress: the query it asks for,
 * the permission check on every result, and word matching for templates.
 *
 * WP_Query and the block templates need a real WordPress and are covered by
 * scripts/e2e.mjs, including a contributor who must not see others' posts.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;

require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class FindPostsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['voyager_test_caps'] );
		parent::tearDown();
	}

	public function test_the_query_asks_only_for_editable_posts_not_in_the_trash(): void {
		$args = Abilities\query_args( 'page', '', '', 10 );

		$this->assertSame( 'editable', $args['perm'] );
		$this->assertSame( [ 'publish', 'future', 'draft', 'pending', 'private' ], $args['post_status'] );
		$this->assertNotContains( 'trash', (array) $args['post_status'] );
		$this->assertSame( 'ids', $args['fields'] );
		$this->assertSame( 'page', $args['post_type'] );
	}

	public function test_the_query_asks_for_one_more_than_the_limit(): void {
		$this->assertSame( 11, Abilities\query_args( 'page', '', '', 10 )['posts_per_page'] );
		$this->assertSame( 21, Abilities\query_args( 'page', '', '', Abilities\FIND_LIMIT )['posts_per_page'] );
	}

	public function test_a_search_looks_in_titles_only_and_a_slug_must_match_exactly(): void {
		$args = Abilities\query_args( 'page', 'Services', 'services', 5 );

		$this->assertSame( 'Services', $args['s'] );
		$this->assertSame( [ 'post_title' ], $args['search_columns'] );
		$this->assertSame( [ 'services' ], $args['post_name__in'] );
		$this->assertArrayNotHasKey( 'orderby', $args );
	}

	public function test_without_a_search_results_are_in_title_order(): void {
		$args = Abilities\query_args( 'post', '', '', 5 );

		$this->assertSame( 'title', $args['orderby'] );
		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'post_name__in', $args );
	}

	public function test_a_post_is_shown_only_when_the_user_can_edit_and_read_it(): void {
		$GLOBALS['voyager_test_caps'] = [
			'edit_post' => [ 1, 2 ],
			'read_post' => [ 1, 3 ],
		];

		$this->assertTrue( Abilities\can_see( 1 ) );
		$this->assertFalse( Abilities\can_see( 2 ) );
		$this->assertFalse( Abilities\can_see( 3 ) );
		$this->assertFalse( Abilities\can_see( 0 ) );
	}

	public function test_template_search_needs_every_word_ignoring_case(): void {
		$this->assertTrue( Abilities\matches_words( 'Header header', 'header' ) );
		$this->assertTrue( Abilities\matches_words( 'Site Header site-header', 'HEADER site' ) );
		$this->assertTrue( Abilities\matches_words( 'Footer footer', '' ) );
		$this->assertFalse( Abilities\matches_words( 'Footer footer', 'header' ) );
		$this->assertFalse( Abilities\matches_words( 'Header header', 'header sticky' ) );
	}
}
