<?php
/**
 * Preview of a change package on the front end.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Tokens\Set_Preview;

defined( 'ABSPATH' ) || exit;

/**
 * ?leitvia_package=<id>&leitvia_sign=… shows the target pages with the open operations of the package applied in memory
 * (filter the_posts), only for a logged-in user who may edit the target and with the signature of the package; anyone
 * else sees the normal page. The signature belongs to the package, not to a user: the agent asks for the link, a
 * person opens it (a nonce of the agent user failed for every other user, and the page showed its old state). Nothing
 * is saved, the page carries noindex and is not cached.
 */
final class Preview {

	public const ARG = 'leitvia_package';

	/**
	 * Query argument with the signature of the package, see sign().
	 */
	public const SIGN = 'leitvia_sign';

	/**
	 * Hooks of the preview. Runs from Gateway::boot().
	 */
	public static function boot(): void {
		add_filter( 'the_posts', array( self::class, 'posts' ), 10, 2 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'template_redirect', array( self::class, 'no_cache' ) );
	}

	/**
	 * Link to the preview of a package for the current user: the first post with an address that an open operation
	 * changes; when it only changes posts without one, the signed preview of the token set it activates (Set_Preview,
	 * global styles have no address) or else the front page (template parts, navigation); '' when it changes no
	 * existing post.
	 *
	 * @param int $id Package ID.
	 */
	public static function url( int $id ): string {
		$states = self::states( $id );
		foreach ( array_keys( $states ) as $post_id ) {
			$link = is_post_type_viewable( (string) get_post_type( $post_id ) ) ? get_permalink( $post_id ) : false;
			if ( ! $link && array_key_last( $states ) === $post_id ) {
				$set = self::token_set( $id );
				if ( $set ) {
					return $set;
				}
				$link = home_url( '/' );
			}
			if ( $link ) {
				return add_query_arg(
					array(
						self::ARG  => $id,
						self::SIGN => self::sign( $id ),
					),
					$link
				);
			}
		}
		return self::token_set( $id );
	}

	/**
	 * Signed preview address of the token set an open operation of a package activates, '' when there is none.
	 *
	 * @param int $id Package ID.
	 */
	private static function token_set( int $id ): string {
		foreach ( Packages::operations( $id ) as $entry ) {
			$set     = (int) ( $entry['target']['token_set'] ?? 0 );
			$preview = $set && in_array( Packages::status( $entry ), array( 'open', 'conflict' ), true ) ? Set_Preview::url( $set ) : null;
			if ( $preview ) {
				return $preview['url'];
			}
		}
		return '';
	}

	/**
	 * Replaces the fields of every post the requested package changes with the new state, on copies of the post
	 * objects. Runs on the_posts.
	 *
	 * @param array<int, \WP_Post|mixed> $posts Posts.
	 * @param \WP_Query|null             $query Query.
	 * @return array<int, \WP_Post|mixed>
	 */
	public static function posts( $posts, $query = null ): array {
		unset( $query );
		$posts = (array) $posts;
		$id    = is_admin() ? 0 : self::requested();
		if ( ! $id ) {
			return $posts;
		}
		$states = self::states( $id );
		foreach ( $posts as $index => $post ) {
			if ( ! $post instanceof \WP_Post || ! isset( $states[ $post->ID ] ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$copy = clone $post;
			foreach ( array_diff( Pipeline::POST_FIELDS, array( 'post_type' ) ) as $field ) {
				if ( array_key_exists( $field, $states[ $post->ID ] ) ) {
					$copy->$field = $states[ $post->ID ][ $field ];
				}
			}
			$posts[ $index ] = $copy;
		}
		return $posts;
	}

	/**
	 * Adds noindex during a preview. Runs on wp_robots.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( array $robots ): array {
		return self::requested() ? wp_robots_no_robots( $robots ) : $robots;
	}

	/**
	 * Sends no-cache headers during a preview. Runs on template_redirect.
	 */
	public static function no_cache(): void {
		if ( self::requested() ) {
			nocache_headers();
		}
	}

	/**
	 * Signature of a package for its preview link, the same for every user.
	 *
	 * @param int $id Package ID.
	 */
	public static function sign( int $id ): string {
		return substr( wp_hash( self::ARG . '|' . $id, 'nonce' ), 0, 20 );
	}

	/**
	 * ID of the package in the request when a user is logged in and the signature fits, else 0. Whether the user may
	 * see the changes decides posts() per post (edit_post).
	 */
	public static function requested(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The signature is verified here; the view changes nothing.
		$id   = absint( $_GET[ self::ARG ] ?? 0 );
		$sign = sanitize_text_field( wp_unslash( (string) ( $_GET[ self::SIGN ] ?? '' ) ) );
		// phpcs:enable
		if ( ! $id || ! get_current_user_id() || ! hash_equals( self::sign( $id ), $sign ) ) {
			return 0;
		}
		return Packages::POST_TYPE === get_post_type( $id ) ? $id : 0;
	}

	/**
	 * New states of the existing posts the open operations of a package change, by post ID; a later operation on the
	 * same post wins.
	 *
	 * @param int $id Package ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function states( int $id ): array {
		$states = array();
		foreach ( Packages::operations( $id ) as $entry ) {
			$post = (int) ( $entry['target']['post'] ?? 0 );
			if ( $post && in_array( Packages::status( $entry ), array( 'open', 'conflict' ), true ) ) {
				$states[ $post ] = (array) $entry['state'];
			}
		}
		return $states;
	}
}
