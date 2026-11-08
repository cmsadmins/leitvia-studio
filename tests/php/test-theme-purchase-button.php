<?php
/**
 * The theme filter Leitvia\purchase_button_label.
 *
 * @package LeitviaStudio
 */

/**
 * The accessible name of a purchase button starts with its visible text (WCAG 2.5.3).
 */
class Test_Theme_Purchase_Button extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and loads its functions.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\purchase_button_label' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
	}

	/**
	 * The label of a button as the filter leaves it.
	 *
	 * @param string $markup Markup of the button.
	 * @return string The aria-label or an empty string.
	 */
	private function label( string $markup ): string {
		$html = new WP_HTML_Tag_Processor( Leitvia\purchase_button_label( $markup ) );
		$html->next_tag( array( 'class_name' => 'add_to_cart_button' ) );
		return (string) $html->get_attribute( 'aria-label' );
	}

	/**
	 * A label in the language of the shop that does not start with the button text becomes "text: name".
	 */
	public function test_different_label_gets_the_text_first(): void {
		$id = self::factory()->post->create(
			array(
				'post_title'  => 'Vase',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'In den Warenkorb: Vase', $this->label( '<a href="/x" aria-label="„Vase“ zum Warenkorb hinzufügen" data-product_id="' . $id . '" class="wp-block-button__link add_to_cart_button">In den Warenkorb</a>' ) );
	}

	/**
	 * Attribute order does not matter, and the text may sit in nested elements.
	 */
	public function test_attribute_order_and_nested_text(): void {
		$id = self::factory()->post->create(
			array(
				'post_title'  => 'Kissen',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'Auswählen: Kissen', $this->label( '<button class="add_to_cart_button" data-product_id="' . $id . '" aria-label="Optionen für „Kissen“"><span> Auswählen </span></button>' ) );
	}

	/**
	 * A label that already starts with the text stays as it is.
	 */
	public function test_correct_label_stays(): void {
		$this->assertSame( 'Add to cart: “Vase”', $this->label( '<a aria-label="Add to cart: “Vase”" data-product_id="1" class="add_to_cart_button">Add to cart</a>' ) );
	}

	/**
	 * Without a product the label is the visible text, the title link next to the button names the product.
	 */
	public function test_without_product_id_the_label_is_the_text(): void {
		$this->assertSame( 'In den Warenkorb', $this->label( '<a aria-label="Warenkorb: Vase" class="add_to_cart_button">In den Warenkorb</a>' ) );
	}

	/**
	 * A product that is not published does not give away its name.
	 */
	public function test_unpublished_product_name_stays_hidden(): void {
		$id = self::factory()->post->create(
			array(
				'post_title'  => 'Geheim',
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'In den Warenkorb', $this->label( '<a aria-label="Geheim kaufen" data-product_id="' . $id . '" class="add_to_cart_button">In den Warenkorb</a>' ) );
	}

	/**
	 * Several buttons are handled one by one, other links with the block button class are not touched.
	 */
	public function test_several_buttons_and_other_links(): void {
		$one  = self::factory()->post->create(
			array(
				'post_title'  => 'Eins',
				'post_status' => 'publish',
			)
		);
		$two  = self::factory()->post->create(
			array(
				'post_title'  => 'Zwei',
				'post_status' => 'publish',
			)
		);
		$html = Leitvia\purchase_button_label(
			'<a class="wp-block-button__link" aria-label="Mehr zum Shop">Shop</a>'
			. '<a class="add_to_cart_button" data-product_id="' . $one . '" aria-label="Eins kaufen">Kaufen</a>'
			. '<a class="add_to_cart_button" data-product_id="' . $two . '" aria-label="Kaufen: Zwei">Kaufen</a>'
		);
		$this->assertStringContainsString( 'aria-label="Mehr zum Shop"', $html );
		$this->assertStringContainsString( 'aria-label="Kaufen: Eins"', $html );
		$this->assertStringContainsString( 'aria-label="Kaufen: Zwei"', $html );
	}

	/**
	 * The button of the product button block changes its text in place ("1 in cart"), so it keeps no fixed label.
	 */
	public function test_interactive_button_is_named_by_its_text(): void {
		$html = Leitvia\purchase_button_label( '<button class="wc-block-components-product-button__button add_to_cart_button" data-product_id="1" aria-label="Add to cart: “Vase”" data-wp-on--click="actions.addCartItem"><span data-wp-text="state.addToCartText">Add to cart</span></button>' );
		$this->assertStringNotContainsString( 'aria-label', $html );
	}
}
