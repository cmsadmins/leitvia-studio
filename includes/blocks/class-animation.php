<?php
/**
 * Animation on scroll for every block: the attributes leitviaAnimation and leitviaMotion and their classes on the
 * rendered block.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Every block with markup of its own gets the attributes leitviaAnimation (none or one of EFFECTS) and leitviaMotion
 * (subtle or bold; without it the site default of the Studio settings applies). The saved markup stays as it is, so no
 * block becomes invalid: only the block comment carries the values, and render_block adds the classes
 * has-lv-animation is-lv-animation-<effect> (has-lv-parallax for the scroll-bound parallax) and
 * is-lv-motion-<strength> to the outer element on the front end. Style and script are those of the reveal block
 * (src/blocks/reveal/style.scss and view.js), one source for both: the block appears once when a fifth of it is
 * clearly in view, opacity, transform, clip-path and filter only; One after another watches each child on its own.
 * Only blocks below the visible area are hidden until then; without JavaScript, with reduced motion, with the switch
 * "Motion off" of the accessibility bar and in the editor the content is always visible. The site setting motion
 * "off" loads nothing at all and marks the body with leitvia-motion-off, which the theme reads for its hover motion.
 * The editor panel is src/editor/animation.js.
 */
final class Animation {

	/**
	 * Effects besides none. Parallax moves with the scroll position (cover background, timeline line) and needs no
	 * script.
	 */
	public const EFFECTS = array( 'fade', 'rise', 'stagger', 'zoom', 'slide-start', 'slide-end', 'wipe-up', 'wipe-start', 'blur', 'mask', 'parallax' );

	/**
	 * Strengths: subtle moves 2.5rem in 800 ms, bold twice the way on a longer curve in 1200 ms.
	 */
	public const STRENGTHS = array( 'subtle', 'bold' );

	/**
	 * Site defaults of the setting motion: off loads nothing, the others are the strength of blocks without
	 * leitviaMotion.
	 */
	public const SITE = array( 'off', 'subtle', 'bold' );

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
	private const DESCRIPTION = 'Every block accepts leitviaAnimation, also core blocks: fade, rise, zoom, slide-start, slide-end, blur, wipe-up, wipe-start (headings, images, covers), mask (images) or stagger (the direct children one after another, up to the sixth; a child with an effect of its own keeps it) once as the block scrolls into view, or parallax (the background of a cover, the line of a timeline) while it scrolls. Use it sparingly, for one or two sections of a landing page; never in the first screen, the header, the navigation or content needed right away. Visitors with reduced motion and pages without JavaScript show the block without the effect.';

	/**
	 * Description of leitviaMotion for the block catalog.
	 */
	private const MOTION_DESCRIPTION = 'Strength of leitviaAnimation: subtle (2.5rem in 800 ms) or bold (twice the way, 1200 ms, for lively brands, images and galleries). Leave it out for the site default of the Studio settings.';

	/**
	 * The site setting motion: off, subtle or bold.
	 */
	public static function site(): string {
		$value = leitvia_studio_setting( 'motion' );
		return in_array( $value, self::SITE, true ) ? $value : 'subtle';
	}

	/**
	 * Registers the attributes on every block but the excluded ones, so the REST API and ServerSideRender accept them.
	 * With the site setting motion off the reveal block loads neither style nor script. Runs on
	 * register_block_type_args.
	 *
	 * @param array<string, mixed> $args Block type arguments.
	 * @param string               $name Block name.
	 * @return array<string, mixed>
	 */
	public static function block_args( array $args, string $name ): array {
		if ( 'leitvia/reveal' === $name && 'off' === self::site() ) {
			unset( $args['style_handles'], $args['style'], $args['view_script_module_ids'], $args['view_script_module'] );
		}
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
		$args['attributes']['leitviaMotion']    = array(
			'type'        => 'string',
			'enum'        => self::STRENGTHS,
			'description' => self::MOTION_DESCRIPTION,
		);
		return $args;
	}

