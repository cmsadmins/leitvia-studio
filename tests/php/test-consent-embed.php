<?php
/**
 * Block leitvia/consent-embed.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Settings;
use LeitviaStudio\Blocks\Consent_Embed;

/**
 * An iframe of another provider waits in the template of the consent placeholder; nothing outside it names the
 * provider's host in src, srcset or style.
 */
class Test_Consent_Embed extends WP_UnitTestCase {

	/**
	 * Saves the planner as provider.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Settings::OPTION,
			array(
				'consent_providers' => array(
					array(
						'label'   => 'Kitchen Planner',
						'hosts'   => array( 'planner.example.com' ),
						'purpose' => '',
						'privacy' => 'https://planner.example.com/privacy',
					),
				),
			) + Settings::DEFAULTS
		);
	}

	/**
	 * Removes uploaded images.
	 */
	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * The block with these attributes, rendered.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	private static function render( array $attributes ): string {
		return do_blocks( '<!-- wp:leitvia/consent-embed ' . wp_json_encode( $attributes ) . ' /-->' );
	}

	/**
	 * The page without the content of <template> elements.
	 *
	 * @param string $html Rendered HTML.
	 */
	private static function outside_templates( string $html ): string {
		return (string) preg_replace( '#<template\b.*?</template>#s', '', $html );
	}

	/**
	 * The iframe with address, title and lazy loading waits in the template; the placeholder names the provider.
	 */
	public function test_iframe_waits_for_consent(): void {
		$html = self::render(
			array(
				'url'         => 'https://planner.example.com/embed/kitchen?lang=en',
				'title'       => 'Kitchen planner of Example Joinery',
				'aspectRatio' => '4/3',
			)
		);
		$this->assertMatchesRegularExpression( '#<template data-leitvia-consent="kitchen-planner"><iframe src="https://planner\.example\.com/embed/kitchen\?lang=en" title="Kitchen planner of Example Joinery" loading="lazy" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe></template>#', $html );
		$outer = self::outside_templates( $html );
		$this->assertStringNotContainsString( '<iframe', $outer );
		$this->assertDoesNotMatchRegularExpression( '#(src|srcset|style)="[^"]*planner\.example\.com#', $outer );
		$this->assertStringContainsString( 'Load content from Kitchen Planner', $outer );
		$this->assertStringContainsString( 'wp-block-leitvia-consent-embed', $outer );
		$this->assertStringContainsString( 'is-ratio-4-3', $outer );
		$this->assertContains( '@leitvia/consent', wp_script_modules()->get_queue() );
	}

	/**
	 * Without a title the iframe is named after the provider; a host without an entry names the placeholder.
	 */
	public function test_title_and_unknown_host(): void {
		$this->assertStringContainsString( 'title="Content from Kitchen Planner"', self::render( array( 'url' => 'https://planner.example.com/embed' ) ) );
		$html = self::render( array( 'url' => 'https://tool.example.net/widget' ) );
		$this->assertStringContainsString( 'data-leitvia-service="tool.example.net"', $html );
		$this->assertStringContainsString( 'Load content from tool.example.net', $html );
		$this->assertStringContainsString( 'title="Content from tool.example.net"', $html );
	}

	/**
	 * A fixed height stays between 200 and 1600 pixels and replaces the aspect ratio.
	 */
	public function test_fixed_height(): void {
		$html = self::render(
			array(
				'url'         => 'https://planner.example.com/embed',
				'aspectRatio' => 'fixed',
				'height'      => 5000,
			)
		);
		$this->assertStringContainsString( 'has-fixed-height', $html );
		$this->assertStringContainsString( '--leitvia-embed-height:1600px', $html );
		$this->assertStringNotContainsString( 'is-ratio-', $html );
	}

	/**
	 * Only https addresses render; the own site needs no consent and the iframe renders at once.
	 */
	public function test_addresses(): void {
		foreach ( array( '', 'http://planner.example.com/embed', 'javascript:alert(1)', 'https://' ) as $url ) {
			$this->assertSame( '', self::render( array( 'url' => $url ) ), $url );
		}
		// The tests site runs on http; the block takes https only, and the host alone decides what is the own site.
		$address = set_url_scheme( home_url( '/configurator/' ), 'https' );
		$own     = self::render( array( 'url' => $address ) );
		$this->assertStringNotContainsString( 'data-leitvia-service', $own );
		$this->assertStringContainsString( '<iframe src="' . esc_url( $address ) . '"', $own );
	}

