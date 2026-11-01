<?php
/**
 * Plugin Name:       Leitvia Studio Example Add-on
 * Description:       Shows how an add-on docks onto the Leitvia Studio Extension API. Used in tests only.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Requires Plugins:  leitvia-studio
 * Author:            CMS ADMINS
 * License:           GPL-2.0-or-later
 * Text Domain:       leitvia-studio-example-addon
 *
 * @package LeitviaStudioExampleAddon
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'leitvia_studio_init',
	static function ( \LeitviaStudio\Extension\Studio $studio ): void {
		$slug = 'leitvia-studio-example-addon';
		// An incompatible add-on is listed with its reason and cannot register entries.
		if ( ! $studio->extensions()->register(
			$slug,
			array(
				'label'           => 'Leitvia Studio Example Add-on',
				'version'         => '1.0.0',
				'requires_studio' => '0.1.0',
				'requires_api'    => 1,
			)
		) ) {
			return;
		}

		$studio->registry( 'checks' )->register(
			$slug . '/always-ok',
			array(
				'label'    => 'Always OK',
				'scope'    => 'token_set',
				'callback' => static fn(): array => array(),
			),
			$slug
		);

		// The add-on registers this script handle itself; it calls window.leitviaStudio.registerLabPanel().
		$studio->registry( 'lab_panels' )->register(
			$slug . '/notes',
			array(
				'label'      => 'Notes',
				'script'     => 'leitvia-studio-example-addon-notes',
				'order'      => 50,
				'capability' => 'edit_theme_options',
			),
			$slug
		);

		// A building block: a tiny post type, the pattern that lists it and two sample entries.
		$studio->registry( 'building_blocks' )->register(
			$slug . '/notes',
			array(
				'title'       => 'Example notes',
				'description' => 'Short notes of the example add-on.',
				'icon'        => 'dashicons-edit',
				'model'       => array(
					'postTypes' => array(
						'lvx_note' => array(
							'label'         => 'Example notes',
							'singularLabel' => 'Example note',
						),
					),
				),
				'patterns'    => array( $slug . '/notes-list' ),
				'samples'     => static fn(): array => array(
					'postType' => 'lvx_note',
					'entries'  => array(
						array(
							'slug'  => 'example-note-one',
							'title' => 'Example note one',
						),
						array(
							'slug'  => 'example-note-two',
							'title' => 'Example note two',
						),
					),
				),
			),
			$slug
		);
		// The pattern names what it needs; the building block above implies the same.
		$studio->registry( 'patterns' )->register(
			$slug . '/notes-list',
			array(
				'title'       => 'Example notes list',
				'description' => 'Heading above the example notes.',
				'area'        => 'content',
				'composition' => array(
					'tone'    => 'surface',
					'density' => 'dense',
					'layout'  => 'list',
					'weight'  => 'medium',
					'role'    => 'detail',
					'width'   => 'wide',
				),
				'needs'       => array( 'postTypes' => array( 'lvx_note' ) ),
			),
			$slug
		);
	}
);

// The list block of the pattern: titles of the entries of a post type, rendered on the server (drafts for editors).
add_action(
	'init',
	static function (): void {
		$name = 'leitvia-studio-example-addon/note-list';
		wp_register_script( 'leitvia-studio-example-addon-note-list', false, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ), '1.0.0', true );
		wp_add_inline_script(
			'leitvia-studio-example-addon-note-list',
			'wp.blocks.registerBlockType( ' . wp_json_encode( $name ) . ', { apiVersion: 3, title: "Example note list", category: "widgets", attributes: { postType: { type: "string" } }, edit: function ( props ) { return wp.element.createElement( "div", wp.blockEditor.useBlockProps(), wp.element.createElement( wp.serverSideRender, { block: ' . wp_json_encode( $name ) . ', attributes: props.attributes } ) ); }, save: function () { return null; } } );'
		);
		register_block_type(
			$name,
			array(
				'api_version'           => 3,
				'title'                 => 'Example note list',
				'attributes'            => array( 'postType' => array( 'type' => 'string' ) ),
				'editor_script_handles' => array( 'leitvia-studio-example-addon-note-list' ),
				'render_callback'       => static function ( array $attributes ): string {
					$type = (string) ( $attributes['postType'] ?? '' );
					if ( ! post_type_exists( $type ) ) {
						return '';
					}
					$posts = get_posts(
						array(
							'post_type'   => $type,
							'post_status' => current_user_can( 'edit_posts' ) ? array( 'publish', 'draft' ) : 'publish',
							'numberposts' => 10,
							'orderby'     => 'menu_order',
							'order'       => 'ASC',
						)
					);
					$items = array_map( static fn( WP_Post $post ): string => '<li>' . esc_html( get_the_title( $post ) ) . '</li>', $posts );
					return '<ul ' . get_block_wrapper_attributes() . '>' . implode( '', $items ) . '</ul>';
				},
			)
		);
		register_block_pattern(
			'leitvia-studio-example-addon/notes-list',
			array(
				'title'   => 'Example notes list',
				'content' => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Example notes</h2><!-- /wp:heading --><!-- wp:leitvia-studio-example-addon/note-list {"postType":"lvx_note"} /--></div><!-- /wp:group -->',
			)
		);
	}
);
