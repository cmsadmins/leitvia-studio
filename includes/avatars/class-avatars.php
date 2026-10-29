<?php
/**
 * Local profile pictures from the media library, Gravatar off by default.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Avatars;

defined( 'ABSPATH' ) || exit;

/**
 * Answers pre_get_avatar_data for every avatar of the site: the user's picture from the media library, else a local
 * placeholder. Gravatar only when an administrator switches on the setting allow_gravatar.
 *
 * In WordPress 7.1, get_avatar_data() in wp-includes/link-template.php returns right after the pre_get_avatar_data
 * filter when $args['url'] is set, before it hashes the email address and builds the Gravatar URL. Setting 'url'
 * therefore keeps every caller (get_avatar(), get_avatar_url(), the REST API's avatar_urls) away from Gravatar.
 *
 * The assignment is the user option leitvia_avatar (one per site, the attachment belongs to that site's library).
 */
final class Avatars {

	/**
	 * User option with the attachment ID.
	 */
	public const OPTION = 'leitvia_avatar';

	/**
	 * Set when Studio switched show_avatars off on deactivation, so activation switches it on again.
	 */
	public const RESTORE = 'leitvia_studio_restore_show_avatars';

	/**
	 * Nonce action and field of the profile form.
	 */
	private const NONCE = 'leitvia_studio_avatar';

