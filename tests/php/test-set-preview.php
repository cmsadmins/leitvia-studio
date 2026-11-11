<?php
/**
 * Preview of a token set on the site.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Overlays;
use LeitviaStudio\Tokens\Set_Preview;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Signed key, the set for one request only, headers, the ability preview-token-set and the italic check.
 *
 * @group multisite
 */
class Test_Set_Preview extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Leitvia theme, an administrator signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		Token_Sets::register();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
	}

	/**
	 * Clears the request and the caches.
	 */
	public function tear_down(): void {
		unset( $_GET[ Set_Preview::ARG ], $_GET[ Set_Preview::KEY ] );
		Set_Preview::forget();
		leitvia_studio()->reset_for_tests();
		\LeitviaStudio\Plugin::forget_contract();
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		Compiler::forget_weak();
		parent::tear_down();
	}

	/**
	 * A saved set from the active theme with another accent.
	 *
	 * @param string $hex Accent.
	 */
	private static function set( string $hex ): int {
		$id                                      = Token_Sets::create_from_active_theme( 'Variant' );
		$doc                                     = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$doc['color']['accent']['$value']['hex'] = $hex;
		$doc['fontFamily']['heading']['$value']  = array( 'Libre Franklin', 'Arial', 'sans-serif' );
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		return $id;
	}

	/**
	 * Simulates a front-end request with the query arguments of a preview address and runs prime().
	 *
	 * @param string $url Preview address.
	 */
	private static function request( string $url ): void {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$_GET[ Set_Preview::ARG ] = $args[ Set_Preview::ARG ] ?? '';
		$_GET[ Set_Preview::KEY ] = $args[ Set_Preview::KEY ] ?? '';
		Set_Preview::forget();
		// The request is parsed: Set_Preview::prime() and Overlays::refresh() run, did_action() counts it.
		do_action_ref_array( 'parse_request', array( &$GLOBALS['wp'] ) );
	}

	/**
	 * Effective accent of this request.
	 *
	 * @param bool $fresh Whether to empty the theme.json caches first.
	 */
	private static function accent( bool $fresh = true ): string {
		if ( $fresh ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
			wp_clean_theme_json_cache();
		}
		$palette = array_column( wp_get_global_settings( array( 'color', 'palette', 'theme' ) ), 'color', 'slug' );
		return strtolower( (string) ( $palette['accent'] ?? '' ) );
	}

	/**
	 * The address carries set and key; the request shows the set, anonymous too, with no-cache headers and noindex,
	 * and nothing is saved.
	 */
	public function test_preview_shows_set_for_this_request_only(): void {
		$before = self::accent();
		$id     = self::set( '#7a1f5c' );
		$link   = Set_Preview::url( $id, '/about/' );
		$this->assertIsArray( $link );
		$this->assertStringStartsWith( home_url( '/about/' ), $link['url'] );
		$this->assertStringContainsString( Set_Preview::ARG . '=' . $id, $link['url'] );
		$this->assertEqualsWithDelta( time() + Set_Preview::TTL, $link['expires'], 5 );

		wp_set_current_user( 0 );
		self::request( $link['url'] );
		$this->assertTrue( Set_Preview::active() );
		$this->assertSame( '#7a1f5c', self::accent() );
		$families = array_column( wp_get_global_settings( array( 'typography', 'fontFamilies', 'theme' ) ), null, 'slug' );
		$this->assertStringContainsString( 'Libre Franklin', $families['heading']['fontFamily'] );
		$this->assertContains( 'italic', array_column( $families['heading']['fontFace'], 'fontStyle' ) );
		$this->assertFalse( Overlays::applies() );
		$this->assertStringContainsString( 'no-referrer', Set_Preview::headers( array() )['Referrer-Policy'] );

		$headers = Set_Preview::headers( array() );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'noindex', $headers['X-Robots-Tag'] );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( Set_Preview::robots( array() )['noindex'] );

		// Nothing was written: without the arguments the site keeps its accent and the set stays inactive.
		self::request( home_url( '/' ) );
		$this->assertFalse( Set_Preview::active() );
		$this->assertSame( $before, self::accent() );
		$this->assertSame( array(), Set_Preview::headers( array() ) );
		$this->assertNotSame( $id, (int) get_option( Compiler::ACTIVE_OPTION ) );
	}

	/**
	 * Global styles resolved before the request was parsed get the set too (prime() empties the caches), a running
	 * overlay stays off and comes back without the preview.
	 */
	public function test_preview_wins_over_cached_styles_and_overlays(): void {
		$id   = self::set( '#7a1f5c' );
		$link = Set_Preview::url( $id );
		// An overlay with another accent runs now.
		$overlay = self::set( '#0b5d3b' );
		update_post_meta(
			$overlay,
			Overlays::META,
			array(
				'start' => gmdate( 'c', time() - 60 ),
				'end'   => gmdate( 'c', time() + HOUR_IN_SECONDS ),
			)
		);
		self::accent();
		self::request( $link['url'] );
		$this->assertSame( '#7a1f5c', self::accent( false ), 'without emptying the caches by hand' );
		$this->assertFalse( Overlays::applies() );

		// Without the preview the overlay is back.
		self::request( home_url( '/' ) );
		$this->assertTrue( Overlays::applies() );
		$this->assertSame( '#0b5d3b', self::accent() );
	}

	/**
	 * A key of one site of a network shows nothing on another.
	 *
	 * @group ms-required
	 */
	public function test_key_is_bound_to_the_site(): void {
		$id   = self::set( '#7a1f5c' );
		$link = Set_Preview::url( $id );
		wp_parse_str( (string) wp_parse_url( $link['url'], PHP_URL_QUERY ), $args );
		$this->assertIsArray( Set_Preview::check( $id, $args[ Set_Preview::KEY ] ) );
		switch_to_blog( self::factory()->blog->create() );
		$this->assertNull( Set_Preview::check( $id, $args[ Set_Preview::KEY ] ) );
		restore_current_blog();
	}

	/**
	 * Keys that are tampered with, expired, issued too far ahead, for another set, of a user without
	 * edit_theme_options, or for a set that is no saved, valid set show nothing.
	 */
	public function test_invalid_keys_show_nothing(): void {
		$id    = self::set( '#7a1f5c' );
		$other = self::set( '#123456' );
		$key   = static function ( string $url ): string {
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
			return (string) $args[ Set_Preview::KEY ];
		};
		$valid = $key( Set_Preview::url( $id )['url'] );
		$this->assertIsArray( Set_Preview::check( $id, $valid ) );
		$this->assertNull( Set_Preview::check( $other, $valid ) );
		$this->assertNull( Set_Preview::check( $id, substr( $valid, 0, -1 ) . ( str_ends_with( $valid, 'a' ) ? 'b' : 'a' ) ) );
		$this->assertNull( Set_Preview::check( $id, '' ) );
		$this->assertNull( Set_Preview::check( $id, 'x-1-' . str_repeat( 'a', 64 ) ) );

		list( $expires, $user, $signature ) = explode( '-', $valid );
		$this->assertNull( Set_Preview::check( $id, ( (int) $expires + 1 ) . "-$user-$signature" ), 'expiry is signed' );
		$this->assertNull( Set_Preview::check( $id, $expires . '-' . ( (int) $user + 1 ) . "-$signature" ), 'user is signed' );

		$late = Set_Preview::url( $id, '', -10 );
		$this->assertGreaterThan( time(), $late['expires'], 'at least a minute' );
		$this->assertEqualsWithDelta( time() + Set_Preview::MAX_TTL, Set_Preview::url( $id, '', 10 * DAY_IN_SECONDS )['expires'], 5 );

		// A user who loses edit_theme_options takes the keys with them.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertNull( Set_Preview::url( $id ) );
		wp_set_current_user( 0 );
		$this->assertNull( Set_Preview::url( $id ) );
		wp_set_current_user( $this->admin );
		$admin = $key( Set_Preview::url( $id )['url'] );
		$this->assertIsArray( Set_Preview::check( $id, $admin ) );
		if ( ! is_multisite() ) {
			get_userdata( $this->admin )->set_role( 'editor' );
			$this->assertNull( Set_Preview::check( $id, $admin ) );
			get_userdata( $this->admin )->set_role( 'administrator' );
		}

		// An invalid document or a set in the trash shows nothing.
		$doc = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		unset( $doc['color']['ink'] );
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$this->assertNull( Set_Preview::check( $id, $valid ) );
		wp_trash_post( $other );
		$this->assertNull( Set_Preview::check( $other, $key( Set_Preview::url( $other )['url'] ) ) );

		// A post of another type with a valid key shows nothing.
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertNull( Set_Preview::check( $page, $key( Set_Preview::url( $page )['url'] ) ) );
	}

	/**
	 * In the admin and without the key the request shows the site as it is.
	 */
	public function test_admin_and_missing_key_show_nothing(): void {
		$id                       = self::set( '#7a1f5c' );
		$_GET[ Set_Preview::ARG ] = (string) $id;
		Set_Preview::forget();
		$this->assertFalse( Set_Preview::active() );
		set_current_screen( 'dashboard' );
		self::request( Set_Preview::url( $id )['url'] );
		$this->assertFalse( Set_Preview::active() );
		set_current_screen( 'front' );
	}

	/**
	 * The ability returns a signed address for an agent; a missing set, a foreign host and a subscriber are refused.
	 */
	public function test_ability(): void {
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$id = self::set( '#7a1f5c' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$ability = wp_get_ability( 'leitvia/preview-token-set' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
		$result = $ability->execute(
			array(
				'set'  => $id,
				'path' => '/about/',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema(), 'output' ) );
		$this->assertStringStartsWith( home_url( '/about/?' ), $result['url'] );
		wp_set_current_user( 0 );
		self::request( $result['url'] );
		$this->assertTrue( Set_Preview::active() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertWPError( $ability->execute( array( 'set' => $id + 1000 ) ) );
		$this->assertWPError(
			$ability->execute(
				array(
					'set'  => $id,
					'path' => '//example.com/',
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $ability->execute( array( 'set' => $id ) ) );
		// An editor may use the gateway but not edit theme options: the permission callback says no before it runs.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $ability->check_permissions( array( 'set' => $id ) ) );
		self::unregister();
	}

	/**
	 * Unregisters the abilities of Leitvia and the category.
	 */
	private static function unregister(): void {
		foreach ( wp_get_abilities() as $registered ) {
			if ( 'leitvia' === $registered->get_category() ) {
				wp_unregister_ability( $registered->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
	}

	/**
	 * A family of the body or heading role without an italic face warns with font_no_italic; one with an italic face
	 * and the mono role do not.
	 */
	public function test_font_without_italic_warns(): void {
		$id      = self::set( '#7a1f5c' );
		$doc     = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$italics = static fn( array $doc ): array => array_values( wp_list_filter( Compiler::check( $doc ), array( 'code' => 'font_no_italic' ) ) );
		$this->assertSame( array(), $italics( $doc ), 'Libre Franklin, the theme fonts and mono' );

		$doc['fontFamily']['heading']['$value'] = array( 'Young Serif', 'Georgia', 'serif' );
		$found                                  = $italics( $doc );
		$this->assertCount( 1, $found );
		$this->assertSame( 'warning', $found[0]['severity'] );
		$this->assertSame(
			array(
				'slug'   => 'heading',
				'family' => 'Young Serif',
			),
			$found[0]['data']
		);
		$this->assertStringContainsString( 'Young Serif', $found[0]['message'] );
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$this->assertTrue( Token_Sets::validate( (string) get_post_meta( $id, Token_Sets::META, true ) ), 'a warning, no error' );
	}
}
