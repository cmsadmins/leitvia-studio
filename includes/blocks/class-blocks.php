<?php
/**
 * Studio blocks: every folder in build/blocks/ becomes an entry of the blocks registry.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

use LeitviaStudio\Extension\Studio;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the built blocks with the Extension API, which hands them to core on init (Studio::register_blocks()).
 */
final class Blocks {

	/**
	 * Registers the manifest of build/blocks/ with core, then one blocks entry leitvia/<folder> per block folder.
	 * Runs on leitvia_studio_init. The registry only accepts the prefix leitvia/ for the owner leitvia-studio, which
	 * is also the namespace of the block names. The product comparison needs WooCommerce and is left out without it.
	 *
	 * @param Studio $studio The Studio instance.
	 */
	public static function register( Studio $studio ): void {
		$dir      = dirname( LEITVIA_STUDIO_FILE ) . '/build/blocks';
		$manifest = dirname( LEITVIA_STUDIO_FILE ) . '/build/blocks-manifest.php';
		if ( ! is_readable( $manifest ) ) {
			return;
		}
		wp_register_block_metadata_collection( $dir, $manifest );
		$files = glob( $dir . '/*/block.json' );
		foreach ( false === $files ? array() : $files as $file ) {
			$path = dirname( $file );
			if ( 'product-compare' === basename( $path ) && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			$studio->registry( 'blocks' )->register( 'leitvia/' . strtolower( basename( $path ) ), array( 'path' => $path ), 'leitvia-studio' );
		}
	}

	/**
	 * Registers every patterns/<name>.php as leitvia-studio/<name>. Each file returns the pattern properties. Runs on
	 * init.
	 */
	public static function patterns(): void {
		$files = glob( dirname( LEITVIA_STUDIO_FILE ) . '/patterns/*.php' );
		foreach ( false === $files ? array() : $files as $file ) {
			register_block_pattern( 'leitvia-studio/' . basename( $file, '.php' ), require $file );
		}
	}

	/**
	 * Adds the inserter category leitvia. Runs on block_categories_all.
	 *
	 * @param array<int, array<string, mixed>> $categories Block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public static function category( array $categories ): array {
		if ( in_array( 'leitvia', array_column( $categories, 'slug' ), true ) ) {
			return $categories;
		}
		$categories[] = array(
			'slug'  => 'leitvia',
			'title' => leitvia_studio_brand_short(),
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * Loads assets/highlight.css for the format "Highlight" on the front end while a rendered block contains the class
	 * leitvia-highlight. Block templates render before wp_head, so the style still lands in the head. Runs on
	 * render_block.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public static function highlight_style( string $content ): string {
		if ( str_contains( $content, 'leitvia-highlight' ) ) {
			self::enqueue_highlight_style();
		}
		return $content;
	}

	/**
	 * Loads assets/highlight.css in the editor canvas. Runs on enqueue_block_assets, which also fires on the front end.
	 */
	public static function editor_highlight_style(): void {
		if ( is_admin() ) {
			self::enqueue_highlight_style();
		}
	}

	/**
	 * Enqueues the style of the format "Highlight".
	 */
	private static function enqueue_highlight_style(): void {
		wp_enqueue_style( 'leitvia-studio-highlight', plugins_url( 'assets/highlight.css', LEITVIA_STUDIO_FILE ), array(), LEITVIA_STUDIO_VERSION );
	}

	/**
	 * Gives the period switch of each rendered pricing block its own radio group. A duplicated block keeps the saved
	 * name of the original, and two blocks with one name would share a single group on the page. Runs on
	 * render_block_leitvia/pricing.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public static function unique_pricing_group( string $content ): string {
		$tags = new \WP_HTML_Tag_Processor( $content );
		$name = wp_unique_id( 'leitvia-pricing-' );
		while ( $tags->next_tag( array( 'tag_name' => 'input' ) ) ) {
			if ( 'radio' === $tags->get_attribute( 'type' ) && str_starts_with( (string) $tags->get_attribute( 'name' ), 'leitvia-pricing-' ) ) {
				$tags->set_attribute( 'name', $name );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Gives the points of each rendered spotlight one shared details name, so the browser keeps one point open at a
	 * time on its own: at once, also without JavaScript, and without the race of two toggle events. The view script
	 * stays for browsers without exclusive details. Runs on render_block_leitvia/spotlight.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public static function spotlight_group( string $content ): string {
		$tags = new \WP_HTML_Tag_Processor( $content );
		$name = wp_unique_id( 'leitvia-spotlight-' );
		while ( $tags->next_tag( array( 'tag_name' => 'details' ) ) ) {
			if ( $tags->has_class( 'wp-block-leitvia-spotlight-hotspot' ) ) {
				$tags->set_attribute( 'name', $name );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Lets users without unfiltered_html save the radios and checkboxes of the pricing switch, the feature filter and
	 * the wizard steps. Only type, name, value, checked and required, and type only as radio or checkbox: an input
	 * without one of these types is removed. Text and password fields would let such a user build a login form that
	 * the browser's password manager fills in and that sends to another host (core allows form and button). Event
	 * handlers, style, formaction and src stay out. Runs on wp_kses_allowed_html.
	 *
	 * @param array<string, mixed> $tags    Allowed tags and attributes.
	 * @param string|mixed         $context Kses context.
	 * @return array<string, mixed>
	 */
	public static function allow_inputs( array $tags, $context ): array {
		if ( 'post' === $context && ! isset( $tags['input'] ) ) {
			$tags['input'] = array(
				'type'     => array(
					'required' => true,
					'values'   => array( 'radio', 'checkbox' ),
				),
				'name'     => true,
				'value'    => true,
				'checked'  => true,
				'required' => true,
			);
		}
		return $tags;
	}
}