	/**
	 * The iframe may not navigate the page and sends no referrer.
	 */
	public function test_sandbox_and_referrer(): void {
		$html = self::render( array( 'url' => 'https://planner.example.com/embed' ) );
		$this->assertMatchesRegularExpression( '#<template[^>]*><iframe[^>]+sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"#', $html );
		$this->assertStringNotContainsString( 'allow-top-navigation', $html );
	}

	/**
	 * Tricks with the address: userinfo before @ names the real host, a look-alike host is no subdomain, case and port
	 * keep the provider, a backslash renders nothing, a host beyond ASCII renders in its ASCII form where intl is there.
	 */
	public function test_tricky_addresses(): void {
		$this->assertStringContainsString( 'data-leitvia-service="evil.example"', self::render( array( 'url' => 'https://planner.example.com@evil.example/' ) ) );
		$this->assertStringContainsString( 'data-leitvia-service="notplanner.example.com"', self::render( array( 'url' => 'https://notplanner.example.com/embed' ) ) );
		$this->assertStringContainsString( 'data-leitvia-service="kitchen-planner"', self::render( array( 'url' => 'https://Planner.Example.com/embed' ) ) );
		$this->assertStringContainsString( 'data-leitvia-service="kitchen-planner"', self::render( array( 'url' => 'https://planner.example.com:8443/embed' ) ) );
		$this->assertSame( '', self::render( array( 'url' => 'https://planner.example.com\\@evil.example/' ) ) );
		$this->assertSame( '', Consent_Embed::url( 'https://planner.example.com\\@evil.example/' ) );
		$this->assertSame( function_exists( 'idn_to_ascii' ) ? 'https://xn--bcher-kva.example/embed' : '', Consent_Embed::url( 'https://bücher.example/embed' ) );
	}

	/**
	 * With consent_embeds off the iframe renders as it is, as embeds do.
	 */
	public function test_setting_off(): void {
		update_option( Settings::OPTION, array( 'consent_embeds' => false ) + (array) get_option( Settings::OPTION ) );
		$html = self::render( array( 'url' => 'https://planner.example.com/embed' ) );
		$this->assertStringNotContainsString( 'data-leitvia-service', $html );
		$this->assertStringContainsString( '<iframe src="https://planner.example.com/embed"', $html );
	}

	/**
	 * The preview image comes from the media library, empty alt text, behind the texts.
	 */
	public function test_preview_from_the_media_library(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$html  = self::outside_templates(
			self::render(
				array(
					'url'       => 'https://planner.example.com/embed',
					'previewId' => $image,
				)
			)
		);
		$this->assertMatchesRegularExpression( '#<div class="leitvia-consent__placeholder has-preview"><img[^>]+class="leitvia-consent__preview"#', $html );
		$this->assertMatchesRegularExpression( '#<img[^>]+alt=""#', $html );
		$this->assertStringContainsString( 'src="' . wp_upload_dir()['baseurl'], $html );
	}

	/**
	 * The title loses tags and is escaped; an address with quotes is no valid URL and renders nothing.
	 */
	public function test_escaped(): void {
		$html = self::render(
			array(
				'url'   => 'https://planner.example.com/embed?a=1&b=2',
				'title' => '"><script>alert(1)</script>Planner',
			)
		);
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'title="&quot;&gt;Planner"', $html );
		$this->assertStringContainsString( 'src="https://planner.example.com/embed?a=1&#038;b=2"', $html );
		$this->assertSame( '', self::render( array( 'url' => 'https://planner.example.com/embed?b="2"' ) ) );
	}

	/**
	 * The editor gets the providers with label and hosts before the script of the block.
	 */
	public function test_editor_data(): void {
		Consent_Embed::editor_data();
		$data = implode( '', (array) wp_scripts()->get_inline_script_data( generate_block_asset_handle( 'leitvia/consent-embed', 'editorScript' ), 'before' ) );
		$this->assertStringContainsString( 'window.leitviaStudioConsentServices = ', $data );
		$this->assertStringContainsString( '"kitchen-planner":{"label":"Kitchen Planner","hosts":["planner.example.com"]}', $data );
		$this->assertStringContainsString( '"youtube":{"label":"YouTube"', $data );
		$this->assertStringContainsString( 'window.leitviaStudioConsentSite = {"host":"' . strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '","idn":' . ( function_exists( 'idn_to_ascii' ) ? 'true' : 'false' ) . '}', $data );
	}
}
