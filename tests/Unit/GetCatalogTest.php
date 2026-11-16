<?php
/**
 * get-catalog in brief and in detail (contract 8 in docs/v2-contracts.md):
 * the brief rows are the whole catalog's rows less the rest, a recipe in
 * detail names each section's pattern and layout and carries its copy rules,
 * each slot whose text is bound marked copy: false, and the Site Data fields
 * its sections bind, and fits in one tool result, a section as get-catalog
 * lists it takes compose's section schema, and a block's name in patterns is
 * refused saying where the block sits.
 *
 * The recipes and copy rules come from recipes/ and patterns/intents/, so
 * these check what ships. scripts/e2e.mjs runs get-catalog over REST and
 * measures the brief answer on a real site, and composes from it.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\BlockTree;
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
	 * A pattern's blocks as BlockTree\describe() lists them, built from its
	 * intent (IntentBlocks). recipe_detail() reads the registered pattern by
	 * default, which needs WordPress to parse.
	 *
	 * @param string $name Pattern name, such as voyager-open/hero.
	 * @return array<int, array{path: string, block: string, label?: string, bindings?: array<mixed>, pattern?: string, copy?: bool}>
	 */
	private static function rows( string $name ): array {
		return BlockTree\describe( IntentBlocks::blocks( substr( $name, strlen( 'voyager-open/' ) ) ) );
	}

	/**
	 * A recipe as get-catalog's recipe input builds it, from the shipped
	 * recipes and intents.
	 *
	 * @param string $slug Recipe slug.
	 * @return array<string, mixed>|null
	 */
	private static function detail( string $slug ): ?array {
		return Abilities\recipe_detail( $slug, null, self::INTENTS, static fn ( string $name ): array => self::rows( $name ) );
	}

	/**
	 * The Site Data source as Catalog\sources() lists it, every field, each
	 * with filled, as with_filled() adds it.
	 *
	 * @return array<string, mixed>
	 */
	private static function site_data_source(): array {
		$fields = [];
		foreach ( SiteData\fields() as $key => $field ) {
			$fields[] = [
				'label'  => $field['label'],
				'args'   => [ 'key' => (string) $key ],
				'filled' => true,
			];
		}

		return [
			'name'   => SiteData\SOURCE,
			'label'  => 'Site data',
			'fields' => $fields,
			'note'   => null,
		];
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
		$brief   = Abilities\brief_recipes( $recipes, self::INTENTS );
		$nulls   = 0;

		$this->assertCount( 6, $brief );
		foreach ( $brief as $index => $recipe ) {
			$this->assertSame( [ 'slug', 'title', 'sections' ], array_keys( $recipe ) );
			$this->assertSame( $recipes[ $index ]['slug'], $recipe['slug'] );
			$this->assertSame( $recipes[ $index ]['title'], $recipe['title'] );

			$detail = self::detail( $recipe['slug'] );
			$this->assertNotNull( $detail );
			$this->assertIsArray( $detail['sections'] );

			foreach ( $recipe['sections'] as $at => $section ) {
				$whole = $recipes[ $index ]['sections'][ $at ];
				unset( $whole['copy_guidance'] );
				$named = $detail['sections'][ $at ];

				// The pattern and layout as the detail names them, so a first
				// layout reads its name; the rest as the file holds it.
				$this->assertSame( [ 'pattern' => $named['pattern'], 'layout' => $named['layout'] ] + $whole, $section, "{$recipe['slug']} section {$at} is not its whole section less copy_guidance, named as the detail names it" );
				$this->assertIsString( $section['layout'], "{$recipe['slug']} section {$at}: every section comes in two layouts, so its layout has a name" );
				$nulls += null === $whole['layout'] ? 1 : 0;
			}
		}

		$this->assertGreaterThan( 0, $nulls, 'some recipe sections give layout null, so the brief answer is seen to name one' );

		$contact = $brief[ array_search( 'contact', array_column( $brief, 'slug' ), true ) ];
		$this->assertSame( [ 'Primary button' ], $contact['sections'][0]['omit'] ?? null, 'a section\'s omit stays in the brief answer, so compose leaves the button out' );
	}

	public function test_a_recipe_in_detail_gives_each_section_the_copy_rules_of_the_pattern_it_names(): void {
		$layouts = Abilities\section_layouts( self::INTENTS );

		foreach ( Abilities\recipes( null, self::INTENTS ) as $whole ) {
			$detail = self::detail( $whole['slug'] );
			$this->assertNotNull( $detail );
			$this->assertSame( [ 'slug', 'title', 'description', 'page_type', 'sections', 'site_data' ], array_keys( $detail ) );
			$this->assertIsArray( $detail['sections'] );
			$this->assertCount( count( $whole['sections'] ), $detail['sections'] );
			$keys = [];

			foreach ( $detail['sections'] as $at => $section ) {
				// The pattern the file's section and layout name, as compose resolves them.
				$name = Abilities\resolve_layout( $layouts, $whole['sections'][ $at ]['pattern'], $whole['sections'][ $at ]['layout'] );
				$this->assertNotNull( $name );

				$this->assertSame(
					json_encode( (object) Abilities\listed_copy_rules( Patterns\intent_meta( $name, self::INTENTS )['copy_rules'], self::rows( $name ) ) ),
					json_encode( $section['copy_rules'] ),
					"{$whole['slug']} section {$at}: copy_rules are not {$name}'s as get-catalog lists them"
				);
				$this->assertSame( json_encode( (object) $whole['sections'][ $at ]['copy_guidance'] ), json_encode( $section['copy_guidance'] ) );

				// The section as the file holds it, but named by its pattern's own name and layout.
				$rest = (array) $section;
				unset( $rest['copy_rules'], $rest['copy_guidance'], $rest['items'] );
				$expected = [
					'pattern' => $name,
					'layout'  => Patterns\intent_meta( $name, self::INTENTS )['layout'],
				] + $whole['sections'][ $at ];
				unset( $expected['copy_guidance'] );
				$this->assertSame( $expected, $rest );

				// The Site Data keys the intent binds, read from the intent file.
				array_push( $keys, ...Patterns\keys_in( IntentBlocks::intent( substr( $name, strlen( 'voyager-open/' ) ) )['intent'] ?? [], SiteData\SOURCE ) );
			}

			$this->assertSame( array_values( array_unique( $keys ) ), $detail['site_data'], "{$whole['slug']}: site_data is not each key its sections bind, once, in page order" );
		}

		$this->assertNotSame( [], self::detail( 'home' )['site_data'] ?? [], 'the home page binds Site Data, so the check is seen to read it' );
		$this->assertNull( self::detail( 'no-such-recipe' ) );
	}

	/**
	 * The pattern compose builds for a section's pattern and layout, as compose
	 * reads them: a layout's own name as its section and layout
	 * (layout_named()), otherwise a section with one of its layouts. Null when
	 * compose refuses the pair.
	 *
	 * @param array<string, list<array{layout: string|null, pattern: string}>> $layouts From section_layouts().
	 * @param string                                                            $pattern A section's pattern.
	 * @param string|null                                                       $layout  Its layout.
	 */
	private static function composed( array $layouts, string $pattern, ?string $layout ): ?string {
		$named = Abilities\layout_named( $layouts, $pattern );

		if ( null === $named ) {
			return Abilities\resolve_layout( $layouts, $pattern, $layout );
		}

		return null === $layout || $named['layout'] === $layout ? Abilities\resolve_layout( $layouts, $named['section'], $named['layout'] ) : null;
	}

	public function test_a_recipe_in_detail_lists_the_numbered_items_omit_takes(): void {
		$home = self::detail( 'home' );
		$this->assertNotNull( $home );
		$this->assertIsArray( $home['sections'] );
		$items = array_column( $home['sections'], 'items', 'pattern' );

		$this->assertSame( [ 'Credential 4' ], $items['voyager-open/proof-strip'] ?? null, 'the first three credentials are required, so omit takes only the fourth' );
		$this->assertSame( [ 'Service 1', 'Service 2', 'Service 3' ], $items['voyager-open/services'] ?? null, 'a whole card goes with its heading and line' );
		$this->assertSame( [], $items['voyager-open/hero'] ?? null, 'the hero numbers no item' );

		// Each item listed, on every recipe, compose leaves out with nothing refused.
		$listed = 0;
		foreach ( Abilities\recipes( null, self::INTENTS ) as $recipe ) {
			$detail = self::detail( $recipe['slug'] );
			$this->assertNotNull( $detail );
			$this->assertIsArray( $detail['sections'] );

			foreach ( $detail['sections'] as $at => $section ) {
				$this->assertIsArray( $section['items'] ?? null, "{$recipe['slug']} section {$at} lists no items" );
				$name = (string) $section['pattern'];

				foreach ( $section['items'] as $label ) {
					$blocks = IntentBlocks::blocks( substr( $name, strlen( 'voyager-open/' ) ) );
					$filled = Abilities\fill_pattern( $blocks, Patterns\intent_meta( $name, self::INTENTS )['copy_rules'], [], [ $label ], [] );
					$this->assertSame( [], $filled, "{$recipe['slug']} section {$at}: omit of {$label} is refused" );
					$this->assertNotContains( $label, array_column( BlockTree\describe( $blocks ), 'label' ), "{$recipe['slug']} section {$at}: {$label} is still there" );
					++$listed;
				}
			}
		}
		$this->assertGreaterThan( 0, $listed );
	}

	public function test_a_recipe_in_detail_names_each_section_s_pattern_and_layout_and_compose_builds_what_the_file_names(): void {
		$layouts = Abilities\section_layouts( self::INTENTS );
		$nulls   = 0;

		foreach ( Abilities\recipes( null, self::INTENTS ) as $whole ) {
			$detail = self::detail( $whole['slug'] );
			$this->assertNotNull( $detail );
			$this->assertIsArray( $detail['sections'] );

			foreach ( $detail['sections'] as $at => $section ) {
				$file = $whole['sections'][ $at ];
				$name = Abilities\resolve_layout( $layouts, $file['pattern'], $file['layout'] );
				$this->assertNotNull( $name );

				$this->assertSame( $name, $section['pattern'], "{$whole['slug']} section {$at}: the pattern is not named by its own name" );
				$this->assertArrayHasKey( $name, Patterns\generated(), 'a name get-catalog\'s patterns input takes' );
				$this->assertSame( Patterns\generated()[ $name ]['layout'], $section['layout'], "{$whole['slug']} section {$at}: the layout is not {$name}'s own" );
				$this->assertSame( $name, self::composed( $layouts, $section['pattern'], $section['layout'] ), "{$whole['slug']} section {$at}: compose builds another pattern from the detail than from the file" );

				if ( null === $file['layout'] ) {
					++$nulls;
					$this->assertIsString( $section['layout'], "{$whole['slug']} section {$at}: a first layout reads null in the file, and the detail names it" );
				}
			}
		}

		$this->assertGreaterThan( 0, $nulls, 'some recipe sections give layout null, so the check is seen to name one' );

		$home = self::detail( 'home' );
		$this->assertNotNull( $home );
		$this->assertSame(
			[ 'hero split', 'proof-strip list', 'services grid', 'cta-band row', 'reviews grid', 'stats row', 'service-area list', 'closing-cta centered' ],
			array_map( static fn ( array $section ): string => substr( (string) $section['pattern'], strlen( 'voyager-open/' ) ) . ' ' . $section['layout'], $home['sections'] ),
			'the home recipe names every section\'s pattern and layout, no layout null'
		);

		$location = self::detail( 'location' );
		$this->assertNotNull( $location );
		$this->assertSame( [ 'voyager-open/hero-cover', 'cover' ], [ $location['sections'][0]['pattern'], $location['sections'][0]['layout'] ], 'a layout that is not the first is named by its own pattern name' );

		// The names come from the generated patterns array, which the kept
		// recipe's version follows (pack_stamp()), as they do from the intents.
		$this->assertSame(
			array_column( $home['sections'], 'layout', 'pattern' ),
			array_column( Abilities\recipe_detail( 'home', null, null, static fn ( string $name ): array => self::rows( $name ) )['sections'] ?? [], 'layout', 'pattern' )
		);
	}

	public function test_a_slot_whose_text_is_bound_is_marked_copy_false_and_is_the_slot_compose_refuses_copy_for(): void {
		$layouts = Abilities\section_layouts( self::INTENTS );
		$marked  = [];

		foreach ( Abilities\recipes( null, self::INTENTS ) as $recipe ) {
			$detail = self::detail( $recipe['slug'] );
			$this->assertNotNull( $detail );
			$this->assertIsArray( $detail['sections'] );

			foreach ( $detail['sections'] as $at => $section ) {
				$name = self::composed( $layouts, $section['pattern'], $section['layout'] );
				$this->assertNotNull( $name );

				/** @var array<string, array<string, mixed>> $rules */
				$rules = json_decode( (string) json_encode( $section['copy_rules'] ), true );
				$bound = array_keys( array_filter( $rules, static fn ( array $rule ): bool => false === ( $rule['copy'] ?? null ) ) );

				// compose and insert refuse copy for exactly these, given copy for every slot.
				$refused = array_column( Abilities\bound_copy_slots( self::rows( $name ), array_fill_keys( array_keys( $rules ), 'Some copy' ) ), 'slot' );
				sort( $refused );
				$sorted = $bound;
				sort( $sorted );
				$this->assertSame( $refused, $sorted, "{$recipe['slug']} section {$at} ({$name}): the slots marked copy: false are not the ones compose refuses copy for" );

				foreach ( $bound as $label ) {
					$this->assertSame( [ 'purpose', 'copy', 'bound' ], array_keys( $rules[ $label ] ), "{$name} \"{$label}\": a bound slot carries its purpose, copy: false and bound, and no min, max, required or claim" );
					$marked[ "{$name} {$label}" ] = $rules[ $label ]['bound'];
				}
			}
		}

		$this->assertSame( [ 'source' => 'voyager/site-data', 'key' => 'phone' ], $marked['voyager-open/hero Click to call'] ?? null, 'the hero\'s call button shows the phone' );
		$this->assertSame( [ 'source' => 'voyager/site-data', 'key' => 'founded' ], $marked['voyager-open/stats Founded value'] ?? null );
		$this->assertArrayNotHasKey( 'voyager-open/contact-section Click to call', $marked, 'the contact section\'s call button binds its link alone, so its text is copy' );

		$contact = self::detail( 'contact' );
		$this->assertNotNull( $contact );
		$this->assertIsArray( $contact['sections'] );
		/** @var array<string, array<string, mixed>> $rules */
		$rules = json_decode( (string) json_encode( $contact['sections'][1]['copy_rules'] ), true );
		$this->assertSame( 'voyager-open/contact-section', $contact['sections'][1]['pattern'] );
		$this->assertSame( [ 'purpose', 'min', 'max', 'required', 'claim' ], array_keys( $rules['Click to call'] ), 'listed as its intent holds it' );
	}

	public function test_the_home_recipe_has_copy_rules_for_every_section_and_each_recipe_in_detail_fits_in_one_tool_result(): void {
		$home = self::detail( 'home' );
		$this->assertNotNull( $home );
		$this->assertIsArray( $home['sections'] );
		foreach ( $home['sections'] as $at => $section ) {
			$this->assertNotSame( '{}', json_encode( $section['copy_rules'] ), "home section {$at} ({$section['pattern']}) has no copy rules" );
		}

		foreach ( Abilities\recipes( null, self::INTENTS ) as $recipe ) {
			// As get-catalog answers: each key as its field, every field with
			// filled. As WordPress sends it over REST: wp_json_encode() with no flags.
			$answer = Abilities\recipe_site_data( self::detail( $recipe['slug'] ), [ self::site_data_source() ] );
			$this->assertIsArray( $answer );
			$this->assertIsArray( $answer['site_data'] );
			$this->assertNotSame( [], $answer['site_data'], "{$recipe['slug']} binds Site Data, so its fields are counted" );

			$json = (string) json_encode( [ 'recipe' => $answer ] );
			$this->assertLessThan( self::LIMIT, strlen( $json ), "get-catalog with recipe {$recipe['slug']} is " . strlen( $json ) . ' characters of JSON' );
			$this->assertGreaterThan( 1000, strlen( $json ), "get-catalog with recipe {$recipe['slug']} holds almost nothing" );
		}
	}

	public function test_a_recipe_s_site_data_is_each_key_as_the_site_data_source_lists_its_field(): void {
		$sources = [
			[
				'name'   => 'core/post-meta',
				'fields' => [
					[
						'label' => 'Phone',
						'args'  => [ 'key' => 'phone' ],
					],
				],
			],
			[
				'name'   => SiteData\SOURCE,
				'fields' => [
					[
						'label'  => 'Phone number',
						'args'   => [ 'key' => 'phone' ],
						'filled' => true,
					],
					[
						'label'  => 'Email address',
						'args'   => [ 'key' => 'email' ],
						'filled' => false,
					],
					[
						'label'  => 'Rating',
						'args'   => [ 'key' => 'rating' ],
						'filled' => false,
					],
				],
			],
		];
		$recipe  = [
			'slug'      => 'home',
			'sections'  => [],
			'site_data' => [ 'email', 'no-such-key', 'phone' ],
		];

		$this->assertSame(
			[
				'slug'      => 'home',
				'sections'  => [],
				'site_data' => [ $sources[1]['fields'][1], $sources[1]['fields'][0] ],
			],
			Abilities\recipe_site_data( $recipe, $sources ),
			'each key in the recipe\'s order, as the Site Data source lists it, filled included; another source\'s field and a key with no field are left out'
		);
		$this->assertSame( [], Abilities\recipe_site_data( [ 'slug' => 'home' ], $sources )['site_data'] ?? null, 'a recipe with no keys lists none' );
		$this->assertNull( Abilities\recipe_site_data( null, $sources ), 'anything but a recipe passes through' );
	}

	public function test_get_catalog_lists_a_bound_text_block_copy_false_and_its_rule_as_bound(): void {
		$listed = [];

		foreach ( [ 'hero', 'contact-section' ] as $slug ) {
			$rows = array_values(
				array_map(
					// As Catalog\patterns() lists a named or bound block.
					static fn ( array $row ): array => isset( $row['label'], BlockTree\TEXT_ATTRIBUTES[ $row['block'] ] ) ? $row + [ 'copy' => true ] : $row,
					array_filter( BlockTree\describe( IntentBlocks::blocks( $slug ) ), static fn ( array $row ): bool => isset( $row['label'] ) || isset( $row['bindings'] ) )
				)
			);

			$listed[] = [
				'name'       => 'voyager-open/' . $slug,
				'copy_rules' => (object) Patterns\intent_meta( 'voyager-open/' . $slug, self::INTENTS )['copy_rules'],
				'blocks'     => $rows,
			];
		}

		[ $hero, $contact ] = Abilities\listed_patterns( $listed );
		$this->assertIsArray( $hero );
		$this->assertIsArray( $contact );

		$copy = static fn ( array $pattern ): array => array_column( array_filter( (array) $pattern['blocks'], static fn ( mixed $row ): bool => is_array( $row ) && isset( $row['copy'] ) ), 'copy', 'label' );

		$this->assertSame(
			[
				'Eyebrow'        => true,
				'Heading'        => true,
				'Body'           => true,
				'Primary button' => true,
				'Click to call'  => false,
			],
			$copy( $hero ),
			'the hero\'s call button shows the phone, so it takes no copy'
		);
		$this->assertTrue( $copy( $contact )['Click to call'] ?? false, 'the contact section\'s call button binds its link alone, so its text is copy' );

		/** @var array<string, array<string, mixed>> $hero_rules */
		$hero_rules = json_decode( (string) json_encode( $hero['copy_rules'] ), true );
		$this->assertFalse( $hero_rules['Click to call']['copy'] ?? null );
		$this->assertSame( 60, $hero_rules['Heading']['max'] ?? null, 'every other rule is the intent\'s' );
		$this->assertSame( json_encode( $listed[1]['copy_rules'] ), json_encode( $contact['copy_rules'] ), 'a pattern with no bound slot keeps its rules as they are' );
		$this->assertIsObject( $contact['copy_rules'], 'copy_rules stays an object, so {} is never []' );
	}

	public function test_a_section_as_get_catalog_lists_it_takes_compose_s_section_schema(): void {
		$this->assertSame( [ 'copy_guidence' ], self::refused_keys( [ 'pattern' => 'voyager-open/hero', 'copy_guidence' => (object) [] ] ), 'the check must refuse a key compose does not list' );
		$this->assertSame( [ 'copy_rules' ], self::refused_keys( [ 'pattern' => 'voyager-open/hero', 'copy_rules' => 'none' ] ), 'the check must refuse copy_rules that is not an object' );

		foreach ( Abilities\recipes( null, self::INTENTS ) as $recipe ) {
			$detail = self::detail( $recipe['slug'] );
			$this->assertNotNull( $detail );
			$this->assertIsArray( $detail['sections'] );
			$brief = Abilities\brief_recipes( [ $recipe ], self::INTENTS )[0];

			foreach ( [ 'brief' => $brief['sections'], 'detail' => $detail['sections'] ] as $form => $sections ) {
				foreach ( $sections as $at => $section ) {
					$this->assertSame( [], self::refused_keys( $section ), "{$recipe['slug']} section {$at}, {$form}: compose's schema refuses these keys" );
				}
			}

			// compose reads both forms as it reads the recipe's own sections:
			// copy_guidance and copy_rules change nothing it builds. Both name
			// each pattern by its own name and layout, which compose builds
			// as the file's section and layout.
			$expected = Abilities\recipe_sections( $recipe );
			$layouts  = Abilities\section_layouts( self::INTENTS );

			foreach ( [ 'brief' => $brief['sections'], 'detail' => $detail['sections'] ] as $form => $sections ) {
				$read = Abilities\sections_input( $sections );
				$this->assertCount( count( $expected ), $read );
				foreach ( $read as $at => $section ) {
					$this->assertSame(
						self::composed( $layouts, $expected[ $at ]['pattern'], $expected[ $at ]['layout'] ),
						self::composed( $layouts, $section['pattern'], $section['layout'] ),
						"{$recipe['slug']} section {$at}: compose builds another pattern from the {$form}"
					);
					$this->assertSame(
						array_diff_key( $expected[ $at ], [ 'pattern' => 1, 'layout' => 1 ] ),
						array_diff_key( $section, [ 'pattern' => 1, 'layout' => 1 ] )
					);
				}
			}
		}
	}

	public function test_compose_takes_each_pattern_get_catalog_lists_by_its_name(): void {
		$items = self::input_schema( 'VoyagerOpenBlocks\Abilities\register_compose', 'voyager-open-blocks/compose' )['properties']['sections']['items'] ?? null;
		$this->assertIsArray( $items );
		$this->assertIsArray( $items['properties']['pattern'] ?? null );
		$this->assertArrayNotHasKey( 'enum', $items['properties']['pattern'], 'an enum refuses a block\'s name with WordPress\'s bare "is not one of" before compose runs' );

		// Each name get-catalog lists on its patterns, such as
		// voyager-open/proof-strip-facts, and each section's name.
		$names    = Abilities\compose_patterns();
		$patterns = Patterns\generated();
		$this->assertSame( [], array_values( array_diff( array_keys( $patterns ), $names ) ) );
		$this->assertSame( [], array_values( array_diff( array_keys( Abilities\section_layouts() ), $names ) ) );
		$this->assertContains( 'voyager-open/proof-strip-facts', $names );
		foreach ( $names as $name ) {
			$this->assertSame( '', Abilities\unknown_pattern( 0, $name, $names, $patterns ), "compose refuses {$name}" );
		}
	}

	public function test_compose_refuses_a_block_s_name_saying_it_is_a_block_and_where_it_sits(): void {
		$names    = Abilities\compose_patterns();
		$patterns = Patterns\generated();

		$this->assertSame(
			'section 2: voyager-open/contact-details is a block, not a pattern: it sits in voyager-open/contact-section and voyager-open/contact-section-stacked. Pass one of those, or place the block with insert.',
			Abilities\unknown_pattern( 2, 'voyager-open/contact-details', $names, $patterns )
		);
		$this->assertStringStartsWith( 'section 0: core/group is a block, not a pattern: it sits in voyager-open/', Abilities\unknown_pattern( 0, 'core/group', $names, $patterns ) );
		$this->assertSame( 'section 1: voyager-open/nothing is not a pattern the pack ships.', Abilities\unknown_pattern( 1, 'voyager-open/nothing', $names, $patterns ) );
		$this->assertSame( 'section 1: voyager-open/hero-split is not a pattern the pack ships.', Abilities\unknown_pattern( 1, 'voyager-open/hero-split', $names, $patterns ), 'a section and its layout run together name nothing' );
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
		// No enum: get-catalog checks the names itself (pattern_refusal()), so
		// a block's name gets an answer WordPress's enum check cannot give.
		$this->assertSame( [ 'type' => 'string' ], $schema['properties']['patterns']['items'] ?? null );
		$this->assertSame( [ 'styles', 'blocks', 'patterns', 'sources', 'used_on', 'recipes' ], $schema['properties']['include']['items']['enum'] ?? null );
		$this->assertSame( Abilities\CATALOG_KEYS, $schema['properties']['include']['items']['enum'] );
	}

	public function test_section_layouts_from_the_generated_array_are_the_intents(): void {
		$from_files = Abilities\section_layouts( self::INTENTS );

		$this->assertGreaterThan( 10, count( $from_files ) );
		$this->assertSame( $from_files, Abilities\section_layouts(), 'the sections, their layouts and their order, as the intent files give them' );
		$this->assertSame( Abilities\recipes( null, self::INTENTS ), Abilities\recipes(), 'so every recipe reads the same' );
	}

	public function test_a_block_s_name_in_patterns_is_refused_saying_it_is_a_block_and_where_it_sits(): void {
		$patterns = Patterns\generated();
		$this->assertNull( Abilities\pattern_refusal( [ 'voyager-open/hero', 'voyager-open/proof-strip-facts' ], $patterns ), 'pattern names pass' );
		$this->assertNull( Abilities\pattern_refusal( [], $patterns ) );

		$refused = Abilities\pattern_refusal( [ 'voyager-open/proof-strip-facts', 'voyager-open/contact-details' ], $patterns );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_invalid_pattern', $refused->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $refused->get_error_data() );

		$message = $refused->get_error_message();
		$this->assertStringStartsWith( 'voyager-open/contact-details is a block, not a pattern: it sits in voyager-open/contact-section and voyager-open/contact-section-stacked.', $message );
		foreach ( array_keys( $patterns ) as $name ) {
			$this->assertStringContainsString( $name, $message, 'the refusal lists every pattern name' );
		}

		$facts = Abilities\pattern_refusal( [ 'voyager-open/facts' ], $patterns );
		$this->assertInstanceOf( \WP_Error::class, $facts );
		$this->assertStringStartsWith( 'voyager-open/facts is a block, not a pattern: it sits in voyager-open/proof-strip-facts, voyager-open/service-area and voyager-open/service-area-split.', $facts->get_error_message() );

		$group = Abilities\pattern_refusal( [ 'core/group' ], $patterns );
		$this->assertInstanceOf( \WP_Error::class, $group );
		$this->assertStringStartsWith( 'core/group is a block, not a pattern: it sits in voyager-open/', $group->get_error_message(), 'a core block is found by the name its comment carries, without core/' );
	}

	/**
	 * Names that only look like a block: a prefix of a block's name, a
	 * section with its layout, another case, a longer name, and text that is
	 * no block name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function not_a_block(): array {
		return [
			'a prefix of contact-details'   => [ 'voyager-open/contact' ],
			'a section and its layout'      => [ 'voyager-open/hero-split' ],
			'another case'                  => [ 'Voyager-Open/Contact-Details' ],
			'a longer name'                 => [ 'voyager-open/contact-details-card' ],
			'a name with a space after it'  => [ 'voyager-open/contact-details {' ],
			'no namespace'                  => [ 'contact-details' ],
			'nothing the pack ships'        => [ 'voyager-open/nothing' ],
		];
	}

	#[DataProvider( 'not_a_block' )]
	public function test_a_name_that_is_neither_a_pattern_nor_a_block_in_one_is_refused_as_no_pattern( string $name ): void {
		$refused = Abilities\pattern_refusal( [ $name ], Patterns\generated() );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertStringStartsWith( "{$name} is not a pattern the pack ships.", $refused->get_error_message() );
		$this->assertStringNotContainsString( 'is a block', $refused->get_error_message() );
	}

	public function test_every_name_that_is_not_a_pattern_is_named_in_one_refusal_in_the_order_given(): void {
		$refused = Abilities\pattern_refusal( [ 'voyager-open/nothing', 'voyager-open/hero', 'voyager-open/icon' ], Patterns\generated() );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$message = $refused->get_error_message();
		$this->assertStringStartsWith( 'voyager-open/nothing is not a pattern the pack ships. voyager-open/icon is a block, not a pattern: it sits in ', $message );
		$this->assertStringNotContainsString( 'voyager-open/hero is', $message, 'a pattern name given beside them is not refused' );
	}

	public function test_get_catalog_refuses_a_block_s_name_before_it_builds_anything(): void {
		foreach ( [ [ 'voyager-open/contact-details' ], 'voyager-open/hero, voyager-open/contact-details' ] as $patterns ) {
			$refused = Abilities\get_catalog( [ 'patterns' => $patterns ] );

			$this->assertInstanceOf( \WP_Error::class, $refused, 'a list, or a comma-separated string as a query string carries it' );
			$this->assertSame( 'voyager_open_blocks_invalid_pattern', $refused->get_error_code() );
		}
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
