<?php
/**
 * PHPUnit bootstrap: the WordPress test suite of wp-env with the plugin loaded as a must-use plugin.
 *
 * @package LeitviaStudio
 */

$leitvia_studio_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';
require dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require $leitvia_studio_tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/leitvia-studio.php';
		// WooCommerce only for the group woocommerce (npm run test:php:commerce); every other run sees a site without it.
		if ( getenv( 'LEITVIA_TESTS_WOOCOMMERCE' ) ) {
			require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
	}
);
// Subdomain network for the matrix entry of the job studio-multisite (npm run test:php:subdomain); sites get a host each.
if ( getenv( 'LEITVIA_TESTS_SUBDOMAIN' ) ) {
	define( 'SUBDOMAIN_INSTALL', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.
}
require $leitvia_studio_tests_dir . '/includes/bootstrap.php';
if ( getenv( 'LEITVIA_TESTS_WOOCOMMERCE' ) ) {
	WC_Install::install();
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reload the roles WooCommerce just added.
	wp_roles();
}
