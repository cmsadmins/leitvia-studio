<?php
/**
 * Translations of the release zip next to a language pack.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio;

defined( 'ABSPATH' ) || exit;

/**
 * The release zip carries its translations in languages/. A language pack of WordPress.org in WP_LANG_DIR wins, but
 * it lags behind a release: translate.wordpress.org learns new strings only with the release and translates them
 * later. So the zip fills what the pack lacks, string by string, in PHP as in scripts; a string the pack has keeps
 * its translation. PHP strings need only the header Domain Path when there is no pack; scripts are looked up in
 * WP_LANG_DIR alone, so they go through the filters here. The zip holds de_DE only: de_DE_formal, de_AT, de_CH and
 * the other German variants read that file for every string their own pack lacks instead of showing English.
 * ponytail: after switch_to_locale() core loads the pack of the new locale alone; mails in another language may show
 * a new string in English until the pack has it.
 */
final class Translations {

	private const DOMAIN = 'leitvia-studio';

	/**
	 * The file of the zip for a translation file of the domain, the German variants mapped to de_DE.
	 *
	 * @param string $file Translation file, for example leitvia-studio-de_AT-<md5>.json.
	 */
	private static function own( string $file ): string {
		return dirname( LEITVIA_STUDIO_FILE ) . '/languages/' . preg_replace( '/-de_\w+-/', '-de_DE-', basename( $file ), 1 );
	}

	/**
	 * Loads the pack first, so it wins where it has a string, then the .mo of the zip for the rest. Runs on
	 * after_setup_theme, the earliest point core allows loading a domain.
	 */
	public static function load(): void {
		if ( ! str_starts_with( determine_locale(), 'de_' ) ) {
			return;
		}
		get_translations_for_domain( self::DOMAIN );
		load_textdomain( self::DOMAIN, dirname( LEITVIA_STUDIO_FILE ) . '/languages/' . self::DOMAIN . '-de_DE.mo' );
	}

	/**
	 * The script translations of the zip when WP_LANG_DIR has none for the script. Runs on
	 * load_script_translation_file.
	 *
	 * @param string|false $file   Translation file.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function script_file( $file, $handle, $domain ) {
		unset( $handle );
		$own = self::own( (string) $file );
		return self::DOMAIN === $domain && $file && ! is_readable( $file ) && is_readable( $own ) ? $own : $file;
	}

	/**
	 * Script translations of a pack with the strings it lacks from the zip. Runs on load_script_translations.
	 *
	 * @param string|false $translations JSON of the translations.
	 * @param string|false $file         Translation file.
	 * @param string       $handle       Script handle.
	 * @param string       $domain       Text domain.
	 * @return string|false
	 */
	public static function script_translations( $translations, $file, $handle, $domain ) {
		unset( $handle );
		$own = self::own( (string) $file );
		if ( self::DOMAIN !== $domain || ! is_string( $translations ) || ! is_readable( $own ) || realpath( (string) $file ) === realpath( $own ) ) {
			return $translations;
		}
		$pack    = json_decode( $translations, true );
		$bundled = json_decode( (string) file_get_contents( $own ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
		$key     = is_array( $pack['locale_data'] ?? null ) ? array_key_first( $pack['locale_data'] ) : null;
		$extra   = is_array( $bundled['locale_data'] ?? null ) ? reset( $bundled['locale_data'] ) : null;
		if ( null === $key || ! is_array( $pack['locale_data'][ $key ] ) || ! is_array( $extra ) ) {
			return $translations;
		}
		$pack['locale_data'][ $key ] += $extra;
		return (string) wp_json_encode( $pack );
	}
}
