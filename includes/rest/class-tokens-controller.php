<?php
/**
 * REST routes for token sets: check, activate, export, import and creation from token sources.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Sources;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Activates, exports and imports token sets. Imports and sources pass the same checks as every other write.
 */
final class Tokens_Controller extends \WP_REST_Controller {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/token-sets/(?P<id>\d+)/activate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => array( $this, 'set_permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/token-sets/(?P<id>\d+)/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'set_permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/token-sets/(?P<id>\d+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'set_permissions_check' ),
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'download' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'target'   => array(
						'description' => 'Sync target that exports the file; another one than leitvia/download always sends a file.',
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]+/[a-z0-9-]+$',
						'default'     => 'leitvia/download',
					),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/token-sets/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'title'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'document' => array( 'required' => true ),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/token-sets',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_from_source' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'title'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'index'  => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/token-sources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sources' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Theme editors.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Result of the checks activation runs on the stored document of a set.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Response { valid, findings, settings }; settings are the compiled preset
	 *                                     lists, null when the document does not compile.
	 */
	public function check( $request ) {
		$id  = (int) $request['id'];
		$doc = Compiler::document( $id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$valid    = Token_Sets::validate( (string) get_post_meta( $id, Token_Sets::META, true ) );
		$settings = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$findings = is_wp_error( $valid ) ? (array) $valid->get_error_data()['findings'] : array();
		// validate() keeps errors only; the warnings (unreadable colors, fonts not bundled) come from the checks.
		if ( ! is_wp_error( $settings ) ) {
			$findings = array_merge( $findings, array_values( wp_list_filter( Compiler::check( $doc ), array( 'severity' => 'error' ), 'NOT' ) ) );
		}
		return rest_ensure_response(
			array(
				'valid'    => ! is_wp_error( $valid ),
				'findings' => $findings,
				'settings' => is_wp_error( $settings ) ? null : $settings,
			)
		);
	}

	/**
	 * The stored document of a set, unchanged. With download=1 it goes through the sync target leitvia/download
	 * and is sent as a .tokens.json file; target names another sync target, whose export is sent as
	 * <slug>.<target>.tokens.json.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( $request ) {
		$id    = (int) $request['id'];
		$valid = Compiler::document( $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		// Objects keep {} and [] apart, so the export is the stored document as it is.
		$doc  = json_decode( (string) get_post_meta( $id, Token_Sets::META, true ) );
		$name = (string) $request['target'];
		if ( ! $request['download'] && 'leitvia/download' === $name ) {
			return rest_ensure_response( $doc );
		}
		$post   = get_post( $id );
		$slug   = $post && '' !== $post->post_name ? $post->post_name : 'token-set-' . $id;
		$target = leitvia_studio()->registry( 'sync_targets' )->get( $name );
		if ( null === $target && 'leitvia/download' !== $name ) {
			return new \WP_Error( 'leitvia_studio_unknown_sync_target', __( 'This export is not available.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		$exported = call_user_func(
			$target['export'] ?? array( Sources::class, 'download' ),
			array(
				'id'       => $id,
				'title'    => (string) get_post_field( 'post_title', $id ),
				'document' => $doc,
			)
		);
		if ( is_wp_error( $exported ) ) {
			return $exported;
		}
		$exported = is_string( $exported ) ? json_decode( $exported ) : $exported;
		if ( ! is_array( $exported ) && ! $exported instanceof \stdClass ) {
			return new \WP_Error( 'leitvia_studio_export_failed', __( 'The sync target returned no token document.', 'leitvia-studio' ), array( 'status' => 500 ) );
		}
		$response = rest_ensure_response( $exported );
		$response->header( 'Content-Type', 'application/design-tokens+json' );
		// Other targets name their files after the second part of their name: brand.flat.tokens.json for my-addon/flat.
		$suffix = 'leitvia/download' === $name ? '' : '.' . explode( '/', $name )[1];
		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( $slug . $suffix ) . '.tokens.json"' );
		return $response;
	}

	/**
	 * Imports a DTCG document from the request body only, never from a URL.
	 *
	 * @param \WP_REST_Request $request Request with title and document (object or JSON text).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		// Room for the title and for escaping when the document comes as JSON text; create() checks the document.
		if ( strlen( $request->get_body() ) > 2 * Dtcg::MAX_BYTES ) {
			return self::too_large();
		}
		// The parsed parameters turn {} into []; the raw body keeps the document as it was sent.
		$body = json_decode( $request->get_body() );
		$doc  = $body instanceof \stdClass && property_exists( $body, 'document' ) ? $body->document : $request['document'];
		// Same flags as the stored form, so 1.0 keeps its fraction and the size check counts the same bytes.
		$json = is_string( $doc ) ? $doc : (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		return $this->create( (string) $request['title'], $json );
	}

	/**
	 * Entries of the registry token_sources with their documents.
	 *
	 * @return \WP_REST_Response
	 */
	public function sources() {
		$sources = array();
		foreach ( leitvia_studio()->registry( 'token_sources' )->all() as $name => $source ) {
			$sources[] = array(
				'name'      => $name,
				'label'     => $source['label'],
				'owner'     => $source['owner'],
				'documents' => array_values( (array) call_user_func( $source['callback'] ) ),
			);
		}
		return rest_ensure_response( $sources );
	}

	/**
	 * Creates a set from document number index of a token source.
	 *
	 * @param \WP_REST_Request $request Request with source, index and an optional title.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_from_source( $request ) {
		$source    = leitvia_studio()->registry( 'token_sources' )->get( (string) $request['source'] );
		$documents = $source ? array_values( (array) call_user_func( $source['callback'] ) ) : array();
		$entry     = $documents[ (int) $request['index'] ] ?? null;
		if ( ! is_array( $entry ) || ! is_array( $entry['document'] ?? null ) ) {
			return new \WP_Error( 'leitvia_studio_no_token_source', __( 'Token source or document not found.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		$title = '' !== $request['title'] ? (string) $request['title'] : sanitize_text_field( (string) ( $entry['title'] ?? '' ) );
		return $this->create( '' !== $title ? $title : (string) ( $source['label'] ?? '' ), (string) wp_json_encode( $entry['document'] ) );
	}

	/**
	 * Checks a raw document like every other write (size, depth, contract, CSS allowlist, contrast) and stores it
	 * as a private set. Only $extensions.com.leitvia.contractVersion is set; the rest stays as it came in.
	 *
	 * @param string $title Set title.
	 * @param string $json  Raw DTCG JSON.
	 * @return \WP_REST_Response|\WP_Error Response { id } with status 201.
	 */
	private function create( string $title, string $json ) {
		$valid = Dtcg::validate_size( $json );
		if ( true === $valid ) {
			$valid = Token_Sets::validate( $json );
		}
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		// The limit applies to the stored form (stamped, pretty printed), which the meta sanitizer would empty.
		$stored = Dtcg::sanitize_json( Dtcg::stamp( $json, leitvia_studio_contract()->version() ) );
		if ( '' === $stored ) {
			return self::too_large();
		}
		$title = '' !== trim( $title ) ? $title : __( 'Imported token set', 'leitvia-studio' );
		$id    = wp_insert_post(
			array(
				'post_type'   => Token_Sets::POST_TYPE,
				'post_status' => 'private',
				// wp_insert_post() unslashes the title.
				'post_title'  => wp_slash( mb_substr( $title, 0, 200 ) ),
				// update_post_meta() unslashes, the JSON keeps its backslashes only when slashed here.
				'meta_input'  => array( Token_Sets::META => wp_slash( $stored ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// Core saves the first revision on the first update; this one keeps the imported state restorable.
		wp_save_post_revision( $id );
		return new \WP_REST_Response( array( 'id' => $id ), 201 );
	}

	/**
	 * Theme editors who may also edit this set. Unknown IDs pass, activate() answers 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function set_permissions_check( $request ) {
		$id = (int) $request['id'];
		return current_user_can( 'edit_theme_options' ) && ( ! get_post( $id ) || current_user_can( 'edit_post', $id ) );
	}

	/**
	 * Activates a set. Answers { active, settings, findings } or an error with status 422 and data.findings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate( $request ) {
		$id     = (int) $request['id'];
		$result = Compiler::activate( $id );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			if ( ! isset( $data['findings'] ) ) {
				$data['findings'] = array(
					array(
						'code'     => $result->get_error_code(),
						'severity' => 'error',
						'message'  => $result->get_error_message(),
					),
				);
				$result->add_data( $data );
			}
			return $result;
		}
		$doc = Compiler::document( $id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		return rest_ensure_response(
			array(
				'active'   => $id,
				'settings' => Dtcg::to_settings( $doc, leitvia_studio_contract() ),
				'findings' => Compiler::check( $doc ),
			)
		);
	}

	/**
	 * Error for documents over the size limit.
	 */
	private static function too_large(): \WP_Error {
		return new \WP_Error( 'leitvia_studio_dtcg_too_large', __( 'The token document is larger than 512 KB.', 'leitvia-studio' ), array( 'status' => 413 ) );
	}
}
