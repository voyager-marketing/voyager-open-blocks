<?php
/**
 * get-catalog in brief and in detail (contract 8 in docs/v2-contracts.md):
 * the brief rows are the whole catalog's rows less the rest, a recipe in
 * detail carries each section's copy rules and fits in one tool result, and
 * a section as get-catalog lists it takes compose's section schema.
 *
 * The recipes and copy rules come from recipes/ and patterns/intents/, so
 * these check what ships. scripts/e2e.mjs runs get-catalog over REST and
 * measures the brief answer on a real site, and composes from it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/prompt-stubs.php';
require_once dirname( __DIR__ ) . '/recipes-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class GetCatalogTest extends TestCase {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/**
	 * Characters of JSON one get-catalog answer stays under, as
	 * scripts/lib/catalog-budget.mjs holds it for npm run e2e.
	 */
	private const LIMIT = 20000;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_doing_it_wrong'] = [];
		$GLOBALS['voyager_test_filters']        = [];
		$GLOBALS['voyager_test_options']        = [];
		$GLOBALS['voyager_test_bloginfo']       = [];
		$GLOBALS['voyager_test_abilities']      = [];
	}

	/**
	 * A registered ability's input schema, through the recording stub.
	 *
	 * @param callable(): void $register The ability's register function.
	 * @param string           $name     Its name.
	 * @return array<string, mixed>
	 */
	private static function input_schema( callable $register, string $name ): array {
		$register();
		$args = $GLOBALS['voyager_test_abilities'][ $name ] ?? null;
		self::assertIsArray( $args );
		self::assertIsArray( $args['input_schema'] ?? null );

		/** @var array<string, mixed> $schema */
		$schema = $args['input_schema'];
		return $schema;
	}

	/**
	 * What compose's schema refuses in one section, as WordPress checks it
	 * against the schema before compose runs: a key it does not list (its
	 * additionalProperties is false), and copy_guidance or copy_rules that is
	 * not an object.
	 *
	 * @param array<string, mixed> $section A section.
	 * @return list<string> The keys refused.
	 */
	private static function refused_keys( array $section ): array {
		$items = self::input_schema( 'VoyagerOpenBlocks\Abilities\register_compose', 'voyager-open-blocks/compose' )['properties']['sections']['items'] ?? null;
		self::assertIsArray( $items );
		self::assertFalse( $items['additionalProperties'] ?? null );
		self::assertIsArray( $items['properties'] ?? null );

		$refused = [];
		foreach ( $section as $key => $value ) {
			$schema = $items['properties'][ $key ] ?? null;
			if ( ! is_array( $schema ) || ( 'object' === ( $schema['type'] ?? null ) && ! is_object( $value ) && ! is_array( $value ) ) ) {
				$refused[] = (string) $key;
			}
		}

		return $refused;
	}

	public function test_a_brief_recipe_is_its_slug_title_and_sections_less_copy_guidance(): void {
		$recipes = Abilities\recipes( null, self::INTENTS );
		$brief   = Abilities\brief_recipes( $recipes );

		$this->assertCount( 6, $brief );
		foreach ( $brief as $index => $recipe ) {
			$this->assertSame( [ 'slug', 'title', 'sections' ], array_keys( $recipe ) );
			$this->assertSame( $recipes[ $index ]['slug'], $recipe['slug'] );
			$this->assertSame( $recipes[ $index ]['title'], $recipe['title'] );

			foreach ( $recipe['sections'] as $at => $section ) {
				$whole = $recipes[ $index ]['sections'][ $at ];
				unset( $whole['copy_guidance'] );
				$this->assertSame( $whole, $section, "{$recipe['slug']} section {$at} is not its whole section less copy_guidance" );
			}
		}

		$contact = $brief[ array_search( 'contact', array_column( $brief, 'slug' ), true ) ];
		$this->assertSame( [ 'Primary button' ], $contact['sections'][0]['omit'] ?? null, 'a section\'s omit stays in the brief answer, so compose leaves the button out' );
	}

	public function test_a_recipe_in_detail_gives_each_section_the_copy_rules_of_the_pattern_it_names(): void {
		$layouts = Abilities\section_layouts( self::INTENTS );

		foreach ( Abilities\recipes( null, self::INTENTS ) as $whole ) {
			$detail = Abilities\recipe_detail( $whole['slug'], null, self::INTENTS );
			$this->assertNotNull( $detail );
			$this->assertSame( [ 'slug', 'title', 'description', 'page_type', 'sections' ], array_keys( $detail ) );
			$this->assertCount( count( $whole['sections'] ), $detail['sections'] );

			foreach ( $detail['sections'] as $at => $section ) {
				$name = Abilities\resolve_layout( $layouts, $section['pattern'], $section['layout'] );
				$this->assertNotNull( $name );

				$this->assertSame(
					json_encode( (object) Patterns\intent_meta( $name, self::INTENTS )['copy_rules'] ),
					json_encode( $section['copy_rules'] ),
					"{$whole['slug']} section {$at}: copy_rules are not {$name}'s"
				);
				$this->assertSame( json_encode( (object) $whole['sections'][ $at ]['copy_guidance'] ), json_encode( $section['copy_guidance'] ) );

				$rest = (array) $section;
				unset( $rest['copy_rules'], $rest['copy_guidance'] );
				$expected = $whole['sections'][ $at ];
				unset( $expected['copy_guidance'] );
				$this->assertSame( $expected, $rest );
			}
		}

		$this->assertNull( Abilities\recipe_detail( 'no-such-recipe', null, self::INTENTS ) );
	}

	public function test_the_home_recipe_has_copy_rules_for_every_section_and_each_recipe_in_detail_fits_in_one_tool_result(): void {
		$home = Abilities\recipe_detail( 'home', null, self::INTENTS );
		$this->assertNotNull( $home );
		foreach ( $home['sections'] as $at => $section ) {
			$this->assertNotSame( '{}', json_encode( $section['copy_rules'] ), "home section {$at} ({$section['pattern']}) has no copy rules" );
		}

		foreach ( Abilities\recipes( null, self::INTENTS ) as $recipe ) {
			// As WordPress sends it over REST: wp_json_encode() with no flags.
			$json = (string) json_encode( [ 'recipe' => Abilities\recipe_detail( $recipe['slug'], null, self::INTENTS ) ] );
			$this->assertLessThan( self::LIMIT, strlen( $json ), "get-catalog with recipe {$recipe['slug']} is " . strlen( $json ) . ' characters of JSON' );
			$this->assertGreaterThan( 1000, strlen( $json ), "get-catalog with recipe {$recipe['slug']} holds almost nothing" );
		}
	}

	public function test_a_section_as_get_catalog_lists_it_takes_compose_s_section_schema(): void {
		$this->assertSame( [ 'copy_guidence' ], self::refused_keys( [ 'pattern' => 'voyager-open/hero', 'copy_guidence' => (object) [] ] ), 'the check must refuse a key compose does not list' );
		$this->assertSame( [ 'copy_rules' ], self::refused_keys( [ 'pattern' => 'voyager-open/hero', 'copy_rules' => 'none' ] ), 'the check must refuse copy_rules that is not an object' );

		foreach ( Abilities\recipes( null, self::INTENTS ) as $recipe ) {
			$detail = Abilities\recipe_detail( $recipe['slug'], null, self::INTENTS );
			$this->assertNotNull( $detail );
			$brief = Abilities\brief_recipes( [ $recipe ] )[0];

			foreach ( [ 'brief' => $brief['sections'], 'detail' => $detail['sections'] ] as $form => $sections ) {
				foreach ( $sections as $at => $section ) {
					$this->assertSame( [], self::refused_keys( $section ), "{$recipe['slug']} section {$at}, {$form}: compose's schema refuses these keys" );
				}
			}

			// compose reads both forms as it reads the recipe's own sections:
			// copy_guidance and copy_rules change nothing it builds.
			$expected = Abilities\recipe_sections( $recipe );
			$this->assertSame( $expected, Abilities\sections_input( $brief['sections'] ) );
			$this->assertSame( $expected, Abilities\sections_input( $detail['sections'] ) );
		}
	}

	public function test_a_brief_pattern_is_its_name_description_section_and_layout(): void {
		$registered = [
			[
				'name'        => 'core/query-standard-posts',
				'description' => 'Not the pack\'s.',
			],
		];
		foreach ( Patterns\generated() as $name => $entry ) {
			$registered[] = [ 'name' => $name ] + $entry['args'];
		}

		$brief = Abilities\brief_patterns( $registered );

		$this->assertSame( array_keys( Patterns\generated() ), array_column( $brief, 'name' ), 'every pack pattern, in the registry\'s order, and no other' );
		foreach ( $brief as $row ) {
			$this->assertSame( [ 'name', 'description', 'section', 'layout' ], array_keys( $row ) );
			$this->assertSame( Patterns\generated()[ $row['name'] ]['args']['description'], $row['description'] );
			$this->assertNotSame( '', $row['description'] );

			$intent = Patterns\intent_meta( $row['name'] );
			$this->assertSame( $intent['section'], $row['section'] );
			$this->assertSame( $intent['layout'], $row['layout'] );
		}
	}

	public function test_get_catalog_takes_recipe_patterns_and_include_and_nothing_else(): void {
		$schema = self::input_schema( 'VoyagerOpenBlocks\Abilities\register_get_catalog', 'voyager-open-blocks/get-catalog' );

		$this->assertSame( [ 'post_type', 'recipe', 'patterns', 'include' ], array_keys( (array) $schema['properties'] ) );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array_column( Abilities\recipes(), 'slug' ), $schema['properties']['recipe']['enum'] ?? null );
		$this->assertSame( array_keys( Patterns\generated() ), $schema['properties']['patterns']['items']['enum'] ?? null );
		$this->assertSame( [ 'styles', 'blocks', 'patterns', 'sources', 'used_on', 'recipes' ], $schema['properties']['include']['items']['enum'] ?? null );
		$this->assertSame( Abilities\CATALOG_KEYS, $schema['properties']['include']['items']['enum'] );
	}

	public function test_section_layouts_from_the_generated_array_are_the_intents(): void {
		$from_files = Abilities\section_layouts( self::INTENTS );

		$this->assertGreaterThan( 10, count( $from_files ) );
		$this->assertSame( $from_files, Abilities\section_layouts(), 'the sections, their layouts and their order, as the intent files give them' );
		$this->assertSame( Abilities\recipes( null, self::INTENTS ), Abilities\recipes(), 'so every recipe reads the same' );
	}

	public function test_a_list_input_is_read_from_an_array_or_a_comma_separated_string(): void {
		$this->assertSame( [ 'used_on', 'blocks' ], Abilities\catalog_list( [ 'used_on', 'blocks', 'used_on' ] ) );
		$this->assertSame( [ 'blocks', 'used_on' ], Abilities\catalog_list( 'blocks, used_on' ) );
		$this->assertSame( [ 'voyager-open/hero' ], Abilities\catalog_list( [ 4, '', 'voyager-open/hero', null ] ) );
		$this->assertSame( [], Abilities\catalog_list( null ) );
		$this->assertSame( [], Abilities\catalog_list( '' ) );
		$this->assertSame( [], Abilities\catalog_list( [] ) );
	}

	/**
	 * Sources as Catalog\sources() gives them, two Site Data fields and one post meta field.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sources(): array {
		return [
			[
				'name'   => SiteData\SOURCE,
				'label'  => 'Site data',
				'fields' => [
					[
						'label' => 'Phone number',
						'args'  => [ 'key' => 'phone' ],
					],
					[
						'label' => 'License number',
						'args'  => [ 'key' => 'license' ],
					],
				],
				'note'   => null,
			],
			[
				'name'   => 'core/post-meta',
				'label'  => 'Post Meta',
				'fields' => [
					[
						'label' => 'Intro',
						'args'  => [ 'key' => 'intro' ],
					],
				],
				'note'   => null,
			],
		];
	}

	// SiteData\owned() is a static that nothing resets, so the owned case runs
	// in its own process.

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_each_site_data_field_says_whether_it_is_filled_as_its_binding_reads_it(): void {
		SiteData\owned( true );
		$GLOBALS['voyager_test_options'] = [
			'voyager_site_phone'   => '(555) 010-0100',
			'voyager_site_license' => '   ',
		];

		$sources = Abilities\with_filled( self::sources() );

		$this->assertSame( [ true, false ], array_column( (array) $sources[0]['fields'], 'filled' ), 'a value of spaces is empty, as compose\'s empty reads it' );
		$this->assertSame( self::sources()[1], $sources[1], 'post meta depends on the post, so it says nothing' );

		// The voyager_site_data_value filter is read too, as the binding reads it.
		add_filter( 'voyager_site_data_value', static fn ( string $value, string $key ): string => 'phone' === $key ? '' : $value, 10, 2 );
		$this->assertSame( [ false, false ], array_column( (array) Abilities\with_filled( self::sources() )[0]['fields'], 'filled' ) );
	}

	public function test_another_owner_s_site_data_fields_carry_no_filled(): void {
		$this->assertFalse( SiteData\owned() );
		$this->assertSame( self::sources(), Abilities\with_filled( self::sources() ) );
	}
}
