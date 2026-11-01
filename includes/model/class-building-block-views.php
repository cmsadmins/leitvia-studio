<?php
/**
 * Building blocks in the editor, on the front end, in the list of their types and in the inserter.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

use LeitviaStudio\Gateway\Abilities\Build;
use LeitviaStudio\Gateway\Building_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the patterns of a building block with its content types (see Building_Blocks::needs_of_pattern()):
 * - editor_data() is window.leitviaStudioNeeds of the post and site editor; src/editor/building-blocks.js shows a
 *   notice with "Set up <title>" above a section whose building block is missing.
 * - hide_unmet() renders such a section as nothing on the front end, so visitors see no heading without content.
 * - list_notice() shows on the list screen of a type of an installed block how to put it on a page; new_page() creates
 *   the draft page with the first pattern of the block.
 * - categories() adds an inserter category per block with its patterns, for the pattern routes of the editor.
 */
final class Building_Block_Views {

	/**
	 * Action of admin-post.php that creates a page with a section of a block.
	 */
	public const PAGE_ACTION = 'leitvia_studio_block_page';

	/**
	 * Query argument of post-new.php that opens the inserter on the pattern category of a block.
	 */
	public const INSERTER_ARG = 'leitvia-inserter';

	/**
	 * Inserter category of a block: leitvia-block-<owner>-<name>.
	 *
	 * @param string $name Registry name of the block.
	 */
	public static function category( string $name ): string {
		return 'leitvia-block-' . sanitize_title( str_replace( '/', '-', $name ) );
	}

	/**
	 * Data of window.leitviaStudioNeeds, null while no building block is registered: patterns (by pattern name the
	 * block it needs with buildingBlock, title and missing), blocks (name, title, installed, category), postTypes (by
	 * post type the block that declares it) and canSetup (Building_Blocks::can_set_up(), as add-building-block asks).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function editor_data(): ?array {
		$registered = leitvia_studio()->registry( 'building_blocks' )->all();
		if ( ! $registered ) {
			return null;
		}
		$blocks = array();
		$types  = array();
		$names  = array_keys( array_filter( leitvia_studio()->registry( 'patterns' )->all(), static fn( array $entry ): bool => isset( $entry['needs'] ) ) );
		foreach ( $registered as $name => $block ) {
			$blocks[ $name ] = array(
				'name'      => $name,
				'title'     => (string) $block['title'],
				'installed' => Building_Blocks::installed( $block ),
				'category'  => self::category( $name ),
			);
			$types          += array_fill_keys( array_keys( (array) ( $block['model']['postTypes'] ?? array() ) ), $name );
			$names           = array_merge( $names, (array) ( $block['patterns'] ?? array() ) );
		}
		$patterns = array();
		foreach ( array_unique( $names ) as $pattern ) {
			$needed = (string) ( Building_Blocks::needs_of_pattern( (string) $pattern )['buildingBlock'] ?? '' );
			if ( isset( $blocks[ $needed ] ) ) {
				$patterns[ $pattern ] = array(
					'buildingBlock' => $needed,
					'title'         => $blocks[ $needed ]['title'],
					'missing'       => ! $blocks[ $needed ]['installed'],
				);
			}
		}
		return array(
			'patterns'  => (object) $patterns,
			'blocks'    => array_values( $blocks ),
			'postTypes' => (object) $types,
			'canSetup'  => Building_Blocks::can_set_up(),
		);
	}

	/**
	 * Renders a section (a block with metadata.patternName) whose needs are missing as nothing on the front end: a
	 * registered building block that is not installed, or explicit post types, taxonomies or settings that fail. A block
	 * that is not registered (add-on gone, kit locked) hides nothing, so content already on the site keeps rendering.
	 * The editor, REST requests (server-side renders of the editor among them) and the admin see it. Runs on
	 * render_block.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function hide_unmet( string $content, array $block ): string {
		$name = (string) ( $block['attrs']['metadata']['patternName'] ?? '' );
		if ( '' === $name || is_admin() || wp_is_serving_rest_request() ) {
			return $content;
		}
		return Building_Blocks::missing( Building_Blocks::needs_of_pattern( $name ) ) ? '' : $content;
	}

	/**
	 * The first pattern of a block that is registered, '' for none.
	 *
	 * @param array<string, mixed> $block Registry entry.
	 */
	private static function first_pattern( array $block ): string {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		foreach ( (array) ( $block['patterns'] ?? array() ) as $pattern ) {
			if ( $registry->is_registered( (string) $pattern ) ) {
				return (string) $pattern;
			}
		}
		return '';
	}

