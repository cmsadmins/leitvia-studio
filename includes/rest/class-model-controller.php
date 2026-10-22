<?php
/**
 * REST routes /leitvia-studio/v1/model, /model/validate and /model/schema.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Model\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and stores the content model.
 */
final class Model_Controller extends \WP_REST_Controller {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$manage = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'leitvia-studio/v1',
			'/model',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_model' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'put_model' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/model/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_model' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/model/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( Model::schema() ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * The current declaration. Every entry carries source "code" or "ui"; counts holds the number of entries per
	 * post type and of terms per taxonomy; errors lists problems of a declaration from code that keep it unregistered.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_model() {
		$model   = Model::current();
		$sources = Model::sources();
		$counts  = array();
		foreach ( Model::SECTIONS as $section ) {
			foreach ( array_keys( (array) ( $model[ $section ] ?? array() ) ) as $key ) {
				$model[ $section ][ $key ]['source'] = $sources[ $section ][ $key ];
			}
		}
		foreach ( array_keys( (array) ( $model['postTypes'] ?? array() ) ) as $key ) {
			$counts['postTypes'][ $key ] = post_type_exists( $key ) ? array_sum( (array) wp_count_posts( $key ) ) : 0;
		}
		foreach ( array_keys( (array) ( $model['taxonomies'] ?? array() ) ) as $key ) {
			$terms                        = taxonomy_exists( $key ) ? wp_count_terms( array( 'taxonomy' => $key ) ) : 0;
			$counts['taxonomies'][ $key ] = is_wp_error( $terms ) ? 0 : (int) $terms;
		}
		$valid = Model::validate( Model::current() );
		return rest_ensure_response(
			array(
				'model'  => $model,
				'counts' => $counts,
				'errors' => is_wp_error( $valid ) ? $valid->get_error_data()['errors'] : array(),
			)
		);
	}

	/**
	 * Stores the declaration of the Model screen (without the entries from code).
	 *
	 * @param \WP_REST_Request $request Request with the declaration as JSON body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_model( $request ) {
		$decl = $this->declaration( $request );
		if ( is_wp_error( $decl ) ) {
			return $decl;
		}
		$saved = Model::save( $decl );
		return is_wp_error( $saved ) ? $saved : $this->get_model();
	}

	/**
	 * Checks a declaration of the Model screen without storing it.
	 *
	 * @param \WP_REST_Request $request Request with the declaration as JSON body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate_model( $request ) {
		$decl = $this->declaration( $request );
		if ( is_wp_error( $decl ) ) {
			return $decl;
		}
		$valid = Model::save( $decl, true );
		return is_wp_error( $valid ) ? $valid : rest_ensure_response( array( 'valid' => true ) );
	}

	/**
	 * The JSON body as declaration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function declaration( $request ) {
		// An empty object {} is a declaration (and fails for the missing version); a list or a scalar is not.
		$decl = json_decode( $request->get_body(), true );
		if ( is_array( $decl ) && ( ! $decl || ! array_is_list( $decl ) ) ) {
			return $decl;
		}
		return new \WP_Error(
			'leitvia_studio_invalid_model',
			__( 'The request body must be a JSON object.', 'leitvia-studio' ),
			array(
				'status' => 400,
				'errors' => array(
					array(
						'path'    => '',
						'message' => __( 'The request body must be a JSON object.', 'leitvia-studio' ),
					),
				),
			)
		);
	}
}
