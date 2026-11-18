<?php
/**
 * Plugin Name:       Leitvia Studio: AI Agents, MCP and Design Tokens for Block Themes
 * Plugin URI:        https://leitvia.com/features/
 * Description:       AI agents such as Claude Code build and maintain block theme sites over MCP or REST. Every change is checked, reviewable and can be rolled back.
 * Version:           1.3.48
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

const LEITVIA_STUDIO_VERSION = '1.3.48';
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

// Translations of the release zip in languages/ fill what a language pack of WordPress.org lacks, see
// LeitviaStudio\Translations.
add_filter( 'load_script_translation_file', array( \LeitviaStudio\Translations::class, 'script_file' ), 10, 3 );
add_filter( 'load_script_translations', array( \LeitviaStudio\Translations::class, 'script_translations' ), 10, 4 );
add_action( 'after_setup_theme', array( \LeitviaStudio\Translations::class, 'load' ) );

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
