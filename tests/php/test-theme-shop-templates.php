<?php
/**
 * The theme filter Leitvia\hide_shop_templates_without_woocommerce.
 *
 * @package LeitviaStudio
 */

/**
 * The shop templates are listed only while WooCommerce runs (WordPress.org theme review, ticket #300777).
 */
class Test_Theme_Shop_Templates extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and loads its functions.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\hide_shop_templates_without_woocommerce' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
	}

	/**
	 * Templates with the given slugs.
	 *
	 * @param string ...$slugs Slugs.
	 * @return WP_Block_Template[]
	 */
	private function templates( string ...$slugs ): array {
		return array_map(
			function ( string $slug ): WP_Block_Template {
				$template       = new WP_Block_Template();
				$template->slug = $slug;
				return $template;
			},
			$slugs
		);
	}

	/**
	 * Slugs left by the filter.
	 *
	 * @param WP_Block_Template[] $templates     Templates.
	 * @param string              $template_type wp_template or wp_template_part.
	 * @return string[]
	 */
	private function slugs( array $templates, string $template_type ): array {
		return wp_list_pluck( Leitvia\hide_shop_templates_without_woocommerce( $templates, array(), $template_type ), 'slug' );
	}

	/**
	 * Without WooCommerce the six shop templates leave the list, the others stay.
	 */
	public function test_shop_templates_hidden_without_woocommerce(): void {
		$all = $this->templates( 'index', 'archive-product', 'single-product', 'product-search-results', 'order-confirmation', 'page-cart', 'page-checkout', 'page' );
		if ( function_exists( 'WC' ) ) {
			$this->assertCount( 8, $this->slugs( $all, 'wp_template' ) );
			return;
		}
		$this->assertSame( array( 'index', 'page' ), $this->slugs( $all, 'wp_template' ) );
	}

	/**
	 * Template parts are not touched, even one named like a shop template.
	 */
	public function test_template_parts_untouched(): void {
		$this->assertSame( array( 'header-shop', 'page-cart' ), $this->slugs( $this->templates( 'header-shop', 'page-cart' ), 'wp_template_part' ) );
	}
}
