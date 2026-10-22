<?php
/**
 * Local profile pictures.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Avatars\Avatars;

/**
 * Every avatar comes from the media library or the local placeholder; Gravatar only after the site owner opts in.
 */
class Test_Avatars extends WP_UnitTestCase {

	/**
	 * Author with an uploaded image.
	 *
	 * @var int
	 */
	private int $user;

	/**
	 * Image uploaded by the author.
	 *
	 * @var int
	 */
	private int $image;

	/**
	 * Creates an author with an uploaded image.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->user  = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'author@example.org',
			)
		);
		$this->image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		wp_update_post(
			array(
				'ID'          => $this->image,
				'post_author' => $this->user,
			)
		);
		update_option( 'show_avatars', 1 );
	}

	/**
	 * Clears the form and the uploaded files.
	 */
	public function tear_down(): void {
		$_POST = array();
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Local picture is served from the media library.
	 */
	public function test_local_picture_is_served_from_the_media_library(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$url = get_avatar_url( $this->user, array( 'size' => 96 ) );
		$this->assertStringStartsWith( wp_get_upload_dir()['baseurl'], $url );
		$this->assertTrue( get_avatar_data( $this->user )['found_avatar'] );
	}

	/**
	 * No local picture: the placeholder for every kind of caller.
	 *
	 * @dataProvider ids_without_local_picture
	 *
	 * @param callable $id Returns what get_avatar() is called with.
	 */
	public function test_no_gravatar_for_anyone_by_default( callable $id ): void {
		$html = (string) get_avatar( $id( $this ), 96 );
		$this->assertDoesNotMatchRegularExpression( '/gravatar\.com/i', $html );
		$this->assertStringContainsString( 'assets/avatar-placeholder.svg', $html );
	}

	/**
	 * Callers without a local picture.
	 *
	 * @return array<string, array<int, callable>>
	 */
	public static function ids_without_local_picture(): array {
		return array(
			'user id'       => array( fn ( $t ) => $t->user ),
			'email'         => array( fn () => 'someone@example.org' ),
			'guest comment' => array(
				fn () => get_comment(
					self::factory()->comment->create(
						array(
							'comment_author_email' => 'guest@example.org',
							'user_id'              => 0,
						)
					)
				),
			),
			'post'          => array( fn ( $t ) => get_post( self::factory()->post->create( array( 'post_author' => $t->user ) ) ) ),
		);
	}

	/**
	 * Guest comment with member email does not borrow the member picture.
	 */
	public function test_guest_comment_with_member_email_does_not_borrow_the_member_picture(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$comment = get_comment(
			self::factory()->comment->create(
				array(
					'comment_author_email' => 'author@example.org',
					'user_id'              => 0,
				)
			)
		);
		$this->assertStringContainsString( 'avatar-placeholder.svg', get_avatar_url( $comment ) );
	}

	/**
	 * Member comment and email get the member picture.
	 */
	public function test_member_comment_and_email_get_the_member_picture(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$comment = get_comment( self::factory()->comment->create( array( 'user_id' => $this->user ) ) );
		$this->assertStringStartsWith( wp_get_upload_dir()['baseurl'], get_avatar_url( $comment ) );
		$this->assertStringStartsWith( wp_get_upload_dir()['baseurl'], get_avatar_url( 'author@example.org' ) );
	}

	/**
	 * Gravatar only when switched on.
	 */
	public function test_gravatar_only_when_switched_on(): void {
		update_option( 'leitvia_studio_settings', array( 'allow_gravatar' => true ) );
		$this->assertStringContainsString( 'gravatar.com', get_avatar_url( 'someone@example.org' ) );
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$this->assertStringNotContainsString( 'gravatar.com', get_avatar_url( $this->user ) ); // Local wins.
	}

	/**
	 * Missing or non image attachment falls back to placeholder.
	 */
	public function test_missing_or_non_image_attachment_falls_back_to_placeholder(): void {
		update_user_option( $this->user, 'leitvia_avatar', 999999 );
		$this->assertStringContainsString( 'avatar-placeholder.svg', get_avatar_url( $this->user ) );
		update_user_option( $this->user, 'leitvia_avatar', self::factory()->post->create() );
		$this->assertStringContainsString( 'avatar-placeholder.svg', get_avatar_url( $this->user ) );
	}

	/**
	 * The previews under Settings, Discussion force the default image: always the placeholder, also with Gravatar on.
	 */
	public function test_force_default_gives_the_placeholder_for_every_default(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		foreach ( array( false, true ) as $allow ) {
			update_option( 'leitvia_studio_settings', array( 'allow_gravatar' => $allow ) );
			foreach ( array( 'mystery', 'blank', 'gravatar_default', 'identicon', 'wavatar', 'monsterid', 'retro', 'robohash' ) as $default ) {
				update_option( 'avatar_default', $default );
				$html = (string) get_avatar(
					$this->user,
					32,
					'',
					'',
					array( 'force_default' => true )
				);
				$this->assertStringContainsString( 'avatar-placeholder.svg', $html, $default );
				$this->assertStringNotContainsString( 'gravatar.com', $html, $default );
			}
		}
	}

	/**
	 * Rest avatar urls are local.
	 */
	public function test_rest_avatar_urls_are_local(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'      => self::factory()->post->create(),
				'comment_author_email' => 'guest@example.org',
				'comment_approved'     => 1,
				'user_id'              => 0,
			)
		);
		$routes  = array(
			"/wp/v2/users/{$this->user}" => 'avatar_urls',
			"/wp/v2/comments/{$comment}" => 'author_avatar_urls',
		);
		foreach ( $routes as $route => $field ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 200, $response->get_status(), $route );
			$urls = $response->get_data()[ $field ];
			$this->assertNotEmpty( $urls, $route );
			foreach ( $urls as $url ) {
				$this->assertStringNotContainsString( 'gravatar.com', $url, $route );
				$this->assertStringContainsString( 'avatar-placeholder.svg', $url, $route );
			}
		}
	}

	/**
	 * Author cannot take a foreign image.
	 */
	public function test_author_cannot_take_a_foreign_image(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$foreign = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		wp_update_post(
			array(
				'ID'          => $foreign,
				'post_author' => $admin,
			)
		);
		wp_set_current_user( $this->user );
		$this->submit( array( 'leitvia_avatar' => $foreign ) );
		Avatars::save( $this->user );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $this->user ) );
		$errors = new WP_Error();
		Avatars::validate( $errors, true, (object) array( 'ID' => $this->user ) );
		$this->assertContains( 'leitvia_avatar_invalid', $errors->get_error_codes() );

		// The own upload is accepted, without an error.
		$this->submit( array( 'leitvia_avatar' => $this->image ) );
		Avatars::save( $this->user );
		$this->assertSame( $this->image, (int) get_user_option( 'leitvia_avatar', $this->user ) );
		$errors = new WP_Error();
		Avatars::validate( $errors, true, (object) array( 'ID' => $this->user ) );
		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Refuses non images and requests without nonce.
	 */
	public function test_refuses_non_images_and_requests_without_nonce(): void {
		wp_set_current_user( $this->user );
		$post = self::factory()->post->create( array( 'post_author' => $this->user ) );
		$this->submit( array( 'leitvia_avatar' => $post ) );
		Avatars::save( $this->user );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $this->user ) );

		$_POST = array( 'leitvia_avatar' => $this->image );
		Avatars::save( $this->user );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $this->user ) );
	}

	/**
	 * Author cannot edit another user.
	 */
	public function test_author_cannot_edit_another_user(): void {
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->user );
		$this->submit(
			array(
				'leitvia_avatar' => $this->image,
				'user_id'        => $other,
			)
		);
		Avatars::save( $other );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $other ) );
	}

	/**
	 * Administrator sets and user keeps and removes the picture.
	 */
	public function test_administrator_sets_and_user_keeps_and_removes_the_picture(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		wp_update_post(
			array(
				'ID'          => $image,
				'post_author' => $admin,
			)
		);
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$this->submit( array( 'leitvia_avatar' => $image ) );
		Avatars::save( $this->user );
		$this->assertSame( $image, (int) get_user_option( 'leitvia_avatar', $this->user ) );

		// The author saves the profile with the administrator's image unchanged in the form: no error, it stays.
		wp_set_current_user( $this->user );
		$this->submit( array( 'leitvia_avatar' => $image ) );
		$errors = new WP_Error();
		Avatars::validate( $errors, true, (object) array( 'ID' => $this->user ) );
		$this->assertFalse( $errors->has_errors() );
		Avatars::save( $this->user );
		$this->assertSame( $image, (int) get_user_option( 'leitvia_avatar', $this->user ) );

		$this->submit(
			array(
				'leitvia_avatar'        => $image,
				'leitvia_avatar_remove' => '1',
			)
		);
		Avatars::save( $this->user );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $this->user ) );
		$this->assertSame( 'attachment', get_post_type( $image ) );
	}

	/**
	 * Profile field markup.
	 */
	public function test_profile_field_markup(): void {
		wp_set_current_user( $this->user );
		$html = Avatars::profile_field( 'Gravatar link', get_userdata( $this->user ) );
		$this->assertStringNotContainsString( 'Gravatar', $html );
		$this->assertStringContainsString( 'id="leitvia-avatar-choose"', $html );
		$this->assertStringNotContainsString( 'leitvia_avatar_remove', $html );
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$this->assertStringContainsString( 'name="leitvia_avatar_remove" value="1" aria-describedby="leitvia-avatar-help"', Avatars::profile_field( '', get_userdata( $this->user ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'Ask an administrator to set your profile picture.', Avatars::profile_field( '', wp_get_current_user() ) );
	}

	/**
	 * Privacy exporter and eraser.
	 */
	public function test_privacy_exporter_and_eraser(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$exporter = apply_filters( 'wp_privacy_personal_data_exporters', array() )['leitvia-studio-avatar'];
		$export   = call_user_func( $exporter['callback'], 'author@example.org', 1 );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( wp_get_attachment_url( $this->image ), $export['data'][0]['data'][0]['value'] );
		$this->assertSame( array(), call_user_func( $exporter['callback'], 'nobody@example.org', 1 )['data'] );

		$eraser = apply_filters( 'wp_privacy_personal_data_erasers', array() )['leitvia-studio-avatar'];
		$erased = call_user_func( $eraser['callback'], 'author@example.org', 1 );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['done'] );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $this->user ) );
		$this->assertSame( 'attachment', get_post_type( $this->image ) );
		$this->assertFalse( call_user_func( $eraser['callback'], 'author@example.org', 1 )['items_removed'] );
	}

	/**
	 * Author card pattern.
	 */
	public function test_author_card_pattern(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia-studio/author-card' );
		$this->assertIsArray( $pattern );
		$this->assertSame( 'Author card with profile picture', $pattern['title'] );
		$this->assertSame( array( 'about' ), $pattern['categories'] );
		foreach ( array( 'wp:avatar', 'wp:post-author-name', 'wp:post-author-biography' ) as $block ) {
			$this->assertStringContainsString( $block, $pattern['content'] );
		}
		$this->assertStringNotContainsString( '#', $pattern['content'] );
	}

	/**
	 * Deactivation switches avatars off and activation restores.
	 */
	public function test_deactivation_switches_avatars_off_and_activation_restores(): void {
		$plugin = plugin_basename( LEITVIA_STUDIO_FILE );
		do_action( 'deactivate_' . $plugin, false );
		$this->assertSame( '0', (string) get_option( 'show_avatars' ) );
		$this->assertSame( '1', (string) get_option( 'leitvia_studio_restore_show_avatars' ) );
		do_action( 'activate_' . $plugin, false );
		$this->assertSame( '1', (string) get_option( 'show_avatars' ) );
		$this->assertFalse( get_option( 'leitvia_studio_restore_show_avatars' ) );
	}

	/**
	 * Activation keeps avatars off when they were off.
	 */
	public function test_activation_keeps_avatars_off_when_they_were_off(): void {
		$plugin = plugin_basename( LEITVIA_STUDIO_FILE );
		update_option( 'show_avatars', 0 );
		do_action( 'deactivate_' . $plugin, false );
		$this->assertFalse( get_option( 'leitvia_studio_restore_show_avatars' ) );
		do_action( 'activate_' . $plugin, false );
		$this->assertSame( '0', (string) get_option( 'show_avatars' ) );
	}

	/**
	 * Deactivation leaves avatars alone with gravatar allowed.
	 */
	public function test_deactivation_leaves_avatars_alone_with_gravatar_allowed(): void {
		update_option( 'leitvia_studio_settings', array( 'allow_gravatar' => true ) );
		do_action( 'deactivate_' . plugin_basename( LEITVIA_STUDIO_FILE ), false );
		$this->assertSame( '1', (string) get_option( 'show_avatars' ) );
		$this->assertFalse( get_option( 'leitvia_studio_restore_show_avatars' ) );
	}

	/**
	 * Pingbacks and trackbacks get no avatar, as in core.
	 */
	public function test_pingbacks_get_no_avatar(): void {
		$comment = get_comment( self::factory()->comment->create( array( 'comment_type' => 'pingback' ) ) );
		$this->assertFalse( get_avatar( $comment ) );
	}

	/**
	 * The email match can be switched off for sites that pass visitor input to get_avatar().
	 */
	public function test_email_match_can_be_switched_off(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		add_filter( 'leitvia_studio_avatar_match_email', '__return_false' );
		$this->assertStringContainsString( 'avatar-placeholder.svg', get_avatar_url( 'author@example.org' ) );
		$this->assertStringStartsWith( wp_get_upload_dir()['baseurl'], get_avatar_url( $this->user ) );
	}

	/**
	 * The picture uses the square thumbnail size.
	 */
	public function test_local_picture_uses_the_thumbnail(): void {
		update_user_option( $this->user, 'leitvia_avatar', $this->image );
		$this->assertSame( wp_get_attachment_image_src( $this->image, 'thumbnail' )[0], get_avatar_url( $this->user, array( 'size' => 300 ) ) );
	}

	/**
	 * The form must name the user being saved; profile_update for another user in the same request changes nothing.
	 */
	public function test_save_only_for_the_user_of_the_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( array( 'leitvia_avatar' => $this->image ) );
		$other = self::factory()->user->create();
		Avatars::save( $other );
		$this->assertFalse( get_user_option( 'leitvia_avatar', $other ) );
		$this->assertSame( 10, has_action( 'profile_update', array( Avatars::class, 'save' ) ) );
		$this->assertFalse( has_action( 'personal_options_update', array( Avatars::class, 'save' ) ) );
	}

	/**
	 * Editors may pick any image they can edit, so their error message does not say "that you uploaded".
	 */
	public function test_error_message_for_editors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST  = array(
			'user_id'                     => get_current_user_id(),
			'leitvia_avatar'              => self::factory()->post->create(),
			'leitvia_studio_avatar_nonce' => wp_create_nonce( 'leitvia_studio_avatar' ),
		);
		$errors = new WP_Error();
		Avatars::validate( $errors, true, (object) array( 'ID' => get_current_user_id() ) );
		$this->assertSame( 'Choose an image from the Media Library.', $errors->get_error_message( 'leitvia_avatar_invalid' ) );
	}

	/**
	 * Fills $_POST like the profile form for the author, with a nonce for the current user.
	 *
	 * @param array<string, mixed> $fields Form fields.
	 */
	private function submit( array $fields ): void {
		$_POST = $fields + array(
			'user_id'                     => $this->user,
			'leitvia_studio_avatar_nonce' => wp_create_nonce( 'leitvia_studio_avatar' ),
		);
	}
}
