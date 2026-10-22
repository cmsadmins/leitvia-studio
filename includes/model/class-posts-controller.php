<?php
/**
 * REST controller of content model post types that are not public.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Core posts controller; only users who may edit the type read it. Repeater rows instead follow their entry: they
 * carry the status inherit, which core reads through the parent post.
 */
class Posts_Controller extends \WP_REST_Posts_Controller {

	/**
	 * Lists only for editors of the type, repeater rows included.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$allowed = $this->can_read_type();
		return true === $allowed ? parent::get_items_permissions_check( $request ) : $allowed;
	}

	/**
	 * Reads one entry: editors of the type, and for repeater rows everyone who may read the entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$allowed = Registrar::is_repeater( $this->post_type ) ? true : $this->can_read_type();
		return true === $allowed ? parent::get_item_permissions_check( $request ) : $allowed;
	}

	/**
	 * Repeater rows of a password protected entry stay hidden until the wp-postpass cookie of the entry is set (the
	 * password form of the front end), except for those who may edit the entry; a password parameter of the REST
	 * request does not unlock them. Lists drop such rows after the query, like core does for attachments, so X-WP-Total may count
	 * rows the response leaves out.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function check_read_permission( $post ) {
		if ( Registrar::is_repeater( $post->post_type ) && $post->post_parent && post_password_required( $post->post_parent ) && ! current_user_can( 'edit_post', $post->post_parent ) ) {
			return false;
		}
		return parent::check_read_permission( $post );
	}

	/**
	 * Creates rows only on an entry of the declaring type the user may edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$allowed = parent::create_item_permissions_check( $request );
		return true === $allowed && Registrar::is_repeater( $this->post_type ) ? $this->check_entry( $request, true ) : $allowed;
	}

	/**
	 * Moves rows only to an entry of the declaring type the user may edit; editing the row itself follows its current
	 * entry (Registrar::map_row_caps()).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$allowed = parent::update_item_permissions_check( $request );
		return true === $allowed && Registrar::is_repeater( $this->post_type ) ? $this->check_entry( $request, false ) : $allowed;
	}

	/**
	 * Repeater rows list their own status by default, so the query loop preview in the editor shows them.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		if ( Registrar::is_repeater( $this->post_type ) ) {
			$params['status']['default'] = 'inherit';
		}
		return $params;
	}

	/**
	 * Checks the parent of a repeater row: an entry of the declaring type that the current user may edit.
	 *
	 * @param \WP_REST_Request $request  Request.
	 * @param bool             $required Whether the parent must be sent.
	 * @return true|\WP_Error
	 */
	private function check_entry( $request, bool $required ) {
		if ( ! isset( $request['parent'] ) ) {
			return $required ? new \WP_Error( 'rest_missing_parent', __( 'Rows need the entry they belong to as parent.', 'leitvia-studio' ), array( 'status' => 400 ) ) : true;
		}
		$entry = get_post( (int) $request['parent'] );
		if ( ! $entry || Registrar::entry_type( $this->post_type ) !== $entry->post_type ) {
			return new \WP_Error( 'rest_invalid_parent', __( 'The parent must be an entry of the type that declares these rows.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $entry->ID ) ) {
			return new \WP_Error( 'rest_cannot_edit_parent', __( 'Sorry, you are not allowed to change the rows of this entry.', 'leitvia-studio' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Whether the current user may edit entries of this type.
	 *
	 * @return true|\WP_Error
	 */
	private function can_read_type() {
		$type = get_post_type_object( $this->post_type );
		if ( $type && current_user_can( $type->cap->edit_posts ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to read entries of this type.', 'leitvia-studio' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
