<?php
/**
 * Plugin bootstrap.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin once on plugins_loaded.
 */
final class Plugin {

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Contract of the active theme, loaded once per request.
	 *
	 * @var Contract\Contract|null
	 */
	private static ?Contract\Contract $contract = null;

	/**
	 * The Extension API, created on boot.
	 *
	 * @var Extension\Studio|null
	 */
	private static ?Extension\Studio $studio = null;

	/**
	 * Wires the modules into WordPress. Safe to call more than once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$studio = self::studio();
		add_action( 'init', array( $studio, 'initialize' ), 5 );
		add_action( 'init', array( $studio, 'register_blocks' ), 20 );
		add_action( 'wp_abilities_api_categories_init', array( $studio, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( $studio, 'register_abilities' ) );
		add_action( 'rest_api_init', array( new Rest\Extensions_Controller( $studio ), 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_api_script' ) );

		// Modules hook in here, one call per module.
		add_action( 'switch_theme', array( self::class, 'forget_contract' ) );
		add_action( 'switch_blog', array( self::class, 'forget_contract' ) );
		add_action( 'rest_api_init', static fn() => ( new Rest\Contract_Controller() )->register_routes() );
		add_action( 'init', array( Lab\Pattern_Meta::class, 'register' ) );
		add_filter( 'rest_pre_insert_wp_block', array( Lab\Pattern_Meta::class, 'validate_rest' ), 10, 2 );
		add_filter( 'rest_wp_block_query', array( Lab\Pattern_Meta::class, 'hide_variants_in_rest' ) );
		add_action( 'pre_get_posts', array( Lab\Pattern_Meta::class, 'hide_variants_in_admin' ) );
		add_action( 'leitvia_studio_init', array( Lab\Token_Lint::class, 'register_check' ) );
		add_action( 'save_post_wp_block', array( Lab\Token_Lint::class, 'store' ), 10, 2 );
		add_action( 'save_post_wp_block', array( Lab\Status::class, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( Lab\Status::class, 'flush_on_meta' ), 10, 3 );
		}
		add_action( 'switch_blog', array( Lab\Status::class, 'flush' ) );
		add_action( 'rest_api_init', static fn() => ( new Rest\Lab_Controller() )->register_routes() );
		add_action( 'init', array( Tokens\Token_Sets::class, 'register' ) );
		add_filter( 'map_meta_cap', array( Tokens\Token_Sets::class, 'map_meta_cap' ) );
		add_filter( 'wp_insert_post_data', array( Tokens\Token_Sets::class, 'force_private' ) );
		add_filter( 'rest_leitvia_tokens_collection_params', array( Tokens\Token_Sets::class, 'collection_params' ) );
		add_action( 'deleted_post', array( Tokens\Compiler::class, 'forget' ) );
		add_filter( 'get_edit_post_link', array( Tokens\Token_Sets::class, 'edit_link' ), 10, 3 );
		add_action( 'rest_api_init', static fn() => ( new Rest\Tokens_Controller() )->register_routes() );
		foreach ( array( 'before_delete_post', 'wp_trash_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( Lab\Pattern_Meta::class, 'follow_original' ) );
		}
		foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( Lab\Status::class, 'flush' ) );
		}
		add_filter( 'wp_count_posts', array( Lab\Pattern_Meta::class, 'count_originals' ), 10, 2 );
		add_action( 'leitvia_studio_init', array( Tokens\Contrast::class, 'register_check' ) );
		add_action( 'leitvia_studio_init', array( Tokens\Sources::class, 'register' ) );
		add_filter( 'rest_pre_insert_leitvia_tokens', array( Tokens\Token_Sets::class, 'validate_rest' ), 10, 2 );
		add_filter( 'wp_theme_json_data_user', array( Tokens\Overlays::class, 'apply' ), 20 );
		add_filter( 'rest_pre_insert_leitvia_tokens', array( Tokens\Overlays::class, 'validate_rest' ), 10, 2 );
		add_filter( 'wp_headers', array( Tokens\Overlays::class, 'headers' ) );
		add_action( 'parse_request', array( Tokens\Overlays::class, 'refresh' ), PHP_INT_MAX );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( Tokens\Overlays::class, 'forget_on_meta' ), 10, 3 );
		}
		add_action( 'transition_post_status', array( Tokens\Overlays::class, 'forget' ), 10, 3 );
		add_action( 'deleted_post', array( Tokens\Overlays::class, 'forget' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( Tokens\Site_Editor_Guard::class, 'validate_rest' ), 10, 3 );
		add_action( 'template_redirect', array( Lab\Preview::class, 'maybe_render' ), 0 );
		add_action( 'leitvia_studio_init', array( Admin\Admin::class, 'register_panels' ) );
		add_action( 'admin_menu', array( Admin\Admin::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Admin::class, 'enqueue' ), 20 );
		add_action( 'init', array( Admin\Settings::class, 'register' ) );
		// Core adds the admin hooks of the emoji loader after init, so admin_init removes those.
		add_action( 'init', array( Admin\Settings::class, 'remove_emoji_loader' ) );
		add_action( 'admin_init', array( Admin\Settings::class, 'remove_emoji_loader' ) );
		add_action( 'rest_api_init', array( Admin\Admin::class, 'register_routes' ) );
		Avatars\Avatars::boot();
		add_action( 'init', array( Model\Registrar::class, 'register' ), 9 );
		add_action( 'init', array( Model\Registrar::class, 'maybe_flush' ), 999 );
		add_filter( 'wp_insert_post_data', array( Model\Registrar::class, 'force_inherit' ) );
		add_action( 'enqueue_block_editor_assets', array( Model\Registrar::class, 'enqueue_editor' ) );
		add_filter( 'render_block_data', array( Model\Registrar::class, 'mark_children_query' ) );
		add_filter( 'query_loop_block_query_vars', array( Model\Registrar::class, 'children_query_vars' ), 10, 2 );
		add_action( 'transition_post_status', array( Model\Registrar::class, 'follow_entry' ), 10, 3 );
		add_action( 'before_delete_post', array( Model\Registrar::class, 'trash_children' ), 10, 2 );
		add_filter( 'map_meta_cap', array( Model\Registrar::class, 'map_row_caps' ), 10, 4 );
		add_action( 'switch_blog', array( Model\Model::class, 'forget' ) );
		add_action( 'rest_api_init', static fn() => ( new Rest\Model_Controller() )->register_routes() );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_model_editor' ), 21 );
		add_action( 'init', array( Model\Bindings::class, 'register' ) );
		add_action( 'save_post', array( Lab\Usage_Index::class, 'index' ), 10, 2 );
		add_action( 'init', array( Admin\Admin::class, 'register_status_column' ), 20 );
		add_action( 'after_setup_theme', array( News\Image_Sizes::class, 'register' ) );
		add_action( 'wp_head', array( News\Schema::class, 'print' ) );
		add_action( 'leitvia_studio_init', array( Blocks\Blocks::class, 'register' ) );
		add_filter( 'block_categories_all', array( Blocks\Blocks::class, 'category' ) );
		add_filter( 'render_block_core/tabs', array( Blocks\Tabs::class, 'render_tabs' ) );
		add_filter( 'render_block_core/tab-panel', array( Blocks\Tabs::class, 'render_panel' ), 10, 2 );
		add_filter( 'render_block', array( Blocks\Blocks::class, 'highlight_style' ) );
		add_action( 'enqueue_block_assets', array( Blocks\Blocks::class, 'editor_highlight_style' ) );
		add_filter( 'render_block_data', array( Blocks\Marquee::class, 'state' ) );
		add_filter( 'wp_kses_allowed_html', array( Blocks\Blocks::class, 'allow_inputs' ), 10, 2 );
		add_filter( 'render_block_leitvia/pricing', array( Blocks\Blocks::class, 'unique_pricing_group' ) );
		add_filter( 'render_block_leitvia/spotlight', array( Blocks\Blocks::class, 'spotlight_group' ) );
		add_filter( 'render_block_leitvia/announcement-bar', array( Blocks\Announcement_Bar::class, 'render' ) );
		add_filter( 'register_block_type_args', array( Blocks\Product_Compare::class, 'block_args' ), 10, 2 );
		add_action( 'init', array( Blocks\Blocks::class, 'patterns' ) );
		add_action( 'init', array( Consent\Consent::class, 'register_module' ) );
		add_filter( 'render_block_core/embed', array( Consent\Consent::class, 'filter_embed' ), 10, 2 );
		add_filter( 'render_block_core/html', array( Consent\Consent::class, 'filter_iframes' ) );
		add_filter( 'the_content', array( Consent\Consent::class, 'filter_iframes' ), 12 );
		add_filter( 'render_block_leitvia/consent-settings', array( Consent\Consent::class, 'filter_settings_block' ) );
		add_filter( 'render_block_data', array( Blocks\Table_Of_Contents::class, 'enter_content' ) );
		add_filter( 'render_block_core/post-content', array( Blocks\Table_Of_Contents::class, 'leave_content' ) );
		add_filter( 'render_block_core/heading', array( Blocks\Table_Of_Contents::class, 'add_id' ), 10, 2 );
		add_action( 'init', array( Blocks\Code_Copy::class, 'register_module' ) );
		add_filter( 'render_block_core/code', array( Blocks\Code_Copy::class, 'render' ) );
		add_action( 'init', array( Blocks\Blocks::class, 'patterns' ) );
		add_filter( 'default_wp_template_part_areas', array( Blocks\Mega_Menu::class, 'areas' ) );
		add_filter( 'register_block_type_args', array( Blocks\Mega_Menu::class, 'block_args' ), 10, 2 );
		add_filter( 'render_block_core/navigation', array( Blocks\Mega_Menu::class, 'collapsible_overlay' ), 10, 2 );
		add_filter( 'query_loop_block_query_vars', array( Blocks\Query_Filter::class, 'query_vars' ), 10, 2 );
		add_filter( 'leitvia_studio_consent_services', array( Blocks\Map::class, 'service' ) );
		add_filter( 'render_block_leitvia/map', array( Blocks\Map::class, 'render' ), 10, 2 );
		add_filter( 'render_block_leitvia/wizard', array( Blocks\Wizard::class, 'render' ) );
		add_filter( 'render_block_leitvia/form-errors', array( Blocks\Form_Errors::class, 'render' ) );
		add_action( 'init', array( Easy_Language\Easy_Language::class, 'register' ) );
		add_filter( 'render_block_leitvia/a11y-bar', array( Easy_Language\Easy_Language::class, 'render_bar' ) );
		Gateway\Gateway::boot();
	}

	/**
	 * Loads the CodeMirror JSON editor of core on the Model screen; window.leitviaStudioCodeEditor holds its settings,
	 * false when the user turned syntax highlighting off.
	 */
	public static function enqueue_model_editor(): void {
		if ( 'leitvia-studio-model' === Admin\Admin::current_screen() ) {
			$settings = wp_enqueue_code_editor( array( 'type' => 'application/json' ) );
			wp_add_inline_script( 'leitvia-studio-admin', 'window.leitviaStudioCodeEditor = ' . wp_json_encode( $settings ) . ';', 'before' );
		}
	}

	/**
	 * Contract of the active theme.
	 */
	public static function contract(): Contract\Contract {
		self::$contract ??= Contract\Contract::for_active_theme();
		return self::$contract;
	}

	/**
	 * Drops the cached contract after a theme or site switch.
	 */
	public static function forget_contract(): void {
		self::$contract = null;
	}

	/**
	 * The Extension API instance.
	 */
	public static function studio(): Extension\Studio {
		self::$studio ??= new Extension\Studio();
		return self::$studio;
	}

	/**
	 * Registers the leitvia-studio-api script (window.leitviaStudio). Studio screens enqueue it or depend on it.
	 */
	public static function register_api_script(): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/api.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_register_script(
			'leitvia-studio-api',
			plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/api.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}
}
