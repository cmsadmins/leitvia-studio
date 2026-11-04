<?php
/**
 * Animation on scroll for every block: the attribute leitviaAnimation and its classes on the rendered block.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Every block with markup of its own gets the attribute leitviaAnimation (none, fade, rise, stagger). The saved markup
 * stays as it is, so no block becomes invalid: only the block comment carries the value, and render_block adds the
 * classes has-lv-animation and is-lv-animation-<effect> to the outer element on the front end. Style and script are
 * those of the reveal block (src/blocks/reveal/style.scss and view.js), one source for both: the block appears once
 * when it scrolls into view, opacity and transform only, 600 ms. Only blocks below the visible area are hidden until
 * then; without JavaScript, with reduced motion and in the editor the content is always visible. The editor panel is
 * src/editor/animation.js.
 */
final class Animation {

	/**
	 * Effects besides none.
	 */
	public const EFFECTS = array( 'fade', 'rise', 'stagger' );

	/**
	 * Blocks without an outer element of their own, whose markup is not theirs to change, that animate already, or
	 * that belong to the header and navigation, where motion only distracts. src/editor/animation.js keeps the same
	 * list (tests/unit/animation.test.mjs compares them).
	 */
	public const EXCLUDED = array(
		'core/freeform',
		'core/html',
		'core/shortcode',
		'core/missing',
		'core/block',
		'core/pattern',
		'core/template-part',
		'core/post-content',
		'core/more',
		'core/nextpage',
		'core/legacy-widget',
		'core/widget-group',
		'core/navigation',
		'core/navigation-link',
		'core/navigation-submenu',
		'core/home-link',
		'core/page-list',
		'core/page-list-item',
		'leitvia/reveal',
		'leitvia/mega-menu',
	);

	/**
	 * When the attribute fits, for agents: the block catalog copies the description of each attribute.
	 */
	private const DESCRIPTION = 'Every block accepts leitviaAnimation, also core blocks: fade, rise or stagger (the direct children one after another, up to the sixth) once as the block scrolls into view. Use it sparingly, for one or two sections of a landing page; never in the header, the navigation or content needed right away. Visitors with reduced motion and pages without JavaScript show the block without the effect.';

	/**
	 * Registers the attribute on every block but the excluded ones, so the REST API and ServerSideRender accept it.
	 * Runs on register_block_type_args.
	 *
	 * @param array<string, mixed> $args Block type arguments.
	 * @param string               $name Block name.
	 * @return array<string, mixed>
	 */
	public static function block_args( array $args, string $name ): array {
		if ( in_array( $name, self::EXCLUDED, true ) ) {
			return $args;
		}
		$args['attributes']                     = (array) ( $args['attributes'] ?? array() );
		$args['attributes']['leitviaAnimation'] = array(
			'type'        => 'string',
			'enum'        => array_merge( array( 'none' ), self::EFFECTS ),
			'default'     => 'none',
			'description' => self::DESCRIPTION,
		);
		return $args;
	}

	/**
	 * Adds the classes of the effect to the outer element and loads style and script of the reveal block, only on pages
	 * that use an effect. Block themes render the template before wp_head, so the style lands in the head. Runs on
	 * render_block.
	 *
	 * @param string               $content Block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string
	 */
	public static function render( string $content, array $block ): string {
		$effect = $block['attrs']['leitviaAnimation'] ?? null;
		if ( ! in_array( $effect, self::EFFECTS, true ) || in_array( (string) $block['blockName'], self::EXCLUDED, true ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() ) {
			return $content;
		}
		$tags->add_class( 'has-lv-animation' );
		$tags->add_class( 'is-lv-animation-' . $effect );
		wp_enqueue_style( generate_block_asset_handle( 'leitvia/reveal', 'style' ) );
		wp_enqueue_script_module( 'leitvia-reveal-view-script-module' );
		return $tags->get_updated_html();
	}
}
