<?php
/**
 * Leitvia Studio in a network, as a site administrator who is no network administrator.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Consent\Consent;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Store;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Rest\Gateway_Access_Controller;

/**
 * Nothing crosses the border of a site: agent access, media, consent, links, a deleted site, and the content model after
 * a network deactivation. Runs in a subdirectory network and, with LEITVIA_TESTS_SUBDOMAIN, in a subdomain network.
 *
 * @group ms-required
 * @group multisite
 */
class Test_Multisite extends WP_UnitTestCase {

	/**
	 * Administrator of the main site, no network administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Second site of the network, with Studio installed.
	 *
	 * @var int
	 */
	private int $other;

	/**
	 * Site administrator signed in, a second site installed, the routes of Studio registered.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other = self::site( 'other' );
		switch_to_blog( $this->other );
		Gateway::install();
		restore_current_blog();
		wp_set_current_user( $this->admin );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Removes the uploaded files.
	 */
	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * A new site, below a path or, in a subdomain network, on its own host.
	 *
	 * @param string $name Name of the site.
	 */
	private static function site( string $name ): int {
		return is_subdomain_install()
			? self::factory()->blog->create(
				array(
					'domain' => $name . '.' . WP_TESTS_DOMAIN,
					'path'   => '/',
				)
			)
			: self::factory()->blog->create( array( 'path' => '/' . $name . '/' ) );
	}

	/**
	 * Dispatches a REST request to Studio.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below /leitvia-studio/v1.
	 * @param array  $body   JSON body.
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
	 * An agent of this site, with a membership on the other site when $both.
	 *
	 * @param bool $both Also a member of the other site.
	 */
	private function agent( bool $both ): int {
		$agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		if ( $both ) {
			add_user_to_blog( $this->other, $agent, Gateway::ROLE );
		}
		return $agent;
	}

	/**
	 * Whether a table of the current site exists. Tests create temporary tables, which SHOW TABLES does not list.
	 *
	 * @param string $table Table name.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$exists   = false !== $wpdb->query( "SELECT 1 FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test.
		$wpdb->suppress_errors( $suppress );
		return $exists;
	}

	/**
	 * D1: a site administrator without create_users adds no user to the network; with the network setting "Add New
	 * Users" they may.
	 */
	public function test_site_admin_needs_create_users_for_a_new_agent(): void {
		$this->assertFalse( current_user_can( 'create_users' ) );
		$response = self::rest( 'POST', '/gateway/access', array( 'name' => 'Intruder' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'leitvia_cannot_create_users', $response->get_data()['code'] );
		$this->assertFalse( username_exists( 'agent-intruder' ) );

		update_site_option( 'add_new_users', 1 );
		$this->assertSame( 200, self::rest( 'POST', '/gateway/access', array( 'name' => 'Helper' ) )->get_status() );
	}

	/**
	 * D1: an agent that also works on another site is out of reach of a site administrator; one of this site only is
	 * not, and its new password works here but not on the other site.
	 */
	public function test_site_admin_manages_agents_of_this_site_only(): void {
		$shared = $this->agent( true );
		foreach ( array( array( 'POST', "/gateway/access/{$shared}/password" ), array( 'DELETE', "/gateway/access/{$shared}" ) ) as [ $method, $route ] ) {
			$response = self::rest( $method, $route );
			$this->assertSame( 403, $response->get_status(), "{$method} {$route}" );
			$this->assertSame( 'leitvia_agent_other_sites', $response->get_data()['code'] );
		}
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $shared ) );

		$own      = $this->agent( false );
		$response = self::rest( 'POST', "/gateway/access/{$own}/password" );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$login    = get_userdata( $own )->user_login;
		$password = $response->get_data()['password'];
		$this->assertInstanceOf( WP_User::class, wp_authenticate_application_password( null, $login, $password ) );

