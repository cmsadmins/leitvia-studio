<?php
/**
 * Form of address of the site: informal or formal.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio;

use LeitviaStudio\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Languages such as German address a reader as du or as Sie. The default texts that visitors read (forms, the dialog
 * of the privacy settings) speak informally; the setting formal_address (address of leitvia/set-organization) or a
 * formal locale of WordPress such as de_DE_formal switches them to the formal form.
 *
 * A text domain registers its formal texts with register(): a callable that returns msgid => text, each text built with
 * _x( msgid, Address::CONTEXT, domain ) (a plural with _nx_noop()), so make-pot finds both forms and every catalog keeps
 * the formal one under the context. English has one form, so both are the same there. The call sites stay as they are:
 * the filters gettext_<domain> and ngettext_<domain> swap a registered text for its formal form.
 */
final class Address {

	/**
	 * Key of the setting in Settings::OPTION.
	 */
	public const SETTING = 'formal_address';

	/**
	 * Context of the formal texts in the catalogs.
	 */
	public const CONTEXT = 'formal address';

	/**
	 * Callables with the formal texts by text domain.
	 *
	 * @var array<string, callable>
	 */
	private static array $sources = array();

	/**
	 * Formal texts by text domain and locale.
	 *
	 * @var array<string, array<string, string|array<mixed>>>
	 */
	private static array $texts = array();

	/**
	 * Whether the site speaks formally: the setting, or a formal locale such as de_DE_formal.
	 */
	public static function formal(): bool {
		return (bool) Settings::get( self::SETTING ) || str_ends_with( determine_locale(), '_formal' );
	}

	/**
	 * Registers the formal texts of a text domain.
	 *
	 * @param string   $domain  Text domain.
	 * @param callable $strings Returns msgid => formal text, or a nooped plural (_nx_noop()) for a plural by its singular.
	 */
	public static function register( string $domain, callable $strings ): void {
		if ( ! self::$sources ) {
			// The formal texts are translations: a catalog loaded, unloaded or a switched locale makes them new.
			foreach ( array( 'load_textdomain', 'unload_textdomain', 'change_locale' ) as $hook ) {
				add_action( $hook, array( self::class, 'forget' ) );
			}
		}
		self::$sources[ $domain ] = $strings;
		add_filter( 'gettext_' . $domain, static fn( $translation, $text ) => self::pick( $domain, (string) $text, $translation ), 10, 2 );
		add_filter( 'ngettext_' . $domain, static fn( $translation, $single, $plural, $number ) => self::pick( $domain, (string) $single, $translation, (int) $number ), 10, 4 );
	}

	/**
	 * Forgets the formal texts kept so far. Runs on load_textdomain, unload_textdomain and change_locale.
	 */
	public static function forget(): void {
		self::$texts = array();
	}

	/**
	 * The formal form of a registered text when the site speaks formally, else the translation as it is.
	 *
	 * @param string   $domain      Text domain.
	 * @param string   $text        Original text, the singular of a plural.
	 * @param mixed    $translation Translation so far.
	 * @param int|null $number      Number of a plural, null for a single text.
	 * @return mixed
	 */
	private static function pick( string $domain, string $text, $translation, ?int $number = null ) {
		// Texts for visitors come after the theme setup; before it the catalogs of plugins are not loaded yet.
		if ( ! did_action( 'after_setup_theme' ) ) {
			return $translation;
		}
		$key                   = $domain . '|' . determine_locale();
		self::$texts[ $key ] ??= (array) ( self::$sources[ $domain ] )();
		$texts                 = self::$texts[ $key ];
		if ( ! isset( $texts[ $text ] ) || ! self::formal() ) {
			return $translation;
		}
		$formal = $texts[ $text ];
		if ( is_array( $formal ) ) {
			return null === $number ? $translation : translate_nooped_plural( $formal, $number, $domain );
		}
		// A formal text the catalog lacks keeps the informal translation rather than falling back to English.
		return null === $number && $formal !== $text ? $formal : $translation;
	}

	/**
	 * The formal texts of Leitvia Studio: the dialog of the privacy settings.
	 *
	 * @return array<string, string>
	 */
	public static function studio(): array {
		return array(
			'This site uses no cookies or services that need your consent. Content from other providers, such as a video or a map, loads only after you allow it where it is shown.' => _x( 'This site uses no cookies or services that need your consent. Content from other providers, such as a video or a map, loads only after you allow it where it is shown.', 'formal address', 'leitvia-studio' ),
			/* translators: %s: link to the privacy policy page. */
			'You revoke a provider you always allow in the %s.' => _x( 'You revoke a provider you always allow in the %s.', 'formal address', 'leitvia-studio' ),
			'Choose which scripts of other providers may load on this site. Providers you always allow:' => _x( 'Choose which scripts of other providers may load on this site. Providers you always allow:', 'formal address', 'leitvia-studio' ),
		);
	}
}
