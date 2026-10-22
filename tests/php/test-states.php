<?php
/**
 * State variants of patterns and the stored accessibility results.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Pattern_Meta;

/**
 * Pattern_Meta::create_state(), GET and POST /patterns/states and POST /patterns/<id>/checks.
 */
class Test_States extends WP_UnitTestCase {

	/**
	 * Markup with text in every lengthened block, an empty paragraph, an image without source and a list.
	 */
	private const CONTENT = '<!-- wp:heading --><h2 class="wp-block-heading">Plan your visit</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>Opening hours and <strong>prices</strong> at a glance.</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->'
		. '<!-- wp:image --><figure class="wp-block-image"><img alt=""/></figure><!-- /wp:image -->'
		. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>First</li><!-- /wp:list-item --><!-- wp:list-item --><li>Second</li><!-- /wp:list-item --><!-- wp:list-item --><li>Third</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
		. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Book now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static int $editor;

	/**
	 * Creates the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Activates the Leitvia theme so its patterns are registered.
	 */
	public function set_up(): void {
		parent::set_up();
		Pattern_Meta::register();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_set_current_user( self::$editor );
	}

	/**
	 * Creates a wp_block.
	 *
	 * @param string $content Block markup.
	 */
	private function block( string $content = self::CONTENT ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'State test pattern',
				'post_content' => $content,
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
	 * Visible text of block markup.
	 *
	 * @param string $content Block markup.
	 */
	private function text( string $content ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( do_blocks( $content ) ) ) );
	}

	/**
	 * Content of a post.
	 *
	 * @param int|\WP_Error $id Post ID.
	 */
	private function content( $id ): string {
		$this->assertIsInt( $id );
		return get_post( $id )->post_content;
	}

	/**
	 * The long state has at least three times the text, its blocks stay valid and the heading gets a long word.
	 */
	public function test_long_state(): void {
		$original = $this->block();
		$state    = Pattern_Meta::create_state( $original, 'long' );
		$content  = $this->content( $state );

		$this->assertSame( 'long', get_post_meta( $state, '_leitvia_lab_state', true ) );
		$this->assertSame( $original, (int) get_post_meta( $state, '_leitvia_lab_variant_of', true ) );
		$this->assertSame( 'State test pattern (Long text)', get_the_title( $state ) );
		$this->assertGreaterThanOrEqual( 3 * strlen( $this->text( self::CONTENT ) ), strlen( $this->text( $content ) ) );
		$this->assertSame( $content, serialize_blocks( parse_blocks( $content ) ) );
		$this->assertStringContainsString( '>Plan your visit Plan your visit Plan your visit PlanyourvisitPlanyourvisitPlanyourvisit</h2>', $content );
		$this->assertStringContainsString( '<p>Opening hours and Opening hours and Opening hours and <strong>prices prices prices</strong>', $content );
		$this->assertStringContainsString( '>Book now Book now Book now</a>', $content );
		// Block comments and tags are untouched: the same blocks in the same order.
		$names = static fn( string $markup ): array => wp_list_pluck( parse_blocks( $markup ), 'blockName' );
		$this->assertSame( $names( self::CONTENT ), $names( $content ) );
	}

	/**
	 * Text with markup characters is escaped again after lengthening.
	 */
	public function test_long_state_keeps_escaping(): void {
		$content = Pattern_Meta::long_content( '<!-- wp:paragraph --><p>Fish &amp; chips &lt;3</p><!-- /wp:paragraph -->' );
		$this->assertSame( '<!-- wp:paragraph --><p>Fish &amp; chips &lt;3 Fish &amp; chips &lt;3 Fish &amp; chips &lt;3</p><!-- /wp:paragraph -->', $content );
	}

	/**
	 * The empty state drops empty paragraphs and images without a source and keeps one list item.
	 */
	public function test_empty_state(): void {
		$content = $this->content( Pattern_Meta::create_state( $this->block(), 'empty' ) );

		$this->assertStringNotContainsString( '<p></p>', $content );
		$this->assertStringNotContainsString( 'wp:image', $content );
		$this->assertStringContainsString( '<li>First</li>', $content );
		$this->assertStringNotContainsString( 'Second', $content );
		$this->assertStringNotContainsString( 'Third', $content );
		$this->assertStringContainsString( 'Opening hours', $content );
		$this->assertSame( $content, serialize_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Error and disabled states are exact copies; a state exists once per pattern.
	 */
	public function test_manual_states_and_duplicates(): void {
		$original = $this->block();
		foreach ( array( 'error', 'disabled' ) as $state ) {
			$id = Pattern_Meta::create_state( $original, $state );
			$this->assertSame( get_post( $original )->post_content, $this->content( $id ), $state );
			$this->assertSame( $state, get_post_meta( $id, '_leitvia_lab_state', true ) );
		}
		$again = Pattern_Meta::create_state( $original, 'error' );
		$this->assertWPError( $again );
		$this->assertSame( 'leitvia_studio_state_exists', $again->get_error_code() );
		$this->assertWPError( Pattern_Meta::create_state( $original, '' ) );
		$this->assertEqualsCanonicalizing( array( 'error', 'disabled' ), array_keys( Pattern_Meta::states( $original ) ) );

		// A trashed state may be made again.
		wp_trash_post( Pattern_Meta::states( $original )['error'] );
		$this->assertIsInt( Pattern_Meta::create_state( $original, 'error' ) );
	}

	/**
	 * A state of a theme pattern is a new wp_block that names the pattern and stays out of the pattern lists.
	 */
	public function test_theme_pattern_state(): void {
		$slug    = 'leitvia/hero-centered';
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		$state   = Pattern_Meta::create_state( $slug, 'error' );

		$this->assertSame( (string) $pattern['content'], $this->content( $state ) );
		$this->assertSame( 'wp_block', get_post_type( $state ) );
		$this->assertSame( 0, (int) get_post_meta( $state, '_leitvia_lab_variant_of', true ) );
		$this->assertSame( $slug, get_post_meta( $state, '_leitvia_lab_source_pattern', true ) );
		$this->assertSame( array( 'error' => $state ), Pattern_Meta::states( $slug ) );
		$this->assertSame( 'leitvia_studio_state_exists', Pattern_Meta::create_state( $slug, 'error' )->get_error_code() );
		$this->assertSame( 'leitvia_studio_no_pattern', Pattern_Meta::create_state( 'leitvia/does-not-exist', 'error' )->get_error_code() );

		$long = $this->content( Pattern_Meta::create_state( $slug, 'long' ) );
		$this->assertSame( $long, serialize_blocks( parse_blocks( $long ) ) );

		$blocks = wp_list_pluck( $this->request( 'GET', '/wp/v2/blocks', array( 'per_page' => 100 ) )->get_data(), 'id' );
		$this->assertNotContains( $state, $blocks );
		$lab = wp_list_pluck( $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'per_page' => 100 ) )->get_data(), 'ref' );
		$this->assertNotContains( 'block:' . $state, $lab );
	}

	/**
	 * GET and POST /leitvia-studio/v1/patterns/states.
	 */
	public function test_states_route(): void {
		$original = $this->block();
		$ref      = 'block:' . $original;

		$created = $this->request( 'POST', '/leitvia-studio/v1/patterns/states', compact( 'ref' ) + array( 'state' => 'long' ) );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'long', $created->get_data()['state'] );
		$this->assertSame( 409, $this->request( 'POST', '/leitvia-studio/v1/patterns/states', compact( 'ref' ) + array( 'state' => 'long' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/leitvia-studio/v1/patterns/states', compact( 'ref' ) + array( 'state' => '' ) )->get_status() );
		$this->assertSame(
			201,
			$this->request(
				'POST',
				'/leitvia-studio/v1/patterns/states',
				array(
					'ref'   => 'pattern:leitvia/hero-centered',
					'state' => 'empty',
				)
			)->get_status()
		);

		$list = $this->request( 'GET', '/leitvia-studio/v1/patterns/states', compact( 'ref' ) )->get_data();
		$this->assertSame( array( 'long' ), wp_list_pluck( $list, 'state' ) );
		$this->assertSame( array( 'empty' ), wp_list_pluck( $this->request( 'GET', '/leitvia-studio/v1/patterns/states', array( 'ref' => 'pattern:leitvia/hero-centered' ) )->get_data(), 'state' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'POST', '/leitvia-studio/v1/patterns/states', compact( 'ref' ) + array( 'state' => 'error' ) )->get_status() );
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/leitvia-studio/v1/patterns/states',
				array(
					'ref'   => 'pattern:leitvia/hero-centered',
					'state' => 'error',
				)
			)->get_status()
		);
		$this->assertSame( 403, $this->request( 'GET', '/leitvia-studio/v1/patterns/states', compact( 'ref' ) )->get_status() );
	}

	/**
	 * POST /patterns/<id>/checks stores the axe result next to the token lint and refuses malformed or huge results.
	 */
	public function test_checks_route(): void {
		$id        = $this->block();
		$violation = array(
			'id'     => 'image-alt',
			'impact' => 'critical',
			'help'   => 'Images must have alternative text',
			'nodes'  => array( 'img' ),
		);
		$route     = '/leitvia-studio/v1/patterns/' . $id . '/checks';

		$response = $this->request( 'POST', $route, array( 'axe' => array( 'default|390|original' => array( $violation ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$checks = get_post_meta( $id, '_leitvia_lab_checks', true );
		$this->assertSame( array( $violation ), $checks['axe']['default|390|original'] );
		$this->assertArrayHasKey( 'tokens', $checks );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $checks['axe_checked_at'] );
		$item = wp_list_filter( $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'per_page' => 100 ) )->get_data(), array( 'ref' => 'block:' . $id ) );
		$this->assertSame( array( $violation ), current( $item )['checks']['axe']['default|390|original'] );

		$too_many = array_fill_keys( array_map( static fn( int $i ): string => "default|390|s{$i}", range( 1, 201 ) ), array() );
		$cases    = array(
			array( 'axe' => 'x' ),
			array( 'axe' => array( 'k' => array( array( 'id' => 'x' ) ) ) ),
			array( 'axe' => array( 'k' => array( array( 'nodes' => array( array( 'img' ) ) ) + $violation ) ) ),
			array( 'axe' => array( 'k' => array( array( 'impact' => 'huge' ) + $violation ) ) ),
			array( 'axe' => array( 'k' => array( array( 'nodes' => array_fill( 0, 21, 'img' ) ) + $violation ) ) ),
			array( 'axe' => $too_many ),
		);
		foreach ( $cases as $body ) {
			$this->assertSame( 400, $this->request( 'POST', $route, $body )->get_status(), (string) wp_json_encode( $body ) );
		}
		// Within every per-field limit, but too large as a whole.
		$big  = array( 'nodes' => array_fill( 0, 20, str_repeat( 'a', 500 ) ) ) + $violation;
		$huge = array_fill_keys( array_map( static fn( int $i ): string => "default|390|s{$i}", range( 1, 30 ) ), array_fill( 0, 10, $big ) );
		// The body length is checked before the schema walks the value, also for a malformed value.
		foreach ( array( $huge, str_repeat( 'a', 300000 ) ) as $axe ) {
			$response = $this->request( 'POST', $route, array( 'axe' => $axe ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'leitvia_studio_checks_too_large', $response->get_data()['data']['details']['axe']['code'] );
		}
		$this->assertSame( array( $violation ), get_post_meta( $id, '_leitvia_lab_checks', true )['axe']['default|390|original'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->request( 'POST', $route, array( 'axe' => array() ) )->get_status() );
	}

	/**
	 * Only preview requests with axe=1 load axe-core; every preview has one document title.
	 */
	public function test_preview_loads_axe_on_request_only(): void {
		$this->assertFileIsReadable( dirname( LEITVIA_STUDIO_FILE ) . '/build/axe-runner.asset.php', 'Run the build first.' );
		$query = array(
			'ref'      => 'block:' . $this->block(),
			'_wpnonce' => wp_create_nonce( 'leitvia_studio_preview' ),
		);
		foreach ( array(
			''  => false,
			'1' => true,
		) as $axe => $loaded ) {
			$GLOBALS['wp_scripts'] = null;
			$GLOBALS['wp_styles']  = null;
			$body                  = \LeitviaStudio\Lab\Preview::respond( $query + array( 'axe' => (string) $axe ) )['body'];
			$this->assertSame( $loaded, str_contains( $body, 'build/axe-runner.js' ), "axe={$axe}" );
			// axe checks the document title too; the preview has exactly one.
			$this->assertSame( 1, substr_count( $body, '<title' ), "axe={$axe}" );
			$this->assertStringContainsString( '<title>Pattern preview</title>', $body );
		}
	}

	/**
	 * Lab items carry the latest change of the pattern and its states; theme patterns show the results of their states.
	 */
	public function test_modified_and_theme_results(): void {
		global $wpdb;
		$original = $this->block();
		$state    = Pattern_Meta::create_state( $original, 'error' );
		$dated    = static function ( int $id, string $date ) use ( $wpdb ): void {
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $date ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			clean_post_cache( $id );
		};
		$dated( $original, '2020-01-01 00:00:00' );
		$dated( $state, '2030-01-01 00:00:00' );
		$items = $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'per_page' => 100 ) )->get_data();
		$item  = current( wp_list_filter( $items, array( 'ref' => 'block:' . $original ) ) );
		$this->assertSame( '2030-01-01T00:00:00+00:00', $item['modified'] );

		$slug      = 'leitvia/hero-centered';
		$long      = Pattern_Meta::create_state( $slug, 'long' );
		$violation = array(
			'id'     => 'image-alt',
			'impact' => 'critical',
			'help'   => 'Images must have alternative text',
			'nodes'  => array( 'img' ),
		);
		$this->assertSame( 200, $this->request( 'POST', '/leitvia-studio/v1/patterns/' . $long . '/checks', array( 'axe' => array( 'default|390|long' => array( $violation ) ) ) )->get_status() );
		$dated( $long, '2031-01-01 00:00:00' );
		$items = $this->request( 'GET', '/leitvia-studio/v1/patterns', array( 'search' => $slug ) )->get_data();
		$item  = current( wp_list_filter( $items, array( 'ref' => 'pattern:' . $slug ) ) );
		$this->assertSame( array( 'default|390|long' => array( $violation ) ), $item['checks']['axe'] );
		$this->assertArrayHasKey( 'tokens', $item['checks'] );
		$this->assertArrayHasKey( 'axe_checked_at', $item['checks'] );
		$this->assertSame( '2031-01-01T00:00:00+00:00', $item['modified'] );
	}

	/**
	 * References follow core pattern names: lower case letters, digits, hyphen, underscore and dot.
	 */
	public function test_pattern_references_like_core(): void {
		$response = $this->request(
			'POST',
			'/leitvia-studio/v1/patterns/states',
			array(
				'ref'   => 'pattern:my_plugin/hero.v2',
				'state' => 'error',
			)
		);
		$this->assertSame( 'leitvia_studio_no_pattern', $response->get_data()['code'] );
		register_block_pattern(
			'my_plugin/hero.v2',
			array(
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertSame(
			201,
			$this->request(
				'POST',
				'/leitvia-studio/v1/patterns/states',
				array(
					'ref'   => 'pattern:my_plugin/hero.v2',
					'state' => 'error',
				)
			)->get_status()
		);
		unregister_block_pattern( 'my_plugin/hero.v2' );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/leitvia-studio/v1/patterns/states',
				array(
					'ref'   => 'pattern:My/Hero',
					'state' => 'error',
				)
			)->get_status()
		);
	}
}
