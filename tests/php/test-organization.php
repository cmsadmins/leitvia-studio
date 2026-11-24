<?php
/**
 * Organization details as a local business.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Settings;
use LeitviaStudio\Organization\Organization;

/**
 * Kind of business, founding date, area served, opening hours and profiles: saved through the settings, printed as
 * one node on the front page and as publisher of posts, read by add-ons and set by agents.
 */
class Test_Organization extends WP_UnitTestCase {

	/**
	 * Starts without saved settings, with the site name.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		update_option( 'blogname', 'Example Joinery' );
	}

	/**
	 * Removes the abilities test_set_organization() handed to core.
	 */
	public function tear_down(): void {
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
	 * Saves organization details through the option, whose sanitize callback cleans them.
	 *
	 * @param array<string, mixed> $details Settings.
	 */
	private static function save( array $details ): void {
		update_option( Settings::OPTION, $details + Settings::DEFAULTS );
	}

	/**
	 * Rows keep known days in the order of the week and times as HH:MM; a row without days or with a wrong time goes.
	 */
	public function test_opening_hours_are_cleaned(): void {
		$this->assertSame(
			array(
				array(
					'days'   => array( 'Monday', 'Saturday' ),
					'opens'  => '08:00',
					'closes' => '17:30',
				),
			),
			Organization::hours(
				array(
					array(
						'days'   => array( 'Saturday', 'Monday', 'Funday', 'Monday' ),
						'opens'  => '08:00',
						'closes' => '17:30',
					),
					array(
						'days'   => array( 'Sunday' ),
						'opens'  => '25:00',
						'closes' => '12:00',
					),
					array(
						'days'   => array(),
						'opens'  => '08:00',
						'closes' => '12:00',
					),
					'not a row',
				)
			)
		);
		$this->assertSame( array(), Organization::hours( 'none' ) );
	}

	/**
	 * Without saved settings: an Organization with the name and address of the site, empty strings and lists.
	 */
	public function test_details_default(): void {
		$this->assertSame(
			array(
				'type'             => 'Organization',
				'name'             => 'Example Joinery',
				'url'              => home_url( '/' ),
				'street'           => '',
				'postal_code'      => '',
				'city'             => '',
				'country'          => '',
				'phone'            => '',
				'email'            => '',
				'founding_date'    => '',
				'founder'          => '',
				'copyright_since'  => '',
				'copyright_holder' => '',
				'area_served'      => array(),
				'opening_hours'    => array(),
				'same_as'          => array(),
				'aggregate_rating' => null,
			),
			Organization::details()
		);
		$this->assertSame( Organization::details(), leitvia_studio_organization_details() );
	}

	/**
	 * A local business carries its type, founding date, area served, opening hours and profiles; empty fields stay out.
	 */
	public function test_node_of_a_local_business(): void {
		self::save(
			array(
				'organization_type'          => 'GeneralContractor',
				'organization_city'          => 'Kassel',
				'organization_founding_date' => '1962',
				'organization_area_served'   => array( 'Kassel', 'Baunatal' ),
				'organization_opening_hours' => array(
					array(
						'days'   => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ),
						'opens'  => '07:30',
						'closes' => '16:30',
					),
				),
				'organization_same_as'       => array( 'https://example.org/brackenfold' ),
			)
		);
		$this->assertSame(
			array(
				'@type'                     => 'GeneralContractor',
				'@id'                       => home_url( '/#organization' ),
				'name'                      => 'Example Joinery',
				'url'                       => home_url( '/' ),
				'address'                   => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => 'Kassel',
				),
				'foundingDate'              => '1962',
				'areaServed'                => array( 'Kassel', 'Baunatal' ),
				'sameAs'                    => array( 'https://example.org/brackenfold' ),
				'openingHoursSpecification' => array(
					array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ),
						'opens'     => '07:30',
						'closes'    => '16:30',
					),
				),
			),
			Organization::node()
		);
		$this->assertSame( Organization::node(), leitvia_studio_organization_schema() );
	}

	/**
	 * Opening hours belong to a local business; an Organization never carries them.
	 */
	public function test_organization_has_no_opening_hours(): void {
		self::save(
			array(
				'organization_opening_hours' => array(
					array(
						'days'   => array( 'Monday' ),
						'opens'  => '08:00',
						'closes' => '12:00',
					),
				),
			)
		);
		$this->assertSame( 'Organization', Organization::node()['@type'] );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', Organization::node() );
	}

	/**
	 * A local business with a logo also gets it as image.
	 */
	public function test_local_business_uses_the_logo_as_image(): void {
		self::save( array( 'organization_type' => 'Plumber' ) );
		set_theme_mod( 'custom_logo', self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' ) );
		$node = Organization::node();
		$this->assertSame( 'ImageObject', $node['logo']['@type'] );
		$this->assertSame( $node['logo']['url'], $node['image'] );
		remove_theme_mod( 'custom_logo' );
		$this->remove_added_uploads();
	}

	/**
	 * The front page carries the organization as a node of its own, which the WebSite names by @id; a post carries no
	 * such node, only its publisher.
	 */
	public function test_node_on_the_front_page_only(): void {
		self::save( array( 'organization_type' => 'Plumber' ) );
		$this->go_to( home_url( '/' ) );
		$graph = \LeitviaStudio\News\Schema::current()['@graph'];
		$this->assertSame( array( 'WebSite', 'Plumber' ), array_column( $graph, '@type' ) );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $graph[0]['publisher'] );

		$this->go_to( get_permalink( self::factory()->post->create() ) );
		$graph = \LeitviaStudio\News\Schema::current()['@graph'];
		$this->assertNotContains( 'Plumber', array_column( $graph, '@type' ) );
		$this->assertSame( 'Plumber', $graph[0]['publisher']['@type'] );
		$this->assertSame( home_url( '/#organization' ), $graph[0]['publisher']['@id'] );
	}

	/**
	 * The ability set-organization takes the new fields, merges only what it names over the saved settings and refuses an input
	 * without a field; get-seo shows the same details.
	 */
	public function test_set_organization(): void {
		// Other test classes unregister the abilities in their tear_down; hand them to core again.
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$properties = wp_get_ability( 'leitvia/set-organization' )->get_input_schema()['properties'];
		foreach ( Organization::FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $properties, $field );
		}
		$this->assertSame( Organization::TYPES, $properties['type']['enum'] );
		$this->assertSame( Organization::TIME, $properties['opening_hours']['items']['properties']['opens']['pattern'] );

		$hours     = array(
			array(
				'days'   => array( 'Saturday' ),
				'opens'  => '09:00',
				'closes' => '12:00',
			),
		);
		$operation = \LeitviaStudio\Gateway\Abilities\Setup::describe_set_organization(
			array(
				'type'          => 'GeneralContractor',
				'area_served'   => array( 'Kassel' ),
				'opening_hours' => $hours,
			)
		);
		$this->assertIsArray( $operation );
		$this->assertSame(
			array(
				'organization_type'          => 'GeneralContractor',
				'organization_area_served'   => array( 'Kassel' ),
				'organization_opening_hours' => $hours,
				'organization_city'          => 'Kassel',
			),
			$operation['change']( array( 'value' => array( 'organization_city' => 'Kassel' ) ) )['value']
		);
		$this->assertWPError( \LeitviaStudio\Gateway\Abilities\Setup::describe_set_organization( array() ) );

		self::save(
			array(
				'organization_type'          => 'GeneralContractor',
				'organization_city'          => 'Kassel',
				'organization_founding_date' => '1962-04',
				'organization_area_served'   => array( 'Kassel' ),
				'organization_opening_hours' => $hours,
				'organization_same_as'       => array( 'https://example.org/brackenfold' ),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$seo = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( self::factory()->post->create() ) ) );
		$this->assertIsArray( $seo, is_wp_error( $seo ) ? $seo->get_error_message() : '' );
		$this->assertSame( Organization::details(), $seo['organization'] );
		$this->assertSame( Organization::TYPES, wp_get_ability( 'leitvia/get-seo' )->get_output_schema()['properties']['organization']['properties']['type']['enum'] );
		// The schema the end-to-end tests check the node against lists the same kinds of business.
		$fixture = wp_json_file_decode( dirname( __DIR__ ) . '/fixtures/news/organization.schema.json', array( 'associative' => true ) );
		$this->assertSame( Organization::TYPES, $fixture['properties']['@type']['enum'] );
	}

	/**
	 * The founder is a Person in the node; the copyright line of the theme takes the start year and the holder from the
	 * settings where its binding names none, and a year that is no YYYY is dropped.
	 */
	public function test_founder_and_copyright_line(): void {
		self::save(
			array(
				'organization_founder'          => 'Ada Example',
				'organization_copyright_since'  => '2009',
				'organization_copyright_holder' => 'Example & Sons',
			)
		);
		$this->assertSame(
			array(
				'@type' => 'Person',
				'name'  => 'Ada Example',
			),
			Organization::node()['founder']
		);
		$this->assertSame(
			array(
				'since'  => '2009',
				'holder' => 'Example & Sons',
			),
			apply_filters(
				'leitvia_copyright',
				array(
					'since'  => '',
					'holder' => '',
				)
			)
		);
		$this->assertSame(
			'Own',
			apply_filters(
				'leitvia_copyright',
				array(
					'since'  => '',
					'holder' => 'Own',
				)
			)['holder']
		);
		// The theme of the test stack is Leitvia; its binding prints the line.
		if ( function_exists( 'Leitvia\site_meta_value' ) ) {
			$this->assertSame( '© 2009–' . wp_date( 'Y' ) . ' Example &amp; Sons', \Leitvia\site_meta_value( array( 'key' => 'copyright' ) ) );
		}
		self::save( array( 'organization_copyright_since' => '09' ) );
		$this->assertSame( '', Organization::details()['copyright_since'] );
	}
}
