<?php
/**
 * Writes the active token set into the user global styles of the active theme.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use WP_Error;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Activation, checks and drift detection. Styles live in core, so they stay when Studio is deactivated.
 */
final class Compiler {

	public const ACTIVE_OPTION = 'leitvia_studio_active_set';
	public const HASH_OPTION   = 'leitvia_studio_applied_hash';

	/**
	 * Warns about font families that neither the theme nor its style variations bundle.
	 *
	 * @param array<string, mixed> $doc Token document.
	 * @return list<array<string, mixed>>
	 */
	private static function check_fonts( array $doc ): array {
		$settings = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		if ( ! is_array( $settings ) ) {
			return array();
		}
		$findings = array();
		foreach ( $settings['typography']['fontFamilies']['theme'] ?? array() as $family ) {
			if ( ! isset( $family['fontFace'] ) ) {
				$findings[] = array(
					'code'     => 'font_not_bundled',
					'severity' => 'warning',
					/* translators: %s: font family slug. */
					'message'  => sprintf( __( 'Font family %s is not bundled, falls back to the system font.', 'leitvia-studio' ), $family['slug'] ),
					'data'     => array( 'slug' => $family['slug'] ),
				);
			}
		}
		return $findings;
	}

	/**
	 * Decoded DTCG document of a token set.
	 *
	 * @param int $set_id Token set ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function document( int $set_id ) {
		$post = get_post( $set_id );
		if ( ! $post || Token_Sets::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'leitvia_studio_no_token_set', __( 'Token set not found.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		$doc = json_decode( (string) get_post_meta( $set_id, Token_Sets::META, true ), true );
		if ( ! is_array( $doc ) ) {
			return new WP_Error( 'leitvia_studio_dtcg_invalid_json', __( 'The token set holds no valid document.', 'leitvia-studio' ), array( 'status' => 422 ) );
		}
		return $doc;
	}

	/**
	 * Findings of the bundled font check and of all registry checks with scope token_set.
	 *
	 * @param array<string, mixed> $doc Token document.
	 * @return list<array<string, mixed>>
	 */
	public static function check( array $doc ): array {
		$findings = self::check_fonts( $doc );
		foreach ( leitvia_studio()->registry( 'checks' )->all( array( 'scope' => 'token_set' ) ) as $check ) {
			foreach ( (array) call_user_func( $check['callback'], $doc ) as $finding ) {
				if ( is_array( $finding ) ) {
					$findings[] = $finding + array( 'check' => $check['name'] );
				}
			}
		}
		return $findings;
	}

	/**
	 * Lays the preset lists of compiled settings over user global styles data as origin theme. A style variation stores
	 * plain lists, which core reads as origin custom, and custom presets outrank theme ones in the stylesheet; so the
	 * roles of the settings leave custom and only other presets stay there. Activation, overlays and the Lab preview
	 * share this, so a set takes effect on a site with a style variation as well.
	 *
	 * @param array<string, mixed> $data     User global styles data, preset lists plain or keyed by origin.
	 * @param array<string, mixed> $settings Settings from Dtcg::to_settings().
	 * @return array<string, mixed>
	 */
	public static function lay_over( array $data, array $settings ): array {
		foreach ( Dtcg::GROUPS as list( $section, $list ) ) {
			if ( ! isset( $settings[ $section ][ $list ]['theme'] ) ) {
				continue;
			}
			$presets = $settings[ $section ][ $list ]['theme'];
			$current = (array) ( $data['settings'][ $section ][ $list ] ?? array() );
			$current = array_is_list( $current ) ? array( 'custom' => $current ) : $current;
			$slugs   = array_column( $presets, 'slug' );
			$custom  = array_values( array_filter( (array) ( $current['custom'] ?? array() ), static fn( $p ): bool => ! in_array( $p['slug'] ?? null, $slugs, true ) ) );
			unset( $current['custom'] );
			$data['settings'][ $section ][ $list ] = array( 'theme' => $presets ) + ( $custom ? array( 'custom' => $custom ) : array() ) + $current;
		}
		return $data;
	}

