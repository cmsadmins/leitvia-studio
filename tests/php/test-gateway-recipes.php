<?php
/**
 * Gateway site recipes.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Studio;
use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Recipes;
use LeitviaStudio\Tokens\Compiler;

/**
 * Base recipes, the registry recipes, list-recipes and apply-recipe through the build abilities and the pipeline.
 *
 * @group multisite
 */
class Test_Gateway_Recipes extends WP_UnitTestCase {

	/**
	 * Fixture recipe of a test add-on.
	 */
	private const ADDON_RECIPE = __DIR__ . '/../fixtures/gateway/recipes/shop/recipe.json';

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
	 * Signs in a user with a role and sets the gateway settings.
	 *
	 * @param string               $role     Role.
	 * @param array<string, mixed> $settings Settings over the defaults.
	 */
	private function sign_in( string $role, array $settings = array() ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		update_option( Gateway::OPTION, $settings + Gateway::DEFAULTS );
	}

	/**
	 * Applies a recipe through the ability.
	 *
	 * @param string $name Recipe name.
	 * @return array<string, mixed>
	 */
	private function apply( string $name ): array {
		$result = wp_get_ability( 'leitvia/apply-recipe' )->execute( array( 'recipe' => $name ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		foreach ( $result['steps'] as $step ) {
			$this->assertNotSame( 'rejected', $step['status'], wp_json_encode( $step ) );
		}
		return $result;
	}

	/**
	 * Steps of a result by status.
	 *
	 * @param array<string, mixed> $result Result.
	 * @param string               $step   Step name.
	 * @return list<string>
	 */
	private static function statuses( array $result, string $step ): array {
		return array_values( array_column( array_filter( $result['steps'], static fn( array $s ): bool => $step === $s['step'] ), 'status' ) );
	}

	/**
	 * Profile full, every content kind unlocked: an administrator gets everything live.
	 */
	private function sign_in_live(): void {
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
	 * Every base recipe validates, and each section is a registered pattern (the patterns of a recipe that requires a
	 * plugin are checked where the plugin runs, see Test_Recipe_Shop).
	 */
	public function test_base_recipes_are_valid(): void {
		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( Recipes::BASE as $name ) {
			$recipe = Recipes::load( dirname( LEITVIA_STUDIO_FILE ) . "/recipes/{$name}/recipe.json" );
			$this->assertIsArray( $recipe, is_wp_error( $recipe ) ? $recipe->get_error_message() : '' );
			if ( ! empty( $recipe['requires'] ) ) {
				continue;
			}
			foreach ( $recipe['pages'] as $page ) {
				foreach ( $page['sections'] as $section ) {
					$slug = is_string( $section ) ? $section : $section['pattern'];
					$this->assertTrue( $registry->is_registered( $slug ), "{$name}: {$slug}" );
				}
			}
			foreach ( $recipe['templateParts'] as $pattern ) {
				$this->assertTrue( $registry->is_registered( $pattern ), "{$name}: {$pattern}" );
			}
		}
		$broken = Recipes::load( __FILE__ );
		$this->assertSame( 'leitvia_invalid_input', $broken->get_error_code() );
	}

	/**
	 * The ability list-recipes shows the base recipes with owner leitvia-studio and the entry of a compatible add-on; its missing
	 * plugin makes it unavailable with a reason, and apply-recipe refuses it. An incompatible add-on cannot register.
	 */
	public function test_list_recipes_and_registry(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$addon = static function ( string $slug, int $api ): array {
			return array(
				'label'           => $slug,
				'version'         => '1.0.0',
				'requires_studio' => '0.1.0',
				'requires_api'    => $api,
			);
		};
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ) use ( $addon ): void {
				$entry = array(
					'label' => 'Shop',
					'path'  => self::ADDON_RECIPE,
				);
				$studio->extensions()->register( 'test-addon', $addon( 'test-addon', LEITVIA_STUDIO_API ) );
				$studio->registry( 'recipes' )->register( 'test-addon/shop', $entry, 'test-addon' );
				$studio->extensions()->register( 'old-addon', $addon( 'old-addon', LEITVIA_STUDIO_API + 1 ) );
				$studio->registry( 'recipes' )->register( 'old-addon/shop', $entry, 'old-addon' );
			},
			20
		);
		self::boot();
		$this->sign_in( Gateway::ROLE );
		$list = array_column( wp_get_ability( 'leitvia/list-recipes' )->execute(), null, 'name' );
		$this->assertSame( array( 'leitvia/blog', 'leitvia/company', 'leitvia/publication', 'leitvia/documentation', 'leitvia/shop', 'test-addon/shop' ), array_keys( $list ) );
		$this->assertSame( 'leitvia-studio', $list['leitvia/publication']['owner'] );
		$this->assertTrue( $list['leitvia/publication']['available'] );
		$this->assertContains( 'imprint', $list['leitvia/publication']['pages'] );
		$this->assertSame( 'test-addon', $list['test-addon/shop']['owner'] );
		$this->assertFalse( $list['test-addon/shop']['available'] );
		$this->assertStringContainsString( 'leitvia-test-missing-plugin', $list['test-addon/shop']['reason'] );
		$refused = wp_get_ability( 'leitvia/apply-recipe' )->execute( array( 'recipe' => 'test-addon/shop' ) );
		$this->assertSame( 'leitvia_recipe_unavailable', $refused->get_error_code() );
		$unknown = wp_get_ability( 'leitvia/apply-recipe' )->execute( array( 'recipe' => 'leitvia/nothing' ) );
		$this->assertSame( 'leitvia_unknown_recipe', $unknown->get_error_code() );
		$this->assertSame( 'leitvia/list-recipes', $unknown->get_error_data()['next_ability'] );
	}

	/**
	 * Everything at "suggest": one package holds every page and setting, written by compose-page and apply-recipe,
	 * with the page kinds in the stored state. A second run adds nothing.
	 */
	public function test_apply_creates_one_package(): void {
		$this->sign_in(
			Gateway::ROLE,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
					'kinds' => array(),
				),
			)
		);
		$pages  = (int) wp_count_posts( 'page' )->draft + (int) wp_count_posts( 'page' )->publish;
		$result = $this->apply( 'leitvia/company' );
		$this->assertGreaterThan( 0, $result['package'] );
		$this->assertSame( array( 'package', 'package', 'package', 'package', 'package' ), self::statuses( $result, 'pages' ) );
		$operations = Packages::operations( $result['package'] );
		$abilities  = array_count_values( array_column( $operations, 'ability' ) );
		$this->assertSame( 5, $abilities['leitvia/compose-page'] );
		$this->assertSame( 5, $abilities['leitvia/apply-recipe'], 'show_on_front, page_on_front, three options' );
		$this->assertSame( array( 'show_on_front', 'page_on_front', 'default_pingback_flag', 'default_ping_status', 'default_comment_status' ), array_column( array_column( array_filter( $operations, static fn( $op ) => isset( $op['target']['option'] ) ), 'target' ), 'option' ) );
		$this->assertSame( array( 'package' ), array_values( array_column( array_filter( $result['steps'], static fn( $s ) => 'page_on_front' === ( $s['target']['option'] ?? '' ) ), 'status' ) ) );
		$this->assertSame( array( 'home' ), array_values( array_filter( array_column( array_column( $operations, 'target' ), 'page' ) ) ), 'page_on_front points to the home page of the package' );
		$kinds = array_column( array_column( array_column( $operations, 'state' ), 'meta_input' ), '_leitvia_page_kind' );
		$this->assertSame( array( 'contact', 'imprint' ), $kinds );
		foreach ( array( 'variation', 'templateParts', 'navigation' ) as $step ) {
			foreach ( array_filter( $result['steps'], static fn( $s ) => $step === $s['step'] ) as $entry ) {
				$this->assertSame( wp_has_ability( $entry['ability'] ) ? $entry['status'] : 'unavailable', $entry['status'] );
			}
		}
		$this->assertSame( $pages, (int) wp_count_posts( 'page' )->draft + (int) wp_count_posts( 'page' )->publish, 'nothing written' );

