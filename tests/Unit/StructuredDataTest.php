<?php
/**
 * The LocalBusiness node on the front page (inc/structured-data.php): what it
 * holds for full Site Data, what it leaves out, when it prints nothing, its
 * filter, and standing aside for each SEO plugin that prints the site's
 * business itself.
 *
 * Every test runs in its own process. Loading the module adds its filters,
 * and other tests empty the filter table in their setUp(), so each test loads
 * the module fresh where nothing has run before it.
 *
 * The stand-aside tests run each plugin's own path from its graph to the
 * page (tests/structured-data-stubs.php), with the site's node as the plugin
 * builds it. Each names the plugin, the version, the file and the function
 * the node and the path were read from.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\SiteData;
use VoyagerOpenBlocks\StructuredData;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class StructuredDataTest extends TestCase {

	private const HOME = 'https://example.com/';

	/** Every Site Data field the node reads, and the ones it must leave out. */
	private const FULL = [
		'voyager_site_phone'            => '(555) 555-0100',
		'voyager_site_email'            => 'hello@example.com',
		'voyager_site_address'          => '100 Main Street, Suite 200',
		'voyager_site_address_city'     => 'Springfield',
		'voyager_site_address_state'    => 'ST',
		'voyager_site_address_zip'      => '00000',
		'voyager_site_hours'            => 'Monday to Friday, 8am to 5pm',
		'voyager_site_social_facebook'  => 'https://facebook.com/example',
		'voyager_site_social_instagram' => 'https://instagram.com/example',
		'voyager_site_social_linkedin'  => 'https://www.linkedin.com/company/example',
		'voyager_site_social_x'         => 'https://x.com/example',
		'voyager_site_services'         => "Drain cleaning\nLeak detection",
		'voyager_site_service_area'     => "Springfield\r\n\r\n  Shelbyville  \nOgdenville",
		'voyager_site_credentials'      => "Licensed and insured\nMember, Example Trade Association",
		'voyager_site_founded'          => '1998',
		'voyager_site_license'          => 'PL-000123',
		'voyager_site_rating'           => '4.8',
		'voyager_site_review_count'     => '127',
		'voyager_site_cta_label'        => 'Book a visit',
		'voyager_site_cta_url'          => 'https://example.com/contact/',
	];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_filters']    = [];
		$GLOBALS['voyager_test_options']    = self::FULL;
		$GLOBALS['voyager_test_bloginfo']   = [ 'name' => 'Example Plumbing' ];
		$GLOBALS['voyager_test_front_page'] = true;
		$GLOBALS['voyager_test_scripts']    = [];
		require_once dirname( __DIR__ ) . '/structured-data-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/inc/structured-data.php';
	}

	/**
	 * The node json() prints, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function printed(): array {
		$json = StructuredData\json();
		$this->assertNotSame( '', $json, 'The pack printed nothing.' );
		$node = json_decode( $json, true );
		$this->assertIsArray( $node );
		/** @var array<string, mixed> $node */
		return $node;
	}

	/**
	 * Site Data with only these options set.
	 *
	 * @param array<string, string> $options Option name => value.
	 * @param string                $name    The Site Title.
	 * @return array<string, mixed> The node, or [].
	 */
	private function node_for( array $options, string $name = 'Example Plumbing' ): array {
		$GLOBALS['voyager_test_options']  = $options;
		$GLOBALS['voyager_test_bloginfo'] = [ 'name' => $name ];

		return StructuredData\node( SiteData\values(), self::HOME );
	}

	public function test_full_site_data_gives_every_property_in_order(): void {
		$this->assertSame(
			[
				'@context'     => 'https://schema.org',
				'@type'        => 'LocalBusiness',
				'@id'          => 'https://example.com/#localbusiness',
				'name'         => 'Example Plumbing',
				'url'          => self::HOME,
				'telephone'    => '(555) 555-0100',
				'email'        => 'hello@example.com',
				'address'      => [
					'@type'           => 'PostalAddress',
					'streetAddress'   => '100 Main Street, Suite 200',
					'addressLocality' => 'Springfield',
					'addressRegion'   => 'ST',
					'postalCode'      => '00000',
				],
				'sameAs'       => [
					'https://facebook.com/example',
					'https://instagram.com/example',
					'https://www.linkedin.com/company/example',
					'https://x.com/example',
				],
				'areaServed'   => [ 'Springfield', 'Shelbyville', 'Ogdenville' ],
				'foundingDate' => '1998',
			],
			$this->printed()
		);
	}

	public function test_the_hours_rating_credentials_and_licence_are_never_stated(): void {
		$encoded = StructuredData\json();

		foreach ( [ 'openingHours', 'openingHoursSpecification', 'aggregateRating', 'ratingValue', 'reviewCount', 'hasCredential', 'Monday', '4.8', '127', 'PL-000123', 'Licensed' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $encoded, "{$absent} is in the node." );
		}
	}

	public function test_empty_values_are_left_out(): void {
		$node = $this->node_for(
			[
				'voyager_site_phone'        => '(555) 555-0100',
				'voyager_site_address_city' => 'Springfield',
				'voyager_site_service_area' => "\n  \n",
				'voyager_site_founded'      => '98',
			]
		);

		$this->assertSame(
			[
				'@context'  => 'https://schema.org',
				'@type'     => 'LocalBusiness',
				'@id'       => 'https://example.com/#localbusiness',
				'name'      => 'Example Plumbing',
				'url'       => self::HOME,
				'telephone' => '(555) 555-0100',
				'address'   => [
					'@type'           => 'PostalAddress',
					'addressLocality' => 'Springfield',
				],
			],
			$node,
			'No email, no street, no social profiles, no area and no year: each is left out, and a two-digit year is no year.'
		);
	}

	public function test_a_social_link_that_is_not_a_web_address_is_left_out(): void {
		$node = $this->node_for(
			[
				'voyager_site_phone'           => '(555) 555-0100',
				'voyager_site_social_facebook' => 'mailto:hello@example.com',
				'voyager_site_social_x'        => 'x.com/example',
				'voyager_site_social_linkedin' => 'https://www.linkedin.com/company/example',
			]
		);

		$this->assertSame( [ 'https://www.linkedin.com/company/example' ], $node['sameAs'] ?? null );
	}

	public function test_an_email_that_is_not_an_address_is_left_out(): void {
		$node = $this->node_for(
			[
				'voyager_site_phone' => '(555) 555-0100',
				'voyager_site_email' => 'hello at example dot com',
			]
		);

		$this->assertArrayNotHasKey( 'email', $node );
		$this->assertSame( '(555) 555-0100', $node['telephone'] ?? null );
	}

	public function test_nothing_below_a_name_and_one_way_to_reach_the_business(): void {
		$this->assertSame( [], $this->node_for( [] ), 'A name alone is too thin.' );
		$this->assertSame( [], $this->node_for( [ 'voyager_site_phone' => '(555) 555-0100' ], '' ), 'No name.' );
		$this->assertSame( [], $this->node_for( [ 'voyager_site_phone' => '  ' ] ), 'A blank phone is no phone.' );
		$this->assertSame( [], $this->node_for( [ 'voyager_site_email' => 'not an address' ] ), 'An invalid email is no email.' );
		$this->assertSame(
			[],
			$this->node_for(
				[
					'voyager_site_address_city'     => 'Springfield',
					'voyager_site_address_state'    => 'ST',
					'voyager_site_address_zip'      => '00000',
					'voyager_site_social_facebook'  => 'https://facebook.com/example',
					'voyager_site_service_area'     => 'Springfield',
					'voyager_site_founded'          => '1998',
				]
			),
			'A city, a region, a postal code and the rest do not reach the business without a street.'
		);

		$this->assertSame( '(555) 555-0100', $this->node_for( [ 'voyager_site_phone' => '(555) 555-0100' ] )['telephone'] ?? null );
		$this->assertSame( 'hello@example.com', $this->node_for( [ 'voyager_site_email' => 'hello@example.com' ] )['email'] ?? null );
		$this->assertSame( '100 Main Street', $this->node_for( [ 'voyager_site_address' => '100 Main Street' ] )['address']['streetAddress'] ?? null );

		$GLOBALS['voyager_test_options'] = [];
		$this->assertSame( '', StructuredData\json(), 'json() prints nothing below the minimum.' );
	}

	public function test_the_site_title_is_decoded_once(): void {
		$node = $this->node_for( [ 'voyager_site_phone' => '(555) 555-0100' ], 'Joe&#039;s &amp; Co' );

		$this->assertSame( "Joe's & Co", $node['name'] ?? null );
	}

	public function test_no_value_can_close_the_script_tag(): void {
		$GLOBALS['voyager_test_bloginfo'] = [ 'name' => '</script><script>alert(1)</script>' ];

		$json = StructuredData\json();

		$this->assertStringNotContainsString( '<', $json );
		$this->assertStringNotContainsString( '>', $json );
		$this->assertSame( '</script><script>alert(1)</script>', json_decode( $json, true )['name'] ?? null, 'It still decodes to the name.' );
	}

	public function test_only_the_front_page(): void {
		$GLOBALS['voyager_test_front_page'] = false;

		$this->assertSame( '', StructuredData\json() );
		StructuredData\render();
		$this->assertSame( [], $GLOBALS['voyager_test_scripts'] );
	}

	public function test_render_prints_one_ld_json_script_with_the_pack_class(): void {
		StructuredData\render();

		$this->assertCount( 1, $GLOBALS['voyager_test_scripts'] );
		[ $data, $attributes ] = $GLOBALS['voyager_test_scripts'][0];
		$this->assertSame( [ 'type' => 'application/ld+json', 'class' => 'voyager-open-blocks-structured-data' ], $attributes );
		$this->assertSame( 'LocalBusiness', json_decode( $data, true )['@type'] ?? null );
		$this->assertStringContainsString( '"url":"https://example.com/"', $data, 'Slashes are not escaped.' );
	}

	public function test_the_filter_sets_a_subtype_and_gets_the_site_data(): void {
		$seen = null;
		add_filter(
			'voyager_open_blocks_structured_data',
			static function ( array $node, array $values ) use ( &$seen ): array {
				$seen          = $values['phone'] ?? null;
				$node['@type'] = 'Plumber';
				return $node;
			}
		);

		$this->assertSame( 'Plumber', $this->printed()['@type'] );
		$this->assertSame( '(555) 555-0100', $seen, 'The filter gets the Site Data values second.' );
	}

	public function test_the_filter_turns_the_output_off(): void {
		add_filter( 'voyager_open_blocks_structured_data', static fn (): array => [] );
		$this->assertSame( '', StructuredData\json(), '[] prints nothing.' );

		$GLOBALS['voyager_test_filters'] = [];
		add_filter( 'voyager_open_blocks_structured_data', static fn (): string => 'not a node' );
		$this->assertSame( '', StructuredData\json(), 'Anything but an array prints nothing.' );
	}

	public function test_the_filter_is_not_asked_below_the_minimum(): void {
		$GLOBALS['voyager_test_options'] = [];
		$asked                           = false;
		add_filter(
			'voyager_open_blocks_structured_data',
			static function ( mixed $node ) use ( &$asked ): mixed {
				$asked = true;
				return $node;
			}
		);

		$this->assertSame( '', StructuredData\json() );
		$this->assertFalse( $asked );
	}

	public function test_loading_the_module_hooks_each_plugin_filter_at_the_last_priority(): void {
		$hooks = [
			'rank_math/schema/validated_data' => 'VoyagerOpenBlocks\StructuredData\observe_rank_math',
			'wpseo_schema_graph'              => 'VoyagerOpenBlocks\StructuredData\observe_yoast',
			'aioseo_schema_output'            => 'VoyagerOpenBlocks\StructuredData\observe_aioseo',
		];

		foreach ( $hooks as $hook => $callback ) {
			$this->assertSame( [ PHP_INT_MAX => [ $callback ] ], $GLOBALS['voyager_test_filters'][ $hook ] ?? null, $hook );
		}
	}

	/**
	 * Rank Math 1.0.282 with the Local SEO module and the knowledge graph set
	 * to a local business: Local_Seo::organization() in
	 * includes/modules/local-seo/class-local-seo.php types the `publisher`
	 * entity with the business type and gives it the @id
	 * home_url( '/#organization' ); on the front page, which is not singular,
	 * the type is that business type alone. It reaches the page through
	 * JsonLD::json_ld() in includes/modules/schema/class-jsonld.php.
	 */
	public function test_it_stands_aside_for_rank_math_local_seo(): void {
		$printed = voyager_test_rank_math_prints(
			[
				'publisher' => [
					'@type' => 'Plumber',
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Plumbing',
					'url'   => 'https://example.com',
				],
				'WebSite'   => [ '@type' => 'WebSite', '@id' => 'https://example.com/#website' ],
			]
		);

		$this->assertNotNull( $printed, 'Rank Math 1.0.282 prints its graph (JsonLD::json_ld(), includes/modules/schema/class-jsonld.php).' );
		$this->assertSame( [ 'Rank Math' ], StructuredData\printed_by() );
		$this->assertSame( '', StructuredData\json(), 'The pack stands aside for Rank Math 1.0.282 Local SEO (Local_Seo::organization(), includes/modules/local-seo/class-local-seo.php).' );
	}

	/**
	 * Rank Math 1.0.282 with the knowledge graph set to a company and no Local
	 * SEO module: Publisher::process() in
	 * includes/modules/schema/snippets/class-publisher.php adds `publisher`,
	 * an Organization.
	 */
	public function test_it_stands_aside_for_the_rank_math_organization(): void {
		voyager_test_rank_math_prints(
			[
				'publisher' => [
					'@type' => 'Organization',
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Plumbing',
				],
			]
		);

		$this->assertSame( '', StructuredData\json(), 'Rank Math 1.0.282 Publisher::process(), includes/modules/schema/snippets/class-publisher.php.' );
	}

	/**
	 * Rank Math 1.0.282 with the knowledge graph set to a person, its default
	 * without WooCommerce (Installer::create_titles_sitemaps_options() in
	 * includes/class-installer.php): Publisher::process() makes `publisher` a
	 * Person off singular pages, so it states no business, and the pack prints.
	 */
	public function test_it_prints_beside_a_rank_math_person(): void {
		voyager_test_rank_math_prints(
			[
				'publisher' => [
					'@type' => 'Person',
					'@id'   => 'https://example.com/#person',
					'name'  => 'Example Person',
				],
			]
		);

		$this->assertSame( [], StructuredData\printed_by() );
		$this->assertSame( 'LocalBusiness', $this->printed()['@type'], 'Rank Math 1.0.282 Publisher::process(), includes/modules/schema/snippets/class-publisher.php.' );
	}

	/**
	 * Rank Math 1.0.282 before its setup: init_frontend() in rank-math.php
	 * builds no front end while the Registration class finds
	 * Helper::is_invalid_registration()
	 * (includes/helpers/class-conditional.php) is true, so `rank_math/head`
	 * never runs and no Rank Math filter is called. Installed is not printed.
	 */
	public function test_it_prints_when_rank_math_printed_nothing(): void {
		$this->assertNull( voyager_test_rank_math_prints( [] ), 'JsonLD::json_ld() returns before validated_data when it collected nothing.' );

		$this->assertSame( 'LocalBusiness', $this->printed()['@type'] );
	}

	/**
	 * Yoast SEO 28.8 for a company with a name and a logo:
	 * Organization::generate() in src/generators/schema/organization.php, an
	 * Organization with the @id `#organization`, passed through
	 * `wpseo_schema_graph` by Schema_Generator::generate_graph() in
	 * src/generators/schema-generator.php.
	 */
	public function test_it_stands_aside_for_the_yoast_organization(): void {
		voyager_test_yoast_prints(
			[
				[ '@type' => 'WebPage', '@id' => 'https://example.com/' ],
				[ '@type' => 'WebSite', '@id' => 'https://example.com/#website' ],
				[
					'@type' => 'Organization',
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Plumbing',
				],
			]
		);

		$this->assertSame( [ 'Yoast SEO' ], StructuredData\printed_by() );
		$this->assertSame( '', StructuredData\json(), 'Yoast SEO 28.8 Organization::generate(), src/generators/schema/organization.php.' );
	}

	/**
	 * Yoast Local SEO, which is not on WordPress.org, so this is its shape as
	 * Yoast's developer documentation gives it ("Yoast Local SEO for
	 * WordPress: Schema output"): the primary location merged into the
	 * Organization piece, typed [ 'Organization', 'Place', 'Dentist' ]. It
	 * reaches the page through Yoast SEO 28.8's `wpseo_schema_graph`
	 * (Schema_Generator::generate_graph(), src/generators/schema-generator.php).
	 */
	public function test_it_stands_aside_for_yoast_local_seo(): void {
		voyager_test_yoast_prints(
			[
				[
					'@type'   => [ 'Organization', 'Place', 'Dentist' ],
					'@id'     => 'https://example.com/#/schema/organization/1',
					'name'    => 'Example Dental',
					'address' => [ '@id' => 'https://example.com/#/schema/address/abc123' ],
				],
				[ '@type' => 'PostalAddress', '@id' => 'https://example.com/#/schema/address/abc123' ],
			]
		);

		$this->assertSame( '', StructuredData\json(), 'Yoast Local SEO, through Yoast SEO 28.8 Schema_Generator::generate_graph(), src/generators/schema-generator.php.' );
	}

	/**
	 * Yoast SEO 28.8 for a person: Person in src/generators/schema/person.php
	 * types the site [ 'Person', 'Organization' ], so it states an
	 * Organization and the pack stands aside.
	 */
	public function test_it_stands_aside_for_the_yoast_person_typed_organization(): void {
		voyager_test_yoast_prints(
			[
				[
					'@type' => [ 'Person', 'Organization' ],
					'@id'   => 'https://example.com/#/schema/person/abc123',
					'name'  => 'Example Person',
				],
			]
		);

		$this->assertSame( '', StructuredData\json(), 'Yoast SEO 28.8 Person, src/generators/schema/person.php.' );
	}

	/**
	 * Yoast SEO 28.8 for a company without a logo:
	 * Meta_Tags_Context::generate_site_represents() in
	 * src/context/meta-tags-context.php returns false, so Organization::is_needed()
	 * is false and the graph has no Organization. The pack prints.
	 */
	public function test_it_prints_beside_a_yoast_graph_with_no_organization(): void {
		$printed = voyager_test_yoast_prints(
			[
				[ '@type' => 'WebPage', '@id' => 'https://example.com/' ],
				[ '@type' => 'WebSite', '@id' => 'https://example.com/#website', 'publisher' => [ '@id' => 'https://example.com/#organization' ] ],
			]
		);

		$this->assertNotNull( $printed );
		$this->assertSame( 'LocalBusiness', $this->printed()['@type'], 'A reference to #organization is not a node: Yoast SEO 28.8 Website, src/generators/schema/website.php.' );
	}

	/**
	 * Yoast SEO 28.8 with its schema turned off: Schema_Presenter::present()
	 * in src/presenters/schema-presenter.php returns '' when
	 * `wpseo_json_ld_output` gives false, before the graph is built, so
	 * `wpseo_schema_graph` never runs. The pack prints.
	 */
	public function test_it_prints_when_yoast_schema_is_off(): void {
		add_filter( 'wpseo_json_ld_output', static fn (): bool => false );

		$this->assertNull(
			voyager_test_yoast_prints(
				[ [ '@type' => 'Organization', '@id' => 'https://example.com/#organization' ] ]
			)
		);
		$this->assertSame( 'LocalBusiness', $this->printed()['@type'] );
	}

	/**
	 * All in One SEO 5.0.5.1 as installed: siteRepresents defaults to
	 * organization (app/Common/Options/Options.php), so KgOrganization::get()
	 * in app/Common/Schema/Graphs/KnowledgeGraph/KgOrganization.php adds an
	 * Organization with the @id `#organization`, passed through
	 * `aioseo_schema_output` by Helpers::getOutput() in
	 * app/Common/Schema/Helpers.php.
	 */
	public function test_it_stands_aside_for_the_aioseo_organization(): void {
		voyager_test_aioseo_prints(
			[
				[ '@type' => 'BreadcrumbList', '@id' => 'https://example.com/#breadcrumblist' ],
				[
					'@type' => 'Organization',
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Plumbing',
				],
				[ '@type' => 'WebSite', '@id' => 'https://example.com/#website' ],
			]
		);

		$this->assertSame( [ 'All in One SEO' ], StructuredData\printed_by() );
		$this->assertSame( '', StructuredData\json(), 'All in One SEO 5.0.5.1 KgOrganization::get(), app/Common/Schema/Graphs/KnowledgeGraph/KgOrganization.php.' );
	}

	/**
	 * All in One SEO 5.0.5.1 set to a person: KgPerson::get() in
	 * app/Common/Schema/Graphs/KnowledgeGraph/KgPerson.php, a Person. The
	 * pack prints.
	 */
	public function test_it_prints_beside_an_aioseo_person(): void {
		voyager_test_aioseo_prints(
			[
				[ '@type' => 'Person', '@id' => 'https://example.com/#person', 'name' => 'Example Person' ],
				[ '@type' => 'WebSite', '@id' => 'https://example.com/#website' ],
			]
		);

		$this->assertSame( 'LocalBusiness', $this->printed()['@type'], 'All in One SEO 5.0.5.1 KgPerson::get().' );
	}

	/**
	 * All in One SEO 5.0.5.1 with its schema off: Schema::get() in
	 * app/Common/Schema/Schema.php returns '' when Helpers::isEnabled() is
	 * false, which `aioseo_schema_disable` decides, before any graph is built.
	 */
	public function test_it_prints_when_aioseo_schema_is_off(): void {
		add_filter( 'aioseo_schema_disable', static fn (): bool => true );

		$this->assertNull( voyager_test_aioseo_prints( [ [ '@type' => 'Organization', '@id' => 'https://example.com/#organization' ] ] ) );
		$this->assertSame( 'LocalBusiness', $this->printed()['@type'] );
	}

	public function test_a_node_another_callback_adds_after_the_plugin_is_still_seen(): void {
		add_filter(
			'aioseo_schema_output',
			static function ( array $graph ): array {
				$graph[] = [ '@type' => 'LocalBusiness', 'name' => 'Added by a theme' ];
				return $graph;
			},
			1000
		);

		voyager_test_aioseo_prints( [ [ '@type' => 'WebSite' ] ] );

		$this->assertSame( '', StructuredData\json(), 'The observer runs at PHP_INT_MAX, after the theme\'s callback.' );
	}

	public function test_what_counts_as_the_site_business(): void {
		$this->assertTrue( StructuredData\is_site_entity( [ '@type' => 'https://schema.org/Organization' ] ), 'A full IRI.' );
		$this->assertTrue( StructuredData\is_site_entity( [ '@type' => 'schema:LocalBusiness' ] ), 'A prefixed name.' );
		$this->assertTrue( StructuredData\is_site_entity( [ '@type' => 'HomeAndConstructionBusiness', '@id' => 'https://example.com/#organization' ] ), 'A business type, by its @id.' );
		$this->assertFalse( StructuredData\is_site_entity( [ '@type' => 'WebSite', 'publisher' => [ '@type' => 'Organization' ] ] ), 'An Organization nested in another node is not a node of the graph.' );
		$this->assertFalse( StructuredData\is_site_entity( [ '@type' => 'OrganizationRole' ] ), 'A type that starts with Organization is not Organization.' );
		$this->assertFalse( StructuredData\is_site_entity( [ '@id' => 'https://example.com/#organization-logo' ] ) );
		$this->assertFalse( StructuredData\is_site_entity( 'Organization' ) );
		$this->assertFalse( StructuredData\holds_site_entity( 'not a graph' ) );
		$this->assertTrue( StructuredData\holds_site_entity( [ '@graph' => [ [ '@type' => 'Organization' ] ] ] ), 'A graph wrapped in @graph.' );
	}
}
