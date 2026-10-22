<?php
/**
 * Commerce abilities: products, their categories and tags, and merchandising.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/list-products, leitvia/get-product, leitvia/update-product, leitvia/update-product-category and
 * leitvia/set-merchandising, registered only while WooCommerce is active. Writes go through Pipeline::run() in the
 * area products (default "draft"); the pipeline writes products with the CRUD of WooCommerce (save()). Prices, taxes
 * and shipping are content kinds and land at most as a suggestion unless a person unlocked them. Orders, refunds,
 * coupons and customers are out of scope; nothing here reads sales or customer figures.
 */
final class Commerce {

	/**
	 * Area of the abilities and its level in the profile "standard".
	 */
	public const AREA = 'products';

	public const LEVEL = 'draft';

	/**
	 * Props of a product in the state of the pipeline, read and written through WC_Product getters and setters.
	 */
	public const PROPS = array( 'image_id', 'gallery_image_ids', 'category_ids', 'tag_ids', 'regular_price', 'sale_price', 'tax_status', 'tax_class', 'shipping_class_id', 'featured', 'upsell_ids', 'cross_sell_ids' );

	/**
	 * Props of a variation; the others belong to its parent.
	 */
	public const VARIATION_PROPS = array( 'image_id', 'regular_price', 'sale_price', 'tax_class', 'shipping_class_id' );

	/**
	 * Content kinds by the props that make them.
	 */
	public const KINDS = array(
		'prices'   => array( 'regular_price', 'sale_price' ),
		'tax'      => array( 'tax_status', 'tax_class' ),
		'shipping' => array( 'shipping_class_id' ),
	);

	/**
	 * Props holding IDs.
	 */
	private const ID_LISTS = array( 'gallery_image_ids', 'category_ids', 'tag_ids', 'upsell_ids', 'cross_sell_ids' );

