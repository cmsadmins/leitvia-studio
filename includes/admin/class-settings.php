<?php
/**
 * The Studio settings option.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Admin;

use LeitviaStudio\Consent\Consent;
use LeitviaStudio\Organization\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the option leitvia_studio_settings; the Settings screen reads and saves it through /wp/v2/settings.
 */
final class Settings {

	public const OPTION = 'leitvia_studio_settings';

	/**
	 * Every setting with its default.
	 */
	public const DEFAULTS = array(
		'hide_product_notes'            => false,
		'delete_data_on_uninstall'      => false,
		'guard_site_editor_contrast'    => true,
		'allow_gravatar'                => false,
		'news_schema'                   => true,
		'consent_embeds'                => true,
		'emoji_script'                  => false,
		'map_tile_url'                  => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		'news_categories'               => array(),
		'news_sitemap'                  => true,
		'title_site_name'               => true,
		'editorial_page'                => 0,
		'motion'                        => 'subtle',
		'organization_street'           => '',
		'organization_postal_code'      => '',
		'organization_city'             => '',
		'organization_country'          => '',
		'organization_phone'            => '',
		'organization_email'            => '',
		'consent_providers'             => array(),
		'organization_type'             => 'Organization',
		'organization_founding_date'    => '',
		'organization_founder'          => '',
		'organization_copyright_since'  => '',
		'organization_copyright_holder' => '',
		'organization_area_served'      => array(),
		'organization_opening_hours'    => array(),
		'organization_same_as'          => array(),
		'organization_rating_value'     => '',
		'organization_rating_count'     => 0,
		'organization_rating_source'    => '',
		'organization_rating_confirmed' => false,
		'ai_crawlers'                   => true,
		'llms_txt'                      => true,
		'llms_summary'                  => '',
		'llms_pages'                    => array(),
		'redirects'                     => array(),
	);

	/**
	 * A rating value from 1 to 5 with at most one decimal (4.8), or empty, as JSON Schema pattern (no delimiters).
	 */
	public const RATING = '^([1-4](\.\d)?|5(\.0)?)?$';

	/**
	 * Most characters of the summary of llms.txt.
	 */
	public const SUMMARY_MAX = 500;

	/**
	 * A tile URL: https, with the placeholders {z}, {x} and {y}, no spaces, quotes or angle brackets.
	 */
	private const TILE_URL_PATTERN = '^https://(?=[^\s]*\{z\})(?=[^\s]*\{x\})(?=[^\s]*\{y\})[^\s"\'<>]+$';

