<?php
/**
 * Contrast guard for global styles saved in the Site Editor.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use WP_Error;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects user global styles whose palette fails a contract pair. Off with the setting guard_site_editor_contrast.
 */
final class Site_Editor_Guard {

	/**
	 * Filter rest_request_before_callbacks. The global styles controller builds its post without
	 * rest_pre_insert_wp_global_styles, so the guard reads the request: its settings replace the stored ones.
	 *
	 * @param mixed            $response Response so far, usually null.
	 * @param array<mixed>     $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed The response, or WP_Error with status 422.
	 */
	public static function validate_rest( $response, $handler, \WP_REST_Request $request ) {
		$options = get_option( 'leitvia_studio_settings', array() );
		if (
			null !== $response
			|| 'GET' === $request->get_method()
			|| ! preg_match( '#^/wp/v2/global-styles/\d+$#D', $request->get_route() )
			|| ! ( is_array( $options ) ? $options['guard_site_editor_contrast'] ?? true : true )
		) {
			return $response;
		}
		// Callers without the right get 401 or 403 from core, not a contrast answer.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $response;
		}
		$contract = leitvia_studio_contract();
		if ( ! $contract->is_valid() ) {
			return $response;
		}
		$settings = $request['settings'];
		$user     = is_array( $settings ) ? $settings['color']['palette']['theme'] ?? array() : array();
		if ( ! is_array( $user ) || ! $user ) {
			return $response;
		}
		$theme    = WP_Theme_JSON_Resolver::get_merged_data( 'theme' )->get_settings()['color']['palette']['theme'] ?? array();
		$palette  = array_merge( array_column( $theme, null, 'slug' ), array_column( $user, null, 'slug' ) );
		$findings = Contrast::findings( array_values( $palette ), $contract->pairs() );
		$errors   = array_values( wp_list_filter( $findings, array( 'severity' => 'error' ) ) );
		if ( ! $errors ) {
			if ( $findings ) {
				self::attach_warnings( $request, $findings );
			}
			return $response;
		}
		return new WP_Error(
			'leitvia_studio_contrast',
			sprintf(
				/* translators: %s: list of failing color pairs. */
				__( 'The styles were not saved. These colors fail the contrast rules of the theme: %s', 'leitvia-studio' ),
				implode( '; ', array_column( $errors, 'message' ) )
			),
			array(
				'status'   => 422,
				'findings' => $findings,
			)
		);
	}

	/**
	 * Adds colors that could not be checked to the response of a save that went through, as leitvia_studio_warnings.
	 *
	 * @param \WP_REST_Request           $request  The saving request.
	 * @param list<array<string, mixed>> $warnings Warning findings.
	 */
	private static function attach_warnings( \WP_REST_Request $request, array $warnings ): void {
		add_filter(
			'rest_request_after_callbacks',
			static function ( $result, $handler, $current ) use ( $request, $warnings ) {
				if ( $current === $request && $result instanceof \WP_REST_Response && is_array( $result->get_data() ) ) {
					$result->set_data( $result->get_data() + array( 'leitvia_studio_warnings' => $warnings ) );
				}
				return $result;
			},
			10,
			3
		);
	}
}