	/**
	 * Registers the abilities when WooCommerce is active. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		if ( ! Scope::commerce() ) {
			return;
		}
		$id      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$ids     = array(
			'type'     => 'array',
			'maxItems' => 20,
			'items'    => $id,
		);
		$image   = array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( 'Attachment ID of an image with alt text, 0 to remove.', 'leitvia-studio' ),
		);
		$price   = array(
			'type'        => 'string',
			'pattern'     => '^([0-9]+([.][0-9]+)?)?$',
			'maxLength'   => 20,
			'description' => __( 'Price as a decimal number with a dot, empty for none. A price change lands at most as a suggestion.', 'leitvia-studio' ),
		);
		$string  = array( 'type' => 'string' );
		$objects = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$set     = array(
			'destructive' => true,
			'idempotent'  => false,
		);
		Abilities::read(
			'leitvia/list-products',
			__( 'List products', 'leitvia-studio' ),
			__( 'Lists the products of the shop, 20 per page, with title, type, status, price (read only), stock status, featured flag and hash, plus all product categories with their hash. Filter by category or search text. Orders, coupons and customers are never listed.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'category' => $id,
					'search'   => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'page'     => $id,
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'products'   => $objects,
					'total'      => array( 'type' => 'integer' ),
					'pages'      => array( 'type' => 'integer' ),
					'categories' => $objects,
				),
			),
			static fn( $input ) => self::list( is_array( $input ) ? $input : array() )
		);
		Abilities::read(
			'leitvia/get-product',
			__( 'Get product', 'leitvia-studio' ),
			__( 'Returns one product or variation: texts, images with alt text, categories and tags with their hash, attributes, price (read only), tax and shipping class, stock status, merchandising and the hash to use as base_hash. No sales, order or customer figures.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'product' => $id ),
				'required'             => array( 'product' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'                => array( 'type' => 'integer' ),
					'type'              => $string,
					'title'             => $string,
					'description'       => $string,
					'short_description' => $string,
					'images'            => $objects,
					'categories'        => $objects,
					'tags'              => $objects,
					'attributes'        => $objects,
					'price'             => array( 'type' => 'object' ),
					'stock_status'      => $string,
					'hash'              => $string,
				),
			),
			static fn( $input ) => self::get( (int) ( $input['product'] ?? 0 ) )
		);
		Abilities::write(
			'leitvia/update-product',
			__( 'Update product', 'leitvia-studio' ),
			__( 'Updates texts, status, images, categories and tags of one product or variation, and its prices, tax and shipping class. Read the product with leitvia/get-product first and send its hash as base_hash. Images need alt text (set it with leitvia/set-alt-text first). Changes to prices, taxes or shipping land at most as a suggestion unless a person unlocked them.', 'leitvia-studio' ),
			'maintain',
			$set,
			array(
				'properties'           => array(
					'product'           => $id,
					'title'             => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'description'       => array(
						'type'      => 'string',
						'maxLength' => 20000,
					),
					'short_description' => array(
						'type'      => 'string',
						'maxLength' => 5000,
					),
					'status'            => array(
						'type' => 'string',
						'enum' => array( 'draft', 'pending', 'publish' ),
					),
					'image'             => $image,
					'gallery'           => $ids,
					'categories'        => $ids,
					'tags'              => $ids,
					'regular_price'     => $price,
					'sale_price'        => $price,
					'tax_status'        => array(
						'type' => 'string',
						'enum' => array( 'taxable', 'shipping', 'none' ),
					),
					'tax_class'         => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'shipping_class'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Term ID of the shipping class, 0 for none.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'product' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update_product' )
		);
		Abilities::write(
			'leitvia/update-product-category',
			__( 'Update product category', 'leitvia-studio' ),
			__( 'Updates name, description, parent and image of a product category, or name and description of a product tag. Use the hash of the term from list-products or get-product as base_hash. The image needs alt text.', 'leitvia-studio' ),
			'maintain',
			$set,
			array(
				'properties'           => array(
					'term'        => $id,
					'name'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'description' => array(
						'type'      => 'string',
						'maxLength' => 5000,
					),
					'parent'      => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'image'       => $image,
				),
				'required'             => array( 'term' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update_term' )
		);
		Abilities::write(
			'leitvia/set-merchandising',
			__( 'Set merchandising', 'leitvia-studio' ),
			__( 'Sets the featured flag, the upsells, the cross-sells and the sort order of one product. The lists replace the current ones. Read the product with leitvia/get-product first and send its hash as base_hash; product IDs come from leitvia/list-products.', 'leitvia-studio' ),
			'maintain',
			$set,
			array(
				'properties'           => array(
					'product'     => $id,
					'featured'    => array( 'type' => 'boolean' ),
					'upsells'     => $ids,
					'cross_sells' => $ids,
					'menu_order'  => array( 'type' => 'integer' ),
				),
				'required'             => array( 'product' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_merchandising' )
		);
	}

	/**
	 * Filter leitvia_studio_gateway_areas: the level of the area products while WooCommerce is active, "draft" in the
	 * profile "standard", "autonomous" in "full", an override of a person wins.
	 *
	 * @param array<string, string> $levels   Level by area.
	 * @param array<string, mixed>  $settings Gateway settings.
	 * @return array<string, string>
	 */
	public static function areas( $levels, $settings ): array {
		$levels = (array) $levels;
		if ( Scope::commerce() ) {
			$levels[ self::AREA ] = (string) ( $settings['overrides']['areas'][ self::AREA ] ?? ( 'full' === ( $settings['profile'] ?? '' ) ? 'autonomous' : self::LEVEL ) );
		}
		return $levels;
	}

