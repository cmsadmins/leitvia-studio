<?php
/**
 * Map block.
 *
 * @package LeitviaStudio
 */

/**
 * The map requests nothing before consent: the canvas with the tile URL sits in the <template> of the placeholder,
 * the list of places and the links stay in the page, and the tile URL setting only takes https URLs with {z}, {x}, {y}.
 */
class Test_Map extends WP_UnitTestCase {

	private const BLOCK = '<!-- wp:leitvia/map {"lat":52.5,"lng":13.4,"zoom":12,"markers":[{"title":"Office <b>Berlin</b>","address":"Main street 1","lat":52.52,"lng":13.405}]} --><div class="wp-block-leitvia-map is-height-medium"><h2 class="wp-block-leitvia-map__heading">Find us</h2><ul class="wp-block-leitvia-map__places"><li><strong>Office Berlin</strong><span>Main street 1</span><a href="https://www.openstreetmap.org/?mlat=52.52&amp;mlon=13.405#map=12/52.52/13.405">View on OpenStreetMap</a></li></ul></div><!-- /wp:leitvia/map -->';

	/**
	 * Deletes the settings.
	 */
	public function tear_down(): void {
		delete_option( 'leitvia_studio_settings' );
		parent::tear_down();
	}

	/**
	 * Markup outside every <template>, the part a browser loads.
	 *
	 * @param string $html Rendered HTML.
	 */
	private static function live( string $html ): string {
		return (string) preg_replace( '#<template\b.*?</template>#s', '', $html );
	}

	/**
	 * Without consent no tile host outside the template.
	 */
	public function test_without_consent_no_tile_host_outside_the_template(): void {
		$html = do_blocks( self::BLOCK );
		$this->assertStringContainsString( 'data-leitvia-service="openstreetmap"', $html );
		$this->assertStringContainsString( 'Load content from OpenStreetMap', $html );
		$this->assertStringContainsString( 'https://osmfoundation.org/wiki/Privacy_Policy', $html );
		$this->assertStringNotContainsString( 'tile.openstreetmap.org', self::live( $html ) );
		$this->assertStringNotContainsString( 'wp-block-leitvia-map__canvas', self::live( $html ) );
		$this->assertMatchesRegularExpression( '#<template data-leitvia-consent="openstreetmap"><div class="wp-block-leitvia-map__canvas"[^>]* data-tiles="https://tile.openstreetmap.org/\{z\}/\{x\}/\{y\}.png"#', $html );
	}

	/**
	 * List and links stay and the map comes first.
	 */
	public function test_list_and_links_stay_and_the_map_comes_first(): void {
		$html = self::live( do_blocks( self::BLOCK ) );
		$this->assertStringContainsString( '<strong>Office Berlin</strong><span>Main street 1</span>', $html );
		$this->assertStringContainsString( '<a href="https://www.openstreetmap.org/?mlat=52.52&amp;mlon=13.405#map=12/52.52/13.405">View on OpenStreetMap</a>', $html );
		$this->assertStringContainsString( 'href="https://www.openstreetmap.org/?mlat=52.5&#038;mlon=13.4#map=12/52.5/13.4">Open on OpenStreetMap</a>', $html );
		$this->assertLessThan( strpos( $html, '<ul class="wp-block-leitvia-map__places"' ), strpos( $html, 'class="leitvia-consent"' ) );
		$this->assertGreaterThan( strpos( $html, '<h2' ), strpos( $html, 'class="leitvia-consent"' ) );
	}

	/**
	 * Marker data is plain text.
	 */
	public function test_marker_data_is_plain_text(): void {
		$html = do_blocks( self::BLOCK );
		$this->assertStringContainsString( 'data-markers="[{&quot;title&quot;:&quot;Office Berlin&quot;,&quot;address&quot;:&quot;Main street 1&quot;,&quot;lat&quot;:52.52,&quot;lng&quot;:13.405}]"', $html );
		$this->assertStringContainsString( 'data-view="[52.5,13.4,12]"', $html );
	}

	/**
	 * Tile url setting is validated.
	 */
	public function test_tile_url_setting_is_validated(): void {
		$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', leitvia_studio_setting( 'map_tile_url' ) );
		foreach ( array( 'http://tiles.example.org/{z}/{x}/{y}.png', 'https://tiles.example.org/{z}/{x}.png', 'https://tiles.example.org/{z}/{x}/{y}.png" onload="x', 'javascript:alert(1)//{z}{x}{y}', 'https:// /{z}/{x}/{y}' ) as $bad ) {
			update_option( 'leitvia_studio_settings', array( 'map_tile_url' => $bad ) );
			$this->assertSame( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', leitvia_studio_setting( 'map_tile_url' ), $bad );
		}
		update_option( 'leitvia_studio_settings', array( 'map_tile_url' => 'https://tiles.example.org/{z}/{x}/{y}.png' ) );
		$this->assertSame( 'https://tiles.example.org/{z}/{x}/{y}.png', leitvia_studio_setting( 'map_tile_url' ) );
		// An invalid URL keeps the last valid one.
		update_option( 'leitvia_studio_settings', array( 'map_tile_url' => 'ftp://x/{z}/{x}/{y}' ) );
		$this->assertSame( 'https://tiles.example.org/{z}/{x}/{y}.png', leitvia_studio_setting( 'map_tile_url' ) );

		// Another tile server is named after its host in the placeholder.
		$html = do_blocks( self::BLOCK );
		$this->assertStringContainsString( 'data-leitvia-service="tiles.example.org"', $html );
		$this->assertStringNotContainsString( 'tiles.example.org/{z}', self::live( $html ) );
	}

	/**
	 * Rest refuses an invalid tile url.
	 */
	public function test_rest_refuses_an_invalid_tile_url(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( 'leitvia_studio_settings' => array( 'map_tile_url' => 'http://tiles.example.org/{z}/{x}/{y}.png' ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * Tiles from the site itself need no placeholder.
	 */
	public function test_tiles_from_the_site_itself_need_no_placeholder(): void {
		update_option( 'leitvia_studio_settings', array( 'map_tile_url' => 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/tiles/{z}/{x}/{y}.png' ) );
		$html = do_blocks( self::BLOCK );
		$this->assertStringNotContainsString( 'leitvia-consent', $html );
		$this->assertStringContainsString( '<div class="wp-block-leitvia-map__canvas" role="region" aria-label="Interactive map"', $html );
	}
}