	/**
	 * User global styles data with the presets of a valid, saved token set; writes nothing.
	 *
	 * @param int                  $set_id Token set ID.
	 * @param array<string, mixed> $data   Current user global styles data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function styles( int $set_id, array $data ) {
		$contract = leitvia_studio_contract();
		if ( ! $contract->is_valid() ) {
			return new WP_Error( 'leitvia_studio_no_contract', __( 'The active theme has no valid contract.', 'leitvia-studio' ), array( 'status' => 422 ) );
		}
		$doc = self::document( $set_id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( 'private' !== get_post_status( $set_id ) ) {
			return new WP_Error( 'leitvia_studio_token_set_status', __( 'Only saved token sets can be activated, not drafts or sets in the trash.', 'leitvia-studio' ), array( 'status' => 422 ) );
		}
		$valid = Token_Sets::validate( (string) get_post_meta( $set_id, Token_Sets::META, true ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$settings = (array) Dtcg::to_settings( $doc, $contract );
		return self::lay_over( $data, $settings ) + array(
			'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
		);
	}

	/**
	 * Validates a token set and writes its presets into the user global styles.
	 *
	 * @param int $set_id Token set ID.
	 * @return true|WP_Error
	 */
	public static function activate( int $set_id ) {
		$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$data = json_decode( (string) ( $post['post_content'] ?? '' ), true );
		$data = self::styles( $set_id, is_array( $data ) ? $data : array() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$post     = empty( $post['ID'] ) ? WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true ) : $post;
		$settings = (array) Dtcg::to_settings( (array) self::document( $set_id ), leitvia_studio_contract() );
		$saved    = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) ( $post['ID'] ?? 0 ),
					'post_content' => (string) wp_json_encode( $data ),
				)
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// Core creates the post with tax_input, which needs a user allowed to assign terms. Without one (WP-CLI, cron)
		// the post lacks its theme term, core never finds it again and the set has no effect.
		wp_set_object_terms( $saved, get_stylesheet(), 'wp_theme' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();

		update_option( self::ACTIVE_OPTION, $set_id );
		update_option(
			self::HASH_OPTION,
			array(
				'theme' => get_stylesheet(),
				'hash'  => self::hash(),
				'set'   => md5( (string) get_post_meta( $set_id, Token_Sets::META, true ) ),
			)
		);

		/**
		 * Fires after a token set was written into the user global styles.
		 *
		 * @param int                  $set_id   Token set ID.
		 * @param array<string, mixed> $settings The compiled theme.json settings.
		 */
		do_action( 'leitvia_studio_token_set_activated', $set_id, $settings );
		return true;
	}

	/**
	 * Drops the active marker and the applied hash when the active set is deleted; the global styles stay as they
	 * are. Runs on deleted_post.
	 *
	 * @param int $post_id Deleted post ID.
	 */
	public static function forget( int $post_id ): void {
		if ( (int) get_option( self::ACTIVE_OPTION ) === $post_id ) {
			delete_option( self::ACTIVE_OPTION );
			delete_option( self::HASH_OPTION );
		}
	}

	/**
	 * Whether the global styles of the active theme no longer match the active set: their preset lists changed since
	 * the last activation for this theme, the set was saved with other values after it, or a role of the set does not
	 * take effect.
	 */
	public static function drift(): bool {
		$applied = get_option( self::HASH_OPTION );
		// The hash belongs to the theme it was written for; another theme has its own global styles.
		if ( ! is_array( $applied ) || get_stylesheet() !== ( $applied['theme'] ?? null ) ) {
			return false;
		}
		$set = (int) get_option( self::ACTIVE_OPTION );
		if ( isset( $applied['set'] ) && md5( (string) get_post_meta( $set, Token_Sets::META, true ) ) !== $applied['set'] ) {
			return true;
		}
		return self::hash() !== ( $applied['hash'] ?? null ) || self::shadowed( $set );
	}

	/**
	 * Whether a role of the set does not take effect: the merged global settings hold another value for it, for
	 * example a custom preset of a style variation that outranks the theme origin.
	 *
	 * @param int $set_id Token set ID.
	 */
	private static function shadowed( int $set_id ): bool {
		$doc      = self::document( $set_id );
		$settings = is_wp_error( $doc ) ? null : Dtcg::to_settings( $doc, leitvia_studio_contract() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		$global = wp_get_global_settings();
		foreach ( Dtcg::GROUPS as list( $section, $list, $key ) ) {
			$effective = Dtcg::effective( $global, $section, $list );
			foreach ( (array) ( $settings[ $section ][ $list ]['theme'] ?? array() ) as $preset ) {
				if ( ( $effective[ $preset['slug'] ][ $key ] ?? null ) !== $preset[ $key ] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Hash of the six preset lists in the user global styles of the active theme.
	 */
	private static function hash(): string {
		$post  = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$data  = json_decode( (string) ( $post['post_content'] ?? '' ), true );
		$lists = array();
		foreach ( Dtcg::GROUPS as list( $section, $list ) ) {
			$lists[] = is_array( $data ) ? ( $data['settings'][ $section ][ $list ]['theme'] ?? null ) : null;
		}
		return md5( (string) wp_json_encode( $lists ) );
	}
}
