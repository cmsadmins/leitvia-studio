<?php
/**
 * Recipe shop: WooCommerce required, header-shop, home page from the shop patterns, legal pages, privacy defaults.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Recipes;

/**
 * The group woocommerce runs with WooCommerce loaded (npm run test:php:commerce), the rest without it.
 */
class Test_Recipe_Shop extends WP_UnitTestCase {

	/**
	 * Options the recipe turns off.
	 */
	private const OFF = array( 'woocommerce_feature_order_attribution_enabled', 'woocommerce_allow_tracking', 'woocommerce_show_marketplace_suggestions', 'woocommerce_feature_remote_logging_enabled' );

	/**
	 * Legal page slugs and their kinds.
	 */
	private const LEGAL = array(
		'imprint'    => 'imprint',
		'privacy'    => 'privacy',
		'terms'      => 'terms',
		'withdrawal' => 'withdrawal',
	);

	/**
	 * Leitvia theme, the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		self::boot();
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
		wp_clean_theme_json_cache();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Runs leitvia_studio_init again and hands the abilities to core.
	 */
	private static function boot(): void {
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
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
	 * Signs in a user with a role and the gateway settings; WooCommerce counts as active and its data options are on.
	 *
	 * @param string               $role     Role.
	 * @param array<string, mixed> $settings Settings over the defaults.
	 */
	private function sign_in( string $role, array $settings = array() ): void {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		foreach ( self::OFF as $option ) {
			update_option( $option, 'yes' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		update_option( Gateway::OPTION, $settings + Gateway::DEFAULTS );
	}

	/**
	 * Applies the recipe; no step may be rejected.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(): array {
		$result = wp_get_ability( 'leitvia/apply-recipe' )->execute( array( 'recipe' => 'leitvia/shop' ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		foreach ( $result['steps'] as $step ) {
			$this->assertNotSame( 'rejected', $step['status'], wp_json_encode( $step ) );
		}
		return $result;
	}

	/**
	 * Statuses of the steps with this name.
	 *
	 * @param array<string, mixed> $result Result.
	 * @param string               $step   Step name.
	 * @return list<string>
	 */
	private static function statuses( array $result, string $step ): array {
		return array_values( array_column( array_filter( $result['steps'], static fn( array $s ): bool => $step === $s['step'] ), 'status' ) );
	}

	/**
	 * Posts of every status per type a recipe writes: pages, template parts, navigation, global styles, packages.
	 *
	 * @return array<string, int>
	 */
	private static function footprint(): array {
		$counts = array();
		foreach ( array( 'page', 'wp_template_part', 'wp_navigation', 'wp_global_styles', Packages::POST_TYPE ) as $type ) {
			$counts[ $type ] = array_sum( array_map( 'intval', (array) wp_count_posts( $type ) ) );
		}
		return $counts;
	}

	/**
	 * A second run of a recipe: every step unchanged or exists, no package, nothing new on the site.
	 *
	 * @param array<string, mixed> $again  Result of the second run.
	 * @param array<string, int>   $before Footprint before it.
	 */
	private function assert_nothing_new( array $again, array $before ): void {
		foreach ( $again['steps'] as $step ) {
			if ( 'unavailable' !== $step['status'] ) {
				$this->assertContains( $step['status'], array( 'unchanged', 'exists' ), wp_json_encode( $step ) );
			}
			$this->assertSame( 0, $step['package'], wp_json_encode( $step ) );
		}
		$this->assertSame( 0, $again['package'] );
		$this->assertSame( $before, self::footprint() );
	}

	/**
	 * Without WooCommerce the recipe is valid, listed as unavailable with the reason and refused.
	 */
	public function test_unavailable_without_woocommerce(): void {
		$recipe = Recipes::load( dirname( LEITVIA_STUDIO_FILE ) . '/recipes/shop/recipe.json' );
		$this->assertIsArray( $recipe, is_wp_error( $recipe ) ? $recipe->get_error_message() : '' );
		$this->assertSame( array( 'woocommerce' ), $recipe['requires'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$list = array_column( wp_get_ability( 'leitvia/list-recipes' )->execute(), null, 'name' );
		$this->assertFalse( $list['leitvia/shop']['available'] );
		$this->assertStringContainsString( 'woocommerce', $list['leitvia/shop']['reason'] );
		$this->assertSame( 'leitvia_recipe_unavailable', wp_get_ability( 'leitvia/apply-recipe' )->execute( array( 'recipe' => 'leitvia/shop' ) )->get_error_code() );
	}

	/**
	 * Everything at "suggest": one package with header-shop, every page and the options turned off; nothing goes
	 * live, and a second run adds nothing.
	 *
	 * @group woocommerce
	 */
	public function test_one_package_and_idempotent(): void {
		$this->sign_in(
			Gateway::ROLE,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
					'kinds' => array(),
				),
			)
		);
		$result = $this->apply();
		$this->assertGreaterThan( 0, $result['package'] );
		$this->assertSame( array_fill( 0, 8, 'package' ), self::statuses( $result, 'pages' ) );
		$operations = Packages::operations( $result['package'] );
		$header     = array_values( array_filter( $operations, static fn( $op ) => 'leitvia/update-template-part' === $op['ability'] && str_contains( (string) ( $op['state']['post_content'] ?? '' ), 'woocommerce/mini-cart' ) ) );
		$this->assertCount( 1, $header, 'header-shop as the header part' );
		$options = array_column( array_column( array_filter( $operations, static fn( $op ) => isset( $op['target']['option'] ) ), 'target' ), 'option' );
		foreach ( self::OFF as $option ) {
			$this->assertContains( $option, $options );
			$this->assertSame( 'yes', get_option( $option ), 'still waiting' );
		}
		$this->assertSame( array( 'package', 'package' ), self::statuses( $result, 'connect' ), 'privacy and terms settings go into the package with their pages' );

		$again = $this->apply();
		$this->assertSame( $result['package'], $again['package'] );
		$this->assertCount( count( $operations ), Packages::operations( $result['package'] ) );
	}

	/**
	 * Live: header-shop, a home page that passes the composition check, legal pages with kind and placeholder text,
	 * WooCommerce pages connected, the data options off, the shop page in the menu. A second run changes nothing: no
	 * package, draft or post, variation, template parts and navigation unchanged.
	 *
	 * @group woocommerce
	 */
	public function test_live_shop(): void {
		$shop = get_page_by_path( 'shop' );
		if ( ! $shop ) {
			self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Shop',
					'post_name'   => 'shop',
					'post_status' => 'publish',
				)
			);
		}
		$this->sign_in(
			'administrator',
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => Gateway::KINDS,
				),
			)
		);
		$result = $this->apply();
		$this->assertSame( array_fill( 0, 8, 'live' ), self::statuses( $result, 'pages' ) );
		$this->assertSame( 0, $result['package'] );

