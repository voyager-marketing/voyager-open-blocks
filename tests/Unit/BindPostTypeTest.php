<?php
/**
 * bind's post_type input: which post type's fields a binding's args are
 * checked against, so a template can bind post meta of the posts it shows.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Catalog;
use WP_Error;

final class BindPostTypeTest extends TestCase {

	public function test_without_post_type_the_posts_own_type_is_used(): void {
		$this->assertSame( 'page', Catalog\binding_post_type( 'page', '', false ) );
		$this->assertSame( 'wp_template', Catalog\binding_post_type( 'wp_template', '', false ) );
		$this->assertSame( 'page', Catalog\binding_post_type( 'page', 'page', false ) );
	}

	public function test_a_template_template_part_or_synced_pattern_takes_the_type_it_shows(): void {
		$this->assertSame( 'post', Catalog\binding_post_type( 'wp_template', 'post', false ) );
		$this->assertSame( 'product', Catalog\binding_post_type( 'wp_template_part', 'product', false ) );
		$this->assertSame( 'post', Catalog\binding_post_type( 'wp_block', 'post', false ) );
	}

	public function test_a_block_inside_a_query_loop_takes_the_type_it_shows(): void {
		$this->assertSame( 'post', Catalog\binding_post_type( 'page', 'post', true ) );
	}

	public function test_a_page_block_outside_a_query_loop_reads_its_own_fields(): void {
		$result = Catalog\binding_post_type( 'page', 'post', false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_post_type', $result->get_error_code() );
	}

	public function test_refused_args_in_a_template_point_at_post_type(): void {
		$refused = new WP_Error( 'voyager_open_blocks_invalid_args', 'No core/post-meta field for post type "wp_template" has args {"key":"subtitle"}.' );

		$explained = Catalog\explain_post_type( $refused, 'wp_template', '' );

		$this->assertSame( 'voyager_open_blocks_invalid_args', $explained->get_error_code() );
		$this->assertStringContainsString( 'pass post_type', $explained->get_error_message() );
	}

	public function test_other_refusals_are_left_alone(): void {
		$args      = new WP_Error( 'voyager_open_blocks_invalid_args', 'No field.' );
		$attribute = new WP_Error( 'voyager_open_blocks_invalid_attribute', 'Not bindable.' );

		$this->assertSame( $args, Catalog\explain_post_type( $args, 'page', '' ) );
		$this->assertSame( $args, Catalog\explain_post_type( $args, 'wp_template', 'post' ) );
		$this->assertSame( $attribute, Catalog\explain_post_type( $attribute, 'wp_template', '' ) );
	}
}
