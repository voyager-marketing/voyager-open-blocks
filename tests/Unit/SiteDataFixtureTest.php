<?php
/**
 * Shared site-data key fixture.
 *
 * tests/fixtures/site-data-keys.json pins what each of the 18 voyager/site-data
 * keys resolves to for a set of untidy option values. voyager-blocks runs a
 * byte-identical copy. Options go in as stored, the way
 * scripts/site-data-fixture-check.mjs writes them with the sanitize filters
 * removed, so the fixture exercises values() and not the settings sanitizers.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\SiteData;

final class SiteDataFixtureTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../fixtures/site-data-keys.json';
	private const PIN     = __DIR__ . '/../fixtures/site-data-keys.sha256';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']  = [];
		$GLOBALS['voyager_test_bloginfo'] = [];
	}

	/**
	 * @return array{options: array<string, string>, expected: array<string, string>}
	 */
	private function load_fixture(): array {
		/** @var array{options: array<string, string>, expected: array<string, string>} $fixture */
		$fixture = json_decode( (string) file_get_contents( self::FIXTURE ), true, 512, JSON_THROW_ON_ERROR );

		return $fixture;
	}

	public function test_every_key_resolves_to_the_fixture_value(): void {
		$fixture = $this->load_fixture();

		// blogname and blogdescription are bloginfo(), the rest are options.
		$GLOBALS['voyager_test_bloginfo'] = [
			'name'        => $fixture['options']['blogname'],
			'description' => $fixture['options']['blogdescription'],
		];
		foreach ( $fixture['options'] as $name => $value ) {
			if ( 'blogname' !== $name && 'blogdescription' !== $name ) {
				$GLOBALS['voyager_test_options'][ $name ] = $value;
			}
		}

		// The hash test pins the key set. This stops a short fixture from passing
		// with fewer than 18 checks.
		$this->assertCount( 18, $fixture['expected'] );

		$year = gmdate( 'Y' );
		$data = SiteData\values();

		foreach ( $fixture['expected'] as $key => $expected ) {
			$this->assertSame(
				str_replace( '{{year}}', $year, $expected ),
				$data[ $key ] ?? null,
				"voyager/site-data key '{$key}' differs from the shared fixture."
			);
		}
	}

	public function test_values_returns_exactly_the_keys_of_fields(): void {
		$this->assertSame( array_keys( SiteData\fields() ), array_keys( SiteData\values() ) );
	}

	public function test_fixture_matches_its_pinned_hash(): void {
		// sha256sum format: "<64 hex>  <name>".
		preg_match( '/^[0-9a-f]{64}/', (string) file_get_contents( self::PIN ), $m );

		$this->assertSame( $m[0] ?? '', hash_file( 'sha256', self::FIXTURE ) );
	}
}
