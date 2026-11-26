<?php
/**
 * Gateway: the word on a menu button, the width of a column, the size of a button, line breaks in a text, italics in a
 * linked title, an image as a new block and extra surfaces in the token check of template parts.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Mega_Menu;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Lab\Token_Lint;

/**
 * Block_Edit::apply(), Block_Edit::structure() and Token_Lint with the edits of a page in a grid look: MENU on the
 * menu button, a column of two thirds, a larger shape button, a claim over three lines, a linked title in italics and
 * the logo mark in the foot of a menu.
 */
class Test_Gateway_Layout_Edits extends WP_UnitTestCase {

	/**
	 * Leitvia theme with an extra surface in the token set.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
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
	 * An extra surface of the token set is a preset of the theme for the token check, also in a template part; a
	 * surface the set lacks stays a finding.
	 */
	public function test_token_check_knows_the_extra_surfaces(): void {
		$rules = array_column( Token_Lint::lint_markup( '<!-- wp:group {"backgroundColor":"surface-extra-mint"} --><div class="wp-block-group has-surface-extra-mint-background-color has-background"></div><!-- /wp:group -->' ), 'rule' );
		$this->assertNotContains( 'no-unknown-preset', $rules );
		$rules = array_column( Token_Lint::lint_markup( '<!-- wp:group {"backgroundColor":"surface-extra-sky"} --><div class="wp-block-group"></div><!-- /wp:group -->' ), 'rule' );
		$this->assertContains( 'no-unknown-preset', $rules );
	}

