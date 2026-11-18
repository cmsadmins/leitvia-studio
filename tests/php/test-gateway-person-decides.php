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
	 * Set-organization waits for a person; get-seo shows the saved details; without a field it is rejected.
	 */
	public function test_organization(): void {
		$result = self::call( 'leitvia/set-organization', array( 'city' => 'Kassel' ) );
		$this->assert_package( $result );
		$this->assertSame( '', leitvia_studio_setting( 'organization_city' ) );
		wp_set_current_user( $this->admin );
		update_option( \LeitviaStudio\Admin\Settings::OPTION, array( 'organization_city' => 'Kassel' ) + \LeitviaStudio\Admin\Settings::DEFAULTS );
		$this->assertSame( 'Kassel', wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( self::factory()->post->create() ) ) )['organization']['city'] );
		$this->assertWPError( self::call( 'leitvia/set-organization', array() ) );
	}

	/**
	 * Set-motion waits for a person and keeps the other settings; once applied blocks without leitviaMotion get the new
	 * strength. A value outside off, subtle and bold is refused.
	 */
	public function test_motion(): void {
		update_option( \LeitviaStudio\Admin\Settings::OPTION, array( 'organization_city' => 'Kassel' ) + \LeitviaStudio\Admin\Settings::DEFAULTS );
		$package = $this->assert_package( self::call( 'leitvia/set-motion', array( 'motion' => 'bold' ) ) );
		$this->assertSame( 'subtle', \LeitviaStudio\Blocks\Animation::site() );
		$this->apply_as_person( $package );
		$this->assertSame( 'bold', \LeitviaStudio\Blocks\Animation::site() );
		$this->assertSame( 'Kassel', leitvia_studio_setting( 'organization_city' ) );
		$this->assertStringContainsString( 'is-lv-motion-bold', do_blocks( '<!-- wp:paragraph {"leitviaAnimation":"fade"} --><p>A</p><!-- /wp:paragraph -->' ) );
		$this->assertWPError( self::call( 'leitvia/set-motion', array( 'motion' => 'loud' ) ) );
		$this->assertWPError( self::call( 'leitvia/set-motion', array() ) );
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

	/**
	 * Asserts that a call with several operations waits in one package and returns its ID.
	 *
	 * @param mixed $result Result.
	 */
	private function assert_batch_package( $result ): int {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertSame( 'package', $result['mode'], (string) wp_json_encode( $result ) );
		$this->assertGreaterThan( 0, $result['package'] );
		return (int) $result['package'];
	}

	/**
	 * Applies a package as the administrator and signs the agent in again.
	 *
	 * @param int $package Package ID.
	 */
	private function apply_as_person( int $package ): void {
		wp_set_current_user( $this->admin );
		$report = Packages::apply( $package );
		$this->assertSame( 'applied', $report['state'], (string) wp_json_encode( $report ) );
		wp_set_current_user( $this->agent );
	}

	/**
	 * Title, tagline, logo and icon wait for a person; once applied core/site-title and core/site-logo show them and
	 * WordPress returns the logo as custom_logo. A small icon gets a notice; a post that is no image, an empty call
	 * and a title without text are refused.
	 */
	public function test_site_identity(): void {
		$image   = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$result  = self::call(
			'leitvia/set-site-identity',
			array(
				'title'   => 'Example Studio',
				'tagline' => 'Furniture made to measure',
				'logo'    => $image,
				'icon'    => $image,
			)
		);
		$package = $this->assert_batch_package( $result );
		$this->assertCount( 4, $result['items'] );
		$this->assertStringContainsString( '512 pixels', (string) $result['notice'] );
		$this->assertNotSame( 'Example Studio', get_option( 'blogname' ) );
		$this->apply_as_person( $package );
		$this->assertSame( 'Example Studio', get_option( 'blogname' ) );
		$this->assertSame( 'Furniture made to measure', get_option( 'blogdescription' ) );
		$this->assertSame( $image, (int) get_option( 'site_icon' ) );
		$this->assertSame( $image, (int) get_theme_mod( 'custom_logo' ) );
		$this->assertStringContainsString( 'Example Studio', do_blocks( '<!-- wp:site-title /-->' ) );
		$this->assertStringContainsString( (string) wp_get_attachment_url( $image ), do_blocks( '<!-- wp:site-logo /-->' ) );

		$rejected = self::call( 'leitvia/set-site-identity', array( 'logo' => self::factory()->post->create() ) );
		$this->assertWPError( $rejected );
		$this->assertSame( 'logo', $rejected->get_error_data()['reason'] );
		$this->assertWPError( self::call( 'leitvia/set-site-identity', array() ) );
		$this->assertWPError( self::call( 'leitvia/set-site-identity', array( 'title' => '<b></b>' ) ) );
	}

	/**
	 * The front page is a setting: a person applies it, 0 shows the latest posts again, the posts page is refused and
	 * a draft gets a notice.
	 */
	public function test_front_page(): void {
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$posts = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_for_posts', $posts );
		$result = self::call( 'leitvia/set-front-page', array( 'page' => $page ) );
		$this->assertSame( '', (string) ( $result['notice'] ?? '' ) );
		$this->apply_as_person( $this->assert_batch_package( $result ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( $page, (int) get_option( 'page_on_front' ) );

		$rejected = self::call( 'leitvia/set-front-page', array( 'page' => $posts ) );
		$this->assertWPError( $rejected );
		$this->assertSame( 'page', $rejected->get_error_data()['reason'] );
		$this->assertWPError( self::call( 'leitvia/set-front-page', array( 'page' => 999999 ) ) );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$this->assertStringContainsString( 'not published', (string) self::call( 'leitvia/set-front-page', array( 'page' => $draft ) )['notice'] );

		$this->apply_as_person( $this->assert_batch_package( self::call( 'leitvia/set-front-page', array( 'page' => 0 ) ) ) );
		$this->assertSame( 'posts', get_option( 'show_on_front' ) );
		$this->assertSame( 0, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Providers are read with their hash and replaced as a whole, sanitized like the settings page: hosts lose scheme
	 * and path, the key stays when the label changes, a stale hash and a provider without a valid host are refused.
	 * Built-in services are listed apart.
	 */
	public function test_consent_providers(): void {
		delete_option( \LeitviaStudio\Admin\Settings::OPTION );
		$read = wp_get_ability( 'leitvia/get-consent-providers' )->execute();
		$this->assertSame( array(), $read['providers'] );
		$this->assertContains( 'youtube', array_column( $read['services'], 'key' ) );
		$planner = array(
			'label'   => 'Kitchen Planner',
			'hosts'   => array( 'https://Planner.Example.com/embed' ),
			'purpose' => 'Plans a kitchen in the browser.',
			'privacy' => 'https://planner.example.com/privacy',
		);
		$result  = self::call(
			'leitvia/set-consent-providers',
			array(
				'providers' => array( $planner ),
				'base_hash' => $read['hash'],
			)
		);
		$this->apply_as_person( $this->assert_package( $result ) );
		$read = wp_get_ability( 'leitvia/get-consent-providers' )->execute();
		$this->assertSame( array( 'planner.example.com' ), $read['providers'][0]['hosts'] );
		$this->assertSame( 'kitchen-planner', $read['providers'][0]['key'] );
		$this->assertNotContains( 'kitchen-planner', array_column( $read['services'], 'key' ) );
		$this->assertArrayHasKey( 'kitchen-planner', \LeitviaStudio\Consent\Consent::services() );

		$renamed = array(
			'key'   => 'kitchen-planner',
			'label' => 'Planner',
		) + $planner;
		$this->apply_as_person(
			$this->assert_package(
				self::call(
					'leitvia/set-consent-providers',
					array(
						'providers' => array( $renamed ),
						'base_hash' => $read['hash'],
					)
				)
			)
		);
		$read = wp_get_ability( 'leitvia/get-consent-providers' )->execute();
		$this->assertSame( array( 'kitchen-planner', 'Planner' ), array( $read['providers'][0]['key'], $read['providers'][0]['label'] ) );

		$stale = self::call(
			'leitvia/set-consent-providers',
			array(
				'providers' => array(),
				'base_hash' => 'outdated',
			)
		);
		$this->assertWPError( $stale );
		$this->assertSame( 'leitvia_stale_hash', $stale->get_error_code() );
		$invalid = self::call(
			'leitvia/set-consent-providers',
			array(
				'providers' => array( $planner, array( 'hosts' => array( 'not a host' ) ) + $planner ),
				'base_hash' => $read['hash'],
			)
		);
		$this->assertWPError( $invalid );
		$this->assertSame( 'providers', $invalid->get_error_data()['reason'] );
		$this->assertStringContainsString( ': 2.', $invalid->get_error_message() );
	}
}
