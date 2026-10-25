<?php
/**
 * Single view of entries of the content model.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * A block template single-<type> for every public post type of the content model: title, excerpt, image and content
 * of the entry without the byline and reading time of an article, then the section "More <type>" with other entries
 * of the type. The template of a theme or the Site Editor with the same slug wins. Also the facts list of an entry
 * (block style leitvia-facts of core/group, a description list) and a byline that hides without an author.
 */
final class Entry_View {

	/**
	 * Name of the core/query variation that lists other entries of the type of the current entry.
	 */
	public const MORE = 'leitvia/more-entries';

	/**
	 * Class of the block style "Facts" of core/group.
	 */
	public const FACTS = 'is-style-leitvia-facts';

	/**
	 * Registers the templates of the public post types of the model and the block style "Facts". Runs on init after
	 * Registrar::register().
	 */
	public static function register(): void {
		register_block_style(
			'core/group',
			array(
				'name'  => 'leitvia-facts',
				'label' => __( 'Facts', 'leitvia-studio' ),
			)
		);
		foreach ( (array) ( Model::current()['postTypes'] ?? array() ) as $key => $type ) {
			if ( empty( $type['public'] ) || ! Registrar::owns_post_type( (string) $key ) ) {
				continue;
			}
			register_block_template(
				'leitvia-studio//single-' . $key,
				array(
					/* translators: %s: singular name of a post type. */
					'title'       => sprintf( __( 'Single %s', 'leitvia-studio' ), $type['singularLabel'] ),
					/* translators: %s: plural name of a post type. */
					'description' => sprintf( __( 'One entry of %s with its title, excerpt, image and content, then other entries of the type.', 'leitvia-studio' ), $type['label'] ),
					'content'     => self::template( (string) $key, (string) $type['label'] ),
					'post_types'  => array( (string) $key ),
				)
			);
		}
	}

	/**
	 * Markup of the single template of a post type.
	 *
	 * @param string $key   Post type key.
	 * @param string $label Plural name of the post type.
	 */
	public static function template( string $key, string $label ): string {
		// Sentence case in English ("More courses"), except for a label that starts with an acronym ("FAQs"); other
		// languages keep the label as it is, German nouns among them.
		$name = str_starts_with( get_locale(), 'en' ) && ! preg_match( '/^\p{Lu}{2}/u', $label ) ? lcfirst( $label ) : $label;
		/* translators: %s: plural name of a post type, such as courses. */
		$more = esc_html( sprintf( __( 'More %s', 'leitvia-studio' ), $name ) );
		// Markup in single-entry.html, as the editor saves it; {{key}} and {{more}} are filled in here.
		$markup = (string) file_get_contents( __DIR__ . '/single-entry.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
		return rtrim(
			strtr(
				$markup,
				array(
					'{{key}}'  => $key,
					'{{more}}' => $more,
				)
			)
		);
	}

	/**
	 * Leaves the current entry out of "More <type>". Runs on query_loop_block_query_vars.
	 *
	 * @param array<string, mixed> $query Query vars.
	 * @param \WP_Block            $block Post template block.
	 * @return array<string, mixed>
	 */
	public static function query_vars( array $query, \WP_Block $block ): array {
		if ( self::MORE === ( $block->context['query']['namespace'] ?? '' ) ) {
			// One ID, the entry on screen, in a query of three entries.
			$query['post__not_in'] = array_merge( (array) ( $query['post__not_in'] ?? array() ), array( (int) get_the_ID() ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		}
		return $query;
	}

	/**
	 * Puts the overview page of the type between Home and the entry in the breadcrumbs of an entry of the model, when
	 * the type has no archive. The overview is the first published page with a query of the type (entries.md), not
	 * the front page. Runs on block_core_breadcrumbs_items.
	 *
	 * @param array<int, array<string, mixed>> $items Breadcrumb items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function breadcrumbs( array $items ): array {
		$type = is_singular() ? (string) get_post_type() : '';
		if ( '' === $type || ! Registrar::owns_post_type( $type ) || get_post_type_archive_link( $type ) ) {
			return $items;
		}
		// ponytail: LIKE search over page content per entry view; store the overview page in the model if it gets slow.
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				's'              => '"postType":"' . $type . '"',
				'sentence'       => true,
				'search_columns' => array( 'post_content' ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'numberposts'    => 2,
			)
		);
		// The front page may hold such a query too; two results leave one other page (no exclude, Plugin Check).
		$pages = array_values( array_filter( $pages, static fn( $page ) => (int) get_option( 'page_on_front' ) !== $page->ID ) );
		if ( ! $pages ) {
			return $items;
		}
		$home = isset( $items[0]['url'] ) && home_url( '/' ) === $items[0]['url'] ? 1 : 0;
		array_splice(
			$items,
			$home,
			0,
			array(
				array(
					'label' => get_the_title( $pages[0] ),
					'url'   => get_permalink( $pages[0] ),
				),
			)
		);
		return $items;
	}

	/**
	 * Drops "More <type>" with its heading while there are no other entries. Runs on render_block_core/query.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function hide_empty_more( string $html, array $block ): string {
		return self::MORE === ( $block['attrs']['namespace'] ?? '' ) && ! str_contains( $html, 'wp-block-post-template' ) ? '' : $html;
	}

	/**
	 * A group in the block style "Facts" becomes a description list: the group a dl, in each of its rows the first
	 * paragraph a dt (the label) and the second a dd (the value). Runs on render_block_core/group.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function facts( string $html, array $block ): string {
		if ( ! in_array( self::FACTS, explode( ' ', (string) ( $block['attrs']['className'] ?? '' ) ), true ) ) {
			return $html;
		}
		// Only pages with a facts list get its rule, not every page with a group (inline_style of the block style).
		if ( ! wp_style_is( 'leitvia-studio-facts', 'registered' ) ) {
			wp_register_style( 'leitvia-studio-facts', false, array(), LEITVIA_STUDIO_VERSION );
			wp_add_inline_style( 'leitvia-studio-facts', '.' . self::FACTS . ' dt,.' . self::FACTS . ' dd{margin:0}' );
		}
		wp_enqueue_style( 'leitvia-studio-facts' );
		// ponytail: string surgery on the markup of the pattern (rows of two paragraphs); other content stays as it is.
		$html = (string) preg_replace( '/^(\s*)<div\b/', '$1<dl', $html, 1 );
		$html = (string) preg_replace( '#</div>(\s*)$#', '</dl>$1', $html, 1 );
		return (string) preg_replace( '#<p\b([^>]*)>(.*?)</p>(\s*)<p\b([^>]*)>(.*?)</p>#s', '<dt$1>$2</dt>$3<dd$4>$5</dd>', $html );
	}

	/**
	 * Drops the part of a byline that names the author ("By" and the author block) when the post has no author to
	 * show, as with post types without author support. Runs on render_block_core/group.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function hide_empty_author( string $html, array $block ): string {
		$names = array_column( (array) $block['innerBlocks'], 'blockName' );
		return in_array( 'core/post-author-name', $names, true ) && ! str_contains( $html, 'wp-block-post-author-name' ) ? '' : $html;
	}
}
