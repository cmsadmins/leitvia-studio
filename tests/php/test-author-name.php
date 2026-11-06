<?php
/**
 * Public names of authors.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Model\Author_Name;

/**
 * No login name in bylines, author links, REST answers and author pages.
 */
class Test_Author_Name extends WP_UnitTestCase {

	/**
	 * A user whose display name is the login.
	 *
	 * @param array<string, mixed> $args Further fields.
	 */
	private static function login_only( array $args = array() ): int {
		return self::factory()->user->create(
			$args + array(
				'user_login'   => 'chefadmin',
				'display_name' => 'chefadmin',
				'role'         => 'administrator',
			)
		);
	}

	/**
	 * The login stays hidden in every public way to the name; a real display name stays as it is.
	 */
	public function test_login_stays_hidden(): void {
		update_option( 'blogname', 'Example Works' );
		$user = self::login_only();
		$this->assertSame( 'Example Works', get_the_author_meta( 'display_name', $user ) );
		$GLOBALS['authordata'] = get_userdata( $user ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As setup_postdata() does.
		$this->assertSame( 'Example Works', get_the_author() );
		unset( $GLOBALS['authordata'] );
		$this->assertSame( home_url( '/' ), get_author_posts_url( $user ) );

		wp_update_user(
			array(
				'ID'         => $user,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);
		$this->assertSame( 'Ada Lovelace', get_the_author_meta( 'display_name', $user ) );

		$named = self::factory()->user->create(
			array(
				'user_login'   => 'ada',
				'display_name' => 'Ada L.',
			)
		);
		$this->assertSame( 'Ada L.', get_the_author_meta( 'display_name', $named ) );
		$this->assertNotSame( home_url( '/' ), get_author_posts_url( $named ) );

		$agent = self::factory()->user->create(
			array(
				'role'         => Gateway::ROLE,
				'first_name'   => 'Bot',
				'display_name' => 'Leitvia Agent',
			)
		);
		$this->assertSame( 'Example Works', Author_Name::of( $agent ) );
	}

	/**
	 * The author page of such a user answers 404; the REST answer holds no login for visitors.
	 */
	public function test_archive_and_rest(): void {
		$user = self::login_only();
		self::factory()->post->create( array( 'post_author' => $user ) );
		$this->go_to( add_query_arg( 'author', $user, home_url( '/' ) ) );
		Author_Name::close_archive();
		$this->assertTrue( is_404() );

		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/' . $user ) );
		$this->assertStringNotContainsString( 'chefadmin', (string) wp_json_encode( $response->get_data() ) );
	}
}
