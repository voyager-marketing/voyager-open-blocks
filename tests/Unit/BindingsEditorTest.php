<?php
/**
 * The data behind the editor hints (inc/bindings-editor.php).
 *
 * assets/editor/hints.js finds placeholder content from this data, never from
 * the text on screen. These tests pin the data to the pattern intents, so a
 * renamed block or a new placeholder logo cannot quietly turn a hint off.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BindingsEditor;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/bindings-editor-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/bindings-editor.php';

final class BindingsEditorTest extends TestCase {

	/**
	 * Keys whose value is never empty: the current year, and the copyright
	 * line built from it.
	 */
	private const ALWAYS_SET = [ 'year', 'copyright' ];

	/**
	 * The block tree of a pattern intent.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function intent_blocks( string $name ): array {
		$json = file_get_contents( dirname( __DIR__, 2 ) . "/patterns/intents/{$name}.json" );
		self::assertIsString( $json );

		$intent = json_decode( $json, true );
		self::assertIsArray( $intent );
		self::assertIsArray( $intent['intent']['blocks'] ?? null );

		/** @var list<array<string, mixed>> */
		return $intent['intent']['blocks'];
	}

	/**
	 * Every node of an intent tree, depth first.
	 *
	 * @param list<array<string, mixed>> $blocks Intent nodes.
	 * @return list<array<string, mixed>>
	 */
	private static function flatten( array $blocks ): array {
		$all = [];

		foreach ( $blocks as $block ) {
			$all[] = $block;
			if ( isset( $block['children'] ) && is_array( $block['children'] ) ) {
				/** @var list<array<string, mixed>> $children */
				$children = $block['children'];
				array_push( $all, ...self::flatten( $children ) );
			}
		}

		return $all;
	}

	/**
	 * @param array<string, mixed> $block Intent node.
	 */
	private static function name_of( array $block ): ?string {
		$name = $block['attrs']['metadata']['name'] ?? null;

		return is_string( $name ) ? $name : null;
	}

	public function test_placeholder_images_cover_every_logo_the_marquee_inserts(): void {
		$assets   = plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE );
		$inserted = [];

		foreach ( self::flatten( self::intent_blocks( 'logo-marquee' ) ) as $block ) {
			if ( 'core/image' === ( $block['block'] ?? null ) && is_string( $block['url'] ?? null ) ) {
				// The swap inc/patterns.php makes when it registers the pattern.
				$inserted[] = str_replace( Patterns\ASSETS_TOKEN, $assets, $block['url'] );
			}
		}

		$this->assertNotEmpty( $inserted );
		$this->assertSame( [], array_values( array_diff( $inserted, BindingsEditor\placeholder_images() ) ) );
	}

	public function test_every_placeholder_image_is_a_file_in_assets_placeholders(): void {
		$base = plugins_url( 'assets/placeholders/', VOYAGER_OPEN_BLOCKS_FILE );
		$urls = BindingsEditor\placeholder_images();

		$this->assertCount( count( glob( VOYAGER_OPEN_BLOCKS_DIR . '/assets/placeholders/*.svg' ) ?: [] ), $urls );

		foreach ( $urls as $url ) {
			$this->assertStringStartsWith( $base, $url );
			$this->assertFileExists( VOYAGER_OPEN_BLOCKS_DIR . '/assets/placeholders/' . substr( $url, strlen( $base ) ) );
		}
	}

	public function test_the_form_slot_names_match_the_contact_section_intent(): void {
		$found = false;

		foreach ( self::flatten( self::intent_blocks( 'contact-section' ) ) as $block ) {
			if ( 'core/column' !== ( $block['block'] ?? null ) || BindingsEditor\FORM_SLOT['slot'] !== self::name_of( $block ) ) {
				continue;
			}

			/** @var list<array<string, mixed>> $children */
			$children = is_array( $block['children'] ?? null ) ? $block['children'] : [];
			foreach ( $children as $child ) {
				if ( 'core/group' === ( $child['block'] ?? null ) && BindingsEditor\FORM_SLOT['fallback'] === self::name_of( $child ) ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found, 'contact-section.json has no "Form" group directly inside a "Form slot" column.' );
	}

	public function test_a_stored_key_links_to_its_field_on_site_data(): void {
		$this->assertSame(
			[
				'screen' => 'site-data',
				'url'    => admin_url( 'options-general.php?page=voyager-site-data' ) . '#voyager_site_phone',
			],
			BindingsEditor\site_data_link( 'phone' )
		);
	}

	public function test_a_derived_key_links_to_the_field_it_comes_from(): void {
		$this->assertSame( BindingsEditor\site_data_link( 'phone' ), BindingsEditor\site_data_link( 'phone_link' ) );
		$this->assertSame( BindingsEditor\site_data_link( 'email' ), BindingsEditor\site_data_link( 'email_link' ) );
		$this->assertSame( BindingsEditor\site_data_link( 'address_city' ), BindingsEditor\site_data_link( 'address_locality' ) );
	}

	public function test_the_company_and_tagline_link_to_settings_general(): void {
		$this->assertSame(
			[
				'screen' => 'general',
				'url'    => admin_url( 'options-general.php' ) . '#blogname',
			],
			BindingsEditor\site_data_link( 'company' )
		);
		$this->assertSame( admin_url( 'options-general.php' ) . '#blogdescription', BindingsEditor\site_data_link( 'tagline' )['url'] ?? null );
	}

	public function test_a_key_that_is_never_empty_or_unknown_gets_no_link(): void {
		foreach ( self::ALWAYS_SET as $key ) {
			$this->assertNull( BindingsEditor\site_data_link( $key ), $key );
		}

		$this->assertNull( BindingsEditor\site_data_link( 'phne' ) );
	}

	public function test_every_key_that_can_be_empty_has_a_link(): void {
		$fields = SiteData\fields();

		foreach ( array_keys( $fields ) as $key ) {
			if ( in_array( $key, self::ALWAYS_SET, true ) ) {
				continue;
			}

			$link = BindingsEditor\site_data_link( $key );
			$this->assertNotNull( $link, "{$key} has nowhere to be set." );

			if ( 'site-data' === $link['screen'] ) {
				$anchor = substr( $link['url'], (int) strrpos( $link['url'], '#' ) + 1 );
				$this->assertContains( $anchor, array_column( $fields, 'option' ), "{$key} links to a field the screen does not have." );
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_hints_data_carries_site_data_links_when_the_pack_owns_the_source(): void {
		SiteData\owned( true );

		$data = BindingsEditor\hints_data();

		$this->assertSame( BindingsEditor\site_data_link( 'phone' ), $data['siteDataLinks']['phone'] ?? null );
		$this->assertArrayNotHasKey( 'year', $data['siteDataLinks'] );
		$this->assertSame( BindingsEditor\placeholder_images(), $data['placeholderImages'] );
		$this->assertSame( BindingsEditor\FORM_SLOT, $data['formSlot'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_hints_data_has_no_site_data_links_when_another_plugin_owns_the_source(): void {
		$data = BindingsEditor\hints_data();

		$this->assertSame( [], $data['siteDataLinks'] );
		$this->assertNotEmpty( $data['placeholderImages'] );
	}
}
