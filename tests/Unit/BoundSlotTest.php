<?php
/**
 * Bound slots: insert and configure refuse a value a binding owns, and
 * validate warns when a bound block's fallback looks like a phone number or
 * an email address.
 *
 * Parsing markup needs WordPress, so these tests build blocks the way
 * parse_blocks() returns them. scripts/e2e.mjs runs the abilities over REST,
 * including validate on bench/runs/loop/willow-vet/after-edit.html.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BlockTree;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class BoundSlotTest extends TestCase {

	private const PHONE = [
		'source' => 'voyager/site-data',
		'args'   => [ 'key' => 'phone' ],
	];

	private const PHONE_LINK = [
		'source' => 'voyager/site-data',
		'args'   => [ 'key' => 'phone_link' ],
	];

	/**
	 * A button as parse_blocks() returns it.
	 *
	 * @param string               $label    metadata.name.
	 * @param string               $text     Its saved text.
	 * @param array<string, mixed> $bindings metadata.bindings.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function button( string $label, string $text, array $bindings ): array {
		$html = "\n<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link wp-element-button\" href=\"/contact/\">{$text}</a></div>\n";

		return [
			'blockName'    => 'core/button',
			'attrs'        => [
				'metadata'  => [
					'name'     => $label,
					'bindings' => $bindings,
				],
				'className' => 'is-style-outline',
			],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * A paragraph as parse_blocks() returns it.
	 *
	 * @param string               $label    metadata.name.
	 * @param string               $text     Its saved text.
	 * @param array<string, mixed> $bindings metadata.bindings.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function paragraph( string $label, string $text, array $bindings = [] ): array {
		$html = "\n<p>{$text}</p>\n";

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [ 'metadata' => array_filter( [ 'name' => $label, 'bindings' => $bindings ] ) ],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * A group holding blocks.
	 *
	 * @param array<array<mixed>> $inner Inner blocks.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function group( array $inner ): array {
		return [
			'blockName'    => 'core/group',
			'attrs'        => [ 'metadata' => [ 'name' => 'CTA band' ] ],
			'innerBlocks'  => $inner,
			'innerHTML'    => "\n<div class=\"wp-block-group\"></div>\n",
			'innerContent' => array_merge( [ "\n<div class=\"wp-block-group\">" ], array_fill( 0, count( $inner ), null ), [ "</div>\n" ] ),
		];
	}

	/**
	 * The CTA band's blocks with its call button's text, as willow-vet stored them.
	 *
	 * @param string $call The call button's saved text.
	 * @return array<int, array<mixed>>
	 */
	private static function cta_band( string $call ): array {
		return [
			self::group(
				[
					self::paragraph( 'Heading', 'Ready to get started?' ),
					self::button(
						'Click to call',
						$call,
						[
							'text' => self::PHONE,
							'url'  => self::PHONE_LINK,
						]
					),
					self::button( 'Email button', 'Email us', [ 'url' => [ 'source' => 'voyager/site-data', 'args' => [ 'key' => 'email_link' ] ] ] ),
				]
			),
		];
	}

	/**
	 * The new blocks' rows as insert reads them once placed: described with
	 * the whole post, so their paths are the post's.
	 *
	 * @param array<int, array<mixed>> $blocks The post's blocks after the insert.
	 * @param int                      $index  Where the new blocks start, at the top level.
	 * @param int                      $count  How many there are.
	 * @return array<int, array<mixed>>
	 */
	private static function placed( array $blocks, int $index = 0, int $count = 1 ): array {
		return array_values(
			array_filter(
				BlockTree\describe( $blocks ),
				static fn ( array $row ): bool => BlockTree\in_range( $row['path'], '', $index, $index + $count - 1 )
			)
		);
	}

	public function test_insert_refuses_copy_for_the_bound_call_button(): void {
		$refused = Abilities\bound_copy_slots( self::placed( self::cta_band( 'Call us' ) ), [ 'Heading' => 'Book today', 'Click to call' => '(555) 555-0113' ] );

		$this->assertSame(
			[
				[
					'slot'      => 'Click to call',
					'reason'    => 'bound',
					'block'     => 'core/button',
					'path'      => '0/1',
					'attribute' => 'text',
					'source'    => 'voyager/site-data',
					'key'       => 'phone',
				],
			],
			$refused
		);
	}

	public function test_a_refused_slot_is_named_by_its_path_in_the_post(): void {
		$post = array_merge(
			array_map( static fn ( int $n ): array => self::paragraph( 'Intro ' . $n, 'Text' ), range( 1, 4 ) ),
			self::cta_band( 'Call us' ),
			[ self::paragraph( 'Outro', 'Text' ) ]
		);

		$refused = Abilities\bound_copy_slots( self::placed( $post, 4 ), [ 'Click to call' => '(555) 555-0113' ] );

		$this->assertSame( [ '4/1' ], array_column( $refused, 'path' ) );
		$this->assertStringContainsString( '(core/button text at 4/1)', Abilities\bound_slot_error( 'copy', $refused )->get_error_message() );
	}

	public function test_a_button_whose_link_alone_is_bound_takes_copy(): void {
		$this->assertSame( [], Abilities\bound_copy_slots( self::placed( self::cta_band( 'Call us' ) ), [ 'Email button' => 'Write to the office', 'Heading' => 'x' ] ) );
	}

	public function test_every_refused_slot_is_listed_at_once(): void {
		$blocks   = self::cta_band( 'Call us' );
		$blocks[] = self::paragraph( 'Phone', '(555) 010-0100', [ 'content' => self::PHONE ] );

		$refused = Abilities\bound_copy_slots( self::placed( $blocks, 0, 2 ), [ 'Click to call' => 'a', 'Phone' => 'b', 'Heading' => 'c' ] );

		$this->assertSame( [ 'Click to call', 'Phone' ], array_column( $refused, 'slot' ) );
		$this->assertSame( [ '0/1', '1' ], array_column( $refused, 'path' ) );
	}

	public function test_text_a_synced_pattern_lets_copies_override_is_not_a_bound_slot(): void {
		$blocks = [ self::paragraph( 'Body', 'Default text', [ '__default' => [ 'source' => 'core/pattern-overrides' ] ] ) ];

		$this->assertSame( [], Abilities\bound_copy_slots( self::placed( $blocks ), [ 'Body' => 'New text' ] ) );
	}

	public function test_the_error_names_each_slot_its_source_and_key_and_where_to_change_it(): void {
		$blocks   = self::cta_band( 'Call us' );
		$blocks[] = self::paragraph( 'Price', '$40', [ 'content' => [ 'source' => 'voyager/post-meta-text', 'args' => [ 'key' => 'price' ] ] ] );

		$error   = Abilities\bound_slot_error( 'copy', Abilities\bound_copy_slots( self::placed( $blocks, 0, 2 ), [ 'Click to call' => 'a', 'Price' => '$50' ] ) );
		$message = $error->get_error_message();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'voyager_open_blocks_bound_slot', $error->get_error_code() );
		$this->assertStringStartsWith( 'Nothing was saved', $message );
		$this->assertStringContainsString( '"Click to call" (core/button text at 0/1) is bound to voyager/site-data, key "phone", so change the value in Settings > Site Data instead.', $message );
		$this->assertStringContainsString( '"Price" (core/paragraph content at 1) is bound to voyager/post-meta-text, key "price", so change the post\'s "price" field instead.', $message );
	}

	public function test_configure_refuses_a_bound_attribute_but_not_a_reset_or_an_unbound_one(): void {
		$icon = [
			'blockName'    => 'voyager-open/icon',
			'attrs'        => [
				'icon'     => 'phone',
				'metadata' => [ 'bindings' => [ 'linkUrl' => self::PHONE_LINK ] ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$refused = Abilities\bound_attribute_slots( $icon, [ 'linkUrl' => 'tel:+15555550113', 'size' => 32 ], '3' );

		$this->assertSame( [ 'linkUrl' ], array_column( $refused, 'slot' ) );
		$this->assertSame( 'phone_link', $refused[0]['key'] );
		$this->assertSame( [], Abilities\bound_attribute_slots( $icon, [ 'linkUrl' => null, 'size' => 32 ], '3' ) );

		$button = self::cta_band( '(555) 555-0113' )[0]['innerBlocks'][1];
		$this->assertIsArray( $button );
		$this->assertSame( 'voyager_open_blocks_bound_slot', Abilities\bound_slot_error( 'attributes', Abilities\bound_attribute_slots( $button, [ 'text' => 'x' ], '0/1' ) )->get_error_code() );
	}

	public function test_phone_numbers_and_email_addresses_look_like_contact_details(): void {
		foreach ( [ '(555) 555-0113', '555-0100', '+1 555 555 0113', 'tel:+15550100', 'Call (555) 555-0113 today' ] as $text ) {
			$this->assertSame( 'phone', Abilities\looks_like( $text ), $text );
		}

		foreach ( [ 'hello@example.com', 'mailto:office@example.org' ] as $text ) {
			$this->assertSame( 'email', Abilities\looks_like( $text ), $text );
		}
	}

	public function test_labels_do_not(): void {
		foreach ( [ 'Call us', 'Email us', 'Call now', 'Call the office', 'Follow @example', '' ] as $text ) {
			$this->assertNull( Abilities\looks_like( $text ), $text );
		}
	}

	public function test_only_keys_named_for_a_phone_or_an_email_are_checked(): void {
		// Digits that read as a phone number, bound to keys that never hold one.
		$blocks = [];
		foreach ( [
			'copyright'        => '© 2015-2026 Willow Vet',
			'founded'          => '1998 - 2026',
			'hours'            => 'Closed 11-10-2026',
			'address_locality' => 'Tokyo 100-0001',
			'name'             => 'Holiday hours from 12.01.2026',
			'tagline'          => 'Open again 2026-11-10 09:00',
			'stats'            => '1500000 pets treated',
			'license'          => 'License #1234567',
			'postal'           => 'Springfield, ST 12345-6789',
		] as $key => $text ) {
			$blocks[] = self::paragraph( ucfirst( $key ), $text, [ 'content' => [ 'source' => 'voyager/site-data', 'args' => [ 'key' => $key ] ] ] );
		}

		$this->assertSame( [], Abilities\stale_fallbacks( $blocks, [] ) );

		// The same check still catches the real thing, on Site Data and on post meta.
		$blocks[] = self::paragraph( 'Office phone', 'Call (555) 555-0113', [ 'content' => [ 'source' => 'voyager/post-meta-text', 'args' => [ 'key' => 'office_phone' ] ] ] );
		$blocks[] = self::paragraph( 'Email', 'old@example.org', [ 'content' => [ 'source' => 'voyager/site-data', 'args' => [ 'key' => 'email' ] ] ] );
		$blocks   = array_merge( $blocks, self::cta_band( '(555) 555-0113' ) );

		$this->assertSame(
			[ 'office_phone' => 'phone', 'email' => 'email', 'phone' => 'phone' ],
			array_column( Abilities\stale_fallbacks( $blocks, [] ), 'looks_like', 'key' )
		);
	}

	public function test_validate_flags_the_phone_number_willow_vet_stored_as_the_call_buttons_fallback(): void {
		$stale = Abilities\stale_fallbacks( self::cta_band( '(555) 555-0113' ), [] );

		$this->assertCount( 1, $stale );
		$this->assertSame( '0/1', $stale[0]['path'] );
		$this->assertSame( 'text', $stale[0]['attribute'] );
		$this->assertSame( '(555) 555-0113', $stale[0]['fallback'] );
		$this->assertSame( 'phone', $stale[0]['looks_like'] );

		$warning = Abilities\stale_fallback_warning( $stale[0] );
		$this->assertStringStartsWith( 'stale_fallback: core/button "Click to call" at 0/1, binding "text" (voyager/site-data, key "phone"): its fallback "(555) 555-0113" looks like a phone number.', $warning );
	}

	public function test_validate_flags_nothing_when_the_bound_button_holds_a_label(): void {
		$this->assertSame( [], Abilities\stale_fallbacks( self::cta_band( 'Call us' ), [] ) );
	}

	public function test_a_fallback_the_packs_patterns_ship_is_a_placeholder_not_stale(): void {
		$details = [
			self::paragraph( 'Phone', '(555) 010-0100', [ 'content' => self::PHONE ] ),
			self::paragraph( 'Email', 'hello@example.com', [ 'content' => [ 'source' => 'voyager/site-data', 'args' => [ 'key' => 'email' ] ] ] ),
		];
		$shipped = Abilities\fallback_index( Abilities\bound_fallbacks( $details ) );

		$this->assertSame( [ 'phone', 'email' ], array_column( Abilities\stale_fallbacks( $details, [] ), 'looks_like' ) );
		$this->assertSame( [], Abilities\stale_fallbacks( $details, $shipped ) );

		$moved = [ self::paragraph( 'Email', 'old@example.org', [ 'content' => [ 'source' => 'voyager/site-data', 'args' => [ 'key' => 'email' ] ] ] ) ];
		$this->assertSame( [ 'email' ], array_column( Abilities\stale_fallbacks( $moved, $shipped ), 'looks_like' ) );
	}

	public function test_a_stale_value_in_the_block_comment_is_cleared_with_configure(): void {
		$icon = [
			'blockName'    => 'voyager-open/icon',
			'attrs'        => [
				'icon'     => 'phone',
				'linkUrl'  => 'tel:+15555550113',
				'metadata' => [ 'bindings' => [ 'linkUrl' => self::PHONE_LINK ] ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$stale = Abilities\stale_fallbacks( [ $icon ], [] );

		$this->assertCount( 1, $stale );
		$this->assertFalse( $stale[0]['in_html'] );
		$this->assertStringEndsWith( 'Remove it with configure, passing null for "linkUrl".', Abilities\stale_fallback_warning( $stale[0] ) );
	}

	public function test_the_warning_lands_on_its_block_and_in_the_report_and_valid_stays_true(): void {
		$report = [
			'valid'      => true,
			'post_type'  => 'page',
			'round_trip' => true,
			'blocks'     => [
				[
					'path'     => '0',
					'block'    => 'core/group',
					'errors'   => [],
					'warnings' => [ 'core/group at 0: an earlier warning.' ],
				],
				[
					'path'     => '0/1',
					'block'    => 'core/button',
					'label'    => 'Click to call',
					'errors'   => [],
					'warnings' => [],
				],
			],
			'errors'     => [],
			'warnings'   => [ 'core/group at 0: an earlier warning.' ],
			'markup'     => '',
		];

		$out = Abilities\with_stale_fallbacks( $report, Abilities\stale_fallbacks( self::cta_band( '(555) 555-0113' ), [] ) );

		$this->assertTrue( $out['valid'] );
		$this->assertCount( 1, $out['blocks'][1]['warnings'] );
		$this->assertStringStartsWith( 'stale_fallback: ', $out['blocks'][1]['warnings'][0] );
		$this->assertSame( [ 'core/group at 0: an earlier warning.', $out['blocks'][1]['warnings'][0] ], $out['warnings'] );
		$this->assertSame( $report, Abilities\with_stale_fallbacks( $report, [] ) );
	}
}
