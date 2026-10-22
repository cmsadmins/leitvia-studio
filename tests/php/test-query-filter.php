<?php
/**
 * Query filter and Load more.
 *
 * @package LeitviaStudio
 */

/**
 * URL parameters qf-<queryId>-<facet> filter the query of their own core/query block, only by public taxonomies and
 * registered meta keys, with cleaned values.
 */
class Test_Query_Filter extends WP_UnitTestCase {

	/**
	 * Post IDs by category slug.
	 *
	 * @var array<string, list<int>>
	 */
	private array $posts = array();

	/**
	 * Registers a private taxonomy and a meta key, creates two posts in each of two categories.
	 */
	public function set_up(): void {
		parent::set_up();
		register_taxonomy( 'leitvia_secret', 'post', array( 'public' => false ) );
		register_post_meta(
			'post',
			'leitvia_rating',
			array(
				'type'         => 'integer',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		foreach ( array( 'alpha', 'beta' ) as $i => $slug ) {
			$term = self::factory()->category->create( array( 'slug' => $slug ) );
			foreach ( array( 1, 2 ) as $n ) {
				$id = self::factory()->post->create( array( 'post_category' => array( $term ) ) );
				update_post_meta( $id, 'leitvia_rating', $i * 2 + $n );
				update_post_meta( $id, 'leitvia_unregistered', 'x' );
				wp_set_object_terms( $id, 'hidden', 'leitvia_secret' );
				$this->posts[ $slug ][] = $id;
			}
		}
	}

	/**
	 * Resets the request and the registrations.
	 */
	public function tear_down(): void {
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '';
		unregister_taxonomy( 'leitvia_secret' );
		unregister_post_meta( 'post', 'leitvia_rating' );
		parent::tear_down();
	}

	/**
	 * Context of a core/query block with the ID `$id`, as core passes it to the blocks inside.
	 *
	 * @param int $id      The queryId.
	 * @param int $per_page Posts per page.
	 * @return array<string, mixed>
	 */
	private static function context( int $id, int $per_page = 10 ): array {
		return array(
			'queryId' => $id,
			'query'   => array(
				'postType' => 'post',
				'perPage'  => $per_page,
				'order'    => 'asc',
				'orderBy'  => 'ID',
				'inherit'  => false,
			),
		);
	}

	/**
	 * Post IDs that core/post-template of the query `$id` would show.
	 *
	 * @param int $id The queryId.
	 * @return list<int>
	 */
	private static function ids( int $id ): array {
		$block = new WP_Block( array( 'blockName' => 'core/post-template' ), self::context( $id ) );
		$query = new WP_Query( array_merge( build_query_vars_from_query_block( $block, 1 ), array( 'fields' => 'ids' ) ) );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Query vars of the query `$id`.
	 *
	 * @param int $id The queryId.
	 * @return array<string, mixed>
	 */
	private static function vars( int $id ): array {
		return build_query_vars_from_query_block( new WP_Block( array( 'blockName' => 'core/post-template' ), self::context( $id ) ), 1 );
	}

	/**
	 * Public taxonomy filters.
	 */
	public function test_public_taxonomy_filters(): void {
		$_GET['qf-1-category'] = array( 'alpha' );
		$this->assertSame( $this->posts['alpha'], self::ids( 1 ) );
		$_GET['qf-1-category'] = array( 'alpha', 'beta' );
		$this->assertSame( array_merge( $this->posts['alpha'], $this->posts['beta'] ), self::ids( 1 ) );
	}

	/**
	 * Registered meta filters.
	 */
	public function test_registered_meta_filters(): void {
		$_GET['qf-1-leitvia_rating'] = array( '1', '4' );
		$this->assertSame( array( $this->posts['alpha'][0], $this->posts['beta'][1] ), self::ids( 1 ) );
	}

	/**
	 * Private taxonomy and unregistered meta are ignored.
	 */
	public function test_private_taxonomy_and_unregistered_meta_are_ignored(): void {
		$_GET['qf-1-leitvia_secret']       = array( 'nothing' );
		$_GET['qf-1-leitvia_unregistered'] = 'y';
		$_GET['qf-1-post_status']          = 'draft';
		$vars                              = (string) wp_json_encode( self::vars( 1 ) );
		$this->assertStringNotContainsString( 'leitvia_secret', $vars );
		$this->assertStringNotContainsString( 'leitvia_unregistered', $vars );
		$this->assertStringNotContainsString( 'draft', $vars );
		$this->assertCount( 4, self::ids( 1 ) );
	}

	/**
	 * Values are cleaned.
	 */
	public function test_values_are_cleaned(): void {
		$_GET['qf-1-category']       = array( "alpha' OR 1=1 --", array( 'nested' ), 'beta' );
		$_GET['qf-1-leitvia_rating'] = array( '1; DROP TABLE wp_posts', '3' );
		$vars                        = self::vars( 1 );
		$this->assertSame( array( 'alpha-or-11', 'beta' ), $vars['tax_query'][0]['terms'] );
		$this->assertSame( array( '3' ), $vars['meta_query'][0]['value'] );
		$this->assertSame( 'NUMERIC', $vars['meta_query'][0]['type'] );
		$this->assertSame( array( $this->posts['beta'][0] ), self::ids( 1 ) );
	}

	/**
	 * Two queries do not affect each other.
	 */
	public function test_two_queries_do_not_affect_each_other(): void {
		$_GET['qf-1-category'] = array( 'alpha' );
		$_GET['qf-2-category'] = array( 'beta' );
		$this->assertSame( $this->posts['alpha'], self::ids( 1 ) );
		$this->assertSame( $this->posts['beta'], self::ids( 2 ) );
		$this->assertCount( 4, self::ids( 3 ) );
	}

	/**
	 * An existing tax query stays nested.
	 */
	public function test_an_existing_tax_query_stays_nested(): void {
		$_GET['qf-1-category'] = array( 'alpha' );
		$block                 = new WP_Block( array( 'blockName' => 'core/post-template' ), self::context( 1 ) );
		$vars                  = LeitviaStudio\Blocks\Query_Filter::query_vars(
			array(
				'post_type' => 'post',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query' => array(
					'relation' => 'OR',
					array(
						'taxonomy' => 'category',
						'terms'    => array( 1 ),
					),
				),
			),
			$block
		);
		$this->assertSame( 'AND', $vars['tax_query']['relation'] );
		$this->assertSame( 'OR', $vars['tax_query'][0]['relation'] );
		$this->assertSame( array( 'alpha' ), $vars['tax_query'][1]['terms'] );
	}

	/**
	 * Renders a block inside the query `$id`.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Attributes.
	 * @param int                  $per   Posts per page.
	 */
	private static function render( string $name, array $attrs = array(), int $per = 10 ): string {
		$_SERVER['REQUEST_URI'] = '/filtered/?' . http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ( new WP_Block(
			array(
				'blockName' => $name,
				'attrs'     => $attrs,
			),
			self::context( 1, $per )
		) )->render();
	}

	/**
	 * Renders a get form with facets count and reset.
	 */
	public function test_renders_a_get_form_with_facets_count_and_reset(): void {
		$_GET = array(
			'qf-1-category' => array( 'alpha' ),
			'qf-2-category' => array( 'beta' ),
			'query-1-page'  => '1',
		);
		$html = self::render(
			'leitvia/query-filter',
			array(
				'facets' => array(
					array(
						'key'     => 'category',
						'control' => 'checkbox',
					),
					array(
						'key'     => 'leitvia_rating',
						'control' => 'select',
					),
					array(
						'key'     => 'leitvia_secret',
						'control' => 'checkbox',
					),
				),
			)
		);
		$this->assertStringContainsString( '<form method="get" role="search" aria-label="Filter" data-leitvia-query="1">', $html );
		$this->assertStringContainsString( '<fieldset><legend>Categories</legend>', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="qf-1-category[]" value="alpha" checked=\'checked\'>', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="qf-1-category[]" value="beta">', $html );
		$this->assertMatchesRegularExpression( '#<label for="leitvia-query-filter-\d+">leitvia_rating</label><select id="leitvia-query-filter-\d+" name="qf-1-leitvia_rating"><option value="">All</option><option value="1">1</option>#', $html );
		$this->assertStringNotContainsString( 'leitvia_secret', $html );
		// The filters of query 2 survive a submit, the page of query 1 does not.
		$this->assertStringContainsString( '<input type="hidden" name="qf-2-category[]" value="beta">', $html );
		$this->assertStringNotContainsString( 'name="query-1-page"', $html );
		$this->assertStringContainsString( '>Apply filters</button>', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*qf-2-category[^"]*">Reset filters</a>#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<a href="[^"]*(qf-1-|query-1-page)[^"]*">Reset filters</a>#', $html );
		$this->assertStringContainsString( '<p class="wp-block-leitvia-query-filter__count" role="status">2 results</p>', $html );
	}

	/**
	 * Load more links to the next page until the last.
	 */
	public function test_load_more_links_to_the_next_page_until_the_last(): void {
		$_GET['qf-1-category'] = array( 'alpha', 'beta' );
		$html                  = self::render( 'leitvia/load-more', array(), 3 );
		$this->assertMatchesRegularExpression( '#<a class="wp-element-button" href="[^"]*query-1-page=2[^"]*" rel="next">Load more</a>#', $html );
		$this->assertStringContainsString( 'qf-1-category', $html );
		$this->assertStringContainsString( 'data-loaded="More results loaded: %d"', $html );

		$_GET['query-1-page'] = '2';
		$this->assertSame( '', self::render( 'leitvia/load-more', array(), 3 ) );
	}
}
