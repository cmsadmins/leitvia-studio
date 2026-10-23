<?php
/**
 * Token overlay tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Overlays;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Time windows, front-end only runtime application, REST validation, deactivation and the change action.
 *
 * @group multisite
 */
class Test_Overlays extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme as an administrator on the front end.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		// The test case unregisters all meta keys after each test.
		Token_Sets::register();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
		set_current_screen( 'front' );
		self::front_request();
	}

	/**
	 * Drops cached contract, preview parameters and theme.json data.
	 */
	public function tear_down(): void {
		unset( $_GET['leitvia_studio_preview'], $_GET['set'], $_GET['_wpnonce'], $GLOBALS['current_screen'] );
		remove_all_actions( 'leitvia_studio_overlay_changed' );
		\LeitviaStudio\Plugin::forget_contract();
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * A running overlay is laid over the front end at runtime; the saved global styles stay as they are.
	 */
	public function test_running_overlay_applies_at_runtime(): void {
		$styles = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$before = get_post( $styles['ID'] )->post_content;
		$id     = self::set_with_accent( '#123456' );
		self::schedule( $id, -HOUR_IN_SECONDS, HOUR_IN_SECONDS );

		$this->assertSame( $id, Overlays::current() );
		$this->assertTrue( Overlays::applies() );
		$this->assertStringContainsString( '--wp--preset--color--accent: #123456', self::stylesheet() );
		$this->assertSame( $before, get_post( $styles['ID'] )->post_content );
	}

	/**
	 * A running overlay wins over the presets of a style variation in the user global styles.
	 */
	public function test_overlay_over_a_style_variation(): void {
		Test_Compiler::apply_variation( 'corporate-light' );
		$id = self::set_with_accent( '#123456' );
		self::schedule( $id, -HOUR_IN_SECONDS, HOUR_IN_SECONDS );

		$css = self::stylesheet();
		$this->assertStringContainsString( '--wp--preset--color--accent: #123456', $css );
		$this->assertStringNotContainsString( '#2152c7', $css );
	}

	/**
	 * Without anybody emptying caches the next front-end request starts and ends the overlay; theme.json data computed
	 * before the request was parsed never holds it. The flag only reports an external object cache, the test cache
	 * stays non-persistent: this proves the order of events, not the behaviour of a persistent cache.
	 */
	public function test_start_and_end_without_emptying_caches(): void {
		$external = wp_using_ext_object_cache( true );
		try {
			$id = self::set_with_accent( '#123456' );
			$this->assertStringNotContainsString( '#123456', wp_get_global_stylesheet() );
			self::schedule( $id, -HOUR_IN_SECONDS, HOUR_IN_SECONDS );
			// Computed early in a request, before parse_request: no overlay yet, and that result is cached.
			unset( $GLOBALS['wp_actions']['parse_request'] );
			$this->assertFalse( Overlays::applies() );
			$this->assertStringNotContainsString( '#123456', wp_get_global_stylesheet() );
			self::front_request();
			$this->assertStringContainsString( '--wp--preset--color--accent: #123456', wp_get_global_stylesheet() );
			self::schedule( $id, -2 * HOUR_IN_SECONDS, -HOUR_IN_SECONDS );
			self::front_request();
			$this->assertStringNotContainsString( '#123456', wp_get_global_stylesheet() );
		} finally {
			wp_using_ext_object_cache( $external );
		}
	}

	/**
	 * The windows come from a transient: without overlays a request runs no query, a changed overlay is seen at once.
	 */
	public function test_windows_are_cached(): void {
		global $wpdb;
		Overlays::current();
		$queries = $wpdb->num_queries;
		Overlays::current();
		self::front_request();
		$this->assertSame( $queries, $wpdb->num_queries );
		$id = self::set_with_accent( '#123456' );
		self::schedule( $id, -HOUR_IN_SECONDS, HOUR_IN_SECONDS );
		$this->assertSame( $id, Overlays::current() );
		wp_trash_post( $id );
		$this->assertSame( 0, Overlays::current() );
	}

	/**
	 * In the admin and in Lab previews of a set the overlay does not apply.
	 */
	public function test_not_in_admin_or_set_preview(): void {
		self::schedule( self::set_with_accent( '#123456' ), -HOUR_IN_SECONDS, HOUR_IN_SECONDS );

		set_current_screen( 'dashboard' );
		$this->assertFalse( Overlays::applies() );
		$this->assertStringNotContainsString( '#123456', self::stylesheet() );

		set_current_screen( 'front' );
		$_GET['leitvia_studio_preview'] = '1';
		$_GET['set']                    = '5';
		$_GET['_wpnonce']               = 'wrong';
		// Only a preview that Lab\Preview would render counts.
		$this->assertTrue( Overlays::applies() );
		$_GET['_wpnonce'] = wp_create_nonce( 'leitvia_studio_preview' );
		$this->assertFalse( Overlays::applies() );
		unset( $_GET['set'] );
		$this->assertTrue( Overlays::applies() );
	}

	/**
	 * In a REST request the overlay does not apply, so the Site Editor never saves overlay values as its base.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_not_in_rest_requests(): void {
		self::schedule( self::set_with_accent( '#123456' ), -HOUR_IN_SECONDS, HOUR_IN_SECONDS );
		define( 'REST_REQUEST', true );
		self::front_request();
		$this->assertFalse( Overlays::applies() );
		$this->assertStringNotContainsString( '#123456', self::stylesheet() );
	}

	/**
	 * Before the start and after the end there is no overlay.
	 */
	public function test_outside_the_window(): void {
		$id = self::set_with_accent( '#123456' );
		self::schedule( $id, HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		$this->assertSame( 0, Overlays::current() );
		self::schedule( $id, -2 * HOUR_IN_SECONDS, -HOUR_IN_SECONDS );
		$this->assertSame( 0, Overlays::current() );
		$this->assertStringNotContainsString( '#123456', self::stylesheet() );
	}

	/**
	 * Overlapping windows are refused; one that starts when the other ends is not an overlap.
	 */
	public function test_overlap_is_refused(): void {
		$first  = self::set_with_accent( '#123456' );
		$second = self::set_with_accent( '#234567' );
		$this->assertSame( 200, self::rest_schedule( $first, 0, 2 * HOUR_IN_SECONDS )->get_status() );

		$response = self::rest_schedule( $second, HOUR_IN_SECONDS, 3 * HOUR_IN_SECONDS );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'leitvia_studio_overlay_overlap', $response->get_data()['code'] );
		$this->assertSame( '', get_post_meta( $second, Overlays::META, true ) );

		$this->assertSame( 200, self::rest_schedule( $second, 2 * HOUR_IN_SECONDS, 3 * HOUR_IN_SECONDS )->get_status() );
		// Stored in UTC.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', get_post_meta( $second, Overlays::META, true )['start'] );
	}

	/**
	 * An end before the start, a malformed date and a set that fails the checks are refused; null removes the overlay.
	 */
	public function test_invalid_overlays_are_refused(): void {
		$id       = self::set_with_accent( '#123456' );
		$response = self::rest_schedule( $id, HOUR_IN_SECONDS, 0 );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'leitvia_studio_overlay_range', $response->get_data()['code'] );

		$request = new WP_REST_Request( 'POST', "/wp/v2/leitvia-tokens/$id" );
		$request->set_body_params(
			array(
				'meta' => array(
					Overlays::META => array(
						'start' => 'tomorrow',
						'end'   => 'later',
					),
				),
			)
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		// Fails the contrast pair accent on surface.
		$invalid  = self::set_with_accent( '#9ab8ae' );
		$response = self::rest_schedule( $invalid, 0, HOUR_IN_SECONDS );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'leitvia_studio_token_set_invalid', $response->get_data()['code'] );
		// Written past REST, it still does not apply.
		self::schedule( $invalid, -HOUR_IN_SECONDS, HOUR_IN_SECONDS );
		$this->assertSame( 0, Overlays::current() );
		delete_post_meta( $invalid, Overlays::META );

		$this->assertSame( 200, self::rest_schedule( $id, 0, HOUR_IN_SECONDS )->get_status() );
		$request = new WP_REST_Request( 'POST', "/wp/v2/leitvia-tokens/$id" );
		$request->set_body_params( array( 'meta' => array( Overlays::META => null ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( '', get_post_meta( $id, Overlays::META, true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, self::rest_schedule( $id, 0, HOUR_IN_SECONDS )->get_status() );
	}

	/**
	 * Without the filter, as after deactivating Studio, the base values return.
	 */
	public function test_deactivation_ends_the_overlay(): void {
		self::schedule( self::set_with_accent( '#123456' ), -HOUR_IN_SECONDS, HOUR_IN_SECONDS );
		$this->assertStringContainsString( '#123456', self::stylesheet() );
		remove_filter( 'wp_theme_json_data_user', array( Overlays::class, 'apply' ), 20 );
		$this->assertStringNotContainsString( '#123456', self::stylesheet() );
		add_filter( 'wp_theme_json_data_user', array( Overlays::class, 'apply' ), 20 );
	}

	/**
	 * Starting and ending fire leitvia_studio_overlay_changed once each.
	 */
	public function test_changes_fire_once(): void {
		$changes = array();
		add_action(
			'leitvia_studio_overlay_changed',
			static function ( int $id ) use ( &$changes ): void {
				$changes[] = $id;
			}
		);
		$id = self::set_with_accent( '#123456' );
		Overlays::current();
		self::schedule( $id, -HOUR_IN_SECONDS, HOUR_IN_SECONDS );
		Overlays::current();
		Overlays::current();
		$this->assertSame( $id, (int) get_transient( Overlays::STATE ) );
		self::schedule( $id, -2 * HOUR_IN_SECONDS, -HOUR_IN_SECONDS );
		Overlays::current();
		Overlays::current();
		$this->assertSame( array( $id, 0 ), $changes );
	}

	/**
	 * Within an hour of the next start or end of a valid overlay, shared caches may keep a page only until then.
	 */
	public function test_cache_headers(): void {
		$this->assertSame( array(), Overlays::headers( array() ) );
		$id = self::set_with_accent( '#123456' );
		self::schedule( $id, 2 * HOUR_IN_SECONDS, 3 * HOUR_IN_SECONDS );
		$this->assertSame( array(), Overlays::headers( array() ) );
		self::schedule( $id, 600, 3 * HOUR_IN_SECONDS );
		$this->assertMatchesRegularExpression( '/^s-maxage=(599|600)$/', Overlays::headers( array() )['Cache-Control'] );
		// A set that fails the checks never switches, so it sets no header.
		delete_post_meta( $id, Overlays::META );
		self::schedule( self::set_with_accent( '#9ab8ae' ), 600, 3 * HOUR_IN_SECONDS );
		$this->assertSame( array(), Overlays::headers( array() ) );
		$this->assertSame( array( 'Cache-Control' => 'no-cache' ), Overlays::headers( array( 'Cache-Control' => 'no-cache' ) ) );
		set_current_screen( 'dashboard' );
		$this->assertSame( array(), Overlays::headers( array() ) );
	}

	/**
	 * Simulates the parsing of a front-end request: Overlays::refresh() runs on parse_request.
	 */
	private static function front_request(): void {
		do_action_ref_array( 'parse_request', array( &$GLOBALS['wp'] ) );
	}

	/**
	 * Variables of the global stylesheet, built anew.
	 */
	private static function stylesheet(): string {
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		return wp_get_global_stylesheet( array( 'variables' ) );
	}

	/**
	 * Writes an overlay window relative to now, past REST.
	 *
	 * @param int $id    Token set ID.
	 * @param int $start Seconds from now.
	 * @param int $end   Seconds from now.
	 */
	private static function schedule( int $id, int $start, int $end ): void {
		update_post_meta(
			$id,
			Overlays::META,
			array(
				'start' => gmdate( 'c', time() + $start ),
				'end'   => gmdate( 'c', time() + $end ),
			)
		);
	}

	/**
	 * Saves an overlay window relative to now through REST.
	 *
	 * @param int $id    Token set ID.
	 * @param int $start Seconds from now.
	 * @param int $end   Seconds from now.
	 */
	private static function rest_schedule( int $id, int $start, int $end ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/wp/v2/leitvia-tokens/$id" );
		$request->set_body_params(
			array(
				'meta' => array(
					Overlays::META => array(
						'start' => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + $start ),
						'end'   => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + $end ),
					),
				),
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * Creates a set from the theme with a different accent color.
	 *
	 * @param string $hex Accent color.
	 */
	private static function set_with_accent( string $hex ): int {
		$id                               = Token_Sets::create_from_active_theme( 'Test' );
		$doc                              = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$doc['color']['accent']['$value'] = array(
			'colorSpace' => 'srgb',
			'components' => array( 0, 0, 0 ),
			'hex'        => $hex,
		);
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		return $id;
	}
}
