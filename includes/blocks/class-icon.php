<?php
/**
 * Icon blocks: leitvia/icon and the icons of leitvia/icon-list-item, drawn as inline SVG from the bundled Lucide set.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * The icons live in assets/icons/lucide.json (name => inner SVG markup, a subset of Lucide, see
 * readme.txt). The block.json files name the same icon names as enum, so agents can only write known icons; an
 * unknown name renders nothing. Every icon is drawn in currentColor at 1em, so color and size come from the text color
 * and font size presets of the block.
 */
final class Icon {

	public const FILE = 'assets/icons/lucide.json';

	/**
	 * Inner SVG markup by icon name, read once per request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $icons = null;

	/**
	 * Inner SVG markup by icon name.
	 *
	 * @return array<string, string>
	 */
	public static function icons(): array {
		if ( null === self::$icons ) {
			$json        = file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/' . self::FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin.
			$icons       = json_decode( (string) $json, true );
			self::$icons = is_array( $icons ) ? array_map( 'strval', $icons ) : array();
		}
		return self::$icons;
	}

	/**
	 * The SVG of an icon. Without a label it is decorative and hidden from assistive technology; with one it is an
	 * image named by the label. The class leitvia-icon--<name> lets a theme style one icon. Empty for an unknown name.
	 *
	 * @param string $name  Icon name.
	 * @param string $label Accessible name, empty for a decorative icon.
	 */
	public static function svg( string $name, string $label = '' ): string {
		$inner = self::icons()[ $name ] ?? '';
		if ( '' === $inner ) {
			return '';
		}
		$a11y = '' === $label ? 'aria-hidden="true" focusable="false"' : sprintf( 'role="img" aria-label="%s"', esc_attr( $label ) );
		// The markup comes from the bundled file, not from the post, so it is not escaped.
		return sprintf(
			'<svg class="leitvia-icon leitvia-icon--%3$s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" %1$s>%2$s</svg>',
			$a11y,
			$inner,
			esc_attr( $name )
		);
	}

	/**
	 * Renders leitvia/icon. A link needs a label as its accessible name; without one, or with a URL esc_url() drops,
	 * the icon renders without the link, so there is never a link that screen readers announce without a name.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		$url   = esc_url( trim( (string) ( $attributes['url'] ?? '' ) ) );
		$link  = '' !== $url && '' !== $label;
		$svg   = self::svg( (string) ( $attributes['icon'] ?? '' ), $link ? '' : $label );
		if ( '' === $svg ) {
			return '';
		}
		if ( $link ) {
			$svg = sprintf( '<a class="wp-block-leitvia-icon__link" href="%1$s" aria-label="%2$s">%3$s</a>', $url, esc_attr( $label ), $svg );
		}
		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes(), $svg );
	}

	/**
	 * Renders leitvia/icon-list-item: puts the decorative icon of the item, or else the one of the list, in front of
	 * the saved text. The text says it all, so the icon stays hidden from assistive technology.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Saved block HTML, an li element.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render_item( array $attributes, string $content, ?\WP_Block $block = null ): string {
		$name = (string) ( $attributes['icon'] ?? '' );
		if ( '' === $name ) {
			$name = (string) ( $block->context['leitvia/iconListIcon'] ?? 'check' );
		}
		$svg = self::svg( $name );
		return '' === $svg ? $content : (string) preg_replace( '/<li\b[^>]*>/', '$0' . $svg, $content, 1 );
	}
}
