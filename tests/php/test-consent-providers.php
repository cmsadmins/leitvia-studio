<?php
/**
 * Providers of embedded content kept in the consent settings.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Settings;
use LeitviaStudio\Consent\Consent;

/**
 * A person adds providers under Settings, Privacy; the consent placeholder names them, links to their privacy policy
 * and remembers "Always allow" under their key.
 */
class Test_Consent_Providers extends WP_UnitTestCase {

	/**
	 * Starts without saved settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	/**
	 * A provider: a kitchen planner.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 */
	private static function planner( array $changes = array() ): array {
		return $changes + array(
			'label'   => 'Kitchen Planner',
			'hosts'   => array( 'planner.example.com' ),
			'purpose' => '',
			'privacy' => 'https://planner.example.com/privacy',
		);
	}

	/**
	 * Hosts lose scheme, port and path and are lowercase; the own site, invalid hosts, a privacy policy without https and
	 * a provider without name or host are dropped.
	 */
	public function test_hosts_and_links_are_checked(): void {
		$own   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$clean = Consent::sanitize_providers(
			array(
				self::planner(
					array(
						'hosts'   => array( 'https://Planner.Example.com:8443/embed', 'not a host', $own, 'planner.example.com', array( 'x' ) ),
						'privacy' => 'http://planner.example.com/privacy',
					)
				),
				self::planner( array( 'label' => '' ) ),
				self::planner( array( 'hosts' => array( 'nothing here' ) ) ),
				'not a provider',
			)
		);
		$this->assertSame(
			array(
				array(
					'key'     => 'kitchen-planner',
					'label'   => 'Kitchen Planner',
					'hosts'   => array( 'planner.example.com' ),
					'purpose' => '',
					'privacy' => '',
				),
			),
			$clean
		);
	}

	/**
	 * Keys come from the label, stay once saved, never take a key of a built-in service or a category of scripts and
	 * never repeat.
	 */
	public function test_keys_are_unique_and_stay(): void {
		$clean = Consent::sanitize_providers(
			array(
				self::planner( array( 'label' => 'YouTube' ) ),
				self::planner( array( 'label' => 'Statistics' ) ),
				self::planner( array( 'label' => 'Küchenplaner' ) ),
				self::planner( array( 'label' => 'Küchenplaner' ) ),
				self::planner(
					array(
						'key'   => 'kitchen-planner',
						'label' => 'Renamed planner',
					)
				),
			)
		);
		$this->assertSame( array( 'youtube-2', 'statistics-2', 'kuchenplaner', 'kuchenplaner-2', 'kitchen-planner' ), array_column( $clean, 'key' ) );
	}

	/**
	 * A new provider listed before a saved one with the key it would make never takes that key: the saved one keeps it.
	 */
	public function test_saved_key_wins_over_a_new_provider(): void {
		$clean = Consent::sanitize_providers(
			array(
				self::planner(),
				self::planner( array( 'key' => 'kitchen-planner' ) ),
			)
		);
		$this->assertSame( array( 'kitchen-planner-2', 'kitchen-planner' ), array_column( $clean, 'key' ) );
	}

	/**
	 * A provider keeps at most MAX_HOSTS different hosts.
	 */
	public function test_at_most_ten_hosts(): void {
		$hosts = array_map( static fn( int $n ): string => "h$n.planner.example.com", range( 1, 15 ) );
		$clean = Consent::sanitize_providers( array( self::planner( array( 'hosts' => array_merge( array( 'h1.planner.example.com' ), $hosts ) ) ) ) );
		$this->assertSame( array_slice( $hosts, 0, Consent::MAX_HOSTS ), $clean[0]['hosts'] );
	}

	/**
	 * At most MAX_PROVIDERS providers stay.
	 */
	public function test_at_most_twenty_providers(): void {
		$many = array_map( static fn( int $n ): array => self::planner( array( 'label' => "Planner $n" ) ), range( 1, 25 ) );
		$this->assertCount( Consent::MAX_PROVIDERS, Consent::sanitize_providers( $many ) );
	}

