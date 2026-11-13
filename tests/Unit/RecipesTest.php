<?php
/**
 * The page recipes in recipes/ against contract 5 in docs/v2-contracts.md and
 * DESIGN.md: one recipe per page type, each section a pattern and layout an
 * intent carries, one hero and it comes first, no two adjacent sections in
 * one style other than plain, and copy guidance only for the named text
 * blocks a copy rule covers.
 *
 * Reads recipes/*.json and patterns/intents/*.json only, so no WordPress is
 * needed.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use VoyagerOpenBlocks\BlockTree;

final class RecipesTest extends TestCase {

	private const RECIPES = __DIR__ . '/../../recipes';
	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/** The page types of contract 5, one recipe each. */
	private const PAGE_TYPES = [ 'home', 'services', 'service', 'location', 'contact', 'about' ];

	/** A recipe's keys, sorted. */
	private const KEYS = [ 'description', 'page_type', 'sections', 'title' ];

	/** A recipe section's keys, sorted. */
	private const SECTION_KEYS = [ 'copy_guidance', 'layout', 'pattern', 'style' ];

	/** Plain and the three section styles (contract 4). */
	private const STYLES = [ 'plain', 'panel', 'inverted', 'accent' ];

	/** The strong styles. Plain and Panel are quiet (DESIGN.md, Colors). */
	private const STRONG = [ 'inverted', 'accent' ];

	/** The section that holds a page's one h1. */
	private const HERO = 'hero';

	/** Where a claim's fact comes from, which its guidance names. */
	private const SOURCE = '/\bsite owner\b|\bSite Data\b/';

	private const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * Every recipe file, by slug, decoded with objects kept as stdClass so a
	 * copy_guidance written as a list can be told from one written as an
	 * object.
	 *
	 * @return array<string, mixed>
	 */
	private static function recipes(): array {
		$out = [];

		foreach ( glob( self::RECIPES . '/*.json' ) ?: [] as $file ) {
			$out[ basename( $file, '.json' ) ] = json_decode( (string) file_get_contents( $file ) );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Every intent file, by pattern name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function intents(): array {
		static $out = null;

		if ( null === $out ) {
			$out = [];
			foreach ( glob( self::INTENTS . '/*.json' ) ?: [] as $file ) {
				$data = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $data ) ) {
					/** @var array<string, mixed> $data */
					$out[ basename( $file, '.json' ) ] = $data;
				}
			}
			ksort( $out );
		}

		return $out;
	}

	/**
	 * The intent a recipe section names, as contract 5 resolves it, or null
	 * when no intent carries that pattern and layout.
	 *
	 * `pattern` names the section by its slug. A section with layouts takes a
	 * layout name its intents carry, or null for its first layout, the intent
	 * that keeps the section's slug. A section with one layout takes null only.
	 * A layout's own pattern name, such as voyager-open/hero-cover, is not a
	 * section's slug, so it names nothing.
	 *
	 * @param string      $pattern The section's `pattern`.
	 * @param string|null $layout  The section's `layout`.
	 */
	private static function resolve( string $pattern, ?string $layout ): ?string {
		if ( 1 !== preg_match( '#^voyager-open/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $pattern, $match ) ) {
			return null;
		}

		$slug     = $match[1];
		$intents  = self::intents();
		$sections = [];
		foreach ( $intents as $name => $intent ) {
			if ( is_string( $intent['section'] ?? null ) && is_string( $intent['layout'] ?? null ) ) {
				$sections[ $intent['section'] ][ $intent['layout'] ] = $name;
			}
		}

		if ( isset( $sections[ $slug ] ) ) {
			if ( null === $layout ) {
				return in_array( $slug, $sections[ $slug ], true ) ? $slug : null;
			}

			return $sections[ $slug ][ $layout ] ?? null;
		}

		if ( isset( $intents[ $slug ] ) && ! array_key_exists( 'section', $intents[ $slug ] ) ) {
			return null === $layout ? $slug : null;
		}

		return null;
	}

	/**
	 * The nodes of an intent's block tree, flattened.
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 * @return list<array<mixed>>
	 */
	private static function nodes( array $intent ): array {
		$out  = [];
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				if ( is_array( $node ) ) {
					$out[] = $node;
					$walk( is_array( $node['children'] ?? null ) ? $node['children'] : [] );
				}
			}
		};

		$blocks = $intent['intent']['blocks'] ?? [];
		$walk( is_array( $blocks ) ? $blocks : [] );

		return $out;
	}

	/**
	 * The labels of an intent's named text blocks, the blocks whose text
	 * insert's copy replaces (BlockTree\TEXT_ATTRIBUTES), and of those whose
	 * text a binding supplies. Insert refuses copy for a bound one.
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 * @return array{text: list<string>, bound: list<string>}
	 */
	private static function slots( array $intent ): array {
		$out = [
			'text'  => [],
			'bound' => [],
		];

		foreach ( self::nodes( $intent ) as $node ) {
			$label     = $node['attrs']['metadata']['name'] ?? null;
			$attribute = BlockTree\TEXT_ATTRIBUTES[ (string) ( $node['block'] ?? '' ) ] ?? null;
			if ( ! is_string( $label ) || null === $attribute ) {
				continue;
			}
			$out['text'][] = $label;
			if ( isset( $node['attrs']['metadata']['bindings'][ $attribute ] ) ) {
				$out['bound'][] = $label;
			}
		}

		return $out;
	}

	/**
	 * Whether an intent holds a level 1 heading.
	 *
	 * @param array<string, mixed> $intent A decoded intent file.
	 */
	private static function holds_h1( array $intent ): bool {
		foreach ( self::nodes( $intent ) as $node ) {
			if ( 'core/heading' === ( $node['block'] ?? null ) && 1 === ( $node['level'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every way the recipes break contract 5 or DESIGN.md, one line each.
	 *
	 * @param array<string, mixed> $recipes Slug => the file as json_decode() returns it, objects as stdClass.
	 * @return list<string>
	 */
	private static function problems( array $recipes ): array {
		$problems = [];
		$seen     = [];
		$intents  = self::intents();

		foreach ( $recipes as $slug => $recipe ) {
			$at = (string) $slug;

			if ( 1 !== preg_match( self::SLUG, $at ) ) {
				$problems[] = "{$at}: the file name is not a slug";
			}
			if ( ! $recipe instanceof stdClass ) {
				$problems[] = "{$at}: the file is not a JSON object";
				continue;
			}

			$fields = get_object_vars( $recipe );
			foreach ( array_diff( self::KEYS, array_keys( $fields ) ) as $key ) {
				$problems[] = "{$at}: missing key \"{$key}\"";
			}
			foreach ( array_diff( array_keys( $fields ), self::KEYS ) as $key ) {
				$problems[] = "{$at}: unknown key \"{$key}\"";
			}

			foreach ( [ 'title', 'description' ] as $key ) {
				if ( array_key_exists( $key, $fields ) && ( ! is_string( $fields[ $key ] ) || '' === trim( $fields[ $key ] ) ) ) {
					$problems[] = "{$at}: \"{$key}\" is not a non-empty string";
				}
			}

			if ( array_key_exists( 'page_type', $fields ) ) {
				$type = $fields['page_type'];
				if ( ! is_string( $type ) || ! in_array( $type, self::PAGE_TYPES, true ) ) {
					$problems[] = "{$at}: page_type is not one of " . implode( ', ', self::PAGE_TYPES );
				} elseif ( isset( $seen[ $type ] ) ) {
					$problems[] = "{$at}: page_type \"{$type}\" is {$seen[ $type ]}'s too, and a page type has one recipe";
				} else {
					$seen[ $type ] = $at;
				}
			}

			if ( ! array_key_exists( 'sections', $fields ) ) {
				continue;
			}
			$sections = $fields['sections'];
			if ( ! is_array( $sections ) || [] === $sections || ! array_is_list( $sections ) ) {
				$problems[] = "{$at}: sections is not a non-empty list";
				continue;
			}

			$previous = null;
			$heroes   = [];
			$h1s      = [];

			foreach ( $sections as $index => $section ) {
				$where = "{$at} section " . ( $index + 1 );

				if ( ! $section instanceof stdClass ) {
					$problems[] = "{$where}: is not a JSON object";
					$previous   = null;
					continue;
				}

				$keys    = get_object_vars( $section );
				$pattern = $keys['pattern'] ?? null;
				$layout  = $keys['layout'] ?? null;
				$style   = $keys['style'] ?? null;
				$name    = null;

				if ( is_string( $pattern ) ) {
					$where .= " ({$pattern})";
				}
				foreach ( array_diff( self::SECTION_KEYS, array_keys( $keys ) ) as $key ) {
					$problems[] = "{$where}: missing key \"{$key}\"";
				}
				foreach ( array_diff( array_keys( $keys ), self::SECTION_KEYS ) as $key ) {
					$problems[] = "{$where}: unknown key \"{$key}\"";
				}

				if ( null !== $layout && ! is_string( $layout ) ) {
					$problems[] = "{$where}: layout is neither a layout's name nor null";
				} elseif ( ! is_string( $pattern ) ) {
					$problems[] = "{$where}: pattern is not a string";
				} else {
					$name = self::resolve( $pattern, $layout );
					if ( null === $name ) {
						$problems[] = sprintf( '%s: names no pattern, since no intent in patterns/intents/ carries "%s" with layout %s', $where, $pattern, null === $layout ? 'null' : "\"{$layout}\"" );
					}
				}

				if ( ! array_key_exists( 'style', $keys ) ) {
					$style = null;
				} elseif ( ! is_string( $style ) || ! in_array( $style, self::STYLES, true ) ) {
					$problems[] = "{$where}: style is not one of " . implode( ', ', self::STYLES );
				} elseif ( $style === $previous && 'plain' !== $style ) {
					$problems[] = "{$where}: shares the style \"{$style}\" with the section before it, and only plain sections sit next to each other in one style";
				} elseif ( in_array( $style, self::STRONG, true ) && in_array( $previous, self::STRONG, true ) ) {
					$problems[] = "{$where}: is strong (\"{$style}\") right after a strong section (\"{$previous}\"), so quiet and strong do not alternate";
				}
				$previous = is_string( $style ) ? $style : null;

				$intent = null === $name ? null : $intents[ $name ];
				if ( null !== $intent ) {
					if ( self::HERO === ( $intent['section'] ?? null ) ) {
						$heroes[] = $index;
					}
					if ( self::holds_h1( $intent ) ) {
						$h1s[] = $index;
					}
				}

				if ( ! array_key_exists( 'copy_guidance', $keys ) ) {
					continue;
				}
				if ( ! $keys['copy_guidance'] instanceof stdClass ) {
					$problems[] = "{$where}: copy_guidance is not an object of slot => line";
					continue;
				}
				if ( null === $intent ) {
					continue;
				}

				$rules = is_array( $intent['copy_rules'] ?? null ) ? $intent['copy_rules'] : [];
				$slots = self::slots( $intent );

				foreach ( get_object_vars( $keys['copy_guidance'] ) as $slot => $line ) {
					$slot = (string) $slot;
					if ( ! is_string( $line ) || '' === trim( $line ) ) {
						$problems[] = "{$where}: the guidance for \"{$slot}\" is not a non-empty string";
					} elseif ( ! in_array( $slot, $slots['text'], true ) ) {
						$problems[] = "{$where}: guidance for \"{$slot}\", which is not a named text block of voyager-open/{$name}";
					} elseif ( ! is_array( $rules[ $slot ] ?? null ) ) {
						$problems[] = "{$where}: guidance for \"{$slot}\", which has no copy rule in voyager-open/{$name}";
					} elseif ( in_array( $slot, $slots['bound'], true ) ) {
						$problems[] = "{$where}: guidance for \"{$slot}\", whose text a binding supplies, so insert refuses copy for it";
					} elseif ( true === ( $rules[ $slot ]['claim'] ?? null ) && 1 !== preg_match( self::SOURCE, $line ) ) {
						$problems[] = "{$where}: \"{$slot}\" is a claim, so its guidance says where the fact comes from: Site Data or the site owner";
					}
				}
			}

			if ( 1 !== count( $heroes ) ) {
				$problems[] = "{$at}: holds " . count( $heroes ) . ' heroes, and a page has one h1, so one hero';
			}
			if ( [] !== $heroes && 0 !== $heroes[0] ) {
				$problems[] = "{$at}: the hero is section " . ( $heroes[0] + 1 ) . ', and it must come first';
			}
			if ( [ 0 ] !== $h1s ) {
				$problems[] = "{$at}: the page's one h1 is not in its first section alone";
			}
		}

		foreach ( array_diff( self::PAGE_TYPES, array_keys( $seen ) ) as $type ) {
			$problems[] = "no recipe has page_type \"{$type}\"";
		}

		return $problems;
	}

	public function test_six_recipes_are_read_one_per_page_type(): void {
		$recipes = self::recipes();
		$this->assertCount( count( self::PAGE_TYPES ), $recipes, 'recipes/ holds one recipe per page type: ' . implode( ', ', self::PAGE_TYPES ) );

		$types = [];
		foreach ( $recipes as $slug => $recipe ) {
			$this->assertInstanceOf( stdClass::class, $recipe, "{$slug}.json is not a JSON object" );
			$types[] = $recipe->page_type ?? null;
		}
		$this->assertEqualsCanonicalizing( self::PAGE_TYPES, $types );
	}

	public function test_every_recipe_follows_contract_5_and_the_design_rules(): void {
		$recipes = self::recipes();
		$this->assertNotSame( [], $recipes, 'no recipe files were read' );
		$this->assertSame( [], self::problems( $recipes ) );
	}

	public function test_the_entry_skill_lists_each_recipe_s_patterns_and_styles_in_order(): void {
		$skill = (string) file_get_contents( __DIR__ . '/../../skills/voyager-open-blocks/SKILL.md' );

		foreach ( self::recipes() as $slug => $recipe ) {
			$this->assertInstanceOf( stdClass::class, $recipe, $slug );
			$type = (string) $recipe->page_type;
			$this->assertSame( 1, preg_match( '/^\| `' . preg_quote( $type, '/' ) . '` \| `recipes\/' . preg_quote( (string) $slug, '/' ) . '\.json` \| (.+) \|$/m', $skill, $row ), "the entry skill's recipe table has no row for {$slug}" );
			preg_match_all( '/`(voyager-open\/[a-z0-9-]+)` \(([a-z]+)\)/', $row[1], $listed, PREG_SET_ORDER );

			$expected = [];
			foreach ( $recipe->sections as $section ) {
				$expected[] = [ 'voyager-open/' . self::resolve( $section->pattern, $section->layout ), $section->style ];
			}
			$this->assertSame( $expected, array_map( static fn ( array $match ): array => [ $match[1], $match[2] ], $listed ), "the entry skill lists {$slug}'s sections differently from the recipe" );
		}
	}

	public function test_the_hero_is_the_only_section_with_an_h1(): void {
		$intents = self::intents();
		$this->assertNotSame( [], $intents, 'no intent files were read' );

		foreach ( $intents as $name => $intent ) {
			$this->assertSame( self::HERO === ( $intent['section'] ?? null ), self::holds_h1( $intent ), "{$name}: only the hero holds an h1" );
		}
	}

	/**
	 * Faults planted in the shipped recipes, each with what its problem says.
	 *
	 * @return array<string, array{\Closure(array<string, mixed>): void, string}>
	 */
	public static function planted(): array {
		$section = static function ( array $recipes, string $slug, int $index ): stdClass {
			$recipe = $recipes[ $slug ];
			self::assertInstanceOf( stdClass::class, $recipe );
			self::assertIsArray( $recipe->sections );
			$found = $recipe->sections[ $index ];
			self::assertInstanceOf( stdClass::class, $found );

			return $found;
		};
		$styled  = static function ( array $recipes, string $slug, string $style ): int {
			foreach ( $recipes[ $slug ]->sections as $index => $section ) {
				if ( $style === $section->style ) {
					return $index;
				}
			}
			self::fail( "{$slug} has no {$style} section to plant a fault beside" );
		};
		$guided  = static function ( array $recipes, bool $claim ): stdClass {
			foreach ( $recipes as $recipe ) {
				foreach ( $recipe->sections as $section ) {
					$name  = self::resolve( $section->pattern, $section->layout );
					$rules = null === $name ? [] : ( self::intents()[ $name ]['copy_rules'] ?? [] );
					foreach ( get_object_vars( $section->copy_guidance ) as $slot => $line ) {
						if ( $claim === ( true === ( $rules[ $slot ]['claim'] ?? null ) ) ) {
							return (object) [
								'guidance' => $section->copy_guidance,
								'slot'     => (string) $slot,
							];
						}
					}
				}
			}
			self::fail( $claim ? 'no recipe gives guidance for a claim slot' : 'no recipe gives guidance for a slot' );
		};

		return [
			'an unknown layout'                       => [
				static function ( array $recipes ) use ( $section ): void {
					$section( $recipes, 'home', 0 )->layout = 'banner';
				},
				'home section 1 (voyager-open/hero): names no pattern',
			],
			'a layout on a section with one layout'   => [
				static function ( array $recipes ) use ( $section, $styled ): void {
					$cta         = $section( $recipes, 'home', $styled( $recipes, 'home', 'accent' ) );
					$cta->layout = 'wide';
				},
				'names no pattern',
			],
			'a layout named by its own pattern'       => [
				static function ( array $recipes ) use ( $section ): void {
					$hero          = $section( $recipes, 'home', 0 );
					$hero->pattern = 'voyager-open/hero-cover';
					$hero->layout  = null;
				},
				'names no pattern, since no intent in patterns/intents/ carries "voyager-open/hero-cover" with layout null',
			],
			'two adjacent accent sections'            => [
				static function ( array $recipes ) use ( $section, $styled ): void {
					$section( $recipes, 'home', $styled( $recipes, 'home', 'accent' ) + 1 )->style = 'accent';
				},
				'shares the style "accent" with the section before it',
			],
			'a strong section after a strong section' => [
				static function ( array $recipes ) use ( $section, $styled ): void {
					$section( $recipes, 'home', $styled( $recipes, 'home', 'accent' ) + 1 )->style = 'inverted';
				},
				'right after a strong section',
			],
			'an unknown style'                        => [
				static function ( array $recipes ) use ( $section ): void {
					$section( $recipes, 'home', 1 )->style = 'dark';
				},
				'home section 2 (voyager-open/proof-strip): style is not one of',
			],
			'guidance for an unnamed slot'            => [
				static function ( array $recipes ) use ( $section ): void {
					$section( $recipes, 'home', 0 )->copy_guidance->Subheading = 'A second line under the heading.';
				},
				'guidance for "Subheading", which is not a named text block of voyager-open/hero',
			],
			'guidance for a named block that is not text' => [
				static function ( array $recipes ) use ( $section ): void {
					$section( $recipes, 'home', 0 )->copy_guidance->Image = 'A photo of the work.';
				},
				'guidance for "Image", which is not a named text block',
			],
			'guidance for a bound slot'               => [
				static function ( array $recipes ) use ( $section ): void {
					$section( $recipes, 'home', 0 )->copy_guidance->{'Click to call'} = 'Call now.';
				},
				'guidance for "Click to call", whose text a binding supplies',
			],
			'claim guidance that names no source'     => [
				static function ( array $recipes ) use ( $guided ): void {
					$found                            = $guided( $recipes, true );
					$found->guidance->{$found->slot} = 'A licence or an award.';
				},
				'is a claim, so its guidance says where the fact comes from',
			],
			'guidance written as a list'              => [
				static function ( array $recipes ) use ( $section ): void {
					$section( $recipes, 'home', 0 )->copy_guidance = [];
				},
				'copy_guidance is not an object',
			],
			'empty guidance'                          => [
				static function ( array $recipes ) use ( $guided ): void {
					$found                            = $guided( $recipes, false );
					$found->guidance->{$found->slot} = ' ';
				},
				'is not a non-empty string',
			],
			'a second hero'                           => [
				static function ( array $recipes ) use ( $section ): void {
					$hero        = clone $section( $recipes, 'home', 0 );
					$hero->style = 'plain';
					array_splice( $recipes['home']->sections, 2, 0, [ $hero ] );
				},
				'home: holds 2 heroes',
			],
			'the hero not first'                      => [
				static function ( array $recipes ): void {
					$sections                    = $recipes['home']->sections;
					$recipes['home']->sections = array_merge( [ $sections[1], $sections[0] ], array_slice( $sections, 2 ) );
				},
				'home: the hero is section 2, and it must come first',
			],
			'no hero'                                 => [
				static function ( array $recipes ): void {
					array_shift( $recipes['home']->sections );
				},
				'home: holds 0 heroes',
			],
			'a missing key'                           => [
				static function ( array $recipes ): void {
					unset( $recipes['home']->description );
				},
				'home: missing key "description"',
			],
			'a section missing a key'                 => [
				static function ( array $recipes ) use ( $section ): void {
					unset( $section( $recipes, 'home', 1 )->style );
				},
				'home section 2 (voyager-open/proof-strip): missing key "style"',
			],
			'an unknown key'                          => [
				static function ( array $recipes ): void {
					$recipes['home']->order = 1;
				},
				'home: unknown key "order"',
			],
			'a page type named twice'                 => [
				static function ( array $recipes ): void {
					$recipes['about']->page_type = 'home';
				},
				'page_type "home" is',
			],
			'an unknown page type'                    => [
				static function ( array $recipes ): void {
					$recipes['about']->page_type = 'blog';
				},
				'about: page_type is not one of',
			],
		];
	}

	/**
	 * @param \Closure(array<string, mixed>): void $plant  Changes the recipes in place.
	 * @param string                               $expect A part of the problem it must cause.
	 */
	#[DataProvider( 'planted' )]
	public function test_a_planted_fault_is_caught( \Closure $plant, string $expect ): void {
		$recipes = self::recipes();
		$this->assertSame( [], self::problems( $recipes ), 'the shipped recipes must pass before a fault is planted' );

		$plant( $recipes );
		$problems = self::problems( $recipes );

		$this->assertNotSame( [], $problems, 'the planted fault went unnoticed' );
		$this->assertStringContainsString( $expect, implode( "\n", $problems ) );
	}
}
