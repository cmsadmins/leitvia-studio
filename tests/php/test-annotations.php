<?php
/**
 * Annotations of patterns in the Lab preview.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Preview;

/**
 * Numbered marks on named blocks, only in the preview request; orphaned annotations in REST.
 */
class Test_Annotations extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the admin.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Logs the admin in and activates the theme.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin );
		switch_theme( 'leitvia' );
	}

	/**
	 * A pattern with a named heading and a named paragraph, annotated on both and on a missing name.
	 */
	private static function pattern(): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"metadata":{"name":"Box"}} --><div class="wp-block-group"><!-- wp:heading {"metadata":{"name":"Title"}} --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
				'meta_input'   => array(
					'_leitvia_lab_annotations' => array(
						array(
							'target' => 'Title',
							'text'   => 'Keep it short.',
						),
						array(
							'target' => 'Gone',
							'text'   => 'Points nowhere.',
						),
						array(
							'target' => 'Box',
							'text'   => 'Uses the surface colour.',
						),
					),
				),
			)
		);
	}

	/**
	 * Preview of a pattern as the admin.
	 *
	 * @param int  $id          Pattern ID.
	 * @param bool $annotations Whether to ask for annotations.
	 */
	private static function preview( int $id, bool $annotations ): string {
		$query = array(
			'ref'      => 'block:' . $id,
			'_wpnonce' => wp_create_nonce( 'leitvia_studio_preview' ),
		);
		return leitvia_studio_tests_preview( $annotations ? $query + array( 'annotations' => '1' ) : $query )['body'];
	}

	/**
	 * With annotations=1 the named blocks carry their numbers and the marks script is printed.
	 */
	public function test_preview_marks_named_blocks(): void {
		$body = self::preview( self::pattern(), true );
		$this->assertMatchesRegularExpression( '/<h2[^>]*data-leitvia-annotation="1"/', $body );
		$this->assertMatchesRegularExpression( '/<div[^>]*data-leitvia-annotation="3"/', $body );
		$this->assertStringNotContainsString( 'data-leitvia-annotation="2"', $body );
		$this->assertStringContainsString( 'leitvia-studio-annotations', $body );
	}

	/**
	 * An annotation without a target marks no block, not every unnamed one; REST rejects such a target.
	 */
	public function test_empty_target_marks_nothing(): void {
		$id = self::pattern();
		update_post_meta(
			$id,
			'_leitvia_lab_annotations',
			array(
				array(
					'target' => '',
					'text'   => 'No target.',
				),
			)
		);
		$this->assertStringNotContainsString( 'data-leitvia-annotation', self::preview( $id, true ) );

		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/blocks/' . $id );
		$request->set_body_params(
			array(
				'meta' => array(
					'_leitvia_lab_annotations' => array(
						array(
							'target' => '',
							'text'   => 'No target.',
						),
					),
				),
			)
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * Without the parameter, and outside the preview, nothing changes.
	 */
	public function test_no_marks_without_preview_context(): void {
		$id = self::pattern();
		$this->assertStringNotContainsString( 'data-leitvia-annotation', self::preview( $id, false ) );
		$this->assertStringNotContainsString( 'data-leitvia-annotation', do_blocks( get_post_field( 'post_content', $id ) ) );
		self::preview( $id, true );
		$this->assertStringNotContainsString( 'data-leitvia-annotation', do_blocks( get_post_field( 'post_content', $id ) ) );
	}

	/**
	 * The REST route numbers the annotations, flags orphaned ones and lists the block names.
	 */
	public function test_rest_marks_orphaned_annotations(): void {
		$id = self::pattern();
		do_action( 'rest_api_init' );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/patterns/' . $id . '/annotations' ) )->get_data();
		$this->assertSame( array( 'Box', 'Title' ), $data['names'] );
		$this->assertSame( array( 1, 2, 3 ), wp_list_pluck( $data['annotations'], 'number' ) );
		$this->assertSame( array( false, true, false ), wp_list_pluck( $data['annotations'], 'orphaned' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/patterns/' . $id . '/annotations' ) )->get_status() );
	}
}