	/**
	 * On the list screen of a type of an installed block, in the bar above the list next to the filters: "Show <type>
	 * on a page:" with "New page with this section" and "Insert in the editor", a group named by its text. No admin
	 * notice (WordPress.org guideline 11); the class "actions" stays off, core hides that bar part on phones.
	 * Runs on manage_posts_extra_tablenav.
	 *
	 * @param string $which top or bottom; only the top bar shows it.
	 */
	public static function list_notice( string $which = 'top' ): void {
		$screen = get_current_screen();
		if ( 'top' !== $which || ! $screen || 'edit' !== $screen->base || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$name  = Building_Blocks::of_post_type( (string) $screen->post_type );
		$block = '' !== $name ? leitvia_studio()->registry( 'building_blocks' )->get( $name ) : null;
		$type  = get_post_type_object( (string) $screen->post_type );
		if ( ! $block || ! $type || ! Building_Blocks::installed( $block ) || '' === self::first_pattern( $block ) ) {
			return;
		}
		$new    = wp_nonce_url(
			add_query_arg(
				array(
					'action'         => self::PAGE_ACTION,
					'building_block' => $name,
				),
				admin_url( 'admin-post.php' )
			),
			self::PAGE_ACTION . '_' . $name
		);
		$insert = add_query_arg(
			array(
				'post_type'        => 'page',
				self::INSERTER_ARG => self::category( $name ),
			),
			admin_url( 'post-new.php' )
		);
		printf(
			'<div class="alignleft leitvia-studio-block-notice" role="group" aria-labelledby="leitvia-studio-block-notice-label"><span id="leitvia-studio-block-notice-label">%s</span> <a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></div>',
			/* translators: %s: plural label of the post type, for example Features. */
			esc_html( sprintf( __( 'Show %s on a page:', 'leitvia-studio' ), $type->labels->name ) ),
			esc_url( $new ),
			esc_html__( 'New page with this section', 'leitvia-studio' ),
			esc_url( $insert ),
			esc_html__( 'Insert in the editor', 'leitvia-studio' )
		);
	}

	/**
	 * Creates a draft page with the first pattern of a block as its section, built like a section of
	 * leitvia/compose-page (metadata.patternName on the root blocks).
	 *
	 * @param string $name Registry name of the block.
	 * @return int|\WP_Error Page ID.
	 */
	public static function create_page( string $name ) {
		$block   = leitvia_studio()->registry( 'building_blocks' )->get( $name );
		$pattern = $block ? self::first_pattern( $block ) : '';
		if ( ! $block || '' === $pattern ) {
			return new \WP_Error( 'leitvia_no_block_pattern', __( 'This building block has no section to put on a page.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		$sections = Build::sections( array( array( 'pattern' => $pattern ) ) );
		$content  = is_wp_error( $sections ) ? $sections : Build::compose( $sections );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		return wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => (string) $block['title'],
				'post_content' => $content,
			),
			true
		);
	}

	/**
	 * Handles "New page with this section": checks nonce and edit_pages, creates the draft and opens it in the editor.
	 * Runs on admin_post_leitvia_studio_block_page.
	 */
	public static function new_page(): void {
		$name = isset( $_GET['building_block'] ) ? sanitize_text_field( wp_unslash( $_GET['building_block'] ) ) : '';
		check_admin_referer( self::PAGE_ACTION . '_' . $name );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You may not create pages.', 'leitvia-studio' ), '', array( 'response' => 403 ) );
		}
		$page = self::create_page( $name );
		if ( is_wp_error( $page ) ) {
			wp_die( esc_html( $page->get_error_message() ), '', array( 'response' => 404 ) );
		}
		wp_safe_redirect( (string) get_edit_post_link( $page, 'url' ) );
		exit;
	}

	/**
	 * Runs categories() before a request to the pattern routes the editor reads (/wp/v2/block-patterns/patterns and
	 * /wp/v2/block-patterns/categories), preloaded ones included; every other REST request leaves the registry alone.
	 * Runs on rest_request_before_callbacks.
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed The response, unchanged.
	 */
	public static function pattern_request( $response, $handler, $request ) {
		unset( $handler );
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wp/v2/block-patterns/' ) ) {
			self::categories();
		}
		return $response;
	}

	/**
	 * Adds an inserter category per block (label: its title) and puts its registered patterns into it. Core sets the
	 * categories of a pattern when it registers, so a pattern without the category registers again with it, once.
	 * Idempotent, see pattern_request().
	 */
	public static function categories(): void {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		foreach ( leitvia_studio()->registry( 'building_blocks' )->all() as $name => $block ) {
			$slug = self::category( $name );
			if ( ! \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $slug ) ) {
				register_block_pattern_category( $slug, array( 'label' => (string) $block['title'] ) );
			}
			foreach ( (array) ( $block['patterns'] ?? array() ) as $pattern ) {
				$props = $registry->get_registered( (string) $pattern );
				if ( ! $props || in_array( $slug, (array) ( $props['categories'] ?? array() ), true ) ) {
					continue;
				}
				$props['categories'] = array_merge( (array) ( $props['categories'] ?? array() ), array( $slug ) );
				$registry->unregister( (string) $pattern );
				$registry->register( (string) $pattern, $props );
			}
		}
	}
}
