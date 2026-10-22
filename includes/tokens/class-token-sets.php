<?php
/**
 * Token sets: post type leitvia_tokens with the DTCG document in revisioned meta.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type and its meta, maps its capabilities to edit_theme_options.
 */
final class Token_Sets {

	public const POST_TYPE = 'leitvia_tokens';
	public const META      = '_leitvia_tokens_dtcg';
	private const CAP_TYPE = 'leitvia_token_set';

	/**
	 * Registers post type and meta. Runs on init.
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Token sets', 'leitvia-studio' ),
					'singular_name' => __( 'Token set', 'leitvia-studio' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => true,
				'rest_base'       => 'leitvia-tokens',
				// custom-fields: the REST API only exposes registered meta for post types that support it.
				'supports'        => array( 'title', 'revisions', 'author', 'custom-fields' ),
				'capability_type' => self::CAP_TYPE,
				'map_meta_cap'    => true,
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'revisions_enabled' => true,
				'auth_callback'     => static fn(): bool => current_user_can( 'edit_theme_options' ),
				'sanitize_callback' => array( Dtcg::class, 'sanitize_json' ),
			)
		);
		// Time window of an overlay (Overlays), start and end in UTC.
		register_post_meta(
			self::POST_TYPE,
			Overlays::META,
			array(
				'type'              => 'object',
				'single'            => true,
				'show_in_rest'      => array( 'schema' => Overlays::schema() ),
				'auth_callback'     => static fn(): bool => current_user_can( 'edit_theme_options' ),
				'sanitize_callback' => array( Overlays::class, 'sanitize' ),
			)
		);
	}

	/**
	 * Maps the primitive capabilities of the post type to edit_theme_options. Filter map_meta_cap.
	 *
	 * @param array<string> $caps Primitive capabilities.
	 * @return array<string>
	 */
	public static function map_meta_cap( array $caps ): array {
		$type = get_post_type_object( self::POST_TYPE );
		if ( ! $type ) {
			return $caps;
		}
		$cap       = $type->cap;
		$primitive = array_diff( array_values( (array) $cap ), array( $cap->edit_post, $cap->read_post, $cap->delete_post, 'read' ) );
		return array_map( static fn( string $c ): string => in_array( $c, $primitive, true ) ? 'edit_theme_options' : $c, $caps );
	}

	/**
	 * Stores every set as private, so it is never readable without edit_theme_options. Filter wp_insert_post_data.
	 *
	 * @param array<string, mixed> $data Post data.
	 * @return array<string, mixed>
	 */
	public static function force_private( array $data ): array {
		if ( self::POST_TYPE === $data['post_type'] && ! in_array( $data['post_status'], array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			$data['post_status'] = 'private';
		}
		return $data;
	}

	/**
	 * Points edit links of token sets, for example on the core revisions screen, to the Studio editor.
	 * Filter get_edit_post_link.
	 *
	 * @param string|null $link    Edit link.
	 * @param int         $post_id Post ID.
	 * @param string      $context 'display' encodes the ampersand.
	 * @return string|null
	 */
	public static function edit_link( $link, $post_id, $context ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $link;
		}
		$separator = 'display' === $context ? '&amp;' : '&';
		return admin_url( 'admin.php?page=leitvia-studio-tokens' . $separator . 'set=' . (int) $post_id );
	}

	/**
	 * Lists private sets by default in REST; anonymous requests for private posts get 401.
	 * Filter rest_leitvia_tokens_collection_params.
	 *
	 * @param array<string, mixed> $params Collection parameters.
	 * @return array<string, mixed>
	 */
	public static function collection_params( array $params ): array {
		$params['status']['default'] = 'private';
		return $params;
	}

	/**
	 * Validates a raw token document: size, compilation against the contract and every check with scope token_set.
	 *
	 * @param string $json Raw DTCG JSON.
	 * @return true|WP_Error Error leitvia_studio_token_set_invalid with status 422 and data.findings.
	 */
	public static function validate( string $json ) {
		$doc   = json_decode( $json, true );
		$valid = Dtcg::validate_size( $json );
		if ( true === $valid && is_array( $doc ) ) {
			$valid = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		}
		if ( is_wp_error( $valid ) ) {
			$findings = array(
				array(
					'code'     => $valid->get_error_code(),
					'severity' => 'error',
					'message'  => $valid->get_error_message(),
				),
			);
		} else {
			$findings = array_values( wp_list_filter( Compiler::check( (array) $doc ), array( 'severity' => 'error' ) ) );
		}
		if ( ! $findings ) {
			return true;
		}
		return new WP_Error(
			'leitvia_studio_token_set_invalid',
			__( 'The token set does not pass the checks.', 'leitvia-studio' ),
			array(
				'status'   => 422,
				'findings' => $findings,
			)
		);
	}

	/**
	 * Rejects REST writes of an invalid document. Filter rest_pre_insert_leitvia_tokens.
	 *
	 * @param \stdClass|WP_Error $prepared Post prepared for the database.
	 * @param \WP_REST_Request   $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public static function validate_rest( $prepared, \WP_REST_Request $request ) {
		$meta = $request['meta'];
		if ( is_wp_error( $prepared ) || ! is_array( $meta ) || ! isset( $meta[ self::META ] ) ) {
			return $prepared;
		}
		$valid = self::validate( (string) $meta[ self::META ] );
		return is_wp_error( $valid ) ? $valid : $prepared;
	}

	/**
	 * Creates a token set from the presets of the active theme, including user global styles.
	 *
	 * @param string $title Set title.
	 * @return int|WP_Error Post ID.
	 */
	public static function create_from_active_theme( string $title ) {
		$contract = leitvia_studio_contract();
		if ( ! $contract->is_valid() ) {
			return new WP_Error( 'leitvia_studio_no_contract', __( 'The active theme has no valid contract.', 'leitvia-studio' ), array( 'status' => 422 ) );
		}
		$doc = Dtcg::from_settings( wp_get_global_settings(), $contract );
		$id  = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => $title,
				// update_post_meta() unslashes, the JSON keeps its backslashes only when slashed here.
				'meta_input'  => array( self::META => wp_slash( (string) wp_json_encode( $doc ) ) ),
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			// Core saves the first revision on the first update; this one keeps the generated state restorable.
			wp_save_post_revision( $id );
		}
		return $id;
	}
}
