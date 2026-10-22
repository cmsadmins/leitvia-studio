<?php
/**
 * Studio's own entries in the registries token_sources and sync_targets.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use LeitviaStudio\Extension\Studio;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Token documents from the active theme and its style variations; export as a file download.
 */
final class Sources {

	/**
	 * Registers the entries. Runs on leitvia_studio_init.
	 *
	 * @param Studio $studio Studio instance.
	 */
	public static function register( Studio $studio ): void {
		$studio->registry( 'token_sources' )->register(
			'leitvia/active-theme',
			array(
				'label'    => __( 'Active theme', 'leitvia-studio' ),
				'callback' => array( self::class, 'active_theme' ),
			),
			'leitvia-studio'
		);
		$studio->registry( 'token_sources' )->register(
			'leitvia/style-variations',
			array(
				'label'    => __( 'Style variations', 'leitvia-studio' ),
				'callback' => array( self::class, 'style_variations' ),
			),
			'leitvia-studio'
		);
		$studio->registry( 'sync_targets' )->register(
			'leitvia/download',
			array(
				'label'  => __( 'Download file', 'leitvia-studio' ),
				'export' => array( self::class, 'download' ),
			),
			'leitvia-studio'
		);
	}

	/**
	 * Source leitvia/active-theme: the presets of the theme without user global styles.
	 *
	 * @return list<array{title: string, document: array<string, mixed>}>
	 */
	public static function active_theme(): array {
		return array(
			array(
				'title'    => (string) wp_get_theme()->get( 'Name' ),
				'document' => Dtcg::from_settings( WP_Theme_JSON_Resolver::get_theme_data()->get_settings(), leitvia_studio_contract() ),
			),
		);
	}

	/**
	 * Source leitvia/style-variations: one document per style variation. Partial variations (colors or typography
	 * only) are laid over the theme, so every document holds all roles.
	 *
	 * @return list<array{title: string, document: array<string, mixed>}>
	 */
	public static function style_variations(): array {
		$documents = array();
		foreach ( WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			// get_theme_data() returns the cached instance, merge() would change it.
			$theme = clone WP_Theme_JSON_Resolver::get_theme_data();
			$theme->merge( new \WP_Theme_JSON( $variation ) );
			$documents[] = array(
				'title'    => (string) ( $variation['title'] ?? '' ),
				'document' => Dtcg::from_settings( $theme->get_settings(), leitvia_studio_contract() ),
			);
		}
		return $documents;
	}

	/**
	 * Sync target leitvia/download: hands the document back, the export route sends it as a file.
	 *
	 * @param array<string, mixed> $package Package with id, title and document.
	 * @return mixed The document.
	 */
	public static function download( array $package ) {
		return $package['document'];
	}
}
