<?php
/**
 * Plugin Name:       Leitvia Studio
 * Plugin URI:        https://leitvia.com
 * Description:       Pattern lab, design tokens and a content model for block themes, stored in WordPress core structures.
 * Version:           1.3.44
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Author:            CMS ADMINS
 * Author URI:        https://cms-admins.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leitvia-studio
 * Domain Path:       /languages
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

const LEITVIA_STUDIO_VERSION = '1.3.44';
const LEITVIA_STUDIO_API     = 1;
// Oldest Extension API version add-ons may still declare; raised two major Studio versions after a breaking change.
const LEITVIA_STUDIO_API_MIN = 1;
const LEITVIA_STUDIO_FILE    = __FILE__;

// LeitviaStudio\Tokens\Token_Sets lives in includes/tokens/class-token-sets.php, LeitviaStudio\Easy_Language\Easy_Language
// in includes/easy-language/class-easy-language.php.
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'LeitviaStudio\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( 'LeitviaStudio\\' ) ) );
		$file  = 'class-' . str_replace( '_', '-', strtolower( (string) array_pop( $parts ) ) ) . '.php';
		$dir   = $parts ? str_replace( '_', '-', strtolower( implode( '/', $parts ) ) ) . '/' : '';
		$path  = __DIR__ . '/includes/' . $dir . $file;
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

require __DIR__ . '/includes/functions.php';

// Translations of the release zip in languages/, for sites without a language pack of WordPress.org. A pack in
// WP_LANG_DIR wins. PHP strings need only the header Domain Path, which core registers on every request; scripts
// are looked up in WP_LANG_DIR alone, so they go through this filter. The zip holds de_DE only: de_DE_formal, de_AT,
// de_CH and the other German variants read that file instead of showing English.
add_filter(
	'load_script_translation_file',
	static function ( $file, $handle, $domain ) {
		$own = __DIR__ . '/languages/' . preg_replace( '/-de_\w+-/', '-de_DE-', basename( (string) $file ), 1 );
		return 'leitvia-studio' === $domain && $file && ! is_readable( $file ) && is_readable( $own ) ? $own : $file;
	},
	10,
	3
);

add_action(
	'after_setup_theme',
	static function (): void {
		if ( preg_match( '/^de_(?!DE$)/', determine_locale() ) ) {
			get_translations_for_domain( 'leitvia-studio' ); // Loads a language pack of the variant when there is one.
			if ( ! is_textdomain_loaded( 'leitvia-studio' ) ) {
				load_textdomain( 'leitvia-studio', __DIR__ . '/languages/leitvia-studio-de_DE.mo' );
			}
		}
	}
);

add_action( 'plugins_loaded', array( \LeitviaStudio\Plugin::class, 'boot' ) );
// Gravatar stays off after a deactivation and the usage index counts as unbuilt (see LeitviaStudio\Avatars\Avatars::deactivate()).
register_activation_hook( __FILE__, array( \LeitviaStudio\Avatars\Avatars::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LeitviaStudio\Avatars\Avatars::class, 'deactivate' ) );
// Content model: rewrite rules follow the declared post types; on deactivation their content stays in the database.
// Network wide every site flushes on its next request, after the network deactivation emptied their rules.
register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		\LeitviaStudio\Avatars\Avatars::each_site(
			$network_wide,
			static fn() => update_option( \LeitviaStudio\Model\Model::FLUSH_OPTION, 1, true )
		);
	}
);
register_deactivation_hook( __FILE__, array( \LeitviaStudio\Model\Registrar::class, 'deactivate' ) );
// Gateway: tables, agent role and the daily prune event per site; data stays after a deactivation.
register_activation_hook( __FILE__, array( \LeitviaStudio\Gateway\Gateway::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LeitviaStudio\Gateway\Gateway::class, 'deactivate' ) );
// WP-CLI: wp leitvia tokens|patterns|model|audit|package|recipe, writes through the gateway abilities.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\LeitviaStudio\Cli\Command::register();
}
