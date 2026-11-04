<?php
/**
 * Icon blocks: leitvia/icon, leitvia/icon-list, leitvia/icon-list-item.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Icon;

/**
 * Icons render as inline SVG from the bundled set: decorative without a label, an image with one, a link only with a
 * label as its name. List items get the icon of the list unless they name their own. The block.json enums list exactly
 * the bundled icons, so agents cannot write a name that renders nothing.
 */
class Test_Icon extends WP_UnitTestCase {

	/**
	 * Saved markup of one icon list item.
	 *
	 * @param string $text  Item text.
	 * @param string $attrs Block comment attributes as JSON, empty for none.
	 */
	private static function item( string $text, string $attrs = '' ): string {
		return '<!-- wp:leitvia/icon-list-item' . ( '' === $attrs ? '' : ' ' . $attrs ) . ' --><li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text">' . $text . '</span></li><!-- /wp:leitvia/icon-list-item -->';
	}

	/**
	 * Without a label the icon is hidden from assistive technology and drawn in currentColor.
	 */
	public function test_decorative_icon_is_hidden(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"phone"} /-->' );
		$this->assertMatchesRegularExpression( '#^<div class="wp-block-leitvia-icon"><svg class="leitvia-icon leitvia-icon--phone" [^>]*stroke="currentColor"[^>]* aria-hidden="true" focusable="false"><path #', $html );
		$this->assertStringNotContainsString( 'role="img"', $html );
	}

	/**
	 * With a label the icon is an image named by it, escaped.
	 */
	public function test_labelled_icon_is_an_image(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"star","label":"Top <b>rated</b>"} /-->' );
		$this->assertStringContainsString( 'role="img" aria-label="Top &lt;b&gt;rated&lt;/b&gt;"', $html );
		$this->assertStringNotContainsString( 'aria-hidden', $html );
	}

	/**
	 * A link takes the label as its name; the icon inside stays decorative, so the name is not read twice.
	 */
	public function test_link_is_named_by_the_label(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"mail","label":"Email us","url":"mailto:hello@example.com"} /-->' );
		$this->assertMatchesRegularExpression( '#<a class="wp-block-leitvia-icon__link" href="mailto:hello@example.com" aria-label="Email us"><svg [^>]*aria-hidden="true"#', $html );
	}

	/**
	 * A link without a label would have no accessible name: the icon renders without it.
	 */
	public function test_no_link_without_label(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"heart","url":"/contact/"} /-->' );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	/**
	 * Unsafe link targets are dropped by esc_url().
	 */
	public function test_unsafe_url_is_dropped(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"link","label":"Open","url":"javascript:alert(1)"} /-->' );
		$this->assertStringNotContainsString( 'javascript', $html );
	}

	/**
	 * An unknown name renders nothing instead of an empty SVG.
	 */
	public function test_unknown_icon_renders_nothing(): void {
		$this->assertSame( '', Icon::render( array( 'icon' => 'no-such-icon' ) ) );
		$this->assertSame( '', Icon::svg( '../lucide' ) );
	}

	/**
	 * Color and font size presets land on the wrapper, which sizes and colors the icon (1em, currentColor).
	 */
	public function test_presets_on_the_wrapper(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"phone","textColor":"accent","fontSize":"heading-l"} /-->' );
		$this->assertMatchesRegularExpression( '#^<div class="wp-block-leitvia-icon [^"]*has-accent-color[^"]*has-heading-l-font-size#', $html );
	}

	/**
	 * Items are list elements with the icon of the list in front of their text; an item can name its own.
	 */
	public function test_list_items_use_the_list_icon_or_their_own(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon-list {"icon":"shield-check"} --><ul role="list" class="wp-block-leitvia-icon-list">' . self::item( 'Hosted in Germany' ) . self::item( 'Call us', '{"icon":"phone"}' ) . '</ul><!-- /wp:leitvia/icon-list -->' );
		$this->assertSame( 2, substr_count( $html, '<li class="wp-block-leitvia-icon-list-item"><svg class="leitvia-icon ' ) );
		$this->assertSame( 2, substr_count( $html, 'aria-hidden="true" focusable="false"' ) );
		$shield = Icon::icons()['shield-check'];
		$phone  = Icon::icons()['phone'];
		$this->assertMatchesRegularExpression( '#<svg [^>]*>' . preg_quote( $shield, '#' ) . '</svg><span class="wp-block-leitvia-icon-list-item__text">Hosted in Germany</span>#', $html );
		$this->assertMatchesRegularExpression( '#<svg [^>]*>' . preg_quote( $phone, '#' ) . '</svg><span class="wp-block-leitvia-icon-list-item__text">Call us</span>#', $html );
	}

	/**
	 * A list saved without an icon attribute uses the default check mark.
	 */
	public function test_list_default_icon_is_check(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon-list --><ul role="list" class="wp-block-leitvia-icon-list">' . self::item( 'Free delivery' ) . '</ul><!-- /wp:leitvia/icon-list -->' );
		$this->assertStringContainsString( Icon::icons()['check'] . '</svg><span', $html );
	}

	/**
	 * The enums of the three block.json files are exactly the bundled icons, and every icon is plain SVG markup.
	 */
	public function test_enums_match_the_bundled_set(): void {
		$names = array_keys( Icon::icons() );
		$this->assertGreaterThanOrEqual( 60, count( $names ) );
		foreach ( array( 'icon', 'icon-list', 'icon-list-item' ) as $block ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( 'leitvia/' . $block );
			$this->assertNotNull( $type, $block );
			$this->assertSame( $names, $type->attributes['icon']['enum'], $block );
		}
		foreach ( Icon::icons() as $name => $inner ) {
			$this->assertMatchesRegularExpression( '#^(<(path|circle|rect|line|polyline|polygon|ellipse) [^<>]*/>)+$#', $inner, $name );
		}
	}
}
