<?php
/**
 * voyager-open/icon's CSS, split by animation: the block's style holds the
 * rules every icon needs, each animation's rules are a stylesheet of their
 * own that loads only for an icon that uses it, and the editor loads all of
 * them.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Icons;

require_once dirname( __DIR__ ) . '/icon-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/icons.php';

final class IconTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_enqueued_styles']    = [];
		$GLOBALS['voyager_test_wrapper_attributes'] = 'class="wp-block-voyager-open-icon"';
	}

	/**
	 * A block.json, decoded.
	 *
	 * @param string $folder `src` or `build`.
	 * @return array<string, mixed>
	 */
	private static function blockJson( string $folder ): array {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $folder . '/icon/block.json' ), true );

		return is_array( $json ) ? $json : [];
	}

	/**
	 * The `animation` enum in src/icon/block.json, less "none".
	 *
	 * @return list<string>
	 */
	private static function animationEnum(): array {
		$attributes = self::blockJson( 'src' )['attributes'] ?? null;
		$animation  = is_array( $attributes ) ? ( $attributes['animation'] ?? null ) : null;
		$enum       = is_array( $animation ) ? ( $animation['enum'] ?? null ) : null;

		return array_values( array_filter( is_array( $enum ) ? array_map( 'strval', $enum ) : [], static fn ( string $name ): bool => '' !== $name ) );
	}

	/**
	 * The names of the @keyframes rules in some CSS.
	 *
	 * @return list<string>
	 */
	private static function keyframes( string $css ): array {
		preg_match_all( '/@keyframes\s+([\w-]+)/', $css, $matches );

		return $matches[1];
	}

	/**
	 * What src/icon/render.php prints for these attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes, bindings already applied.
	 */
	private function render( array $attributes ): string {
		ob_start();
		( static function ( array $attributes ): void {
			$content = '';
			$block   = null;
			require dirname( __DIR__, 2 ) . '/src/icon/render.php';
		} )( $attributes );

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function animations(): array {
		$out = [];
		foreach ( array_keys( Icons\ANIMATIONS ) as $name ) {
			$out[ $name ] = [ $name ];
		}
		return $out;
	}

	public function test_every_animation_in_block_json_has_a_stylesheet_and_no_other(): void {
		$this->assertCount( 7, self::animationEnum() );
		$this->assertSame( self::animationEnum(), array_keys( Icons\ANIMATIONS ) );
	}

	#[DataProvider( 'animations' )]
	public function test_an_animation_stylesheet_holds_its_own_keyframes_and_trigger_rules_only( string $name ): void {
		$css  = Icons\animation_css( $name );
		$icon = '.wp-block-voyager-open-icon.has-animation-' . $name;
		$svg  = ' .wp-block-voyager-open-icon__svg';

		$this->assertSame( [ 'voyager-open-icon-' . $name ], self::keyframes( $css ) );
		$this->assertStringContainsString( $icon . '.is-trigger-always' . $svg . '{animation:voyager-open-icon-' . $name . ' ', $css );
		$this->assertStringContainsString( $icon . '.is-trigger-hover:hover' . $svg . ',' . $icon . '.is-trigger-hover:focus-within' . $svg . '{animation:voyager-open-icon-' . $name . ' ', $css );
		$this->assertStringContainsString( $icon . '.is-trigger-scroll' . $svg . '{animation:voyager-open-icon-' . $name . ' ', $css );
		$this->assertStringContainsString( '@supports (animation-timeline:view()){.wp-block-voyager-open-icon.is-trigger-scroll' . $svg . '{animation-duration:auto;animation-timeline:view();', $css );

		preg_match_all( '/has-animation-([\w-]+)/', $css, $named );
		$this->assertSame( [ $name ], array_values( array_unique( $named[1] ) ) );
	}

	#[DataProvider( 'animations' )]
	public function test_reduced_motion_stops_the_animation( string $name ): void {
		$this->assertStringContainsString(
			'@media (prefers-reduced-motion:reduce){.wp-block-voyager-open-icon .wp-block-voyager-open-icon__svg{animation:none!important}}',
			Icons\animation_css( $name )
		);
	}

	public function test_an_unknown_animation_has_no_rules(): void {
		$this->assertSame( '', Icons\animation_css( '' ) );
		$this->assertSame( '', Icons\animation_css( 'shake' ) );
		$this->assertSame( '', Icons\animation_css( 'has-animation-pulse' ) );
	}

	public function test_the_block_style_holds_no_animation_and_stays_under_1_kb(): void {
		$this->assertSame( 'file:./style-index.css', self::blockJson( 'src' )['style'] ?? null );

		foreach ( [ 'style-index.css', 'style-index-rtl.css' ] as $file ) {
			$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/build/icon/' . $file );

			$this->assertStringContainsString( '.wp-block-voyager-open-icon{display:flex', $css, $file );
			$this->assertSame( [], self::keyframes( $css ), $file );
			$this->assertStringNotContainsString( 'has-animation-', $css, $file );
			$this->assertStringNotContainsString( 'is-trigger-', $css, $file );
			$this->assertLessThan( 1024, strlen( $css ), $file );
		}
	}

	public function test_the_editor_loads_every_animation(): void {
		$expected = array_merge( [ 'file:./index.css' ], array_map( 'VoyagerOpenBlocks\Icons\animation_handle', array_keys( Icons\ANIMATIONS ) ) );

		foreach ( [ 'src', 'build' ] as $folder ) {
			$this->assertSame( $expected, self::blockJson( $folder )['editorStyle'] ?? null, $folder );
		}
	}

	public function test_enqueue_loads_one_known_animation_and_nothing_else(): void {
		Icons\enqueue_animation( 'pulse' );
		$this->assertSame( [ 'voyager-open-icon-animation-pulse' ], $GLOBALS['voyager_test_enqueued_styles'] );

		$GLOBALS['voyager_test_enqueued_styles'] = [];
		foreach ( [ '', 'shake', 'Pulse', ' pulse' ] as $name ) {
			Icons\enqueue_animation( $name );
		}
		$this->assertSame( [], $GLOBALS['voyager_test_enqueued_styles'] );
	}

	#[DataProvider( 'animations' )]
	public function test_an_icon_with_an_animation_loads_that_animation_alone( string $name ): void {
		$html = $this->render( [ 'icon' => 'star', 'size' => 48, 'animation' => $name, 'animationTrigger' => 'always' ] );

		$this->assertStringContainsString( '<svg class="wp-block-voyager-open-icon__svg"', $html );
		$this->assertSame( [ Icons\animation_handle( $name ) ], $GLOBALS['voyager_test_enqueued_styles'] );
	}

	public function test_an_icon_with_no_animation_loads_no_animation(): void {
		$html = $this->render( [ 'icon' => 'star', 'size' => 48 ] );
		$html .= $this->render( [ 'icon' => 'star', 'size' => 48, 'animation' => '', 'animationTrigger' => 'always' ] );

		$this->assertSame( 2, substr_count( $html, '<svg class="wp-block-voyager-open-icon__svg"' ) );
		$this->assertSame( [], $GLOBALS['voyager_test_enqueued_styles'] );
	}

	public function test_an_unknown_icon_renders_nothing_and_loads_nothing(): void {
		$this->assertSame( '', $this->render( [ 'icon' => 'not-an-icon', 'animation' => 'pulse' ] ) );
		$this->assertSame( [], $GLOBALS['voyager_test_enqueued_styles'] );
	}
}
