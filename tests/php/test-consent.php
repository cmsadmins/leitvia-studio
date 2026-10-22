<?php
/**
 * Consent placeholder for third-party embeds.
 *
 * @package LeitviaStudio
 */

/**
 * Embeds from other hosts render a placeholder, the original waits in a <template>.
 */
class Test_Consent extends WP_UnitTestCase {

	/**
	 * The oEmbed HTML per URL, as the providers answer, so no test asks the network.
	 */
	private const OEMBED = array(
		'https://www.youtube.com/watch?v=abcdefghijk' => '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/abcdefghijk?feature=oembed" allowfullscreen></iframe>',
		'https://vimeo.com/123456'                    => '<iframe title="Film" src="https://player.vimeo.com/video/123456" width="500" height="281" style="background:url(https://i.vimeocdn.com/x.jpg)"></iframe>',
		'https://x.com/someone/status/123'            => '<blockquote class="twitter-tweet"><p>Post</p><img src="https://pbs.twimg.com/a.jpg" srcset="https://pbs.twimg.com/a.jpg 2x"><video poster="https://pbs.twimg.com/p.jpg"></video></blockquote><script async src="https://platform.twitter.com/widgets.js"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Sample oEmbed HTML of X.
	);

	/**
	 * Answers oEmbed from OEMBED and enables the placeholder.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'leitvia_studio_settings' );
		add_filter( 'pre_oembed_result', static fn( $result, $url ) => self::OEMBED[ $url ] ?? $result, 10, 2 );
		add_filter( 'pre_oembed_result', array( $this, 'own_site' ), 10, 2 );
	}

	/**
	 * The oEmbed HTML for an embed of the own site.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $url    Embedded URL.
	 * @return mixed
	 */
	public function own_site( $result, $url ) {
		return str_starts_with( $url, home_url() ) ? '<iframe class="wp-embedded-content" src="' . esc_url( $url ) . 'embed/"></iframe>' : $result;
	}

