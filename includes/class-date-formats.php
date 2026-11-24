<?php
/**
 * Date and time format that match the language of the site.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio;

defined( 'ABSPATH' ) || exit;

/**
 * A site installed in English keeps "F j, Y" and "g:i a" after a switch to German, so posts show "November 19, 2026".
 * Once per site language (the option WPLANG, not the locale of a request, which Polylang changes) Studio sets the
 * formats of that language: on activation, after a change of the site language, and on sites that had the wrong ones
 * before. It only replaces a format nobody chose: the English default of WordPress or the value Studio set itself the
 * last time. A format a person or an agent set stays.
 */
final class Date_Formats {

	/**
	 * Option with the site language handled last and the formats Studio wrote for it.
	 */
	public const OPTION = 'leitvia_studio_date_locale';

	/**
	 * Defaults of an English installation of WordPress, by key.
	 */
	private const ENGLISH = array(
		'date' => 'F j, Y',
		'time' => 'g:i a',
	);

	/**
	 * Formats by language or locale where the translation of WordPress is not what Leitvia wants or may be missing.
	 */
	private const FORMATS = array(
		'de'    => array(
			'date' => 'j. F Y',
			'time' => 'H:i',
		),
		'en_US' => self::ENGLISH,
		'en_GB' => array(
			'date' => 'j F Y',
			'time' => 'H:i',
		),
	);

	/**
	 * Applies the formats when the site language changed since the last request. Runs on init.
	 */
	public static function maybe_apply(): void {
		$locale = (string) get_option( 'WPLANG' );
		$locale = '' === $locale ? 'en_US' : $locale;
		$done   = get_option( self::OPTION );
		$done   = is_array( $done ) ? $done : array();
		if ( ( $done['locale'] ?? null ) === $locale ) {
			return;
		}
		$formats = self::formats( $locale );
		$written = array( 'locale' => $locale );
		foreach ( array(
			'date' => 'date_format',
			'time' => 'time_format',
		) as $key => $option ) {
			$current = (string) get_option( $option );
			$chosen  = self::ENGLISH[ $key ] !== $current && ( $done[ $key ] ?? null ) !== $current;
			if ( null === $formats || $chosen ) {
				continue;
			}
			update_option( $option, $formats[ $key ] );
			$written[ $key ] = $formats[ $key ];
		}
		update_option( self::OPTION, $written, true );
	}

	/**
	 * Date and time format of a locale: the table FORMATS by locale or language, else the translation of the English
	 * defaults in the language files of WordPress; null when neither knows the locale.
	 *
	 * @param string $locale WordPress locale, such as de_DE.
	 * @return array{date: string, time: string}|null
	 */
	public static function formats( string $locale ): ?array {
		$language = explode( '_', $locale )[0];
		$formats  = self::FORMATS[ $locale ] ?? self::FORMATS[ $language ] ?? null;
		// switch_to_locale() refuses the locale already active, as the site locale is on init.
		$switched = null === $formats && get_locale() !== $locale && switch_to_locale( $locale );
		if ( null === $formats && ( $switched || get_locale() === $locale ) ) {
			// phpcs:disable WordPress.WP.I18n.MissingArgDomain -- The formats of WordPress itself, in its default text domain.
			$date = __( 'F j, Y' );
			$time = __( 'g:i a' );
			// phpcs:enable
			if ( $switched ) {
				restore_previous_locale();
			}
			$formats = self::ENGLISH !== array(
				'date' => $date,
				'time' => $time,
			) ? array(
				'date' => $date,
				'time' => $time,
			) : null;
		}
		/**
		 * Filters the date and time format Leitvia Studio sets for a site language.
		 *
		 * @param array<mixed>|null $formats Formats with the keys date and time, null to leave the site as it is.
		 * @param string            $locale  Site locale.
		 */
		$formats = apply_filters( 'leitvia_studio_date_formats', $formats, $locale );
		return is_array( $formats ) && is_string( $formats['date'] ?? null ) && is_string( $formats['time'] ?? null ) && '' !== $formats['date'] && '' !== $formats['time']
			? array(
				'date' => $formats['date'],
				'time' => $formats['time'],
			) : null;
	}
}