	/**
	 * Settings that hold a list, with the schema of one item. news_categories, a list of term IDs, is not one of them.
	 */
	private const LISTS = array(
		'consent_providers'          => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array( 'type' => 'string' ),
				'label'   => array( 'type' => 'string' ),
				'hosts'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'purpose' => array( 'type' => 'string' ),
				'privacy' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'organization_area_served'   => array( 'type' => 'string' ),
		'organization_opening_hours' => array(
			'type'                 => 'object',
			'properties'           => array(
				'days'   => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
						'enum' => Organization::DAYS,
					),
				),
				'opens'  => array(
					'type'    => 'string',
					'pattern' => Organization::TIME,
				),
				'closes' => array(
					'type'    => 'string',
					'pattern' => Organization::TIME,
				),
			),
			'additionalProperties' => false,
		),
		'organization_same_as'       => array( 'type' => 'string' ),
		'redirects'                  => array(
			'type'                 => 'object',
			'properties'           => array(
				'from'   => array( 'type' => 'string' ),
				'to'     => array( 'type' => 'string' ),
				'status' => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
	);

	/**
	 * Most entries of a list of texts or addresses.
	 */
	private const MAX_ENTRIES = 50;

	/**
	 * Registers the option for the settings API and REST. Runs on init.
	 */
	public static function register(): void {
		register_setting(
			'leitvia_studio',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Leitvia Studio settings.', 'leitvia-studio' ),
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						// array_map() with two arrays drops the keys, array_combine() puts them back.
						'properties'           => array_combine(
							array_keys( self::DEFAULTS ),
							array_map(
								static fn( $fallback, string $key ): array => match ( true ) {
									is_bool( $fallback )  => array(
										'type'    => 'boolean',
										'default' => $fallback,
									),
									is_int( $fallback )   => array(
										'type'    => 'integer',
										'default' => $fallback,
										'minimum' => 0,
									),
									isset( self::LISTS[ $key ] ) => array(
										'type'    => 'array',
										'default' => $fallback,
										'items'   => self::LISTS[ $key ],
									),
									'organization_type' === $key => array(
										'type'    => 'string',
										'default' => $fallback,
										'enum'    => Organization::TYPES,
									),
									'organization_founding_date' === $key => array(
										'type'    => 'string',
										'default' => $fallback,
										'pattern' => Organization::DATE,
									),
									'organization_copyright_since' === $key => array(
										'type'    => 'string',
										'default' => $fallback,
										'pattern' => Organization::YEAR,
									),
									'organization_rating_value' === $key => array(
										'type'    => 'string',
										'default' => $fallback,
										'pattern' => self::RATING,
									),
									'llms_summary' === $key => array(
										'type'      => 'string',
										'default'   => $fallback,
										'maxLength' => self::SUMMARY_MAX,
									),
									is_array( $fallback ) => array(
										'type'    => 'array',
										'default' => $fallback,
										'items'   => array(
											'type'    => 'integer',
											'minimum' => 1,
										),
									),
									'motion' === $key     => array(
										'type'    => 'string',
										'default' => $fallback,
										'enum'    => \LeitviaStudio\Blocks\Animation::SITE,
									),
									str_starts_with( $key, 'organization_' ) => array(
										'type'    => 'string',
										'default' => $fallback,
									),
									default               => array(
										'type'    => 'string',
										'default' => $fallback,
										'pattern' => self::TILE_URL_PATTERN,
									),
								},
								self::DEFAULTS,
								array_keys( self::DEFAULTS )
							)
						),
						'additionalProperties' => false,
					),
				),
			)
		);
	}

	/**
	 * Keeps the known keys as booleans, page IDs and lists of term IDs, the tile URL as a valid tile URL, motion as off,
	 * subtle or bold (else the saved value or its default), the organization details as plain text, the lists of consent
	 * providers, area served, opening hours and profiles as their valid entries (Consent::sanitize_providers(),
	 * Organization::hours()), the kind of business as one of Organization::TYPES and the founding date as YYYY, YYYY-MM
	 * or a valid YYYY-MM-DD, and drops the rest. Missing keys and an invalid tile URL keep their saved value, so a partial
	 * update through /wp/v2/settings changes only the keys it sends; without a saved value they get their default.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = array();
		foreach ( self::DEFAULTS as $key => $fallback ) {
			$given         = array_key_exists( $key, $value ) ? $value[ $key ] : self::get( $key );
			$clean[ $key ] = match ( true ) {
				is_bool( $fallback )  => array_key_exists( $key, $value ) ? filter_var( $given, FILTER_VALIDATE_BOOLEAN ) : (bool) $given,
				is_int( $fallback )   => absint( $given ),
				'consent_providers' === $key => Consent::sanitize_providers( $given ),
				'organization_opening_hours' === $key => Organization::hours( $given ),
				'organization_area_served' === $key => self::texts( $given ),
				'organization_same_as' === $key => self::https_urls( $given ),
				'organization_type' === $key => is_string( $given ) && in_array( $given, Organization::TYPES, true ) ? $given : self::DEFAULTS['organization_type'],
				'organization_founding_date' === $key => self::founding_date( $given ),
				'organization_copyright_since' === $key => is_string( $given ) && preg_match( '#' . Organization::YEAR . '#', $given ) ? $given : '',
				'organization_rating_value' === $key => self::rating_value( $given ),
				'organization_rating_source' === $key => self::https_url( $given ),
				'llms_summary' === $key => mb_substr( sanitize_textarea_field( (string) $given ), 0, self::SUMMARY_MAX ),
				'redirects' === $key  => \LeitviaStudio\Seo\Redirects::sanitize( $given ),
				is_array( $fallback ) => array_values( array_unique( array_filter( array_map( 'absint', (array) $given ) ) ) ),
				'motion' === $key     => in_array( $given, \LeitviaStudio\Blocks\Animation::SITE, true ) ? $given : self::motion(),
				'organization_email' === $key => sanitize_email( (string) $given ),
				str_starts_with( $key, 'organization_' ) => sanitize_text_field( (string) $given ),
				default               => self::tile_url( $given ),
			};
		}
		return $clean;
	}

	/**
	 * A founding date as YYYY, YYYY-MM or a day of the calendar as YYYY-MM-DD, else ''.
	 *
	 * @param mixed $date Submitted date.
	 */
	private static function founding_date( $date ): string {
		if ( ! is_string( $date ) || ! preg_match( '#' . Organization::DATE . '#', $date ) ) {
			return '';
		}
		// The pattern keeps the month between 1 and 12 and the day up to 31; checkdate() drops 2025-02-30.
		return 10 !== strlen( $date ) || checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ? $date : '';
	}

	/**
	 * A rating value from 1 to 5 as RATING allows it, else ''.
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function rating_value( $value ): string {
		return is_string( $value ) && preg_match( '#' . self::RATING . '#', $value ) ? $value : '';
	}

	/**
	 * One address that starts with https://, else ''.
	 *
	 * @param mixed $value Submitted address.
	 */
	private static function https_url( $value ): string {
		$url = is_string( $value ) && str_starts_with( $value, 'https://' ) ? esc_url_raw( $value, array( 'https' ) ) : '';
		return (string) $url;
	}

	/**
	 * The saved motion setting when it is valid, else its default.
	 */
	private static function motion(): string {
		$saved = self::get( 'motion' );
		return in_array( $saved, \LeitviaStudio\Blocks\Animation::SITE, true ) ? $saved : self::DEFAULTS['motion'];
	}

	/**
	 * Plain texts of a list without empty entries and repeats, at most MAX_ENTRIES.
	 *
	 * @param mixed $value Submitted list.
	 * @return list<string>
	 */
	private static function texts( $value ): array {
		$texts = array_map( static fn( $text ): string => is_scalar( $text ) ? sanitize_text_field( (string) $text ) : '', is_array( $value ) ? $value : array() );
		return array_slice( array_values( array_unique( array_filter( $texts, static fn( string $text ): bool => '' !== $text ) ) ), 0, self::MAX_ENTRIES );
	}

	/**
	 * Addresses of a list that start with https://, without repeats, at most MAX_ENTRIES; anything else is dropped.
	 *
	 * @param mixed $value Submitted list.
	 * @return list<string>
	 */
	private static function https_urls( $value ): array {
		$urls = array_map( static fn( $url ): string => is_string( $url ) && str_starts_with( $url, 'https://' ) ? esc_url_raw( $url, array( 'https' ) ) : '', is_array( $value ) ? $value : array() );
		return array_slice( array_values( array_unique( array_filter( $urls, static fn( string $url ): bool => '' !== $url ) ) ), 0, self::MAX_ENTRIES );
	}

	/**
	 * A valid map tile URL (https, with {z}, {x} and {y}), else the saved one, else the default.
	 *
	 * @param mixed $url Candidate URL.
	 */
	public static function tile_url( $url ): string {
		$saved = get_option( self::OPTION );
		foreach ( array( $url, is_array( $saved ) ? ( $saved['map_tile_url'] ?? null ) : null ) as $candidate ) {
			if ( is_string( $candidate ) && preg_match( '#' . self::TILE_URL_PATTERN . '#', $candidate ) && filter_var( str_replace( array( '{z}', '{x}', '{y}', '{s}' ), '0', $candidate ), FILTER_VALIDATE_URL ) ) {
				return $candidate;
			}
		}
		return self::DEFAULTS['map_tile_url'];
	}

	/**
	 * Removes the emoji loader of core unless the setting emoji_script is on. Core's detection script makes browsers
	 * without native emoji load images from s.w.org, a request to another host without consent; feeds and emails get
	 * the same images through wp_staticize_emoji(). Runs on init and admin_init.
	 */
	public static function remove_emoji_loader(): void {
		if ( self::get( 'emoji_script' ) ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
		// Kept by core for backwards compatibility and unhooked only by wp_enqueue_emoji_styles(); left alone it would
		// print a deprecation notice.
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	/**
	 * One setting, or its default when it is not saved.
	 *
	 * @param string $key Setting name, a key of DEFAULTS.
	 */
	public static function get( string $key ): mixed {
		$value = get_option( self::OPTION );
		return is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : ( self::DEFAULTS[ $key ] ?? null );
	}
}
