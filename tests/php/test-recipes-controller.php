<?php
/**
 * REST route /leitvia-studio/v1/recipes of the Recipes screen and applying a recipe through the Abilities API.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Studio;
use LeitviaStudio\Gateway\Gateway;

/**
 * Recipes screen data.
 *
 * @group multisite
 */
class Test_Recipes_Controller extends WP_UnitTestCase {

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
	 * Removes the abilities, the category and the add-on of a test.
	 */
	public function tear_down(): void {
		self::unregister();
		remove_all_actions( 'leitvia_studio_init', 20 );
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
	 * GET /leitvia-studio/v1/recipes.
	 */
	private static function list(): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/recipes' ) );
	}

	/**
	 * Only people who may edit the theme read the list.
	 */
	public function test_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, self::list()->get_status() );
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( 403, self::list()->get_status(), $role );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, self::list()->get_status() );
	}

	/**
	 * The list holds what list-recipes reports plus source and plan: pages with title, parent and sections, navigation
	 * labels, header and footer, settings. A recipe of an add-on names the add-on; its missing plugin makes it
	 * unavailable with the reason.
	 */
	public function test_list_with_source_and_plan(): void {
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ): void {
				$studio->extensions()->register(
					'test-addon',
					array(
						'label'           => 'Test add-on',
						'version'         => '1.0.0',
						'requires_studio' => '0.1.0',
						'requires_api'    => LEITVIA_STUDIO_API,
					)
				);
				$studio->registry( 'recipes' )->register(
					'test-addon/shop',
					array(
						'label' => 'Shop',
						'path'  => __DIR__ . '/../fixtures/gateway/recipes/shop/recipe.json',
					),
					'test-addon'
				);
			},
			20
		);
		self::boot();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$items = array_column( self::list()->get_data(), null, 'name' );

		$blog = $items['leitvia/blog'];
		$this->assertSame( 'core', $blog['source'] );
		$this->assertSame( '', $blog['sourceLabel'] );
		$this->assertTrue( $blog['available'] );
		$this->assertNotSame( '', $blog['title'] );
		$this->assertNotSame( '', $blog['description'] );
		$plan = $blog['plan'];
		$this->assertSame( $blog['pages'], array_column( $plan['pages'], 'slug' ) );
		foreach ( $plan['pages'] as $page ) {
			$this->assertNotSame( '', $page['title'] );
			$this->assertGreaterThan( 0, $page['sections'] );
		}
		$this->assertNotSame( '', $plan['variation'] );
		$this->assertArrayHasKey( 'header', (array) $plan['templateParts'] );
		$this->assertNotEmpty( $plan['navigation'] );
		$this->assertContains( 'show_on_front', $plan['settings'] );
		$this->assertSame( '', $plan['tokenSet'] );
		$this->assertSame( array(), $plan['contentModel'] );
		// Navigation shows page titles, not slugs.
		$titles = array_column( $plan['pages'], 'title' );
		$this->assertNotEmpty( array_intersect( $plan['navigation'], $titles ) );

		$addon = $items['test-addon/shop'];
		$this->assertSame( 'addon', $addon['source'] );
		$this->assertSame( 'Test add-on', $addon['sourceLabel'] );
		$this->assertFalse( $addon['available'] );
		$this->assertStringContainsString( 'leitvia-test-missing-plugin', $addon['reason'] );
		$this->assertSame( array( 'Shop' ), array_column( $addon['plan']['pages'], 'title' ) );
	}

	/**
	 * The screen applies through POST /wp-abilities/v1/abilities/leitvia/apply-recipe/run: at "suggest" everything waits
	 * in one change package, nothing goes live; an unavailable recipe is refused with its reason.
	 */
	public function test_apply_through_the_abilities_api(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
					'kinds' => array(),
				),
			) + Gateway::DEFAULTS
		);
		$run       = static function ( string $recipe ): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/leitvia/apply-recipe/run' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'input' => array( 'recipe' => $recipe ) ) ) );
			return rest_do_request( $request );
		};
		$published = (int) wp_count_posts( 'page' )->publish;
		$response  = $run( 'leitvia/company' );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$result = $response->get_data();
		$this->assertSame( 'leitvia/company', $result['recipe'] );
		$this->assertGreaterThan( 0, $result['package'] );
		$this->assertContains( 'package', array_column( $result['steps'], 'status' ) );
		$this->assertNotContains( 'live', array_column( $result['steps'], 'status' ) );
		$this->assertSame( $published, (int) wp_count_posts( 'page' )->publish );

		$refused = $run( 'leitvia/shop' );
		$this->assertSame( 422, $refused->get_status() );
		$this->assertSame( 'leitvia_recipe_unavailable', $refused->get_data()['code'] );
	}
}
