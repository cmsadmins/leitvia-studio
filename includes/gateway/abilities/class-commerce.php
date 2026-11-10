<?php
/**
 * Commerce abilities: products, their categories and tags, attributes, reviews and merchandising.
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
 * Abilities leitvia/list-products, leitvia/get-product, leitvia/create-product, leitvia/update-product,
 * leitvia/update-product-category, leitvia/set-merchandising, leitvia/list-product-attributes,
 * leitvia/update-product-attribute, leitvia/list-product-reviews and leitvia/update-product-review, registered only while WooCommerce is active. Writes go through Pipeline::run() in the
 * area products (default "draft"); the pipeline writes products with the CRUD of WooCommerce (save()). Prices, taxes
 * and shipping are content kinds and land at most as a suggestion unless a person unlocked them. Orders, refunds,
 * coupons and customers are out of scope; nothing here reads sales or customer figures, and reviews come without email
 * address, IP address or user account of the reviewer.
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
	 * Props of a new product: PROPS plus SKU and stock, which only create-product sets.
	 */
	public const CREATE_PROPS = array( 'image_id', 'gallery_image_ids', 'category_ids', 'tag_ids', 'regular_price', 'sale_price', 'tax_status', 'tax_class', 'shipping_class_id', 'featured', 'upsell_ids', 'cross_sell_ids', 'sku', 'manage_stock', 'stock_quantity' );

	/**
	 * Content kinds by the props that make them.
	 */
	public const KINDS = array(
		'prices'   => array( 'regular_price', 'sale_price' ),
		'tax'      => array( 'tax_status', 'tax_class' ),
		'shipping' => array( 'shipping_class_id' ),
	);

	/**
	 * Values of a global product attribute that list-product-attributes reads and update-product-attribute takes.
	 */
	public const MAX_VALUES = 200;

	/**
	 * Statuses of a product review in list-product-reviews and update-product-review, with the status core's comment
	 * functions take for each.
	 */
	public const REVIEW_STATUSES = array(
		'approved' => 'approve',
		'hold'     => 'hold',
		'spam'     => 'spam',
		'trash'    => 'trash',
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
			'leitvia/create-product',
			__( 'Create product', 'leitvia-studio' ),
			__( 'Creates a simple product. It takes title, slug, texts as block markup or plain text (a long description in plain text becomes paragraph blocks, one per block of text between blank lines), prices, SKU, stock, categories and an image of the media library; base_hash is empty. Categories take IDs, slugs or names; a name no category has yet creates that category. The image needs alt text (leitvia/set-alt-text). The status is capped by the autonomy level, and a product with a price lands at most as a suggestion unless a person unlocked prices.', 'leitvia-studio' ),
			'build',
			array(
				'destructive' => false,
				'idempotent'  => false,
			),
			array(
				'properties'           => array(
					'title'             => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'slug'              => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'description'       => array(
						'type'        => 'string',
						'description' => __( 'Long description, block markup or plain text. Plain text without HTML becomes paragraph blocks: a blank line starts a new paragraph, the text is escaped. No fixed length, as with the content of leitvia/update-content; a change package shows the comparison up to 1 MB for before and after together.', 'leitvia-studio' ),
					),
					'short_description' => array(
						'type'        => 'string',
						'maxLength'   => 5000,
						'description' => __( 'Short description next to the price, block markup or plain text.', 'leitvia-studio' ),
					),
					'status'            => array(
						'type' => 'string',
						'enum' => array( 'draft', 'pending', 'publish' ),
					),
					'regular_price'     => $price,
					'sale_price'        => $price,
					'sku'               => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100,
					),
					'stock'             => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Units in stock; turns on stock management for the product. Without it the product is in stock without a count.', 'leitvia-studio' ),
					),
					'categories'        => array(
						'type'        => 'array',
						'maxItems'    => 20,
						'items'       => array(
							'type'      => array( 'integer', 'string' ),
							'minLength' => 1,
							'maxLength' => 200,
						),
						'description' => __( 'Product categories as IDs (numbers), slugs or names (text). A name no category has yet creates the category.', 'leitvia-studio' ),
					),
					'image'             => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Attachment ID of an image with alt text.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'title' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_create_product' )
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
						'type'        => 'string',
						'description' => __( 'Long description, block markup or plain text. Plain text without HTML becomes paragraph blocks: a blank line starts a new paragraph, the text is escaped. No fixed length, as with the content of leitvia/update-content; a change package shows the comparison up to 1 MB for before and after together.', 'leitvia-studio' ),
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
		Abilities::read(
			'leitvia/list-product-attributes',
			__( 'List product attributes', 'leitvia-studio' ),
			__( 'Lists the global product attributes with their values and hash. Per attribute ID, name, slug (taxonomy pa_*) and sort order, per value ID, name and slug, in the order the shop shows them. Attributes a single product defines for itself are part of leitvia/get-product.', 'leitvia-studio' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array( 'attributes' => $objects ),
			),
			static fn() => array( 'attributes' => self::list_attributes() )
		);
		Abilities::write(
			'leitvia/update-product-attribute',
			__( 'Update product attribute', 'leitvia-studio' ),
			__( 'Renames a global product attribute and its values and sorts the values, in one operation. Read the attribute with leitvia/list-product-attributes and send its hash as base_hash. Values are named by their ID; order lists every value ID in the new order and sorts the attribute by it (order_by menu_order). Slugs stay, so filters and links keep working.', 'leitvia-studio' ),
			'maintain',
			$set,
			array(
				'properties'           => array(
					'attribute' => $id,
					'name'      => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'values'    => array(
						'type'        => 'array',
						'maxItems'    => self::MAX_VALUES,
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'value' => $id,
								'name'  => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 200,
								),
							),
							'required'             => array( 'value', 'name' ),
							'additionalProperties' => false,
						),
						'description' => __( 'New names of values: value is the ID from leitvia/list-product-attributes.', 'leitvia-studio' ),
					),
					'order'     => array(
						'type'        => 'array',
						'maxItems'    => self::MAX_VALUES,
						'items'       => $id,
						'description' => __( 'Every value ID of the attribute, in the new order.', 'leitvia-studio' ),
					),
					'order_by'  => array(
						'type'        => 'string',
						'enum'        => array( 'menu_order', 'name', 'name_num', 'id' ),
						'description' => __( 'How the shop sorts the values: menu_order (the custom order), name, name_num (numbers in names as numbers) or id.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'attribute' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update_attribute' )
		);
		$status = array(
			'type' => 'string',
			'enum' => array_keys( self::REVIEW_STATUSES ),
		);
		Abilities::read(
			'leitvia/list-product-reviews',
			__( 'List product reviews', 'leitvia-studio' ),
			__( 'Lists product reviews with rating and hash, 20 per page, oldest first; per review ID, product, author name, date, text, rating (1 to 5, 0 for none), verified owner and status. unrated lists only reviews without a rating; unrated_total counts them. Without the capability of the reviews screen of WooCommerce (moderate_comments) only approved reviews of published products, which visitors see anyway; status in the output names the status listed. No email address, IP address or user account of a reviewer.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'product' => $id,
					'status'  => $status,
					'unrated' => array( 'type' => 'boolean' ),
					'page'    => $id,
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'reviews'       => $objects,
					'total'         => array( 'type' => 'integer' ),
					'pages'         => array( 'type' => 'integer' ),
					'unrated_total' => array( 'type' => 'integer' ),
					'status'        => $string,
				),
			),
			static fn( $input ) => self::list_reviews( is_array( $input ) ? $input : array() )
		);
		Abilities::write(
			'leitvia/update-product-review',
			__( 'Update product review', 'leitvia-studio' ),
			__( 'Sets rating, verified flag or status of a product review. Its text and author stay. Read the review with leitvia/list-product-reviews and send its hash as base_hash. The average rating and the review count of the product follow. Without the capability of the reviews screen of WooCommerce (moderate_comments) only a missing rating of an approved review can be set.', 'leitvia-studio' ),
			'maintain',
			$set,
			array(
				'properties'           => array(
					'review'   => $id,
					'rating'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 5,
					),
					'verified' => array( 'type' => 'boolean' ),
					'status'   => $status,
				),
				'required'             => array( 'review' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update_review' )
		);
	}

	/**
	 * Output of list-product-reviews: reviews (comments of the type review, top level; older shops stored them as plain
	 * comments) of products in scope. Without the capability of the reviews screen only approved ones.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function list_reviews( array $input ): array {
		$status = (string) ( $input['status'] ?? 'approved' );
		if ( ! self::may_moderate_reviews() ) {
			$status = 'approved';
		}
		$args = array(
			'type__in'    => array( 'review', 'comment' ),
			'post_type'   => 'product',
			// Counts and pages match the list: reviews of published products only.
			'post_status' => 'publish',
			'parent'      => 0,
			'status'      => self::REVIEW_STATUSES[ $status ] ?? 'approve',
			'orderby'     => 'comment_ID',
			'order'       => 'ASC',
		);
		if ( ! empty( $input['product'] ) ) {
			$args['post_id'] = (int) $input['product'];
		}
		$unrated = array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filter of the agent.
				'relation' => 'OR',
				array(
					'key'     => 'rating',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rating',
					'value'   => array( '', '0' ),
					'compare' => 'IN',
				),
			),
		);
		if ( ! empty( $input['unrated'] ) ) {
			$args += $unrated;
		}
		$total   = (int) get_comments( $args + array( 'count' => true ) );
		$page    = max( 1, (int) ( $input['page'] ?? 1 ) );
		$reviews = array();
		foreach ( get_comments(
			$args + array(
				'number' => 20,
				'offset' => ( $page - 1 ) * 20,
			)
		) as $comment ) {
			$state = self::review_state( (int) $comment->comment_ID );
			if ( $state ) {
				$reviews[] = array(
					'id'      => (int) $comment->comment_ID,
					'product' => (int) $comment->comment_post_ID,
					'author'  => (string) $comment->comment_author,
					'date'    => (string) get_comment_date( 'c', $comment ),
					'content' => (string) $comment->comment_content,
				) + $state['review'] + array( 'hash' => Hashes::of( $state ) );
			}
		}
		return array(
			'reviews'       => $reviews,
			'total'         => $total,
			'pages'         => (int) ceil( $total / 20 ),
			'unrated_total' => (int) get_comments( $unrated + $args + array( 'count' => true ) ),
			'status'        => $status,
		);
	}

	/**
	 * State of a product review for the pipeline: array( 'review' => rating (0 for none), verified and status, one of
	 * REVIEW_STATUSES ); an empty array for any other comment or a product out of scope.
	 *
	 * @param int $id Comment ID.
	 * @return array<string, mixed>
	 */
	public static function review_state( int $id ): array {
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment instanceof \WP_Comment || ! in_array( $comment->comment_type, array( 'review', 'comment' ), true ) || (int) $comment->comment_parent || 'product' !== get_post_type( (int) $comment->comment_post_ID ) || ! Scope::readable( (int) $comment->comment_post_ID ) ) {
			return array();
		}
		$status = array_search(
			wp_get_comment_status( $comment ),
			array(
				'approved' => 'approved',
				'hold'     => 'unapproved',
				'spam'     => 'spam',
				'trash'    => 'trash',
			),
			true
		);
		return array(
			'review' => array(
				'rating'   => (int) get_comment_meta( $id, 'rating', true ),
				'verified' => (bool) get_comment_meta( $id, 'verified', true ),
				'status'   => false === $status ? 'hold' : $status,
			),
		);
	}

	/**
	 * Hash of a product review over its state, '' when it is none.
	 *
	 * @param int $id Comment ID.
	 */
	public static function review_hash( int $id ): string {
		$state = self::review_state( $id );
		return $state ? Hashes::of( $state ) : '';
	}

	/**
	 * Whether the current user may edit this review, as on the reviews screen of WooCommerce: its capability for
	 * editing (edit_products) and edit_comment of the review, which core maps to editing the product.
	 *
	 * @param int $id Comment ID.
	 */
	public static function may_edit_review( int $id ): bool {
		// The filter of WooCommerce, so a shop that changed who handles reviews changes it here too.
		$capability = (string) apply_filters( 'woocommerce_product_reviews_page_capability', 'edit_products', 'moderate' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of WooCommerce.
		return current_user_can( $capability ) && current_user_can( 'edit_comment', $id );
	}

	/**
	 * Whether the current user may see and moderate all reviews, as the reviews screen of WooCommerce asks
	 * (moderate_comments). Agents lack it: held and spam reviews can hold personal data a visitor never saw.
	 */
	public static function may_moderate_reviews(): bool {
		$capability = (string) apply_filters( 'woocommerce_product_reviews_page_capability', 'moderate_comments', 'view' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of WooCommerce.
		return current_user_can( $capability );
	}

	/**
	 * Writes the fields of a review that differ from its state: rating (0 removes it), verified and status, then
	 * lets WooCommerce count average rating and reviews of the product again. Called by the pipeline, also to restore
	 * a snapshot.
	 *
	 * @param int                  $id     Comment ID.
	 * @param array<string, mixed> $fields Fields of the state, see review_state().
	 */
	public static function save_review( int $id, array $fields ): bool {
		$state = self::review_state( $id );
		if ( ! $state ) {
			return false;
		}
		$old    = (array) $state['review'];
		$rating = (int) $fields['rating'];
		if ( $rating !== $old['rating'] ) {
			$saved = $rating > 0 ? update_comment_meta( $id, 'rating', $rating ) : delete_comment_meta( $id, 'rating' );
			if ( ! $saved ) {
				return false;
			}
		}
		if ( (bool) $fields['verified'] !== $old['verified'] ) {
			update_comment_meta( $id, 'verified', (int) (bool) $fields['verified'] );
		}
		$status = (string) $fields['status'];
		if ( $status !== $old['status'] && ! self::set_review_status( $id, $old['status'], $status ) ) {
			return false;
		}
		// The pipeline writes reviews only while WooCommerce is active, see Scope::commerce().
		\WC_Comments::clear_transients( (int) get_comment( $id )?->comment_post_ID );
		return true;
	}

	/**
	 * Moves a review from one status to another with the functions of core for trash and spam, so their meta, the
	 * restore of the trash in the admin and the emptying of the trash work as for a review a person moved.
	 *
	 * @param int    $id   Comment ID.
	 * @param string $from Status of REVIEW_STATUSES.
	 * @param string $to   Status of REVIEW_STATUSES.
	 */
	private static function set_review_status( int $id, string $from, string $to ): bool {
		if ( 'trash' === $from && ! wp_untrash_comment( $id ) ) {
			return false;
		}
		if ( 'spam' === $from && ! wp_unspam_comment( $id ) ) {
			return false;
		}
		return match ( $to ) {
			'trash' => (bool) wp_trash_comment( $id ),
			'spam'  => (bool) wp_spam_comment( $id ),
			default => self::review_state( $id )['review']['status'] === $to || (bool) wp_set_comment_status( $id, self::REVIEW_STATUSES[ $to ] ?? 'hold' ),
		};
	}

	/**
	 * Operation of update-product-review in the area products.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_review( array $input ) {
		$target = array( 'review' => (int) ( $input['review'] ?? 0 ) );
		$fields = array_intersect_key( $input, array_flip( array( 'rating', 'verified', 'status' ) ) );
		if ( ! $fields ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: rating, verified or status.', 'leitvia-studio' ), $target );
		}
		return array(
			'area'   => self::AREA,
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $fields ): array {
				$state['review'] = array(
					'rating'   => (int) ( $fields['rating'] ?? $state['review']['rating'] ),
					'verified' => (bool) ( $fields['verified'] ?? $state['review']['verified'] ),
					'status'   => (string) ( $fields['status'] ?? $state['review']['status'] ),
				);
				return $state;
			},
		);
	}

	/**
	 * Global product attributes with their values, see attribute_state(), plus slug and hash.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_attributes(): array {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $row ) {
			$id    = (int) $row->attribute_id;
			$state = self::attribute_state( $id );
			if ( ! $state ) {
				continue;
			}
			$taxonomy = wc_attribute_taxonomy_name( (string) $row->attribute_name );
			$values   = array();
			foreach ( $state['attribute']['values'] as $value ) {
				$term     = get_term( (int) $value['id'], $taxonomy );
				$values[] = array(
					'id'   => (int) $value['id'],
					'name' => (string) $value['name'],
					'slug' => $term instanceof \WP_Term ? $term->slug : '',
				);
			}
			$out[] = array(
				'id'       => $id,
				'name'     => (string) $state['attribute']['name'],
				'slug'     => $taxonomy,
				'order_by' => (string) $state['attribute']['order_by'],
				'values'   => $values,
				'hash'     => Hashes::of( $state ),
			);
		}
		return $out;
	}

	/**
	 * State of a global product attribute for the pipeline: array( 'attribute' => name, order_by and values ), the values
	 * with ID, name and order (term meta order of WooCommerce) in the order of that meta, then name; an empty array when
	 * the attribute does not exist.
	 * ponytail: at most MAX_VALUES values; an attribute with more needs paging.
	 *
	 * @param int $id Attribute ID.
	 * @return array<string, mixed>
	 */
	public static function attribute_state( int $id ): array {
		$attribute = $id ? wc_get_attribute( $id ) : null;
		if ( ! $attribute ) {
			return array();
		}
		$terms  = get_terms(
			array(
				'taxonomy'   => (string) $attribute->slug,
				'hide_empty' => false,
				'number'     => self::MAX_VALUES,
				'orderby'    => 'term_id',
			)
		);
		$values = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$values[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'order' => (int) get_term_meta( (int) $term->term_id, 'order', true ),
			);
		}
		usort( $values, static fn( array $a, array $b ): int => array( $a['order'], $a['name'], $a['id'] ) <=> array( $b['order'], $b['name'], $b['id'] ) );
		return array(
			'attribute' => array(
				'name'     => (string) $attribute->name,
				'order_by' => (string) $attribute->order_by,
				'values'   => $values,
			),
		);
	}

	/**
	 * Hash of a global product attribute over its state, '' when it does not exist.
	 *
	 * @param int $id Attribute ID.
	 */
	public static function attribute_hash( int $id ): string {
		$state = self::attribute_state( $id );
		return $state ? Hashes::of( $state ) : '';
	}

	/**
	 * Writes name and sort order of an attribute with wc_update_attribute() (slug, type and archives stay), then the
	 * names and the order meta of its values. Called by the pipeline, also to restore a snapshot.
	 *
	 * @param int                  $id     Attribute ID.
	 * @param array<string, mixed> $fields Fields of the state, see attribute_state().
	 */
	public static function save_attribute( int $id, array $fields ): bool {
		$attribute = wc_get_attribute( $id );
		if ( ! $attribute ) {
			return false;
		}
		$saved = wc_update_attribute(
			$id,
			wp_slash(
				array(
					'name'         => (string) $fields['name'],
					'slug'         => (string) $attribute->slug,
					'type'         => (string) $attribute->type,
					'order_by'     => (string) $fields['order_by'],
					'has_archives' => (bool) $attribute->has_archives,
				)
			)
		);
		if ( is_wp_error( $saved ) ) {
			return false;
		}
		foreach ( (array) $fields['values'] as $value ) {
			$term = get_term( (int) $value['id'], (string) $attribute->slug );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			if ( $term->name !== (string) $value['name'] && is_wp_error( wp_update_term( $term->term_id, $term->taxonomy, wp_slash( array( 'name' => (string) $value['name'] ) ) ) ) ) {
				return false;
			}
			update_term_meta( $term->term_id, 'order', (int) $value['order'] );
		}
		return true;
	}

	/**
	 * Operation of update-product-attribute: names as plain text, a value of another attribute or an order that leaves
	 * out or repeats a value is invalid, and an order sorts by it (order_by menu_order).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_attribute( array $input ) {
		$id     = (int) ( $input['attribute'] ?? 0 );
		$target = array( 'attribute' => $id );
		$name   = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : null;
		$names  = array();
		foreach ( (array) ( $input['values'] ?? array() ) as $value ) {
			$names[ (int) ( $value['value'] ?? 0 ) ] = sanitize_text_field( (string) ( $value['name'] ?? '' ) );
		}
		$order = isset( $input['order'] ) ? array_map( 'intval', (array) $input['order'] ) : null;
		$by    = isset( $input['order_by'] ) ? (string) $input['order_by'] : ( null !== $order ? 'menu_order' : null );
		if ( null === $name && ! $names && null === $order && null === $by ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: name, values, order or order_by.', 'leitvia-studio' ), $target );
		}
		if ( '' === $name || in_array( '', $names, true ) ) {
			return Block_Edit::invalid( 'name', __( 'A name needs text; HTML is removed from names.', 'leitvia-studio' ), $target );
		}
		if ( null !== $order && 'menu_order' !== $by ) {
			return Block_Edit::invalid( 'order_by', __( 'An order of the values needs order_by menu_order; leave order_by out to sort by the order.', 'leitvia-studio' ), $target );
		}
		return array(
			'area'   => self::AREA,
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $name, $names, $order, $by, $target ) {
				$current = (array) $state['attribute'];
				$ids     = array_map( 'intval', array_column( (array) $current['values'], 'id' ) );
				if ( array_diff( array_keys( $names ), $ids ) ) {
					return Block_Edit::invalid( 'values', __( 'A value ID does not belong to this attribute. Use the IDs from leitvia/list-product-attributes.', 'leitvia-studio' ), $target, 'leitvia/list-product-attributes' );
				}
				$sorted = $order;
				if ( null !== $sorted ) {
					sort( $sorted );
					$all = $ids;
					sort( $all );
					if ( $sorted !== $all ) {
						return Block_Edit::invalid( 'order', __( 'The order has to list every value ID of the attribute exactly once.', 'leitvia-studio' ), $target, 'leitvia/list-product-attributes' );
					}
				}
				$position = null === $order ? array() : array_flip( $order );
				foreach ( $current['values'] as &$value ) {
					$value['name']  = $names[ (int) $value['id'] ] ?? $value['name'];
					$value['order'] = $position[ (int) $value['id'] ] ?? $value['order'];
				}
				unset( $value );
				if ( null !== $order ) {
					usort( $current['values'], static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
				}
				$state['attribute'] = array(
					'name'     => $name ?? $current['name'],
					'order_by' => $by ?? $current['order_by'],
				) + $current;
				return $state;
			},
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
		$new   = (array) ( $after['product'] ?? array() );
		// A new product starts from the defaults, so only a price it brings counts.
		$old = $before ? (array) ( $before['product'] ?? array() ) : ( $new ? self::defaults() : array() );
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
		return self::read( $product, $product->is_type( 'variation' ) ? self::VARIATION_PROPS : self::PROPS );
	}

	/**
	 * Props of a new simple product before create-product changes them: CREATE_PROPS with the defaults of WooCommerce.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::read( new \WC_Product_Simple(), self::CREATE_PROPS );
	}

	/**
	 * Props of a product as the state holds them.
	 *
	 * @param \WC_Product $product Product.
	 * @param string[]    $names   Props to read.
	 * @return array<string, mixed>
	 */
	private static function read( \WC_Product $product, array $names ): array {
		$props = array();
		foreach ( $names as $prop ) {
			$value          = $product->{'get_' . $prop}( 'edit' );
			$props[ $prop ] = match ( true ) {
				in_array( $prop, self::ID_LISTS, true )                           => array_map( 'intval', array_values( (array) $value ) ),
				in_array( $prop, array( 'image_id', 'shipping_class_id' ), true ) => (int) $value,
				in_array( $prop, array( 'featured', 'manage_stock' ), true )      => (bool) $value,
				default                                                           => is_scalar( $value ) ? (string) $value : '',
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
		return self::write( $product, $state );
	}

	/**
	 * Creates a simple product from the state of create-product. Called by the pipeline; category names that no
	 * category has yet become categories first.
	 *
	 * @param array<string, mixed> $state State, see Pipeline::state().
	 * @return int|\WP_Error Product ID.
	 */
	public static function create( array $state ) {
		$categories = array();
		foreach ( (array) ( $state['product']['category_ids'] ?? array() ) as $category ) {
			$term = is_int( $category ) ? $category : self::category( (string) $category );
			if ( ! $term ) {
				$created = wp_insert_term( (string) $category, 'product_cat' );
				$term    = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
			}
			if ( ! $term ) {
				return new \WP_Error( 'leitvia_execution_failed' );
			}
			$categories[] = $term;
		}
		$state['product']['category_ids'] = $categories;
		return self::write( new \WC_Product_Simple(), $state );
	}

	/**
	 * Writes post fields and props of a state with the CRUD of WooCommerce.
	 *
	 * @param \WC_Product          $product Product.
	 * @param array<string, mixed> $state   State.
	 * @return int|\WP_Error Product ID.
	 */
	private static function write( \WC_Product $product, array $state ) {
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
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		// WooCommerce hands the texts to wp_insert_post() and wp_update_post() as they are, which unslash them: without
		// slashes core's KSES would read the JSON of a block comment with lost backslashes and drop its attributes (a
		// synced pattern without ref and content). The props stay unslashed (variation titles, change detection, hooks);
		// only the sanitizing of that one call gets them slashed, before KSES. Each filter removes itself after its first
		// run, so a post that a hook writes meanwhile stays as it is. Inside save_post WooCommerce writes the row directly.
		$slash = static function ( $text ) use ( &$slash ) {
			remove_filter( (string) current_filter(), $slash, 0 );
			return wp_slash( $text );
		};
		$hooks = doing_action( 'save_post' ) ? array() : array( 'title_save_pre', 'content_save_pre', 'excerpt_save_pre' );
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $slash, 0 );
		}
		try {
			return $product->save();
		} finally {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $slash, 0 );
			}
		}
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
	 * Operation of create-product: a simple product in the area products. Categories are resolved to IDs here; names
	 * no category has yet stay names until the pipeline writes the product (see create()).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_create_product( array $input ) {
		$target  = array( 'post' => 0 );
		$regular = (string) ( $input['regular_price'] ?? '' );
		$sale    = (string) ( $input['sale_price'] ?? '' );
		if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
			return Block_Edit::invalid( 'sale_price', __( 'A sale price needs a regular price that is higher.', 'leitvia-studio' ), $target );
		}
		$sku = trim( (string) ( $input['sku'] ?? '' ) );
		if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
			return Block_Edit::invalid( 'sku', __( 'Another product already has this SKU. Use another one or leave it out.', 'leitvia-studio' ), $target, 'leitvia/list-products' );
		}
		$categories = array();
		foreach ( (array) ( $input['categories'] ?? array() ) as $value ) {
			$term = is_int( $value ) ? ( term_exists( $value, 'product_cat' ) ? $value : 0 ) : self::category( (string) $value );
			if ( is_int( $value ) && ! $term ) {
				/* translators: %d: term ID */
				return Block_Edit::invalid( 'categories', sprintf( __( 'There is no product category with the ID %d.', 'leitvia-studio' ), $value ), $target, 'leitvia/list-products' );
			}
			$taxonomy = get_taxonomy( 'product_cat' );
			if ( ! $term && ( ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) ) ) {
				return Block_Edit::invalid( 'categories', __( 'Your user may not create product categories. Use existing ones from leitvia/list-products.', 'leitvia-studio' ), $target, 'leitvia/list-products' );
			}
			$categories[] = $term ? $term : trim( (string) $value );
		}
		$props = array_filter(
			array(
				'image_id'      => isset( $input['image'] ) ? (int) $input['image'] : null,
				'category_ids'  => $categories ? array_values( array_unique( $categories ) ) : null,
				'regular_price' => '' !== $regular ? $regular : null,
				'sale_price'    => '' !== $sale ? $sale : null,
				'sku'           => '' !== $sku ? $sku : null,
			),
			static fn( $value ): bool => null !== $value
		);
		if ( isset( $input['stock'] ) ) {
			$props['manage_stock']   = true;
			$props['stock_quantity'] = (string) (int) $input['stock'];
		}
		$invalid = self::invalid_images( isset( $props['image_id'] ) ? array( (int) $props['image_id'] ) : array(), $target );
		if ( $invalid ) {
			return $invalid;
		}
		$fields = array(
			'post_title'   => (string) ( $input['title'] ?? '' ),
			'post_content' => self::paragraphs( (string) ( $input['description'] ?? '' ) ),
			'post_excerpt' => (string) ( $input['short_description'] ?? '' ),
			'post_name'    => (string) ( $input['slug'] ?? '' ),
			'post_status'  => (string) ( $input['status'] ?? 'draft' ),
		);
		return array(
			'area'   => self::AREA,
			'target' => $target + array( 'post_type' => 'product' ),
			'record' => true,
			'change' => static function ( array $state ) use ( $fields, $props ): array {
				$state['product'] = $props + (array) ( $state['product'] ?? array() );
				return $fields + $state;
			},
		);
	}

	/**
	 * A long description as blocks: plain text becomes one escaped paragraph block per part between blank lines (single
	 * line breaks become <br>), so the product holds no classic content. Block markup and HTML stay as they are. The
	 * short description stays text: the product template prints it inside a paragraph of its own.
	 *
	 * @param string $text Block markup or plain text.
	 */
	public static function paragraphs( string $text ): string {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		if ( '' === $text || preg_match( '#<[a-z!/]#i', $text ) ) {
			return $text;
		}
		$blocks = array();
		foreach ( (array) preg_split( '/\n\s*\n/', $text ) as $part ) {
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( trim( (string) $part ) ), false ) . "</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * ID of the product category with this slug or name, 0 for none.
	 *
	 * @param string $value Slug or name.
	 */
	private static function category( string $value ): int {
		$term = get_term_by( 'slug', sanitize_title( $value ), 'product_cat' );
		$term = $term instanceof \WP_Term ? $term : get_term_by( 'name', $value, 'product_cat' );
		return $term instanceof \WP_Term ? $term->term_id : 0;
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
				'post_content' => isset( $input['description'] ) ? self::paragraphs( (string) $input['description'] ) : null,
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