		// The agent joins the other site later: the password still belongs to this one.
		add_user_to_blog( $this->other, $own, Gateway::ROLE );
		switch_to_blog( $this->other );
		$refused = wp_authenticate_application_password( null, $login, $password );
		restore_current_blog();
		$this->assertWPError( $refused );
		$this->assertSame( 'leitvia_other_site', $refused->get_error_code() );
	}

	/**
	 * D1: a network administrator revokes on one site only the passwords of that site; the list counts only those that
	 * work here.
	 */
	public function test_network_admin_revokes_the_passwords_of_this_site_only(): void {
		grant_super_admin( $this->admin );
		$agent = $this->agent( true );
		$this->assertSame( 200, self::rest( 'POST', "/gateway/access/{$agent}/password" )->get_status() );
		switch_to_blog( $this->other );
		$this->assertSame( 200, self::rest( 'POST', "/gateway/access/{$agent}/password" )->get_status() );
		restore_current_blog();
		$this->assertCount( 2, WP_Application_Passwords::get_user_application_passwords( $agent ) );
		$listed = wp_list_pluck( self::rest( 'GET', '/gateway/access' )->get_data()['agents'], 'passwords', 'id' );
		$this->assertSame( 1, $listed[ $agent ] );

		$revoked = self::rest( 'DELETE', "/gateway/access/{$agent}" );
		$this->assertSame( 1, $revoked->get_data()['revoked'] );
		$left = array_values( WP_Application_Passwords::get_user_application_passwords( $agent ) );
		$this->assertCount( 1, $left );
		$this->assertSame( array( $left[0]['uuid'] => $this->other ), get_user_meta( $agent, Gateway_Access_Controller::SITES_META, true ) );
		revoke_super_admin( $this->admin );
	}

	/**
	 * D2: add-media reads no image from the uploads of another site and none from the private folder.
	 */
	public function test_add_media_reads_only_the_own_uploads(): void {
		$fixture = dirname( __DIR__ ) . '/fixtures/avatar.png';
		switch_to_blog( $this->other );
		$theirs = wp_upload_dir();
		restore_current_blog();
		$ours = wp_upload_dir();
		wp_mkdir_p( $theirs['path'] );
		wp_mkdir_p( $ours['basedir'] . '/' . Packages::PRIVATE_DIR . '/x' );
		copy( $fixture, $theirs['path'] . '/secret.png' );
		copy( $fixture, $ours['basedir'] . '/' . Packages::PRIVATE_DIR . '/x/secret.png' );
		copy( $fixture, $ours['path'] . '/mine.png' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$add = static fn( string $url ) => wp_get_ability( 'leitvia/add-media' )->execute(
			array(
				'url'        => $url,
				'decorative' => true,
				'base_hash'  => '',
			)
		);
		// On the host of this site: in a subdomain network the other site has a host of its own.
		$other = $ours['baseurl'] . '/sites/' . $this->other . substr( $theirs['url'], strlen( $theirs['baseurl'] ) ) . '/secret.png';
		foreach ( array( $other, $ours['baseurl'] . '/' . Packages::PRIVATE_DIR . '/x/secret.png' ) as $url ) {
			$result = $add( $url );
			$this->assertWPError( $result, $url );
			$this->assertSame( 'leitvia_invalid_input', $result->get_error_code(), $url );
		}
		$this->assertIsArray( $add( $ours['url'] . '/mine.png' ) );

		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		wp_unregister_ability_category( 'leitvia' );
		leitvia_studio()->reset_for_tests();
		wp_delete_file( $theirs['path'] . '/secret.png' );
		wp_delete_file( $ours['basedir'] . '/' . Packages::PRIVATE_DIR . '/x/secret.png' );
		wp_delete_file( $ours['path'] . '/mine.png' );
	}

	/**
	 * D3: the consent module stores under the path of the site, which the server hands over.
	 */
	public function test_consent_carries_the_path_of_the_site(): void {
		Consent::placeholder( 'youtube', '<iframe src="https://www.youtube.com/embed/x"></iframe>', 'https://www.youtube.com/watch?v=x' );
		$this->assertSame( '/', wp_interactivity_state( 'leitvia/consent' )['site'] );

		switch_to_blog( $this->other );
		Consent::placeholder( 'youtube', '<iframe src="https://www.youtube.com/embed/x"></iframe>', 'https://www.youtube.com/watch?v=x' );
		$this->assertSame( is_subdomain_install() ? '/' : '/other/', wp_interactivity_state( 'leitvia/consent' )['site'] );
		restore_current_blog();
	}

	/**
	 * D4: deleting a site removes its Studio tables, the profile pictures of its members and the agent passwords bound
	 * to it.
	 */
	public function test_deleting_a_site_removes_its_data(): void {
		global $wpdb;
		grant_super_admin( $this->admin );
		$site  = self::site( 'gone' );
		$agent = $this->agent( false );
		add_user_to_blog( $site, $agent, Gateway::ROLE );
		switch_to_blog( $site );
		Gateway::install();
		$tables = array( Store::table( 'audit' ), Store::table( 'snapshots' ) );
		update_user_option( $this->admin, 'leitvia_avatar', 12 );
		$this->assertSame( 200, self::rest( 'POST', "/gateway/access/{$agent}/password" )->get_status() );
		restore_current_blog();
		$this->assertSame( 200, self::rest( 'POST', "/gateway/access/{$agent}/password" )->get_status() );
		revoke_super_admin( $this->admin );
		foreach ( $tables as $table ) {
			$this->assertTrue( self::table_exists( $table ), $table );
		}

		wp_delete_site( $site );
		foreach ( $tables as $table ) {
			$this->assertFalse( self::table_exists( $table ), $table );
		}
		$this->assertSame( '', get_user_meta( $this->admin, $wpdb->get_blog_prefix( $site ) . 'leitvia_avatar', true ) );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $agent ), 'the password of the main site stays' );
		$this->assertSame( array( get_current_blog_id() ), array_values( get_user_meta( $agent, Gateway_Access_Controller::SITES_META, true ) ) );
	}

	/**
	 * K1: a root-relative link of a recipe or an agent points into the site, also below a path.
	 */
	public function test_root_relative_links_stay_in_the_site(): void {
		$this->assertSame( '/services/', Block_Edit::url( '/services/' ) );
		switch_to_blog( $this->other );
		$path = is_subdomain_install() ? '' : '/other';
		$this->assertSame( $path . '/services/', Block_Edit::url( '/services/' ) );
		$this->assertSame( $path . '/', Block_Edit::url( '/' ) );
		$this->assertSame( $path . '/contact/', Block_Edit::url( $path . '/contact/' ) );
		$this->assertSame( 'https://example.com/x/', Block_Edit::url( 'https://example.com/x/' ) );
		$this->assertSame( '#top', Block_Edit::url( '#top' ) );
		restore_current_blog();
	}

	/**
	 * K2: after a network deactivation emptied the rewrite rules of every site, a network activation makes every site
	 * flush again with its content model types.
	 */
	public function test_network_activation_flushes_the_rules_of_every_site(): void {
		$plugin = plugin_basename( LEITVIA_STUDIO_FILE );
		do_action( 'deactivate_' . $plugin, true );
		switch_to_blog( $this->other );
		delete_option( Model::FLUSH_OPTION );
		restore_current_blog();

		do_action( 'activate_' . $plugin, true );
		foreach ( array( get_current_blog_id(), $this->other ) as $site ) {
			switch_to_blog( $site );
			$this->assertSame( '1', (string) get_option( Model::FLUSH_OPTION ), "site {$site}" );
			restore_current_blog();
		}
	}
}
