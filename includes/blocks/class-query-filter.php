<?php
/**
 * Query filter and Load more: facets and paging for the posts of a core/query block, with the state in the URL.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders leitvia/query-filter and leitvia/load-more and turns the URL parameters qf-<queryId>-<facet> into a
 * tax_query or meta_query for the core/query block with that queryId. Only public taxonomies and registered meta keys
 * of the types string, integer and boolean count, both shown in REST; everything else in the URL is ignored.
 */
final class Query_Filter {

	/**
	 * At most this many values per facet are read from the URL and offered as options.
	 */
	private const MAX_VALUES = 50;

	/**
	 * Adds the facets in the URL to the query of the core/query block. Runs on query_loop_block_query_vars.
	 *
	 * @param array<string, mixed> $query Query vars built from the block.
	 * @param \WP_Block            $block Block that builds the query, with the context queryId.
	 * @return array<string, mixed>
	 */
	public static function query_vars( array $query, \WP_Block $block ): array {
		if ( ! isset( $block->context['queryId'] ) ) {
			return $query;
		}
		$post_type = is_string( $query['post_type'] ?? null ) ? $query['post_type'] : 'post';
		$tax       = array();
		$meta      = array();
		foreach ( self::selection( (int) $block->context['queryId'], $post_type ) as $key => $values ) {
			if ( self::taxonomy_allowed( $key, $post_type ) ) {
				$tax[] = array(
					'taxonomy' => $key,
					'field'    => 'slug',
					'terms'    => $values,
				);
			} else {
				$meta[] = array(
					'key'     => $key,
					'value'   => $values,
					'compare' => 'IN',
					'type'    => 'integer' === self::meta_type( $key, $post_type ) ? 'NUMERIC' : 'CHAR',
				);
			}
		}
		// Nested, so an existing relation of the block (for example OR) keeps its meaning.
		if ( $tax ) {
			$query['tax_query'] = array_merge( array( 'relation' => 'AND' ), empty( $query['tax_query'] ) ? array() : array( $query['tax_query'] ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		if ( $meta ) {
			$query['meta_query'] = array_merge( array( 'relation' => 'AND' ), empty( $query['meta_query'] ) ? array() : array( $query['meta_query'] ), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $query;
	}

	/**
	 * The facets of one query in the URL: allowed key => cleaned values, empty facets left out.
	 *
	 * @param int    $query_id  The queryId of the core/query block.
	 * @param string $post_type Post type of the query.
	 * @return array<string, list<string>>
	 */
	public static function selection( int $query_id, string $post_type ): array {
		$prefix = 'qf-' . $query_id . '-';
		$found  = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only filter parameters.
		$params = (array) map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
		foreach ( $params as $name => $raw ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, $prefix ) ) {
				continue;
			}
			$key    = substr( $name, strlen( $prefix ) );
			$values = self::clean( $key, $post_type, $raw );
			if ( $values ) {
				$found[ $key ] = $values;
			}
		}
		return $found;
	}

	/**
	 * Cleans the URL values of one facet: term slugs for a taxonomy, numbers, text or "1" for meta, nothing for any
	 * other key. Values go into WP_Query as data, never into SQL.
	 *
	 * @param string $key       Facet key.
	 * @param string $post_type Post type of the query.
	 * @param mixed  $raw       Value from the URL, a string or a list.
	 * @return list<string>
	 */
	private static function clean( string $key, string $post_type, $raw ): array {
		$type = self::taxonomy_allowed( $key, $post_type ) ? 'taxonomy' : self::meta_type( $key, $post_type );
		if ( '' === $type ) {
			return array();
		}
		$values = array();
		foreach ( array_slice( (array) $raw, 0, self::MAX_VALUES ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			switch ( $type ) {
				case 'taxonomy':
					$value = sanitize_title( $value );
					break;
				case 'integer':
					$value = is_numeric( $value ) ? (string) (int) $value : '';
					break;
				case 'boolean':
					$value = '1' === $value ? '1' : '';
					break;
				default:
					$value = sanitize_text_field( $value );
			}
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * Whether a taxonomy may filter: public, shown in REST and attached to the post type.
	 *
	 * @param string $key       Taxonomy name.
	 * @param string $post_type Post type of the query.
	 */
	public static function taxonomy_allowed( string $key, string $post_type ): bool {
		$taxonomy = get_taxonomy( $key );
		return $taxonomy && $taxonomy->public && $taxonomy->show_in_rest && is_object_in_taxonomy( $post_type, $key );
	}

	/**
	 * Type of a meta key that may filter (string, integer or boolean), '' for any other key. The key must be registered
	 * for the post type or all post types and shown in REST; fields of the content model are registered that way.
	 *
	 * @param string $key       Meta key.
	 * @param string $post_type Post type of the query.
	 */
	public static function meta_type( string $key, string $post_type ): string {
		$args = get_registered_meta_keys( 'post', $post_type )[ $key ] ?? get_registered_meta_keys( 'post' )[ $key ] ?? null;
		$type = (string) ( $args['type'] ?? '' );
		return ! empty( $args['show_in_rest'] ) && in_array( $type, array( 'string', 'integer', 'boolean' ), true ) ? $type : '';
	}

	/**
	 * The query of the surrounding core/query block for the current page, built as core builds it (filters included),
	 * and its page number. Null for a query that inherits the main query: core does not run
	 * query_loop_block_query_vars for it.
	 *
	 * @param \WP_Block $block Block inside core/query, with the context query and queryId.
	 * @return array{0: \WP_Query, 1: int, 2: string}|null Query, page number and the name of the page parameter.
	 */
	private static function query( \WP_Block $block ): ?array {
		$query = $block->context['query'] ?? null;
		if ( ! is_array( $query ) || ! empty( $query['inherit'] ) || ! isset( $block->context['queryId'] ) ) {
			return null;
		}
		$key = 'query-' . (int) $block->context['queryId'] . '-page';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only page parameter.
		$page = max( 1, absint( wp_unslash( $_GET[ $key ] ?? 1 ) ) );
		$vars = build_query_vars_from_query_block( $block, $page );
		return array( new \WP_Query( array_merge( $vars, array( 'fields' => 'ids' ) ) ), $page, $key );
	}

	/**
	 * Options of one facet: value => label. Terms with posts for a taxonomy, "Yes" for a boolean, the stored values
	 * for other meta keys.
	 *
	 * @param string $key       Facet key.
	 * @param string $post_type Post type of the query.
	 * @return array<int|string, string> Numeric values become integer keys in PHP.
	 */
	private static function options( string $key, string $post_type ): array {
		if ( self::taxonomy_allowed( $key, $post_type ) ) {
			$terms = get_terms(
				array(
					'taxonomy' => $key,
					'number'   => self::MAX_VALUES,
				)
			);
			return is_array( $terms ) ? wp_list_pluck( $terms, 'name', 'slug' ) : array();
		}
		$type = self::meta_type( $key, $post_type );
		if ( 'boolean' === $type ) {
			return array( '1' => __( 'Yes', 'leitvia-studio' ) );
		}
		if ( '' === $type ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- distinct values of one meta key, no API for that.
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value LIMIT %d", $key, self::MAX_VALUES ) );
		return array_combine( $values, $values );
	}

	/**
	 * Name of a facet: the taxonomy name, the label or description of the meta key, else the key.
	 *
	 * @param string $key       Facet key.
	 * @param string $post_type Post type of the query.
	 */
	private static function legend( string $key, string $post_type ): string {
		$taxonomy = get_taxonomy( $key );
		if ( $taxonomy && self::taxonomy_allowed( $key, $post_type ) ) {
			return (string) $taxonomy->labels->name;
		}
		$args = get_registered_meta_keys( 'post', $post_type )[ $key ] ?? get_registered_meta_keys( 'post' )[ $key ] ?? array();
		foreach ( array( $args['label'] ?? '', $args['description'] ?? '' ) as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}
		return $key;
	}

	/**
	 * Renders leitvia/query-filter: a GET form with one group per facet, Apply filters, Reset filters and the number
	 * of results in a status region, which view.js keeps up to date through the router.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param \WP_Block            $block      Block instance.
	 */
	public static function render( array $attributes, \WP_Block $block ): string {
		$found = self::query( $block );
		if ( null === $found ) {
			return '';
		}
		$query_id  = (int) $block->context['queryId'];
		$post_type = is_string( $block->context['query']['postType'] ?? null ) ? $block->context['query']['postType'] : 'post';
		$prefix    = 'qf-' . $query_id . '-';
		$selected  = self::selection( $query_id, $post_type );
		$groups    = '';
		foreach ( (array) ( $attributes['facets'] ?? array() ) as $facet ) {
			$key     = is_array( $facet ) ? (string) ( $facet['key'] ?? '' ) : '';
			$options = '' === $key ? array() : self::options( $key, $post_type );
			if ( ! $options ) {
				continue;
			}
			$name   = $prefix . $key;
			$chosen = $selected[ $key ] ?? array();
			if ( 'select' === ( $facet['control'] ?? '' ) ) {
				$id   = wp_unique_id( 'leitvia-query-filter-' );
				$list = sprintf( '<option value="">%s</option>', esc_html__( 'All', 'leitvia-studio' ) );
				foreach ( $options as $value => $label ) {
					$list .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( in_array( (string) $value, $chosen, true ), true, false ), esc_html( $label ) );
				}
				$groups .= sprintf( '<p class="wp-block-leitvia-query-filter__select"><label for="%1$s">%2$s</label><select id="%1$s" name="%3$s">%4$s</select></p>', esc_attr( $id ), esc_html( self::legend( $key, $post_type ) ), esc_attr( $name ), $list );
				continue;
			}
			$boxes = '';
			foreach ( $options as $value => $label ) {
				$boxes .= sprintf( '<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>', esc_attr( $name ), esc_attr( (string) $value ), checked( in_array( (string) $value, $chosen, true ), true, false ), esc_html( $label ) );
			}
			$groups .= sprintf( '<fieldset><legend>%1$s</legend>%2$s</fieldset>', esc_html( self::legend( $key, $post_type ) ), $boxes );
		}

		// Parameters of other blocks on the page (another query, its page) survive a submit of this form. Only keys of lowercase
		// letters, digits, "-" and "_" (as core and Leitvia blocks use them) and plain text values are carried over.
		$own    = array( $found[2] );
		$hidden = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only parameters.
		$params = (array) map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
		foreach ( $params as $name => $value ) {
			$key = is_string( $name ) ? sanitize_key( $name ) : '';
			if ( '' === $key || $key !== $name || str_starts_with( $key, $prefix ) || in_array( $key, $own, true ) ) {
				continue;
			}
			foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
				if ( is_scalar( $item ) ) {
					$hidden .= sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $key . ( is_array( $value ) ? '[]' : '' ) ), esc_attr( sanitize_text_field( (string) $item ) ) );
				}
			}
		}
		$reset = remove_query_arg( array_merge( $own, array_map( static fn( $key ) => $prefix . $key, array_keys( $selected ) ) ) );
		$total = (int) $found[0]->found_posts;
		$label = (string) ( $attributes['label'] ?? '' );

		return sprintf(
			'<div %1$s><form method="get" role="search" aria-label="%2$s" data-leitvia-query="%3$d">%4$s%5$s<p class="wp-block-leitvia-query-filter__actions"><button type="submit" class="wp-element-button">%6$s</button> <a href="%7$s">%8$s</a></p><p class="wp-block-leitvia-query-filter__count" role="status">%9$s</p></form></div>',
			get_block_wrapper_attributes(),
			esc_attr( '' !== $label ? $label : __( 'Filter', 'leitvia-studio' ) ),
			$query_id,
			$groups,
			$hidden,
			esc_html__( 'Apply filters', 'leitvia-studio' ),
			esc_url( $reset ),
			esc_html__( 'Reset filters', 'leitvia-studio' ),
			/* translators: %s: number of posts that match the filters. */
			esc_html( sprintf( _n( '%s result', '%s results', $total, 'leitvia-studio' ), number_format_i18n( $total ) ) )
		);
	}

	/**
	 * Renders leitvia/load-more: a link to the next page of the query with the same parameters, nothing on the last
	 * page. view.js appends the posts of that page instead of leaving.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param \WP_Block            $block      Block instance.
	 */
	public static function render_load_more( array $attributes, \WP_Block $block ): string {
		$found = self::query( $block );
		if ( null === $found || $found[1] >= (int) $found[0]->max_num_pages ) {
			return '';
		}
		$text = (string) ( $attributes['text'] ?? '' );
		return sprintf(
			'<div %1$s data-leitvia-query="%2$d"><a class="wp-element-button" href="%3$s" rel="next">%4$s</a><p class="screen-reader-text" role="status" data-loaded="%5$s"></p></div>',
			get_block_wrapper_attributes(),
			(int) $block->context['queryId'],
			esc_url( add_query_arg( $found[2], $found[1] + 1 ) ),
			esc_html( '' !== $text ? $text : __( 'Load more', 'leitvia-studio' ) ),
			/* translators: %d: number of posts just added to the list. */
			esc_attr__( 'More results loaded: %d', 'leitvia-studio' )
		);
	}
}
