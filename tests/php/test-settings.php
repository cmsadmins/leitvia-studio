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
				'news_categories'            => array(
					'type'    => 'array',
					'default' => array(),
					'items'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'news_sitemap'               => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'title_site_name'            => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'formal_address'             => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'editorial_page'             => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'motion'                     => array(
					'type'    => 'string',
					'default' => 'subtle',
					'enum'    => array( 'off', 'subtle', 'bold' ),
				),
				'organization_street'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'organization_postal_code'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'organization_city'          => array(
					'type'    => 'string',
					'default' => '',
				),
				'organization_country'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'organization_phone'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'organization_email'         => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			array_slice( $schema['properties'], 0, 20 )
		);
	}

	/**
	 * The settings of round 14: consent providers, kind of business, founding date and the lists of the organization.
	 */
	public function test_schema_of_lists_and_organization(): void {
		$properties = get_registered_settings()['leitvia_studio_settings']['show_in_rest']['schema']['properties'];
		$this->assertSame(
			array( 'organization_contact_type', 'consent_providers', 'organization_type', 'organization_founding_date', 'organization_founder', 'organization_copyright_since', 'organization_copyright_holder', 'organization_area_served', 'organization_opening_hours', 'organization_same_as', 'organization_people', 'organization_rating_value', 'organization_rating_count', 'organization_rating_source', 'organization_rating_confirmed', 'ai_crawlers', 'llms_txt', 'llms_summary', 'llms_pages', 'redirects', 'organization_description', 'organization_logo', 'robots_disallow', 'llms_news' ),
			array_keys( array_slice( $properties, 20 ) )
		);
		$this->assertSame( 'array', $properties['consent_providers']['type'] );
		$this->assertSame( array( 'key', 'label', 'hosts', 'purpose', 'privacy' ), array_keys( $properties['consent_providers']['items']['properties'] ) );
		$this->assertFalse( $properties['consent_providers']['items']['additionalProperties'] );
		$this->assertSame( \LeitviaStudio\Organization\Organization::TYPES, $properties['organization_type']['enum'] );
		$this->assertSame( 'Organization', $properties['organization_type']['default'] );
		$this->assertSame( \LeitviaStudio\Organization\Organization::DATE, $properties['organization_founding_date']['pattern'] );
		$this->assertSame( \LeitviaStudio\Organization\Organization::YEAR, $properties['organization_copyright_since']['pattern'] );
		$this->assertSame( array( 'type' => 'string' ), $properties['organization_area_served']['items'] );
		$this->assertSame( \LeitviaStudio\Organization\Organization::DAYS, $properties['organization_opening_hours']['items']['properties']['days']['items']['enum'] );
		$this->assertSame( \LeitviaStudio\Organization\Organization::TIME, $properties['organization_opening_hours']['items']['properties']['opens']['pattern'] );
		$this->assertSame( array( 'type' => 'string' ), $properties['organization_same_as']['items'] );
	}

	/**
	 * Lists keep valid entries only; a wrong kind of business or founding date falls back.
	 */
	public function test_sanitize_lists_and_organization(): void {
		$clean = \LeitviaStudio\Admin\Settings::sanitize(
			array(
				'organization_type'          => 'Castle',
				'organization_founding_date' => '62',
				'organization_area_served'   => array( ' Kassel ', '', 'Kassel', 'Baunatal', array( 'x' ) ),
				'organization_same_as'       => array( 'https://example.org/brackenfold', 'http://example.org/old', 'javascript:alert(1)', 'https://example.org/brackenfold' ),
				'organization_opening_hours' => 'none',
			)
		);
		$this->assertSame( 'Organization', $clean['organization_type'] );
		$this->assertSame( '', $clean['organization_founding_date'] );
		$this->assertSame( array( 'Kassel', 'Baunatal' ), $clean['organization_area_served'] );
		$this->assertSame( array( 'https://example.org/brackenfold' ), $clean['organization_same_as'] );
		$this->assertSame( array(), $clean['organization_opening_hours'] );

		$clean = \LeitviaStudio\Admin\Settings::sanitize(
			array(
				'organization_type'          => 'GeneralContractor',
				'organization_founding_date' => '1962-04-01',
			)
		);
		$this->assertSame( 'GeneralContractor', $clean['organization_type'] );
		$this->assertSame( '1962-04-01', $clean['organization_founding_date'] );

		// A day has to be on the calendar, a month between 1 and 12.
		$dates = array(
			'1962'       => '1962',
			'1962-12'    => '1962-12',
			'1962-13'    => '',
			'1962-00'    => '',
			'2024-02-29' => '2024-02-29',
			'2025-02-29' => '',
			'1962-04-31' => '',
			'1962-4-1'   => '',
		);
		foreach ( $dates as $date => $expected ) {
			$this->assertSame( $expected, \LeitviaStudio\Admin\Settings::sanitize( array( 'organization_founding_date' => (string) $date ) )['organization_founding_date'], (string) $date );
		}
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
				'hide_product_notes'            => true,
				'delete_data_on_uninstall'      => false,
				'guard_site_editor_contrast'    => true,
				'allow_gravatar'                => false,
				'news_schema'                   => true,
				'consent_embeds'                => true,
				'emoji_script'                  => false,
				'map_tile_url'                  => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'news_categories'               => array(),
				'news_sitemap'                  => true,
				'title_site_name'               => true,
				'formal_address'                => false,
				'editorial_page'                => 0,
				'motion'                        => 'subtle',
				'organization_street'           => '',
				'organization_postal_code'      => '',
				'organization_city'             => '',
				'organization_country'          => '',
				'organization_phone'            => '',
				'organization_email'            => '',
				'organization_contact_type'     => '',
				'consent_providers'             => array(),
				'organization_type'             => 'Organization',
				'organization_founding_date'    => '',
				'organization_founder'          => '',
				'organization_copyright_since'  => '',
				'organization_copyright_holder' => '',
				'organization_area_served'      => array(),
				'organization_opening_hours'    => array(),
				'organization_same_as'          => array(),
				'organization_people'           => array(),
				'organization_rating_value'     => '',
				'organization_rating_count'     => 0,
				'organization_rating_source'    => '',
				'organization_rating_confirmed' => false,
				'ai_crawlers'                   => true,
				'llms_txt'                      => true,
				'llms_summary'                  => '',
				'llms_pages'                    => array(),
				'redirects'                     => array(),
				'organization_description'      => '',
				'organization_logo'             => 0,
				'robots_disallow'               => array(),
				'llms_news'                     => 0,
			),
			get_option( 'leitvia_studio_settings' )
		);
	}

	/**
	 * News settings: category IDs as a list of unique positive integers, the editorial page as an ID, the sitemap as a
	 * boolean; missing keys keep their saved value.
	 */
	public function test_sanitize_news_settings(): void {
		update_option(
			'leitvia_studio_settings',
			array(
				'news_categories' => array( '3', 3, 0, -2, 'x', 7 ),
				'news_sitemap'    => '0',
				'editorial_page'  => '12',
			)
		);
		$saved = get_option( 'leitvia_studio_settings' );
		$this->assertSame( array( 3, 2, 7 ), $saved['news_categories'] );
		$this->assertFalse( $saved['news_sitemap'] );
		$this->assertSame( 12, $saved['editorial_page'] );

		update_option( 'leitvia_studio_settings', array( 'hide_product_notes' => true ) );
		$this->assertSame( array( 3, 2, 7 ), leitvia_studio_setting( 'news_categories' ) );
		$this->assertSame( 12, leitvia_studio_setting( 'editorial_page' ) );
	}

	/**
	 * Motion keeps off, subtle and bold; anything else keeps the saved value, else subtle. REST refuses other values.
	 */
	public function test_sanitize_motion(): void {
		delete_option( 'leitvia_studio_settings' );
		$this->assertSame( 'subtle', leitvia_studio_setting( 'motion' ) );
		update_option( 'leitvia_studio_settings', array( 'motion' => 'bold' ) );
		$this->assertSame( 'bold', leitvia_studio_setting( 'motion' ) );
		update_option( 'leitvia_studio_settings', array( 'motion' => 'spin' ) );
		$this->assertSame( 'bold', leitvia_studio_setting( 'motion' ) );
		update_option( 'leitvia_studio_settings', array( 'motion' => 'off' ) );
		$this->assertSame( 'off', leitvia_studio_setting( 'motion' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'motion' => 'wild' ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'motion' => 'subtle' ) ) );
		$this->assertSame( 'subtle', rest_do_request( $request )->get_data()['leitvia_studio_settings']['motion'] );
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
				'hide_product_notes'            => true,
				'delete_data_on_uninstall'      => true,
				'guard_site_editor_contrast'    => false,
				'allow_gravatar'                => true,
				'news_schema'                   => true,
				'consent_embeds'                => true,
				'emoji_script'                  => false,
				'map_tile_url'                  => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'news_categories'               => array(),
				'news_sitemap'                  => true,
				'title_site_name'               => true,
				'formal_address'                => false,
				'editorial_page'                => 0,
				'motion'                        => 'subtle',
				'organization_street'           => '',
				'organization_postal_code'      => '',
				'organization_city'             => '',
				'organization_country'          => '',
				'organization_phone'            => '',
				'organization_email'            => '',
				'organization_contact_type'     => '',
				'consent_providers'             => array(),
				'organization_type'             => 'Organization',
				'organization_founding_date'    => '',
				'organization_founder'          => '',
				'organization_copyright_since'  => '',
				'organization_copyright_holder' => '',
				'organization_area_served'      => array(),
				'organization_opening_hours'    => array(),
				'organization_same_as'          => array(),
				'organization_people'           => array(),
				'organization_rating_value'     => '',
				'organization_rating_count'     => 0,
				'organization_rating_source'    => '',
				'organization_rating_confirmed' => false,
				'ai_crawlers'                   => true,
				'llms_txt'                      => true,
				'llms_summary'                  => '',
				'llms_pages'                    => array(),
				'redirects'                     => array(),
				'organization_description'      => '',
				'organization_logo'             => 0,
				'robots_disallow'               => array(),
				'llms_news'                     => 0,
			),
			$response->get_data()['leitvia_studio_settings']
		);
		$this->assertFalse( leitvia_studio_setting( 'guard_site_editor_contrast' ) );

		// A partial update changes only the keys it sends.
		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'hide_product_notes' => false ) ) );
		$this->assertSame(
			array(
				'hide_product_notes'            => false,
				'delete_data_on_uninstall'      => true,
				'guard_site_editor_contrast'    => false,
				'allow_gravatar'                => true,
				'news_schema'                   => true,
				'consent_embeds'                => true,
				'emoji_script'                  => false,
				'map_tile_url'                  => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'news_categories'               => array(),
				'news_sitemap'                  => true,
				'title_site_name'               => true,
				'formal_address'                => false,
				'editorial_page'                => 0,
				'motion'                        => 'subtle',
				'organization_street'           => '',
				'organization_postal_code'      => '',
				'organization_city'             => '',
				'organization_country'          => '',
				'organization_phone'            => '',
				'organization_email'            => '',
				'organization_contact_type'     => '',
				'consent_providers'             => array(),
				'organization_type'             => 'Organization',
				'organization_founding_date'    => '',
				'organization_founder'          => '',
				'organization_copyright_since'  => '',
				'organization_copyright_holder' => '',
				'organization_area_served'      => array(),
				'organization_opening_hours'    => array(),
				'organization_same_as'          => array(),
				'organization_people'           => array(),
				'organization_rating_value'     => '',
				'organization_rating_count'     => 0,
				'organization_rating_source'    => '',
				'organization_rating_confirmed' => false,
				'ai_crawlers'                   => true,
				'llms_txt'                      => true,
				'llms_summary'                  => '',
				'llms_pages'                    => array(),
				'redirects'                     => array(),
				'organization_description'      => '',
				'organization_logo'             => 0,
				'robots_disallow'               => array(),
				'llms_news'                     => 0,
			),
			rest_do_request( $request )->get_data()['leitvia_studio_settings']
		);

		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'unknown' => true ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
