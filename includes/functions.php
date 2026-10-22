<?php
/**
 * Public template functions of Leitvia Studio.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract of the active theme. Check is_valid() before relying on its content.
 */
function leitvia_studio_contract(): \LeitviaStudio\Contract\Contract {
	return \LeitviaStudio\Plugin::contract();
}

/**
 * The Extension API: add-on list and registries.
 */
function leitvia_studio(): \LeitviaStudio\Extension\Studio {
	return \LeitviaStudio\Plugin::studio();
}

/**
 * One Studio setting (option leitvia_studio_settings), or its default when it is not saved.
 *
 * @param string $key Setting name, for example guard_site_editor_contrast.
 */
function leitvia_studio_setting( string $key ): mixed {
	return \LeitviaStudio\Admin\Settings::get( $key );
}

/**
 * Whether an SEO plugin that prints its own structured data is active: Yoast SEO, Rank Math, SEOPress, All in One SEO
 * or The SEO Framework. Leitvia Studio prints no JSON-LD then, so a page never carries two article nodes.
 */
function leitvia_studio_seo_plugin_active(): bool {
	$active = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' )
		|| defined( 'AIOSEO_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' )
		|| class_exists( 'WPSEO_Options', false ) || class_exists( 'RankMath', false ) || function_exists( 'aioseo' )
		|| function_exists( 'the_seo_framework' ) || function_exists( 'seopress_init' );

	/**
	 * Filters whether an SEO plugin is active. Return true for an SEO plugin the check does not know.
	 *
	 * @param bool $active Whether a known SEO plugin is active.
	 */
	return (bool) apply_filters( 'leitvia_studio_seo_plugin_active', $active );
}

/**
 * Consent placeholder for third-party content: names the provider, links to its privacy policy and to the content,
 * and keeps `$html` in a <template> that the script module @leitvia/consent inserts once the visitor agrees.
 *
 * @param string $service      Service key from the filter leitvia_studio_consent_services, or a host name.
 * @param string $html         Original HTML of the third-party content.
 * @param string $fallback_url Link to the content on the provider's site, used without JavaScript.
 */
function leitvia_studio_consent_placeholder( string $service, string $html, string $fallback_url ): string {
	return \LeitviaStudio\Consent\Consent::placeholder( $service, $html, $fallback_url );
}

/**
 * The product name, menu title, logo and support link Studio shows to people: admin menu, screen titles, the product
 * line of Studio screens, block and ability categories, the MCP server and the texts agents pass on. The filter
 * leitvia_studio_brand changes them; slugs, ability names and the text domain never change.
 *
 * @return array{name: string, menu_title: string, logo_url: string, support_url: string}
 */
function leitvia_studio_brand(): array {
	$default = array(
		'name'        => __( 'Leitvia Studio', 'leitvia-studio' ),
		'menu_title'  => '',
		'logo_url'    => '',
		'support_url' => '',
	);

	/**
	 * Filters the brand Studio shows to people.
	 *
	 * @param array $brand {
	 *     @type string $name        Product name, default "Leitvia Studio".
	 *     @type string $menu_title  Title of the admin menu, '' for the name.
	 *     @type string $logo_url    URL of a logo image, '' for the Leitvia mark.
	 *     @type string $support_url Link for help, '' for none.
	 * }
	 */
	$brand = (array) apply_filters( 'leitvia_studio_brand', $default );
	$brand = array_map( static fn( $value ): string => is_string( $value ) ? trim( $value ) : '', array_intersect_key( $brand + $default, $default ) );
	if ( '' === $brand['name'] ) {
		$brand['name'] = $default['name'];
	}
	if ( '' === $brand['menu_title'] ) {
		$brand['menu_title'] = $brand['name'];
	}
	$brand['logo_url']    = esc_url_raw( $brand['logo_url'] );
	$brand['support_url'] = esc_url_raw( $brand['support_url'] );
	return $brand;
}

/**
 * Short product name for categories and the MCP server: "Leitvia" under the default brand, else the brand name.
 */
function leitvia_studio_brand_short(): string {
	$name = leitvia_studio_brand()['name'];
	return __( 'Leitvia Studio', 'leitvia-studio' ) === $name ? __( 'Leitvia', 'leitvia-studio' ) : $name;
}
