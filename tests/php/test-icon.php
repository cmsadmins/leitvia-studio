<?php
/**
 * Icon blocks: leitvia/icon, leitvia/icon-list, leitvia/icon-list-item.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Icon;

/**
 * Icons render as inline SVG from the bundled set or from the filter leitvia_studio_icons: decorative without a label,
 * an image with one, a link only with a label as its name. List items get the icon of the list unless they name their
 * own. An unknown name renders nothing; a filter entry cannot replace a bundled icon.
 */
class Test_Icon extends WP_UnitTestCase {

	/**
	 * Removes the icons a test added.
	 */
	public function tear_down(): void {
		remove_all_filters( 'leitvia_studio_icons' );
		Icon::flush();
		// editor_data() made the scripts of the icon blocks depend on the handle; a later test that enqueues them would
		// see a dependency that is no longer registered.
		foreach ( array( 'leitvia/icon', 'leitvia/icon-list' ) as $block ) {
			foreach ( (array) WP_Block_Type_Registry::get_instance()->get_registered( $block )?->editor_script_handles as $handle ) {
				$script = wp_scripts()->query( $handle );
				if ( $script ) {
					$script->deps = array_values( array_diff( $script->deps, array( Icon::EDITOR_HANDLE ) ) );
				}
			}
		}
		wp_deregister_script( Icon::EDITOR_HANDLE );
		parent::tear_down();
	}

