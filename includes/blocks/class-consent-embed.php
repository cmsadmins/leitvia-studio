<?php
/**
 * Embed behind consent: an iframe of another provider that loads only after the visitor agrees.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

use LeitviaStudio\Consent\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Renders leitvia/consent-embed. The iframe waits in the template of the consent placeholder (Consent::placeholder()),
 * named after the provider whose hosts the settings (consent_providers) or the filter leitvia_studio_consent_services
 * list for the address, else after the host. The script module @leitvia/consent loads it after a click or a stored
 * "Always allow", moves the focus to it and announces it. A preview image comes from the media library of the site,
 * never from the provider. An address of the own site needs no consent, and with the setting consent_embeds off the
 * iframe renders at once, as embeds do.
 */
final class Consent_Embed {

	/**
	 * Aspect ratios of the attribute aspectRatio, as value => suffix of the class is-ratio-*.
	 */
	public const RATIOS = array(
		'16/9' => '16-9',
		'4/3'  => '4-3',
		'1/1'  => '1-1',
		'9/16' => '9-16',
	);

	/**
	 * Smallest and largest fixed height in pixels.
	 */
	public const MIN_HEIGHT = 200;
	public const MAX_HEIGHT = 1600;

	/**
	 * What the iframe may do: run its scripts and forms and open windows, never navigate the page around it.
	 */
	public const SANDBOX = 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox';

	/**
	 * The block: '' without a valid https address.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$url = self::url( is_string( $attributes['url'] ?? null ) ? $attributes['url'] : '' );
		if ( '' === $url ) {
			return '';
		}
		$service = Consent::service_for( $url );
		$title   = sanitize_text_field( is_string( $attributes['title'] ?? null ) ? $attributes['title'] : '' );
		if ( '' === $title ) {
			/* translators: %s: provider name. */
			$title = sprintf( __( 'Content from %s', 'leitvia-studio' ), '' === $service ? (string) wp_parse_url( $url, PHP_URL_HOST ) : Consent::label( $service ) );
		}
		$html = sprintf( '<iframe src="%1$s" title="%2$s" loading="lazy" sandbox="%3$s" referrerpolicy="no-referrer"></iframe>', esc_url( $url ), esc_attr( $title ), self::SANDBOX );

		$ratio   = is_string( $attributes['aspectRatio'] ?? null ) ? $attributes['aspectRatio'] : '16/9';
		$wrapper = array( 'class' => 'is-ratio-' . ( self::RATIOS[ $ratio ] ?? '16-9' ) );
		if ( 'fixed' === $ratio ) {
			$wrapper = array(
				'class' => 'has-fixed-height',
				'style' => sprintf( '--leitvia-embed-height:%dpx', max( self::MIN_HEIGHT, min( self::MAX_HEIGHT, absint( $attributes['height'] ?? 600 ) ) ) ),
			);
		}

		if ( '' !== $service && leitvia_studio_setting( 'consent_embeds' ) ) {
			$preview = absint( $attributes['previewId'] ?? 0 );
			$image   = $preview ? wp_get_attachment_image(
				$preview,
				'large',
				false,
				array(
					'class'   => 'leitvia-consent__preview',
					'alt'     => '',
					'loading' => 'lazy',
				)
			) : '';
			$html    = Consent::placeholder( $service, $html, $url, $image );
		}
		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes( $wrapper ), $html );
	}

	/**
	 * The address as the block renders it: https only, a host with letters beyond ASCII in its ASCII form when the intl
	 * extension is there (the browser does the same), '' for anything else. Spaces, backslashes, quotes and angle
	 * brackets make it '': the browser reads a backslash as a slash and would load another host than the one named here.
	 *
	 * @param string $url Address of the attribute url.
	 */
	public static function url( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '/[\s\\\\"\'<>]/', $url ) ) {
			return '';
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( preg_match( '/[^\x00-\x7f]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$url   = is_string( $ascii ) ? (string) preg_replace( '#^https://' . preg_quote( $host, '#' ) . '#', 'https://' . $ascii, $url ) : $url;
		}
		return str_starts_with( $url, 'https://' ) && filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}

	/**
	 * Hands the providers to the editor as window.leitviaStudioConsentServices (label and hosts by key, hosts in
	 * lowercase) and the host of the own site and whether it can turn letters beyond ASCII in a host into ASCII as
	 * window.leitviaStudioConsentSite, before the script of the block, which names the provider of an address without
	 * asking the provider. Runs on enqueue_block_editor_assets.
	 */
	public static function editor_data(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'leitvia/consent-embed' );
		$data = array();
		foreach ( Consent::services() as $key => $service ) {
			$data[ $key ] = array(
				'label' => (string) ( $service['label'] ?? $key ),
				'hosts' => array_values( array_map( 'strtolower', array_filter( (array) ( $service['hosts'] ?? array() ), 'is_string' ) ) ),
			);
		}
		$site = array(
			'host' => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'idn'  => function_exists( 'idn_to_ascii' ),
		);
		foreach ( $type ? $type->editor_script_handles : array() as $handle ) {
			wp_add_inline_script( $handle, 'window.leitviaStudioConsentServices = ' . wp_json_encode( (object) $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
			wp_add_inline_script( $handle, 'window.leitviaStudioConsentSite = ' . wp_json_encode( $site, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		}
	}
}