	/**
	 * Filter leitvia_studio_gateway_content_kind: prices, tax and shipping when the operation changes their props.
	 *
	 * @param string[]             $kinds  Content kinds.
	 * @param mixed                $op     Operation.
	 * @param array<string, mixed> $before State before.
	 * @param array<string, mixed> $after  State after.
	 * @return string[]
	 */
	public static function kinds( $kinds, $op, $before, $after ): array {
		unset( $op );
		$kinds = (array) $kinds;
		$old   = (array) ( $before['product'] ?? array() );
		$new   = (array) ( $after['product'] ?? array() );
		foreach ( self::KINDS as $kind => $props ) {
			$keys = array_flip( $props );
			// Loose: a stored state went through JSON, and 48.00 is 48.
			if ( array_intersect_key( $old, $keys ) != array_intersect_key( $new, $keys ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
				$kinds[] = $kind;
			}
		}
		return $kinds;
	}

	/**
	 * Whether the pipeline handles posts of this type as products.
	 *
	 * @param string $post_type Post type.
	 */
	public static function handles( string $post_type ): bool {
		return Scope::commerce() && in_array( $post_type, Scope::COMMERCE_TYPES, true );
	}

	/**
	 * Props of a product for the state and the hash, PROPS or VARIATION_PROPS as stored; an empty array without one.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>
	 */
	public static function props( int $id ): array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}
		$props = array();
		foreach ( $product->is_type( 'variation' ) ? self::VARIATION_PROPS : self::PROPS as $prop ) {
			$value          = $product->{'get_' . $prop}( 'edit' );
			$props[ $prop ] = match ( true ) {
				in_array( $prop, self::ID_LISTS, true )                => array_map( 'intval', array_values( (array) $value ) ),
				in_array( $prop, array( 'image_id', 'shipping_class_id' ), true ) => (int) $value,
				'featured' === $prop                                   => (bool) $value,
				default                                                => is_scalar( $value ) ? (string) $value : '',
			};
		}
		return $props;
	}

	/**
	 * Writes the state of a product with the CRUD of WooCommerce: post fields as name, description, short description,
	 * status, slug and order (a variation takes its texts from the parent), then the props. Called by the pipeline.
	 *
	 * @param int                  $id    Product ID.
	 * @param array<string, mixed> $state State, see Pipeline::state().
	 * @return int|\WP_Error Product ID.
	 */
	public static function save( int $id, array $state ) {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'leitvia_execution_failed' );
		}
		$props = (array) ( $state['product'] ?? array() );
		if ( ! $product->is_type( 'variation' ) ) {
			$props += array(
				'name'              => (string) $state['post_title'],
				'description'       => (string) $state['post_content'],
				'short_description' => (string) $state['post_excerpt'],
				'status'            => (string) $state['post_status'],
				'slug'              => (string) $state['post_name'],
				'menu_order'        => (int) $state['menu_order'],
			);
		}
		$set = $product->set_props( $props );
		return is_wp_error( $set ) ? $set : $product->save();
	}

	/**
	 * Output of list-products.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function list( array $input ): array {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
			'has_password'   => false,
			'posts_per_page' => 20,
			'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);
		if ( '' !== (string) ( $input['search'] ?? '' ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['category'] ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filter of the agent.
				array(
					'taxonomy' => 'product_cat',
					'terms'    => (int) $input['category'],
				),
			);
		}
		$query    = new \WP_Query( $args );
		$products = array();
		foreach ( wp_parse_id_list( (array) $query->posts ) as $post_id ) {
			$product = Scope::readable( $post_id ) ? wc_get_product( $post_id ) : null;
			if ( $product instanceof \WC_Product ) {
				$products[] = array(
					'id'           => $product->get_id(),
					'title'        => $product->get_name(),
					'type'         => $product->get_type(),
					'status'       => $product->get_status(),
					'price'        => $product->get_price( 'edit' ),
					'stock_status' => $product->get_stock_status(),
					'featured'     => $product->get_featured(),
					'link'         => (string) get_permalink( $product->get_id() ),
					'hash'         => Hashes::document( $product->get_id() ),
				);
			}
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		return array(
			'products'   => $products,
			'total'      => (int) $query->found_posts,
			'pages'      => (int) $query->max_num_pages,
			'categories' => is_array( $terms ) ? array_map( array( self::class, 'term' ), $terms ) : array(),
		);
	}

	/**
	 * Output of get-product, or leitvia_out_of_scope without content for anything but a product in scope.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( int $id ) {
		$post    = get_post( $id );
		$product = $post && in_array( $post->post_type, Scope::COMMERCE_TYPES, true ) && Scope::readable( $post ) ? wc_get_product( $post ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return self::out_of_scope( array( 'post' => $id ) );
		}
		$attributes = array();
		foreach ( $product->get_attributes() as $key => $attribute ) {
			$attributes[] = $attribute instanceof \WC_Product_Attribute ? array(
				'name'      => wc_attribute_label( $attribute->get_name() ),
				'options'   => $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			) : array(
				'name'    => wc_attribute_label( (string) $key ),
				'options' => array( (string) $attribute ),
			);
		}
		$terms = static fn( array $ids ): array => array_values( array_filter( array_map( static fn( int $term ) => ( get_term( $term ) instanceof \WP_Term ) ? self::term( get_term( $term ) ) : null, $ids ) ) );
		$props = self::props( $product->get_id() );
		return array(
			'id'                => $product->get_id(),
			'type'              => $product->get_type(),
			'parent'            => $product->get_parent_id(),
			'status'            => $product->get_status(),
			'title'             => $product->get_name(),
			'slug'              => $product->get_slug(),
			'link'              => (string) get_permalink( $product->get_id() ),
			'description'       => $product->get_description( 'edit' ),
			'short_description' => $product->get_short_description( 'edit' ),
			'images'            => array_map( array( self::class, 'image' ), array_values( array_filter( array_merge( array( (int) $product->get_image_id( 'edit' ) ), array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) ) ) ) ),
			'categories'        => $terms( (array) ( $props['category_ids'] ?? array() ) ),
			'tags'              => $terms( (array) ( $props['tag_ids'] ?? array() ) ),
			'attributes'        => $attributes,
			'price'             => array(
				'price'    => $product->get_price( 'edit' ),
				'regular'  => $product->get_regular_price( 'edit' ),
				'sale'     => $product->get_sale_price( 'edit' ),
				'currency' => get_woocommerce_currency(),
			),
			'tax_status'        => $product->get_tax_status( 'edit' ),
			'tax_class'         => $product->get_tax_class( 'edit' ),
			'shipping_class'    => $product->get_shipping_class_id( 'edit' ),
			'stock_status'      => $product->get_stock_status( 'edit' ),
			'featured'          => $product->get_featured( 'edit' ),
			'upsells'           => array_map( 'intval', $product->get_upsell_ids( 'edit' ) ),
			'cross_sells'       => array_map( 'intval', $product->get_cross_sell_ids( 'edit' ) ),
			'variations'        => $product->is_type( 'variable' ) ? array_map( 'intval', $product->get_children() ) : array(),
			'hash'              => Hashes::document( $product->get_id() ),
		);
	}

	/**
	 * Operation of update-product.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_product( array $input ) {
		$id     = (int) ( $input['product'] ?? 0 );
		$target = array( 'post' => $id );
		$fields = array_filter(
			array(
				'post_title'   => $input['title'] ?? null,
				'post_content' => $input['description'] ?? null,
				'post_excerpt' => $input['short_description'] ?? null,
				'post_status'  => $input['status'] ?? null,
			),
			'is_string'
		);
		$props  = array_filter(
			array(
				'image_id'          => $input['image'] ?? null,
				'gallery_image_ids' => $input['gallery'] ?? null,
				'category_ids'      => $input['categories'] ?? null,
				'tag_ids'           => $input['tags'] ?? null,
				'regular_price'     => $input['regular_price'] ?? null,
				'sale_price'        => $input['sale_price'] ?? null,
				'tax_status'        => $input['tax_status'] ?? null,
				'tax_class'         => $input['tax_class'] ?? null,
				'shipping_class_id' => $input['shipping_class'] ?? null,
			),
			static fn( $value ): bool => null !== $value
		);
		if ( ! $fields && ! $props ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: texts, status, images, categories, tags, prices, tax or shipping class.', 'leitvia-studio' ), $target );
		}
		$invalid = self::invalid_props( $props, $target );
		if ( $invalid ) {
			return $invalid;
		}
		return array(
			'area'   => self::AREA,
			'target' => $target,
			'record' => true,
			'change' => static fn( array $state ) => self::change( $state, $fields, $props, $target ),
		);
	}

	/**
	 * Operation of set-merchandising.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_merchandising( array $input ) {
		$id     = (int) ( $input['product'] ?? 0 );
		$target = array( 'post' => $id );
		$props  = array_filter(
			array(
				'featured'       => $input['featured'] ?? null,
				'upsell_ids'     => $input['upsells'] ?? null,
				'cross_sell_ids' => $input['cross_sells'] ?? null,
			),
			static fn( $value ): bool => null !== $value
		);
		$fields = isset( $input['menu_order'] ) ? array( 'menu_order' => (int) $input['menu_order'] ) : array();
		if ( ! $props && ! $fields ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: featured, upsells, cross-sells or menu_order.', 'leitvia-studio' ), $target );
		}
		foreach ( array_merge( (array) ( $props['upsell_ids'] ?? array() ), (array) ( $props['cross_sell_ids'] ?? array() ) ) as $linked ) {
			$post = get_post( (int) $linked );
			if ( (int) $linked === $id || ! $post || 'product' !== $post->post_type || ! Scope::readable( $post ) ) {
				return Block_Edit::invalid( 'products', __( 'Upsells and cross-sells must be other products of the shop.', 'leitvia-studio' ), $target, 'leitvia/list-products' );
			}
		}
		return array(
			'area'   => self::AREA,
			'target' => $target,
			'record' => true,
			'change' => static fn( array $state ) => self::change( $state, $fields, $props, $target ),
		);
	}

	/**
	 * Operation of update-product-category.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_term( array $input ) {
		$id     = (int) ( $input['term'] ?? 0 );
		$target = array( 'term' => $id );
		$term   = get_term( $id );
		if ( ! $term instanceof \WP_Term || ! Scope::term_readable( $id ) ) {
			return self::out_of_scope( $target );
		}
		$fields = array_filter(
			array(
				'name'         => $input['name'] ?? null,
				'description'  => $input['description'] ?? null,
				'parent'       => $input['parent'] ?? null,
				'thumbnail_id' => $input['image'] ?? null,
			),
			static fn( $value ): bool => null !== $value
		);
		if ( ! $fields ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: name, description, parent or image.', 'leitvia-studio' ), $target );
		}
		$hierarchical = is_taxonomy_hierarchical( $term->taxonomy );
		if ( isset( $fields['parent'] ) && ( ! $hierarchical || ( $fields['parent'] && ( $fields['parent'] === $id || ! term_exists( (int) $fields['parent'], $term->taxonomy ) ) ) ) ) {
			return Block_Edit::invalid( 'parent', __( 'The parent must be another category of the same kind, or 0. Tags have no parent.', 'leitvia-studio' ), $target );
		}
		if ( isset( $fields['thumbnail_id'] ) ) {
			if ( ! in_array( 'thumbnail_id', Pipeline::TERM_META[ $term->taxonomy ] ?? array(), true ) ) {
				return Block_Edit::invalid( 'image', __( 'Only product categories have an image.', 'leitvia-studio' ), $target );
			}
			$invalid = self::invalid_images( array( (int) $fields['thumbnail_id'] ), $target );
			if ( $invalid ) {
				return $invalid;
			}
			$fields['thumbnail_id'] = $fields['thumbnail_id'] ? (string) $fields['thumbnail_id'] : '';
		}
		return array(
			'area'   => self::AREA,
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $fields ): array {
				$state['term'] = $fields + (array) $state['term'];
				return $state;
			},
		);
	}

	/**
	 * New state of a product: the post fields and props over the current ones. A target that is no product is out of
	 * scope; a prop a variation does not have is invalid.
	 *
	 * @param array<string, mixed> $state  State.
	 * @param array<string, mixed> $fields Post fields.
	 * @param array<string, mixed> $props  Props.
	 * @param array<string, mixed> $target Target.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function change( array $state, array $fields, array $props, array $target ) {
		if ( ! isset( $state['product'] ) ) {
			return self::out_of_scope( $target );
		}
		$unknown = array_diff_key( $props, $state['product'] );
		if ( $unknown ) {
			/* translators: %s: names of the fields */
			return Block_Edit::invalid( 'variation', sprintf( __( 'A variation takes these from its parent product: %s.', 'leitvia-studio' ), implode( ', ', array_keys( $unknown ) ) ), $target );
		}
		$state['product'] = $props + $state['product'];
		return $fields + $state;
	}

	/**
	 * A rejection when an image, category, tag, tax class or shipping class of the props does not exist, else null.
	 *
	 * @param array<string, mixed> $props  Props.
	 * @param array<string, mixed> $target Target.
	 */
	private static function invalid_props( array $props, array $target ): ?\WP_Error {
		$images = array_merge( isset( $props['image_id'] ) ? array( (int) $props['image_id'] ) : array(), (array) ( $props['gallery_image_ids'] ?? array() ) );
		$failed = self::invalid_images( $images, $target );
		if ( $failed ) {
			return $failed;
		}
		$taxonomies = array(
			'category_ids'      => 'product_cat',
			'tag_ids'           => 'product_tag',
			'shipping_class_id' => 'product_shipping_class',
		);
		foreach ( $taxonomies as $prop => $taxonomy ) {
			foreach ( array_filter( (array) ( $props[ $prop ] ?? array() ) ) as $term ) {
				if ( ! term_exists( (int) $term, $taxonomy ) ) {
					/* translators: %s: taxonomy name */
					return Block_Edit::invalid( 'term', sprintf( __( 'A term ID does not exist in %s.', 'leitvia-studio' ), $taxonomy ), $target, 'leitvia/list-products' );
				}
			}
		}
		if ( isset( $props['tax_class'] ) && ! in_array( $props['tax_class'], array_merge( array( '', 'parent' ), \WC_Tax::get_tax_class_slugs() ), true ) ) {
			return Block_Edit::invalid( 'tax_class', __( 'Unknown tax class. Use the slug of a tax class of the shop, or empty for the standard rate.', 'leitvia-studio' ), $target );
		}
		return null;
	}

	/**
	 * A rejection when an image is no image of the media library or fails the alt text check, else null. Product
	 * images are never decorative, so an empty alt text fails too. 0 removes an image and passes.
	 *
	 * @param int[]                $images Attachment IDs.
	 * @param array<string, mixed> $target Target.
	 */
	private static function invalid_images( array $images, array $target ): ?\WP_Error {
		foreach ( array_filter( $images ) as $image ) {
			if ( ! wp_attachment_is_image( $image ) || ! Scope::readable( $image ) ) {
				return Block_Edit::invalid( 'image', __( 'The ID is no image of the media library.', 'leitvia-studio' ), $target );
			}
			$alt      = trim( (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) );
			$findings = Content_Checks::alt_text( Build::image_markup( wp_basename( (string) get_attached_file( $image ) ), $alt, false ), array( 'post_type' => 'page' ) );
			if ( $findings ) {
				$findings = array_map( static fn( array $finding ): array => array( 'image' => $image ) + $finding, $findings );
				return Rejection::make( 'leitvia_check_failed', __( 'A product image needs alt text that describes it. Set it with leitvia/set-alt-text and try again.', 'leitvia-studio' ), 422, 'checks', $target, 'leitvia/set-alt-text', 'once', array( 'findings' => $findings ) );
			}
		}
		return null;
	}

	/**
	 * A term as list-products and get-product show it, with the hash for update-product-category.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	private static function term( \WP_Term $term ): array {
		$item = array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'hash'        => Hashes::term( $term->term_id ),
		);
		if ( 'product_cat' === $term->taxonomy ) {
			$image         = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
			$item['image'] = $image ? self::image( $image ) : null;
		}
		return $item;
	}

	/**
	 * An image with URL and alt text.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 */
	private static function image( int $id ): array {
		return array(
			'id'  => $id,
			'url' => (string) wp_get_attachment_image_url( $id, 'full' ),
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Rejection leitvia_out_of_scope without content.
	 *
	 * @param array<string, mixed> $target Target.
	 */
	private static function out_of_scope( array $target ): \WP_Error {
		return Rejection::make( 'leitvia_out_of_scope', __( 'Agents may not see or change this object. Only products, product categories and tags are open to them, never orders, refunds, coupons or customers.', 'leitvia-studio' ), 403, 'scope', $target, '', 'never' );
	}
}
