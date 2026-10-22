<?php
/**
 * Index of the patterns every post uses.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one _leitvia_uses row per used pattern reference on every post, so "where is this pattern used" is one indexed
 * meta query. Theme templates from files are not posts; used_by() parses them live.
 */
final class Usage_Index {

	/**
	 * Meta key of the index, one row per reference.
	 */
	public const META = '_leitvia_uses';

	/**
	 * Option that marks the index as built for every existing post.
	 */
	public const BUILT = 'leitvia_studio_usage_index_built';

	/**
	 * Most posts used_by() lists.
	 */
	public const LIMIT = 100;

	/**
	 * Post types that are never indexed.
	 */
	private const SKIP = array( 'attachment', 'revision', 'leitvia_tokens' );

	/**
	 * References per theme template file, by stylesheet, for the current request.
	 *
	 * @var array<string, array<string, array{template: \WP_Block_Template, refs: list<string>}>>
	 */
	private static array $files = array();

	/**
	 * Rewrites the index of a saved post. Runs on save_post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function index( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}
		delete_post_meta( $post_id, self::META );
		foreach ( Status::refs( $post->post_content ) as $ref ) {
			// Not unique: one row per reference, so the lookup by meta_key and meta_value uses the table index.
			add_post_meta( $post_id, self::META, $ref );
		}
	}

	/**
	 * Indexed post types: every type in the REST API except attachments and token sets.
	 *
	 * @return list<string>
	 */
	public static function types(): array {
		return array_values( array_diff( get_post_types( array( 'show_in_rest' => true ) ), self::SKIP ) );
	}

