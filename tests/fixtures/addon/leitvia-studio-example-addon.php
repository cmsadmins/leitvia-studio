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
	}
);
