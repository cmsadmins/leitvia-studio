<?php
/**
 * When registered patterns were added and last updated.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Reads patterns/dates.json of the active theme, its parent and Studio (written from git by scripts/pattern-dates.mjs)
 * and passes them through the filter leitvia_studio_pattern_dates, so add-ons add the dates of their patterns.
 */
final class Pattern_Dates {

	/**
	 * Dates by pattern slug, for the current request.
	 *
	 * @var array<string, array{added: string, updated: string}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Dates of every pattern that has some: slug => { added, updated } as YYYY-MM-DD, '' when unknown.
	 *
	 * @return array<string, array{added: string, updated: string}>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$dates = array();
			// The child theme wins over its parent for a slug both have.
			foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory(), dirname( LEITVIA_STUDIO_FILE ) ) ) as $dir ) {
				$dates = array_merge( $dates, self::read( $dir . '/patterns/dates.json' ) );
			}
			/**
			 * Filters the dates of registered patterns, which the Lab shows as "Updated" and sorts by.
			 *
			 * @param mixed $dates Array of { added, updated } as YYYY-MM-DD by pattern slug.
			 */
			$filtered    = apply_filters( 'leitvia_studio_pattern_dates', $dates );
			self::$cache = array();
			foreach ( is_array( $filtered ) ? $filtered : array() as $slug => $entry ) {
				if ( is_string( $slug ) && is_array( $entry ) ) {
					self::$cache[ $slug ] = array(
						'added'   => self::date( $entry['added'] ?? '' ),
						'updated' => self::date( $entry['updated'] ?? '' ),
					);
				}
			}
		}
		return self::$cache;
	}

	/**
	 * Dates of one pattern, '' each when unknown.
	 *
	 * @param string $slug Pattern slug.
	 * @return array{added: string, updated: string}
	 */
	public static function get( string $slug ): array {
		return self::all()[ $slug ] ?? array(
			'added'   => '',
			'updated' => '',
		);
	}

	/**
	 * Entries of one dates.json, nothing when it is missing or broken.
	 *
	 * @param string $file Path.
	 * @return array<string, mixed>
	 */
	public static function read( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of a theme or plugin.
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Empties the cache; for tests.
	 */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * A YYYY-MM-DD date, '' for anything else.
	 *
	 * @param mixed $value Value.
	 */
	private static function date( $value ): string {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ? $value : '';
	}
}
