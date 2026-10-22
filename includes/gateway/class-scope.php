<?php
/**
 * What agents may see and change.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Model\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Post types, taxonomies and statuses open to the gateway. Private, password protected, privacy requests, fonts,
 * change packages, orders, refunds and coupons are never in scope; customers are users, which no ability reads.
 */
final class Scope {

	/**
	 * Post types in scope besides the entry types of the content model.
	 */
	public const POST_TYPES = array(
		'post',
		'page',
		'attachment',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'leitvia_tokens',
	);

	/**
	 * Post types of WooCommerce in scope while it is active.
	 */
	public const COMMERCE_TYPES = array( 'product', 'product_variation' );

	/**
	 * Taxonomies whose terms agents may change while WooCommerce is active.
	 */
	public const TAXONOMIES = array( 'product_cat', 'product_tag' );

	/**
	 * Statuses in scope. Token sets are always private and only readable with edit_theme_options, so private counts
	 * for them alone.
	 */
	public const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'inherit' );

	/**
	 * Post types in scope.
	 *
	 * @return list<string>
	 */
	public static function post_types(): array {
		$model   = array_values( array_filter( Registrar::post_types(), static fn( string $type ): bool => ! Registrar::is_repeater( $type ) ) );
		$default = array_merge( self::POST_TYPES, self::commerce() ? self::COMMERCE_TYPES : array(), $model );
		/**
		 * Filters the post types open to the gateway. A filter can remove types and add types of the content model
		 * (repeaters included); other types and the blocked ones (privacy requests, fonts, change packages, orders) are
		 * dropped.
		 *
		 * @param list<string> $post_types Post types.
		 */
		$filtered = (array) apply_filters( 'leitvia_studio_gateway_post_types', $default );
		$possible = array_merge( $default, Registrar::post_types() );
		return array_values( array_filter( array_unique( array_intersect( $possible, $filtered ) ), static fn( string $type ): bool => ! self::blocked( $type ) ) );
	}

	/**
	 * Whether a post type is never in scope, whatever a filter says.
	 *
	 * @param string $post_type Post type.
	 */
	public static function blocked( string $post_type ): bool {
		return in_array( $post_type, array( 'user_request', 'leitvia_package', 'shop_coupon' ), true )
			|| str_starts_with( $post_type, 'wp_font_' )
			|| str_starts_with( $post_type, 'shop_order' );
	}

	/**
	 * Whether WooCommerce is active.
	 */
	public static function commerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Taxonomies in scope: TAXONOMIES while WooCommerce is active, none otherwise.
	 *
	 * @return list<string>
	 */
	public static function taxonomies(): array {
		return self::commerce() ? self::TAXONOMIES : array();
	}

	/**
	 * Whether the term exists in a taxonomy in scope.
	 *
	 * @param int $term Term ID.
	 */
	public static function term_readable( int $term ): bool {
		$term = get_term( $term );
		return $term instanceof \WP_Term && in_array( $term->taxonomy, self::taxonomies(), true );
	}

	/**
	 * Whether the current user may read the post through the gateway: type and status in scope, no password, and
	 * read_post granted.
	 *
	 * @param \WP_Post|int $post Post or ID.
	 */
	public static function readable( \WP_Post|int $post ): bool {
		$post = get_post( $post );
		if ( ! $post || '' !== $post->post_password || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		return in_array( $post->post_status, self::statuses( $post->post_type ), true ) && current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Statuses in scope for a post type: private for token sets, STATUSES for the others.
	 *
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	public static function statuses( string $post_type ): array {
		return 'leitvia_tokens' === $post_type ? array( 'private' ) : self::STATUSES;
	}
}