	/**
	 * Adds icons through the filter.
	 *
	 * @param array<string, mixed> $icons Entries by name.
	 */
	private static function add_icons( array $icons ): void {
		add_filter( 'leitvia_studio_icons', static fn( array $set ): array => $icons + $set );
		Icon::flush();
	}

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
		$shield = Icon::icons()['shield-check']['svg'];
		$phone  = Icon::icons()['phone']['svg'];
		$this->assertMatchesRegularExpression( '#<svg [^>]*>' . preg_quote( $shield, '#' ) . '</svg><span class="wp-block-leitvia-icon-list-item__text">Hosted in Germany</span>#', $html );
		$this->assertMatchesRegularExpression( '#<svg [^>]*>' . preg_quote( $phone, '#' ) . '</svg><span class="wp-block-leitvia-icon-list-item__text">Call us</span>#', $html );
	}

	/**
	 * A list saved without an icon attribute uses the default check mark.
	 */
	public function test_list_default_icon_is_check(): void {
		$html = do_blocks( '<!-- wp:leitvia/icon-list --><ul role="list" class="wp-block-leitvia-icon-list">' . self::item( 'Free delivery' ) . '</ul><!-- /wp:leitvia/icon-list -->' );
		$this->assertStringContainsString( Icon::icons()['check']['svg'] . '</svg><span', $html );
	}

	/**
	 * The block.json files name no enum, so names from the filter pass the block validation; every bundled icon is
	 * plain stroke markup on the Lucide grid.
	 */
	public function test_bundled_set_without_enums(): void {
		$names = array_keys( Icon::icons() );
		$this->assertGreaterThanOrEqual( 100, count( $names ) );
		foreach ( array( 'icon', 'icon-list', 'icon-list-item' ) as $block ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( 'leitvia/' . $block );
			$this->assertNotNull( $type, $block );
			$this->assertArrayNotHasKey( 'enum', $type->attributes['icon'], $block );
			$this->assertSame( 'string', $type->attributes['icon']['type'], $block );
		}
		foreach ( Icon::icons() as $name => $icon ) {
			$this->assertSame( array( 'svg', 'viewBox', 'paint' ), array_keys( $icon ), $name );
			$this->assertSame( '0 0 24 24', $icon['viewBox'], $name );
			$this->assertSame( 'stroke', $icon['paint'], $name );
			$this->assertMatchesRegularExpression( '#^(<(path|circle|rect|line|polyline|polygon|ellipse) [^<>]*/>)+$#', $icon['svg'], $name );
		}
	}

	/**
	 * A filled icon from the filter keeps its own viewBox and is drawn with fill="currentColor", without stroke
	 * attributes; as a block and in a list item it renders like a bundled one.
	 */
	public function test_filter_icon_with_own_view_box_and_fill(): void {
		self::add_icons(
			array(
				'brand-mark' => array(
					'svg'     => '<path d="M0 0h100v100H0z"/>',
					'viewBox' => '0 0 100 100',
					'paint'   => 'fill',
				),
			)
		);
		$svg = Icon::svg( 'brand-mark' );
		$this->assertStringContainsString( 'class="leitvia-icon leitvia-icon--brand-mark"', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 100 100"', $svg );
		$this->assertStringContainsString( 'fill="currentColor"', $svg );
		$this->assertStringNotContainsString( 'stroke', $svg );
		$this->assertStringContainsString( '><path d="M0 0h100v100H0z"/></svg>', $svg );
		$html = do_blocks( '<!-- wp:leitvia/icon {"icon":"brand-mark","label":"Brand"} /-->' );
		$this->assertStringContainsString( 'role="img" aria-label="Brand"', $html );
		$list = do_blocks( '<!-- wp:leitvia/icon-list {"icon":"brand-mark"} --><ul role="list" class="wp-block-leitvia-icon-list">' . self::item( 'Ours' ) . '</ul><!-- /wp:leitvia/icon-list -->' );
		$this->assertStringContainsString( '<path d="M0 0h100v100H0z"/></svg><span', $list );
	}

	/**
	 * A stroke icon from the filter gets the stroke attributes of the bundled set; missing fields take the defaults.
	 */
	public function test_filter_icon_defaults_to_stroke_on_the_lucide_grid(): void {
		self::add_icons( array( 'brand-line' => array( 'svg' => '<line x1="2" y1="2" x2="22" y2="22"/>' ) ) );
		$svg = Icon::svg( 'brand-line' );
		$this->assertStringContainsString( 'viewBox="0 0 24 24"', $svg );
		$this->assertStringContainsString( 'fill="none" stroke="currentColor" stroke-width="2"', $svg );
	}

	/**
	 * A stroke icon on another grid keeps the weight of Lucide: the stroke width grows with the viewBox (24 to 2).
	 */
	public function test_stroke_width_follows_the_view_box(): void {
		self::add_icons(
			array(
				'brand-wide' => array(
					'svg'     => '<path d="M1 1h40"/>',
					'viewBox' => '0 0 48 48',
				),
				'brand-tiny' => array(
					'svg'     => '<path d="M1 1h4"/>',
					'viewBox' => '0 0 16 16',
				),
			)
		);
		$this->assertStringContainsString( 'stroke-width="4"', Icon::svg( 'brand-wide' ) );
		$this->assertStringContainsString( 'stroke-width="1.3333"', Icon::svg( 'brand-tiny' ) );
		$this->assertStringContainsString( 'stroke-width="2"', Icon::svg( 'phone' ) );
	}

	/**
	 * An icon with a wide viewBox is 24 high and wider in its proportion, with the stroke of its height; square icons
	 * stay 24 by 24.
	 */
	public function test_wide_icon_keeps_its_proportion(): void {
		self::add_icons(
			array(
				'brand-pair' => array(
					'svg'     => '<path d="M1 1h40"/>',
					'viewBox' => '0 0 48 24',
				),
			)
		);
		$svg = Icon::svg( 'brand-pair' );
		$this->assertStringContainsString( 'viewBox="0 0 48 24" width="48" height="24"', $svg );
		$this->assertStringContainsString( 'stroke-width="2"', $svg );
		$this->assertStringContainsString( 'width="24" height="24"', Icon::svg( 'phone' ) );
		$this->assertSame( '24', Icon::width( '0 0 0 0' ) );
		$this->assertSame( '36', Icon::width( '0 0 150 100' ) );
	}

	/**
	 * The icons of the filter are read once per request; flush() reads them again, for an add-on whose source changed.
	 */
	public function test_filter_icons_are_kept_until_flushed(): void {
		$calls = 0;
		add_filter(
			'leitvia_studio_icons',
			static function ( array $set ) use ( &$calls ): array {
				++$calls;
				return $set + array( 'brand-ok' => array( 'svg' => '<path d="M1 1h2"/>' ) );
			}
		);
		Icon::flush();
		Icon::svg( 'brand-ok' );
		Icon::svg( 'brand-ok' );
		Icon::icons();
		$this->assertSame( 1, $calls );
		Icon::flush();
		Icon::custom();
		$this->assertSame( 2, $calls );
		do_action( 'switch_blog', 1, 1 );
		Icon::custom();
		$this->assertSame( 3, $calls, 'a switch of the site reads them again' );
	}

	/**
	 * The bundled set stays stable: a filter entry with a Lucide name is ignored.
	 */
	public function test_filter_cannot_replace_a_bundled_icon(): void {
		$phone = Icon::icons()['phone'];
		self::add_icons(
			array(
				'phone' => array(
					'svg'   => '<rect width="10" height="10"/>',
					'paint' => 'fill',
				),
			)
		);
		$this->assertSame( $phone, Icon::icons()['phone'] );
		$this->assertStringNotContainsString( '<rect width="10" height="10"/>', Icon::svg( 'phone' ) );
	}

	/**
	 * Entries with a name outside [a-z0-9-], without markup, with a broken viewBox or an unknown paint are dropped.
	 */
	public function test_filter_drops_invalid_entries(): void {
		$path = '<path d="M1 1h2"/>';
		self::add_icons(
			array(
				'Brand'       => array( 'svg' => $path ),
				'brand mark'  => array( 'svg' => $path ),
				'brand_mark'  => array( 'svg' => $path ),
				'../brand'    => array( 'svg' => $path ),
				'2brand'      => array( 'svg' => $path ),
				'404'         => array( 'svg' => $path ),
				'brand-flat'  => array(
					'svg'     => $path,
					'viewBox' => '0 0 0 24',
				),
				'brand-neg'   => array(
					'svg'     => $path,
					'viewBox' => '0 0 24 -24',
				),
				'brand-empty' => array( 'svg' => '' ),
				'brand-box'   => array(
					'svg'     => $path,
					'viewBox' => '0 0 24" onload="x',
				),
				'brand-paint' => array(
					'svg'   => $path,
					'paint' => 'url(#x)',
				),
				'brand-text'  => $path,
				'brand-ok'    => array( 'svg' => $path ),
			)
		);
		$this->assertSame( array( 'brand-ok' ), array_keys( Icon::custom() ) );
		$this->assertArrayNotHasKey( 'Brand', Icon::icons() );
		$this->assertSame( '', Icon::svg( 'Brand' ) );
		$this->assertSame( '', Icon::svg( 'no-such-icon' ) );
	}

	/**
	 * The block catalog names the icons an agent may write: the bundled set and the icons of the filter.
	 */
	public function test_catalog_lists_the_icon_names(): void {
		self::add_icons( array( 'brand-ok' => array( 'svg' => '<path d="M1 1h2"/>' ) ) );
		$catalog = array_column( LeitviaStudio\Blocks\Catalog::get(), null, 'name' );
		foreach ( array( 'leitvia/icon', 'leitvia/icon-list', 'leitvia/icon-list-item' ) as $block ) {
			$enum = $catalog[ $block ]['attributes']['properties']['icon']['enum'];
			$this->assertSame( array_keys( Icon::icons() ), $enum, $block );
			$this->assertContains( 'brand-ok', $enum, $block );
			$this->assertContains( 'phone', $enum, $block );
		}
	}

	/**
	 * The editor gets the filter entries, not the bundled set it imports itself, before the scripts of both icon blocks.
	 */
	public function test_editor_gets_the_filter_icons(): void {
		self::add_icons( array( 'brand-ok' => array( 'svg' => '<path d="M1 1h2"/>' ) ) );
		// Other tests start a fresh script registry (a new request); the handles of the blocks come with init.
		foreach ( array( 'leitvia/icon', 'leitvia/icon-list' ) as $block ) {
			$handle = WP_Block_Type_Registry::get_instance()->get_registered( $block )->editor_script_handles[0];
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script( $handle, false, array(), LEITVIA_STUDIO_VERSION, false );
			}
		}
		Icon::editor_data();
		Icon::editor_data();
		$after = (array) wp_scripts()->get_data( Icon::EDITOR_HANDLE, 'after' );
		$this->assertCount( 1, array_filter( $after ), 'one inline script, however often the hook runs' );
		$this->assertStringContainsString( 'window.leitviaStudioIcons = {"brand-ok":{"svg":"\u003Cpath d=\\"M1 1h2\\"\/\u003E","viewBox":"0 0 24 24","paint":"stroke"}};', implode( '', $after ) );
		$this->assertStringNotContainsString( '"phone"', implode( '', $after ) );
		foreach ( array( 'leitvia/icon', 'leitvia/icon-list' ) as $block ) {
			$handle = WP_Block_Type_Registry::get_instance()->get_registered( $block )->editor_script_handles[0];
			$this->assertSame( 1, count( array_keys( wp_scripts()->query( $handle )->deps, Icon::EDITOR_HANDLE, true ) ), $block );
			$this->assertStringNotContainsString( 'leitviaStudioIcons', implode( '', (array) wp_scripts()->get_data( $handle, 'before' ) ), $block );
		}
	}
}
