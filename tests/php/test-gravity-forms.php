<?php
/**
 * Captcha services of other hosts in Gravity Forms.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Consent\Gravity_Forms;
use LeitviaStudio\Gateway\Abilities\Read_Audit;

require_once __DIR__ . '/stubs/gravity-forms.php';

/**
 * The site audit warns when Gravity Forms loads a captcha from another host, and stays silent without Gravity Forms.
 */
class Test_Gravity_Forms extends WP_UnitTestCase {

	/**
	 * No forms in the stand-in.
	 */
	public function set_up(): void {
		parent::set_up();
		GFAPI::$forms = array();
	}

	/**
	 * Sets the active plugins of the site.
	 *
	 * @param string[] $plugins Plugin files.
	 */
	private static function active( array $plugins ): void {
		update_option( 'active_plugins', $plugins );
	}

	/**
	 * Checks of the site findings.
	 *
	 * @return list<string>
	 */
	private static function checks(): array {
		return array_column( Read_Audit::site(), 'check' );
	}

	/**
	 * Without Gravity Forms nothing is reported, not even for an active captcha add-on or a form in the stand-in.
	 */
	public function test_silent_without_gravity_forms(): void {
		self::active( array( 'gravityformsrecaptcha/recaptcha.php' ) );
		GFAPI::$forms = array(
			array(
				'title'  => 'Inquiry',
				'fields' => array( array( 'type' => 'captcha' ) ),
			),
		);
		$this->assertSame( array(), Gravity_Forms::captchas() );
		$this->assertNotContains( 'leitvia/form-captcha', self::checks() );
	}

	/**
	 * Gravity Forms with the honeypot only: no finding.
	 */
	public function test_no_finding_without_captcha(): void {
		self::active( array( 'gravityforms/gravityforms.php' ) );
		GFAPI::$forms = array(
			array(
				'title'  => 'Inquiry',
				'fields' => array( array( 'type' => 'text' ), array( 'type' => 'fileupload' ) ),
			),
		);
		$this->assertSame( array(), Gravity_Forms::captchas() );
		$this->assertNotContains( 'leitvia/form-captcha', self::checks() );
	}

	/**
	 * An active captcha add-on and a reCAPTCHA field are named in one warning; the local simple and math captchas are not.
	 * Captcha plugins for many form plugins are named as able to load it.
	 */
	public function test_captcha_add_on_and_field(): void {
		self::active( array( 'gravityforms/gravityforms.php', 'gravityformsrecaptcha/recaptcha.php', 'hcaptcha-for-forms-and-more/hcaptcha.php', 'simple-cloudflare-turnstile/simple-cloudflare-turnstile.php' ) );
		GFAPI::$forms = array(
			array(
				'title'  => 'Inquiry',
				'fields' => array( array( 'type' => 'captcha' ) ),
			),
			array(
				'title'  => 'Application',
				'fields' => array(
					array(
						'type'        => 'captcha',
						'captchaType' => 'math',
					),
				),
			),
		);

		$hcaptcha  = 'hCaptcha for WordPress (if its Gravity Forms integration is on)';
		$turnstile = 'Simple Cloudflare Turnstile (if its Gravity Forms integration is on)';
		$this->assertSame( array( 'Gravity Forms reCAPTCHA Add-On', $hcaptcha, $turnstile, 'reCAPTCHA field in the form "Inquiry"' ), Gravity_Forms::captchas() );
		$finding = wp_list_filter( Read_Audit::site(), array( 'check' => 'leitvia/form-captcha' ) );
		$this->assertCount( 1, $finding );
		$finding = reset( $finding );
		$this->assertSame( 'warning', $finding['severity'] );
		$this->assertStringContainsString( "Gravity Forms reCAPTCHA Add-On, $hcaptcha, $turnstile, reCAPTCHA field in the form \"Inquiry\"", $finding['message'] );
	}
}
