<?php
/**
 * Captcha services of other hosts in Gravity Forms.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Finds what makes Gravity Forms load a captcha from another host before a visitor agrees: an active captcha add-on or
 * a reCAPTCHA field in a form. The simple and the math captcha of Gravity Forms stay on the site and do not count.
 * Gravity Forms counts as active by its plugin file in the active plugins of the site or the network, or by its loaded
 * class GFForms; without it the check finds nothing, whatever else is active. Read_Audit::site() reports the result.
 */
final class Gravity_Forms {

	/**
	 * Plugins that put a captcha service of another host into Gravity Forms: folder => name.
	 */
	public const CAPTCHA_PLUGINS = array(
		'gravityformsrecaptcha'       => 'Gravity Forms reCAPTCHA Add-On',
		'gravityformsturnstile'       => 'Gravity Forms Cloudflare Turnstile Add-On',
		'hcaptcha-for-forms-and-more' => 'hCaptcha for WordPress',
		'simple-cloudflare-turnstile' => 'Simple Cloudflare Turnstile',
	);

	/**
	 * Folders of CAPTCHA_PLUGINS that serve many form plugins and load the captcha in Gravity Forms only with their own
	 * Gravity Forms integration on, which is not read here: the finding says they can load it.
	 */
	private const GENERAL_PLUGINS = array( 'hcaptcha-for-forms-and-more', 'simple-cloudflare-turnstile' );

	/**
	 * Captcha types of the captcha field that stay on the site; every other type is Google reCAPTCHA.
	 */
	private const LOCAL_CAPTCHAS = array( 'simple_captcha', 'math' );

	/**
	 * Names of the captcha add-ons and the forms with a reCAPTCHA field, empty without Gravity Forms.
	 *
	 * @return list<string>
	 */
	public static function captchas(): array {
		$plugins = self::plugins();
		if ( ! in_array( 'gravityforms/gravityforms.php', $plugins, true ) && ! class_exists( 'GFForms', false ) ) {
			return array();
		}
		$found = array();
		foreach ( $plugins as $plugin ) {
			$folder = explode( '/', $plugin )[0];
			if ( ! isset( self::CAPTCHA_PLUGINS[ $folder ] ) ) {
				continue;
			}
			$found[] = in_array( $folder, self::GENERAL_PLUGINS, true )
				/* translators: %s: name of a captcha plugin for many form plugins. */
				? sprintf( __( '%s (if its Gravity Forms integration is on)', 'leitvia-studio' ), self::CAPTCHA_PLUGINS[ $folder ] )
				: self::CAPTCHA_PLUGINS[ $folder ];
		}
		foreach ( class_exists( 'GFAPI', false ) ? \GFAPI::get_forms() : array() as $form ) {
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				// GF_Field implements ArrayAccess; the stand-in of the tests gives arrays.
				$field = is_array( $field ) || $field instanceof \ArrayAccess ? $field : array();
				if ( 'captcha' === ( $field['type'] ?? '' ) && ! in_array( $field['captchaType'] ?? '', self::LOCAL_CAPTCHAS, true ) ) {
					/* translators: %s: title of a form. */
					$found[] = sprintf( __( 'reCAPTCHA field in the form "%s"', 'leitvia-studio' ), sanitize_text_field( (string) ( $form['title'] ?? '' ) ) );
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Plugin files active on the site and in the network.
	 *
	 * @return list<string>
	 */
	private static function plugins(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_filter( $plugins, 'is_string' ) );
	}
}