	/**
	 * Indexes one batch of posts of every indexed type in ID order, after the last ID of the previous batch. Only a run
	 * that started at 0 marks the index as built when its last batch is done.
	 *
	 * @param int $after Last post ID of the previous batch, 0 to start.
	 * @param int $limit Batch size.
	 * @return array{done: bool, next: int}
	 */
	public static function reindex( int $after, int $limit = 50 ): array {
		global $wpdb;
		$limit  = max( 1, $limit );
		$after  = max( 0, $after );
		$types  = self::types();
		$status = array_values( array_diff( get_post_stati(), array( 'auto-draft', 'inherit' ) ) );
		if ( 0 === $after ) {
			// A run from the start resets the flag to 0 instead of deleting it: the option then exists, which the last
			// batch takes as proof that this run covered every post from ID 1. A run resumed from a later ID finds no
			// option and leaves the flag alone, and a deactivation in between deletes it, so that run never counts.
			update_option( self::BUILT, 0, false );
		}
		$ids = array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset paging; WP_Query pages only by OFFSET.
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE ID > %d AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $status ), '%s' ) ) . ') ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
					array_merge( array( $wpdb->posts, $after ), $types, $status, array( $limit ) )
				)
			)
		);
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				self::index( $id, $post );
			}
		}
		$done = count( $ids ) < $limit;
		if ( $done && false !== get_option( self::BUILT ) ) {
			update_option( self::BUILT, 1, false );
		}
		return array(
			'done' => $done,
			'next' => $ids ? end( $ids ) : $after,
		);
	}

	/**
	 * Whether every existing post went through the index once.
	 */
	public static function is_built(): bool {
		return (bool) get_option( self::BUILT );
	}

	/**
	 * Posts and theme templates that use a pattern directly, as far as the current user may read them.
	 *
	 * @param string    $ref  Pattern reference.
	 * @param bool|null $more Set to true when more than LIMIT posts use the pattern and the list is cut.
	 * @param-out bool $more
	 * @return list<array{id: int|string, type: string, type_label: string, title: string, status: string, status_label: string, edit_url: string}>
	 */
	public static function used_by( string $ref, ?bool &$more = null ): array {
		$query = array(
			'post_type'              => self::types(),
			'post_status'            => 'any',
			'posts_per_page'         => self::LIMIT + 1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_key'               => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup, see index().
			'meta_value'             => $ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Indexed lookup, see index().
			// Templates and template parts in the database belong to a theme; only those of the active theme count.
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Few template posts.
				'relation' => 'OR',
				array(
					'taxonomy' => 'wp_theme',
					'operator' => 'NOT EXISTS',
				),
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				),
			),
		);
		// Filter in SQL before the limit, so unreadable drafts and private posts of others cannot push readable ones out
		// of the list. Capabilities differ per post type: every status of the types whose private posts and posts of
		// others the user may handle, only public posts of the other types, plus every own post.
		$full  = array_values(
			array_filter(
				$query['post_type'],
				static function ( string $type ): bool {
					$object = get_post_type_object( $type );
					return $object && current_user_can( $object->cap->read_private_posts ) && current_user_can( $object->cap->edit_others_posts );
				}
			)
		);
		$rest  = array_values( array_diff( $query['post_type'], $full ) );
		$found = array();
		if ( $full ) {
			$found[] = get_posts( array( 'post_type' => $full ) + $query );
		}
		if ( $rest ) {
			$found[] = get_posts(
				array(
					'post_type'   => $rest,
					'post_status' => array_values( get_post_stati( array( 'public' => true ) ) ),
				) + $query
			);
			$found[] = get_posts(
				array(
					'post_type' => $rest,
					'author'    => get_current_user_id(),
				) + $query
			);
		}
		$posts = array();
		foreach ( array_merge( ...$found ) as $post ) {
			$posts[ $post->ID ] = $post;
		}
		usort( $posts, static fn( \WP_Post $a, \WP_Post $b ): int => strcasecmp( $a->post_title, $b->post_title ) );
		$more  = count( $posts ) > self::LIMIT;
		$posts = array_slice( $posts, 0, self::LIMIT );
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$type    = get_post_type_object( $post->post_type );
			$status  = get_post_status_object( $post->post_status );
			$items[] = array(
				'id'           => $post->ID,
				'type'         => $post->post_type,
				'type_label'   => $type ? (string) $type->labels->singular_name : $post->post_type,
				'title'        => get_the_title( $post ),
				'status'       => $post->post_status,
				'status_label' => $status ? (string) $status->label : $post->post_status,
				'edit_url'     => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			);
		}
		foreach ( self::theme_files() as $id => $file ) {
			if ( ! in_array( $ref, $file['refs'], true ) ) {
				continue;
			}
			$type    = get_post_type_object( $file['template']->type );
			$items[] = array(
				'id'           => $id,
				'type'         => $file['template']->type,
				'type_label'   => $type ? (string) $type->labels->singular_name : $file['template']->type,
				'title'        => (string) $file['template']->title,
				'status'       => 'publish',
				'status_label' => __( 'Theme file', 'leitvia-studio' ),
				'edit_url'     => current_user_can( 'edit_theme_options' ) ? add_query_arg(
					array(
						'postType' => $file['template']->type,
						'postId'   => rawurlencode( $id ),
					),
					admin_url( 'site-editor.php' )
				) : '',
			);
		}
		return $items;
	}

	/**
	 * Patterns a pattern uses directly.
	 *
	 * @param string $ref Pattern reference.
	 * @return list<string>
	 */
	public static function uses( string $ref ): array {
		return Status::parts( $ref );
	}

	/**
	 * Least mature effective status of the patterns a post uses, '' when it uses none. A deprecated pattern counts
	 * as draft, as it does inside patterns.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function post_status( int $post_id ): string {
		$result = '';
		foreach ( (array) get_post_meta( $post_id, self::META, false ) as $ref ) {
			$status = Status::effective( (string) $ref );
			$status = 'deprecated' === $status ? 'draft' : $status;
			if ( '' === $result || Status::RANK[ $status ] < Status::RANK[ $result ] ) {
				$result = $status;
			}
		}
		return $result;
	}

	/**
	 * Theme templates and template parts that exist only as files, with the patterns they use.
	 *
	 * @return array<string, array{template: \WP_Block_Template, refs: list<string>}>
	 */
	private static function theme_files(): array {
		$theme = get_stylesheet();
		if ( ! isset( self::$files[ $theme ] ) ) {
			self::$files[ $theme ] = array();
			foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
				foreach ( get_block_templates( array(), $type ) as $template ) {
					// Templates edited in the site editor live in the database and are indexed like posts.
					if ( ! $template->wp_id ) {
						self::$files[ $theme ][ $template->id ] = array(
							'template' => $template,
							'refs'     => Status::refs( (string) $template->content ),
						);
					}
				}
			}
		}
		return self::$files[ $theme ];
	}
}