	/**
	 * A saved core/embed block for `$url`, rendered like a post body.
	 *
	 * @param string $url Embedded URL.
	 */
	private static function render( string $url ): string {
		$markup = '<!-- wp:embed {"url":"' . esc_url_raw( $url ) . '","type":"video","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->'
			. "\n" . '<figure class="wp-block-embed is-type-video wp-block-embed wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n" . $url . "\n" . '</div><figcaption class="wp-element-caption">Caption</figcaption></figure>' . "\n<!-- /wp:embed -->";
		return apply_filters( 'the_content', $markup );
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
	 * YouTube, Vimeo and X load nothing from their hosts: no iframe, no script, no foreign URL in src, srcset,
	 * poster or style outside the template.
	 */
	public function test_embeds_load_nothing_from_other_hosts(): void {
		foreach ( array( 'https://www.youtube.com/watch?v=abcdefghijk', 'https://vimeo.com/123456', 'https://x.com/someone/status/123' ) as $url ) {
			$html  = self::render( $url );
			$outer = self::outside_templates( $html );
			$this->assertStringContainsString( '<template data-leitvia-consent=', $html, $url );
			$this->assertMatchesRegularExpression( '#<template data-leitvia-consent="[a-z]+">\s*<(iframe|blockquote)#', $html, $url );
			$this->assertStringNotContainsString( '<iframe', $outer, $url );
			$this->assertDoesNotMatchRegularExpression( '#<script[^>]+src=#i', $outer, $url );
			$this->assertDoesNotMatchRegularExpression( '#(src|srcset|poster|style)="[^"]*(youtube|vimeo|twimg|twitter|x\.com)#i', $outer, $url );
		}
	}

	/**
	 * The placeholder names provider and purpose, links to the original and to the privacy policy, and hides the
	 * button until JavaScript runs.
	 */
	public function test_placeholder_content(): void {
		$html = self::outside_templates( self::render( 'https://www.youtube.com/watch?v=abcdefghijk' ) );
		$this->assertStringContainsString( 'Content from YouTube', $html );
		$this->assertStringContainsString( 'This content is hosted by YouTube.', $html );
		$this->assertStringContainsString( '<a href="https://www.youtube.com/watch?v=abcdefghijk">Open on YouTube</a>', $html );
		$this->assertStringContainsString( '<a href="https://policies.google.com/privacy">Privacy policy of YouTube</a>', $html );
		$this->assertMatchesRegularExpression( '#<p class="leitvia-consent__actions" hidden[^>]*><button type="button"[^>]*>Load content from YouTube</button>#', $html );
		$this->assertStringContainsString( 'Always allow YouTube on this site', $html );
		$this->assertStringContainsString( 'has-leitvia-consent', $html );
		$this->assertStringContainsString( 'Caption', $html );
		$this->assertContains( '@leitvia/consent', wp_script_modules()->get_queue() );
	}

	/**
	 * An embed of the own site stays as core renders it.
	 */
	public function test_own_site_unchanged(): void {
		$html = self::render( home_url( '/sample-page/' ) );
		$this->assertStringContainsString( '<iframe class="wp-embedded-content"', $html );
		$this->assertStringNotContainsString( 'leitvia-consent', $html );
	}

	/**
	 * With consent_embeds off, core output stays unchanged.
	 */
	public function test_setting_off_keeps_core_output(): void {
		update_option( 'leitvia_studio_settings', array( 'consent_embeds' => false ) );
		$html = self::render( 'https://www.youtube.com/watch?v=abcdefghijk' );
		$this->assertStringContainsString( 'src="https://www.youtube.com/embed/abcdefghijk?feature=oembed"', self::outside_templates( $html ) );
		$this->assertStringNotContainsString( 'leitvia-consent', $html );
	}

	/**
	 * The filter adds a service and changes texts of a known one; a host without an entry gets a placeholder too.
	 */
	public function test_filter_adds_services_and_changes_texts(): void {
		$filter = static function ( array $services ): array {
			$services['example']            = array(
				'label'   => 'Example Media',
				'hosts'   => array( 'example.org' ),
				'privacy' => 'https://example.org/privacy',
			);
			$services['youtube']['label']   = 'YT';
			$services['youtube']['purpose'] = 'Shows a video.';
			return $services;
		};
		add_filter( 'leitvia_studio_consent_services', $filter );
		$this->assertSame( 'example', LeitviaStudio\Consent\Consent::service_for( 'https://media.example.org/v/1' ) );
		$this->assertSame( 'unknown.test', LeitviaStudio\Consent\Consent::service_for( 'https://unknown.test/v/1' ) );
		$html = self::render( 'https://www.youtube.com/watch?v=abcdefghijk' );
		$this->assertStringContainsString( 'Load content from YT', $html );
		$this->assertStringContainsString( 'Shows a video.', $html );

		$html = leitvia_studio_consent_placeholder( 'example', '<iframe src="https://example.org/v/1"></iframe>', 'https://example.org/v/1' );
		$this->assertStringContainsString( 'Open on Example Media', $html );
		$this->assertStringContainsString( 'https://example.org/privacy', $html );
		remove_filter( 'leitvia_studio_consent_services', $filter );
	}

	/**
	 * Labels, URLs and service keys are escaped.
	 */
	public function test_output_is_escaped(): void {
		add_filter(
			'leitvia_studio_consent_services',
			static function ( array $services ): array {
				$services['youtube']['label']   = '<script>alert(1)</script>';
				$services['youtube']['privacy'] = 'javascript:alert(1)';
				return $services;
			}
		);
		$html = leitvia_studio_consent_placeholder( 'you"tube', '', 'javascript:alert(2)' );
		$this->assertStringNotContainsString( 'you"tube', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );

		$html = self::outside_templates( leitvia_studio_consent_placeholder( 'youtube', '<iframe></iframe>', 'https://www.youtube.com/watch?v="><b>' ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '"><b>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	/**
	 * Foreign iframes in classic content, also from autoembed, and in core/html wait behind the placeholder; iframes of
	 * the own site and other markup stay as they are.
	 */
	public function test_iframes_in_classic_content_and_custom_html(): void {
		$own     = '<iframe src="' . esc_url( home_url( '/map/' ) ) . '" title="Map"></iframe>';
		$classic = "<p>Watch this:</p>\n\n<iframe title=\"Shaping\" src=\"https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ\" allowfullscreen></iframe>\n\n{$own}\n\nhttps://vimeo.com/123456\n\n<p>Done</p>";
		$custom  = '<!-- wp:html --><div><iframe src="//player.vimeo.com/video/1"></iframe></div><!-- /wp:html -->';
		foreach ( array(
			'classic'   => $classic,
			'core/html' => $custom,
		) as $case => $markup ) {
			$html  = apply_filters( 'the_content', $markup );
			$outer = self::outside_templates( $html );
			$this->assertStringContainsString( '<template data-leitvia-consent=', $html, $case );
			$this->assertDoesNotMatchRegularExpression( '#src="[^"]*(youtube|vimeo)#', $outer, $case );
		}
		$html = apply_filters( 'the_content', $classic );
		$this->assertStringContainsString( '<template data-leitvia-consent="youtube"><iframe title="Shaping" src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ" allowfullscreen></iframe></template>', $html );
		$this->assertStringContainsString( 'data-leitvia-service="vimeo"', $html );
		$this->assertStringContainsString( $own, self::outside_templates( $html ) );
		$this->assertStringContainsString( '<p>Done</p>', $html );
		// wpautop ran before: the placeholder stands in place of the paragraph and keeps its markup whole.
		$this->assertStringNotContainsString( '<p><div class="leitvia-consent"', $html );
		$this->assertStringContainsString( 'role="status"></p><template data-leitvia-consent="youtube">', $html );
		$this->assertSame( 1, substr_count( apply_filters( 'the_content', '<!-- wp:html --><iframe src="https://www.youtube.com/embed/x"></iframe><!-- /wp:html -->' ), '<template' ), 'wrapped once' );

		update_option( 'leitvia_studio_settings', array( 'consent_embeds' => false ) );
		$this->assertStringNotContainsString( 'leitvia-consent', apply_filters( 'the_content', $classic ) );
	}
}
