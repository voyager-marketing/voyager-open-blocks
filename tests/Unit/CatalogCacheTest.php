<?php
/**
 * get-catalog keeps the parts of its answer that change only with the code,
 * the theme or a setting, and builds them again after a trigger. Whether a
 * Site Data field is filled, and used_on, are read on every call.
 *
 * Abilities\catalog_answer() takes its builders, so the builders here are
 * stand-ins that count their calls and need no WordPress. The transient is
 * held serialized, as the options table holds it (tests/catalog-cache-stubs.php).
 * The real builders run over REST in npm run e2e, which reads used_on after
 * binding the phone on a page, with the parts kept from its earlier calls.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use VoyagerOpenBlocks\PostMetaText;

require_once dirname( __DIR__ ) . '/prompt-stubs.php';
require_once dirname( __DIR__ ) . '/recipes-stubs.php';
require_once dirname( __DIR__ ) . '/catalog-cache-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class CatalogCacheTest extends TestCase {

	/**
	 * The builders whose parts are kept between calls.
	 */
	private const KEPT = [ 'styles', 'blocks', 'patterns', 'brief_patterns', 'sources', 'recipes', 'brief_recipes', 'recipe' ];

	/**
	 * The builders called on every call.
	 */
	private const FRESH = [ 'filled', 'used_on' ];

	/**
	 * The triggers the cache must build again after: a plugin activated,
	 * deactivated or upgraded, another theme, a Global Styles save, and the
	 * bindable post meta allowlist.
	 */
	private const TRIGGERS = [
		'activated_plugin',
		'deactivated_plugin',
		'upgrader_process_complete',
		'switch_theme',
		'save_post_wp_global_styles',
		'add_option_voyager_open_blocks_bindable_meta',
		'update_option_voyager_open_blocks_bindable_meta',
		'delete_option_voyager_open_blocks_bindable_meta',
	];

	/**
	 * Builder => one entry per call, the argument it was given.
	 *
	 * @var array<string, list<string>>
	 */
	private array $calls = [];

	/**
	 * The phone number the stand-in filled reads: '' while it is empty.
	 */
	private string $phone = '';

	/**
	 * Published posts that bind the phone, which the stand-in used_on counts.
	 */
	private int $posts = 0;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']                = [];
		$GLOBALS['voyager_test_transients']             = [];
		$GLOBALS['voyager_test_actions']                = [];
		$GLOBALS['voyager_test_locale']                 = 'en_US';
		$GLOBALS['voyager_test_stylesheet']             = 'theme';
		$GLOBALS['voyager_test_registered_post_types']  = [ 'post', 'page' ];
		$this->calls                                    = [];
		$this->phone                                    = '';
		$this->posts                                    = 0;
	}

	/**
	 * Stand-in builders: each records its call and returns a part shaped as
	 * the real one, with the locale in a label, as translation puts it there,
	 * and objects where the real parts hold them (copy_rules, copy_guidance).
	 *
	 * @return array<string, callable>
	 */
	private function builders(): array {
		$locale = static fn (): string => (string) $GLOBALS['voyager_test_locale'];

		return [
			'styles'         => function () use ( $locale ): array {
				$this->calls['styles'][] = '';
				return [
					'block'  => 'core/group',
					'roles'  => 'theme',
					'styles' => [
						[
							'style' => 'panel',
							'label' => 'Panel ' . $locale(),
						],
					],
				];
			},
			'blocks'         => function (): array {
				$this->calls['blocks'][] = '';
				return [
					[
						'name'     => 'voyager-open/icon',
						'bindable' => [ 'url' ],
					],
				];
			},
			'patterns'       => function (): array {
				$this->calls['patterns'][] = '';
				return [
					[
						'name'       => 'voyager-open/hero',
						'copy_rules' => (object) [ 'Heading' => [ 'max' => 60 ] ],
					],
					[
						'name'       => 'voyager-open/cta-band',
						'copy_rules' => (object) [],
					],
				];
			},
			'brief_patterns' => function (): array {
				$this->calls['brief_patterns'][] = '';
				return [
					[
						'name'   => 'voyager-open/hero',
						'layout' => null,
					],
				];
			},
			'sources'        => function ( string $post_type ) use ( $locale ): array {
				$this->calls['sources'][] = $post_type;
				return [
					[
						'name'   => 'voyager/site-data',
						'label'  => 'Site data ' . $locale(),
						'fields' => [
							[
								'label' => 'Phone number',
								'args'  => [ 'key' => 'phone' ],
							],
						],
					],
					[
						'name'   => 'core/post-meta',
						'label'  => 'Post Meta for ' . $post_type,
						'fields' => [],
					],
				];
			},
			'recipes'        => function (): array {
				$this->calls['recipes'][] = '';
				return [
					[
						'slug'     => 'home',
						'sections' => [
							[
								'pattern'       => 'voyager-open/hero',
								'copy_guidance' => (object) [ 'Heading' => 'What you do, where.' ],
							],
						],
					],
				];
			},
			'brief_recipes'  => function (): array {
				$this->calls['brief_recipes'][] = '';
				return [
					[
						'slug'     => 'home',
						'sections' => [ [ 'pattern' => 'voyager-open/hero' ] ],
					],
				];
			},
			'recipe'         => function ( string $slug ): ?array {
				$this->calls['recipe'][] = $slug;
				return 'home' === $slug
					? [
						'slug'     => 'home',
						'sections' => [
							[
								'pattern'    => 'voyager-open/hero',
								'copy_rules' => (object) [ 'Heading' => [ 'max' => 60 ] ],
							],
						],
					]
					: null;
			},
			'filled'         => function ( array $sources ): array {
				$this->calls['filled'][] = '';
				foreach ( $sources as $index => $source ) {
					if ( 'voyager/site-data' === $source['name'] ) {
						foreach ( $source['fields'] as $at => $field ) {
							$sources[ $index ]['fields'][ $at ]['filled'] = '' !== $this->phone;
						}
					}
				}
				return $sources;
			},
			'used_on'        => function ( array $sources ): array {
				$this->calls['used_on'][] = '';
				return [
					'voyager/site-data:phone' => [
						'posts'          => $this->posts,
						'templates'      => 0,
						'template_parts' => 0,
					],
				];
			},
		];
	}

	/**
	 * get-catalog's answer through the stand-ins, failing on an error.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private function answer( array $input = [] ): array {
		$answer = Abilities\catalog_answer( $input, $this->builders() );
		$this->assertIsArray( $answer );

		return $answer;
	}

	/**
	 * How many times each kept builder has been called.
	 *
	 * @return array<string, int>
	 */
	private function keptBuilds(): array {
		$out = [];
		foreach ( self::KEPT as $builder ) {
			$out[ $builder ] = count( $this->calls[ $builder ] ?? [] );
		}

		return $out;
	}

	/**
	 * Fire a hook as WordPress would: every callback add_action() was given for it.
	 */
	private static function fire( string $hook ): void {
		$callbacks = $GLOBALS['voyager_test_actions'][ $hook ] ?? [];
		self::assertNotSame( [], $callbacks, "nothing is hooked to {$hook}" );

		foreach ( $callbacks as $callback ) {
			$callback();
		}
	}

	/**
	 * Every input get-catalog takes: in brief, each list in full, patterns by
	 * name, a recipe, and all of them together for another post type.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function inputs(): array {
		return [
			'brief'              => [ [] ],
			'include all six'    => [ [ 'include' => [ 'styles', 'blocks', 'patterns', 'sources', 'used_on', 'recipes' ] ] ],
			'patterns by name'   => [ [ 'patterns' => [ 'voyager-open/cta-band' ] ] ],
			'a recipe'           => [ [ 'recipe' => 'home' ] ],
			'everything, a post' => [
				[
					'post_type' => 'post',
					'include'   => [ 'styles', 'blocks', 'patterns', 'sources', 'used_on', 'recipes' ],
					'patterns'  => [ 'voyager-open/hero' ],
					'recipe'    => 'home',
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $input Input.
	 */
	#[DataProvider( 'inputs' )]
	public function test_a_warm_second_get_catalog_call_builds_nothing( array $input ): void {
		$first = $this->answer( $input );
		$built = $this->keptBuilds();
		$fresh = array_map( fn ( string $builder ): int => count( $this->calls[ $builder ] ?? [] ), array_combine( self::FRESH, self::FRESH ) );

		$this->assertGreaterThan( 0, array_sum( $built ), 'the first call builds what it answers with' );
		$this->assertLessThanOrEqual( 1, max( $built ), 'the first call builds each part once' );

		$second = $this->answer( $input );

		$this->assertSame( $built, $this->keptBuilds(), 'the second call built a kept part again' );
		$this->assertSame( json_encode( $first ), json_encode( $second ), 'the second answer is the first' );
		foreach ( self::FRESH as $builder ) {
			$this->assertSame( 2 * $fresh[ $builder ], count( $this->calls[ $builder ] ?? [] ), "{$builder} is built on every call that answers with it" );
		}
	}

	public function test_the_answer_is_what_the_builders_give_and_patterns_by_name_keep_their_order(): void {
		$brief = $this->answer();
		$this->assertSame( [ 'styles', 'patterns', 'sources', 'recipes' ], array_keys( $brief ) );
		$this->assertSame( 'voyager-open/hero', $brief['patterns'][0]['name'], 'in brief, patterns are the brief rows' );
		$this->assertFalse( $brief['sources'][0]['fields'][0]['filled'] );

		$named = $this->answer( [ 'patterns' => [ 'voyager-open/cta-band', 'voyager-open/hero' ] ] );
		$this->assertSame( [ 'patterns' ], array_keys( $named ) );
		$this->assertSame( [ 'voyager-open/hero', 'voyager-open/cta-band' ], array_column( $named['patterns'], 'name' ), 'the names in the whole list\'s order, as Catalog\patterns() gives them' );
		$this->assertSame( '{"Heading":{"max":60}}', json_encode( $named['patterns'][0]['copy_rules'] ), 'an object stays an object once kept' );
		$this->assertSame( '{}', json_encode( $named['patterns'][1]['copy_rules'] ), 'an empty object stays {} once kept, never []' );

		$recipe = $this->answer( [ 'recipe' => 'home' ] );
		$this->assertSame( [ 'recipe' ], array_keys( $recipe ) );
		$this->assertSame( '{"Heading":{"max":60}}', json_encode( $recipe['recipe']['sections'][0]['copy_rules'] ) );

		$this->assertSame( 1, count( $this->calls['patterns'] ), 'patterns by name read the kept whole list' );
	}

	public function test_a_part_is_built_once_for_every_input_that_needs_it(): void {
		$this->answer();
		$this->answer( [ 'include' => [ 'styles' ] ] );
		$this->answer( [ 'include' => [ 'sources', 'used_on' ] ] );

		$this->assertCount( 1, $this->calls['styles'], 'the brief answer and include styles share one styles part' );
		$this->assertSame( [ 'page' ], $this->calls['sources'], 'the brief answer, include sources and used_on share the page\'s sources' );
	}

	public function test_the_issue_s_triggers_are_hooked(): void {
		$this->assertSame( self::TRIGGERS, Abilities\CATALOG_TRIGGERS );
		$this->assertSame( 'voyager_open_blocks_bindable_meta', PostMetaText\OPTION );

		Abilities\catalog_triggers();
		$this->assertSame( self::TRIGGERS, array_keys( $GLOBALS['voyager_test_actions'] ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function triggers(): array {
		$out = [];
		foreach ( self::TRIGGERS as $hook ) {
			$out[ $hook ] = [ $hook ];
		}

		return $out;
	}

	#[DataProvider( 'triggers' )]
	public function test_each_trigger_makes_the_next_call_build_again( string $hook ): void {
		Abilities\catalog_triggers();
		$all = [
			'include' => [ 'styles', 'blocks', 'patterns', 'sources', 'used_on', 'recipes' ],
			'recipe'  => 'home',
		];

		$this->answer();
		$this->answer( $all );
		$warm = $this->keptBuilds();
		$this->answer();
		$this->answer( $all );
		$this->assertSame( $warm, $this->keptBuilds(), 'warm before the trigger' );

		self::fire( $hook );

		$this->answer();
		$this->answer( $all );
		foreach ( $this->keptBuilds() as $builder => $count ) {
			$this->assertSame( $warm[ $builder ] + 1, $count, "after {$hook}, {$builder} is built again, once" );
		}
	}

	public function test_another_version_of_the_pack_builds_again(): void {
		$this->answer();
		$warm = $this->keptBuilds();

		// What an earlier Version left: the same transient, under its own version.
		$name = Abilities\catalog_transient();
		$kept = unserialize( $GLOBALS['voyager_test_transients'][ $name ]['value'] );
		$this->assertSame( Abilities\catalog_version(), $kept['version'] );
		$this->assertStringStartsWith( VOYAGER_OPEN_BLOCKS_VERSION . '|', $kept['version'], 'the version holds the pack\'s Version' );

		$kept['version'] = '0.0.1|' . substr( $kept['version'], strlen( VOYAGER_OPEN_BLOCKS_VERSION ) + 1 );
		set_transient( $name, $kept, Abilities\CATALOG_TTL );
		$this->answer();
		$this->assertSame( $warm['styles'] + 1, $this->keptBuilds()['styles'], 'parts an earlier Version kept are built again' );

		set_transient( $name, 'not the parts', Abilities\CATALOG_TTL );
		$this->answer();
		$this->assertSame( $warm['styles'] + 2, $this->keptBuilds()['styles'], 'a transient that holds anything else is built again' );
	}

	public function test_another_theme_builds_again_and_each_locale_keeps_its_own_parts(): void {
		$this->answer();
		$this->assertCount( 1, $this->calls['styles'] );

		$GLOBALS['voyager_test_stylesheet'] = 'other-theme';
		$this->answer();
		$this->assertCount( 2, $this->calls['styles'], 'another active theme, even without switch_theme, builds again' );

		$GLOBALS['voyager_test_locale'] = 'de_DE';
		$german                         = $this->answer();
		$this->assertCount( 3, $this->calls['styles'], 'another locale builds its own parts' );
		$this->assertSame( 'Panel de_DE', $german['styles']['styles'][0]['label'] );

		$GLOBALS['voyager_test_locale'] = 'en_US';
		$english                        = $this->answer();
		$this->assertCount( 3, $this->calls['styles'], 'the first locale\'s parts are still kept' );
		$this->assertSame( 'Panel en_US', $english['styles']['styles'][0]['label'], 'never another locale\'s labels' );
		$this->assertCount( 2, $GLOBALS['voyager_test_transients'] );
	}

	public function test_used_on_and_filled_are_read_on_every_call_with_the_cache_warm(): void {
		$input = [ 'include' => [ 'sources', 'used_on' ] ];

		$before = $this->answer( $input );
		$this->assertSame( 0, $before['used_on']['voyager/site-data:phone']['posts'] );
		$this->assertFalse( $before['sources'][0]['fields'][0]['filled'] );
		$warm = $this->keptBuilds();

		// The phone is set, and a page that binds it is published.
		$this->phone = '(555) 010-0100';
		++$this->posts;

		$after = $this->answer( $input );
		$this->assertSame( $warm, $this->keptBuilds(), 'nothing kept was built again' );
		$this->assertSame( 1, $after['used_on']['voyager/site-data:phone']['posts'], 'used_on counts the page published after the parts were kept' );
		$this->assertTrue( $after['sources'][0]['fields'][0]['filled'], 'filled follows the phone set after the parts were kept' );
		$this->assertTrue( $this->answer()['sources'][0]['fields'][0]['filled'], 'and so does the brief answer' );

		$kept = unserialize( $GLOBALS['voyager_test_transients'][ Abilities\catalog_transient() ]['value'] );
		$this->assertSame( [ 'sources:page', 'styles', 'brief_patterns', 'brief_recipes' ], array_keys( $kept['parts'] ), 'no used_on and no filled are kept' );
		$this->assertArrayNotHasKey( 'filled', $kept['parts']['sources:page'][0]['fields'][0], 'the kept sources hold no filled' );
	}

	public function test_sources_are_kept_per_registered_post_type_and_an_unknown_one_is_never_kept(): void {
		$this->answer( [ 'post_type' => 'page' ] );
		$this->answer( [ 'post_type' => 'post' ] );
		$this->answer( [ 'post_type' => 'page' ] );
		$this->answer( [ 'post_type' => 'post' ] );
		$this->assertSame( [ 'page', 'post' ], $this->calls['sources'] );

		$this->answer( [ 'post_type' => 'no-such-type' ] );
		$this->answer( [ 'post_type' => 'no-such-type' ] );
		$this->assertSame( [ 'page', 'post', 'no-such-type', 'no-such-type' ], $this->calls['sources'], 'an unknown post type is built on every call' );

		$kept = unserialize( $GLOBALS['voyager_test_transients'][ Abilities\catalog_transient() ]['value'] );
		$this->assertArrayNotHasKey( 'sources:no-such-type', $kept['parts'], 'so any number of made-up post types adds nothing to the transient' );
	}

	public function test_an_unknown_recipe_is_refused_and_never_kept(): void {
		$refused = Abilities\catalog_answer( [ 'recipe' => 'no-such-recipe' ], $this->builders() );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'voyager_open_blocks_invalid_recipe', $refused->get_error_code() );
		$this->assertStringContainsString( 'lists the recipes: home.', $refused->get_error_message() );

		$kept = unserialize( $GLOBALS['voyager_test_transients'][ Abilities\catalog_transient() ]['value'] );
		$this->assertSame( [ 'brief_recipes' ], array_keys( $kept['parts'] ), 'only the recipe list the refusal names is kept' );

		Abilities\catalog_answer( [ 'recipe' => 'no-such-recipe' ], $this->builders() );
		$this->assertSame( [ 'no-such-recipe', 'no-such-recipe' ], $this->calls['recipe'] );
	}

	public function test_the_parts_expire_so_the_transient_is_never_autoloaded(): void {
		$this->answer();

		$this->assertGreaterThan( 0, Abilities\CATALOG_TTL );
		$this->assertSame( Abilities\CATALOG_TTL, $GLOBALS['voyager_test_transients'][ Abilities\catalog_transient() ]['expiration'], 'set_transient() autoloads a transient with no expiration' );
	}

	public function test_the_pack_stamp_follows_its_data_files(): void {
		$dir = sys_get_temp_dir() . '/voyager-catalog-stamp-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/inc/generated', 0777, true );
		mkdir( $dir . '/build', 0777, true );
		mkdir( $dir . '/recipes', 0777, true );
		$then = time() - 100;
		foreach ( [ '/inc/generated/patterns.php', '/build/blocks-manifest.php', '/recipes/home.json' ] as $file ) {
			file_put_contents( $dir . $file, 'x' );
			touch( $dir . $file, $then );
		}

		try {
			clearstatcache();
			$stamp = Abilities\pack_stamp( $dir );
			$this->assertSame( $stamp, Abilities\pack_stamp( $dir ), 'the same files give the same stamp' );

			foreach ( [ '/inc/generated/patterns.php', '/build/blocks-manifest.php', '/recipes/home.json' ] as $at => $file ) {
				touch( $dir . $file, $then + 10 + $at );
				clearstatcache();
				$this->assertNotSame( $stamp, Abilities\pack_stamp( $dir ), "{$file} changed, so the stamp did" );
				$stamp = Abilities\pack_stamp( $dir );
			}

			file_put_contents( $dir . '/recipes/about.json', 'x' );
			clearstatcache();
			$this->assertNotSame( $stamp, Abilities\pack_stamp( $dir ), 'a recipe added changes the stamp' );
		} finally {
			foreach ( [ '/inc/generated/patterns.php', '/build/blocks-manifest.php', '/recipes/home.json', '/recipes/about.json' ] as $file ) {
				@unlink( $dir . $file );
			}
			foreach ( [ '/inc/generated', '/inc', '/build', '/recipes', '' ] as $sub ) {
				@rmdir( $dir . $sub );
			}
		}
	}

	public function test_get_catalog_builds_through_every_builder_catalog_answer_takes(): void {
		$this->assertSame( array_merge( self::KEPT, self::FRESH ), array_keys( Abilities\catalog_builders() ) );
	}
}
