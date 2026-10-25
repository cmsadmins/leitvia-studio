<?php
/**
 * Admin menu and screens of Leitvia Studio.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Leitvia Studio" menu. Every screen is one React root; src/admin/screens/ holds one file per screen.
 */
final class Admin {

	/**
	 * Screens by page slug; each one has its file in src/admin/screens/.
	 */
	public const SCREENS = array(
		'leitvia-studio'          => array(
			'capability' => 'edit_theme_options',
		),
		'leitvia-studio-tokens'   => array(
			'capability' => 'edit_theme_options',
		),
		'leitvia-studio-model'    => array(
			'capability' => 'manage_options',
		),
		'leitvia-studio-recipes'  => array(
			'capability' => 'edit_theme_options',
		),
		'leitvia-studio-inbox'    => array(
			'capability' => 'edit_theme_options',
		),
		'leitvia-studio-agents'   => array(
			'capability' => 'manage_options',
		),
		'leitvia-studio-settings' => array(
			'capability' => 'manage_options',
		),
	);

	/**
	 * User meta that marks the product notes as dismissed.
	 */
	public const NOTES_DISMISSED = 'leitvia_studio_notes_dismissed';

	/**
	 * WordPress screen IDs of the registered screens, mapped to their page slug.
	 *
	 * @var array<string, string>
	 */
	private static array $hooks = array();

	/**
	 * Menu titles by page slug.
	 *
	 * @return array<string, string>
	 */
	private static function titles(): array {
		return array(
			'leitvia-studio'          => __( 'Lab', 'leitvia-studio' ),
			'leitvia-studio-tokens'   => __( 'Tokens', 'leitvia-studio' ),
			'leitvia-studio-model'    => __( 'Content Model', 'leitvia-studio' ),
			'leitvia-studio-recipes'  => __( 'Recipes', 'leitvia-studio' ),
			'leitvia-studio-inbox'    => __( 'Inbox', 'leitvia-studio' ),
			'leitvia-studio-agents'   => __( 'Agents', 'leitvia-studio' ),
			'leitvia-studio-settings' => __( 'Settings', 'leitvia-studio' ),
		);
	}

	/**
	 * Adds the menu after Appearance. Runs on admin_menu.
	 */
	public static function register_menu(): void {
		$titles      = self::titles();
		$brand       = leitvia_studio_brand();
		self::$hooks = array();
		$hook        = add_menu_page(
			$brand['name'],
			$brand['menu_title'],
			self::SCREENS['leitvia-studio']['capability'],
			'leitvia-studio',
			array( self::class, 'render' ),
			// A logo of any size would break the menu; another brand gets a neutral dashicon instead of the mark.
			'' === $brand['logo_url'] ? self::menu_icon() : 'dashicons-art',
			61
		);
		self::$hooks[ $hook ] = 'leitvia-studio';
		foreach ( self::SCREENS as $slug => $screen ) {
			$hook = add_submenu_page( 'leitvia-studio', $titles[ $slug ], $titles[ $slug ], $screen['capability'], $slug, array( self::class, 'render' ) );
			if ( $hook ) {
				self::$hooks[ $hook ] = $slug;
			}
		}
	}

	/**
	 * Page slug of the current Studio screen, '' elsewhere.
	 */
	public static function current_screen(): string {
		$screen = get_current_screen();
		return $screen ? ( self::$hooks[ $screen->id ] ?? '' ) : '';
	}

