<?php
/**
 * Switching between a Leitvia theme and its child theme without losing the design.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Contract;

use LeitviaStudio\Lab\Usage_Index;
use LeitviaStudio\Tokens\Compiler;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress keeps the global styles (wp_global_styles) and the templates and template parts edited in the site editor
 * (wp_template, wp_template_part) per stylesheet, through the term of the taxonomy wp_theme. A switch from Leitvia to a
 * child theme of Leitvia, or back, would leave the site without them. This copies them to the new stylesheet when it
 * has none of its own; the old ones stay, so switching back finds them unchanged. Nothing in the target is ever
 * overwritten.
 *
 * Navigation menus (wp_navigation) belong to no theme and parts point to them by ID, the token overlays cache by
 * stylesheet and compile again, and the copies go through save_post, which puts them into the usage index of the Lab.
 */
final class Child_Theme {

	/**
	 * Post statuses core reads templates in, see get_block_templates().
	 */
	private const STATUSES = array( 'auto-draft', 'draft', 'publish' );

	/**
	 * Post meta that is not copied: the usage index is rebuilt on save_post, edit locks belong to the original.
	 */
	private const SKIP_META = array( Usage_Index::META, '_edit_lock', '_edit_last' );

	/**
	 * Copies global styles, templates and template parts. Runs on switch_theme, after core saved the new theme.
	 *
	 * @param string    $name      Name of the new theme.
	 * @param \WP_Theme $new_theme New theme.
	 * @param \WP_Theme $old_theme Old theme.
	 */
	public static function carry_over( string $name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
		unset( $name );
		$from = $old_theme->get_stylesheet();
		$to   = $new_theme->get_stylesheet();
		if ( $from === $to || ! self::related( $new_theme, $old_theme ) ) {
			return;
		}
		$styles = self::copy_styles( $from, $to );
		foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
			self::copy_templates( $type, $from, $to );
		}
		// The applied hash of the active token set belongs to the stylesheet it was written for (Compiler::drift()).
		$applied = get_option( Compiler::HASH_OPTION );
		if ( $styles && is_array( $applied ) && ( $applied['theme'] ?? null ) === $from ) {
			update_option( Compiler::HASH_OPTION, array( 'theme' => $to ) + $applied );
		}
		\WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}

	/**
	 * Whether one theme is the parent of the other and that parent ships a Leitvia contract.
	 *
	 * @param \WP_Theme $a One theme.
	 * @param \WP_Theme $b The other theme.
	 */
	private static function related( \WP_Theme $a, \WP_Theme $b ): bool {
		$parent = $a->get_template();
		return $parent === $b->get_template()
			&& in_array( $parent, array( $a->get_stylesheet(), $b->get_stylesheet() ), true )
			&& is_file( trailingslashit( $a->get_template_directory() ) . Contract::FILE );
	}

	/**
	 * Posts of a type that belong to a stylesheet, newest first.
	 *
	 * @param string   $type       Post type.
	 * @param string   $stylesheet Stylesheet.
	 * @param string[] $statuses   Post statuses.
	 * @param string   $slug       Post name, empty for all.
	 * @return list<\WP_Post>
	 */
	private static function posts( string $type, string $stylesheet, array $statuses, string $slug = '' ): array {
		$query = array(
			'post_type'      => $type,
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Few template posts, once per theme switch.
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $stylesheet,
				),
			),
		);
		if ( '' !== $slug ) {
			$query['post_name__in'] = array( $slug );
		}
		return array_values( get_posts( $query ) );
	}

	/**
	 * Copies the user global styles when the target has none; whether a copy was made.
	 *
	 * @param string $from Old stylesheet.
	 * @param string $to   New stylesheet.
	 */
	private static function copy_styles( string $from, string $to ): bool {
		$source = self::posts( 'wp_global_styles', $from, array( 'publish' ) )[0] ?? null;
		if ( ! $source || self::posts( 'wp_global_styles', $to, array( 'publish' ) ) ) {
			return false;
		}
		return (bool) self::copy(
			$source,
			$to,
			array( 'post_name' => 'wp-global-styles-' . rawurlencode( $to ) )
		);
	}

	/**
	 * Copies the templates or template parts the target does not have yet.
	 *
	 * @param string $type wp_template or wp_template_part.
	 * @param string $from Old stylesheet.
	 * @param string $to   New stylesheet.
	 */
	private static function copy_templates( string $type, string $from, string $to ): void {
		$done = array();
		foreach ( self::posts( $type, $from, self::STATUSES ) as $source ) {
			// The newest post of a slug is the one core shows.
			if ( isset( $done[ $source->post_name ] ) || self::posts( $type, $to, array_merge( self::STATUSES, array( 'trash' ) ), $source->post_name ) ) {
				continue;
			}
			$done[ $source->post_name ] = true;
			self::copy(
				$source,
				$to,
				array( 'post_content' => self::retheme( $source->post_content, $from, $to ) )
			);
		}
	}

	/**
	 * Template part blocks that name the old stylesheet name the new one; core renders a part only for the active one.
	 *
	 * @param string $content Block markup.
	 * @param string $from    Old stylesheet.
	 * @param string $to      New stylesheet.
	 */
	private static function retheme( string $content, string $from, string $to ): string {
		return (string) preg_replace_callback(
			'/<!-- wp:template-part \{[^\n]*?\} \/?-->/',
			static fn( array $m ): string => str_replace( '"theme":' . wp_json_encode( $from ), '"theme":' . wp_json_encode( $to ), $m[0] ),
			$content
		);
	}

	/**
	 * Copies a post with its meta and terms to another stylesheet; the new post ID, 0 on failure.
	 *
	 * @param \WP_Post             $source Post to copy.
	 * @param string               $to     New stylesheet.
	 * @param array<string, mixed> $fields Fields that differ from the source.
	 */
	private static function copy( \WP_Post $source, string $to, array $fields ): int {
		// The source passed the content filters when it was saved. Without a user allowed unfiltered HTML (WP-CLI,
		// cron) kses would filter it again and could change the copy.
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$id = wp_insert_post(
			wp_slash(
				$fields + array(
					'post_type'    => $source->post_type,
					'post_status'  => $source->post_status,
					'post_name'    => $source->post_name,
					'post_title'   => $source->post_title,
					'post_content' => $source->post_content,
					'post_excerpt' => $source->post_excerpt,
					'post_author'  => (int) $source->post_author,
				)
			),
			true
		);
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		// Not tax_input: it needs a user allowed to assign terms, which WP-CLI and cron do not have.
		wp_set_object_terms( $id, $to, 'wp_theme' );
		$areas = wp_get_object_terms( $source->ID, 'wp_template_part_area', array( 'fields' => 'names' ) );
		if ( is_array( $areas ) && $areas ) {
			wp_set_object_terms( $id, $areas, 'wp_template_part_area' );
		}
		foreach ( (array) get_post_meta( $source->ID ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return $id;
	}
}
