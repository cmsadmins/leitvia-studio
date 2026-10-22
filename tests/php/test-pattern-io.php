<?php
/**
 * Pattern export and import.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Pattern_Meta;

/**
 * Round trip of content and lab metadata, core files, limits and rights.
 */
class Test_Pattern_IO extends WP_UnitTestCase {

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
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	/**
	 * Logs the admin in and registers the routes.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin );
		do_action( 'rest_api_init' );
	}

	/**
	 * POST /patterns/import with a raw body.
	 *
	 * @param string $body Request body.
	 */
	private static function import( string $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/patterns/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/**
	 * Export of a pattern as decoded JSON.
	 *
	 * @param int $id Pattern ID.
	 * @return array<string, mixed>
	 */
	private static function export( int $id ): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/patterns/' . $id . '/export' ) );
		return json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}

	/**
	 * Export then import keeps content, title, categories, changelog, annotations and variants; approved becomes review.
	 */
	public function test_round_trip(): void {
		$category  = wp_insert_term( 'Heroes', 'wp_pattern_category', array( 'slug' => 'heroes' ) );
		$changelog = array(
			array(
				'version'  => '1.2.0',
				'date'     => '2026-10-01',
				'breaking' => false,
				'note'     => "Added a button.\nSecond line.",
			),
		);
		$original  = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Hero & friends',
				'post_content' => '<!-- wp:heading {"metadata":{"name":"Title"}} --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->',
				'meta_input'   => array(
					'_leitvia_lab_status'      => 'approved',
					'_leitvia_lab_version'     => '1.2.0',
					'_leitvia_lab_changelog'   => $changelog,
					'_leitvia_lab_annotations' => array(
						array(
							'target' => 'Title',
							'text'   => 'Short.',
						),
					),
					'wp_pattern_sync_status'   => 'unsynced',
				),
			)
		);
		wp_set_object_terms( $original, array( (int) $category['term_id'] ), 'wp_pattern_category' );
		$variant = Pattern_Meta::create_variant( $original, 'empty', 'Hero empty' );

		$file = self::export( $original );
		$this->assertSame( 'wp_block', $file['__file'] );
		$this->assertSame( 'unsynced', $file['syncStatus'] );
		$this->assertSame( 1, $file['leitvia']['format'] );
		$this->assertCount( 1, $file['leitvia']['variants'] );
		$file['leitvia']['categories'][] = 'brand-new';

		$response = self::import( (string) wp_json_encode( $file ) );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$copy = (int) substr( $response->get_data()['ref'], 6 );

		$this->assertNotSame( $original, $copy );
		$this->assertSame( get_post_field( 'post_content', $original ), get_post_field( 'post_content', $copy ) );
		$this->assertSame( 'Hero & friends', get_post_field( 'post_title', $copy ) );
		$this->assertSame( 'review', get_post_meta( $copy, '_leitvia_lab_status', true ) );
		$this->assertSame( '1.2.0', get_post_meta( $copy, '_leitvia_lab_version', true ) );
		$this->assertSame( $changelog, get_post_meta( $copy, '_leitvia_lab_changelog', true ) );
		$this->assertSame( get_post_meta( $original, '_leitvia_lab_annotations', true ), get_post_meta( $copy, '_leitvia_lab_annotations', true ) );
		$this->assertSame( 'unsynced', get_post_meta( $copy, 'wp_pattern_sync_status', true ) );
		$this->assertEqualsCanonicalizing( array( 'heroes', 'brand-new' ), wp_get_object_terms( $copy, 'wp_pattern_category', array( 'fields' => 'slugs' ) ) );

		$variants = Pattern_Meta::variants( $copy );
		$this->assertCount( 1, $variants );
		$this->assertSame( 'empty', get_post_meta( $variants[0], '_leitvia_lab_state', true ) );
		$this->assertSame( get_post_field( 'post_content', $variant ), get_post_field( 'post_content', $variants[0] ) );
		$this->assertSame( 'Hero empty', get_post_field( 'post_title', $variants[0] ) );

		// Exporting the copy gives the same file apart from the status and the new category.
		$again = self::export( $copy );
		$this->assertSame( $file['content'], $again['content'] );
		$this->assertSame( $file['leitvia']['changelog'], $again['leitvia']['changelog'] );
		$this->assertSame( $file['leitvia']['annotations'], $again['leitvia']['annotations'] );
		$this->assertSame( $file['leitvia']['variants'][0]['content'], $again['leitvia']['variants'][0]['content'] );
	}

	/**
	 * A plain core export is accepted and becomes a draft pattern.
	 */
	public function test_core_file(): void {
		$response = self::import( '{"__file":"wp_block","title":"Core pattern","content":"<!-- wp:paragraph --><p>Core</p><!-- /wp:paragraph -->","syncStatus":""}' );
		$this->assertSame( 201, $response->get_status() );
		$id = (int) substr( $response->get_data()['ref'], 6 );
		$this->assertSame( 'Core pattern', get_the_title( $id ) );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertSame( '', get_post_meta( $id, 'wp_pattern_sync_status', true ) );
	}

	/**
	 * Files over 1 MB are refused with 413.
	 */
	public function test_large_file(): void {
		$content = str_repeat( 'a', 1048577 );
		$this->assertSame( 413, self::import( '{"__file":"wp_block","title":"Big","content":"' . $content . '"}' )->get_status() );
	}

	/**
	 * Invalid files are refused and nothing is written.
	 */
	public function test_invalid_files(): void {
		$before = wp_count_posts( 'wp_block' )->publish;
		$files  = array(
			'not json',
			'{"__file":"wp_template","title":"X","content":""}',
			'{"__file":"wp_block","title":"","content":""}',
			'{"__file":"wp_block","title":"X","content":"","leitvia":{"format":2}}',
			'{"__file":"wp_block","title":"X","content":"","leitvia":{"format":1,"status":"published"}}',
			'{"__file":"wp_block","title":"X","content":"","leitvia":{"format":1,"annotations":[{"target":"A"}]}}',
			'{"__file":"wp_block","title":"X","content":"","leitvia":{"format":1,"variants":[{"__file":"wp_block","title":"V","content":"","leitvia":{"format":1,"state":"broken"}}]}}',
		);
		foreach ( $files as $file ) {
			$this->assertSame( 400, self::import( $file )->get_status(), $file );
		}
		$this->assertSame( $before, wp_count_posts( 'wp_block' )->publish );
	}

	/**
	 * Without unfiltered_html scripts are stripped; without the right to create patterns the route is closed.
	 */
	public function test_rights_and_kses(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$response = self::import( '{"__file":"wp_block","title":"Script","content":"<p>Text</p><script>alert(1)</script>"}' );
		$this->assertSame( 201, $response->get_status() );
		$this->assertStringNotContainsString( '<script', get_post_field( 'post_content', (int) substr( $response->get_data()['ref'], 6 ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 403, self::import( '{"__file":"wp_block","title":"X","content":""}' )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/patterns/' . self::factory()->post->create( array( 'post_type' => 'wp_block' ) ) . '/export' ) )->get_status() );
	}

	/**
	 * The export leaves out variants the user may not edit; variants that fail to save are reported.
	 */
	public function test_variant_rights_and_failures(): void {
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		$original = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'post_title'  => 'Mine',
				'post_author' => $author,
			)
		);
		$variant  = Pattern_Meta::create_variant( $original, 'empty', 'Not mine' );
		wp_update_post(
			array(
				'ID'          => $variant,
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		$this->assertCount( 1, self::export( $original )['leitvia']['variants'] );
		wp_set_current_user( $author );
		$this->assertSame( array(), self::export( $original )['leitvia']['variants'] );

		$fail     = static fn( bool $is_empty, array $data ): bool => $is_empty || 'Broken variant' === $data['post_title'];
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		$response = self::import( '{"__file":"wp_block","title":"With variant","content":"","leitvia":{"format":1,"variants":[{"__file":"wp_block","title":"Broken variant","content":"","leitvia":{"format":1,"state":"empty"}}]}}' );
		remove_filter( 'wp_insert_post_empty_content', $fail );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['imported_variants'] );
		$this->assertSame( 1, $response->get_data()['failed_variants'] );
	}
}