		$again = $this->apply( 'leitvia/company' );
		$this->assertSame( $result['package'], $again['package'] );
		$this->assertCount( count( $operations ), Packages::operations( $result['package'] ) );
	}

	/**
	 * A package key from the caller puts every part into that package even where the administrator could write live;
	 * applying the one package sets the front page to the page it created, without a second run.
	 */
	public function test_package_key_holds_everything(): void {
		$this->sign_in_live();
		$pages  = (int) wp_count_posts( 'page' )->draft + (int) wp_count_posts( 'page' )->publish;
		$front  = get_option( 'page_on_front' );
		$result = wp_get_ability( 'leitvia/apply-recipe' )->execute(
			array(
				'recipe'  => 'leitvia/company',
				'package' => 'company-task',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		foreach ( $result['steps'] as $step ) {
			$this->assertContains( $step['status'], array( 'package', 'unavailable' ), wp_json_encode( $step ) );
		}
		$this->assertSame( $pages, (int) wp_count_posts( 'page' )->draft + (int) wp_count_posts( 'page' )->publish, 'nothing written' );
		$this->assertSame( $front, get_option( 'page_on_front' ) );
		$this->assertCount( 1, Packages::all( get_current_user_id(), 'open' ) );

		$report = Packages::apply( $result['package'] );
		$this->assertSame( array(), $report['errors'], wp_json_encode( $report['errors'] ) );
		$this->assertSame( array(), $report['conflicts'] );
		$this->assertSame( 'applied', $report['state'] );
		$home = get_page_by_path( 'home' );
		$this->assertSame( 'publish', $home->post_status );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( (string) $home->ID, (string) get_option( 'page_on_front' ) );
	}

	/**
	 * A setting that points to a page of the package waits when that page is not applied, and is never written as a slug.
	 */
	public function test_setting_waits_for_its_page(): void {
		$this->sign_in_live();
		$result = wp_get_ability( 'leitvia/apply-recipe' )->execute(
			array(
				'recipe'  => 'leitvia/company',
				'package' => 'company-task',
			)
		);
		$ops    = Packages::operations( $result['package'] );
		$front  = (int) array_values( array_filter( $ops, static fn( array $op ): bool => 'page_on_front' === ( $op['target']['option'] ?? '' ) ) )[0]['id'];
		$before = get_option( 'page_on_front' );
		$report = Packages::apply( $result['package'], array( $front ) );
		$this->assertSame( array(), $report['applied'] );
		$this->assertSame( 'leitvia_page_missing', $report['errors'][0]['code'] );
		$this->assertSame( $before, get_option( 'page_on_front' ) );
		$this->assertSame( 'open', Packages::state( $result['package'] ) );
	}

	/**
	 * Standard profile for an agent: pages become drafts, the imprint (legal) and every setting a package.
	 */
	public function test_apply_follows_autonomy(): void {
		$this->sign_in( Gateway::ROLE );
		$result = $this->apply( 'leitvia/company' );
		$this->assertSame( array( 'draft', 'draft', 'draft', 'draft', 'package' ), self::statuses( $result, 'pages' ) );
		$this->assertSame( array_fill( 0, 5, 'package' ), array_merge( self::statuses( $result, 'reading' ), self::statuses( $result, 'options' ) ) );
		$home = get_page_by_path( 'home' );
		$this->assertSame( 'draft', $home->post_status );
		$this->assertSame( 'page-landing', get_page_template_slug( $home ) );
		$this->assertSame( 'contact', get_post_meta( get_page_by_path( 'contact' )->ID, '_leitvia_page_kind', true ) );
		$this->assertSame( 'posts', get_option( 'show_on_front' ) );
	}

	/**
	 * Publication live: transparency pages with their kinds, the posts page and front page set, news schema stays on.
	 * A second run finds every page by kind or slug and changes nothing.
	 */
	public function test_publication(): void {
		$this->sign_in_live();
		$result = $this->apply( 'leitvia/publication' );
		$this->assertSame( array_fill( 0, 7, 'live' ), self::statuses( $result, 'pages' ) );
		foreach ( array(
			'editorial-standards' => 'editorial',
			'authors'             => 'authors',
			'contact'             => 'contact',
			'imprint'             => 'imprint',
		) as $slug => $kind ) {
			$page = get_page_by_path( $slug );
			$this->assertSame( 'publish', $page->post_status, $slug );
			$this->assertSame( $kind, get_post_meta( $page->ID, '_leitvia_page_kind', true ) );
		}
		$this->assertStringContainsString( 'author page', get_page_by_path( 'authors' )->post_content );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( get_page_by_path( 'home' )->ID, (int) get_option( 'page_on_front' ) );
		$this->assertSame( get_page_by_path( 'news' )->ID, (int) get_option( 'page_for_posts' ) );
		$this->assertSame( 'closed', get_option( 'default_ping_status' ) );
		$this->assertTrue( (bool) leitvia_studio_setting( 'news_schema' ) );
		$this->assertSame( 0, $result['package'] );

		$count = (int) wp_count_posts( 'page' )->publish;
		wp_update_post(
			array(
				'ID'        => get_page_by_path( 'imprint' )->ID,
				'post_name' => 'legal-notice',
			)
		);
		$again = $this->apply( 'leitvia/publication' );
		$this->assertSame( array_fill( 0, 7, 'exists' ), self::statuses( $again, 'pages' ) );
		$this->assertSame( array( 'exists', 'exists', 'exists' ), self::statuses( $again, 'reading' ) );
		$this->assertSame( $count, (int) wp_count_posts( 'page' )->publish );
	}

	/**
	 * Company live, then again: variation, template parts and navigation already hold the recipe state, so the second
	 * run reports them unchanged and writes no package, draft or post.
	 */
	public function test_company_second_run_changes_nothing(): void {
		$this->sign_in_live();
		$result = $this->apply( 'leitvia/company' );
		$this->assertSame( 0, $result['package'] );
		foreach ( array( 'variation', 'templateParts', 'navigation' ) as $step ) {
			$this->assertNotEmpty( self::statuses( $result, $step ), $step );
			$this->assertSame( array( 'live' ), array_unique( self::statuses( $result, $step ) ), $step );
		}

		$before = self::footprint();
		$again  = $this->apply( 'leitvia/company' );
		$this->assert_nothing_new( $again, $before );
		foreach ( array( 'variation', 'templateParts', 'navigation' ) as $step ) {
			$this->assertSame( array( 'unchanged' ), array_unique( self::statuses( $again, $step ) ), $step );
		}
	}

	/**
	 * The confirmation run of a package setup (setup step 5): variation, footer and navigation changed after the
	 * package was applied are kept as exists, and the run proposes nothing.
	 */
	public function test_confirmation_run_keeps_what_changed_after_the_package(): void {
		$this->sign_in( Gateway::ROLE );
		$agent  = get_current_user_id();
		$input  = array(
			'recipe'  => 'leitvia/company',
			'package' => 'setup-task',
		);
		$result = wp_get_ability( 'leitvia/apply-recipe' )->execute( $input );
		$this->assertGreaterThan( 0, $result['package'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$report = Packages::apply( $result['package'] );
		$this->assertSame( 'applied', $report['state'], wp_json_encode( $report ) );

		$styles                                = (int) WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() )['ID'];
		$data                                  = json_decode( (string) get_post_field( 'post_content', $styles ), true );
		$data['styles']['color']['background'] = '#fbf8f3';
		$menu                                  = get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		)[0];
		$footer                                = (int) get_block_template( get_stylesheet() . '//footer', 'wp_template_part' )->wp_id;
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $styles,
					'post_content' => wp_json_encode( $data ),
				)
			)
		);
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $menu->ID,
					'post_content' => '<!-- wp:navigation-link {"label":"Shop","url":"/shop/","kind":"custom"} /-->',
				)
			)
		);
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $footer,
					'post_content' => '<!-- wp:site-title /-->',
				)
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();

		wp_set_current_user( $agent );
		$before = self::footprint();
		$again  = $this->apply( 'leitvia/company' );
		$this->assert_nothing_new( $again, $before );
		foreach ( array( 'variation', 'navigation' ) as $step ) {
			$this->assertSame( array( 'exists' ), self::statuses( $again, $step ), $step );
		}
		$this->assertSame( array( 'unchanged', 'exists' ), self::statuses( $again, 'templateParts' ), 'header as written, footer changed' );
		$this->assertStringContainsString( 'Shop', get_post_field( 'post_content', $menu->ID ) );
	}

	/**
	 * An active token set keeps the variation of the recipe out of every later run, even when the styles still hold
	 * what the recipe wrote.
	 */
	public function test_active_token_set_keeps_the_variation(): void {
		$this->sign_in_live();
		$this->apply( 'leitvia/company' );
		update_option(
			Compiler::ACTIVE_OPTION,
			self::factory()->post->create(
				array(
					'post_type'   => 'leitvia_tokens',
					'post_status' => 'private',
				)
			)
		);
		$again = $this->apply( 'leitvia/company' );
		$this->assertSame( array( 'exists' ), self::statuses( $again, 'variation' ) );
		$this->assertSame( 0, $again['package'] );
	}

	/**
	 * Documentation: page-docs pages below the docs page.
	 */
	public function test_documentation_hierarchy(): void {
		$this->sign_in_live();
		$this->apply( 'leitvia/documentation' );
		$docs = get_page_by_path( 'docs' );
		$this->assertSame( 'page-docs', get_page_template_slug( $docs ) );
		foreach ( array( 'getting-started', 'configuration' ) as $slug ) {
			$page = get_page_by_path( 'docs/' . $slug );
			$this->assertSame( $docs->ID, $page->post_parent, $slug );
			$this->assertSame( 'page-docs', get_page_template_slug( $page ) );
		}
		$this->assertNull( get_page_by_path( 'getting-started' ) );
	}

	/**
	 * The footer pattern of a recipe has sample links to "#": the recipe writes the footer with the pages of
	 * footerNavigation instead, by title and address, and no link to "#" stays.
	 */
	public function test_footer_links_recipe_pages(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->sign_in_live();
		$this->apply( 'leitvia/company' );
		$footer = get_block_template( get_stylesheet() . '//footer', 'wp_template_part' );
		$this->assertNotNull( $footer );
		$this->assertStringNotContainsString( '"url":"#"', $footer->content );
		$links = array();
		foreach ( parse_blocks( $footer->content ) as $block ) {
			array_push( $links, ...self::navigation_links( $block ) );
		}
		$this->assertSame(
			array(
				'Services' => home_url( '/services/' ),
				'About'    => home_url( '/about/' ),
				'Contact'  => home_url( '/contact/' ),
				'Imprint'  => home_url( '/imprint/' ),
			),
			array_column( $links, 'url', 'label' )
		);
		foreach ( array( 'services', 'about', 'contact', 'imprint' ) as $slug ) {
			$this->assertSame( home_url( '/' . $slug . '/' ), get_permalink( get_page_by_path( $slug ) ), 'the link leads to the page' );
		}
	}

	/**
	 * A run with some pages links only pages that are on the site or come in this run: navigation and footer of the
	 * company recipe with home and imprint lead to the imprint and leave out services, about and contact.
	 */
	public function test_navigation_links_only_pages_of_the_run(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->sign_in_live();
		$result = $this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'pages'  => array( 'home', 'imprint' ),
			)
		);
		$this->assertNull( get_page_by_path( 'services' ) );
		$this->assertSame( array( 'waiting' ), self::statuses( $result, 'navigation' ), 'none of services, about and contact is there, so the menu waits' );
		$footer = array();
		foreach ( parse_blocks( get_block_template( get_stylesheet() . '//footer', 'wp_template_part' )->content ) as $block ) {
			array_push( $footer, ...self::navigation_links( $block ) );
		}
		$this->assertSame( array( 'Imprint' => home_url( '/imprint/' ) ), array_column( $footer, 'url', 'label' ) );

		$this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'pages'  => array( 'contact' ),
			)
		);
		$navigation = get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);
		$this->assertNotEmpty( $navigation );
		$links = array();
		foreach ( parse_blocks( $navigation[0]->post_content ) as $block ) {
			array_push( $links, ...self::navigation_links( $block ) );
		}
		$this->assertSame( array( 'Contact' => home_url( '/contact/' ) ), array_column( $links, 'url', 'label' ), 'the menu names the page of the run only' );
	}

	/**
	 * Attributes of the navigation links in a parsed block and its inner blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return list<array<string, mixed>>
	 */
	private static function navigation_links( array $block ): array {
		$links = 'core/navigation-link' === $block['blockName'] ? array( $block['attrs'] ) : array();
		foreach ( $block['innerBlocks'] as $inner ) {
			array_push( $links, ...self::navigation_links( $inner ) );
		}
		return $links;
	}

	/**
	 * The filter leitvia_studio_gateway_areas adds areas and caps levels; admin and invalid levels stay locked.
	 */
	public function test_areas_filter(): void {
		$this->sign_in( Gateway::ROLE, array( 'profile' => 'full' ) );
		$policy = static fn( array $levels ): array => array(
			'content'  => 'suggest',
			'commerce' => 'draft',
			'admin'    => 'autonomous',
			'design'   => 'anything',
		) + $levels;
		add_filter( 'leitvia_studio_gateway_areas', $policy );
		$this->assertSame( 'suggest', Autonomy::area_level( 'content' ) );
		$this->assertSame( 'draft', Autonomy::area_level( 'commerce' ) );
		$this->assertSame( 'autonomous', Autonomy::area_level( 'structure' ) );
		$this->assertSame( 'locked', Autonomy::area_level( 'admin' ) );
		$this->assertSame( 'locked', Autonomy::area_level( 'design' ) );
		$this->assertSame( 'locked', Autonomy::area_level( 'unknown' ) );
		remove_filter( 'leitvia_studio_gateway_areas', $policy );
	}

	/**
	 * The ability compose-page takes only page templates of the contract and parents agents can read.
	 */
	public function test_compose_page_template_and_parent(): void {
		$this->sign_in_live();
		$input = array(
			'title'     => 'Docs',
			'frame'     => 'contact',
			'sections'  => array( array( 'pattern' => 'leitvia/content-faq' ) ),
			'base_hash' => '',
		);
		$this->assertSame( 'leitvia_invalid_input', wp_get_ability( 'leitvia/compose-page' )->execute( $input + array( 'template' => 'single' ) )->get_error_code() );
		$private = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
			)
		);
		$this->assertSame( 'leitvia_invalid_input', wp_get_ability( 'leitvia/compose-page' )->execute( $input + array( 'parent' => $private ) )->get_error_code() );
	}

	/**
	 * The filter leitvia_studio_recipe_unavailable locks a recipe in the list and for apply-recipe; the filter
	 * leitvia_studio_recipe_steps gets the run with its package key and page IDs and adds steps to the report.
	 */
	public function test_recipe_filters(): void {
		$this->sign_in_live();
		$lock = static fn( string $reason, string $name ): string => 'leitvia/company' === $name ? 'Locked for this test.' : $reason;
		add_filter( 'leitvia_studio_recipe_unavailable', $lock, 10, 2 );
		$list = array_column( wp_get_ability( 'leitvia/list-recipes' )->execute(), null, 'name' );
		$this->assertFalse( $list['leitvia/company']['available'] );
		$this->assertSame( 'Locked for this test.', $list['leitvia/company']['reason'] );
		$this->assertTrue( $list['leitvia/blog']['available'] );
		$refused = wp_get_ability( 'leitvia/apply-recipe' )->execute( array( 'recipe' => 'leitvia/company' ) );
		$this->assertSame( 'leitvia_recipe_unavailable', $refused->get_error_code() );
		$this->assertSame( 'Locked for this test.', $refused->get_error_message() );
		remove_filter( 'leitvia_studio_recipe_unavailable', $lock );

		$seen  = array();
		$extra = static function ( array $steps, string $name, array $recipe, string $key, array $pages ) use ( &$seen ): array {
			$seen    = array(
				'name'  => $name,
				'title' => $recipe['title'],
				'key'   => $key,
				'pages' => $pages,
			);
			$steps[] = array(
				'step'    => 'addon',
				'ability' => 'leitvia/apply-recipe',
				'status'  => 'exists',
				'target'  => array(),
				'code'    => '',
				'package' => 0,
			);
			return $steps;
		};
		add_filter( 'leitvia_studio_recipe_steps', $extra, 10, 5 );
		$result = $this->apply( 'leitvia/company' );
		remove_filter( 'leitvia_studio_recipe_steps', $extra );
		$this->assertSame( array( 'exists' ), self::statuses( $result, 'addon' ) );
		$this->assertSame( 'leitvia/company', $seen['name'] );
		$this->assertSame( 'recipe-leitvia-company', $seen['key'] );
		$this->assertSame( get_page_by_path( 'home' )->ID, $seen['pages']['home'] );
	}

	/**
	 * A person's run with settle at "suggest" applies the package before leitvia_studio_recipe_steps, so add-on steps
	 * find the pages live, and again after it; applied lists both packages. A package an add-on applied itself stays a
	 * no-op.
	 */
	public function test_person_run_settles_before_the_add_on_steps(): void {
		$this->sign_in(
			'administrator',
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
					'kinds' => array(),
				),
			)
		);
		$seen  = array();
		$extra = static function ( array $steps, string $name, array $recipe, string $key, array $pages ) use ( &$seen ): array {
			$seen = array(
				'open' => Packages::find( $key ),
				'home' => get_post_status( (int) ( $pages['home'] ?? 0 ) ),
			);
			// What the add-on writes with the key collects in a new package.
			$steps[] = Recipes::call(
				'addon',
				'leitvia/compose-page',
				array(
					'title'    => 'Add-on page',
					'frame'    => 'contact',
					'slug'     => 'add-on-page',
					'sections' => array( array( 'pattern' => 'leitvia/content-faq' ) ),
					'package'  => $key,
				)
			);
			return $steps;
		};
		add_filter( 'leitvia_studio_recipe_steps', $extra, 10, 5 );
		$result = $this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'settle' => true,
			)
		);
		remove_filter( 'leitvia_studio_recipe_steps', $extra );
		$this->assertSame( 0, $seen['open'] );
		$this->assertSame( 'publish', $seen['home'] );
		$this->assertCount( 2, $result['applied'], (string) wp_json_encode( $result ) );
		$this->assertSame( 'publish', get_post_status( get_page_by_path( 'add-on-page' ) ) );
		$this->assertNotContains( 'package', array_column( $result['steps'], 'status' ) );
	}

	/**
	 * The filters leitvia_studio_recipe_document and leitvia_studio_recipe_model localise a recipe: the plan and dry
	 * run of the Recipes screen show the filtered page title and type label, and the run writes them.
	 */
	public function test_localisation_filters(): void {
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ): void {
				$studio->registry( 'recipes' )->register(
					'leitvia/model',
					array(
						'label' => 'Model',
						'path'  => __DIR__ . '/../fixtures/gateway/recipes/model/recipe.json',
					),
					'leitvia-studio'
				);
			},
			20
		);
		self::boot();
		$this->sign_in_live();
		$names    = array();
		$document = static function ( array $recipe, string $name ) use ( &$names ): array {
			$names[]                     = $name;
			$recipe['pages'][0]['title'] = 'Seite';
			return $recipe;
		};
		$model    = static function ( array $declaration, string $name ) use ( &$names ): array {
			$names[]                                       = $name;
			$declaration['postTypes']['lv_mnote']['label'] = 'Notizen';
			return $declaration;
		};
		add_filter( 'leitvia_studio_recipe_document', $document, 10, 2 );
		add_filter( 'leitvia_studio_recipe_model', $model, 10, 2 );

		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/recipes' );
		$request->set_query_params(
			array(
				'recipe' => 'leitvia/model',
				'dryRun' => true,
			)
		);
		$item = rest_do_request( $request )->get_data()[0];
		$this->assertSame( array( 'Seite' ), array_column( $item['plan']['pages'], 'title' ) );
		$this->assertSame( array( 'Notizen' ), $item['plan']['contentModel'] );
		$this->assertSame( array( 'new' ), self::statuses( $item['dryRun'], 'contentModel' ) );
		$this->assertFalse( post_type_exists( 'lv_mnote' ) );

		$this->run_recipe( array( 'recipe' => 'leitvia/model' ) );
		remove_filter( 'leitvia_studio_recipe_document', $document, 10 );
		remove_filter( 'leitvia_studio_recipe_model', $model, 10 );
		// The list of recipes localises every recipe as well; each call names the recipe it filters.
		$this->assertContains( 'leitvia/model', $names );
		$this->assertSame( array(), array_diff( $names, array_column( Recipes::all(), 'name' ) ) );
		$this->assertSame( 'Seite', get_page_by_path( 'model-page' )->post_title );
		$this->assertTrue( post_type_exists( 'lv_mnote' ) );
		$this->assertSame( 'Notizen', get_post_type_object( 'lv_mnote' )->labels->name );
		unregister_post_type( 'lv_mnote' );
		delete_option( LeitviaStudio\Model\Model::OPTION );
		LeitviaStudio\Model\Model::forget();
	}

	/**
	 * The part frontPage without the front page on the site: show_on_front waits with page_on_front instead of switching
	 * the site to an empty static front page, in the dry run and in the run.
	 */
	public function test_front_page_waits_for_its_page(): void {
		$this->sign_in_live();
		$this->assertNull( get_page_by_path( 'home' ) );
		$input = array(
			'recipe' => 'leitvia/company',
			'parts'  => array( 'frontPage' ),
		);
		foreach ( array( $input + array( 'dryRun' => true ), $input ) as $run ) {
			$result = $this->run_recipe( $run );
			$this->assertSame( array( 'waiting', 'waiting' ), self::statuses( $result, 'reading' ), wp_json_encode( $result['steps'] ) );
			$this->assertSame( array( 'page_missing' ), array_values( array_unique( array_column( $result['steps'], 'code' ) ) ) );
			$this->assertSame( 'posts', get_option( 'show_on_front' ) );
		}
	}

	/**
	 * Runs apply-recipe with an input of parts, pages, building blocks or a dry run.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private function run_recipe( array $input ): array {
		$result = wp_get_ability( 'leitvia/apply-recipe' )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		foreach ( $result['steps'] as $step ) {
			$this->assertNotSame( 'rejected', $step['status'], wp_json_encode( $step ) );
		}
		return $result;
	}

	/**
	 * Names of a column of the steps, once each.
	 *
	 * @param array<string, mixed> $result Result.
	 * @param string               $column step or part.
	 * @return list<string>
	 */
	private static function column( array $result, string $column ): array {
		return array_values( array_unique( array_column( $result['steps'], $column ) ) );
	}

	/**
	 * Parts limit a run: only pages, and of them only home and contact, leave style, template parts, navigation and
	 * reading settings alone; the part frontPage then sets the front page to the page that exists.
	 */
	public function test_parts_and_pages(): void {
		$this->sign_in_live();
		$before = self::footprint();
		$front  = get_option( 'show_on_front' );
		$result = $this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'parts'  => array( 'pages' ),
				'pages'  => array( 'home', 'contact' ),
			)
		);
		$this->assertSame( array( 'pages' ), self::column( $result, 'step' ) );
		$this->assertSame( array( 'pages' ), self::column( $result, 'part' ) );
		$this->assertSame( array( 'live', 'live' ), self::statuses( $result, 'pages' ) );
		$this->assertInstanceOf( WP_Post::class, get_page_by_path( 'home' ) );
		$this->assertInstanceOf( WP_Post::class, get_page_by_path( 'contact' ) );
		$this->assertNull( get_page_by_path( 'about' ) );
		$after = self::footprint();
		foreach ( array( 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) as $type ) {
			$this->assertSame( $before[ $type ], $after[ $type ], $type );
		}
		$this->assertSame( $front, get_option( 'show_on_front' ) );

		$reading = $this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'parts'  => array( 'frontPage' ),
			)
		);
		$this->assertSame( array( 'reading' ), self::column( $reading, 'step' ) );
		$this->assertSame( array( 'frontPage' ), self::column( $reading, 'part' ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( get_page_by_path( 'home' )->ID, (int) get_option( 'page_on_front' ) );
		$this->assertNull( get_page_by_path( 'about' ), 'pages stay out' );
	}

	/**
	 * A dry run writes nothing and says what a run would replace on a fresh site; after the run nothing is replaced and
	 * the parts the recipe wrote are unchanged. It runs the filter of dry runs, not the one of runs, with the selection.
	 */
	public function test_dry_run_writes_nothing(): void {
		$this->sign_in_live();
		$runs      = 0;
		$selection = array();
		$count     = static function ( array $steps ) use ( &$runs ): array {
			++$runs;
			return $steps;
		};
		$plan      = static function ( array $steps, string $name, array $recipe, string $key, array $pages, array $sel ) use ( &$selection ): array {
			$selection = $sel;
			return $steps;
		};
		add_filter( 'leitvia_studio_recipe_steps', $count );
		add_filter( 'leitvia_studio_recipe_plan_steps', $plan, 10, 6 );
		$before  = self::footprint();
		$options = static fn(): array => array( get_option( 'show_on_front' ), get_option( 'page_on_front' ), get_option( 'default_comment_status' ), get_option( Recipes::WRITES_OPTION ) );
		$stored  = $options();
		$dry     = $this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'dryRun' => true,
			)
		);
		$this->assertTrue( $dry['dryRun'] );
		$this->assertSame( 0, $dry['package'] );
		$this->assertSame( 0, $runs );
		$this->assertTrue( $selection['dryRun'] );
		$this->assertSame( Recipes::PARTS, $selection['parts'] );
		foreach ( $dry['steps'] as $step ) {
			$this->assertContains( $step['status'], array( 'new', 'replace', 'exists', 'unchanged', 'waiting', 'package' ), wp_json_encode( $step ) );
		}
		$this->assertSame( array( 'new' ), array_values( array_unique( self::statuses( $dry, 'pages' ) ) ) );
		$this->assertEqualsCanonicalizing( array( 'design', 'header', 'footer', 'navigation', 'frontPage', 'options' ), $dry['replaces'] );
		$this->assertSame( $before, self::footprint() );
		$this->assertSame( $stored, $options() );

		$this->apply( 'leitvia/company' );
		$this->assertSame( 1, $runs );
		$again = $this->run_recipe(
			array(
				'recipe' => 'leitvia/company',
				'dryRun' => true,
			)
		);
		remove_filter( 'leitvia_studio_recipe_steps', $count );
		remove_filter( 'leitvia_studio_recipe_plan_steps', $plan, 10 );
		$this->assertSame( array(), $again['replaces'] );
		$this->assertSame( array( 'exists' ), array_values( array_unique( self::statuses( $again, 'pages' ) ) ) );
		foreach ( array( 'variation', 'templateParts', 'navigation' ) as $step ) {
			$this->assertSame( array( 'unchanged' ), array_values( array_unique( self::statuses( $again, $step ) ) ), $step );
		}
	}

	/**
	 * The building blocks a recipe lists are added with the part buildingBlocks, their samples as drafts with the part
	 * samples; design, header, navigation, pages and the front page stay as they are.
	 */
	public function test_building_blocks_of_a_recipe(): void {
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ): void {
				$studio->registry( 'recipes' )->register(
					'leitvia/notes',
					array(
						'label' => 'Notes',
						'path'  => __DIR__ . '/../fixtures/gateway/recipes/notes/recipe.json',
					),
					'leitvia-studio'
				);
				$studio->registry( 'building_blocks' )->register(
					'leitvia/recipe-notes',
					array(
						'title'       => 'Notes',
						'description' => 'Short notes.',
						'model'       => array(
							'postTypes' => array(
								'lv_rnote' => array(
									'label'         => 'Notes',
									'singularLabel' => 'Note',
								),
							),
						),
						'samples'     => static fn(): array => array(
							'postType' => 'lv_rnote',
							'entries'  => array(
								array(
									'slug'    => 'a-note',
									'title'   => 'A note',
									'content' => '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->',
								),
							),
						),
					),
					'leitvia-studio'
				);
			},
			20
		);
		self::boot();
		$this->sign_in_live();
		$before = self::footprint();
		$front  = get_option( 'show_on_front' );
		$plan   = Recipes::selection( array( 'buildingBlocks' => array( 'leitvia/recipe-notes', 'leitvia/unknown' ) ), Recipes::load( __DIR__ . '/../fixtures/gateway/recipes/notes/recipe.json' ) );
		$this->assertSame( array( 'leitvia/recipe-notes' ), $plan['buildingBlocks'], 'only blocks the recipe lists' );
		$this->assertFalse( $plan['contentModel'] );

		$result = $this->run_recipe(
			array(
				'recipe' => 'leitvia/notes',
				'parts'  => array( 'buildingBlocks', 'samples' ),
			)
		);
		$this->assertSame( array( 'contentModel', 'blockEntries' ), self::column( $result, 'step' ) );
		$this->assertSame( array( 'buildingBlocks', 'samples' ), self::column( $result, 'part' ) );
		$this->assertTrue( post_type_exists( 'lv_rnote' ) );
		$note = get_posts(
			array(
				'post_type'   => 'lv_rnote',
				'name'        => 'a-note',
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $note );
		$this->assertSame( 'draft', $note[0]->post_status );
		$this->assertNull( get_page_by_path( 'notes-home' ) );
		$after = self::footprint();
		foreach ( array( 'page', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) as $type ) {
			$this->assertSame( $before[ $type ], $after[ $type ], $type );
		}
		$this->assertSame( $front, get_option( 'show_on_front' ) );
		unregister_post_type( 'lv_rnote' );
	}
}
