<?php
/**
 * Icon blocks: leitvia/icon and the icons of leitvia/icon-list-item, drawn as inline SVG from the bundled Lucide set.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * The icons live in assets/icons/lucide.json (name => inner SVG markup, a subset of Lucide, see readme.txt). The
 * filter leitvia_studio_icons adds icons of a site or an add-on: entries by name with svg (inner markup), viewBox
 * (default 0 0 24 24) and paint (stroke or fill, default stroke). A bundled name cannot be replaced, so the set stays
 * stable. The block.json files name no enum, so any name passes the block validation; an unknown name renders nothing.
 * Every icon is drawn in currentColor at 1em, so color and size come from the text color and font size presets of
 * the block.
 */
final class Icon {

	public const FILE = 'assets/icons/lucide.json';

	/**
	 * Names of icons: a lowercase letter, then lowercase letters, digits and hyphens.
	 */
	private const NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

	/**
	 * Handle of the inline script with the icons of the filter for the editor.
	 */
	public const EDITOR_HANDLE = 'leitvia-studio-icons';

	/**
	 * A viewBox: four numbers.
	 */
	private const VIEW_BOX = '/^-?\d+(?:\.\d+)?(?: -?\d+(?:\.\d+)?){3}$/';

	/**
	 * The bundled icons by name, read once per request.
	 *
	 * @var array<string, array{svg: string, viewBox: string, paint: string}>|null
	 */
	private static ?array $lucide = null;

	/**
	 * The valid entries of the filter, kept for the request once init ran; flush() empties it.
	 *
	 * @var array<string, array{svg: string, viewBox: string, paint: string}>|null
	 */
	private static ?array $custom = null;

	/**
	 * The bundled icons and the valid ones of the filter, by name.
	 *
	 * @return array<string, array{svg: string, viewBox: string, paint: string}>
	 */
	public static function icons(): array {
		return self::lucide() + self::custom();
	}

	/**
	 * The bundled icons by name.
	 *
	 * @return array<string, array{svg: string, viewBox: string, paint: string}>
	 */
	private static function lucide(): array {
		if ( null === self::$lucide ) {
			$json         = file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/' . self::FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin.
			$icons        = json_decode( (string) $json, true );
			self::$lucide = array_map( static fn( $svg ): array => self::entry( (string) $svg ), is_array( $icons ) ? $icons : array() );
		}
		return self::$lucide;
	}

	/**
	 * The valid entries of the filter leitvia_studio_icons whose names are not bundled.
	 *
	 * @return array<string, array{svg: string, viewBox: string, paint: string}>
	 */
	public static function custom(): array {
		if ( null !== self::$custom ) {
			return self::$custom;
		}
		/**
		 * Filters the icons the icon blocks may draw besides the bundled set.
		 *
		 * Entries by name: svg (inner markup an add-on has cleaned, printed as it is), viewBox and paint.
		 *
		 * @param array<mixed> $icons Entries by name.
		 */
		$filtered = (array) apply_filters( 'leitvia_studio_icons', array() );
		$lucide   = self::lucide();
		$icons    = array();
		foreach ( $filtered as $name => $icon ) {
			$name = (string) $name;
			if ( isset( $lucide[ $name ] ) || ! preg_match( self::NAME, $name ) || ! is_array( $icon ) ) {
				continue;
			}
			$entry = self::entry( (string) ( $icon['svg'] ?? '' ), (string) ( $icon['viewBox'] ?? '0 0 24 24' ), (string) ( $icon['paint'] ?? 'stroke' ) );
			$box   = explode( ' ', $entry['viewBox'] );
			if ( '' !== $entry['svg'] && preg_match( self::VIEW_BOX, $entry['viewBox'] ) && (float) $box[2] > 0 && (float) $box[3] > 0 && in_array( $entry['paint'], array( 'stroke', 'fill' ), true ) ) {
				$icons[ $name ] = $entry;
			}
		}
		// Before init an add-on may not have hooked its icons yet, so only a later result is kept.
		if ( did_action( 'init' ) ) {
			self::$custom = $icons;
		}
		return $icons;
	}

	/**
	 * Empties the kept icons of the filter. An add-on calls it when the source of its icons changes; Studio calls it
	 * when the site switches (switch_blog).
	 */
	public static function flush(): void {
		self::$custom = null;
	}

	/**
	 * Stroke width of a stroke icon: 2 on the 24 grid of Lucide, in proportion to the height of another viewBox (icons
	 * are drawn 1em high), so every icon draws lines of the same weight at the same size.
	 *
	 * @param string $view_box viewBox.
	 */
	public static function stroke_width( string $view_box ): string {
		$height = (float) ( explode( ' ', $view_box )[3] ?? 24 );
		return self::number( 2 * ( $height > 0 ? $height : 24 ) / 24 );
	}

