<?php
/**
 * Lab meta, variants and the lab REST routes.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Pattern_Meta;

/**
 * Meta registration, REST validation, variants and GET /leitvia-studio/v1/patterns.
 */
class Test_Pattern_Meta extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static int $editor;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private static int $subscriber;

	/**
	 * Creates the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$editor     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Activates the Leitvia theme so its patterns are registered. The test case drops all meta keys after each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Pattern_Meta::register();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
	}

	/**
	 * Creates a wp_block.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 */
	private function block( array $args = array() ): int {
		return self::factory()->post->create(
			$args + array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Lab test pattern',
				'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body or query parameters.
	 */
	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Writes lab meta of a pattern as the editor.
	 *
	 * @param int                  $id   Pattern ID.
	 * @param array<string, mixed> $meta Meta values.
	 */
	private function write( int $id, array $meta ): WP_REST_Response {
		wp_set_current_user( self::$editor );
		return $this->request( 'POST', '/wp/v2/blocks/' . $id, array( 'meta' => $meta ) );
	}

	/**
	 * Every key is registered on wp_block.
	 */
	public function test_keys_are_registered(): void {
		$keys = array_keys( get_registered_meta_keys( 'post', 'wp_block' ) );
		foreach ( array( 'status', 'version', 'replaced_by', 'changelog', 'checks', 'variant_of', 'state', 'annotations' ) as $key ) {
			$this->assertContains( '_leitvia_lab_' . $key, $keys );
		}
	}

	/**
	 * An editor writes and reads every key over the core REST route; defaults apply to new patterns.
	 */
	public function test_editor_reads_and_writes_all_keys(): void {
		$id   = $this->block();
		$meta = array(
			'_leitvia_lab_status'      => 'review',
			'_leitvia_lab_version'     => '1.2.0',
			'_leitvia_lab_replaced_by' => 'pattern:leitvia/hero-centered',
			'_leitvia_lab_changelog'   => array(
				array(
					'version'  => '1.2.0',
					'date'     => '2026-10-17',
					'breaking' => false,
					'note'     => 'New button.',
				),
			),
			'_leitvia_lab_checks'      => array(
				'tokens'     => array(
					'count'    => 0,
					'findings' => array(),
				),
				'axe'        => array(
					'default|390' => array(
						array(
							'id'     => 'image-alt',
							'impact' => 'critical',
							'help'   => 'Images need alt text',
							'nodes'  => array( 'img' ),
						),
					),
				),
				'checked_at' => '2026-10-17T10:00:00+00:00',
			),
			'_leitvia_lab_variant_of'  => 0,
			'_leitvia_lab_state'       => '',
			'_leitvia_lab_annotations' => array(
				array(
					'target' => 'Hero',
					'text'   => 'Keep the heading short.',
				),
			),
		);

		wp_set_current_user( self::$editor );
		$fresh = $this->request( 'GET', '/wp/v2/blocks/' . $id, array( 'context' => 'edit' ) )->get_data()['meta'];
		$this->assertSame( 'draft', $fresh['_leitvia_lab_status'] );
		$this->assertSame( '0.1.0', $fresh['_leitvia_lab_version'] );

		$response = $this->write( $id, $meta );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$read = $this->request( 'GET', '/wp/v2/blocks/' . $id, array( 'context' => 'edit' ) )->get_data()['meta'];
		foreach ( $meta as $key => $value ) {
			$this->assertEquals( $value, $read[ $key ], $key );
		}
	}

	/**
	 * A subscriber cannot write lab meta.
	 */
	public function test_subscriber_gets_403(): void {
		$id = $this->block();
		wp_set_current_user( self::$subscriber );
		$response = $this->request( 'POST', '/wp/v2/blocks/' . $id, array( 'meta' => array( '_leitvia_lab_status' => 'approved' ) ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertNotSame( 'approved', get_post_meta( $id, '_leitvia_lab_status', true ) );
	}

	/**
	 * Values outside the schema are rejected.
	 */
	public function test_invalid_status_is_400(): void {
		$id = $this->block();
		$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_status' => 'published' ) )->get_status() );
		$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_version' => '1.0' ) )->get_status() );
		$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_replaced_by' => 'https://example.com' ) )->get_status() );
	}

	/**
	 * Deprecated needs a replacement, in the same request or stored.
	 */
	public function test_deprecated_needs_replacement(): void {
		$id       = $this->block();
		$response = $this->write( $id, array( '_leitvia_lab_status' => 'deprecated' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'leitvia_studio_invalid_lab_meta', $response->get_data()['code'] );
		$this->assertSame( 'draft', get_post_meta( $id, '_leitvia_lab_status', true ), 'nothing was written' );

		$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_replaced_by' => 'block:' . $this->block() ) )->get_status() );
		$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_status' => 'deprecated' ) )->get_status() );
	}

	/**
	 * Approving needs a version of at least the latest changelog entry.
	 */
	public function test_approved_version_follows_changelog(): void {
		$id        = $this->block();
		$changelog = array(
			array(
				'version'  => '1.1.0',
				'date'     => '2026-10-17',
				'breaking' => false,
				'note'     => 'Spacing.',
			),
		);
		$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_changelog' => $changelog ) )->get_status() );
		$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_status' => 'approved' ) )->get_status(), 'version 0.1.0 is below 1.1.0' );
		$this->assertSame(
			200,
			$this->write(
				$id,
				array(
					'_leitvia_lab_status'  => 'approved',
					'_leitvia_lab_version' => '1.1.0',
				)
			)->get_status()
		);
	}

	/**
	 * A breaking entry needs a higher major version than the entry before it.
	 */
	public function test_breaking_entry_needs_new_major(): void {
		$id    = $this->block();
		$entry = static fn( string $version, bool $breaking ): array => array(
			'version'  => $version,
			'date'     => '2026-10-17',
			'breaking' => $breaking,
			'note'     => 'Change.',
		);
		$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_changelog' => array( $entry( '1.5.0', true ), $entry( '1.4.0', false ) ) ) )->get_status() );
		$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_changelog' => array( $entry( '2.0.0', true ), $entry( '1.4.0', false ) ) ) )->get_status() );
		$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_changelog' => array( $entry( '1.0.0', true ) ) ) )->get_status(), 'the first entry has nothing before it' );
	}

	/**
	 * Variants copy content and categories, keep their own status and stay out of the core pattern list.
	 */
	public function test_variants(): void {
		$original = $this->block( array( 'post_content' => '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->' ) );
		wp_set_object_terms( $original, array( 'lab-test' ), 'wp_pattern_category' );
		update_post_meta( $original, '_leitvia_lab_status', 'approved' );

		$variant = Pattern_Meta::create_variant( $original, 'long', 'Long text' );
		$this->assertIsInt( $variant );
		$this->assertSame( get_post( $original )->post_content, get_post( $variant )->post_content );
		$this->assertSame( array( 'lab-test' ), wp_get_object_terms( $variant, 'wp_pattern_category', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( $original, (int) get_post_meta( $variant, '_leitvia_lab_variant_of', true ) );
		$this->assertSame( 'long', get_post_meta( $variant, '_leitvia_lab_state', true ) );
		$this->assertSame( 'draft', \LeitviaStudio\Lab\Status::own( 'block:' . $variant ) );
		$this->assertWPError( Pattern_Meta::create_variant( $original, 'broken', 'x' ) );
		$this->assertWPError( Pattern_Meta::create_variant( $variant, '', 'x' ) );

		wp_set_current_user( self::$editor );
		$ids = wp_list_pluck( $this->request( 'GET', '/wp/v2/blocks', array( 'per_page' => 100 ) )->get_data(), 'id' );
		$this->assertContains( $original, $ids );
		$this->assertNotContains( $variant, $ids );
	}

	/**
	 * POST /leitvia-studio/v1/patterns/<id>/variants needs edit_post.
	 */
	public function test_variant_route(): void {
		$original = $this->block();
		wp_set_current_user( self::$subscriber );
		$this->assertSame( 403, $this->request( 'POST', '/leitvia-studio/v1/patterns/' . $original . '/variants', array( 'state' => 'error' ) )->get_status() );

		wp_set_current_user( self::$editor );
		$response = $this->request(
			'POST',
			'/leitvia-studio/v1/patterns/' . $original . '/variants',
			array(
				'state' => 'error',
				'title' => 'Error state',
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Error state', $response->get_data()['title'] );
		$this->assertStringEndsWith( '/wp/v2/blocks/' . substr( $response->get_data()['ref'], 6 ), $response->get_headers()['Location'] );
		$this->assertSame( 400, $this->request( 'POST', '/leitvia-studio/v1/patterns/' . $original . '/variants', array( 'state' => 'nope' ) )->get_status() );
	}

	/**
	 * Wrong types and incomplete entries end in a clean 400, nothing is written.
	 */
	public function test_wrong_types_are_400(): void {
		$id    = $this->block();
		$cases = array(
			array( '_leitvia_lab_status' => 5 ),
			array( '_leitvia_lab_version' => array( '1.0.0' ) ),
			array( '_leitvia_lab_replaced_by' => 7 ),
			array( '_leitvia_lab_changelog' => 'x' ),
			array( '_leitvia_lab_changelog' => array( 'x' ) ),
			array( '_leitvia_lab_changelog' => array( array( 'breaking' => true ) ) ),
			array(
				'_leitvia_lab_changelog' => array(
					array(
						'version'  => 2,
						'date'     => '2026-10-17',
						'breaking' => true,
						'note'     => 'x',
					),
				),
			),
			array( '_leitvia_lab_annotations' => 'x' ),
			array( '_leitvia_lab_variant_of' => 'abc' ),
		);
		foreach ( $cases as $meta ) {
			$response = $this->write( $id, $meta );
			$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $meta ) );
			$this->assertSame( 'leitvia_studio_invalid_lab_meta', $response->get_data()['code'], (string) wp_json_encode( $meta ) );
		}
		$this->assertSame( array(), get_post_meta( $id, '_leitvia_lab_changelog', true ) );
	}

	/**
	 * The replacement and the original of a variant must be other, existing patterns.
	 */
	public function test_references_must_exist(): void {
		$id      = $this->block();
		$other   = $this->block();
		$variant = Pattern_Meta::create_variant( $other, 'error', 'Error' );
		foreach ( array( 'block:' . $id, 'block:999999', 'pattern:leitvia/does-not-exist' ) as $ref ) {
			$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_replaced_by' => $ref ) )->get_status(), $ref );
		}
		foreach ( array( 'block:' . $other, 'pattern:leitvia/hero-centered' ) as $ref ) {
			$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_replaced_by' => $ref ) )->get_status(), $ref );
		}
		foreach ( array( $id, $variant, 999999 ) as $original ) {
			$this->assertSame( 400, $this->write( $id, array( '_leitvia_lab_variant_of' => $original ) )->get_status(), (string) $original );
		}
		$this->assertSame( 200, $this->write( $id, array( '_leitvia_lab_variant_of' => $other ) )->get_status() );
	}

	/**
	 * Notes and annotation texts lose markup but keep their line breaks.
	 */
	public function test_free_text_is_sanitized(): void {
		$id = $this->block();
		$this->write(
			$id,
			array(
				'_leitvia_lab_changelog'   => array(
					array(
						'version'  => '0.1.0',
						'date'     => '2026-10-17',
						'breaking' => false,
						'note'     => "<b>Bold</b>\nnext",
					),
				),
				'_leitvia_lab_annotations' => array(
					array(
						'target' => 'Hero',
						'text'   => '<script>alert(1)</script>Keep it short',
					),
				),
			)
		);
		$this->assertSame( "Bold\nnext", get_post_meta( $id, '_leitvia_lab_changelog', true )[0]['note'] );
		$this->assertSame( 'Keep it short', get_post_meta( $id, '_leitvia_lab_annotations', true )[0]['text'] );
	}

	/**
	 * Variants go to the trash, come back and are deleted together with their original.
	 */
	public function test_variants_follow_their_original(): void {
		$original = $this->block();
		$variant  = Pattern_Meta::create_variant( $original, 'empty', 'Empty' );

		wp_trash_post( $original );
		$this->assertSame( 'trash', get_post_status( $variant ) );
		wp_untrash_post( $original );
		$this->assertNotSame( 'trash', get_post_status( $variant ) );

		wp_delete_post( $original, true );
		$this->assertNull( get_post( $variant ) );
	}

	/**
	 * The status counts of the admin pattern list leave variants out.
	 */
	public function test_admin_counts_leave_variants_out(): void {
		$original = $this->block();
		Pattern_Meta::create_variant( $original, 'long', 'Long' );
		set_current_screen( 'edit-wp_block' );
		$this->assertSame( 1, (int) wp_count_posts( 'wp_block' )->publish );
		set_current_screen( 'front' );
	}

	/**
	 * GET /leitvia-studio/v1/patterns lists every contract pattern and wp_block originals with their status.
	 */
	public function test_pattern_list(): void {
		$child  = $this->block( array( 'post_title' => 'Lab child' ) );
		$parent = $this->block(
			array(
				'post_title'   => 'Lab parent',
				'post_content' => '<!-- wp:block {"ref":' . $child . '} /-->',
			)
		);
		update_post_meta( $parent, '_leitvia_lab_status', 'approved' );
		update_post_meta( $child, '_leitvia_lab_status', 'review' );
		Pattern_Meta::create_variant( $parent, 'empty', 'Lab parent empty' );

		wp_set_current_user( self::$subscriber );
		$this->assertSame( 403, $this->request( 'GET', '/leitvia-studio/v1/patterns' )->get_status() );

		wp_set_current_user( self::$editor );
		$response = $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'per_page' => 100 ) );
		$this->assertSame( 200, $response->get_status() );
		$items = array_column( $response->get_data(), null, 'ref' );
		foreach ( leitvia_studio_contract()->patterns() as $pattern ) {
			$this->assertArrayHasKey( 'pattern:' . $pattern['slug'], $items );
		}
		$item = $items[ 'block:' . $parent ];
		$this->assertSame( 'user', $item['source'] );
		$this->assertSame( 'approved', $item['status'] );
		$this->assertSame( 'review', $item['effective_status'] );
		$this->assertSame( 1, $item['variant_count'] );
		$this->assertSame( 0, $item['checks']['tokens']['count'] );
		$this->assertStringContainsString( 'post=' . $parent, $item['edit_url'] );
		$this->assertCount( 2, wp_list_filter( $items, array( 'source' => 'user' ) ), 'variants are not listed' );
		$this->assertSame( 'approved', $items['pattern:leitvia/hero-split-image']['effective_status'] );

		$this->assertSame( array( 'block:' . $parent ), wp_list_pluck( $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'search' => 'lab parent' ) )->get_data(), 'ref' ) );
		$this->assertSame( array( 'block:' . $child ), wp_list_pluck( $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'status' => array( 'review' ) ) )->get_data(), 'ref' ) );
		$this->assertCount( 2, $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'source' => 'user' ) )->get_data() );

		$page = $this->request(
			'GET',
			'/leitvia-studio/v1/patterns',
			array(
				'source'   => 'theme',
				'per_page' => 5,
				'page'     => 2,
			)
		);
		$this->assertCount( 5, $page->get_data() );
		$this->assertGreaterThanOrEqual( count( leitvia_studio_contract()->patterns() ), (int) $page->get_headers()['X-WP-Total'] );
	}

	/**
	 * The parameter variant_of lists the variants of one pattern instead of the patterns.
	 */
	public function test_pattern_list_of_variants(): void {
		$original = $this->block();
		$variant  = Pattern_Meta::create_variant( $original, '', 'Lab test variant' );
		$this->block( array( 'post_title' => 'Lab test other pattern' ) );
		wp_set_current_user( self::$editor );

		$data = $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'variant_of' => $original ) )->get_data();
		$this->assertSame( array( 'block:' . $variant ), array_column( $data, 'ref' ) );
		$this->assertSame( 'Lab test variant', $data[0]['title'] );
	}

	/**
	 * Patterns of the active theme come first, own patterns next, patterns of core and plugins last.
	 */
	public function test_pattern_list_order(): void {
		$block = $this->block( array( 'post_title' => 'Lab test order pattern' ) );
		register_block_pattern(
			'lab-test/other',
			array(
				'title'   => 'Lab test other source',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_current_user( self::$editor );

		$refs = array();
		for ( $page = 1; $page < 20; $page++ ) {
			$data = $this->request(
				'GET',
				'/leitvia-studio/v1/patterns',
				array(
					'per_page' => 100,
					'page'     => $page,
				)
			)->get_data();
			if ( ! $data ) {
				break;
			}
			$refs = array_merge( $refs, array_column( $data, 'ref' ) );
		}
		unregister_block_pattern( 'lab-test/other' );
		$theme = array_keys( array_intersect( $refs, array_map( static fn( array $pattern ): string => 'pattern:' . $pattern['slug'], leitvia_studio_contract()->patterns() ) ) );
		$own   = (int) array_search( 'block:' . $block, $refs, true );
		// Every contract pattern is listed, also when its content was loaded earlier in the request.
		$this->assertCount( count( leitvia_studio_contract()->patterns() ), $theme );
		$this->assertGreaterThan( max( $theme ), $own );
		$this->assertGreaterThan( $own, (int) array_search( 'pattern:lab-test/other', $refs, true ) );
	}
}