	/**
	 * Wires the module into WordPress.
	 */
	public static function boot(): void {
		add_filter( 'pre_get_avatar_data', array( self::class, 'filter_data' ), 10, 2 );
		add_filter( 'user_profile_picture_description', array( self::class, 'profile_field' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		// After edit_user() saved the profile; a refused profile (user_profile_update_errors) changes no picture either.
		add_action( 'profile_update', array( self::class, 'save' ) );
		add_action( 'user_profile_update_errors', array( self::class, 'validate' ), 10, 3 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
		add_action( 'init', array( self::class, 'register_pattern' ) );
	}

	/**
	 * Fills the avatar URL. Runs on pre_get_avatar_data.
	 *
	 * @param array<string, mixed> $args        Avatar arguments after core's defaults.
	 * @param mixed                $id_or_email User ID, email, WP_User, WP_Post, WP_Comment or hash.
	 * @return array<string, mixed>
	 */
	public static function filter_data( array $args, mixed $id_or_email ): array {
		if ( isset( $args['url'] ) ) {
			return $args; // Someone else already answered.
		}
		// Pingbacks and trackbacks get no avatar, as in core (get_avatar_data() checks this after the filter).
		if ( $id_or_email instanceof \WP_Comment && ! is_avatar_comment_type( get_comment_type( $id_or_email ) ) ) {
			$args['url']          = false;
			$args['found_avatar'] = false;
			return $args;
		}
		$user_id = self::user_id( $id_or_email );
		$image   = $user_id ? (int) get_user_option( self::OPTION, $user_id ) : 0;
		if ( $image && wp_attachment_is_image( $image ) && empty( $args['force_default'] ) ) {
			// The thumbnail size is cropped square by default; without it the full image is the fallback.
			$src = wp_get_attachment_image_src( $image, 'thumbnail' );
			if ( $src ) {
				$args['url']          = $src[0];
				$args['found_avatar'] = true;
				return $args;
			}
		}
		if ( leitvia_studio_setting( 'allow_gravatar' ) && empty( $args['force_default'] ) ) {
			return $args; // Core builds the Gravatar URL, the site owner opted in.
		}
		$args['url']          = plugins_url( 'assets/avatar-placeholder.svg', LEITVIA_STUDIO_FILE );
		$args['found_avatar'] = false;
		return $args;
	}

	/**
	 * The user an avatar request is about. A comment counts only through its user_id: a guest who types a member's
	 * email address must not get the member's picture.
	 *
	 * An email string is matched to a member, because core and themes call get_avatar( $email ) for known users. Code
	 * that passes an address typed by a visitor would let the visitor show a member's picture; such sites switch the
	 * match off with the filter leitvia_studio_avatar_match_email.
	 *
	 * @param mixed $id_or_email See filter_data().
	 */
	private static function user_id( mixed $id_or_email ): int {
		if ( $id_or_email instanceof \WP_User ) {
			return $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			return (int) $id_or_email->user_id;
		}
		if ( is_numeric( $id_or_email ) ) {
			return absint( $id_or_email );
		}
		/**
		 * Whether an email address passed to get_avatar() gets the picture of the member with that address.
		 *
		 * @param bool   $match Default true.
		 * @param string $email The address.
		 */
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) && apply_filters( 'leitvia_studio_avatar_match_email', true, $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? $user->ID : 0;
		}
		return 0;
	}

	/**
	 * Replaces the Gravatar link under the profile picture with the media library controls. Core prints the return
	 * value unfiltered inside a paragraph, so the markup is phrasing content only. Runs on
	 * user_profile_picture_description.
	 *
	 * @param string   $description  Core description.
	 * @param \WP_User $profile_user User being edited.
	 */
	public static function profile_field( string $description, \WP_User $profile_user ): string {
		if ( is_network_admin() ) {
			return ''; // The media library of the network admin belongs to the main site.
		}
		if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'edit_users' ) ) {
			return esc_html__( 'Ask an administrator to set your profile picture.', 'leitvia-studio' );
		}
		$current = (int) get_user_option( self::OPTION, $profile_user->ID );
		ob_start();
		?>
		<input type="hidden" name="leitvia_avatar" id="leitvia-avatar-id" value="<?php echo esc_attr( $current ? (string) $current : '' ); ?>" />
		<?php wp_nonce_field( self::NONCE, 'leitvia_studio_avatar_nonce', false ); ?>
		<button type="button" class="button hidden" id="leitvia-avatar-choose" aria-describedby="leitvia-avatar-help" hidden>
			<?php esc_html_e( 'Choose from Media Library', 'leitvia-studio' ); ?>
		</button>
		<?php if ( $current ) : ?>
			<label><input type="checkbox" name="leitvia_avatar_remove" value="1" aria-describedby="leitvia-avatar-help" /> <?php esc_html_e( 'Remove profile picture', 'leitvia-studio' ); ?></label>
		<?php endif; ?>
		<span id="leitvia-avatar-help"><?php esc_html_e( 'Pick an image you uploaded. It is stored on this site and shown wherever WordPress shows your profile picture. Save the profile to apply it.', 'leitvia-studio' ); ?></span>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Loads the media picker on the profile screens. Runs on admin_enqueue_scripts.
	 *
	 * @param string $hook_suffix Admin screen.
	 */
	public static function enqueue( string $hook_suffix ): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/profile.asset.php';
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) || is_network_admin() || ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_media();
		wp_enqueue_script( 'leitvia-studio-profile', plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/profile.js', array_merge( $asset['dependencies'], array( 'media-views' ) ), $asset['version'], true );
		wp_set_script_translations( 'leitvia-studio-profile', 'leitvia-studio' );
		// Users who may not edit other people's uploads only see their own in the picker.
		$data = array( 'author' => current_user_can( 'edit_others_posts' ) ? 0 : get_current_user_id() );
		wp_add_inline_script( 'leitvia-studio-profile', 'window.leitviaStudioProfile = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * What the profile form asks for: null for nothing to do, 0 to remove, an attachment ID to store, false when the
	 * submitted image is not allowed. Checks the nonce, edit_user, then the image: an attachment, an image, and one the
	 * current user may edit, so an author cannot take a colleague's photo.
	 *
	 * @param int $user_id User being saved.
	 */
	private static function submitted( int $user_id ): int|false|null {
		$nonce = isset( $_POST['leitvia_studio_avatar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['leitvia_studio_avatar_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return null;
		}
		// The form names the user it edits; profile_update can also fire for other users during the same request.
		if ( ! isset( $_POST['user_id'] ) || absint( wp_unslash( $_POST['user_id'] ) ) !== $user_id ) {
			return null;
		}
		if ( ! empty( $_POST['leitvia_avatar_remove'] ) ) {
			return 0;
		}
		$id = isset( $_POST['leitvia_avatar'] ) ? absint( wp_unslash( $_POST['leitvia_avatar'] ) ) : 0;
		if ( ! $id || (int) get_user_option( self::OPTION, $user_id ) === $id ) {
			return null; // Nothing picked, or the picture stays as it is (maybe set by an administrator).
		}
		return 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) && current_user_can( 'edit_post', $id ) ? $id : false;
	}

	/**
	 * Stores or removes the picture. Runs on personal_options_update and edit_user_profile_update.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save( int $user_id ): void {
		$id = self::submitted( $user_id );
		if ( 0 === $id ) {
			delete_user_option( $user_id, self::OPTION );
		} elseif ( is_int( $id ) ) {
			update_user_option( $user_id, self::OPTION, $id );
		}
	}

	/**
	 * Reports a refused picture; core shows it above the form. Runs on user_profile_update_errors.
	 *
	 * @param \WP_Error $errors Errors of the profile form.
	 * @param bool      $update Whether an existing user is saved.
	 * @param object    $user   User data, with ID on update.
	 */
	public static function validate( \WP_Error $errors, bool $update, object $user ): void {
		if ( $update && isset( $user->ID ) && false === self::submitted( (int) $user->ID ) ) {
			$errors->add(
				'leitvia_avatar_invalid',
				current_user_can( 'edit_others_posts' )
					? __( 'Choose an image from the Media Library.', 'leitvia-studio' )
					: __( 'Choose an image from the Media Library that you uploaded.', 'leitvia-studio' )
			);
		}
	}

	/**
	 * Adds the exporter. Runs on wp_privacy_personal_data_exporters.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['leitvia-studio-avatar'] = array(
			/* translators: %s: product name */
			'exporter_friendly_name' => sprintf( __( '%s profile picture', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser. Runs on wp_privacy_personal_data_erasers.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['leitvia-studio-avatar'] = array(
			/* translators: %s: product name */
			'eraser_friendly_name' => sprintf( __( '%s profile picture', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the picture URL of the user with this email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email ): array {
		$user  = get_user_by( 'email', $email );
		$image = $user ? (int) get_user_option( self::OPTION, $user->ID ) : 0;
		$url   = $image ? wp_get_attachment_url( $image ) : false;
		$data  = array();
		if ( $user && $url ) {
			$data[] = array(
				'group_id'    => 'leitvia-studio-avatar',
				'group_label' => __( 'Profile picture', 'leitvia-studio' ),
				'item_id'     => 'leitvia-avatar-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Profile picture', 'leitvia-studio' ),
						'value' => $url,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Removes the assignment of the user with this email address. The image stays: it is media library content.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = $user && get_user_option( self::OPTION, $user->ID ) && delete_user_option( $user->ID, self::OPTION );
		return array(
			'items_removed'  => (bool) $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggests a paragraph for the privacy policy guide. Runs on admin_init.
	 */
	public static function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Profile pictures on this site come from its media library.', 'leitvia-studio' ) . ' ';
		if ( leitvia_studio_setting( 'allow_gravatar' ) ) {
			$text .= esc_html__( 'For users and commenters without a picture from the media library, your browser loads the picture from Gravatar, a service of Automattic Inc. in the USA. Gravatar receives your IP address and a hash of the email address of the author on every page view that shows such a picture.', 'leitvia-studio' );
			$text .= ' ' . sprintf(
				/* translators: %s: URL of the Automattic privacy policy. */
				esc_html__( 'Automattic privacy policy: %s', 'leitvia-studio' ),
				'https://automattic.com/privacy/'
			);
		} else {
			$text .= esc_html__( 'This site does not load profile pictures from Gravatar.', 'leitvia-studio' );
		}
		wp_add_privacy_policy_content( leitvia_studio_brand()['name'], wp_kses_post( $text . '</p>' ) );
	}

	/**
	 * Registers the pattern "Author card with profile picture". Runs on init.
	 */
	public static function register_pattern(): void {
		register_block_pattern(
			'leitvia-studio/author-card',
			array(
				'title'      => __( 'Author card with profile picture', 'leitvia-studio' ),
				'categories' => array( 'about' ),
				'blockTypes' => array(),
				'content'    => '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group"><!-- wp:avatar {"size":96} /-->

<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:post-author-name /-->

<!-- wp:post-author-biography /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->',
			)
		);
	}

	/**
	 * Switches show_avatars off on deactivation unless Gravatar is allowed: saved core/avatar blocks and comment
	 * avatars of other themes would load Gravatar without Studio. Remembers it for activate(). Also the one per-site
	 * deactivation pass of Studio: the usage index misses every save while Studio is off, so it counts as unbuilt.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::each_site(
			$network_wide,
			static function (): void {
				delete_option( \LeitviaStudio\Lab\Usage_Index::BUILT );
				if ( ! leitvia_studio_setting( 'allow_gravatar' ) && get_option( 'show_avatars' ) ) {
					update_option( 'show_avatars', 0 );
					update_option( self::RESTORE, 1 );
				}
			}
		);
	}

	/**
	 * Switches show_avatars on again where deactivate() switched it off.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::each_site(
			$network_wide,
			static function (): void {
				if ( get_option( self::RESTORE ) ) {
					update_option( 'show_avatars', 1 );
					delete_option( self::RESTORE );
				}
			}
		);
	}

	/**
	 * Runs a callback on the current site, or on every site in batches of 100 when network wide. The gateway uses it too.
	 *
	 * @param bool     $network_wide Whether to visit every site.
	 * @param callable $callback     Work for one site.
	 */
	public static function each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();
			return;
		}
		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields'     => 'ids',
					'number'     => 100,
					'offset'     => $offset,
					'network_id' => get_current_network_id(),
				)
			);
			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site );
				$callback();
				restore_current_blog();
			}
			$offset += 100;
		} while ( $sites );
	}
}
