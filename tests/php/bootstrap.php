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
// Files of earlier runs: tests that upload or import images without remove_added_uploads() leave them in the tests
// site, about 50 per run, and every remove_added_uploads() lists the whole folder over the slow bind mount of wp-env
// (3,400 files made each avatar test take 2 s). The tests site holds nothing else, so every run starts without them.
require_once ABSPATH . 'wp-admin/includes/file.php';
if ( WP_Filesystem() ) {
	$leitvia_studio_uploads_dir = wp_upload_dir()['basedir'];
	foreach ( array_merge( (array) glob( $leitvia_studio_uploads_dir . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ), (array) glob( $leitvia_studio_uploads_dir . '/sites', GLOB_ONLYDIR ) ) as $leitvia_studio_uploads ) {
		$GLOBALS['wp_filesystem']->delete( $leitvia_studio_uploads, true );
	}
}
if ( getenv( 'LEITVIA_TESTS_WOOCOMMERCE' ) ) {
	WC_Install::install();
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reload the roles WooCommerce just added.
	wp_roles();
}
