<?php
/**
 * Runs when the plugin is deleted in the admin.
 *
 * @package LeitviaStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// WordPress loads this file without the plugin; the file system helper comes from the class of change packages.
if ( ! class_exists( \LeitviaStudio\Gateway\Packages::class ) ) {
	require __DIR__ . '/includes/gateway/class-packages.php';
}

/**
 * Cleans one site: Studio options always; token sets, Lab metadata, the usage index, the content model declaration,
 * the easy language relation and profile picture assignments only with the setting delete_data_on_uninstall. Images in
 * the media library always stay.
 */
$leitvia_studio_uninstall_site = static function (): void {
	// Every post of a type in every status, trash and auto-draft included; stops when a batch deletes nothing, so it
	// cannot loop forever.
	$delete_posts = static function ( string $post_type ): void {
		do {
			$ids     = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => array_keys( get_post_stati() ),
					'fields'         => 'ids',
					'posts_per_page' => 100,
				)
			);
			$deleted = 0;
			foreach ( $ids as $id ) {
				$deleted += wp_delete_post( (int) $id, true ) ? 1 : 0;
			}
		} while ( $deleted );
	};
	$settings     = get_option( 'leitvia_studio_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
		$delete_posts( 'leitvia_tokens' );
		// Lab metadata of patterns; the wp_block posts themselves stay. The meta cache of exactly these posts goes too.
		global $wpdb;
		$lab = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_leitvia_lab_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup on uninstall, no API lists meta by key prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_leitvia_lab_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup on uninstall, no API deletes by key prefix.
		foreach ( $lab as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
		// Pattern usage index of every post.
		delete_metadata( 'post', 0, '_leitvia_uses', '', true );
		// Profile picture assignments of this site (user option, key with the site's table prefix).
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'leitvia_avatar', '', true );
		// The declaration of the Model screen; posts, terms, meta and leitvia_site_* options created with it stay.
		delete_option( 'leitvia_studio_model' );
		delete_post_meta_by_key( '_leitvia_model_trashed_with_entry' );
		// The easy language relation; both pages stay.
		delete_post_meta_by_key( '_leitvia_easy_language_of' );
		// The page kind that recipes and compose-page set (legal pages, contact); the pages stay.
		delete_post_meta_by_key( '_leitvia_page_kind' );
	}
	// Gateway: change packages, audit log, snapshots, schema version, settings, the prune event, pending checks after
	// writing with their tokens, and the role; agent users stay.
	$delete_posts( 'leitvia_package' );
	// Files of package operations that were never applied wait in uploads/leitvia-studio/private/<random>/; before
	// 1.3.10 they waited in uploads/leitvia-private/, which stays on sites that never loaded 1.3.10.
	// Without a usable file system (see Packages::filesystem()) the folders stay; the rest of the cleanup still runs.
	$uploads = wp_upload_dir( null, false )['basedir'];
	$files   = \LeitviaStudio\Gateway\Packages::filesystem();
	if ( $files ) {
		$files->delete( $uploads . '/leitvia-studio/private', true );
		$files->delete( $uploads . '/leitvia-private', true );
		// The folder of the plugin below uploads, when nothing else is left in it.
		if ( is_dir( $uploads . '/leitvia-studio' ) && ! glob( $uploads . '/leitvia-studio/*' ) ) {
			$files->rmdir( $uploads . '/leitvia-studio' );
		}
	}
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}leitvia_audit, {$wpdb->prefix}leitvia_snapshots" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own tables, removed on uninstall.
	delete_option( 'leitvia_studio_db' );
	delete_option( 'leitvia_studio_gateway' );
	delete_option( 'leitvia_studio_recipe_writes' );
	wp_clear_scheduled_hook( 'leitvia_studio_gateway_prune' );
	// Checks after writing are single events with arguments; wp_unschedule_hook() takes them all.
	wp_unschedule_hook( 'leitvia_studio_verify' );
	$leitvia_studio_tokens = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_leitvia_verify_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup on uninstall, no API lists transients by prefix.
	foreach ( $leitvia_studio_tokens as $leitvia_studio_token ) {
		delete_transient( substr( $leitvia_studio_token, strlen( '_transient_' ) ) );
	}
	remove_role( 'leitvia_agent' );
	delete_option( 'leitvia_studio_active_set' );
	delete_option( 'leitvia_studio_applied_hash' );
	delete_option( 'leitvia_studio_settings' );
	delete_option( 'leitvia_studio_usage_index_built' );
	delete_option( 'leitvia_studio_restore_show_avatars' );
	delete_option( 'leitvia_studio_flush_rewrite' );
	delete_option( 'leitvia_studio_model_checked' );
	// Overlay state, windows and the compiled overlay sets: the transients leitvia_studio_overlay_*. With an external
	// object cache they live there until they expire or are evicted (state and windows have no expiry); without one they are options, deleted through the API so
	// the options cache stays right.
	global $wpdb;
	$leitvia_studio_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_leitvia_studio_overlay_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup on uninstall, no API lists transients by prefix.
	foreach ( array_merge( array( '_transient_leitvia_studio_overlay_state', '_transient_leitvia_studio_overlay_windows' ), $leitvia_studio_names ) as $leitvia_studio_name ) {
		delete_transient( substr( $leitvia_studio_name, strlen( '_transient_' ) ) );
	}
};

// User meta is shared by all sites of a network.
delete_metadata( 'user', 0, 'leitvia_studio_notes_dismissed', '', true );

if ( is_multisite() ) {
	$leitvia_studio_offset = 0;
	do {
		$leitvia_studio_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $leitvia_studio_offset,
			)
		);
		foreach ( $leitvia_studio_sites as $leitvia_studio_site ) {
			switch_to_blog( (int) $leitvia_studio_site );
			$leitvia_studio_uninstall_site();
			restore_current_blog();
		}
		$leitvia_studio_offset += 100;
	} while ( $leitvia_studio_sites );
} else {
	$leitvia_studio_uninstall_site();
}
