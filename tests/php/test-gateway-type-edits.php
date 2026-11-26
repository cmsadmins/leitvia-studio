<?php
/**
 * Gateway: font role, text alignment and italics of headings and paragraphs, settings of a navigation, text and link
 * of its items and extra surfaces as ground of a group.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Block_Edit;

/**
 * Block_Edit::apply() with the fields an agent needs for a mono number, a centred heading, an italic word in a claim,
 * a menu button on every width and the links of a footer navigation.
 */
class Test_Gateway_Type_Edits extends WP_UnitTestCase {

	/**
	 * Leitvia theme with its font roles.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_clean_theme_json_cache();
	}

	/**
	 * Fresh theme.json data for the next test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_theme_json_data_theme' );
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * The attribute fontFamily takes a font role of the theme as class and attribute, null removes it, another value is refused.
	 */
	public function test_font_family_of_a_heading(): void {
		$markup  = '<!-- wp:heading --><h2 class="wp-block-heading">01</h2><!-- /wp:heading -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'fontFamily' => 'mono' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '"fontFamily":"mono"', $content );
		$this->assertStringContainsString( 'has-mono-font-family', $content );

		$content = Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'fontFamily' => null ) ) );
		$this->assertIsString( $content );
		$this->assertStringNotContainsString( 'font-family', $content );
		$this->assertStringNotContainsString( 'fontFamily', $content );

		$error = Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'fontFamily' => 'comic' ) ) );
		$this->assertWPError( $error );
	}

	/**
	 * The attribute textAlign writes style.typography.textAlign and has-text-align-<value>, swaps and removes it; an insert takes it too.
	 */
	public function test_text_align_of_heading_and_paragraph(): void {
		$markup  = '<!-- wp:paragraph {"textColor":"ink-muted"} --><p class="has-ink-muted-color has-text-color">Hi</p><!-- /wp:paragraph -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'textAlign' => 'center' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( 'center', parse_blocks( $content )[0]['attrs']['style']['typography']['textAlign'] );
		// The editor saves style before the colors.
		$this->assertLessThan( strpos( $content, '"textColor"' ), strpos( $content, '"style"' ) );
		$this->assertStringContainsString( 'has-text-align-center', $content );

		$content = Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'textAlign' => 'right' ) ) );
		$this->assertStringContainsString( 'has-text-align-right', $content );
		$this->assertStringNotContainsString( 'has-text-align-center', $content );

		$content = Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'textAlign' => null ) ) );
		$this->assertStringNotContainsString( 'textAlign', $content );
		$this->assertStringNotContainsString( 'has-text-align', $content );

		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'textAlign' => 'justify' ) ) ) );

		$group   = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$content = Block_Edit::structure(
			$group,
			'p:0.0',
			array(
				'insert' => array(
					'block'      => 'core/heading',
					'text'       => 'Contact',
					'attributes' => array( 'textAlign' => 'center' ),
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<h2 class="wp-block-heading has-text-align-center">Contact</h2>', $content );
	}

	/**
	 * The field emphasis sets the first place outside a link and italics in em, also after a new text of the same edit
	 * and in a button label.
	 */
	public function test_emphasis_in_a_claim(): void {
		$markup  = '<!-- wp:paragraph --><p>Good work for <a href="/people/">people</a> who need good people.</p><!-- /wp:paragraph -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'emphasis' => 'people' ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'Good work for <a href="/people/">people</a> who need good <em>people</em>.', $content );

		$content = Block_Edit::apply(
			'<!-- wp:heading --><h2 class="wp-block-heading">Old</h2><!-- /wp:heading -->',
			'p:0',
			array(
				'text'     => 'We make the city better',
				'emphasis' => 'city',
			)
		);
		$this->assertStringContainsString( 'We make the <em>city</em> better', $content );

		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'emphasis' => 'Berlin' ) ) );
		// A button label takes an italic word too, such as the label of a shaped button.
		$button  = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">GET in TOUCH</a></div><!-- /wp:button -->';
		$content = Block_Edit::apply( $button, 'p:0', array( 'emphasis' => 'TOUCH' ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'href="/contact/">GET in <em>TOUCH</em></a>', $content );
		$this->assertWPError( Block_Edit::apply( '<!-- wp:image --><figure class="wp-block-image"><img src="/a.jpg" alt=""/></figure><!-- /wp:image -->', 'p:0', array( 'emphasis' => 'a' ) ) );
	}

	/**
	 * The field strike sets s like emphasis sets em, both on the same words give italics struck through; emphasis with font sets the
	 * italic words in another font role; mark sets a passage in a font role, color and size as the text color format of
	 * the editor saves it. Values outside the presets and a mark without one are refused.
	 */
	public function test_strike_mark_and_emphasis_with_font(): void {
		$markup  = '<!-- wp:heading --><h2 class="wp-block-heading">Old</h2><!-- /wp:heading -->';
		$content = Block_Edit::apply(
			$markup,
			'p:0',
			array(
				'text'     => 'Less Bla Bla, more doing',
				'emphasis' => 'Bla Bla,',
				'strike'   => 'Bla Bla,',
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'Less <em><s>Bla Bla,</s></em> more doing', $content );

		$content = Block_Edit::apply(
			$markup,
			'p:0',
			array(
				'text'     => '01 Consulting for your city',
				'emphasis' => array(
					'text' => 'your',
					'font' => 'ui',
				),
				'mark'     => array(
					'text'  => '01',
					'font'  => 'mono',
					'color' => 'accent',
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-accent-color has-mono-font-family">01</mark> Consulting for <em class="has-ui-font-family">your</em> city', $content );

		$this->assertWPError( Block_Edit::apply( $content, 'p:0', array( 'mark' => array( 'text' => 'city' ) ) ), 'mark without font, color or size' );
		$this->assertWPError(
			Block_Edit::apply(
				$content,
				'p:0',
				array(
					'mark' => array(
						'text'  => 'city',
						'color' => 'red',
					),
				)
			)
		);
		$this->assertWPError(
			Block_Edit::apply(
				$content,
				'p:0',
				array(
					'emphasis' => array(
						'text'  => 'city',
						'color' => 'accent',
					),
				)
			)
		);
		$this->assertWPError( Block_Edit::apply( $content, 'p:0', array( 'strike' => 'Berlin' ) ) );
	}

	/**
	 * The attribute verticalAlignment of a columns block and a column sets its class and comment, replaces an old one and null
	 * removes both.
	 */
	public function test_vertical_alignment_of_columns(): void {
		$markup  = '<!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'verticalAlignment' => 'bottom' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<!-- wp:columns {"verticalAlignment":"bottom"} --><div class="wp-block-columns are-vertically-aligned-bottom">', $content );

		$content = Block_Edit::apply( $content, 'p:0.0', array( 'attributes' => array( 'verticalAlignment' => 'center' ) ) );
		$this->assertStringContainsString( '<!-- wp:column {"verticalAlignment":"center"} --><div class="wp-block-column is-vertically-aligned-center">', $content );

		$content = Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'verticalAlignment' => null ) ) );
		$this->assertStringContainsString( '<!-- wp:columns --><div class="wp-block-columns">', $content );
		$this->assertWPError( Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'verticalAlignment' => 'stretch' ) ) ), 'stretch on a column only' );
	}

	/**
	 * A navigation takes overlayMenu always and overlay colors in its comment; a default leaves the key out.
	 */
	public function test_navigation_overlay_settings(): void {
		$markup  = '<!-- wp:navigation {"overlayMenu":"mobile","ariaLabel":"Main"} /-->';
		$content = Block_Edit::apply(
			$markup,
			'p:0',
			array(
				'attributes' => array(
					'overlayMenu'            => 'always',
					'overlayBackgroundColor' => 'accent',
					'overlayTextColor'       => 'on-accent',
					'hasIcon'                => false,
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$attrs = parse_blocks( $content )[0]['attrs'];
		$this->assertSame( 'always', $attrs['overlayMenu'] );
		$this->assertSame( 'accent', $attrs['overlayBackgroundColor'] );
		$this->assertSame( 'on-accent', $attrs['overlayTextColor'] );
		$this->assertFalse( $attrs['hasIcon'] );
		$this->assertSame( 'Main', $attrs['ariaLabel'] );

		$content = Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'overlayMenu' => 'mobile' ) ) );
		$this->assertArrayNotHasKey( 'overlayMenu', parse_blocks( $content )[0]['attrs'] );

		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'overlayBackgroundColor' => 'red' ) ) ) );
		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'overlayBackgroundColor' => 'surface-extra-none' ) ) ) );
	}

	/**
	 * The fields text and url of a navigation link change label and url; a new url makes it a custom link.
	 */
	public function test_navigation_link_text_and_url(): void {
		$markup  = '<!-- wp:navigation {"overlayMenu":"never"} --><!-- wp:navigation-link {"label":"About","type":"page","id":12,"url":"/about/","kind":"post-type"} /--><!-- /wp:navigation -->';
		$content = Block_Edit::apply(
			$markup,
			'p:0.0',
			array(
				'text' => 'Imprint & legal',
				'url'  => '/imprint/',
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$attrs = parse_blocks( $content )[0]['innerBlocks'][0]['attrs'];
		$this->assertSame( 'Imprint &amp; legal', $attrs['label'] );
		$this->assertSame( '/imprint/', $attrs['url'] );
		$this->assertSame( 'custom', $attrs['kind'] );
		$this->assertArrayNotHasKey( 'id', $attrs );
		$this->assertArrayNotHasKey( 'type', $attrs );

		$this->assertWPError( Block_Edit::apply( $markup, 'p:0.0', array( 'text' => ' ' ) ) );
		$this->assertWPError( Block_Edit::apply( $markup, 'p:0.0', array( 'url' => 'javascript:alert(1)' ) ) );
	}

	/**
	 * A light group takes an extra surface of the token set as backgroundColor, one the set lacks is refused.
	 */
	public function test_group_takes_an_extra_surface(): void {
		add_filter(
			'wp_theme_json_data_theme',
			static fn( $data ) => $data->update_with(
				array(
					'version'  => 3,
					'settings' => array(
						'color' => array(
							'palette' => array(
								array(
									'slug'  => 'surface-extra-mint',
									'name'  => 'Mint',
									'color' => '#5ee89a',
								),
							),
						),
					),
				)
			)
		);
		wp_clean_theme_json_cache();
		$markup  = '<!-- wp:group {"backgroundColor":"surface-alt"} --><div class="wp-block-group has-surface-alt-background-color has-background"></div><!-- /wp:group -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'backgroundColor' => 'surface-extra-mint' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'has-surface-extra-mint-background-color', $content );
		$this->assertStringNotContainsString( 'has-surface-alt-background-color', $content );

		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'backgroundColor' => 'surface-extra-sky' ) ) ) );
	}
}