	/**
	 * The field text on a navigation puts its word on the menu button and switches the icon off; the render shows it. A menu that
	 * opens the part navigation-overlay refuses overlay colors, which core ignores there.
	 */
	public function test_word_on_the_menu_button(): void {
		$markup  = '<!-- wp:navigation {"overlayMenu":"always","overlay":"navigation-overlay","overlayBackgroundColor":"surface","ariaLabel":"Main"} /-->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'text' => 'MENU' ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$attrs = parse_blocks( $content )[0]['attrs'];
		$this->assertSame( 'MENU', $attrs['leitviaMenuText'] );
		$this->assertFalse( $attrs['hasIcon'] );
		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'text' => str_repeat( 'x', 31 ) ) ) );
		$this->assertWPError( Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'overlayBackgroundColor' => 'surface-extra-mint' ) ) ) );
		$this->assertIsString( Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'overlayMenu' => 'mobile' ) ) ) );

		$button = '<nav class="wp-block-navigation"><button aria-haspopup="dialog" class="wp-block-navigation__responsive-container-open always-shown">Menu</button></nav>';
		$this->assertStringContainsString( 'always-shown">MENU</button>', Mega_Menu::menu_text( $button, parse_blocks( $content )[0] ) );
		$this->assertSame( $button, Mega_Menu::menu_text( $button, array( 'attrs' => array( 'leitviaMenuText' => 'MENU' ) ) ), 'with the icon the button keeps it' );
	}

	/**
	 * The attribute width on a column writes the attribute and flex-basis, keeps other styles; null removes both; no other unit.
	 */
	public function test_width_of_a_column(): void {
		$markup  = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"33.33%","style":{"spacing":{"padding":{"top":"var:preset|spacing|s"}}}} --><div class="wp-block-column" style="padding-top:var(--wp--preset--spacing--s);flex-basis:33.33%"></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$content = Block_Edit::apply( $markup, 'p:0.0', array( 'attributes' => array( 'width' => '75%' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<!-- wp:column {"width":"75%","style"', $content );
		$this->assertStringContainsString( 'style="padding-top:var(--wp--preset--spacing--s);flex-basis:75%"', $content );

		$content = Block_Edit::apply( $content, 'p:0.1', array( 'attributes' => array( 'width' => '25%' ) ) );
		$this->assertStringContainsString( '<!-- wp:column {"width":"25%"} --><div class="wp-block-column" style="flex-basis:25%">', $content );

		$content = Block_Edit::apply( $content, 'p:0.0', array( 'attributes' => array( 'width' => null ) ) );
		$this->assertStringNotContainsString( '"width":"75%"', $content );
		$this->assertStringContainsString( 'style="padding-top:var(--wp--preset--spacing--s)"', $content );

		$this->assertWPError( Block_Edit::apply( $markup, 'p:0.0', array( 'attributes' => array( 'width' => '300px' ) ) ) );
	}

	/**
	 * The attribute fontSize on a button: the size class on its link, has-custom-font-size on its wrapper, as the editor saves them.
	 */
	public function test_size_of_a_button(): void {
		$markup  = '<!-- wp:button {"className":"is-style-shape-triangle"} --><div class="wp-block-button is-style-shape-triangle"><a class="wp-block-button__link wp-element-button" href="/contact/">Get in touch</a></div><!-- /wp:button -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'attributes' => array( 'fontSize' => 'body' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( 'body', parse_blocks( $content )[0]['attrs']['fontSize'] );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*has-custom-font-size[^"]*"><a class="[^"]*has-body-font-size#', $content );

		$content = Block_Edit::apply( $content, 'p:0', array( 'attributes' => array( 'fontSize' => null ) ) );
		$this->assertStringNotContainsString( 'font-size', $content );
	}

	/**
	 * A new line or <br> in text becomes a br, other markup stays text; a link edit keeps the breaks.
	 */
	public function test_line_breaks_in_a_text(): void {
		$markup  = '<!-- wp:heading --><h2 class="wp-block-heading">Old</h2><!-- /wp:heading -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'text' => "Brand\nyour<br/>Story <b>now</b>" ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '>Brand<br>your<br>Story &lt;b&gt;now&lt;/b&gt;</h2>', $content );

		$button  = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/old/">Go</a></div><!-- /wp:button -->';
		$content = Block_Edit::apply( $button, 'p:0', array( 'text' => "GET\nin\nTOUCH" ) );
		$this->assertStringContainsString( '>GET<br>in<br>TOUCH</a>', $content );
		$content = Block_Edit::apply( $content, 'p:0', array( 'url' => '/contact/' ) );
		$this->assertStringContainsString( 'href="/contact/">GET<br>in<br>TOUCH</a>', $content );
	}

	/**
	 * The field emphasis finds words inside a link when they stand nowhere outside one: a title linked as a whole.
	 */
	public function test_emphasis_inside_a_link(): void {
		$markup  = '<!-- wp:heading --><h2 class="wp-block-heading"><a href="/consulting/">Consulting</a></h2><!-- /wp:heading -->';
		$content = Block_Edit::apply( $markup, 'p:0', array( 'emphasis' => 'Consulting' ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<a href="/consulting/"><em>Consulting</em></a>', $content );
	}

	/**
	 * An insert builds a core/image from an image of the media library; without image it is refused.
	 */
	public function test_insert_an_image(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id      = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$markup  = '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-logo {"width":32} /--></div><!-- /wp:group -->';
		$content = Block_Edit::structure(
			$markup,
			'p:0.0',
			array(
				'insert'   => array(
					'block' => 'core/image',
					'image' => array(
						'id'         => $id,
						'decorative' => true,
					),
				),
				'position' => 'before',
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$image = parse_blocks( $content )[0]['innerBlocks'][0];
		$this->assertSame( 'core/image', $image['blockName'] );
		$this->assertSame( $id, $image['attrs']['id'] );
		$this->assertStringContainsString( 'wp-image-' . $id, $image['innerHTML'] );
		$this->assertStringContainsString( 'alt=""', $image['innerHTML'] );

		$this->assertWPError( Block_Edit::structure( $markup, 'p:0.0', array( 'insert' => array( 'block' => 'core/image' ) ) ) );
	}
}
