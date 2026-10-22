<?php
/**
 * REST controller of content model taxonomies that are not public.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Core terms controller; only users who may assign terms of the taxonomy read it.
 */
class Terms_Controller extends \WP_REST_Terms_Controller {

	/**
	 * Lists only for users who may assign terms.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$allowed = $this->can_read_taxonomy();
		return true === $allowed ? parent::get_items_permissions_check( $request ) : $allowed;
	}

	/**
	 * Reads one term only for users who may assign terms.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$allowed = $this->can_read_taxonomy();
		return true === $allowed ? parent::get_item_permissions_check( $request ) : $allowed;
	}

	/**
	 * Whether the current user may assign terms of this taxonomy.
	 *
	 * @return true|\WP_Error
	 */
	private function can_read_taxonomy() {
		$taxonomy = get_taxonomy( $this->taxonomy );
		if ( $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to read terms of this taxonomy.', 'leitvia-studio' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
