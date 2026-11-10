<?php
/**
 * Gateway commerce abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Commerce;
use LeitviaStudio\Gateway\Abilities\Read_Audit;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Preview;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Gateway\Store;

/**
 * Abilities list-products, get-product, create-product, update-product, update-product-category, set-merchandising,
 * list-product-attributes, update-product-attribute, list-product-reviews and update-product-review: reads without sales or customer figures, writes through the pipeline in the area products, prices, taxes and shipping at most
 * "suggest", orders, refunds, coupons and customers out of scope, rollback for products. The group woocommerce runs
 * with WooCommerce loaded (npm run test:php:commerce).
 */
class Test_Gateway_Commerce extends WP_UnitTestCase {

	/**
	 * Block markup without the leitviaId the pipeline adds to every block.
	 *
	 * @param string $markup Block markup.
	 */
	private static function without_ids( string $markup ): string {
		return (string) preg_replace( '/ \{"metadata":\{"leitviaId":"[a-z0-9]+"\}\}/', '', $markup );
	}


	/**
	 * Names of the ten abilities.
	 */
	private const ABILITIES = array( 'leitvia/list-products', 'leitvia/get-product', 'leitvia/create-product', 'leitvia/update-product', 'leitvia/update-product-category', 'leitvia/set-merchandising', 'leitvia/list-product-attributes', 'leitvia/update-product-attribute', 'leitvia/list-product-reviews', 'leitvia/update-product-review' );

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Leitvia theme, agent user, standard profile and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $this->agent );
		update_option( Gateway::OPTION, Gateway::DEFAULTS );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Unregisters the abilities of Leitvia and the category.
	 */
	private static function unregister(): void {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
	}

	/**
	 * Saves gateway settings over the defaults.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function settings( array $settings ): void {
		update_option( Gateway::OPTION, $settings + Gateway::DEFAULTS );
	}

	/**
	 * A saved simple product.
	 *
	 * @param array<string, mixed> $props Props over the defaults.
	 */
	private static function product( array $props = array() ): int {
		$product = new WC_Product_Simple();
		$product->set_props(
			$props + array(
				'name'          => 'Oak tray',
				'status'        => 'publish',
				'description'   => '<p>A tray of oiled oak.</p>',
				'regular_price' => '48.00',
			)
		);
		return $product->save();
	}

	/**
	 * An image of the media library with this alt text.
	 *
	 * @param string $alt Alt text.
	 */
	private function image( string $alt ): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return $id;
	}

	/**
	 * Runs an ability with the current hash of the product.
	 *
	 * @param string               $name    Ability name.
	 * @param int                  $product Product whose hash is the base hash.
	 * @param array<string, mixed> $input   Input.
	 * @return mixed
	 */
	private static function call( string $name, int $product, array $input ) {
		return wp_get_ability( $name )->execute( $input + array( 'base_hash' => Hashes::document( $product ) ) );
	}

	/**
	 * Asserts a result without error and returns it.
	 *
	 * @param mixed $result Result.
	 * @return array<string, mixed>
	 */
	private function assert_ok( $result ): array {
		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		return $result;
	}

	/**
	 * Asserts a rejection with this code and returns its data.
	 *
	 * @param string $code   Error code.
	 * @param mixed  $result Result.
	 * @return array<string, mixed>
	 */
	private function assert_rejected( string $code, $result ): array {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return (array) $result->get_error_data();
	}

	/**
	 * A global attribute Colour (pa_colour, sorted by the custom order) with the values Red, Blue and Green in that
	 * order. WooCommerce registers the taxonomy of an attribute on init, so the test registers it as WooCommerce does.
	 *
	 * @return array{0: int, 1: array<string, int>} Attribute ID and term IDs by name.
	 */
	private static function attribute(): array {
		$id = wc_create_attribute(
			array(
				'name'     => 'Colour',
				'slug'     => 'colour',
				'order_by' => 'menu_order',
			)
		);
		register_taxonomy(
			'pa_colour',
			array( 'product' ),
			array(
				'capabilities' => array(
					'manage_terms' => 'manage_product_terms',
					'edit_terms'   => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms',
				),
			)
		);
		$terms = array();
		foreach ( array( 'Red', 'Blue', 'Green' ) as $order => $name ) {
			$terms[ $name ] = self::factory()->term->create(
				array(
					'taxonomy' => 'pa_colour',
					'name'     => $name,
				)
			);
			update_term_meta( $terms[ $name ], 'order', $order );
		}
		return array( (int) $id, $terms );
	}

	/**
	 * The attribute of the ID as list-product-attributes lists it.
	 *
	 * @param int $id Attribute ID.
	 * @return array<string, mixed>
	 */
	private static function listed( int $id ): array {
		$list = wp_get_ability( 'leitvia/list-product-attributes' )->execute();
		return (array) current( wp_list_filter( (array) $list['attributes'], array( 'id' => $id ) ) );
	}

	/**
	 * Without WooCommerce the eight abilities, the products area and product types do not exist.
	 */
	public function test_without_woocommerce_not_registered(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );
		foreach ( self::ABILITIES as $name ) {
			$this->assertFalse( wp_has_ability( $name ), $name );
		}
		$this->assertNotContains( 'product', Scope::post_types() );
		$this->assertSame( array(), Scope::taxonomies() );
	}

	/**
	 * Reading: list and single product with texts, images with alt text, terms with hash, attributes, read-only price,
	 * stock status and hash; no sales, order or customer figures.
	 *
	 * @group woocommerce
	 */
	public function test_read_products(): void {
		foreach ( self::ABILITIES as $name ) {
			$this->assertNotNull( wp_get_ability( $name ), $name );
		}
		$this->assertTrue( wp_get_ability( 'leitvia/get-product' )->get_meta()['annotations']['readonly'] );
		$this->assertFalse( wp_get_ability( 'leitvia/update-product' )->get_meta()['annotations']['readonly'] );
		$category  = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Kitchen',
			)
		);
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Material' );
		$attribute->set_options( array( 'Oak' ) );
		$attribute->set_visible( true );
		$image = $this->image( 'Oak tray on a linen cloth' );
		$id    = self::product(
			array(
				'category_ids' => array( $category ),
				'image_id'     => $image,
				'attributes'   => array( $attribute ),
				'total_sales'  => 17,
			)
		);

		$product = $this->assert_ok( wp_get_ability( 'leitvia/get-product' )->execute( array( 'product' => $id ) ) );
		$this->assertSame( 'Oak tray', $product['title'] );
		$this->assertSame( '<p>A tray of oiled oak.</p>', $product['description'] );
		$this->assertSame( 'Oak tray on a linen cloth', $product['images'][0]['alt'] );
		$this->assertSame( 'Kitchen', $product['categories'][0]['name'] );
		$this->assertSame( Hashes::term( $category ), $product['categories'][0]['hash'] );
		$this->assertSame( array( 'Oak' ), $product['attributes'][0]['options'] );
		$this->assertSame( '48.00', $product['price']['regular'] );
		$this->assertSame( 'instock', $product['stock_status'] );
		$this->assertSame( Hashes::document( $id ), $product['hash'] );
		$json = (string) wp_json_encode( $product );
		$this->assertStringNotContainsString( 'sales"', $json );
		$this->assertStringNotContainsString( '17', $json );
		$this->assertStringNotContainsString( 'review', $json );

		$list = $this->assert_ok( wp_get_ability( 'leitvia/list-products' )->execute( array( 'category' => $category ) ) );
		$this->assertSame( array( $id ), wp_list_pluck( $list['products'], 'id' ) );
		$this->assertSame( Hashes::document( $id ), $list['products'][0]['hash'] );
		$this->assertContains( $category, wp_list_pluck( $list['categories'], 'id' ) );
		$this->assertStringNotContainsString( 'sales"', (string) wp_json_encode( $list ) );
		$this->assertSame( $list['total'], $this->assert_ok( wp_get_ability( 'leitvia/list-products' )->execute() )['total'], 'no input reads with the defaults, as GET without query arguments' );
	}

	/**
	 * Update-product works in the area products, default "draft": a published product gives a package with preview, a draft
	 * product is written with the CRUD; the base hash is required and images need alt text.
	 *
	 * @group woocommerce
	 */
	public function test_update_product_draft_level(): void {
		$category = self::factory()->term->create( array( 'taxonomy' => 'product_cat' ) );
		$id       = self::product();
		$input    = array(
			'product'     => $id,
			'title'       => 'Oiled oak tray',
			'description' => '<p>Hand oiled.</p>',
			'categories'  => array( $category ),
		);

		$this->assertWPError( wp_get_ability( 'leitvia/update-product' )->execute( $input ), 'base_hash is required' );
		$this->assert_rejected( 'leitvia_stale_hash', wp_get_ability( 'leitvia/update-product' )->execute( $input + array( 'base_hash' => '0000000000000000' ) ) );
		$data = $this->assert_rejected( 'leitvia_check_failed', self::call( 'leitvia/update-product', $id, $input + array( 'image' => $this->image( '' ) ) ) );
		$this->assertSame( 'leitvia/alt-text', $data['findings'][0]['check'] );
		$this->assert_rejected( 'leitvia_check_failed', self::call( 'leitvia/update-product', $id, array( 'gallery' => array( $this->image( 'canola.jpg' ) ) ) + $input ) );

		$result = $this->assert_ok( self::call( 'leitvia/update-product', $id, $input ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( 'draft', $result['level'] );
		$this->assertStringContainsString( Preview::ARG . '=' . $result['package'], Preview::url( $result['package'] ) );
		$this->assertSame( 'Oak tray', wc_get_product( $id )->get_name(), 'nothing written before a person applies' );

		$draft  = self::product( array( 'status' => 'draft' ) );
		$result = $this->assert_ok( self::call( 'leitvia/update-product', $draft, array( 'product' => $draft ) + $input ) );
		$this->assertSame( 'draft', $result['mode'] );
		$product = wc_get_product( $draft );
		$this->assertSame( 'Oiled oak tray', $product->get_name() );
		$this->assertSame( '<p>Hand oiled.</p>', $product->get_description() );
		$this->assertSame( array( $category ), $product->get_category_ids() );
		$this->assertSame( Hashes::document( $draft ), $result['hash'] );
	}

	/**
	 * Prices, taxes and shipping are content kinds: at most "suggest", also in profile full, until a person unlocks the
	 * kind; then the CRUD writes the price live.
	 *
	 * @group woocommerce
	 */
	public function test_prices_tax_shipping_at_most_suggest(): void {
		self::settings( array( 'profile' => 'full' ) );
		$shipping = self::factory()->term->create( array( 'taxonomy' => 'product_shipping_class' ) );
		$id       = self::product();
		$changes  = array(
			'prices'   => array( 'regular_price' => '59.00' ),
			'tax'      => array( 'tax_status' => 'none' ),
			'shipping' => array( 'shipping_class' => $shipping ),
		);
		foreach ( $changes as $kind => $change ) {
			$result = $this->assert_ok( self::call( 'leitvia/update-product', $id, array( 'product' => $id ) + $change ) );
			$this->assertContains( $kind, $result['kinds'], $kind );
			$this->assertSame( 'suggest', $result['level'], $kind );
			$this->assertSame( 'package', $result['mode'], $kind );
		}
		$this->assertSame( '48.00', wc_get_product( $id )->get_regular_price() );
		$text = $this->assert_ok(
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product' => $id,
					'title'   => 'Oak serving tray',
				)
			)
		);
		$this->assertSame( 'live', $text['mode'], 'texts are autonomous in profile full' );

		self::settings(
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => array( 'prices' ),
				),
			)
		);
		$result = $this->assert_ok( self::call( 'leitvia/update-product', $id, $changes['prices'] + array( 'product' => $id ) ) );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( '59.00', wc_get_product( $id )->get_price(), 'the CRUD keeps the active price in sync' );
	}

	/**
	 * Orders, refunds, coupons and customers are out of scope for every read and write ability, without content, and
	 * audit-site and list-products show none of them.
	 *
	 * @group woocommerce
	 */
	public function test_orders_coupons_customers_out_of_scope(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.com' );
		$order->save();
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->save();
		$coupon = new WC_Coupon();
		$coupon->set_code( 'save10' );
		$coupon->save();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertNotContains( 'shop_coupon', Scope::post_types() );
		$this->assertTrue( Scope::blocked( 'shop_coupon' ) );

		foreach ( array( $order->get_id(), $refund->get_id(), $coupon->get_id(), $customer ) as $id ) {
			$data = $this->assert_rejected( 'leitvia_out_of_scope', wp_get_ability( 'leitvia/get-product' )->execute( array( 'product' => $id ) ) );
			$this->assertSame( array( 'post' => $id ), $data['target'] );
			$this->assertStringNotContainsString( 'buyer@example.com', (string) wp_json_encode( $data ) );
			$this->assert_rejected(
				'leitvia_out_of_scope',
				wp_get_ability( 'leitvia/update-product' )->execute(
					array(
						'product'   => $id,
						'title'     => 'Changed',
						'base_hash' => Hashes::document( $id ),
					)
				)
			);
			$this->assert_rejected(
				'leitvia_out_of_scope',
				wp_get_ability( 'leitvia/set-merchandising' )->execute(
					array(
						'product'   => $id,
						'featured'  => true,
						'base_hash' => Hashes::document( $id ),
					)
				)
			);
		}
		$this->assert_rejected( 'leitvia_out_of_scope', wp_get_ability( 'leitvia/get-page-outline' )->execute( array( 'post' => $order->get_id() ) ) );
		$this->assert_rejected(
			'leitvia_out_of_scope',
			wp_get_ability( 'leitvia/update-product-category' )->execute(
				array(
					'term'      => self::factory()->category->create(),
					'name'      => 'Changed',
					'base_hash' => '',
				)
			)
		);

		$product = self::product();
		$audit   = $this->assert_ok( wp_get_ability( 'leitvia/audit-site' )->execute( array() ) );
		$listed  = wp_list_pluck( $audit['items'], 'post' );
		$this->assertContains( $product, $listed );
		$list = $this->assert_ok( wp_get_ability( 'leitvia/list-products' )->execute( array() ) );
		foreach ( array( $order->get_id(), $refund->get_id(), $coupon->get_id() ) as $id ) {
			$this->assertNotContains( $id, $listed );
			$this->assertNotContains( $id, wp_list_pluck( $list['products'], 'id' ) );
		}
	}

	/**
	 * Live writes go through the CRUD with a snapshot of the props; rollback restores them and refuses after a later
	 * change.
	 *
	 * @group woocommerce
	 */
	public function test_snapshot_and_rollback(): void {
		self::settings(
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => array( 'prices' ),
				),
			)
		);
		$id     = self::product();
		$result = $this->assert_ok(
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product'       => $id,
					'title'         => 'Oak serving tray',
					'regular_price' => '52.00',
				)
			)
		);
		$this->assertSame( 'live', $result['mode'] );
		$snapshot = Store::get_snapshot( $result['snapshot'] );
		$this->assertSame( 'product', $snapshot['object_type'] );
		$this->assertSame( 'Oak tray', $snapshot['state']['post_title'] );
		$this->assertSame( '48.00', $snapshot['state']['product']['regular_price'] );
		$this->assertSame( '52.00', wc_get_product( $id )->get_price() );

		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$product = wc_get_product( $id );
		$this->assertSame( 'Oak tray', $product->get_name() );
		$this->assertSame( '48.00', $product->get_price() );
		$this->assertSame( Packages::state( $result['package'] ), 'rolled_back' );

		$result = $this->assert_ok(
			self::call(
				'leitvia/set-merchandising',
				$id,
				array(
					'product'  => $id,
					'featured' => true,
				)
			)
		);
		$product->set_sale_price( '40.00' );
		$product->save();
		$data = $this->assert_rejected( 'leitvia_rollback_conflict', wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertNotEmpty( $data['conflicts'][0]['diff'] );
		$this->assertTrue( wc_get_product( $id )->get_featured(), 'nothing rolled back on conflict' );
	}

	/**
	 * Merchandising: featured, upsells and cross-sells of other products; categories through update-product-category,
	 * a package in the profile standard (terms have no draft), live with rollback in profile full.
	 *
	 * @group woocommerce
	 */
	public function test_merchandising_and_categories(): void {
		self::settings( array( 'profile' => 'full' ) );
		$id    = self::product();
		$other = self::product( array( 'name' => 'Linen cloth' ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/set-merchandising',
				$id,
				array(
					'product' => $id,
					'upsells' => array( $id ),
				)
			)
		);
		$this->assert_ok(
			self::call(
				'leitvia/set-merchandising',
				$id,
				array(
					'product'     => $id,
					'featured'    => true,
					'upsells'     => array( $other ),
					'cross_sells' => array( $other ),
				)
			)
		);
		$product = wc_get_product( $id );
		$this->assertTrue( $product->get_featured() );
		$this->assertSame( array( $other ), array_map( 'intval', $product->get_upsell_ids() ) );
		$this->assertSame( array( $other ), array_map( 'intval', $product->get_cross_sell_ids() ) );

		$term  = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Kitchen',
			)
		);
		$input = array(
			'term'      => $term,
			'name'      => 'Kitchen and table',
			'base_hash' => Hashes::term( $term ),
		);
		$this->assert_rejected( 'leitvia_check_failed', wp_get_ability( 'leitvia/update-product-category' )->execute( $input + array( 'image' => $this->image( '' ) ) ) );
		$image  = $this->image( 'Table set for two' );
		$result = $this->assert_ok( wp_get_ability( 'leitvia/update-product-category' )->execute( $input + array( 'image' => $image ) ) );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( 'Kitchen and table', get_term( $term )->name );
		$this->assertSame( $image, (int) get_term_meta( $term, 'thumbnail_id', true ) );
		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertSame( 'Kitchen', get_term( $term )->name );
		$this->assertSame( '', get_term_meta( $term, 'thumbnail_id', true ) );

		self::settings( array() );
		$result = $this->assert_ok( wp_get_ability( 'leitvia/update-product-category' )->execute( array( 'base_hash' => Hashes::term( $term ) ) + $input ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( 'Kitchen', get_term( $term )->name );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$applied = $this->assert_ok( Packages::apply( $result['package'] ) );
		$this->assertSame( 'applied', $applied['state'] );
		$this->assertSame( 'Kitchen and table', get_term( $term )->name );
	}

	/**
	 * Scripts, event attributes and script URLs in product texts and term descriptions are rejected.
	 *
	 * @group woocommerce
	 */
	public function test_unsafe_texts_rejected(): void {
		$id = self::product( array( 'status' => 'draft' ) );
		foreach ( array(
			array( 'description' => '<p>Oak</p><script>alert(1)</script>' ),
			array( 'short_description' => '<a href="#" onclick="alert(1)">Oak</a>' ),
			array( 'short_description' => '<a href="javascript:alert(1)">Oak</a>' ),
		) as $change ) {
			$this->assert_rejected( 'leitvia_unsafe_content', self::call( 'leitvia/update-product', $id, array( 'product' => $id ) + $change ) );
		}
		$term = self::factory()->term->create( array( 'taxonomy' => 'product_tag' ) );
		$this->assert_rejected(
			'leitvia_unsafe_content',
			wp_get_ability( 'leitvia/update-product-category' )->execute(
				array(
					'term'        => $term,
					'description' => '<a href="javascript:alert(1)">Oak</a>',
					'base_hash'   => Hashes::term( $term ),
				)
			)
		);
	}

	/**
	 * Create-product in the profile standard: a product without a price is written as a draft with SKU, stock, image and
	 * categories by ID, slug and a new name; a product with a price waits in a package as a suggestion until a person
	 * applies it.
	 *
	 * @group woocommerce
	 */
	public function test_create_product_draft_and_package(): void {
		$kitchen = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Kitchen',
			)
		);
		$desk    = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Desk',
				'slug'     => 'desk',
			)
		);
		$image   = $this->image( 'Notebook bound in brown leather' );
		$input   = array(
			'title'             => 'Leather notebook A5',
			'slug'              => 'leather-notebook-a5',
			'description'       => 'Hand bound with 192 pages of cream paper.',
			'short_description' => '<!-- wp:paragraph --><p>Bound in our workshop.</p><!-- /wp:paragraph -->',
			'status'            => 'publish',
			'sku'               => 'CT-NB-A5',
			'stock'             => 5,
			'categories'        => array( $kitchen, 'desk', 'Notebooks' ),
			'image'             => $image,
			'base_hash'         => '',
		);

		$result = $this->assert_ok( wp_get_ability( 'leitvia/create-product' )->execute( $input ) );
		$this->assertSame( 'draft', $result['mode'] );
		$this->assertSame( 'draft', $result['level'] );
		$this->assertSame( array(), $result['kinds'] );
		$product = wc_get_product( $result['target']['post'] );
		$this->assertInstanceOf( WC_Product_Simple::class, $product );
		$this->assertSame( 'draft', $product->get_status(), 'the level caps the status' );
		$this->assertSame( 'Leather notebook A5', $product->get_name() );
		$this->assertSame( 'leather-notebook-a5', $product->get_slug() );
		$this->assertSame( "<!-- wp:paragraph -->\n<p>Hand bound with 192 pages of cream paper.</p>\n<!-- /wp:paragraph -->", self::without_ids( $product->get_description() ), 'plain text becomes a paragraph block' );
		$this->assertStringContainsString( 'Bound in our workshop.', $product->get_short_description() );
		$this->assertSame( 'CT-NB-A5', $product->get_sku() );
		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 5, $product->get_stock_quantity() );
		$this->assertSame( $image, (int) $product->get_image_id() );
		$created = get_term_by( 'name', 'Notebooks', 'product_cat' );
		$this->assertInstanceOf( WP_Term::class, $created, 'a new name creates the category' );
		$ids = array_map( 'intval', $product->get_category_ids() );
		sort( $ids );
		$expected = array( $kitchen, $desk, $created->term_id );
		sort( $expected );
		$this->assertSame( $expected, $ids );
		$this->assertSame( Hashes::document( $product->get_id() ), $result['hash'] );

		$priced = $this->assert_ok(
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'         => 'Belt in oxblood',
					'regular_price' => '68.00',
					'sale_price'    => '59.00',
					'categories'    => array( 'Belts' ),
					'base_hash'     => '',
				)
			)
		);
		$this->assertContains( 'prices', $priced['kinds'] );
		$this->assertSame( 'suggest', $priced['level'] );
		$this->assertSame( 'package', $priced['mode'] );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'product',
					'title'       => 'Belt in oxblood',
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			),
			'nothing written before a person applies'
		);
		$this->assertFalse( get_term_by( 'name', 'Belts', 'product_cat' ), 'the new category waits with the product' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$applied = $this->assert_ok( Packages::apply( $priced['package'] ) );
		$this->assertSame( 'applied', $applied['state'] );
		$belt = wc_get_product( (int) Packages::operations( $priced['package'] )[0]['applied']['post'] );
		$this->assertInstanceOf( WC_Product::class, $belt );
		$this->assertSame( 'Belt in oxblood', $belt->get_name() );
		$this->assertSame( '68.00', $belt->get_regular_price() );
		$this->assertSame( '59.00', $belt->get_price() );
		$this->assertSame( array( get_term_by( 'name', 'Belts', 'product_cat' )->term_id ), array_map( 'intval', $belt->get_category_ids() ) );
	}

	/**
	 * Create-product rejects a sale price without a higher regular price, a SKU another product has, an unknown category
	 * ID, an image without alt text and unsafe texts, and writes nothing.
	 *
	 * @group woocommerce
	 */
	public function test_create_product_rejections(): void {
		self::product( array( 'sku' => 'CT-TAKEN' ) );
		$before = array_sum( array_map( 'intval', (array) wp_count_posts( 'product' ) ) );
		$cases  = array(
			'sale_price' => array( 'sale_price' => '20.00' ),
			'sku'        => array( 'sku' => 'CT-TAKEN' ),
			'categories' => array( 'categories' => array( 999999 ) ),
		);
		foreach ( $cases as $reason => $change ) {
			$data = $this->assert_rejected(
				'leitvia_invalid_input',
				wp_get_ability( 'leitvia/create-product' )->execute(
					$change + array(
						'title'     => 'Pen case',
						'base_hash' => '',
					)
				)
			);
			$this->assertSame( $reason, $data['reason'] );
		}
		$data = $this->assert_rejected(
			'leitvia_invalid_input',
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'         => 'Pen case',
					'regular_price' => '20.00',
					'sale_price'    => '20.00',
					'base_hash'     => '',
				)
			)
		);
		$this->assertSame( 'sale_price', $data['reason'] );
		$this->assert_rejected(
			'leitvia_check_failed',
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'     => 'Pen case',
					'image'     => $this->image( '' ),
					'base_hash' => '',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_unsafe_content',
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'       => 'Pen case',
					'description' => '<p>Leather</p><script>alert(1)</script>',
					'base_hash'   => '',
				)
			)
		);
		wp_cache_flush();
		$this->assertSame( $before, array_sum( array_map( 'intval', (array) wp_count_posts( 'product' ) ) ) );
	}

	/**
	 * In the profile full a product without a price goes live with a package for the rollback, which deletes it.
	 *
	 * @group woocommerce
	 */
	public function test_create_product_live_and_rollback(): void {
		self::settings( array( 'profile' => 'full' ) );
		$result = $this->assert_ok(
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'     => 'Repair voucher',
					'status'    => 'publish',
					'base_hash' => '',
				)
			)
		);
		$this->assertSame( 'live', $result['mode'] );
		$id = (int) $result['target']['post'];
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertGreaterThan( 0, $result['package'] );

		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertNull( get_post( $id ) );
	}

	/**
	 * A long description in plain text becomes escaped paragraph blocks, one per part between blank lines, in
	 * create-product and update-product, so leitvia/audit-site finds no classic content. Block markup and HTML stay as
	 * they are; the short description stays text.
	 *
	 * @group woocommerce
	 */
	public function test_plain_text_description_becomes_paragraphs(): void {
		self::settings( array( 'profile' => 'full' ) );
		$result  = $this->assert_ok(
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'             => 'Card case',
					'status'            => 'publish',
					'description'       => "Vegetable-tanned leather & waxed thread.\r\nFits 6 > 5 cards.\n\n  Made in Leipzig.  ",
					'short_description' => 'Six cards, one piece of leather.',
					'base_hash'         => '',
				)
			)
		);
		$id      = (int) $result['target']['post'];
		$product = wc_get_product( $id );
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Vegetable-tanned leather &amp; waxed thread.<br>\nFits 6 &gt; 5 cards.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Made in Leipzig.</p>\n<!-- /wp:paragraph -->",
			self::without_ids( $product->get_description() )
		);
		$this->assertSame( 'Six cards, one piece of leather.', $product->get_short_description() );
		$audit = wp_list_filter( Read_Audit::audit( $id - 1, 1 )['items'], array( 'post' => $id ) );
		$this->assertNotEmpty( $audit );
		$this->assertNotContains( 'leitvia/classic-content', array_column( current( $audit )['findings'], 'check' ) );

		$update = array(
			'product'     => $id,
			'description' => 'Now with a pen loop.',
		);
		$this->assert_ok( self::call( 'leitvia/update-product', $id, $update ) );
		$this->assertSame( "<!-- wp:paragraph -->\n<p>Now with a pen loop.</p>\n<!-- /wp:paragraph -->", self::without_ids( wc_get_product( $id )->get_description() ) );
		$update['description'] = "<!-- wp:paragraph -->\n<p>Kept as <strong>sent</strong>.</p>\n<!-- /wp:paragraph -->";
		$this->assert_ok( self::call( 'leitvia/update-product', $id, $update ) );
		$this->assertSame( $update['description'], self::without_ids( wc_get_product( $id )->get_description() ) );
	}

	/**
	 * A long description in block markup has no fixed length, as the content of update-content: markup of lists and
	 * columns counts, so 20,000 characters were reached at about 950 words. create-product and update-product store
	 * more than that as sent.
	 *
	 * @group woocommerce
	 */
	public function test_long_description_has_no_fixed_length(): void {
		self::settings( array( 'profile' => 'full' ) );
		$section = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Vegetable-tanned leather, waxed thread and hand-burnished edges.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		$long    = implode( "\n\n", array_fill( 0, 120, $section ) );
		$this->assertGreaterThan( 20000, strlen( $long ) );
		$result = $this->assert_ok(
			wp_get_ability( 'leitvia/create-product' )->execute(
				array(
					'title'       => 'Card case',
					'description' => $long,
					'base_hash'   => '',
				)
			)
		);
		$id     = (int) $result['target']['post'];
		$this->assertSame( $long, self::without_ids( wc_get_product( $id )->get_description() ) );

		$longer = $long . "\n\n" . $section;
		$this->assert_ok(
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product'     => $id,
					'description' => $longer,
				)
			)
		);
		$this->assertSame( $longer, self::without_ids( wc_get_product( $id )->get_description() ) );
	}

	/**
	 * Global attributes: list-product-attributes reads name, slug, sort order, the values in their order with slugs and
	 * the hash; update-product-attribute renames the attribute and a value and sets the order in one operation, slugs
	 * stay. Live in the profile full with snapshot and rollback, a package in the profile standard (attributes have no
	 * draft) that a person applies.
	 *
	 * @group woocommerce
	 */
	public function test_product_attributes(): void {
		[ $id, $terms ] = self::attribute();
		$listed         = self::listed( $id );
		$this->assertSame( 'Colour', $listed['name'] );
		$this->assertSame( 'pa_colour', $listed['slug'] );
		$this->assertSame( 'menu_order', $listed['order_by'] );
		$this->assertSame( array( 'Red', 'Blue', 'Green' ), array_column( $listed['values'], 'name' ) );
		$this->assertSame( array( 'red', 'blue', 'green' ), array_column( $listed['values'], 'slug' ) );
		$this->assertSame( array_values( $terms ), array_column( $listed['values'], 'id' ) );
		$this->assertSame( Commerce::attribute_hash( $id ), $listed['hash'] );

		self::settings( array( 'profile' => 'full' ) );
		$input = array(
			'attribute' => $id,
			'name'      => 'Farbe',
			'values'    => array(
				array(
					'value' => $terms['Red'],
					'name'  => 'Rot',
				),
			),
			'order'     => array( $terms['Green'], $terms['Red'], $terms['Blue'] ),
		);
		// Values and their order need edit_product_terms, the name of the attribute itself manage_product_terms.
		$this->assert_rejected( 'leitvia_forbidden', wp_get_ability( 'leitvia/update-product-attribute' )->execute( $input + array( 'base_hash' => $listed['hash'] ) ) );
		$this->assertSame( 'Colour', wc_get_attribute( $id )->name );
		$values = $this->assert_ok( wp_get_ability( 'leitvia/update-product-attribute' )->execute( array_diff_key( $input, array( 'name' => true ) ) + array( 'base_hash' => $listed['hash'] ) ) );
		$this->assertSame( 'live', $values['mode'] );
		$this->assertSame( 'Rot', get_term( $terms['Red'] )->name );
		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $values['package'] ) ) );
		wp_get_current_user()->add_cap( 'manage_product_terms' );
		$result = $this->assert_ok( wp_get_ability( 'leitvia/update-product-attribute' )->execute( $input + array( 'base_hash' => Commerce::attribute_hash( $id ) ) ) );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( array( 'attribute' => $id ), $result['target'] );
		$this->assertSame( 'Farbe', wc_get_attribute( $id )->name );
		$this->assertSame( 'pa_colour', wc_get_attribute( $id )->slug );
		$this->assertSame( 'Rot', get_term( $terms['Red'] )->name );
		$this->assertSame( 'red', get_term( $terms['Red'] )->slug );
		$changed = self::listed( $id );
		$this->assertSame( array( 'Green', 'Rot', 'Blue' ), array_column( $changed['values'], 'name' ) );
		$this->assertSame( $result['hash'], $changed['hash'] );
		$snapshot = Store::get_snapshot( $result['snapshot'] );
		$this->assertSame( 'attribute', $snapshot['object_type'] );
		$this->assertSame( 'Colour', $snapshot['state']['attribute']['name'] );

		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertSame( 'rolled_back', Packages::state( $result['package'] ) );
		$this->assertSame( 'Colour', wc_get_attribute( $id )->name );
		$this->assertSame( array( 'Red', 'Blue', 'Green' ), array_column( self::listed( $id )['values'], 'name' ) );

		self::settings( array() );
		$result = $this->assert_ok( wp_get_ability( 'leitvia/update-product-attribute' )->execute( $input + array( 'base_hash' => Commerce::attribute_hash( $id ) ) ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( 'Colour', wc_get_attribute( $id )->name );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'applied', $this->assert_ok( Packages::apply( $result['package'] ) )['state'] );
		$this->assertSame( 'Farbe', wc_get_attribute( $id )->name );
		$this->assertSame( 'Rot', get_term( $terms['Red'] )->name );
		unregister_taxonomy( 'pa_colour' );
	}

	/**
	 * Update-product-attribute refuses an empty change, a value of another taxonomy, an order that leaves out or repeats
	 * a value, an order with another order_by, names without text, a stale hash and an attribute that does not exist;
	 * HTML is removed from names.
	 *
	 * @group woocommerce
	 */
	public function test_product_attribute_rejections(): void {
		[ $id, $terms ] = self::attribute();
		$tag            = self::factory()->term->create( array( 'taxonomy' => 'product_tag' ) );
		$call           = static fn( array $input ) => wp_get_ability( 'leitvia/update-product-attribute' )->execute(
			$input + array(
				'attribute' => $id,
				'base_hash' => Commerce::attribute_hash( $id ),
			)
		);
		$all            = array_values( $terms );
		foreach ( array(
			'change'   => array(),
			'values'   => array(
				'values' => array(
					array(
						'value' => $tag,
						'name'  => 'Sale',
					),
				),
			),
			'order'    => array( 'order' => array( $terms['Red'], $terms['Blue'] ) ),
			'order_by' => array(
				'order'    => $all,
				'order_by' => 'name',
			),
			'name'     => array( 'name' => '<b></b>' ),
		) as $reason => $input ) {
			$this->assertSame( $reason, $this->assert_rejected( 'leitvia_invalid_input', $call( $input ) )['reason'], $reason );
		}
		$this->assertSame( 'order', $this->assert_rejected( 'leitvia_invalid_input', $call( array( 'order' => array( $terms['Red'], $terms['Blue'], $terms['Blue'] ) ) ) )['reason'] );
		$this->assert_rejected(
			'leitvia_stale_hash',
			$call(
				array(
					'name'      => 'Farbe',
					'base_hash' => 'stale',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_out_of_scope',
			$call(
				array(
					'attribute' => $id + 1000,
					'name'      => 'Farbe',
				)
			)
		);

		self::settings( array( 'profile' => 'full' ) );
		wp_get_current_user()->add_cap( 'manage_product_terms' );
		$this->assert_ok( $call( array( 'name' => '<em>Farbe</em>' ) ) );
		$this->assertSame( 'Farbe', wc_get_attribute( $id )->name );
		$this->assertSame( 'menu_order', wc_get_attribute( $id )->order_by );
		$this->assert_ok( $call( array( 'order_by' => 'name' ) ) );
		$this->assertSame( 'name', wc_get_attribute( $id )->order_by );
		unregister_taxonomy( 'pa_colour' );
	}

	/**
	 * A product description keeps a synced pattern with its overrides (ref and content) as sent, quotes, line breaks
	 * and backslashes in the overrides included, as update-content keeps it in a page; so does a title with a
	 * backslash. A save that drops attributes fails with leitvia_attributes_lost and leaves the product as it was.
	 *
	 * @group woocommerce
	 */
	public function test_synced_pattern_in_description(): void {
		self::settings( array( 'profile' => 'full' ) );
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:paragraph {"metadata":{"bindings":{"__default":{"source":"core/pattern-overrides"}},"name":"Feature"}} --><p>Feature</p><!-- /wp:paragraph -->',
			)
		);
		$id      = self::product();
		$attrs   = array(
			'ref'     => $pattern,
			'content' => array( 'Feature' => array( 'content' => "Oiled \"oak\"\nwith a 2\\3 joint" ) ),
		);
		// As agents write it: JSON with backslash escapes, not the " spelling of serialize_block().
		$markup = '<!-- wp:block ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
		$this->assert_ok(
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product'     => $id,
					'title'       => 'Oak tray 2\\3',
					'description' => $markup,
				)
			)
		);
		$product = wc_get_product( $id );
		$this->assertSame( 'Oak tray 2\\3', $product->get_name() );
		$blocks = parse_blocks( $product->get_description() );
		$this->assertSame( 'core/block', $blocks[0]['blockName'] );
		unset( $blocks[0]['attrs']['metadata'] );
		$this->assertSame( $attrs, $blocks[0]['attrs'] );

		$stored = $product->get_description();
		// Only the new text loses its attributes; restoring the stored one works.
		$drop = static function ( $content ) {
			if ( ! str_contains( (string) $content, 'Oiled oak' ) ) {
				return $content;
			}
			$blocks = parse_blocks( wp_unslash( (string) $content ) );
			unset( $blocks[0]['attrs']['content'] );
			return wp_slash( serialize_blocks( $blocks ) );
		};
		add_filter( 'content_save_pre', $drop, 99 );
		$attrs['content']['Feature']['content'] = 'Oiled oak';
		$data                                   = $this->assert_rejected(
			'leitvia_attributes_lost',
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product'     => $id,
					'description' => '<!-- wp:block ' . wp_json_encode( $attrs ) . ' /-->',
				)
			)
		);
		remove_filter( 'content_save_pre', $drop, 99 );
		$this->assertSame(
			array(
				array(
					'block'      => 'core/block',
					'index'      => 0,
					'attributes' => array( 'content' ),
				),
			),
			$data['lost']
		);
		$this->assertSame( $stored, wc_get_product( $id )->get_description() );
	}

	/**
	 * The texts reach WooCommerce unslashed: a rename with an apostrophe gives the variations of a variable product the
	 * name without a backslash, and a write of the price alone leaves the post row (description with quotes) untouched.
	 *
	 * @group woocommerce
	 */
	public function test_texts_unslashed_in_woocommerce(): void {
		self::settings(
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => array( 'prices' ),
				),
			)
		);
		$variable = new WC_Product_Variable();
		$variable->set_props(
			array(
				'name'   => 'Oak tray',
				'status' => 'publish',
			)
		);
		$id        = $variable->save();
		$variation = new WC_Product_Variation();
		$variation->set_props(
			array(
				'parent_id'     => $id,
				'status'        => 'publish',
				'regular_price' => '48.00',
			)
		);
		$child = $variation->save();
		$this->assertStringContainsString( 'Oak tray', get_post_field( 'post_title', $child, 'raw' ) );
		$this->assert_ok(
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product' => $id,
					'title'   => "Tom's tray",
				)
			)
		);
		$this->assertSame( "Tom's tray", get_post_field( 'post_title', $id, 'raw' ) );
		$this->assertStringContainsString( "Tom's tray", get_post_field( 'post_title', $child, 'raw' ) );
		$this->assertStringNotContainsString( '\\', get_post_field( 'post_title', $child, 'raw' ) );

		$simple  = self::product( array( 'description' => '<p>A "tray" of oiled oak.</p>' ) );
		$written = 0;
		$count   = static function () use ( &$written ): void {
			++$written;
		};
		add_action( 'pre_post_update', $count );
		$result = $this->assert_ok(
			self::call(
				'leitvia/update-product',
				$simple,
				array(
					'product'       => $simple,
					'regular_price' => '59.00',
				)
			)
		);
		remove_action( 'pre_post_update', $count );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( '59.00', wc_get_product( $simple )->get_regular_price() );
		$this->assertSame( 0, $written, 'no wp_update_post() for a price' );
		$this->assertSame( '<p>A "tray" of oiled oak.</p>', get_post_field( 'post_content', $simple, 'raw' ) );
	}

	/**
	 * A product description keeps a stored block KSES would cut (Custom HTML a person wrote) byte for byte when an
	 * agent changes another part, as update-content does, and rejects a new one as html_block.
	 *
	 * @group woocommerce
	 */
	public function test_stored_html_block_in_description(): void {
		self::settings( array( 'profile' => 'full' ) );
		$html = '<!-- wp:html --><iframe src="https://example.org/map"></iframe><!-- /wp:html -->';
		// As a person with unfiltered_html stores it.
		kses_remove_filters();
		$id = self::product( array( 'description' => $html ) );
		kses_init_filters();
		$stored = wc_get_product( $id )->get_description();
		$this->assertStringContainsString( '<iframe', $stored );
		$this->assert_ok(
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product'     => $id,
					'description' => $stored . '<!-- wp:paragraph --><p>Oiled oak.</p><!-- /wp:paragraph -->',
				)
			)
		);
		$this->assertStringContainsString( '<iframe src="https://example.org/map"></iframe>', wc_get_product( $id )->get_description() );
		$data = $this->assert_rejected(
			'leitvia_unsafe_content',
			self::call(
				'leitvia/update-product',
				$id,
				array(
					'product'     => $id,
					'description' => $stored . '<!-- wp:html --><iframe src="https://example.org/x"></iframe><!-- /wp:html -->',
				)
			)
		);
		$this->assertSame( 'html_block', $data['reason'] );
	}

	/**
	 * A review of the product, approved unless said otherwise, with this rating (0 for none).
	 *
	 * @param int    $product  Product ID.
	 * @param int    $rating   Rating, 0 for none.
	 * @param string $approved comment_approved.
	 */
	private static function review( int $product, int $rating, string $approved = '1' ): int {
		$id = (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $product,
				'comment_type'         => 'review',
				'comment_approved'     => $approved,
				'comment_author'       => 'Ann',
				'comment_author_email' => 'ann@example.org',
				'comment_author_IP'    => '192.0.2.1',
				'comment_content'      => 'A fine tray.',
			)
		);
		if ( $rating ) {
			update_comment_meta( $id, 'rating', $rating );
		}
		WC_Comments::clear_transients( $product );
		return $id;
	}

	/**
	 * Reviews: list-product-reviews reads approved reviews with rating, verified, status and hash but no email or IP
	 * address; held ones only for a person with moderate_comments. update-product-review sets rating and verified
	 * live in profile full with rollback, as a package in standard; the product counts its average again. A new status
	 * needs moderate_comments.
	 *
	 * @group woocommerce
	 */
	public function test_product_reviews(): void {
		$product = self::product();
		$unrated = self::review( $product, 0 );
		$rated   = self::review( $product, 4 );
		$held    = self::review( $product, 5, '0' );
		$list    = static fn( array $input = array() ) => wp_get_ability( 'leitvia/list-product-reviews' )->execute( $input );

		$all = $list( array( 'product' => $product ) );
		$this->assertSame( array( $unrated, $rated ), array_column( $all['reviews'], 'id' ) );
		$this->assertSame( 2, $all['total'] );
		$this->assertSame( 1, $all['unrated_total'] );
		$this->assertSame( array( 0, 4 ), array_column( $all['reviews'], 'rating' ) );
		$this->assertSame( array( 'approved', 'approved' ), array_column( $all['reviews'], 'status' ) );
		$this->assertSame( 'Ann', $all['reviews'][0]['author'] );
		$this->assertSame( 'A fine tray.', $all['reviews'][0]['content'] );
		$this->assertSame( Commerce::review_hash( $unrated ), $all['reviews'][0]['hash'] );
		$this->assertStringNotContainsString( 'ann@example.org', (string) wp_json_encode( $all ) );
		$this->assertStringNotContainsString( '192.0.2.1', (string) wp_json_encode( $all ) );
		$this->assertSame( array( $unrated ), array_column( $list( array( 'unrated' => true ) )['reviews'], 'id' ) );
		// Agents lack moderate_comments: held reviews stay hidden.
		$this->assertSame( array( $unrated, $rated ), array_column( $list( array( 'status' => 'hold' ) )['reviews'], 'id' ) );
		$this->assertSame( 4.0, (float) wc_get_product( $product )->get_average_rating() );

		self::settings( array( 'profile' => 'full' ) );
		$update = static fn( int $review, array $input ) => wp_get_ability( 'leitvia/update-product-review' )->execute(
			$input + array(
				'review'    => $review,
				'base_hash' => Commerce::review_hash( $review ),
			)
		);
		$result = $this->assert_ok( $update( $unrated, array( 'rating' => 5 ) ) );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( array( 'review' => $unrated ), $result['target'] );
		$this->assertSame( '5', get_comment_meta( $unrated, 'rating', true ) );
		$this->assertSame( 'A fine tray.', get_comment( $unrated )->comment_content );
		$this->assertSame( Commerce::review_hash( $unrated ), $result['hash'] );
		$this->assertSame( 4.5, (float) wc_get_product( $product )->get_average_rating() );
		$this->assertSame( 0, $list( array( 'product' => $product ) )['unrated_total'] );
		$snapshot = Store::get_snapshot( $result['snapshot'] );
		$this->assertSame( 'review', $snapshot['object_type'] );
		$this->assertSame( 0, $snapshot['state']['review']['rating'] );

		// Without moderate_comments only lost stars come back: another rating, the verified flag and a status are
		// moderation, and held reviews do not exist for agents.
		foreach ( array( array( 'status' => 'spam' ), array( 'rating' => 1 ), array( 'verified' => true ) ) as $input ) {
			$this->assert_rejected( 'leitvia_forbidden', $update( $rated, $input ) );
		}
		$this->assert_rejected( 'leitvia_out_of_scope', $update( $held, array( 'rating' => 1 ) ) );
		$this->assertSame( '1', get_comment( $rated )->comment_approved );
		$this->assertSame( '4', get_comment_meta( $rated, 'rating', true ) );

		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertSame( 'rolled_back', Packages::state( $result['package'] ) );
		$this->assertSame( '', get_comment_meta( $unrated, 'rating', true ) );
		$this->assertSame( 4.0, (float) wc_get_product( $product )->get_average_rating() );

		self::settings( array() );
		$result = $this->assert_ok( $update( $unrated, array( 'rating' => 3 ) ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( '', get_comment_meta( $unrated, 'rating', true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'applied', $this->assert_ok( Packages::apply( $result['package'] ) )['state'] );
		$this->assertSame( '3', get_comment_meta( $unrated, 'rating', true ) );

		// A person with moderate_comments sees held reviews and approves them.
		$this->assertSame( array( $held ), array_column( $list( array( 'status' => 'hold' ) )['reviews'], 'id' ) );
		self::settings( array( 'profile' => 'full' ) );
		$this->assert_ok( $update( $held, array( 'status' => 'approved' ) ) );
		$this->assertSame( '1', get_comment( $held )->comment_approved );
		$this->assertSame( 3, wc_get_product( $product )->get_review_count() );
		$this->assertSame( 4.0, (float) wc_get_product( $product )->get_average_rating() );
		$this->assert_ok( $update( $held, array( 'verified' => true ) ) );
		$this->assertSame( '1', get_comment_meta( $held, 'verified', true ) );

		// Trash through core: its meta lets the admin restore the review as it was; a rollback takes it back out.
		$trashed = $this->assert_ok( $update( $rated, array( 'status' => 'trash' ) ) );
		$this->assertSame( 'trash', get_comment( $rated )->comment_approved );
		$this->assertSame( '1', get_comment_meta( $rated, '_wp_trash_meta_status', true ) );
		$this->assertSame( 'trash', $list( array( 'status' => 'trash' ) )['status'] );
		$this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $trashed['package'] ) ) );
		$this->assertSame( '1', get_comment( $rated )->comment_approved );
		$this->assertSame( '', get_comment_meta( $rated, '_wp_trash_meta_status', true ) );
	}

	/**
	 * Update-product-review refuses an empty change, a stale hash, a comment of a post and a reply to a review.
	 *
	 * @group woocommerce
	 */
	public function test_product_review_rejections(): void {
		$product = self::product();
		$review  = self::review( $product, 0 );
		$reply   = (int) wp_insert_comment(
			array(
				'comment_post_ID' => $product,
				'comment_parent'  => $review,
				'comment_content' => 'Thank you.',
			)
		);
		$comment = (int) self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) );
		$call    = static fn( int $id, array $input ) => wp_get_ability( 'leitvia/update-product-review' )->execute(
			$input + array(
				'review'    => $id,
				'base_hash' => Commerce::review_hash( $id ),
			)
		);
		$this->assertSame( 'change', $this->assert_rejected( 'leitvia_invalid_input', $call( $review, array() ) )['reason'] );
		$this->assert_rejected(
			'leitvia_stale_hash',
			$call(
				$review,
				array(
					'rating'    => 5,
					'base_hash' => 'stale',
				)
			)
		);
		foreach ( array( $reply, $comment ) as $id ) {
			$this->assertSame( '', Commerce::review_hash( $id ) );
			$this->assert_rejected( 'leitvia_out_of_scope', $call( $id, array( 'rating' => 5 ) ) );
		}
		$this->assertSame( array( $review ), array_column( wp_get_ability( 'leitvia/list-product-reviews' )->execute( array() )['reviews'], 'id' ) );
	}
}
