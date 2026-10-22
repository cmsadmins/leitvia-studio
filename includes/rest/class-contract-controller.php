<?php
/**
 * REST routes GET /leitvia-studio/v1/contract and GET /leitvia-studio/v1/blocks.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the contract of the active theme to theme editors and the block catalog to authors.
 */
final class Contract_Controller extends \WP_REST_Controller {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/contract',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/blocks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( leitvia_studio_contract()->blocks() ),
					'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				),
				'schema' => array( \LeitviaStudio\Blocks\Catalog::class, 'schema' ),
			)
		);
	}

	/**
	 * Only users who may change the theme read its contract.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WP_REST_Controller.
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Returns state and content of the contract.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WP_REST_Controller.
		$contract = leitvia_studio_contract();
		return rest_ensure_response(
			array(
				'exists'   => $contract->exists(),
				'valid'    => $contract->is_valid(),
				'error'    => $contract->error(),
				'version'  => $contract->version(),
				'contract' => $contract->is_valid() ? $contract->data() : null,
				'active'   => (int) get_option( \LeitviaStudio\Tokens\Compiler::ACTIVE_OPTION, 0 ),
				'drift'    => \LeitviaStudio\Tokens\Compiler::drift(),
				'blocks'   => rest_url( 'leitvia-studio/v1/blocks' ),
			)
		);
	}

	/**
	 * Schema of the response.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( ! $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'leitvia-studio-contract',
				'type'       => 'object',
				'properties' => array(
					'exists'   => array(
						'description' => __( 'Whether the active theme ships a contract file.', 'leitvia-studio' ),
						'type'        => 'boolean',
						'readonly'    => true,
					),
					'valid'    => array(
						'description' => __( 'Whether the contract passed validation.', 'leitvia-studio' ),
						'type'        => 'boolean',
						'readonly'    => true,
					),
					'error'    => array(
						'description' => __( 'Validation error, empty when valid.', 'leitvia-studio' ),
						'type'        => 'string',
						'readonly'    => true,
					),
					'version'  => array(
						'description' => __( 'Contract version, empty unless valid.', 'leitvia-studio' ),
						'type'        => 'string',
						'readonly'    => true,
					),
					'contract' => array(
						'description' => __( 'The whole contract, null unless valid.', 'leitvia-studio' ),
						'type'        => array( 'object', 'null' ),
						'readonly'    => true,
					),
					'active'   => array(
						'description' => __( 'ID of the token set last activated, 0 for none.', 'leitvia-studio' ),
						'type'        => 'integer',
						'readonly'    => true,
					),
					'drift'    => array(
						'description' => __( 'Whether the global styles changed since the active token set was applied.', 'leitvia-studio' ),
						'type'        => 'boolean',
						'readonly'    => true,
					),
					'blocks'   => array(
						'description' => __( 'URL of the block catalog with attribute schemas and usage rules.', 'leitvia-studio' ),
						'type'        => 'string',
						'format'      => 'uri',
						'readonly'    => true,
					),
				),
			);
		}
		return $this->add_additional_fields_schema( $this->schema );
	}
}
