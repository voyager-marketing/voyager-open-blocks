<?php
/**
 * The arrays `npm run patterns:build` generates in inc/generated/ say what the
 * files they are built from say.
 *
 * inc/patterns.php and inc/icons.php read the arrays and no file, so each
 * reader must give what it gave when it read the files: register() registers
 * the patterns the intents and their markup describe, in the order glob()
 * lists the intents; bound_keys() and intent_meta() give what they give for
 * the intents folder; and Icons\all() is the icon map in assets/icons.json.
 * The expected values here are read from the files the way inc/patterns.php
 * and inc/icons.php read them before the arrays existed.
 *
 * Each generated file must also return a literal array, with no expression in
 * it, so OPcache can keep it in memory as it is.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Cast;
use VoyagerOpenBlocks\Icons;
use VoyagerOpenBlocks\Patterns;
use VoyagerOpenBlocks\PostMetaText;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/patterns-stubs.php';
require_once dirname( __DIR__ ) . '/icon-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/icons.php';

final class GeneratedArraysTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	private const INTENTS = self::ROOT . '/patterns/intents';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_block_patterns']           = [];
		$GLOBALS['voyager_test_block_pattern_categories'] = [];
	}

	/**
	 * The intent files, in the order glob() lists them.
	 *
	 * @return list<string>
	 */
	private static function intentFiles(): array {
		return glob( self::INTENTS . '/*.json' ) ?: [];
	}

	/**
	 * A field's string entries, as a list.
	 *
	 * @param mixed $value A string or a list of strings from the intent file.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return array_values( array_filter( (array) $value, 'is_string' ) );
	}

	/**
	 * What register() registered when it read the files: each intent and
	 * its markup, in glob() order, with the assets URL swapped in.
	 *
	 * @return array<string, array<string, mixed>> Pattern name => args.
	 */
	private static function registeredFromFiles(): array {
		$assets = plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE );
		$out    = [];

		foreach ( self::intentFiles() as $intent_file ) {
			$name   = basename( $intent_file, '.json' );
			$markup = self::ROOT . '/patterns/' . $name . '.html';
			$meta   = json_decode( (string) file_get_contents( $intent_file ), true );

			if ( ! is_array( $meta ) || ! is_readable( $markup ) || empty( $meta['title'] ) ) {
				continue;
			}

			$out[ 'voyager-open/' . $name ] = [
				'title'         => Cast\to_string( $meta['title'] ),
				'description'   => Cast\to_string( $meta['description'] ?? '' ),
				'content'       => str_replace( Patterns\ASSETS_TOKEN, $assets, (string) file_get_contents( $markup ) ),
				'categories'    => self::strings( $meta['categories'] ?? [ 'voyager-open' ] ),
				'keywords'      => self::strings( $meta['keywords'] ?? [] ),
				'blockTypes'    => self::strings( $meta['blockTypes'] ?? [] ),
				'viewportWidth' => Cast\to_int( $meta['viewportWidth'] ?? 1280 ),
			];
		}

		return $out;
	}

	public function test_register_registers_each_pattern_as_its_intent_and_markup_files_give_it(): void {
		$expected = self::registeredFromFiles();

		Patterns\register();

		$this->assertCount( count( self::intentFiles() ), $expected, 'Every intent has its markup.' );
		$this->assertNotSame( [], $expected );
		$this->assertSame( $expected, $GLOBALS['voyager_test_block_patterns'], 'The same patterns, args and order as reading the files gave.' );
		$this->assertArrayHasKey( 'voyager-open', $GLOBALS['voyager_test_block_pattern_categories'] );
	}

	public function test_the_array_keeps_the_assets_token_and_register_swaps_it_in(): void {
		$kept = array_filter(
			Patterns\generated(),
			static fn ( array $pattern ): bool => str_contains( $pattern['args']['content'], Patterns\ASSETS_TOKEN )
		);

		Patterns\register();

		$this->assertNotSame( [], $kept, 'No generated pattern holds the assets token, so this proves nothing.' );
		foreach ( array_keys( $kept ) as $name ) {
			$content = $GLOBALS['voyager_test_block_patterns'][ $name ]['content'] ?? '';
			$this->assertIsString( $content );
			$this->assertStringNotContainsString( Patterns\ASSETS_TOKEN, $content, $name );
			$this->assertStringContainsString( plugins_url( 'assets/', VOYAGER_OPEN_BLOCKS_FILE ), $content, $name );
		}
	}

	/**
	 * Every binding source an intent binds through, read from the intent
	 * files, so a pattern that binds a new source is compared too, and the
	 * pack's two sources and core's post meta, whether a pattern binds them or
	 * not, as controls that the comparison holds for a source no pattern binds.
	 *
	 * @return array<string, array{string}>
	 */
	public static function sources(): array {
		$sources = [];

		foreach ( self::intentFiles() as $intent_file ) {
			array_push( $sources, ...self::sources_in( json_decode( (string) file_get_contents( $intent_file ), true ) ) );
		}

		$cases = [];
		foreach ( array_unique( [ ...$sources, SiteData\SOURCE, PostMetaText\SOURCE, 'core/post-meta' ] ) as $source ) {
			$cases[ $source ] = [ $source ];
		}

		return $cases;
	}

	/**
	 * The sources of every binding in an intent, at any depth: each object
	 * with a string `source` beside an `args` object, the shape keys_in() reads.
	 *
	 * @param mixed $node A decoded intent file, or any part of one.
	 * @return list<string>
	 */
	private static function sources_in( mixed $node ): array {
		if ( ! is_array( $node ) ) {
			return [];
		}

		$found = isset( $node['source'], $node['args'] ) && is_string( $node['source'] ) && is_array( $node['args'] ) ? [ $node['source'] ] : [];

		foreach ( $node as $child ) {
			array_push( $found, ...self::sources_in( $child ) );
		}

		return $found;
	}

	public function test_the_sources_are_read_from_the_intents(): void {
		$read = [];
		foreach ( self::intentFiles() as $intent_file ) {
			array_push( $read, ...self::sources_in( json_decode( (string) file_get_contents( $intent_file ), true ) ) );
		}

		$this->assertContains( SiteData\SOURCE, $read, 'no intent binds voyager/site-data, so the read finds nothing' );
		$this->assertSame( [], array_values( array_diff( array_unique( $read ), array_keys( self::sources() ) ) ), 'a source an intent binds is not compared' );
		$this->assertSame( [ 'voyager/test-source' ], self::sources_in( [ 'blocks' => [ [ 'attrs' => [ 'metadata' => [ 'bindings' => [ 'content' => [ 'source' => 'voyager/test-source', 'args' => [ 'key' => 'x' ] ] ] ] ] ] ] ] ), 'the read finds a source at any depth' );
	}

	#[DataProvider( 'sources' )]
	public function test_bound_keys_gives_what_the_intents_folder_gives( string $source ): void {
		$this->assertSame( Patterns\bound_keys( $source, self::INTENTS ), Patterns\bound_keys( $source ) );
	}

	public function test_bound_keys_finds_the_site_data_keys(): void {
		$this->assertNotSame( [], Patterns\bound_keys( SiteData\SOURCE ), 'No pattern binds voyager/site-data, so the comparison above proves nothing.' );
	}

	public function test_intent_meta_gives_what_each_intent_file_gives(): void {
		$sections = 0;
		$rules    = 0;

		foreach ( self::intentFiles() as $intent_file ) {
			$name = 'voyager-open/' . basename( $intent_file, '.json' );
			$meta = Patterns\intent_meta( $name );

			$this->assertSame( Patterns\intent_meta( $name, self::INTENTS ), $meta, $name );
			$sections += null === $meta['section'] ? 0 : 1;
			$rules    += [] === $meta['copy_rules'] ? 0 : 1;
		}

		$this->assertGreaterThan( 0, $sections, 'No intent has a section, so the comparison proves less than it says.' );
		$this->assertGreaterThan( 0, $rules, 'No intent has copy rules, so the comparison proves less than it says.' );
	}

	public function test_intent_meta_gives_nothing_for_a_name_the_pack_does_not_ship(): void {
		$none = [
			'section'    => null,
			'layout'     => null,
			'copy_rules' => [],
		];

		foreach ( [ 'voyager-open/../composer', 'core/hero', 'voyager-open/no-such-section', 'hero', '' ] as $name ) {
			$this->assertSame( $none, Patterns\intent_meta( $name ), $name );
		}
	}

	public function test_icons_are_the_icon_map_in_assets_icons_json(): void {
		$json     = json_decode( (string) file_get_contents( self::ROOT . '/assets/icons.json' ), true );
		$list     = is_array( $json ) ? ( $json['icons'] ?? null ) : null;
		$expected = [];

		foreach ( is_array( $list ) ? $list : [] as $name => $path ) {
			if ( is_string( $path ) ) {
				$expected[ (string) $name ] = $path;
			}
		}

		$this->assertNotSame( [], $expected );
		$this->assertSame( $expected, Icons\all() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function generatedFiles(): array {
		return [
			'patterns' => [ 'inc/generated/patterns.php' ],
			'icons'    => [ 'inc/generated/icons.php' ],
		];
	}

	/**
	 * After `return`, nothing but array syntax and literals: no constant, no
	 * call, no operator but a minus sign, no variable.
	 */
	#[DataProvider( 'generatedFiles' )]
	public function test_each_generated_file_returns_a_literal_array( string $file ): void {
		$tokens    = token_get_all( (string) file_get_contents( self::ROOT . '/' . $file ) );
		$returned  = false;
		$after     = [];
		$allowed   = [ T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW ];
		$literals  = [ 'true', 'false', 'null' ];
		$syntax    = [ '[', ']', ',', '-', ';' ];
		$offending = [];

		foreach ( $tokens as $token ) {
			if ( ! $returned ) {
				$returned = is_array( $token ) && T_RETURN === $token[0];
				continue;
			}
			$after[] = $token;
		}

		foreach ( $after as $token ) {
			if ( is_string( $token ) ? in_array( $token, $syntax, true ) : ( in_array( $token[0], $allowed, true ) || ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), $literals, true ) ) ) ) {
				continue;
			}
			$offending[] = is_string( $token ) ? $token : token_name( $token[0] ) . ' ' . $token[1];
		}

		$this->assertTrue( $returned, "{$file} has no return." );
		$this->assertGreaterThan( 2, count( $after ) );
		$this->assertSame( '[', is_string( $after[1] ?? null ) ? $after[1] : ( $after[1][1] ?? '' ), "{$file} does not return an array." );
		$this->assertSame( [], $offending, "{$file} returns more than literals." );
	}
}
