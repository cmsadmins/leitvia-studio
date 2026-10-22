<?php
/**
 * Uninstall tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Token_Sets;

/**
 * Studio options go always; token sets, Lab metadata, the usage index and the content model declaration only with
 * delete_data_on_uninstall.
 *
 * Each test runs in its own process because uninstall.php needs the constant WP_UNINSTALL_PLUGIN.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Uninstall extends WP_UnitTestCase {

	/**
	 * Without the setting the token sets stay.
	 */
	public function test_keeps_sets_by_default(): void {
		$sites   = self::sites_with_data();
		$content = self::site_content( $sites );
		$user    = self::factory()->user->create();
		update_user_meta( $user, 'leitvia_studio_notes_dismissed', 1 );
		self::assign_pictures( $user, $sites );
		self::uninstall();
		$this->assertSame( '', get_user_meta( $user, 'leitvia_studio_notes_dismissed', true ) );
		foreach ( $sites as $site => $set ) {
			self::switch_to( $site );
			$this->assertSame( self::$pictures[ $site ], (int) get_user_option( 'leitvia_avatar', $user ), 'profile picture assignment stays' );
			$this->assertFalse( get_option( 'leitvia_studio_restore_show_avatars' ) );
			$this->assertFalse( get_option( 'leitvia_studio_settings', false ) );
			$this->assertFalse( get_option( 'leitvia_studio_active_set' ) );
			$this->assertFalse( get_option( 'leitvia_studio_applied_hash' ) );
			$this->assertSame( Token_Sets::POST_TYPE, get_post_type( $set ) );
			$this->assertSame( array( 'block:' . $content[ $site ]['block'] ), get_post_meta( $content[ $site ]['page'], '_leitvia_uses', false ) );
			$this->assertSame( (string) $content[ $site ]['block'], get_post_meta( $content[ $site ]['page'], '_leitvia_easy_language_of', true ) );
			$this->assertSame( 'imprint', get_post_meta( $content[ $site ]['page'], '_leitvia_page_kind', true ) );
			$this->assertSame( 'approved', get_post_meta( $content[ $site ]['block'], '_leitvia_lab_status', true ), 'Lab metadata stays' );
			$this->assertSame( '1', get_post_meta( $content[ $site ]['session'], '_leitvia_model_trashed_with_entry', true ) );
			$this->assertFalse( get_option( 'leitvia_studio_usage_index_built' ) );
			$this->assertSame( array( 'version' => 1 ), get_option( 'leitvia_studio_model' ) );
			$this->assert_site_content_kept( $content[ $site ] );
			$this->assertFalse( get_option( 'leitvia_studio_flush_rewrite' ) );
			$this->assertFalse( get_option( 'leitvia_studio_model_checked' ) );
			$this->assertFalse( get_transient( 'leitvia_studio_overlay_state' ) );
			$this->assertFalse( get_transient( 'leitvia_studio_overlay_windows' ) );
			$this->assertFalse( get_transient( 'leitvia_studio_overlay_abc' ) );
			// The overlay of a kept set stays with it.
			$this->assertSame( '2026-01-01T00:00:00Z', get_post_meta( $set, '_leitvia_tokens_overlay', true )['start'] );
			self::switch_to( 0 );
		}
	}

	/**
	 * With the setting the token sets and their revisions go too.
	 */
	public function test_deletes_sets_with_setting(): void {
		$sites      = self::sites_with_data( true );
		$content    = self::site_content( $sites );
		$trashed    = array();
		$auto_draft = array();
		foreach ( array_keys( $sites ) as $site ) {
			self::switch_to( $site );
			$trashed[ $site ]    = wp_insert_post(
				array(
					'post_type'   => Token_Sets::POST_TYPE,
					'post_title'  => 'Trashed',
					'post_status' => 'trash',
				)
			);
			$auto_draft[ $site ] = wp_insert_post(
				array(
					'post_type'   => Token_Sets::POST_TYPE,
					'post_title'  => 'Auto draft',
					'post_status' => 'auto-draft',
				)
			);
			self::switch_to( 0 );
		}
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();
		self::assign_pictures( $user, $sites );
		update_user_meta( $other, 'leitvia_studio_unrelated', 1 );
		self::uninstall();
		$this->assertSame( '1', get_user_meta( $other, 'leitvia_studio_unrelated', true ) );
		foreach ( $sites as $site => $set ) {
			self::switch_to( $site );
			$this->assertFalse( get_user_option( 'leitvia_avatar', $user ), 'profile picture assignment goes' );
			$this->assertSame( 'attachment', get_post_type( self::$pictures[ $site ] ), 'the image in the media library stays' );
			$this->assertFalse( get_option( 'leitvia_studio_active_set' ) );
			$this->assertNull( get_post( $set ) );
			$this->assertNull( get_post( $trashed[ $site ] ) );
			$this->assertNull( get_post( $auto_draft[ $site ] ) );
			$this->assertSame( array(), wp_get_post_revisions( $set ) );
			$this->assertSame( '', get_post_meta( $set, '_leitvia_tokens_overlay', true ) );
			// Every _leitvia_lab_* key goes; the pattern and its core meta stay.
			$this->assertSame( array( 'wp_pattern_sync_status' => array( 'unsynced' ) ), get_post_meta( $content[ $site ]['block'] ) );
			$this->assertSame( array(), get_post_meta( $content[ $site ]['page'], '_leitvia_uses', false ) );
			$this->assertFalse( metadata_exists( 'post', $content[ $site ]['page'], '_leitvia_easy_language_of' ) );
			$this->assertFalse( metadata_exists( 'post', $content[ $site ]['page'], '_leitvia_page_kind' ) );
			$this->assertSame( '', get_post_meta( $content[ $site ]['session'], '_leitvia_model_trashed_with_entry', true ) );
			$this->assertFalse( get_option( 'leitvia_studio_usage_index_built' ) );
			// The model declaration goes, content created with it stays.
			$this->assertFalse( get_option( 'leitvia_studio_model' ) );
			$this->assert_site_content_kept( $content[ $site ] );
			self::switch_to( 0 );
		}
	}

	/**
	 * Change packages, gateway tables, schema version, settings, prune event, pending checks after writing with their
	 * tokens and role go on every site; agent users stay.
	 */
	public function test_removes_gateway_data(): void {
		global $wpdb;
		$sites    = array_keys( self::sites_with_data() );
		$agent    = self::factory()->user->create( array( 'role' => 'leitvia_agent' ) );
		$dropped  = array();
		$packages = array();
		$files    = array();
		// The test suite turns DROP TABLE into DROP TEMPORARY TABLE, so the statements are recorded instead.
		add_filter(
			'query',
			static function ( string $query ) use ( &$dropped ): string {
				if ( str_starts_with( $query, 'DROP TABLE IF EXISTS' ) ) {
					$dropped[] = $query;
				}
				return $query;
			},
			5
		);
		foreach ( $sites as $site ) {
			self::switch_to( $site );
			\LeitviaStudio\Gateway\Gateway::install();
			update_option( 'leitvia_studio_gateway', array( 'rate_per_minute' => 10 ) );
			$packages[ $site ] = wp_insert_post(
				array(
					'post_type'   => 'leitvia_package',
					'post_status' => 'private',
					'post_title'  => 'Package',
				)
			);
			$files[ $site ]    = \LeitviaStudio\Gateway\Packages::stash_file( 'waiting.png', 'bytes' );
			// A check after writing that has not run yet, and the token of one that is running.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'leitvia_studio_verify', array( array( 'post' => $packages[ $site ] ) ) );
			set_transient( 'leitvia_verify_' . $site, 'pending', 5 * MINUTE_IN_SECONDS );
			self::switch_to( 0 );
		}
		self::uninstall();
		$this->assertCount( count( $sites ), $dropped );
		foreach ( $sites as $site ) {
			self::switch_to( $site );
			$this->assertContains( "DROP TABLE IF EXISTS {$wpdb->prefix}leitvia_audit, {$wpdb->prefix}leitvia_snapshots", $dropped );
			$this->assertFalse( get_option( 'leitvia_studio_db' ) );
			$this->assertFalse( get_option( 'leitvia_studio_gateway', false ) );
			$this->assertFalse( wp_next_scheduled( 'leitvia_studio_gateway_prune' ) );
			$this->assertFalse( wp_get_scheduled_event( 'leitvia_studio_verify', array( array( 'post' => $packages[ $site ] ) ) ), 'pending checks after writing go' );
			$this->assertFalse( get_transient( 'leitvia_verify_' . $site ) );
			$this->assertNull( get_role( 'leitvia_agent' ) );
			$this->assertNull( get_post( $packages[ $site ] ), 'change packages go' );
			$this->assertNotSame( '', $files[ $site ] );
			$this->assertDirectoryDoesNotExist( dirname( $files[ $site ], 2 ), 'files of open packages go' );
			self::switch_to( 0 );
		}
		$this->assertInstanceOf( WP_User::class, get_user_by( 'id', $agent ), 'agent users stay' );
	}

	/**
	 * Site content that uninstall never deletes, plus the Studio meta on it: a pattern with Lab metadata, a page that
	 * uses it, an entry of the content model with meta, a trashed repeater row, the user global styles and a site
	 * setting of the model.
	 *
	 * @param array<int, int> $sites Set ID by site ID.
	 * @return array<int, array<string, int>> Post IDs by site ID.
	 */
	private static function site_content( array $sites ): array {
		$content = array();
		foreach ( array_keys( $sites ) as $site ) {
			self::switch_to( $site );
			$block = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
			add_post_meta( $block, 'wp_pattern_sync_status', 'unsynced' );
			add_post_meta( $block, '_leitvia_lab_status', 'approved' );
			add_post_meta( $block, '_leitvia_lab_variant_of', 1 );
			add_post_meta( $block, '_leitvia_lab_checks', array( 'axe' => array() ) );
			$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
			add_post_meta( $page, '_leitvia_uses', 'block:' . $block );
			add_post_meta( $page, '_leitvia_easy_language_of', $block );
			add_post_meta( $page, '_leitvia_page_kind', 'imprint' );
			$event = self::factory()->post->create( array( 'post_type' => 'event' ) );
			add_post_meta( $event, 'event_venue', 'Hall 1' );
			$session = self::factory()->post->create(
				array(
					'post_type'   => 'event_session',
					'post_parent' => $event,
					'post_status' => 'trash',
				)
			);
			add_post_meta( $session, '_leitvia_model_trashed_with_entry', '1' );
			$styles = self::factory()->post->create(
				array(
					'post_type'    => 'wp_global_styles',
					'post_status'  => 'publish',
					'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"settings":{"color":{"palette":{"theme":[{"slug":"accent","name":"Accent","color":"#1f4f43"}]}}}}',
				)
			);
			update_option( 'leitvia_site_company_phone', '+49 89 1234' );
			update_option( 'leitvia_studio_usage_index_built', 1 );
			$content[ $site ] = compact( 'block', 'page', 'event', 'session', 'styles' );
			self::switch_to( 0 );
		}
		return $content;
	}

	/**
	 * Patterns, global styles, content of the model and its site settings survive uninstall (plan 3.6).
	 *
	 * @param array<string, int> $content Post IDs of site_content() for the current site.
	 */
	private function assert_site_content_kept( array $content ): void {
		$this->assertSame( 'wp_block', get_post_type( $content['block'] ) );
		$this->assertSame( 'page', get_post_type( $content['page'] ) );
		$this->assertSame( 'event', get_post_type( $content['event'] ) );
		$this->assertSame( 'Hall 1', get_post_meta( $content['event'], 'event_venue', true ) );
		$this->assertSame( 'event_session', get_post_type( $content['session'] ) );
		$this->assertStringContainsString( '#1f4f43', get_post_field( 'post_content', $content['styles'] ) );
		$this->assertSame( '+49 89 1234', get_option( 'leitvia_site_company_phone' ) );
	}

	/**
	 * Creates options and a set on the main site and, in multisite, on a second site.
	 *
	 * @param bool $delete Value of delete_data_on_uninstall.
	 * @return array<int, int> Set ID by site ID.
	 */
	private static function sites_with_data( bool $delete = false ): array {
		$sites = array( get_current_blog_id() );
		if ( is_multisite() ) {
			$sites[] = self::factory()->blog->create();
		}
		$sets = array();
		foreach ( $sites as $site ) {
			self::switch_to( $site );
			// Every setting, the ones of phase 3 (news schema, consent, emoji script, map tiles) included, lives in this one
			// option and goes with it.
			update_option( 'leitvia_studio_settings', array( 'delete_data_on_uninstall' => $delete ) + \LeitviaStudio\Admin\Settings::DEFAULTS );
			update_option( 'leitvia_studio_active_set', 1 );
			update_option( 'leitvia_studio_applied_hash', array( 'hash' => 'x' ) );
			update_option( 'leitvia_studio_restore_show_avatars', 1 );
			update_option( 'leitvia_studio_model', array( 'version' => 1 ) );
			update_option( 'leitvia_studio_flush_rewrite', 1 );
			update_option( 'leitvia_studio_model_checked', 'x' );
			set_transient( 'leitvia_studio_overlay_state', 5 );
			set_transient( 'leitvia_studio_overlay_windows', array() );
			set_transient( 'leitvia_studio_overlay_abc', array( 'settings' => null ), DAY_IN_SECONDS );
			$set = wp_insert_post(
				array(
					'post_type'   => Token_Sets::POST_TYPE,
					'post_title'  => 'Set',
					'post_status' => 'private',
				)
			);
			add_post_meta(
				$set,
				'_leitvia_tokens_overlay',
				array(
					'start' => '2026-01-01T00:00:00Z',
					'end'   => '2026-01-02T00:00:00Z',
				)
			);
			wp_save_post_revision( $set );
			$sets[ $site ] = $set;
			self::switch_to( 0 );
		}
		return $sets;
	}

	/**
	 * Image per site, by site ID.
	 *
	 * @var array<int, int>
	 */
	private static array $pictures = array();

	/**
	 * Gives the user a profile picture on every site (an image attachment of that site).
	 *
	 * @param int             $user  User ID.
	 * @param array<int, int> $sites Set ID by site ID.
	 */
	private static function assign_pictures( int $user, array $sites ): void {
		foreach ( array_keys( $sites ) as $site ) {
			self::switch_to( $site );
			self::$pictures[ $site ] = self::factory()->attachment->create_object(
				array(
					'file'           => 'avatar.png',
					'post_mime_type' => 'image/png',
				)
			);
			update_user_option( $user, 'leitvia_avatar', self::$pictures[ $site ] );
			self::switch_to( 0 );
		}
	}

	/**
	 * Switches to a site in multisite; 0 restores the previous one.
	 *
	 * @param int $site Site ID or 0.
	 */
	private static function switch_to( int $site ): void {
		if ( is_multisite() ) {
			$site ? switch_to_blog( $site ) : restore_current_blog();
		}
	}

	/**
	 * Runs uninstall.php.
	 */
	private static function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'leitvia-studio/leitvia-studio.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
