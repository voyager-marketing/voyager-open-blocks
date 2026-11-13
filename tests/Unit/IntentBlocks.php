<?php
/**
 * A pattern's blocks as parse_blocks() returns them, built from its intent in
 * patterns/intents/, which is what patterns/<name>.html is built from.
 *
 * Parsing markup needs WordPress, so the unit tests build the blocks
 * themselves. A text block (BlockTree\TEXT_ATTRIBUTES) saves its text in its
 * own tag, a button its link too, so BlockTree\own_text() reads it as core's
 * would. Every other block saves a wrapper with one slot per inner block and
 * a blank line between them, as core's containers do, so BlockTree\omit() and
 * BlockTree\repeat() can change it. scripts/e2e.mjs runs the abilities over
 * REST on the patterns as registered.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

final class IntentBlocks {

	private const INTENTS = __DIR__ . '/../../patterns/intents';

	/**
	 * A decoded intent file.
	 *
	 * @param string $name Its file name less .json, such as proof-strip.
	 * @return array<string, mixed>
	 */
	public static function intent( string $name ): array {
		$data = json_decode( (string) file_get_contents( self::INTENTS . "/{$name}.json" ), true );

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( "patterns/intents/{$name}.json does not decode." );
		}

		/** @var array<string, mixed> $data */
		return $data;
	}

	/**
	 * A pattern's top-level blocks, built from its intent.
	 *
	 * @param string $name Its file name less .json, such as proof-strip.
	 * @return array<int, array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}>
	 */
	public static function blocks( string $name ): array {
		$nodes = self::intent( $name )['intent']['blocks'] ?? [];

		/** @var list<array<string, mixed>> $nodes */
		return array_map( static fn ( array $node ): array => self::block( $node ), is_array( $nodes ) ? $nodes : [] );
	}

	/**
	 * One intent node as a parsed block.
	 *
	 * @param array<string, mixed> $node Intent node.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	public static function block( array $node ): array {
		/** @var list<array<string, mixed>> $children */
		$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
		$inner    = array_map( static fn ( array $child ): array => self::block( $child ), $children );
		$name     = (string) $node['block'];
		$attrs    = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];
		$text     = htmlspecialchars( (string) ( $node['text'] ?? '' ), ENT_QUOTES );
		$tags     = [
			'core/heading'   => 'h' . (int) ( $node['level'] ?? 2 ),
			'core/paragraph' => 'p',
			'core/list-item' => 'li',
		];

		if ( 'core/button' === $name ) {
			$url  = htmlspecialchars( (string) ( $node['url'] ?? '' ), ENT_QUOTES );
			$html = "\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"{$url}\">{$text}</a></div>\n";

			return self::with( $name, $attrs, [], [ $html ] );
		}

		if ( isset( $tags[ $name ] ) && [] === $inner ) {
			$html = "\n<{$tags[ $name ]}>{$text}</{$tags[ $name ]}>\n";

			return self::with( $name, $attrs, [], [ $html ] );
		}

		$content = [ "\n<div>" ];
		foreach ( array_keys( $inner ) as $index ) {
			if ( $index > 0 ) {
				$content[] = "\n\n";
			}
			$content[] = null;
		}
		$content[] = "</div>\n";

		return self::with( $name, $attrs, $inner, $content );
	}

	/**
	 * A parsed block from its parts.
	 *
	 * @param string             $name    Block name.
	 * @param array<mixed>       $attrs   Attributes.
	 * @param array<array<mixed>> $inner   Inner blocks.
	 * @param array<mixed>       $content innerContent.
	 * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}
	 */
	private static function with( string $name, array $attrs, array $inner, array $content ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
			'innerContent' => $content,
		];
	}

	/**
	 * Each named block's label and own text, by path, for the blocks that have both.
	 *
	 * @param array<int, array<mixed>> $blocks Blocks.
	 * @param string                   $prefix Path of the parent, '' at the top.
	 * @return array<string, array{label: string, text: string}>
	 */
	public static function texts( array $blocks, string $prefix = '' ): array {
		$out = [];

		foreach ( $blocks as $index => $block ) {
			$path  = '' === $prefix ? (string) $index : $prefix . '/' . $index;
			$label = $block['attrs']['metadata']['name'] ?? null;

			/** @var array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>} $block */
			$text = \VoyagerOpenBlocks\BlockTree\own_text( $block );

			if ( is_string( $label ) && '' !== $text ) {
				$out[ $path ] = [
					'label' => $label,
					'text'  => $text,
				];
			}

			/** @var array<int, array<mixed>> $inner */
			$inner = $block['innerBlocks'];
			$out  += self::texts( $inner, $path );
		}

		return $out;
	}
}
