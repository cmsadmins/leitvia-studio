<?php
/**
 * Map block: the interactive map behind the consent placeholder.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

use LeitviaStudio\Consent\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The saved HTML of leitvia/map is a list of places with links to OpenStreetMap and requests nothing. On render this
 * class puts the map canvas above the list, wrapped in the consent placeholder unless the tile server is the site
 * itself. view.js starts Leaflet once the canvas is in the page, so tiles load only after consent.
 */
final class Map {

	/**
	 * Adds the service openstreetmap to the consent placeholder. Runs on leitvia_studio_consent_services.
	 *
	 * @param array<string, array<string, mixed>> $services Known services.
	 * @return array<string, array<string, mixed>>
	 */
	public static function service( array $services ): array {
		$services['openstreetmap'] ??= array(
			'label'   => 'OpenStreetMap',
			'hosts'   => array( 'openstreetmap.org' ),
			'privacy' => 'https://osmfoundation.org/wiki/Privacy_Policy',
		);
		return $services;
	}

	/**
	 * Link to a point on openstreetmap.org.
	 *
	 * @param float $lat  Latitude.
	 * @param float $lng  Longitude.
	 * @param int   $zoom Zoom level.
	 */
	public static function link( float $lat, float $lng, int $zoom ): string {
		return sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', round( $lat, 6 ), round( $lng, 6 ), $zoom );
	}

	/**
	 * Inserts the map canvas, behind the consent placeholder, before the list of places. Runs on
	 * render_block_leitvia/map.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function render( string $content, array $block ): string {
		$attrs   = (array) ( $block['attrs'] ?? array() );
		$lat     = (float) ( $attrs['lat'] ?? 52.52 );
		$lng     = (float) ( $attrs['lng'] ?? 13.405 );
		$zoom    = max( 1, min( 19, (int) ( $attrs['zoom'] ?? 13 ) ) );
		$markers = array();
		foreach ( (array) ( $attrs['markers'] ?? array() ) as $marker ) {
			if ( is_array( $marker ) && is_numeric( $marker['lat'] ?? null ) && is_numeric( $marker['lng'] ?? null ) ) {
				$markers[] = array(
					'title'   => wp_strip_all_tags( (string) ( $marker['title'] ?? '' ) ),
					'address' => wp_strip_all_tags( (string) ( $marker['address'] ?? '' ) ),
					'lat'     => (float) $marker['lat'],
					'lng'     => (float) $marker['lng'],
				);
			}
		}
		// The tile URL is a template with the placeholders {z}, {x}, {y} and {s}. esc_url() strips curly braces, so the
		// view script could no longer fill them in; it is escaped as an attribute value instead. Settings::tile_url()
		// validated it before: https only, {z}, {x} and {y} present, no whitespace, quotes or angle brackets
		// (TILE_URL_PATTERN) and a valid URL once the placeholders are filled; anything else gives the default server.
		$tiles   = \LeitviaStudio\Admin\Settings::tile_url( leitvia_studio_setting( 'map_tile_url' ) );
		$canvas  = sprintf(
			'<div class="wp-block-leitvia-map__canvas" role="region" aria-label="%1$s" data-tiles="%2$s" data-attribution="%3$s" data-view="%4$s" data-markers="%5$s"></div>',
			esc_attr__( 'Interactive map', 'leitvia-studio' ),
			esc_attr( $tiles ),
			esc_attr( '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' ),
			esc_attr( (string) wp_json_encode( array( $lat, $lng, $zoom ) ) ),
			esc_attr( (string) wp_json_encode( $markers ) )
		);
		$service = Consent::service_for( $tiles );
		$map     = '' === $service ? $canvas : Consent::placeholder( $service, $canvas, self::link( $lat, $lng, $zoom ) );

		$at = strpos( $content, '<ul class="wp-block-leitvia-map__places"' );
		if ( false === $at ) {
			$at = strrpos( $content, '</div>' );
		}
		return false === $at ? $content : substr_replace( $content, $map, $at, 0 );
	}
}
