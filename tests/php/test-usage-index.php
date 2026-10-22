<?php
/**
 * Pattern usage index.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Usage_Index;

/**
 * Index rows on save, lookups, theme template files, rebuild and the inherited status of posts.
 */
class Test_Usage_Index extends WP_UnitTestCase {

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
	 * Logs the admin in.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin );
	}

	/**
	 * Saving writes one row per used pattern, emptying the content removes them.
	 */
	public function test_index_follows_saves_and_deletes(): void {
		$pattern = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
			)
		);
		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /--><!-- wp:group {"metadata":{"patternName":"leitvia/hero-split-image","name":"Hero"}} --><div class="wp-block-group"></div><!-- /wp:group -->',
			)
		);
		$this->assertEqualsCanonicalizing( array( 'block:' . $pattern, 'pattern:leitvia/hero-split-image' ), get_post_meta( $page, '_leitvia_uses', false ) );
		$this->assertSame( array( $page ), wp_list_pluck( Usage_Index::used_by( 'block:' . $pattern ), 'id' ) );

		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '',
			)
		);
		$this->assertSame( array(), get_post_meta( $page, '_leitvia_uses', false ) );
	}

	/**
	 * Revisions and autosaves get no rows.
	 */
	public function test_revisions_and_autosaves_are_not_indexed(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:block {"ref":1} /-->',
			)
		);
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '<!-- wp:block {"ref":2} /-->',
			)
		);
		$autosave = _wp_put_post_revision(
			array(
				'ID'           => $page,
				'post_content' => '<!-- wp:block {"ref":3} /-->',
				'post_title'   => 'Autosave',
				'post_type'    => 'page',
			),
			true
		);
		$this->assertNotEmpty( wp_get_post_revisions( $page ) );
		foreach ( wp_get_post_revisions( $page, array( 'post_status' => 'any' ) ) as $revision ) {
			$this->assertSame( array(), get_post_meta( $revision->ID, '_leitvia_uses', false ) );
		}
		$this->assertSame( array(), get_post_meta( (int) $autosave, '_leitvia_uses', false ) );
		$this->assertSame( array( 'block:2' ), get_post_meta( $page, '_leitvia_uses', false ) );
	}

	/**
	 * Pattern blocks (core/pattern) and patterns nested in other blocks are found, each reference once.
	 */
	public function test_pattern_blocks_and_nested_blocks(): void {
		$post = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:pattern {"slug":"leitvia/cta-band"} /--><!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:block {"ref":7} /--><!-- wp:block {"ref":7} /--></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->',
			)
		);
		$this->assertEqualsCanonicalizing( array( 'pattern:leitvia/cta-band', 'block:7' ), get_post_meta( $post, '_leitvia_uses', false ) );
	}

	/**
	 * Templates in the database are indexed; theme files are parsed live.
	 */
	public function test_templates(): void {
		switch_theme( 'leitvia' );
		$template = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'custom-landing',
				'post_title'   => 'Custom landing',
				'post_content' => '<!-- wp:block {"ref":9} /-->',
				'tax_input'    => array( 'wp_theme' => array( 'leitvia' ) ),
			)
		);
		$this->assertSame( array( 'block:9' ), get_post_meta( $template, '_leitvia_uses', false ) );
		$this->assertContains( $template, wp_list_pluck( Usage_Index::used_by( 'block:9' ), 'id' ) );

		// The first pattern a theme file template uses is listed with the template ID.
		$file = null;
		foreach ( get_block_templates( array(), 'wp_template' ) as $candidate ) {
			$refs = LeitviaStudio\Lab\Status::refs( $candidate->content );
			if ( ! $candidate->wp_id && $refs ) {
				$file = array( $candidate->id, $refs[0] );
				break;
			}
		}
		$this->assertNotNull( $file, 'The Leitvia theme has a template that uses a pattern.' );
		$this->assertContains( $file[0], wp_list_pluck( Usage_Index::used_by( $file[1] ), 'id' ) );
	}

	/**
	 * Only posts the user may read are listed; trash is left out.
	 */
	public function test_used_by_respects_read_rights_and_trash(): void {
		$content = '<!-- wp:block {"ref":11} /-->';
		$public  = self::factory()->post->create( array( 'post_content' => $content ) );
		$private = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'private',
				'post_author'  => self::$admin,
			)
		);
		$trashed = self::factory()->post->create( array( 'post_content' => $content ) );
		wp_trash_post( $trashed );

		$this->assertEqualsCanonicalizing( array( $public, $private ), wp_list_pluck( Usage_Index::used_by( 'block:11' ), 'id' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( array( $public ), wp_list_pluck( Usage_Index::used_by( 'block:11' ), 'id' ) );
	}

	/**
	 * Rebuilding with reindex() fills missing rows in batches and marks the index as built.
	 */
	public function test_reindex_in_batches(): void {
		$posts = self::factory()->post->create_many( 3, array( 'post_content' => '<!-- wp:block {"ref":5} /-->' ) );
		foreach ( $posts as $post ) {
			delete_post_meta( $post, '_leitvia_uses', false );
		}
		delete_option( Usage_Index::BUILT );
		$this->assertFalse( Usage_Index::is_built() );

		$offset = 0;
		do {
			$batch  = Usage_Index::reindex( $offset, 2 );
			$offset = $batch['next'];
		} while ( ! $batch['done'] );

		$this->assertTrue( Usage_Index::is_built() );
		foreach ( $posts as $post ) {
			$this->assertSame( array( 'block:5' ), get_post_meta( $post, '_leitvia_uses', false ) );
		}
	}

	/**
	 * Posts inherit the least mature effective status of their patterns, in the list column and in REST.
	 */
	public function test_post_status(): void {
		$approved = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'meta_input'  => array( '_leitvia_lab_status' => 'approved' ),
			)
		);
		$review   = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'meta_input'  => array( '_leitvia_lab_status' => 'review' ),
			)
		);
		$page     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:block {"ref":' . $approved . '} /--><!-- wp:block {"ref":' . $review . '} /-->',
			)
		);
		$empty    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 'review', Usage_Index::post_status( $page ) );
		$this->assertSame( '', Usage_Index::post_status( $empty ) );

		ob_start();
		LeitviaStudio\Admin\Admin::render_status_column( 'leitvia_pattern_status', $page );
		$this->assertSame( 'In review', ob_get_clean() );
		$this->assertArrayHasKey( 'leitvia_pattern_status', LeitviaStudio\Admin\Admin::add_status_column( array() ) );

		do_action( 'rest_api_init' );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page ) );
		$this->assertSame( 'review', $response->get_data()['leitvia_pattern_status'] );
	}

	/**
	 * The usage route lists both directions and the reindex route needs the Lab capability.
	 */
	public function test_rest_routes(): void {
		$part    = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'post_title'  => 'Part',
			)
		);
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $part . '} /-->',
			)
		);
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/usage' );
		$request->set_param( 'ref', 'block:' . $part );
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( array( $pattern ), wp_list_pluck( $data['used_by'], 'id' ) );

		$request->set_param( 'ref', 'block:' . $pattern );
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( array( 'block:' . $part ), wp_list_pluck( $data['uses'], 'ref' ) );
		$this->assertSame( 'Part', $data['uses'][0]['title'] );

		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'POST', '/leitvia-studio/v1/usage/reindex' ) )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/leitvia-studio/v1/usage/reindex' ) )->get_status() );
	}

	/**
	 * A contributor gets no usage of a pattern of another author they may not read, and in "Uses" only the bare
	 * reference of such a part.
	 */
	public function test_usage_route_hides_unreadable_patterns(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$private = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'private',
				'post_title'  => 'Secret',
				'post_author' => $author,
			)
		);
		$draft   = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'draft',
				'post_title'  => 'Unfinished',
				'post_author' => $author,
			)
		);
		$public  = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $private . '} /--><!-- wp:block {"ref":' . $draft . '} /-->',
			)
		);
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/usage' );
		foreach ( array( $private, $draft ) as $id ) {
			$request->set_param( 'ref', 'block:' . $id );
			$this->assertSame( 403, rest_do_request( $request )->get_status() );
		}

		$request->set_param( 'ref', 'block:' . $public );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$uses = $response->get_data()['uses'];
		$this->assertSame( array( 'block:' . $private, 'block:' . $draft ), wp_list_pluck( $uses, 'ref' ) );
		$this->assertSame( array( 'block:' . $private, 'block:' . $draft ), wp_list_pluck( $uses, 'title' ) );
		$this->assertSame( array( '', '' ), wp_list_pluck( $uses, 'effective_status' ) );
	}

	/**
	 * Unreadable posts are left out before the limit, so they cannot push readable ones out; a cut list is flagged.
	 */
	public function test_used_by_filters_before_the_limit(): void {
		$content = '<!-- wp:block {"ref":13} /-->';
		self::factory()->post->create_many(
			Usage_Index::LIMIT + 1,
			array(
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_author'  => self::$admin,
				'post_title'   => 'A draft',
			)
		);
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$public      = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_title'   => 'Z public',
			)
		);
		$own         = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_author'  => $contributor,
				'post_title'   => 'Z own draft',
			)
		);

		Usage_Index::used_by( 'block:13', $more );
		$this->assertTrue( $more );

		wp_set_current_user( $contributor );
		$this->assertEqualsCanonicalizing( array( $public, $own ), wp_list_pluck( Usage_Index::used_by( 'block:13', $more ), 'id' ) );
		$this->assertFalse( $more );
	}

	/**
	 * Templates in the database of a theme that is not active are not listed.
	 */
	public function test_templates_of_other_themes_are_left_out(): void {
		switch_theme( 'leitvia' );
		$other = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'other-landing',
				'post_content' => '<!-- wp:block {"ref":15} /-->',
				'tax_input'    => array( 'wp_theme' => array( 'some-other-theme' ) ),
			)
		);
		$this->assertSame( array( 'block:15' ), get_post_meta( $other, '_leitvia_uses', false ) );
		$this->assertNotContains( $other, wp_list_pluck( Usage_Index::used_by( 'block:15' ), 'id' ) );
	}

	/**
	 * Only a rebuild that started at 0 marks the index as built; deactivation marks it as unbuilt again.
	 */
	public function test_built_flag(): void {
		self::factory()->post->create_many( 3 );
		delete_option( Usage_Index::BUILT );
		$batch = Usage_Index::reindex( 1, 1000 );
		$this->assertTrue( $batch['done'] );
		$this->assertFalse( Usage_Index::is_built() );

		Usage_Index::reindex( 0, 1000 );
		$this->assertTrue( Usage_Index::is_built() );

		LeitviaStudio\Avatars\Avatars::deactivate();
		$this->assertFalse( get_option( Usage_Index::BUILT ) );
	}
}
