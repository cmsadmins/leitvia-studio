<?php
/**
 * The Studio settings option.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Admin;

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
		'hide_product_notes'         => false,
		'delete_data_on_uninstall'   => false,
		'guard_site_editor_contrast' => true,
		'allow_gravatar'             => false,
		'news_schema'                => true,
		'consent_embeds'             => true,
		'emoji_script'               => false,
		'map_tile_url'               => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
	);

	/**
	 * A tile URL: https, with the placeholders {z}, {x} and {y}, no spaces, quotes or angle brackets.
	 */
	private const TILE_URL_PATTERN = '^https://(?=[^\s]*\{z\})(?=[^\s]*\{x\})(?=[^\s]*\{y\})[^\s"\'<>]+$';

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
						'properties'           => array_map(
							static fn( $fallback ): array => is_bool( $fallback )
								? array(
									'type'    => 'boolean',
									'default' => $fallback,
								)
								: array(
									'type'    => 'string',
									'default' => $fallback,
									'pattern' => self::TILE_URL_PATTERN,
								),
							self::DEFAULTS
						),
						'additionalProperties' => false,
					),
				),
			)
		);
	}

	/**
	 * Keeps the known keys as booleans, the tile URL as a valid tile URL, and drops the rest. Missing keys and an invalid
	 * tile URL keep their saved value, so a partial update through /wp/v2/settings changes only the keys it sends;
	 * without a saved value they get their default.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string, bool|string>
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = array();
		foreach ( self::DEFAULTS as $key => $fallback ) {
			if ( ! is_bool( $fallback ) ) {
				$clean[ $key ] = self::tile_url( $value[ $key ] ?? self::get( $key ) );
				continue;
			}
			$clean[ $key ] = array_key_exists( $key, $value ) ? filter_var( $value[ $key ], FILTER_VALIDATE_BOOLEAN ) : (bool) self::get( $key );
		}
		return $clean;
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
