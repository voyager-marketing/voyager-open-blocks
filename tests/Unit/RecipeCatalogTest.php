<?php
/**
 * The page recipes as the abilities read them (contract 5 in
 * docs/v2-contracts.md): get-catalog's `recipes` list, which is
 * Abilities\recipe_list(), and compose's `recipe` input.
 *
 * A recipe file that does not decode, is not in contract 5's shape, or names
 * a section and layout no intent carries is skipped with a _doing_it_wrong()
 * notice and never reaches the list. RecipesTest checks the shipped recipes
 * against the design rules; this checks what the abilities do with a file.
 * scripts/e2e.mjs runs get-catalog and compose with a recipe over REST.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use WP_Error;

require_once dirname( __DIR__ ) . '/recipes-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class RecipeCatalogTest extends TestCase {

	private const RECIPES = __DIR__ . '/../../recipes';
	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/** The function that reports a skipped recipe file. */
	private const NOTICE = 'VoyagerOpenBlocks\Abilities\recipes';

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_doing_it_wrong'] = [];
		$GLOBALS['voyager_test_filters']        = [];

		$dir = tempnam( sys_get_temp_dir(), 'voyager-recipes-' );
		$this->assertIsString( $dir );
		unlink( $dir );
		mkdir( $dir );
		$this->dir = $dir;
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	/**
	 * A shipped recipe, decoded with objects as arrays, for a test to change.
	 *
	 * @param string $slug Recipe slug.
	 * @return array<string, mixed>
	 */
	private static function shipped( string $slug ): array {
		$data = json_decode( (string) file_get_contents( self::RECIPES . '/' . $slug . '.json' ), true );
		self::assertIsArray( $data );

		/** @var array<string, mixed> $data */
		return $data;
	}

	/**
	 * The slugs recipe_list() gives for a directory.
	 *
	 * @return list<string>
	 */
	private function listed(): array {
		return array_column( Abilities\recipe_list( $this->dir, self::INTENTS ), 'slug' );
	}

	public function test_get_catalog_lists_every_shipped_recipe_as_its_file_holds_it_with_its_slug(): void {
		$files = glob( self::RECIPES . '/*.json' ) ?: [];
		$list  = Abilities\recipe_list( null, self::INTENTS );

		$this->assertCount( 6, $files, 'recipes/ holds one recipe per page type' );
		$this->assertSame( array_map( static fn ( string $file ): string => basename( $file, '.json' ), $files ), array_column( $list, 'slug' ) );
		$this->assertSame( [], $GLOBALS['voyager_test_doing_it_wrong'], 'a shipped recipe was skipped' );

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		foreach ( $list as $index => $recipe ) {
			$file = json_decode( (string) file_get_contents( $files[ $index ] ) );
			$this->assertSame(
				json_encode( $file, $flags ),
				json_encode( array_diff_key( $recipe, [ 'slug' => true ] ), $flags ),
				"{$recipe['slug']}: get-catalog lists it differently from the file"
			);
		}
	}

	public function test_a_section_with_no_copy_guidance_lists_an_empty_object(): void {
		$recipe = self::shipped( 'contact' );
		foreach ( (array) $recipe['sections'] as $index => $section ) {
			unset( $recipe['sections'][ $index ]['copy_guidance'] );
		}
		file_put_contents( $this->dir . '/contact.json', (string) json_encode( $recipe ) );

		$sections = Abilities\recipe_list( $this->dir, self::INTENTS )[0]['sections'] ?? [];

		$this->assertNotSame( [], $sections );
		foreach ( $sections as $section ) {
			$this->assertSame( '{}', json_encode( $section['copy_guidance'] ) );
		}
	}

	/**
	 * Recipe files the reader skips: a file name, its contents, and part of
	 * the reason read_recipe() gives.
	 *
	 * @return array<string, array{string, \Closure(): string, string}>
	 */
	public static function faulty(): array {
		$home = static function ( \Closure $plant ): \Closure {
			return static function () use ( $plant ): string {
				$recipe = self::shipped( 'home' );
				$plant( $recipe );
				return (string) json_encode( $recipe, JSON_UNESCAPED_SLASHES );
			};
		};

		return [
			'a file that does not decode'          => [ 'home', static fn (): string => '{ "title": "Home page", ', 'does not decode' ],
			'a JSON list'                          => [ 'home', static fn (): string => '[]', 'does not decode to a JSON object' ],
			'an empty object'                      => [ 'home', static fn (): string => '{}', 'does not decode to a JSON object' ],
			'a file name that is not a slug'       => [ 'Home page', $home( static function ( array &$recipe ): void {} ), 'not a slug' ],
			'an unknown layout'                    => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][0]['layout'] = 'banner';
					}
				),
				'sections[0] names voyager-open/hero with layout "banner"',
			],
			'a layout on a section with one layout' => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][1]['layout'] = 'wide';
					}
				),
				'sections[1] names voyager-open/proof-strip with layout "wide"',
			],
			'a layout named by its own pattern'    => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][0]['pattern'] = 'voyager-open/hero-cover';
						$recipe['sections'][0]['layout']  = null;
					}
				),
				'sections[0] names voyager-open/hero-cover with layout null',
			],
			'an unknown pattern'                   => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][2]['pattern'] = 'voyager-open/banner';
					}
				),
				'sections[2] names voyager-open/banner',
			],
			'an unknown style'                     => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][1]['style'] = 'dark';
					}
				),
				'sections[1]: style is not one of plain, panel, inverted, accent',
			],
			'no title'                             => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						unset( $recipe['title'] );
					}
				),
				'"title" is not a non-empty string',
			],
			'no sections'                          => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'] = [];
					}
				),
				'"sections" is not a non-empty list',
			],
			'a section that is not an object'      => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][1] = 'voyager-open/proof-strip';
					}
				),
				'sections[1] is not a JSON object',
			],
			'copy guidance written as a list'      => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][0]['copy_guidance'] = [ 'What the business does.' ];
					}
				),
				'sections[0]: copy_guidance is not an object',
			],
			'guidance that is not a string'        => [
				'home',
				$home(
					static function ( array &$recipe ): void {
						$recipe['sections'][0]['copy_guidance']['Heading'] = 5;
					}
				),
				'the copy_guidance for "Heading" is not a string',
			],
		];
	}

	/**
	 * @param string                $slug   The file name, less .json.
	 * @param \Closure(): string    $write  The file's contents.
	 * @param string                $expect Part of the reason it is skipped.
	 */
	#[DataProvider( 'faulty' )]
	public function test_a_faulty_recipe_file_is_skipped_with_a_notice_and_never_reaches_get_catalog( string $slug, \Closure $write, string $expect ): void {
		copy( self::RECIPES . '/contact.json', $this->dir . '/contact.json' );
		$this->assertSame( [ 'contact' ], $this->listed(), 'the control file must be listed before a faulty one is added' );
		$this->assertSame( [], $GLOBALS['voyager_test_doing_it_wrong'] );

		$file = $this->dir . '/' . $slug . '.json';
		file_put_contents( $file, $write() );

		$this->assertSame( [ 'contact' ], $this->listed(), 'the faulty file reached the list' );
		$this->assertSame( [ self::NOTICE ], $GLOBALS['voyager_test_doing_it_wrong'], 'the faulty file was skipped without a notice' );
		$this->assertNull( Abilities\find_recipe( $slug, $this->dir, self::INTENTS ) );

		$reason = Abilities\read_recipe( $file, Abilities\section_layouts( self::INTENTS ) );
		$this->assertIsString( $reason );
		$this->assertStringContainsString( $expect, $reason );
	}

	public function test_the_same_file_unchanged_is_listed_with_no_notice(): void {
		file_put_contents( $this->dir . '/home.json', (string) json_encode( self::shipped( 'home' ), JSON_UNESCAPED_SLASHES ) );

		$this->assertSame( [ 'home' ], $this->listed() );
		$this->assertSame( [], $GLOBALS['voyager_test_doing_it_wrong'] );
	}

	public function test_a_recipe_expands_to_its_sections_with_their_styles_and_no_copy(): void {
		$recipe = Abilities\find_recipe( 'home', null, self::INTENTS );
		$this->assertNotNull( $recipe );

		$expected = [];
		foreach ( (array) self::shipped( 'home' )['sections'] as $section ) {
			$this->assertIsArray( $section );
			$expected[] = [
				'pattern' => $section['pattern'],
				'layout'  => $section['layout'],
				'style'   => $section['style'],
				'copy'    => [],
				'omit'    => [],
				'repeat'  => [],
			];
		}

		$this->assertSame( $expected, Abilities\recipe_sections( $recipe ) );
		$this->assertNull( Abilities\find_recipe( 'no-such-recipe', null, self::INTENTS ) );
	}

	public function test_a_recipe_section_s_omit_is_listed_and_goes_to_compose_as_the_section_s_omit(): void {
		$recipe = Abilities\find_recipe( 'contact', null, self::INTENTS );
		$this->assertNotNull( $recipe );

		$hero = $recipe['sections'][0];
		$this->assertSame( 'voyager-open/hero', $hero['pattern'] );
		$this->assertSame( [ 'Primary button' ], $hero['omit'] ?? null, 'the contact page\'s hero leaves out its main button, which links to the contact page' );

		$sections = Abilities\recipe_sections( $recipe );
		$this->assertSame( [ 'Primary button' ], $sections[0]['omit'] );
		$this->assertSame( [], $sections[1]['omit'], 'a section with no omit omits nothing' );
		$this->assertArrayNotHasKey( 'omit', $recipe['sections'][1], 'a section whose file gives no omit lists none' );
	}

	/**
	 * A recipe's omit the reader refuses: what to plant on the home recipe's
	 * proof strip, and part of the reason read_recipe() gives.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function faulty_omit(): array {
		// The same reading RecipesTest gives the shipped files.
		$reason = 'sections[1]: omit is not a non-empty list of block labels';

		return [
			'omit that is not a list'      => [ 'Credential 4', $reason ],
			'omit written as an object'    => [ [ 'label' => 'Credential 4' ], $reason ],
			'an empty omit'                => [ [], $reason ],
			'an empty label'               => [ [ 'Credential 4', '' ], $reason ],
			'a label of spaces'            => [ [ ' ' ], $reason ],
			'a label that is not a string' => [ [ 4 ], $reason ],
		];
	}

	/**
	 * @param mixed  $omit   The omit to plant.
	 * @param string $expect Part of the reason the file is skipped.
	 */
	#[DataProvider( 'faulty_omit' )]
	public function test_a_recipe_whose_omit_is_not_a_list_of_labels_is_skipped( mixed $omit, string $expect ): void {
		$recipe                        = self::shipped( 'home' );
		$recipe['sections'][1]['omit'] = $omit;
		$file                          = $this->dir . '/home.json';
		file_put_contents( $file, (string) json_encode( $recipe, JSON_UNESCAPED_SLASHES ) );

		$this->assertSame( [], $this->listed() );
		$this->assertSame( [ self::NOTICE ], $GLOBALS['voyager_test_doing_it_wrong'] );

		$reason = Abilities\read_recipe( $file, Abilities\section_layouts( self::INTENTS ) );
		$this->assertIsString( $reason );
		$this->assertStringContainsString( $expect, $reason );
	}

	public function test_compose_s_recipe_input_is_an_enum_of_the_slugs_found(): void {
		$schema = Abilities\recipe_input_schema();
		$files  = glob( self::RECIPES . '/*.json' ) ?: [];

		$this->assertSame( 'string', $schema['type'] ?? null );
		$this->assertSame( array_map( static fn ( string $file ): string => basename( $file, '.json' ), $files ), $schema['enum'] ?? null );
		$this->assertStringContainsString( 'copy_guidance', (string) ( $schema['description'] ?? '' ) );
	}

	public function test_compose_refuses_an_unknown_recipe_naming_the_ones_there_are(): void {
		$refused = Abilities\compose(
			[
				'title'  => 'A page',
				'recipe' => 'no-such-recipe',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_invalid_recipe', $refused->get_error_code() );
		$this->assertStringContainsString( 'Nothing was saved: no recipe "no-such-recipe"', $refused->get_error_message() );
		$this->assertStringContainsString( 'home', $refused->get_error_message() );
	}

	public function test_compose_refuses_a_recipe_and_sections_together(): void {
		$refused = Abilities\compose(
			[
				'title'    => 'A page',
				'recipe'   => 'home',
				'sections' => [ [ 'pattern' => 'voyager-open/hero' ] ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_invalid_recipe', $refused->get_error_code() );
		$this->assertStringContainsString( 'not both', $refused->get_error_message() );
	}

	public function test_compose_with_neither_asks_for_sections_or_a_recipe(): void {
		$refused = Abilities\compose( [ 'title' => 'A page' ] );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_invalid_sections', $refused->get_error_code() );
		$this->assertStringContainsString( 'recipe', $refused->get_error_message() );
	}
}
