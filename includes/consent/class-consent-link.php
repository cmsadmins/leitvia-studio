<?php
/**
 * Cookie settings: the link of the footer and the dialog it opens.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The footers of the theme Leitvia hold a Cookie settings link (class leitvia-consent-link, filter
 * leitvia_consent_link). Studio answers with the privacy policy page as its address and prints a native dialog at the
 * end of the page, which a short inline script opens instead (the dialog keeps the focus inside, closes with Escape and
 * gives the focus back to the link). Without JavaScript the link leads to the privacy policy page, where the consent
 * settings block belongs.
 *
 * With scripts of plugins that wait for consent (Scripts), the module @leitvia/consent loads on every page anyway, and
 * the dialog holds the consent settings: the categories with Allow and the providers the visitor always allows with
 * Revoke. Without them the dialog says that no script needs consent, names the embeds that ask one by one and links
 * the privacy policy page; then the link loads no file of Studio at all.
 *
 * The link shows on every page, also on a site without any service that needs consent: then its dialog says that the
 * site uses no cookies or services that need consent, so visitors find the answer where they look for it. The filter
 * leitvia_studio_consent_link_always (bool, default true) set to false shows the link only where a service needs
 * consent: scripts that wait for it, or an embed of another provider shown as a consent placeholder on the page
 * (rendered before the footer).
 */
final class Consent_Link {

	/**
	 * Id of the dialog, also the fragment of the link in the footer patterns.
	 */
	public const ID = 'leitvia-consent';

	/**
	 * Whether a link asked for the dialog on this page.
	 *
	 * @var bool
	 */
	private static bool $used = false;

	/**
	 * Whether an embed of another provider waits for consent on this page.
	 *
	 * @var bool
	 */
	private static bool $embed = false;

	/**
	 * Notes that the page shows an embed behind a consent placeholder. Called by Consent::placeholder().
	 */
	public static function embed(): void {
		self::$embed = true;
	}

	/**
	 * Attributes of the link. Filter leitvia_consent_link.
	 *
	 * @param mixed $attributes Attributes from earlier filters.
	 * @return array<string, string>
	 */
	public static function attributes( $attributes = array() ): array {
		$attributes = is_array( $attributes ) ? $attributes : array();
		/**
		 * Filters whether the Cookie settings link shows although no service on the page needs consent.
		 *
		 * @param bool $always Default true: the link shows on every page; false shows it only with scripts that wait or
		 *                     an embed on the page.
		 */
		if ( ! self::has_services() && ! self::$embed && ! apply_filters( 'leitvia_studio_consent_link_always', true ) ) {
			return $attributes;
		}
		self::$used = true;
		$privacy    = get_privacy_policy_url();
		return array(
			'href'                      => '' === $privacy ? '#' . self::ID : $privacy,
			'aria-haspopup'             => 'dialog',
			'data-leitvia-consent-open' => self::ID,
		) + $attributes;
	}

	/**
	 * Whether scripts of plugins wait for consent on this site.
	 */
	public static function has_services(): bool {
		return array() !== Scripts::entries();
	}

	/**
	 * Prints the dialog and its opener when a link asked for them. Runs on wp_footer.
	 */
	public static function dialog(): void {
		if ( ! self::$used ) {
			return;
		}
		// Only pages with the link get the rules of the dialog, in the tokens of the theme.
		wp_register_style( 'leitvia-studio-consent-dialog', false, array(), LEITVIA_STUDIO_VERSION );
		wp_add_inline_style( 'leitvia-studio-consent-dialog', '.leitvia-consent-dialog{box-sizing:border-box;max-width:min(36rem,calc(100% - 2rem));padding:var(--wp--preset--spacing--m,1.5rem);border:1px solid var(--wp--preset--color--stroke,currentColor);background:var(--wp--preset--color--surface,#fff);color:var(--wp--preset--color--ink,#000)}.leitvia-consent-dialog::backdrop{background:rgb(0 0 0/50%)}.leitvia-consent-dialog>h2{margin-top:0;font-size:var(--wp--preset--font-size--heading-s,1.25rem)}' );
		wp_print_styles( 'leitvia-studio-consent-dialog' );
		printf(
			'<dialog id="%1$s" class="leitvia-consent-dialog" aria-labelledby="%1$s-title"><h2 id="%1$s-title">%2$s</h2>%3$s<form method="dialog"><button type="submit" class="wp-element-button">%4$s</button></form></dialog>',
			esc_attr( self::ID ),
			esc_html__( 'Cookie settings', 'leitvia-studio' ),
			self::has_services() ? self::settings() : self::notice(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in settings() and notice().
			esc_html__( 'Close', 'leitvia-studio' )
		);
		// The link names its dialog; a browser without showModal follows the link.
		wp_print_inline_script_tag( "document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('[data-leitvia-consent-open]'),d=a&&document.getElementById(a.getAttribute('data-leitvia-consent-open'));if(d&&d.showModal){e.preventDefault();d.showModal();}});" );
	}

	/**
	 * The dialog without scripts that wait for consent.
	 */
	public static function notice(): string {
		$privacy = get_privacy_policy_url();
		return '<p>' . esc_html__( 'This site uses no cookies or services that need your consent. Content from other providers, such as a video or a map, loads only after you allow it where it is shown.', 'leitvia-studio' ) . '</p>'
			. ( '' === $privacy ? '' : '<p>' . sprintf(
				/* translators: %s: link to the privacy policy page. */
				esc_html__( 'You revoke a provider you always allow in the %s.', 'leitvia-studio' ),
				'<a href="' . esc_url( $privacy ) . '">' . esc_html__( 'privacy policy', 'leitvia-studio' ) . '</a>'
			) . '</p>' );
	}

	/**
	 * The consent settings block in the dialog: its saved markup (src/blocks/consent-settings/save.js), so its filter
	 * translates the texts and adds the categories of scripts, and the Interactivity API renders the directives.
	 */
	public static function settings(): string {
		$markup = '<div class="wp-block-leitvia-consent-settings" data-wp-interactive="leitvia/consent" data-wp-init="callbacks.list" data-wp-context="' . esc_attr( (string) wp_json_encode( array( 'revoked' => '%s is no longer allowed.' ) ) ) . '">'
			. '<p class="wp-block-leitvia-consent-settings__text">' . esc_html__( 'Choose which scripts of other providers may load on this site. Providers you always allow:', 'leitvia-studio' ) . '</p>'
			. '<div class="wp-block-leitvia-consent-settings__state">'
			. '<p data-wp-bind--hidden="!state.noScript">Without JavaScript, this site stores no consent: content from other providers stays a link.</p>'
			. '<div hidden data-wp-bind--hidden="state.noScript">'
			. '<p data-wp-bind--hidden="state.hasList">You have not allowed any provider on this site in this browser.</p>'
			. '<ul class="wp-block-leitvia-consent-settings__list" hidden data-wp-bind--hidden="!state.hasList"><template data-wp-each="state.list" data-wp-each-key="context.item.key"><li><span data-wp-text="context.item.label"></span> <button type="button" class="wp-element-button" data-wp-on--click="actions.revoke">Revoke <span class="screen-reader-text" data-wp-text="context.item.label"></span></button></li></template></ul>'
			. '<p role="status" data-wp-text="state.status"></p>'
			. '</div></div></div>';
		return render_block(
			array(
				'blockName'    => 'leitvia/consent-settings',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $markup,
				'innerContent' => array( $markup ),
			)
		);
	}
}