	/**
	 * A saved provider names the placeholder of its hosts and their subdomains, links to its privacy policy and appears
	 * with its label in the consent settings block; an empty purpose leaves the sentence of the placeholder.
	 */
	public function test_saved_provider_names_the_placeholder(): void {
		update_option( Settings::OPTION, array( 'consent_providers' => array( self::planner() ) ) + Settings::DEFAULTS );
		$this->assertSame( 'kitchen-planner', Consent::service_for( 'https://eu.planner.example.com/embed' ) );
		$this->assertSame( 'Kitchen Planner', Consent::label( 'kitchen-planner' ) );
		$html = Consent::placeholder( 'kitchen-planner', '<iframe src="https://planner.example.com/embed" title="Planner"></iframe>', 'https://planner.example.com/embed' );
		$this->assertStringContainsString( 'Content from Kitchen Planner', $html );
		$this->assertStringContainsString( 'This content is hosted by Kitchen Planner.', $html );
		$this->assertStringContainsString( '<a href="https://planner.example.com/privacy">Privacy policy of Kitchen Planner</a>', $html );
		$this->assertStringContainsString( 'Always allow Kitchen Planner on this site', $html );

		Consent::filter_settings_block( '<p role="status" data-wp-text="state.status"></p>' );
		$this->assertSame( 'Kitchen Planner', wp_interactivity_state( 'leitvia/consent' )['labels']['kitchen-planner'] );
	}

	/**
	 * A purpose comes first; the sentence on IP address, device and cookies follows it.
	 */
	public function test_purpose_and_data_flow(): void {
		update_option( Settings::OPTION, array( 'consent_providers' => array( self::planner( array( 'purpose' => 'Plan your kitchen online.' ) ) ) ) + Settings::DEFAULTS );
		$html = Consent::placeholder( 'kitchen-planner', '<iframe src="https://planner.example.com/embed" title="Planner"></iframe>', 'https://planner.example.com/embed' );
		$this->assertStringContainsString( '<p class="leitvia-consent__purpose">Plan your kitchen online. This content is hosted by Kitchen Planner. Loading it sends your IP address and details of your device to the provider, which may also set cookies.', $html );
	}

	/**
	 * A host matches as a whole label: the userinfo before @ and a look-alike host name never name the provider; case
	 * and port do not matter, and hosts added by the filter match in any case.
	 */
	public function test_host_matching(): void {
		update_option( Settings::OPTION, array( 'consent_providers' => array( self::planner() ) ) + Settings::DEFAULTS );
		$this->assertSame( 'evil.example', Consent::service_for( 'https://planner.example.com@evil.example/' ) );
		$this->assertSame( 'notplanner.example.com', Consent::service_for( 'https://notplanner.example.com/embed' ) );
		$this->assertSame( 'kitchen-planner', Consent::service_for( 'https://Planner.EXAMPLE.com/embed' ) );
		$this->assertSame( 'kitchen-planner', Consent::service_for( 'https://planner.example.com:8443/embed' ) );

		$filter = static function ( array $services ): array {
			$services['booking'] = array(
				'label' => 'Booking',
				'hosts' => array( 'Booking.Example.NET' ),
			);
			return $services;
		};
		add_filter( 'leitvia_studio_consent_services', $filter );
		$this->assertSame( 'booking', Consent::service_for( 'https://eu.booking.example.net/widget' ) );
		remove_filter( 'leitvia_studio_consent_services', $filter );
	}

	/**
	 * A built-in service keeps its hosts: a provider that lists youtube.com does not take YouTube embeds.
	 */
	public function test_built_in_services_come_first(): void {
		update_option( Settings::OPTION, array( 'consent_providers' => array( self::planner( array( 'hosts' => array( 'youtube.com' ) ) ) ) ) + Settings::DEFAULTS );
		$this->assertSame( 'youtube', Consent::service_for( 'https://www.youtube.com/embed/abc' ) );
	}

	/**
	 * A preview image of the site sits in the placeholder in front of the texts; without one the markup stays as before.
	 */
	public function test_preview_image(): void {
		$iframe  = '<iframe src="https://planner.example.com/embed" title="Planner"></iframe>';
		$preview = '<img class="leitvia-consent__preview" src="' . esc_url( home_url( '/wp-content/uploads/planner.jpg' ) ) . '" alt="">';
		$with    = Consent::placeholder( 'planner.example.com', $iframe, 'https://planner.example.com/embed', $preview );
		$this->assertMatchesRegularExpression( '#<div class="leitvia-consent__placeholder has-preview"><img class="leitvia-consent__preview"[^>]*><div class="leitvia-consent__text"><p class="leitvia-consent__title">#', $with );
		$without = Consent::placeholder( 'planner.example.com', $iframe, 'https://planner.example.com/embed' );
		$this->assertStringContainsString( '<div class="leitvia-consent__placeholder"><p class="leitvia-consent__title">', $without );
		$this->assertStringNotContainsString( 'leitvia-consent__text', $without );
	}

	/**
	 * Over REST an administrator saves providers, which come back cleaned.
	 */
	public function test_rest_saves_cleaned_providers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( Settings::OPTION => array( 'consent_providers' => array( self::planner( array( 'hosts' => array( 'https://Planner.Example.com/embed' ) ) ) ) ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'planner.example.com' ), $response->get_data()[ Settings::OPTION ]['consent_providers'][0]['hosts'] );
	}
}
