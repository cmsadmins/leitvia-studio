<?php
/**
 * Product comparison block: table markup, live price with tax hint, which products show, nothing without WooCommerce.
 *
 * @package LeitviaStudio
 */

/**
 * The group woocommerce runs with WooCommerce loaded (npm run test:php:commerce), the rest without it.
 */
class Test_Product_Compare extends WP_UnitTestCase {

	/**
	 * Saved block with these attributes.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function block( array $attrs ): string {
		return '<!-- wp:leitvia/product-compare ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * A saved simple product.
	 *
	 * @param string               $name  Product name.
	 * @param array<string, mixed> $props Further props.
	 */
	private static function product( string $name, array $props = array() ): int {
		$product = new WC_Product_Simple();
		$product->set_props(
			array_merge(
				array(
					'name'          => $name,
					'status'        => 'publish',
					'regular_price' => '48.00',
				),
				$props
			)
		);
		return $product->save();
	}

	/**
	 * Without WooCommerce the block is not registered and a saved block renders nothing.
	 */
	public function test_without_woocommerce_not_registered_and_empty(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );
		$this->assertFalse( WP_Block_Type_Registry::get_instance()->is_registered( 'leitvia/product-compare' ) );
		$this->assertNull( leitvia_studio()->registry( 'blocks' )->get( 'leitvia/product-compare' ) );
		$this->assertSame( '', trim( do_blocks( self::block( array( 'products' => array( 1, 2 ) ) ) ) ) );
	}

	/**
	 * Two to four products give a captioned table with a column header per product and a row header per feature.
	 *
	 * @group woocommerce
	 */
	public function test_table_headers_for_two_to_four_products(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'leitvia/product-compare' ) );
		$ids = array( self::product( 'Oak tray' ), self::product( 'Stoneware vase' ), self::product( 'Linen cushion' ), self::product( 'Wool throw' ) );
		foreach ( array( 2, 3, 4 ) as $count ) {
			$html = do_blocks(
				self::block(
					array(
						'caption'  => 'Trays compared',
						'products' => array_slice( $ids, 0, $count ),
					)
				)
			);
			$this->assertStringContainsString( '<caption>Trays compared</caption>', $html );
			$this->assertSame( $count + 1, substr_count( $html, '<th scope="col"' ), "$count products" );
			preg_match_all( '#<th scope="row">([^<]+)</th>#', $html, $rows );
			$this->assertSame( array( 'Price', 'Rating', 'Availability', 'Buy' ), $rows[1] );
			$this->assertSame( $count * 4, substr_count( $html, '<td data-label=' ) );
		}
		$html = do_blocks( self::block( array( 'products' => $ids ) ) );
		$this->assertMatchesRegularExpression( '/<figure class="(?=[^"]*\bwp-block-leitvia-comparison-table\b)(?=[^"]*\bwp-block-leitvia-product-compare\b)[^"]*"/', $html );
		$this->assertStringContainsString( '<caption>Product comparison</caption>', $html );
		$this->assertStringContainsString( 'aria-label="Product comparison" tabindex="0" role="region"', $html );
	}

	/**
	 * The price is WooCommerce's (wc_price()), followed by the tax hint while taxes are on.
	 *
	 * @group woocommerce
	 */
	public function test_price_from_wc_price_with_tax_hint(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		update_option( 'woocommerce_default_country', 'DE' );
		$a    = self::product( 'Oak tray', array( 'regular_price' => '64.00' ) );
		$b    = self::product(
			'Linen cushion',
			array(
				'regular_price' => '39.00',
				'sale_price'    => '29.00',
			)
		);
		$html = do_blocks( self::block( array( 'products' => array( $a, $b ) ) ) );
		$this->assertStringContainsString( wc_price( 64 ), $html );
		$this->assertStringContainsString( wc_get_product( $b )->get_price_html(), $html );
		$this->assertSame( 2, substr_count( $html, '<small class="wp-block-leitvia-product-compare__tax">(incl. VAT)</small>' ) );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->assertStringNotContainsString( '__tax', do_blocks( self::block( array( 'products' => array( $a, $b ) ) ) ) );
	}

	/**
	 * Missing, draft and password protected products drop out; private products never show, not even to an admin. At
	 * most four products count, as the editor allows.
	 *
	 * @group woocommerce
	 */
	public function test_only_published_products(): void {
		$shown  = self::product( 'Oak tray' );
		$other  = self::product( 'Stoneware vase' );
		$hidden = array(
			self::product( 'Draft lamp', array( 'status' => 'draft' ) ),
			self::product( 'Private bowl', array( 'status' => 'private' ) ),
			self::product( 'Locked jug', array( 'post_password' => 'secret' ) ),
			999999,
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = do_blocks( self::block( array( 'products' => array( $shown, $hidden[0], $other, $hidden[1] ) ) ) );
		$this->assertStringContainsString( '>Oak tray</a></th>', $html );
		$this->assertStringContainsString( '>Stoneware vase</a></th>', $html );
		$this->assertSame( 3, substr_count( $html, '<th scope="col"' ) );
		$html .= do_blocks( self::block( array( 'products' => array( $hidden[2], $hidden[3], $shown ) ) ) );
		foreach ( array( 'Draft lamp', 'Private bowl', 'Locked jug' ) as $name ) {
			$this->assertStringNotContainsString( $name, $html );
		}
		$five = array( $shown, $other, self::product( 'A' ), self::product( 'B' ), self::product( 'C' ) );
		$this->assertSame( 5, substr_count( do_blocks( self::block( array( 'products' => $five ) ) ), '<th scope="col"' ) );
		$this->assertSame( '', trim( do_blocks( self::block( array( 'products' => $hidden ) ) ) ) );
	}

	/**
	 * Attribute rows with "Not specified" for a missing value, availability, the highlighted column and add to cart links that name their product.
	 *
	 * @group woocommerce
	 */
	public function test_attributes_availability_highlight_and_links(): void {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Material' );
		$attribute->set_options( array( 'Oak' ) );
		$attribute->set_visible( true );
		$tray = self::product( 'Oak tray', array( 'attributes' => array( $attribute ) ) );
		$wool = self::product( 'Wool throw', array( 'stock_status' => 'outofstock' ) );
		$html = do_blocks(
			self::block(
				array(
					'products'  => array( $tray, $wool ),
					'features'  => array( 'Material' ),
					'highlight' => $wool,
				)
			)
		);
		$this->assertStringContainsString( '<th scope="row">Material</th><td data-label="Oak tray"><span class="wp-block-leitvia-comparison-table__label">Oak tray: </span>Oak</td>', $html );
		// A product without the attribute says so instead of leaving the cell empty.
		$this->assertStringContainsString( '<td data-label="Wool throw" class="is-highlighted-column"><span class="wp-block-leitvia-comparison-table__label">Wool throw: </span><span class="has-ink-muted-color">Not specified</span></td>', $html );
		$this->assertStringContainsString( '<span class="wp-block-leitvia-comparison-table__label">Oak tray: </span>In stock</td>', $html );
		$this->assertStringContainsString( '<td data-label="Wool throw" class="is-highlighted-column"><span class="wp-block-leitvia-comparison-table__label">Wool throw: </span>Out of stock</td>', $html );
		$this->assertStringContainsString( 'href="?add-to-cart=' . $tray . '" rel="nofollow">Add <span class="screen-reader-text">Oak tray</span> to cart</a>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $wool ) ) . '" rel="nofollow">View <span class="screen-reader-text">Wool throw</span> details</a>', $html );
		// The column header and the five cells of the column.
		$this->assertSame( 6, substr_count( $html, 'class="is-highlighted-column"' ) );
	}
}
