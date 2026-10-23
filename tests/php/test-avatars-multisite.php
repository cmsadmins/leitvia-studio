<?php
/**
 * Local profile pictures in a network.
 *
 * @package LeitviaStudio
 */

/**
 * The picture belongs to one site; network wide deactivation and activation reach every site.
 *
 * @group ms-required
 * @group multisite
 */
class Test_Avatars_Multisite extends WP_UnitTestCase {

	/**
	 * Removes the uploaded files.
	 */
	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Picture is set per site.
	 */
	public function test_picture_is_set_per_site(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$site = self::factory()->blog->create();

		switch_to_blog( $site );
		$image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		update_user_option( $user, 'leitvia_avatar', $image );
		$this->assertStringStartsWith( wp_get_upload_dir()['baseurl'], get_avatar_url( $user ) );
		$this->assertStringContainsString( "/sites/{$site}/", get_avatar_url( $user ) );
		restore_current_blog();

		$this->assertStringContainsString( 'avatar-placeholder.svg', get_avatar_url( $user ) );
		$this->assertSame( (string) $image, (string) get_user_meta( $user, $wpdb->get_blog_prefix( $site ) . 'leitvia_avatar', true ) );
		$this->assertSame( '', get_user_meta( $user, $wpdb->get_blog_prefix( get_current_blog_id() ) . 'leitvia_avatar', true ) );
	}

	/**
	 * Network wide deactivation and activation.
	 */
	public function test_network_wide_deactivation_and_activation(): void {
		$plugin = plugin_basename( LEITVIA_STUDIO_FILE );
		$on     = self::factory()->blog->create();
		$off    = self::factory()->blog->create();
		update_option( 'show_avatars', 1 );
		foreach ( array(
			$on  => 1,
			$off => 0,
		) as $site => $value ) {
			switch_to_blog( $site );
			update_option( 'show_avatars', $value );
			restore_current_blog();
		}

		do_action( 'deactivate_' . $plugin, true );
		foreach ( array( get_current_blog_id(), $on, $off ) as $site ) {
			switch_to_blog( $site );
			$this->assertSame( '0', (string) get_option( 'show_avatars' ), "site {$site}" );
			restore_current_blog();
		}

		do_action( 'activate_' . $plugin, true );
		foreach ( array(
			get_current_blog_id() => '1',
			$on                   => '1',
			$off                  => '0',
		) as $site => $expected ) {
			switch_to_blog( $site );
			$this->assertSame( $expected, (string) get_option( 'show_avatars' ), "site {$site}" );
			$this->assertFalse( get_option( 'leitvia_studio_restore_show_avatars' ), "site {$site}" );
			restore_current_blog();
		}
	}
}
