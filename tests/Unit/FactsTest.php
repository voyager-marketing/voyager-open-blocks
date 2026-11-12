<?php
/**
 * voyager-open/facts: its render file, the items rules, the bindable
 * attribute and the Ticks style.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\BlockStyles;
use VoyagerOpenBlocks\Facts;
use VoyagerOpenBlocks\SiteData;

require_once dirname( __DIR__ ) . '/facts-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/facts.php';
require_once dirname( __DIR__, 2 ) . '/inc/block-styles.php';

final class FactsTest extends TestCase {

	private const WRAPPER = 'class="wp-block-voyager-open-facts"';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_options']            = [];
		$GLOBALS['voyager_test_filters']            = [];
		$GLOBALS['voyager_test_block_styles']       = [];
		$GLOBALS['voyager_test_wrapper_attributes'] = self::WRAPPER;
		add_filter( 'block_bindings_supported_attributes_voyager-open/facts', 'VoyagerOpenBlocks\Facts\bindable_attributes' );
	}

	/**
	 * What src/facts/render.php prints for these attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes, bindings already applied.
	 */
	private function render( array $attributes ): string {
		ob_start();
		( static function ( array $attributes ): void {
			$content = '';
			$block   = null;
			require dirname( __DIR__, 2 ) . '/src/facts/render.php';
		} )( $attributes );

		return (string) ob_get_clean();
	}

	/**
	 * @param list<string> $items Escaped items.
	 */
	private function listOf( array $items ): string {
		return '<ul ' . self::WRAPPER . '><li>' . implode( '</li><li>', $items ) . '</li></ul>';
	}

	public function test_it_prints_one_li_per_line_in_order_inside_the_wrapper(): void {
		$this->assertSame(
			$this->listOf( [ 'Drain cleaning', 'Water heater repair', 'Leak detection' ] ),
			$this->render( [ 'items' => "Drain cleaning\nWater heater repair\nLeak detection" ] )
		);
	}

	public function test_it_carries_the_wrapper_attributes(): void {
		$GLOBALS['voyager_test_wrapper_attributes'] = 'class="wp-block-voyager-open-facts is-style-voyager-open-ticks" id="facts"';

		$this->assertSame(
			'<ul class="wp-block-voyager-open-facts is-style-voyager-open-ticks" id="facts"><li>One</li></ul>',
			$this->render( [ 'items' => 'One' ] )
		);
	}

	public function test_it_splits_on_every_line_break_and_trims_each_line(): void {
		$this->assertSame(
			$this->listOf( [ 'One', 'Two', 'Three', 'Four' ] ),
			$this->render( [ 'items' => "  One  \r\nTwo\t\rThree\n \tFour" ] )
		);
	}

	public function test_it_drops_empty_and_blank_lines(): void {
		$this->assertSame(
			$this->listOf( [ 'One', 'Two' ] ),
			$this->render( [ 'items' => "\n\nOne\n   \n\r\n\t\nTwo\n\n" ] )
		);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function no_items(): array {
		return [
			'no attribute'        => [ [] ],
			'empty string'        => [ [ 'items' => '' ] ],
			'only line breaks'    => [ [ 'items' => "\n\r\n\r" ] ],
			'only whitespace'     => [ [ 'items' => "  \n\t \n " ] ],
			'not a string'        => [ [ 'items' => [ 'One', 'Two' ] ] ],
			'null from a binding' => [ [ 'items' => null ] ],
		];
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	#[DataProvider( 'no_items' )]
	public function test_it_prints_nothing_with_no_items( array $attributes ): void {
		$this->assertSame( '', $this->render( $attributes ) );
	}

	public function test_it_escapes_each_item(): void {
		$this->assertSame(
			$this->listOf( [ '&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;b&gt;Bold&lt;/b&gt; &quot;quoted&quot;', 'Joe&#039;s plumbing' ] ),
			$this->render( [ 'items' => "<script>alert(1)</script>\n<b>Bold</b> \"quoted\"\nJoe's plumbing" ] )
		);
	}

	public function test_an_item_cannot_close_the_list(): void {
		$html = $this->render( [ 'items' => "One</li></ul><img src=x onerror=alert(1)>\nTwo" ] );

		$this->assertSame( 1, substr_count( $html, '</ul>' ) );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/**
	 * The same stored list, as each plugin's voyager/site-data hands it over.
	 *
	 * This plugin returns the value raw. voyager-blocks returns it through
	 * esc_html(), so its ampersands and quotes arrive as entities.
	 */
	public function test_an_ampersand_prints_once_whichever_plugin_owns_the_source(): void {
		$stored = "Heating & cooling\nJoe's plumbing\nR&D <labs>";
		$GLOBALS['voyager_test_options']['voyager_site_services'] = $stored;

		$raw     = SiteData\get_binding_value( [ 'key' => 'services' ], null, 'items' );
		$escaped = esc_html( SiteData\resolve_list( $stored ) );

		$this->assertSame( "Heating & cooling\nJoe's plumbing\nR&D <labs>", $raw );
		$this->assertSame( "Heating &amp; cooling\nJoe&#039;s plumbing\nR&amp;D &lt;labs&gt;", $escaped );

		$expected = $this->listOf( [ 'Heating &amp; cooling', 'Joe&#039;s plumbing', 'R&amp;D &lt;labs&gt;' ] );

		$this->assertSame( $expected, $this->render( [ 'items' => $raw ] ), 'raw, as this plugin returns it' );
		$this->assertSame( $expected, $this->render( [ 'items' => $escaped ] ), 'through esc_html(), as voyager-blocks returns it' );
	}

	public function test_items_matches_the_site_data_list_rules(): void {
		$value = "  Drain cleaning \r\n\r\nLeak detection\r Water heater repair \n";

		$this->assertSame( [ 'Drain cleaning', 'Leak detection', 'Water heater repair' ], Facts\items( $value ) );
		$this->assertSame( SiteData\resolve_list( $value ), implode( "\n", Facts\items( $value ) ) );
	}

	public function test_markup_is_empty_with_no_items(): void {
		$this->assertSame( '', Facts\markup( [], self::WRAPPER ) );
	}

	public function test_items_is_bindable(): void {
		$this->assertSame( [ 'items' ], apply_filters( 'block_bindings_supported_attributes_voyager-open/facts', [] ) );
		$this->assertSame( [ 'items' ], Facts\bindable_attributes( [ 'items' ] ) );
		$this->assertSame( [ 'content', 'items' ], Facts\bindable_attributes( [ 'content' ] ) );
	}

	public function test_ticks_registers_for_core_list_and_the_facts_block(): void {
		BlockStyles\register();

		foreach ( [ 'core/list' => '.wp-block-list', 'voyager-open/facts' => '.wp-block-voyager-open-facts' ] as $block => $list ) {
			$styles = $GLOBALS['voyager_test_block_styles'][ $block ] ?? [];
			$ticks  = array_values( array_filter( $styles, static fn ( array $style ): bool => 'voyager-open-ticks' === ( $style['name'] ?? '' ) ) );

			$this->assertCount( 1, $ticks, $block );
			$this->assertSame( 'Ticks', $ticks[0]['label'] ?? null, $block );
			$this->assertSame( BlockStyles\ticks_css( $list ), $ticks[0]['inline_style'] ?? null, $block );
		}
	}

	public function test_ticks_css_for_core_list_is_unchanged(): void {
		$tick = rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.75 6 6 9-13.5"/></svg>' );

		$this->assertSame(
			'.wp-block-list.is-style-voyager-open-ticks{list-style:none;padding-inline-start:0;display:flex;flex-wrap:wrap;gap:.75em 2em}'
				. '.wp-block-list.is-style-voyager-open-ticks>li{position:relative;margin:0;padding-inline-start:1.6em}'
				. '.wp-block-list.is-style-voyager-open-ticks>li::before{content:"";position:absolute;inset-inline-start:0;top:.2em;width:1.1em;height:1.1em;background:currentColor;'
				. '-webkit-mask:url("data:image/svg+xml,' . $tick . '") center/contain no-repeat;mask:url("data:image/svg+xml,' . $tick . '") center/contain no-repeat}'
				. '.wp-block-group.is-layout-flex:has(>.wp-block-list.is-style-voyager-open-ticks){container:voyager-open-ticks/inline-size}'
				. '@container voyager-open-ticks (max-width:32em){.wp-block-list.is-style-voyager-open-ticks{flex-direction:column;width:100%}}',
			BlockStyles\ticks_css( '.wp-block-list' )
		);
	}

	public function test_ticks_css_for_facts_targets_only_the_facts_list(): void {
		$css = BlockStyles\ticks_css( '.wp-block-voyager-open-facts' );

		$this->assertStringNotContainsString( '.wp-block-list', $css );
		$this->assertSame( 5, substr_count( $css, '.wp-block-voyager-open-facts.is-style-voyager-open-ticks' ) );
		$this->assertStringContainsString( ':has(>.wp-block-voyager-open-facts.is-style-voyager-open-ticks){container:voyager-open-ticks/inline-size}', $css );
	}
}
