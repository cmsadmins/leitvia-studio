<?php
/**
 * Gateway commerce abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Preview;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Gateway\Store;

/**
 * Abilities list-products, get-product, update-product, update-product-category and set-merchandising: reads without
 * sales or customer figures, writes through the pipeline in the area products, prices, taxes and shipping at most
 * "suggest", orders, refunds, coupons and customers out of scope, rollback for products. The group woocommerce runs
 * with WooCommerce loaded (npm run test:php:commerce).
 */
class Test_Gateway_Commerce extends WP_UnitTestCase {

	/**
	 * Names of the five abilities.
	 */
	private const ABILITIES = array( 'leitvia/list-products', 'leitvia/get-product', 'leitvia/update-product', 'leitvia/update-product-category', 'leitvia/set-merchandising' );

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
	 * Without WooCommerce the five abilities, the products area and product types do not exist.
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
}
