<?php
/**
 * Token set post type tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Token_Sets;

/**
 * Post type, meta, revisions and capabilities.
 */
class Test_Token_Sets extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		// The test case unregisters all meta keys after each test.
		Token_Sets::register();
	}

	/**
	 * Post type and meta are registered as specified.
	 */
	public function test_post_type_and_meta(): void {
		$type = get_post_type_object( 'leitvia_tokens' );
		$this->assertNotNull( $type );
		$this->assertTrue( $type->show_in_rest );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_ui );
		$this->assertSame( 'leitvia-tokens', $type->rest_base );
		$this->assertTrue( post_type_supports( 'leitvia_tokens', 'title' ) );
		$this->assertTrue( post_type_supports( 'leitvia_tokens', 'revisions' ) );
		$meta = get_registered_meta_keys( 'post', 'leitvia_tokens' );
		$this->assertArrayHasKey( '_leitvia_tokens_dtcg', $meta );
		$this->assertTrue( $meta['_leitvia_tokens_dtcg']['revisions_enabled'] );
	}

	/**
	 * A meta-only change over REST creates a revision; restoring it restores the meta.
	 */
	public function test_meta_change_creates_revision_and_restore(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = Token_Sets::create_from_active_theme( 'Base' );
		$this->assertIsInt( $id );
		$original = get_post_meta( $id, '_leitvia_tokens_dtcg', true );
		$doc      = json_decode( $original, true );
		$this->assertSame( \LeitviaStudio\Tokens\Dtcg::from_settings( wp_get_global_settings(), leitvia_studio_contract() ), $doc );

		$doc['color']['accent']['$value']['hex'] = '#123456';
		$request                                 = new WP_REST_Request( 'POST', '/wp/v2/leitvia-tokens/' . $id );
		$request->set_body_params( array( 'meta' => array( '_leitvia_tokens_dtcg' => wp_json_encode( $doc ) ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$changed = get_post_meta( $id, '_leitvia_tokens_dtcg', true );
		$this->assertStringContainsString( '#123456', $changed );

		$revisions = array_values(
			wp_get_post_revisions(
				$id,
				array(
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			)
		);
		$this->assertCount( 2, $revisions );
		$this->assertSame( $original, get_post_meta( $revisions[0]->ID, '_leitvia_tokens_dtcg', true ) );
		$this->assertSame( $changed, get_post_meta( end( $revisions )->ID, '_leitvia_tokens_dtcg', true ) );

		wp_restore_post_revision( $revisions[0]->ID );
		$this->assertSame( $original, get_post_meta( $id, '_leitvia_tokens_dtcg', true ) );
	}

	/**
	 * Sets are always private and not readable without edit_theme_options.
	 */
	public function test_sets_are_private(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$id = Token_Sets::create_from_active_theme( 'Base' );
		$this->assertSame( 'private', get_post_status( $id ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertCount( 1, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/leitvia-tokens' ) )->get_data() );

		wp_set_current_user( 0 );
		$this->assertSame( array(), rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/leitvia-tokens' ) )->get_data() );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/leitvia-tokens/' . $id ) )->get_status() );
		$request = new WP_REST_Request( 'GET', '/wp/v2/leitvia-tokens' );
		$request->set_param( 'status', 'publish' );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( current_user_can( 'read_post', $id ) );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
	}

	/**
	 * Only users with edit_theme_options manage sets.
	 *
	 * @dataProvider roles
	 *
	 * @param string $role    Role.
	 * @param bool   $allowed Whether the role may create sets.
	 */
	public function test_capabilities( string $role, bool $allowed ): void {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		if ( is_multisite() && 'administrator' === $role ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
		$this->assertSame( $allowed, current_user_can( get_post_type_object( 'leitvia_tokens' )->cap->create_posts ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/leitvia-tokens' );
		$request->set_body_params( array( 'title' => 'X' ) );
		$this->assertSame( $allowed ? 201 : 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * Roles and whether they may create sets.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function roles(): array {
		return array(
			'subscriber'    => array( 'subscriber', false ),
			'editor'        => array( 'editor', false ),
			'administrator' => array( 'administrator', true ),
		);
	}
}
