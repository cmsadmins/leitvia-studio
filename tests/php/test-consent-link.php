<?php
/**
 * Cookie settings link of the footer and its dialog.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Consent\Consent_Link;

/**
 * The theme asks the filter leitvia_consent_link for the link of the footer; Studio answers with the privacy policy
 * page and prints the dialog, which says that no service needs consent while no script waits for one.
 */
class Test_Consent_Link extends WP_UnitTestCase {

	/**
	 * Theme Leitvia with its filter of the link.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\consent_link' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		add_filter( 'render_block_core/navigation-link', 'Leitvia\consent_link', 10, 2 );
	}

	/**
	 * No privacy page, no filter of scripts, no link asked for.
	 */
	public function tear_down(): void {
		delete_option( 'wp_page_for_privacy_policy' );
		( new ReflectionProperty( Consent_Link::class, 'used' ) )->setValue( null, false );
		( new ReflectionProperty( Consent_Link::class, 'embed' ) )->setValue( null, false );
		parent::tear_down();
	}

	/**
	 * Output of the dialog.
	 */
	private static function dialog(): string {
		ob_start();
		Consent_Link::dialog();
		return (string) ob_get_clean();
	}

	/**
	 * The Cookie settings link of a footer, rendered.
	 */
	private static function link(): string {
		return render_block(
			array(
				'blockName'    => 'core/navigation-link',
				'attrs'        => array(
					'label'     => 'Cookie settings',
					'url'       => '#leitvia-consent',
					'kind'      => 'custom',
					'className' => 'leitvia-consent-link',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * The link gets the privacy page and the dialog attributes, the dialog stands at the end of the page.
	 */
	public function test_link_and_dialog(): void {
		remove_all_filters( 'leitvia_studio_consent_link_always' );
		remove_all_filters( 'leitvia_studio_consent_scripts' );
		$this->assertSame( '', self::dialog(), 'no link, no dialog' );
		$html = self::link();
		$this->assertStringContainsString( 'href="#leitvia-consent"', $html, 'without a privacy page the link opens the dialog only' );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
		$this->assertStringContainsString( 'data-leitvia-consent-open="leitvia-consent"', $html );
		$privacy = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Privacy',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$this->assertStringContainsString( 'href="' . get_permalink( $privacy ) . '"', self::link() );
		$dialog = self::dialog();
		$this->assertStringContainsString( '<dialog id="leitvia-consent" class="leitvia-consent-dialog" aria-labelledby="leitvia-consent-title">', $dialog );
		$this->assertStringContainsString( 'This site uses no cookies or services that need your consent.', $dialog );
		$this->assertStringContainsString( 'href="' . get_permalink( $privacy ) . '"', $dialog );
		$this->assertStringContainsString( '<form method="dialog">', $dialog );
		$this->assertStringContainsString( 'showModal', $dialog );
		$this->assertStringNotContainsString( 'data-wp-interactive', $dialog, 'without scripts that wait the dialog needs no module' );
	}

	/**
	 * With scripts that wait for consent the dialog holds the consent settings with their categories.
	 */
	public function test_dialog_with_scripts(): void {
		add_filter(
			'leitvia_studio_consent_scripts',
			static fn(): array => array(
				array(
					'host'     => 'widgets.example.com',
					'category' => 'marketing',
				),
			)
		);
		self::link();
		$dialog = self::dialog();
		$this->assertStringContainsString( 'wp-block-leitvia-consent-settings', $dialog );
		$this->assertStringContainsString( 'data-wp-interactive="leitvia/consent"', $dialog );
		$this->assertStringContainsString( 'Marketing', $dialog );
		$this->assertStringNotContainsString( 'This site uses no services', $dialog );
	}

	/**
	 * With the filter set to false and without scripts that wait or an embed on the page Studio gives no address, the
	 * theme leaves the link out and no dialog follows. An embed placeholder rendered before the footer brings it back.
	 */
	public function test_link_only_where_a_service_needs_consent(): void {
		add_filter( 'leitvia_studio_consent_link_always', '__return_false' );
		remove_all_filters( 'leitvia_studio_consent_scripts' );
		$this->assertSame( '', self::link(), 'no service, no link' );
		$this->assertSame( '', self::dialog(), 'no link, no dialog' );
		\LeitviaStudio\Consent\Consent::placeholder( 'youtube', '<iframe src="https://www.youtube-nocookie.com/embed/x"></iframe>', 'https://www.youtube.com/watch?v=x' );
		$this->assertStringContainsString( 'data-leitvia-consent-open="leitvia-consent"', self::link(), 'an embed on the page asks for the link' );
		$this->assertStringContainsString( '<dialog id="leitvia-consent"', self::dialog() );
	}

	/**
	 * Without an answer to the filter the theme leaves the link out.
	 */
	public function test_theme_drops_the_link_without_an_answer(): void {
		remove_all_filters( 'leitvia_consent_link' );
		$this->assertSame( '', self::link() );
	}
}