	/**
	 * Adds the classes of the effect and its strength to the outer element and loads style and script of the reveal
	 * block, only on pages that use an effect (parallax needs the style only). Block themes render the template before
	 * wp_head, so the style lands in the head. The reveal block gets the strength of the site default. Nothing with the
	 * site setting motion off. Runs on render_block.
	 *
	 * @param string               $content Block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string
	 */
	public static function render( string $content, array $block ): string {
		$site = self::site();
		$name = (string) $block['blockName'];
		if ( 'off' === $site ) {
			return $content;
		}
		if ( 'leitvia/reveal' === $name ) {
			$effect = 'reveal';
		} else {
			$effect = $block['attrs']['leitviaAnimation'] ?? null;
			if ( ! in_array( $effect, self::EFFECTS, true ) || in_array( $name, self::EXCLUDED, true ) ) {
				return $content;
			}
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() ) {
			return $content;
		}
		if ( 'parallax' === $effect ) {
			$tags->add_class( 'has-lv-parallax' );
		} elseif ( 'reveal' !== $effect ) {
			$tags->add_class( 'has-lv-animation' );
			$tags->add_class( 'is-lv-animation-' . $effect );
		}
		$motion = $block['attrs']['leitviaMotion'] ?? null;
		$tags->add_class( 'is-lv-motion-' . ( in_array( $motion, self::STRENGTHS, true ) ? $motion : $site ) );
		wp_enqueue_style( generate_block_asset_handle( 'leitvia/reveal', 'style' ) );
		if ( 'parallax' !== $effect ) {
			self::enqueue_module( 'leitvia/reveal' );
		}
		return $tags->get_updated_html();
	}

	/**
	 * Enqueues the view script module of a registered block by the ID core gave it, not by a handle spelled out here:
	 * core builds that ID itself and may change how.
	 *
	 * @param string $name Block name.
	 */
	public static function enqueue_module( string $name ): void {
		$type   = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$module = $type ? (string) ( $type->view_script_module_ids[0] ?? '' ) : '';
		if ( '' !== $module ) {
			wp_enqueue_script_module( $module );
		}
	}

	/**
	 * Marks the body with leitvia-motion-off while the site setting motion is off, so the theme and other plugins
	 * leave out their motion as well (hover lifts, for example). Runs on body_class.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( array $classes ): array {
		if ( 'off' === self::site() ) {
			$classes[] = 'leitvia-motion-off';
		}
		return $classes;
	}

	/**
	 * Block attributes with new values for leitviaAnimation and leitviaMotion, in the order the editor saves them:
	 * after the attributes of the block, lock and metadata, before those of the block supports. The default none and
	 * a null leave the key out, as the editor does. For agents (Gateway\Block_Edit): only the block comment changes.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Current attributes.
	 * @param array<string, mixed> $values leitviaAnimation and/or leitviaMotion.
	 * @return array<string, mixed>
	 */
	public static function with_attrs( string $name, array $attrs, array $values ): array {
		$own    = array( 'leitviaAnimation', 'leitviaMotion' );
		$type   = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$order  = array_flip( array_keys( (array) ( $type->attributes ?? array() ) ) );
		$at     = $order['leitviaAnimation'] ?? PHP_INT_MAX;
		$mine   = array_intersect_key( $values + $attrs, array_flip( $own ) );
		$mine   = array_filter(
			array_merge( array_intersect_key( array_flip( $own ), $mine ), $mine ),
			static fn( $value, string $key ): bool => null !== $value && ! ( 'leitviaAnimation' === $key && 'none' === $value ),
			ARRAY_FILTER_USE_BOTH
		);
		$rest   = array_diff_key( $attrs, array_flip( $own ) );
		$before = array_filter( $rest, static fn( string $key ): bool => isset( $order[ $key ] ) && $order[ $key ] < $at, ARRAY_FILTER_USE_KEY );
		return $before + $mine + array_diff_key( $rest, $before );
	}
}