	/**
	 * Prints the frame of a screen; the app renders into the root element.
	 */
	public static function render(): void {
		$screen = get_current_screen();
		?>
		<div class="wrap leitvia-studio">
			<?php self::render_brand(); ?>
			<?php echo self::render_slot( $screen ? $screen->id : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup of render_slot(), its parts are escaped there or printed by add-ons. ?>
			<div id="leitvia-studio-root" data-screen="<?php echo esc_attr( self::current_screen() ); ?>"></div>
		</div>
		<?php
	}

	/**
	 * The Leitvia mark from assets/, with currentColor.
	 */
	private static function mark(): string {
		return (string) file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/assets/leitvia-mark.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
	}

	/**
	 * Menu icon: the mark as a base64 SVG data URI filled black, which wp-admin's svg-painter recolours to the menu
	 * colours of the admin colour scheme, like a dashicon. No request leaves the site.
	 */
	public static function menu_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( str_replace( 'currentColor', 'black', self::mark() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wp-admin paints only base64 SVG menu icons.
	}

	/**
	 * Product line above the screen title: the Leitvia mark, "Leitvia Studio" and "by CMS ADMINS" as a link to the
	 * maker. The mark is decorative (the text names the product), inlined from assets/ so no request leaves the site.
	 * Another brand (filter leitvia_studio_brand) shows its name, its logo as a decorative image and its support link
	 * instead; the maker link stays only under the default brand.
	 */
	private static function render_brand(): void {
		$brand = leitvia_studio_brand();
		$logo  = '' === $brand['logo_url'] ? self::mark() : '<img src="' . esc_url( $brand['logo_url'] ) . '" alt="" class="leitvia-studio-brand__logo">';
		$link  = '';
		if ( '' !== $brand['support_url'] ) {
			$link = ' <a class="leitvia-studio-brand__by" href="' . esc_url( $brand['support_url'] ) . '">' . esc_html__( 'Support', 'leitvia-studio' ) . '</a>';
		} elseif ( '' === $brand['logo_url'] && __( 'Leitvia Studio', 'leitvia-studio' ) === $brand['name'] ) {
			$link = ' <a class="leitvia-studio-brand__by" href="' . esc_url( 'https://www.cms-admins.de/' ) . '">' . esc_html__( 'by CMS ADMINS', 'leitvia-studio' ) . '</a>';
		}
		$svg = array(
			'img'  => array(
				'src'   => true,
				'alt'   => true,
				'class' => true,
			),
			'svg'  => array(
				'xmlns'       => true,
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'class'       => true,
				'aria-hidden' => true,
				'focusable'   => true,
			),
			'path' => array(
				'fill'      => true,
				'fill-rule' => true,
				'd'         => true,
			),
		);
		printf(
			'<p class="leitvia-studio-brand">%1$s<span class="leitvia-studio-brand__name">%2$s</span>%3$s</p>',
			wp_kses( $logo, $svg ),
			esc_html( $brand['name'] ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
		);
	}

	/**
	 * The note on Leitvia Pro in the product notes: what Pro adds and a link to its plans. Runs on
	 * leitvia_studio_upsell_slot; prints nothing while Leitvia Pro is active or another brand is set (filter
	 * leitvia_studio_brand), so client sites of an agency never see it.
	 */
	public static function pro_note(): void {
		if ( defined( 'LEITVIA_PRO_VERSION' ) || __( 'Leitvia Studio', 'leitvia-studio' ) !== leitvia_studio_brand()['name'] ) {
			return;
		}
		echo self::pro_note_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pro_note_markup().
	}

	/**
	 * Markup of the note on Leitvia Pro.
	 */
	public static function pro_note_markup(): string {
		return sprintf(
			'<p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p>',
			esc_html__( 'Leitvia Pro', 'leitvia-studio' ),
			esc_html__( 'adds the blocks Slider, Scroll story, Before and after and Entry list, and eight industry kits with their own brand, pages and patterns. Everything in Leitvia Studio stays free.', 'leitvia-studio' ),
			esc_url( 'https://leitvia.com/pricing/' ),
			esc_html__( 'Plans of Leitvia Pro', 'leitvia-studio' )
		);
	}

	/**
	 * Product notes above the app of a Studio screen: the note on Leitvia Pro (pro_note()) and what add-ons print
	 * into leitvia_studio_upsell_slot. Nothing is printed outside Studio screens, with the setting hide_product_notes
	 * or after the user dismissed the notes.
	 *
	 * @param string $screen_id WordPress screen ID, for example toplevel_page_leitvia-studio.
	 * @return string The notes wrapped in an aside with a dismiss button, or ''.
	 */
	public static function render_slot( string $screen_id ): string {
		$slug = self::$hooks[ $screen_id ] ?? '';
		if ( '' === $slug || Settings::get( 'hide_product_notes' ) || get_user_meta( get_current_user_id(), self::NOTES_DISMISSED, true ) ) {
			return '';
		}
		ob_start();
		/**
		 * Fires above the app of every Studio screen. Output appears in an aside the user can dismiss.
		 *
		 * @param string $slug Page slug of the screen, for example leitvia-studio.
		 */
		do_action( 'leitvia_studio_upsell_slot', $slug );
		$notes = trim( (string) ob_get_clean() );
		if ( '' === $notes ) {
			return '';
		}
		return sprintf(
			'<aside class="leitvia-studio-notes" aria-label="%1$s">%2$s<p><button type="button" class="button leitvia-studio-notes__dismiss" aria-label="%3$s">%4$s</button></p></aside>',
			esc_attr__( 'Product notes', 'leitvia-studio' ),
			$notes,
			esc_attr__( 'Dismiss product notes', 'leitvia-studio' ),
			esc_html__( 'Dismiss', 'leitvia-studio' )
		);
	}

	/**
	 * Registers /leitvia-studio/v1/notes/dismiss: POST dismisses the notes for the current user, DELETE shows them again.
	 * Runs on rest_api_init.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/notes/dismiss',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function (): \WP_REST_Response {
						update_user_meta( get_current_user_id(), self::NOTES_DISMISSED, 1 );
						return new \WP_REST_Response( array( 'dismissed' => true ) );
					},
					'permission_callback' => static fn(): bool => current_user_can( 'read' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static function (): \WP_REST_Response {
						delete_user_meta( get_current_user_id(), self::NOTES_DISMISSED );
						return new \WP_REST_Response( array( 'dismissed' => false ) );
					},
					'permission_callback' => static fn(): bool => current_user_can( 'read' ),
				),
			)
		);
	}

	/**
	 * Loads the app on Studio screens only. Runs on admin_enqueue_scripts.
	 */
	public static function enqueue(): void {
		$screen_id  = self::current_screen();
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/admin.asset.php';
		if ( '' === $screen_id || ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$url   = plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/';
		wp_enqueue_script( 'leitvia-studio-admin', $url . 'admin.js', array_merge( $asset['dependencies'], array( 'leitvia-studio-api' ) ), $asset['version'], true );
		wp_enqueue_style( 'leitvia-studio-admin', $url . 'style-admin.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'leitvia-studio-admin', 'rtl', 'replace' );
		// Open Sans for the headings of Studio screens, served from the plugin (Latin subset, 600 and 700).
		$fonts = plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'assets/fonts/open-sans/';
		$faces = '';
		foreach ( array( 600, 700 ) as $weight ) {
			$faces .= sprintf( '@font-face{font-family:"Open Sans";font-style:normal;font-weight:%1$d;font-display:swap;src:url("%2$s") format("woff2");}', $weight, esc_url( $fonts . 'open-sans-latin-' . $weight . '.woff2' ) );
		}
		wp_add_inline_style( 'leitvia-studio-admin', $faces );
		wp_set_script_translations( 'leitvia-studio-admin', 'leitvia-studio' );

		$panels = array();
		foreach ( leitvia_studio()->registry( 'lab_panels' )->all() as $name => $panel ) {
			if ( current_user_can( $panel['capability'] ) ) {
				wp_enqueue_script( $panel['script'] );
				$panels[] = array(
					'name'  => $name,
					'label' => $panel['label'],
					'order' => $panel['order'],
				);
			}
		}
		usort( $panels, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		$data = array(
			'screen'         => $screen_id,
			'userId'         => get_current_user_id(),
			'previewUrl'     => add_query_arg(
				array(
					'leitvia_studio_preview' => 1,
					'_wpnonce'               => wp_create_nonce( 'leitvia_studio_preview' ),
				),
				home_url( '/' )
			),
			'variations'     => array_map(
				static fn( array $variation ): array => array(
					'slug'  => (string) ( $variation['slug'] ?? '' ),
					'title' => (string) ( $variation['title'] ?? '' ),
				),
				leitvia_studio_contract()->is_valid() ? leitvia_studio_contract()->variations() : array()
			),
			'labPanels'      => $panels,
			'notesDismissed' => (bool) get_user_meta( get_current_user_id(), self::NOTES_DISMISSED, true ),
			'usageIndex'     => \LeitviaStudio\Lab\Usage_Index::is_built(),
			'brand'          => leitvia_studio_brand()['name'],
			'agentRole'      => (string) ( wp_roles()->role_names[ \LeitviaStudio\Gateway\Gateway::ROLE ] ?? '' ),
		);
		wp_add_inline_script( 'leitvia-studio-admin', 'window.leitviaStudioAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Registers the Studio panels of the Lab detail view. Runs on leitvia_studio_init.
	 *
	 * @param \LeitviaStudio\Extension\Studio $studio Studio.
	 */
	public static function register_panels( \LeitviaStudio\Extension\Studio $studio ): void {
		$studio->registry( 'lab_panels' )->register(
			'leitvia/variants',
			array(
				'label'      => __( 'Variants', 'leitvia-studio' ),
				'script'     => 'leitvia-studio-admin',
				'order'      => 10,
				'capability' => 'edit_theme_options',
			),
			'leitvia-studio'
		);
		$studio->registry( 'lab_panels' )->register(
			'leitvia/states',
			array(
				'label'      => __( 'States', 'leitvia-studio' ),
				'script'     => 'leitvia-studio-admin',
				'order'      => 20,
				'capability' => 'edit_theme_options',
			),
			'leitvia-studio'
		);
		$studio->registry( 'lab_panels' )->register(
			'leitvia/checks',
			array(
				'label'      => __( 'Checks', 'leitvia-studio' ),
				'script'     => 'leitvia-studio-admin',
				'order'      => 30,
				'capability' => 'edit_theme_options',
			),
			'leitvia-studio'
		);
		$studio->registry( 'lab_panels' )->register(
			'leitvia/annotations',
			array(
				'label'      => __( 'Annotations', 'leitvia-studio' ),
				'script'     => 'leitvia-studio-admin',
				'order'      => 40,
				'capability' => 'edit_theme_options',
			),
			'leitvia-studio'
		);
		$studio->registry( 'lab_panels' )->register(
			'leitvia/usage',
			array(
				'label'      => __( 'Usage', 'leitvia-studio' ),
				'script'     => 'leitvia-studio-admin',
				'order'      => 50,
				'capability' => 'edit_theme_options',
			),
			'leitvia-studio'
		);
	}

	/**
	 * Post types whose admin lists and REST responses show the inherited pattern status: pages, posts and the post
	 * types of the content model. Complete after init, priority 9.
	 *
	 * @return list<string>
	 */
	public static function status_types(): array {
		return array_values( array_unique( array_merge( array( 'page', 'post' ), \LeitviaStudio\Model\Registrar::post_types() ) ) );
	}

	/**
	 * Hooks the column "Pattern status" into the admin lists of status_types(). Runs on init, priority 20.
	 */
	public static function register_status_column(): void {
		foreach ( self::status_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( self::class, 'add_status_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( self::class, 'render_status_column' ), 10, 2 );
		}
	}

	/**
	 * Adds the column "Pattern status". Runs on manage_{$post_type}_posts_columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function add_status_column( array $columns ): array {
		$columns['leitvia_pattern_status'] = __( 'Pattern status', 'leitvia-studio' );
		return $columns;
	}

	/**
	 * Prints the least mature effective status of the patterns a post uses. Runs on
	 * manage_{$post_type}_posts_custom_column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_status_column( string $column, int $post_id ): void {
		if ( 'leitvia_pattern_status' !== $column ) {
			return;
		}
		$labels = array(
			'draft'    => __( 'Draft', 'leitvia-studio' ),
			'review'   => __( 'In review', 'leitvia-studio' ),
			'approved' => __( 'Approved', 'leitvia-studio' ),
		);
		echo esc_html( $labels[ \LeitviaStudio\Lab\Usage_Index::post_status( $post_id ) ] ?? '' );
	}
}
