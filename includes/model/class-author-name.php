<?php
/**
 * Public names of authors.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

use LeitviaStudio\Gateway\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * No login name reaches visitors. Core fills the display name with the login, so a site nobody tuned shows it in the
 * byline, the author box, the feed and the structured data. Here a user whose display name is the login shows first and
 * last name, else the name of the site, as does every agent user; such a user has no author page either, since its
 * address would carry the login. A person sets the real name in the profile; no ability touches users.
 */
final class Author_Name {

	/**
	 * Registers the filters. Runs from Plugin::boot().
	 */
	public static function boot(): void {
		add_filter( 'get_the_author_display_name', array( self::class, 'meta' ), 10, 2 );
		add_filter( 'the_author', array( self::class, 'current' ) );
		add_filter( 'author_link', array( self::class, 'link' ), 10, 2 );
		add_filter( 'rest_prepare_user', array( self::class, 'rest' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'close_archive' ), 1 );
	}

	/**
	 * Whether the display name of a user would show the login, or the user is an agent.
	 *
	 * @param \WP_User $user User.
	 */
	public static function hides( \WP_User $user ): bool {
		$name = strtolower( trim( $user->display_name ) );
		return in_array( Gateway::ROLE, (array) $user->roles, true ) || in_array( $name, array( '', strtolower( $user->user_login ), strtolower( $user->user_nicename ) ), true );
	}

	/**
	 * Name of a user for visitors, see the class description.
	 *
	 * @param int $user_id User ID.
	 */
	public static function of( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		if ( ! self::hides( $user ) ) {
			return $user->display_name;
		}
		$real = in_array( Gateway::ROLE, (array) $user->roles, true ) ? '' : trim( $user->first_name . ' ' . $user->last_name );
		/**
		 * Filters the name visitors see for an author without a display name of their own.
		 *
		 * @param string $name    First and last name, else the name of the site.
		 * @param int    $user_id User ID.
		 */
		return (string) apply_filters( 'leitvia_studio_public_author_name', '' !== $real ? $real : (string) get_bloginfo( 'name' ), $user_id );
	}

	/**
	 * Stored display name, for people and agents who work on the site.
	 *
	 * @param int $user_id User ID.
	 */
	public static function stored( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : '';
	}

	/**
	 * Display name for get_the_author_meta(); screens of the admin keep the stored one. Runs on
	 * get_the_author_display_name.
	 *
	 * @param string    $value   Stored name.
	 * @param int|false $user_id User ID.
	 */
	public static function meta( $value, $user_id = 0 ): string {
		return is_admin() || ! $user_id ? (string) $value : self::of( (int) $user_id );
	}

	/**
	 * Name for get_the_author(), which reads the property of the current author. Runs on the_author.
	 *
	 * @param string|null $name Stored name.
	 */
	public static function current( $name ): string {
		global $authordata;
		return ! is_admin() && $authordata instanceof \WP_User ? self::of( $authordata->ID ) : (string) $name;
	}

	/**
	 * Address of an author: the home page for a user without a name of their own. Runs on author_link.
	 *
	 * @param string $link    Address.
	 * @param int    $user_id User ID.
	 */
	public static function link( $link, $user_id ): string {
		$user = get_userdata( (int) $user_id );
		return ! is_admin() && $user && self::hides( $user ) ? home_url( '/' ) : (string) $link;
	}

	/**
	 * REST answer of a user for someone who may not list users, with no login in name, slug and link. Runs on
	 * rest_prepare_user.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_User          $user     User.
	 * @return \WP_REST_Response
	 */
	public static function rest( $response, $user ) {
		if ( self::hides( $user ) && ! current_user_can( 'list_users' ) ) {
			$data         = (array) $response->get_data();
			$data['name'] = self::of( $user->ID );
			$data['slug'] = 'author-' . $user->ID;
			$data['link'] = home_url( '/' );
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * An author page of a user without a name of their own answers 404, before core redirects ?author=1 to the address
	 * with the login. Runs on template_redirect.
	 */
	public static function close_archive(): void {
		global $wp_query;
		$user = is_author() ? get_queried_object() : null;
		if ( $user instanceof \WP_User && self::hides( $user ) ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}
}