		$part = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		$this->assertStringContainsString( 'woocommerce/mini-cart', $part->content );

		$home = get_page_by_path( 'home' );
		$this->assertSame( $home->ID, (int) get_option( 'page_on_front' ) );
		$sections = Composition::sections( $home->post_content );
		$this->assertSame( array( 'leitvia/shop-product-hero', 'leitvia/shop-category-tiles', 'leitvia/shop-new-arrivals', 'leitvia/shop-usp-bar', 'leitvia/shop-trust-badges', 'leitvia/cta-banner' ), $sections );
		$this->assertGreaterThanOrEqual( 80, Composition::score( $sections, leitvia_studio_contract()->data(), 'page-landing' )['score'] );
		$this->assertStringNotContainsString( 'href="#"', $home->post_content );

		foreach ( self::LEGAL as $slug => $kind ) {
			$page = get_page_by_path( $slug );
			$this->assertSame( $kind, get_post_meta( $page->ID, '_leitvia_page_kind', true ), $slug );
			$this->assertStringContainsString( 'This page is a placeholder', $page->post_content, $slug );
		}
		$this->assertNotNull( get_page_by_path( 'shipping-and-payment' ) );
		$this->assertSame( get_page_by_path( 'privacy' )->ID, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertSame( get_page_by_path( 'terms' )->ID, (int) get_option( 'woocommerce_terms_page_id' ) );
		foreach ( self::OFF as $option ) {
			$this->assertSame( 'no', get_option( $option ), $option );
		}
		$navigation = array_values( array_filter( $result['steps'], static fn( $s ) => 'navigation' === $s['step'] ) );
		$this->assertCount( 1, $navigation );

		$before = self::footprint();
		$again  = $this->apply();
		$this->assertSame( array_fill( 0, 8, 'exists' ), self::statuses( $again, 'pages' ) );
		$this->assertSame( array( 'exists', 'exists' ), self::statuses( $again, 'connect' ) );
		$this->assertSame( array_fill( 0, 7, 'exists' ), self::statuses( $again, 'options' ) );
		foreach ( array( 'variation', 'templateParts', 'navigation' ) as $step ) {
			$this->assertSame( array( 'unchanged' ), array_unique( self::statuses( $again, $step ) ), $step );
		}
		$this->assert_nothing_new( $again, $before );
	}

	/**
	 * Standard profile for an agent: ordinary pages become drafts, legal pages at most a suggestion.
	 *
	 * @group woocommerce
	 */
	public function test_legal_pages_are_suggestions(): void {
		$this->sign_in( Gateway::ROLE );
		$result = $this->apply();
		$pages  = array_column( array_filter( $result['steps'], static fn( $s ) => 'pages' === $s['step'] ), 'status', null );
		$slugs  = array( 'home', 'about', 'contact', 'imprint', 'privacy', 'terms', 'withdrawal', 'shipping-and-payment' );
		$status = array_combine( $slugs, $pages );
		foreach ( array_keys( self::LEGAL ) as $slug ) {
			$this->assertSame( 'package', $status[ $slug ], $slug );
		}
		$this->assertSame( 'draft', $status['home'] );
		$this->assertSame( 'draft', $status['shipping-and-payment'] );
		foreach ( self::OFF as $option ) {
			$this->assertSame( 'yes', get_option( $option ), 'settings wait for a person' );
		}
	}

	/**
	 * The benefits bar where its useWhen puts it, right below the h1 of a shop hero, passes the heading check.
	 *
	 * @group woocommerce
	 */
	public function test_usp_bar_right_below_the_hero(): void {
		$this->sign_in( Gateway::ROLE );
		$result = wp_get_ability( 'leitvia/compose-page' )->execute(
			array(
				'title'     => 'Shop home',
				'frame'     => 'home',
				'sections'  => array(
					array(
						'pattern' => 'leitvia/shop-product-hero',
						'edits'   => array(
							array(
								'block' => 'p:0.0.0.3.0',
								'url'   => '/shop/',
							),
							array(
								'block' => 'p:0.0.0.3.1',
								'url'   => '/about/',
							),
						),
					),
					array( 'pattern' => 'leitvia/shop-usp-bar' ),
				),
				'base_hash' => '',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( array(), array_filter( $result['findings'], static fn( array $f ): bool => 'leitvia/headings' === $f['check'] ) );
	}
}
