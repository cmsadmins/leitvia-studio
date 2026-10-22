<?php
/**
 * Preview of a change package on the front end.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * ?leitvia_package=<id>&_wpnonce=… shows the target pages with the open operations of the package applied in memory
 * (filter the_posts), only for a user who may edit the target and with a valid nonce; anyone else sees the normal
 * page. Nothing is saved, the page carries noindex and is not cached.
 */
final class Preview {

	public const ARG = 'leitvia_package';

	/**
	 * Hooks of the preview. Runs from Gateway::boot().
	 */
	public static function boot(): void {
		add_filter( 'the_posts', array( self::class, 'posts' ), 10, 2 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'template_redirect', array( self::class, 'no_cache' ) );
	}

	/**
	 * Link to the preview of a package for the current user: the first post an open operation changes, '' when it
	 * changes no existing post.
	 *
	 * @param int $id Package ID.
	 */
	public static function url( int $id ): string {
		foreach ( self::states( $id ) as $post_id => $state ) {
			$link = get_permalink( $post_id );
			if ( $link ) {
				return add_query_arg(
					array(
						self::ARG  => $id,
						'_wpnonce' => wp_create_nonce( self::ARG . '_' . $id ),
					),
					$link
				);
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
	 * ID of the package in the request when its nonce is valid for the current user, else 0.
	 */
	public static function requested(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified here.
		$id    = absint( $_GET[ self::ARG ] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( (string) ( $_GET['_wpnonce'] ?? '' ) ) );
		// phpcs:enable
		if ( ! $id || ! get_current_user_id() || ! wp_verify_nonce( $nonce, self::ARG . '_' . $id ) ) {
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