	/**
	 * Width of an icon that is 24 high, in the proportion of its viewBox: 24 for a square, more for a wide icon, so a
	 * combined icon is as high as a simple one and the CSS draws it 1em high with the width auto.
	 *
	 * @param string $view_box viewBox.
	 */
	public static function width( string $view_box ): string {
		$box = array_map( 'floatval', explode( ' ', $view_box ) );
		return ( $box[2] ?? 0 ) > 0 && ( $box[3] ?? 0 ) > 0 ? self::number( 24 * $box[2] / $box[3] ) : '24';
	}

	/**
	 * A number with at most four decimals, without trailing zeros.
	 *
	 * @param float $value Number.
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
	}

	/**
	 * One icon entry.
	 *
	 * @param string $svg      Inner SVG markup.
	 * @param string $view_box viewBox.
	 * @param string $paint    stroke or fill.
	 * @return array{svg: string, viewBox: string, paint: string}
	 */
	private static function entry( string $svg, string $view_box = '0 0 24 24', string $paint = 'stroke' ): array {
		return array(
			'svg'     => $svg,
			'viewBox' => $view_box,
			'paint'   => $paint,
		);
	}

	/**
	 * The SVG of an icon. Without a label it is decorative and hidden from assistive technology; with one it is an
	 * image named by the label. The class leitvia-icon--<name> lets a theme style one icon. A stroke icon gets the
	 * stroke attributes of Lucide (stroke_width()), a fill icon fill="currentColor" and none of them. Empty for an
	 * unknown name.
	 *
	 * @param string $name  Icon name.
	 * @param string $label Accessible name, empty for a decorative icon.
	 */
	public static function svg( string $name, string $label = '' ): string {
		$icon = self::icons()[ $name ] ?? null;
		if ( ! $icon ) {
			return '';
		}
		$a11y  = '' === $label ? 'aria-hidden="true" focusable="false"' : sprintf( 'role="img" aria-label="%s"', esc_attr( $label ) );
		$paint = 'fill' === $icon['paint'] ? 'fill="currentColor"' : 'fill="none" stroke="currentColor" stroke-width="' . self::stroke_width( $icon['viewBox'] ) . '" stroke-linecap="round" stroke-linejoin="round"';
		// The markup comes from the bundled file or from an add-on that cleaned it, not from the post, so it is not
		// escaped.
		return sprintf(
			'<svg class="leitvia-icon leitvia-icon--%3$s" xmlns="http://www.w3.org/2000/svg" viewBox="%4$s" width="%6$s" height="24" %5$s %1$s>%2$s</svg>',
			$a11y,
			$icon['svg'],
			esc_attr( $name ),
			esc_attr( $icon['viewBox'] ),
			$paint,
			esc_attr( self::width( $icon['viewBox'] ) )
		);
	}

	/**
	 * Hands the icons of the filter to the editor as window.leitviaStudioIcons: one inline script on the handle
	 * EDITOR_HANDLE without a file, a dependency of the scripts of leitvia/icon and leitvia/icon-list (which registers
	 * the items too), so it prints once and before both; the picker imports the bundled set itself. Runs on
	 * enqueue_block_editor_assets.
	 */
	public static function editor_data(): void {
		if ( ! wp_script_is( self::EDITOR_HANDLE, 'registered' ) ) {
			wp_register_script( self::EDITOR_HANDLE, false, array(), LEITVIA_STUDIO_VERSION, false );
			wp_add_inline_script( self::EDITOR_HANDLE, 'window.leitviaStudioIcons = ' . wp_json_encode( (object) self::custom(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';' );
		}
		foreach ( array( 'leitvia/icon', 'leitvia/icon-list' ) as $block ) {
			$type = \WP_Block_Type_Registry::get_instance()->get_registered( $block );
			foreach ( $type ? $type->editor_script_handles : array() as $handle ) {
				$script = wp_scripts()->query( $handle );
				if ( $script && ! in_array( self::EDITOR_HANDLE, $script->deps, true ) ) {
					$script->deps[] = self::EDITOR_HANDLE;
				}
			}
		}
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
		// A callback, so a $ or a backslash in the markup of an icon is no back reference.
		return '' === $svg ? $content : (string) preg_replace_callback( '/<li\b[^>]*>/', static fn( array $m ): string => $m[0] . $svg, $content, 1 );
	}
}
