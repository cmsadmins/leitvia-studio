<?php
/**
 * What only a person decides: settings, the content model, the global styles and no administration at all.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Dtcg;

/**
 * An agent in the profile full, every content kind unlocked, an area filter that opens everything and even the
 * capability manage_options: option writes, the activation of a token set, style variations and the content model
 * declaration still only wait in a package that the agent cannot apply. No ability or capability reaches plugins,
 * themes or users. A person applies such a package and settles a recipe run as before.
 *
 * @group multisite
 */
class Test_Gateway_Person_Decides extends WP_UnitTestCase {

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Leitvia theme, the abilities handed to core, the agent signed in with everything open.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_option(
			Gateway::OPTION,
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		add_filter( 'leitvia_studio_gateway_areas', array( self::class, 'open_everything' ) );
		wp_set_current_user( $this->agent );
	}

	/**
	 * Removes the abilities, the category and the filter.
	 */
	public function tear_down(): void {
		remove_filter( 'leitvia_studio_gateway_areas', array( self::class, 'open_everything' ) );
		self::unregister();
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		Model::forget();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Filter leitvia_studio_gateway_areas of a policy that opens every area, admin included.
	 *
	 * @param array<string, string> $levels Level by area.
	 * @return array<string, string>
	 */
	public static function open_everything( array $levels ): array {
		return array_fill_keys( array_keys( $levels ), 'autonomous' ) + array( 'admin' => 'autonomous' );
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
	 * Runs an ability.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function call( string $name, array $input ) {
		$ability = wp_get_ability( $name );
		return $ability->execute( isset( $ability->get_input_schema()['properties']['base_hash'] ) ? $input + array( 'base_hash' => '' ) : $input );
	}

	/**
	 * Asserts that a result waits in a package at the level suggest and returns the package ID.
	 *
	 * @param mixed $result Result.
	 */
	private function assert_package( $result ): int {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertSame( array( 'package', 'suggest' ), array( $result['mode'], $result['level'] ) );
		$this->assertGreaterThan( 0, $result['package'] );
		return (int) $result['package'];
	}

	/**
	 * Asserts that the agent cannot apply the package, through the ability or a replay, and returns its operations.
	 *
	 * @param int $package Package ID.
	 * @return list<array<string, mixed>>
	 */
	private function assert_agent_cannot_apply( int $package ): array {
		$this->assertSame( 'leitvia_needs_human', self::call( 'leitvia/apply-package', array( 'id' => $package ) )->get_error_code() );
		$report = Packages::apply( $package );
		$this->assertSame( array(), $report['applied'], 'a replay by the agent writes nothing' );
		$this->assertSame( array( 'leitvia_needs_human' ), array_values( array_unique( array_column( $report['errors'], 'code' ) ) ) );
		return Packages::operations( $package );
	}

	/**
	 * ID of the user global styles of the active theme, 0 for none.
	 */
	private static function styles_id(): int {
		return (int) ( WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() )['ID'] ?? 0 );
	}

	/**
	 * Every option write of an agent becomes a package, even with manage_options; a person applies it.
	 */
	public function test_option_write_is_a_package(): void {
		get_userdata( $this->agent )->add_cap( 'manage_options' );
		wp_set_current_user( $this->agent );
		$this->assertFalse( Gateway::is_person() );
		$name     = get_option( 'blogname' );
		$packages = array();
		foreach ( array( 'blogname' => 'Agent name', 'show_on_front' => 'page', 'default_comment_status' => 'closed' ) as $option => $value ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test.
			$before     = get_option( $option );
			$op         = new Operation( 'leitvia/test-write', 'content', array( 'option' => $option ), Hashes::of( (string) wp_json_encode( $before ) ), static fn(): array => array( 'value' => $value ) );
			$packages[] = $this->assert_package( Pipeline::run( $op ) );
			$this->assertSame( $before, get_option( $option ), $option );
		}
		$this->assertSame( 'suggest', Autonomy::level( 'corrections', array(), array( 'option' => 'blogname' ) ) );
		$this->assert_agent_cannot_apply( $packages[0] );
		$this->assertSame( $name, get_option( 'blogname' ) );

		wp_set_current_user( $this->admin );
		$this->assertSame( 'autonomous', Autonomy::level( 'corrections', array(), array( 'option' => 'blogname' ) ), 'a person is not bound' );
		$this->assertSame( 'applied', Packages::apply( $packages[0] )['state'] );
		$this->assertSame( 'Agent name', get_option( 'blogname' ) );

		// The applied setting stays with the person: the agent cannot roll it back either.
		wp_set_current_user( $this->agent );
		$this->assertSame( 'leitvia_needs_human', self::call( 'leitvia/rollback', array( 'id' => $packages[0] ) )->get_error_code() );
		$this->assertSame( 'Agent name', get_option( 'blogname' ) );
	}

	/**
	 * An agent creates a token set, but its activation and a style variation wait for a person.
	 */
	public function test_token_set_activation_and_variation_are_packages(): void {
		$doc                                     = Dtcg::from_settings( wp_get_global_settings(), leitvia_studio_contract() );
		$doc['color']['accent']['$value']['hex'] = '#0b4f9c';
		$created                                 = self::call(
			'leitvia/create-token-set',
			array(
				'title'    => 'Blue',
				'document' => $doc,
			)
		);
		$this->assertSame( 'live', $created['mode'], 'a token set draft stays open to agents' );
		$set     = (int) $created['target']['post'];
		$active  = get_option( Compiler::ACTIVE_OPTION );
		$package = $this->assert_package( self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertSame( 0, self::styles_id() );
		$this->assertSame( $active, get_option( Compiler::ACTIVE_OPTION ) );
		$this->assert_package( self::call( 'leitvia/set-variation', array( 'variation' => 'corporate-light' ) ) );
		$this->assertSame( 0, self::styles_id() );
		$this->assert_agent_cannot_apply( $package );
		$this->assertSame( 0, self::styles_id() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 'applied', Packages::apply( $package )['state'] );
		$this->assertSame( $set, (int) get_option( Compiler::ACTIVE_OPTION ) );
		$this->assertGreaterThan( 0, self::styles_id() );
	}

	/**
	 * The content model declaration registers post types: an agent only proposes it.
	 */
	public function test_content_model_declaration_is_a_package(): void {
		$package = $this->assert_package(
			self::call(
				'leitvia/define-content-type',
				array(
					'declaration' => array(
						'version'   => 1,
						'postTypes' => array(
							'lv_book' => array(
								'label'         => 'Books',
								'singularLabel' => 'Book',
							),
						),
					),
					'base_hash'   => Hashes::of( (string) wp_json_encode( get_option( Model::OPTION ) ) ),
				)
			)
		);
		$this->assertSame( array(), Model::stored() );
		$this->assert_agent_cannot_apply( $package );
		$this->assertSame( array(), Model::stored() );
		$this->assertFalse( post_type_exists( 'lv_book' ) );
	}

	/**
	 * No Leitvia ability manages plugins, themes, users, roles, options or files; an agent holds none of their
	 * capabilities, and core routes refuse its writes to them.
	 */
	public function test_no_administration_at_all(): void {
		$names = array_filter( array_map( static fn( $ability ): string => $ability->get_name(), wp_get_abilities() ), static fn( string $name ): bool => str_starts_with( $name, 'leitvia/' ) );
		$this->assertNotEmpty( $names );
		$this->assertSame( array(), array_values( preg_grep( '/plugin|theme|user|role|capabilit|option|setting|file|code|login|password/', $names ) ) );

		wp_set_current_user( $this->agent );
		foreach ( array( 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'switch_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_themes', 'update_core', 'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'manage_options', 'unfiltered_html', 'unfiltered_upload', 'edit_files', 'import', 'export' ) as $cap ) {
			$this->assertFalse( user_can( $this->agent, $cap ), $cap );
		}

		foreach ( array( '/wp/v2/plugins', '/wp/v2/users', '/wp/v2/settings', '/wp/v2/users/me/application-passwords', '/wp/v2/themes' ) as $route ) {
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', $route ) )->get_status(), $route );
		}
	}

	/**
	 * A person who asks to settle a recipe run gets it applied at once, settings and variation included.
	 */
	public function test_person_settled_run_still_applies(): void {
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
					'kinds' => array(),
				),
			) + Gateway::DEFAULTS
		);
		remove_filter( 'leitvia_studio_gateway_areas', array( self::class, 'open_everything' ) );
		wp_set_current_user( $this->admin );
		$result = self::call(
			'leitvia/apply-recipe',
			array(
				'recipe' => 'leitvia/company',
				'settle' => true,
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotContains( 'package', array_column( $result['steps'], 'status' ), (string) wp_json_encode( $result['steps'] ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertGreaterThan( 0, self::styles_id() );
	}

	/**
	 * The posts page is a setting like the others: an agent proposes an existing page, a person applies it, and the
	 * front page or a page that does not exist are refused.
	 */
	public function test_posts_page(): void {
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$front = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', 0 );
		$result = self::call( 'leitvia/set-posts-page', array( 'page' => $page ) );
		$this->assert_package( $result );
		$this->assertSame( 0, (int) get_option( 'page_for_posts' ) );
		wp_set_current_user( $this->admin );
		$report = Packages::apply( (int) $result['package'] );
		$this->assertSame( 'applied', $report['state'], wp_json_encode( $report ) );
		$this->assertSame( $page, (int) get_option( 'page_for_posts' ) );
		wp_set_current_user( $this->agent );
		foreach ( array( $front, 999999 ) as $wrong ) {
			$rejected = self::call( 'leitvia/set-posts-page', array( 'page' => $wrong ) );
			$this->assertWPError( $rejected );
			$this->assertSame( 'page', $rejected->get_error_data()['reason'] );
		}
		update_option( 'show_on_front', 'posts' );
		$this->assertStringContainsString( 'static front page', (string) self::call( 'leitvia/set-posts-page', array( 'page' => $page ) )['notice'] );
	}
}
