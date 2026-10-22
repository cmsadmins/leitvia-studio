<?php
/**
 * Product comparison: two to four WooCommerce products in the table of the comparison table block, rendered live.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * The block saves only product IDs, attribute keys, the caption and the highlighted product; prices, rating and
 * availability are read on every request. The markup is the one of leitvia/comparison-table (caption, column header
 * per product, row header per feature, hidden cell labels for the cards below 600 px), so its stylesheet does the
 * layout. Products that are missing, not published or password protected are left out. Without WooCommerce the block
 * is not registered (Blocks::register()), so saved blocks render nothing.
 */
final class Product_Compare {

	public const MAX = 4;

	/**
	 * Gives the block its render callback, which also serves the preview in the editor. Runs on
	 * register_block_type_args.
	 *
	 * @param array<string, mixed> $args Block type arguments.
	 * @param string               $name Block name.
	 * @return array<string, mixed>
	 */
	public static function block_args( array $args, string $name ): array {
		if ( 'leitvia/product-compare' === $name ) {
			$args['render_callback'] = array( self::class, 'render' );
		}
		return $args;
	}

	/**
	 * Renders the table.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	public static function render( array $attrs ): string {
		$products = array();
		foreach ( array_slice( array_map( 'intval', (array) ( $attrs['products'] ?? array() ) ), 0, self::MAX ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof \WC_Product && 'publish' === $product->get_status() && '' === (string) $product->get_post_password() ) {
				$products[] = $product;
			}
		}
		if ( ! $products ) {
			return '';
		}
		$highlight = (int) ( $attrs['highlight'] ?? 0 );
		$caption   = trim( (string) ( $attrs['caption'] ?? '' ) );
		$caption   = '' === $caption ? __( 'Product comparison', 'leitvia-studio' ) : $caption;

		$rows = array( array( __( 'Price', 'leitvia-studio' ), array( self::class, 'price' ) ) );
		if ( wc_review_ratings_enabled() ) {
			$rows[] = array( __( 'Rating', 'leitvia-studio' ), array( self::class, 'rating' ) );
		}
		foreach ( (array) ( $attrs['features'] ?? array() ) as $key ) {
			$key    = (string) $key;
			$rows[] = array( wc_attribute_label( $key ), static fn( \WC_Product $p ): string => self::attribute( $p, $key ) );
		}
		$rows[] = array( __( 'Availability', 'leitvia-studio' ), array( self::class, 'availability' ) );
		$rows[] = array( __( 'Buy', 'leitvia-studio' ), array( self::class, 'buy' ) );

		$cell = static fn( \WC_Product $p ): string => $p->get_id() === $highlight ? ' class="is-highlighted-column"' : '';
		$head = '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Feature', 'leitvia-studio' ) . '</span></th>';
		foreach ( $products as $p ) {
			$head .= sprintf( '<th scope="col"%s><a href="%s">%s</a></th>', $cell( $p ), esc_url( $p->get_permalink() ), esc_html( $p->get_name() ) );
		}
		$body = '';
		foreach ( $rows as list( $label, $value ) ) {
			$body .= '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $products as $p ) {
				$name  = esc_attr( $p->get_name() );
				$body .= sprintf(
					'<td data-label="%1$s"%2$s><span class="wp-block-leitvia-comparison-table__label">%1$s: </span>%3$s</td>',
					$name,
					$cell( $p ),
					$value( $p )
				);
			}
			$body .= '</tr>';
		}

		wp_enqueue_style( 'leitvia-comparison-table-style' );
		$wrapper = get_block_wrapper_attributes(
			array(
				'class'      => 'wp-block-leitvia-comparison-table',
				'tabindex'   => '0',
				'role'       => 'region',
				'aria-label' => $caption,
			)
		);
		return sprintf( '<figure %1$s><table><caption>%2$s</caption><thead><tr>%3$s</tr></thead><tbody>%4$s</tbody></table></figure>', $wrapper, esc_html( $caption ), $head, $body );
	}

	/**
	 * Price from WooCommerce (wc_price() with sale and range markup), with the tax hint of the shop settings unless a
	 * price display suffix already says it.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function price( \WC_Product $product ): string {
		$html = $product->get_price_html();
		if ( wc_tax_enabled() && '' === (string) get_option( 'woocommerce_price_display_suffix', '' ) ) {
			$hint  = 'incl' === get_option( 'woocommerce_tax_display_shop' ) ? WC()->countries->inc_tax_or_vat() : WC()->countries->ex_tax_or_vat();
			$html .= ' <small class="wp-block-leitvia-product-compare__tax">' . esc_html( $hint ) . '</small>';
		}
		return $html;
	}

	/**
	 * Average rating as text.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function rating( \WC_Product $product ): string {
		if ( ! $product->get_review_count() ) {
			return esc_html__( 'No reviews yet', 'leitvia-studio' );
		}
		/* translators: %s: average rating, for example 4.5. */
		return esc_html( sprintf( __( 'Rated %s out of 5', 'leitvia-studio' ), number_format_i18n( (float) $product->get_average_rating(), 1 ) ) );
	}

	/**
	 * Value of an attribute, or "Not specified" in the muted color of the palette when the product has none.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Attribute name or taxonomy.
	 */
	public static function attribute( \WC_Product $product, string $key ): string {
		$value = $product->get_attribute( $key );
		return '' === $value ? '<span class="has-ink-muted-color">' . esc_html__( 'Not specified', 'leitvia-studio' ) . '</span>' : esc_html( $value );
	}

	/**
	 * Stock status in the words of WooCommerce: In stock, Out of stock, On backorder.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function availability( \WC_Product $product ): string {
		$options = wc_get_product_stock_status_options();
		return esc_html( $options[ $product->get_stock_status() ] ?? '' );
	}

	/**
	 * A link that works without JavaScript and names its product: "Add <name> to cart" for a simple product that can be
	 * bought (WooCommerce adds it on the request), otherwise "View <name>" to the product page (options to choose, out
	 * of stock). The visible text keeps the short form, the product name is visually hidden.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function buy( \WC_Product $product ): string {
		$name = '<span class="screen-reader-text">' . esc_html( $product->get_name() ) . '</span>';
		$buy  = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();
		/* translators: %s: product name, visually hidden. The visible text reads "Add to cart". */
		$text = $buy ? __( 'Add %s to cart', 'leitvia-studio' ) : __( 'View %s details', 'leitvia-studio' );
		return sprintf(
			'<a class="wp-block-leitvia-product-compare__buy wp-element-button" href="%s" rel="nofollow">%s</a>',
			esc_url( $buy ? $product->add_to_cart_url() : $product->get_permalink() ),
			sprintf( esc_html( $text ), $name )
		);
	}
}
