<?php
/**
 * Mega menu: a navigation item that opens a template part as its panel.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders leitvia/mega-menu and registers the template part area mega-menu for its panels.
 *
 * What core 7.1.3 does, checked on the system before building this:
 * - core/navigation lists its children in allowedBlocks. A block that names core/navigation in `parent` is allowed
 *   too (the editor accepts a block when either list allows it). On the front end the navigation puts every child
 *   that renders an <li> into its list, so this block renders an <li> itself.
 * - Submenus with submenuVisibility "click" are buttons bound to state.isSubmenuOpen of the store core/navigation.
 *   Escape closes an open submenu and returns focus to its toggle; the store is locked, so other code cannot reuse it.
 * - In the overlay (overlayMenu "mobile" or "always", core/navigation-overlay-close in custom overlays) the getter
 *   isSubmenuOpen is true for every submenu while the overlay is open, and core's CSS shows every submenu and hides
 *   the chevrons. A long menu becomes one flat list in which group labels read like links. view.js makes those
 *   submenus collapsible in navigations that hold a mega menu or are too long to scan (collapsible_overlay()) and
 *   leaves everything outside the overlay to core.
 */
final class Mega_Menu {

	/**
	 * Adds the area mega-menu. Runs on default_wp_template_part_areas.
	 *
	 * @param array<int, array<string, string>> $areas Template part areas.
	 * @return array<int, array<string, string>>
	 */
	public static function areas( array $areas ): array {
		$areas[] = array(
			'area'        => 'mega-menu',
			'label'       => _x( 'Mega menu', 'template part area', 'leitvia-studio' ),
			'description' => __( 'Panels of mega menu items in the navigation: columns with headings and links, designed in the Site Editor.', 'leitvia-studio' ),
			'icon'        => 'layout',
			'area_tag'    => 'div',
		);
		return $areas;
	}

	/**
	 * Gives the block its render callback. Runs on register_block_type_args.
	 *
	 * @param array<string, mixed> $args Block type arguments.
	 * @param string               $name Block name.
	 * @return array<string, mixed>
	 */
	public static function block_args( array $args, string $name ): array {
		if ( 'leitvia/mega-menu' === $name ) {
			$args['render_callback'] = array( self::class, 'render' );
		}
		return $args;
	}

	/**
	 * Without the template part the item is only the link; the panel and its button stay hidden until view.js runs.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$label       = (string) ( $attributes['label'] ?? '' );
		$url         = (string) ( $attributes['url'] ?? '' );
		$slug        = sanitize_title( (string) ( $attributes['slug'] ?? '' ) );
		$description = (string) ( $attributes['description'] ?? '' );
		$text        = '<span class="wp-block-navigation-item__label">' . esc_html( $label ) . '</span>'
			. ( '' === $description ? '' : '<span class="wp-block-navigation-item__description">' . esc_html( $description ) . '</span>' );
		$html        = '' === $url
			? '<span class="wp-block-navigation-item__content">' . $text . '</span>'
			: '<a class="wp-block-navigation-item__content" href="' . esc_url( $url ) . '">' . $text . '</a>';

		$part = '' === $slug ? null : get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
		if ( $part ) {
			$id    = wp_unique_id( 'leitvia-mega-menu-' );
			$html .= sprintf(
				'<button type="button" class="wp-block-leitvia-mega-menu__toggle" aria-expanded="false" aria-controls="%1$s" aria-label="%2$s" hidden><svg viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M1.5 4L6 8l4.5-4" stroke-width="1.5"></path></svg></button><div id="%1$s" class="wp-block-leitvia-mega-menu__panel" hidden>%3$s</div>',
				esc_attr( $id ),
				/* translators: %s: Menu item text. */
				esc_attr( sprintf( __( '%s submenu', 'leitvia-studio' ), wp_strip_all_tags( $label ) ) ),
				do_blocks(
					get_comment_delimited_block_content(
						'core/template-part',
						array(
							'slug'  => $slug,
							'theme' => get_stylesheet(),
						),
						''
					)
				)
			);
		}
		return '<li ' . get_block_wrapper_attributes( array( 'class' => 'wp-block-navigation-item' ) ) . '>' . $html . '</li>';
	}

	/**
	 * Marks a navigation whose overlay gets collapsible submenus and loads the view module and style for it. On by
	 * default for a navigation with a mega menu, a submenu inside a submenu or more than 12 links in submenus; the
	 * filter leitvia_studio_collapsible_overlay decides last. Without JavaScript nothing changes. Runs on
	 * render_block_core/navigation.
	 *
	 * @param string               $content Rendered navigation.
	 * @param array<string, mixed> $block   Parsed navigation block.
	 */
	public static function collapsible_overlay( string $content, array $block ): string {
		$attrs = (array) ( $block['attrs'] ?? array() );
		if ( 'never' === ( $attrs['overlayMenu'] ?? 'mobile' ) ) {
			return $content;
		}
		$inner = (array) ( $block['innerBlocks'] ?? array() );
		if ( ! $inner ) {
			// Core renders the referenced navigation or, without a reference, its fallback.
			$post  = empty( $attrs['ref'] ) ? \WP_Navigation_Fallback::get_fallback() : get_post( (int) $attrs['ref'] );
			$inner = $post instanceof \WP_Post ? parse_blocks( $post->post_content ) : array();
		}
		$count = self::count( $inner, 0 );
		/**
		 * Filters whether the overlay of this navigation gets collapsible submenus.
		 *
		 * @param bool                 $collapsible Default: a mega menu, nested submenus or more than 12 submenu links.
		 * @param array<string, mixed> $block       Parsed navigation block.
		 */
		if ( ! apply_filters( 'leitvia_studio_collapsible_overlay', $count['mega'] || $count['nested'] || $count['links'] > 12, $block ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag( array( 'tag_name' => 'nav' ) ) ) {
			return $content;
		}
		$tags->set_attribute( 'data-leitvia-collapsible', '' );
		wp_enqueue_script_module( 'leitvia-mega-menu-view-script-module' );
		wp_enqueue_style( 'leitvia-mega-menu-style' );
		return $tags->get_updated_html();
	}

	/**
	 * Counts in a list of parsed blocks: mega menus, submenus inside submenus and links inside submenus.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @param int               $depth  Number of submenus around the list.
	 * @return array{mega: bool, nested: bool, links: int}
	 */
	private static function count( array $blocks, int $depth ): array {
		$count = array(
			'mega'   => false,
			'nested' => false,
			'links'  => 0,
		);
		foreach ( $blocks as $inner ) {
			$name = (string) ( $inner['blockName'] ?? '' );
			$sub  = 'core/navigation-submenu' === $name;
			if ( $depth > 0 && ( $sub || 'core/navigation-link' === $name ) ) {
				++$count['links'];
			}
			$found           = self::count( (array) ( $inner['innerBlocks'] ?? array() ), $depth + ( $sub ? 1 : 0 ) );
			$count['mega']   = $count['mega'] || $found['mega'] || 'leitvia/mega-menu' === $name;
			$count['nested'] = $count['nested'] || $found['nested'] || ( $sub && $depth > 0 );
			$count['links'] += $found['links'];
		}
		return $count;
	}
}
