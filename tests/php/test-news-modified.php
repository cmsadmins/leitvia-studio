<?php
/**
 * The date of the last change of published posts.
 *
 * @package LeitviaStudio
 */

/**
 * News\Modified: an update that keeps the words keeps the date of the last change, one that changes them sets it.
 */
class Test_News_Modified extends WP_UnitTestCase {

	/**
	 * A published post changed last on 1 October 2026; an update with the same words keeps that date, with new markup
	 * and meta alike, a new word sets it, and a draft is left to core.
	 */
	public function test_keeps_the_date_without_new_words(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Opening hours',
				'post_content' => '<!-- wp:paragraph --><p>Open on weekdays.</p><!-- /wp:paragraph -->',
			)
		);
		$old  = static function () use ( $post ): void {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture: wp_update_post() always stamps the current time.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => '2026-10-01 11:00:00',
					'post_modified_gmt' => '2026-10-01 09:00:00',
				),
				array( 'ID' => $post )
			);
			clean_post_cache( $post );
		};
		$old();
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<!-- wp:paragraph {"className":"is-style-lead"} --><p class="is-style-lead">Open  on <strong>weekdays</strong>.</p><!-- /wp:paragraph -->',
				'meta_input'   => array( '_leitvia_seo_title' => 'Opening hours of the shop' ),
			)
		);
		$this->assertSame( '2026-10-01 09:00:00', get_post( $post )->post_modified_gmt );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<!-- wp:paragraph --><p>Open on weekdays and Saturdays.</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertNotSame( '2026-10-01 09:00:00', get_post( $post )->post_modified_gmt );

		$old();
		add_filter( 'leitvia_studio_keep_modified', '__return_false' );
		wp_update_post(
			array(
				'ID'         => $post,
				'meta_input' => array( '_leitvia_seo_title' => 'Hours' ),
			)
		);
		remove_filter( 'leitvia_studio_keep_modified', '__return_false' );
		$this->assertNotSame( '2026-10-01 09:00:00', get_post( $post )->post_modified_gmt );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( array( 'post_status' => 'draft' ), \LeitviaStudio\News\Modified::keep( array( 'post_status' => 'draft' ), array( 'ID' => $draft ) ) );
	}
}
