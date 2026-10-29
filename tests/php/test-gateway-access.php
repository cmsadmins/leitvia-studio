<?php
/**
 * Gateway access: agent users, application passwords, revoking, settings, and who may apply packages.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Store;

/**
 * Routes /leitvia-studio/v1/gateway/access and /gateway/settings for people with manage_options, the REST nonce, the
 * confirmation of the profile "full", and the rule that the autonomy level binds agents only when applying packages.
 *
 * @group multisite
 */
class Test_Gateway_Access extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Abilities handed to core, routes registered, the administrator signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Removes the abilities and the category, and the cookie state of the nonce test.
	 */
	public function tear_down(): void {
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = false;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
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
	 * Dispatches a REST request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below /leitvia-studio/v1.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private static function rest( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/leitvia-studio/v1' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Audit entries of an action.
	 *
	 * @param string $action Ability column, for example access/revoke.
	 * @return list<object>
	 */
	private static function audit( string $action ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ability = %s', Store::table( 'audit' ), $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
	}

	/**
	 * Creating an agent makes a user with the role leitvia_agent and a core application password; the plain password
	 * is in that one response only, the list never shows it.
	 */
	public function test_create_agent_shows_the_password_once(): void {
		$created = self::rest( 'POST', '/gateway/access', array( 'name' => 'Claude Code' ) );
		$this->assertSame( 200, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$data = $created->get_data();
		$user = get_userdata( $data['agent']['id'] );
		$this->assertSame( array( Gateway::ROLE ), array_values( $user->roles ) );
		$this->assertSame( 'Claude Code', $user->display_name );
		$this->assertSame( 'agent-claude-code', $user->user_login );
		$this->assertSame( 'no-store', $created->get_headers()['Cache-Control'] );

		$passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );
		$this->assertCount( 1, $passwords );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$this->assertInstanceOf( WP_User::class, wp_authenticate_application_password( null, $user->user_login, $data['password'] ) );

		$list = self::rest( 'GET', '/gateway/access' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertStringNotContainsString( $data['password'], (string) wp_json_encode( $list->get_data() ) );
		$this->assertSame( array( 'Claude Code' ), array_column( $list->get_data()['agents'], 'name' ) );
		$this->assertSame( 1, $list->get_data()['agents'][0]['passwords'] );
		$this->assertArrayHasKey( 'compatible', $list->get_data()['mcp'] );

		$this->assertSame( 'agent-claude-code-2', get_userdata( self::rest( 'POST', '/gateway/access', array( 'name' => 'Claude Code' ) )->get_data()['agent']['id'] )->user_login );
		$this->assertNotEmpty( self::audit( 'access/create' ) );
		$this->assertStringNotContainsString( str_replace( ' ', '', $data['password'] ), (string) wp_json_encode( self::audit( 'access/password' ) ) );
	}

	/**
	 * An agent never signs in with the account password and never opens the admin, admin-ajax.php included; the
	 * application password still authenticates over the whole core chain of an API request. People sign in as before.
	 * Getting the role ends every session of the user.
	 */
	public function test_agent_signs_in_with_application_password_only(): void {
		$data  = self::rest( 'POST', '/gateway/access', array( 'name' => 'Login test' ) )->get_data();
		$agent = get_userdata( $data['agent']['id'] );
		wp_set_password( 'account-password-1', $agent->ID );
		$editor = self::factory()->user->create(
			array(
				'role'      => 'editor',
				'user_pass' => 'account-password-2',
			)
		);

		$denied = wp_authenticate( $agent->user_login, 'account-password-1' );
		$this->assertWPError( $denied );
		$this->assertSame( 'leitvia_agent_login', $denied->get_error_code() );
		$this->assertWPError( wp_authenticate( $agent->user_email, 'account-password-1' ), 'also with the email address' );
		$this->assertSame( $editor, wp_authenticate( get_userdata( $editor )->user_login, 'account-password-2' )->ID );

		add_filter( 'application_password_is_api_request', '__return_true' );
		$api = wp_authenticate( $agent->user_login, $data['password'] );
		$this->assertInstanceOf( WP_User::class, $api, $api instanceof WP_Error ? $api->get_error_message() : '' );
		$this->assertSame( $agent->ID, $api->ID );

		$sessions = WP_Session_Tokens::get_instance( $editor );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$this->assertCount( 1, $sessions->get_all() );
		get_userdata( $editor )->add_role( Gateway::ROLE );
		$this->assertSame( array(), $sessions->get_all(), 'adding the role ends the sessions' );
		get_userdata( $editor )->remove_role( Gateway::ROLE );
		$sessions->create( time() + HOUR_IN_SECONDS );
		get_userdata( $editor )->set_role( Gateway::ROLE );
		$this->assertSame( array(), $sessions->get_all(), 'setting the role ends the sessions' );
		get_userdata( $editor )->set_role( 'editor' );

		wp_set_current_user( $editor );
		Gateway::admin_guard();
		// No exception for admin-ajax.php: the guard asks for the role only.
		wp_set_current_user( $agent->ID );
		$this->expectException( WPDieException::class );
		Gateway::admin_guard();
	}

	/**
	 * Revoking deletes every application password of the agent and writes an audit entry; users who are no agent are
	 * never touched.
	 */
	public function test_revoke_deletes_all_passwords_and_logs(): void {
		$agent = self::rest( 'POST', '/gateway/access', array( 'name' => 'Bot' ) )->get_data()['agent']['id'];
		$this->assertSame( 200, self::rest( 'POST', "/gateway/access/{$agent}/password" )->get_status() );
		$this->assertCount( 2, WP_Application_Passwords::get_user_application_passwords( $agent ) );

		$revoked = self::rest( 'DELETE', "/gateway/access/{$agent}" );
		$this->assertSame( 200, $revoked->get_status() );
		$this->assertSame( 2, $revoked->get_data()['revoked'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $agent ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $agent ), 'the user stays' );
		$entries = self::audit( 'access/revoke' );
		$this->assertCount( 1, $entries );
		$this->assertSame( $agent, (int) $entries[0]->object_id );
		$this->assertSame( $this->admin, (int) $entries[0]->user_id );

		WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'Own' ) );
		$this->assertSame( 404, self::rest( 'DELETE', "/gateway/access/{$this->admin}" )->get_status() );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $this->admin ) );
	}

	/**
	 * Only people with manage_options: editors and agents (even with manage_options) get 403 on every route.
	 */
	public function test_only_people_with_manage_options(): void {
		$agent  = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		$routes = array( array( 'GET', '/gateway/access' ), array( 'POST', '/gateway/access' ), array( 'DELETE', "/gateway/access/{$agent}" ), array( 'POST', "/gateway/access/{$agent}/password" ), array( 'GET', '/gateway/settings' ), array( 'POST', '/gateway/settings' ) );
		$strong = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		get_userdata( $strong )->add_cap( 'manage_options' );
		foreach ( array( self::factory()->user->create( array( 'role' => 'editor' ) ), $agent, $strong ) as $user ) {
			wp_set_current_user( $user );
			foreach ( $routes as [ $method, $route ] ) {
				$this->assertSame( 403, self::rest( $method, $route, array( 'name' => 'X' ) )->get_status(), "$method $route as $user" );
			}
		}
		$this->assertFalse( Gateway::is_person() );
	}

	/**
	 * A cookie request without the REST nonce counts as signed out and gets 401; with the nonce it passes.
	 */
	public function test_nonce_is_required(): void {
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true;
		rest_cookie_check_errors( null );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, self::rest( 'POST', '/gateway/access', array( 'name' => 'Sneaky' ) )->get_status() );
		$this->assertFalse( username_exists( 'agent-sneaky' ) );

		wp_set_current_user( $this->admin );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 200, self::rest( 'GET', '/gateway/access' )->get_status() );
	}

	/**
	 * Settings validate against the schema of the gateway, "full" needs confirm: true, admin stays locked.
	 */
	public function test_settings(): void {
		$this->assertSame( Autonomy::DEFAULTS, self::rest( 'GET', '/gateway/settings' )->get_data()['defaults'] );

		foreach ( array( array( 'overrides' => array( 'areas' => array( 'content' => 'wild' ) ) ), array( 'overrides' => array( 'kinds' => array( 'everything' ) ) ), array( 'rate_per_minute' => 0 ), array( 'profile' => 'chaos' ) ) as $invalid ) {
			$this->assertSame( 400, self::rest( 'POST', '/gateway/settings', $invalid )->get_status(), wp_json_encode( $invalid ) );
		}
		$this->assertSame( Gateway::DEFAULTS, Gateway::settings() );

		$denied = self::rest( 'POST', '/gateway/settings', array( 'profile' => 'full' ) );
		$this->assertSame( 400, $denied->get_status() );
		$this->assertSame( 'leitvia_confirm_required', $denied->get_data()['code'] );
		$this->assertSame( 'standard', Gateway::settings()['profile'] );

		$saved = self::rest(
			'POST',
			'/gateway/settings',
			array(
				'profile'   => 'full',
				'confirm'   => true,
				'verify'    => false,
				'overrides' => array(
					'areas' => array(
						'admin'  => 'autonomous',
						'design' => 'suggest',
					),
					'kinds' => array( 'prices' ),
				),
			)
		);
		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$settings = Gateway::settings();
		$this->assertSame( 'full', $settings['profile'] );
		$this->assertFalse( $settings['verify'] );
		$this->assertSame( array( 'design' => 'suggest' ), $settings['overrides']['areas'] );
		$this->assertSame( 'locked', Autonomy::area_level( 'admin' ) );
		$this->assertSame( 'autonomous', Autonomy::area_level( 'content' ) );
		$this->assertNotEmpty( self::audit( 'access/settings' ) );

		// Staying at "full" needs no new confirmation.
		$this->assertSame( 200, self::rest( 'POST', '/gateway/settings', array( 'rate_per_minute' => 30 ) )->get_status() );
		$this->assertSame( 30, Gateway::settings()['rate_per_minute'] );
		$this->assertSame( 'full', Gateway::settings()['profile'] );
	}

	/**
	 * The option is not in /wp/v2/settings, so the confirmation of "full" cannot be skipped there.
	 */
	public function test_option_not_in_core_settings_route(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$this->assertArrayNotHasKey( Gateway::OPTION, $response->get_data() );
	}

	/**
	 * Applying a package: a person with manage_options decides whatever the level (ability and so WP-CLI as well),
	 * an agent only for autonomous operations and never through the REST routes of the inbox.
	 */
	public function test_person_applies_any_level_agent_only_autonomous(): void {
		switch_theme( 'leitvia' );
		$agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array( 'content' => 'suggest' ),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_current_user( $agent );
		$new    = '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->';
		$result = Pipeline::run( new Operation( 'leitvia/test-write', 'content', array( 'post' => $post ), Hashes::document( $post ), static fn( array $state ): array => array( 'post_content' => $new ) + $state ) );
		$this->assertSame( 'package', $result['mode'] );
		$package = (int) $result['package'];

		$this->assertSame( 'leitvia_needs_human', wp_get_ability( 'leitvia/apply-package' )->execute( array( 'id' => $package ) )->get_error_code() );
		$this->assertSame( 403, self::rest( 'POST', "/packages/{$package}/apply" )->get_status() );
		$this->assertSame( 403, self::rest( 'GET', '/packages' )->get_status() );

		wp_set_current_user( $this->admin );
		$report = wp_get_ability( 'leitvia/apply-package' )->execute( array( 'id' => $package ) );
		$this->assertIsArray( $report, is_wp_error( $report ) ? wp_json_encode( $report->get_error_data() ) : '' );
		$this->assertSame( 'applied', $report['state'] );
		$this->assertStringContainsString( 'New', get_post( $post )->post_content );
		$this->assertSame( 'applied', Packages::state( $package ) );

		$summary = Packages::summary( $package );
		$this->assertSame( get_userdata( $agent )->display_name, $summary['agent'] );
		// The stored title, without the "Private:" prefix get_the_title() adds to the private package post.
		$this->assertSame( get_post_field( 'post_title', $package, 'raw' ), $summary['title'] );
		$this->assertStringNotContainsString( 'Private', $summary['title'] );
		$this->assertSame(
			array(
				'findings' => 0,
				'score'    => null,
			),
			$summary['checks']
		);
		$this->assertNull( $summary['verify'] );
	}
}
