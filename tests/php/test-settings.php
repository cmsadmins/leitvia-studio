<?php
/**
 * Settings option.
 *
 * @package LeitviaStudio
 */

/**
 * The option leitvia_studio_settings is registered with a schema, saves through /wp/v2/settings and keeps known keys.
 */
class Test_Settings extends WP_UnitTestCase {

	/**
	 * Registered with its group, a closed schema and the defaults.
	 */
	public function test_registered_with_schema(): void {
		$setting = get_registered_settings()['leitvia_studio_settings'] ?? null;
		$this->assertIsArray( $setting );
		$this->assertSame( 'leitvia_studio', $setting['group'] );
		$this->assertSame( 'object', $setting['type'] );
		$schema = $setting['show_in_rest']['schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			array(
				'hide_product_notes'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'delete_data_on_uninstall'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'guard_site_editor_contrast' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'allow_gravatar'             => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'news_schema'                => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'consent_embeds'             => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'emoji_script'               => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'map_tile_url'               => array(
					'type'    => 'string',
					'default' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
					'pattern' => '^https://(?=[^\s]*\{z\})(?=[^\s]*\{x\})(?=[^\s]*\{y\})[^\s"\'<>]+$',
				),
			),
			$schema['properties']
		);
	}

	/**
	 * Without a saved option the helper returns the defaults.
	 */
	public function test_defaults_without_option(): void {
		delete_option( 'leitvia_studio_settings' );
		$this->assertTrue( leitvia_studio_setting( 'guard_site_editor_contrast' ) );
		$this->assertFalse( leitvia_studio_setting( 'hide_product_notes' ) );
		$this->assertFalse( leitvia_studio_setting( 'delete_data_on_uninstall' ) );
		$this->assertFalse( leitvia_studio_setting( 'allow_gravatar' ) );
		$this->assertTrue( leitvia_studio_setting( 'news_schema' ) );
		$this->assertTrue( leitvia_studio_setting( 'consent_embeds' ) );
		$this->assertFalse( leitvia_studio_setting( 'emoji_script' ) );
	}

	/**
	 * The emoji loader of core (images from s.w.org) is gone by default and comes back only with emoji_script.
	 */
	public function test_emoji_loader_only_on_request(): void {
		delete_option( 'leitvia_studio_settings' );
		\LeitviaStudio\Admin\Settings::remove_emoji_loader();
		$this->assertFalse( has_action( 'wp_head', 'print_emoji_detection_script' ) );
		$this->assertFalse( has_action( 'admin_print_scripts', 'print_emoji_detection_script' ) );
		$this->assertFalse( has_filter( 'wp_mail', 'wp_staticize_emoji_for_email' ) );
		ob_start();
		wp_head();
		$this->assertStringNotContainsString( 'wpemojiSettings', (string) ob_get_clean() );

		add_action( 'wp_head', 'print_emoji_detection_script', 7 );
		update_option( 'leitvia_studio_settings', array( 'emoji_script' => true ) );
		\LeitviaStudio\Admin\Settings::remove_emoji_loader();
		$this->assertSame( 7, has_action( 'wp_head', 'print_emoji_detection_script' ) );
	}

	/**
	 * Unknown keys are dropped, values become booleans, missing keys get their default.
	 */
	public function test_sanitize_drops_unknown_keys(): void {
		update_option(
			'leitvia_studio_settings',
			array(
				'hide_product_notes' => '1',
				'unknown'            => true,
			)
		);
		$this->assertSame(
			array(
				'hide_product_notes'         => true,
				'delete_data_on_uninstall'   => false,
				'guard_site_editor_contrast' => true,
				'allow_gravatar'             => false,
				'news_schema'                => true,
				'consent_embeds'             => true,
				'emoji_script'               => false,
				'map_tile_url'               => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			),
			get_option( 'leitvia_studio_settings' )
		);
	}

	/**
	 * Only administrators read and write the option over REST; unknown keys are refused there by the schema.
	 */
	public function test_rest_only_for_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params(
			array(
				'leitvia_studio_settings' => array(
					'hide_product_notes'         => true,
					'delete_data_on_uninstall'   => true,
					'guard_site_editor_contrast' => false,
					'allow_gravatar'             => true,
					'news_schema'                => true,
					'consent_embeds'             => true,
					'emoji_script'               => false,
				),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'hide_product_notes'         => true,
				'delete_data_on_uninstall'   => true,
				'guard_site_editor_contrast' => false,
				'allow_gravatar'             => true,
				'news_schema'                => true,
				'consent_embeds'             => true,
				'emoji_script'               => false,
				'map_tile_url'               => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			),
			$response->get_data()['leitvia_studio_settings']
		);
		$this->assertFalse( leitvia_studio_setting( 'guard_site_editor_contrast' ) );

		// A partial update changes only the keys it sends.
		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'hide_product_notes' => false ) ) );
		$this->assertSame(
			array(
				'hide_product_notes'         => false,
				'delete_data_on_uninstall'   => true,
				'guard_site_editor_contrast' => false,
				'allow_gravatar'             => true,
				'news_schema'                => true,
				'consent_embeds'             => true,
				'emoji_script'               => false,
				'map_tile_url'               => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			),
			rest_do_request( $request )->get_data()['leitvia_studio_settings']
		);

		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'unknown' => true ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
