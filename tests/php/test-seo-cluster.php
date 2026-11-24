<?php
/**
 * Topic clusters, llms.txt, AI crawlers, redirects and the rating of the organization.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Settings;
use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\News\Schema;
use LeitviaStudio\Organization\Organization;
use LeitviaStudio\Seo\Cluster;
use LeitviaStudio\Seo\Llms;
use LeitviaStudio\Seo\Redirects;

/**
 * Seo\Cluster, Seo\Llms, Seo\Redirects, the abilities set-page-structure, set-redirects and set-llms, the rating of
 * set-organization and the star check of update-seo, compose-page on an existing page.
 */
class Test_Seo_Cluster extends WP_UnitTestCase {

	/**
	 * Agent user at full autonomy and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		// Full autonomy, settings and organization data included, so every write goes live.
		update_option(
			Gateway::OPTION,
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(
						'content'   => 'autonomous',
						'structure' => 'autonomous',
					),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		update_option( 'blog_public', '1' );
		update_option( 'blogname', 'Bike Shop' );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities.
	 */
	public function tear_down(): void {
		self::unregister();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Unregisters the abilities of Leitvia and the category.
	 */
	private static function unregister(): void {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
	}

	/**
	 * A published page.
	 *
	 * @param string $title Title.
	 */
	private function page( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $title . ' for small companies.',
			)
		);
	}

	/**
	 * Runs set-page-structure with the hash of the page.
	 *
	 * @param int                  $post  Page.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function structure( int $post, array $input ) {
		return wp_get_ability( 'leitvia/set-page-structure' )->execute(
			$input + array(
				'post'      => $post,
				'base_hash' => \LeitviaStudio\Gateway\Hashes::document( $post ),
			)
		);
	}

	/**
	 * A page names its hub and its service through the ability; the breadcrumbs and the BreadcrumbList show Home, the
	 * hub and the page, the Service carries an Offer with a net price. A loop and an unknown frame are refused, and the
	 * change rolls back.
	 */
	public function test_hub_breadcrumbs_and_service(): void {
		$hub  = $this->page( 'Care' );
		$page = $this->page( 'Website care' );
		$done = self::structure(
			$page,
			array(
				'frame'   => 'service',
				'hub'     => $hub,
				'service' => array(
					'name'  => 'Website care',
					'price' => 29,
					'unit'  => 'per month',
				),
			)
		);
		$this->assertSame( 'live', $done['mode'], wp_json_encode( $done ) );
		$this->assertSame( array( $hub ), Cluster::hubs( $page ) );
		$this->assertSame( 'service', get_post_meta( $page, Composition::FRAME_META, true ) );
		$this->assert_rejected( 'hub', self::structure( $hub, array( 'hub' => $page ) ) );
		$this->assert_rejected( 'frame', self::structure( $page, array( 'frame' => 'no-such-frame' ) ) );

		$this->go_to( get_permalink( $page ) );
		$items = Cluster::breadcrumbs(
			array(
				array(
					'label' => 'Home',
					'url'   => home_url( '/' ),
				),
				array( 'label' => 'Website care' ),
			)
		);
		$this->assertSame( array( 'Home', 'Care', 'Website care' ), array_column( $items, 'label' ) );
		$graph = Schema::current()['@graph'];
		$types = array_column( $graph, '@type' );
		$this->assertSame( array( 'BreadcrumbList', 'Service' ), $types );
		$this->assertSame( array( 'Bike Shop', 'Care', 'Website care' ), array_column( $graph[0]['itemListElement'], 'name' ) );
		$offer = $graph[1]['offers'];
		$this->assertSame( array( 29.0, 'EUR', false, 'per month' ), array( $offer['price'], $offer['priceCurrency'], $offer['priceSpecification']['valueAddedTaxIncluded'], $offer['priceSpecification']['unitText'] ) );

		$this->assertSame( 'rolled_back', wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $done['package'] ) )['state'] );
		$this->assertSame( array(), Cluster::hubs( $page ) );
		$this->assertNull( Cluster::service( $page ) );
	}

	/**
	 * A service with packages prints one Offer per package in an AggregateOffer with the lowest and highest price; a
	 * range prints an AggregateOffer; a range that ends below its start is refused.
	 */
	public function test_service_with_packages_and_range(): void {
		$page = $this->page( 'Care' );
		$done = self::structure(
			$page,
			array(
				'service' => array(
					'name'   => 'Website care',
					'unit'   => 'per month',
					'offers' => array(
						array(
							'name'  => 'Small',
							'price' => 29,
						),
						array(
							'name'        => 'Large',
							'price'       => 149,
							'description' => 'With weekly backups.',
							'unit'        => 'per month, billed yearly',
						),
					),
				),
			)
		);
		$this->assertSame( 'live', $done['mode'], wp_json_encode( $done ) );
		$this->assertSame( 29.0, Cluster::service( $page )['price'], 'the lowest package is the price from' );
		$node = Cluster::service_node( get_post( $page ), array( '@id' => 'org' ) );
		$this->assertSame( array( 'AggregateOffer', 29.0, 149.0, 2 ), array( $node['offers']['@type'], $node['offers']['lowPrice'], $node['offers']['highPrice'], $node['offers']['offerCount'] ) );
		$this->assertSame( array( 'Small', 'Large' ), array_column( $node['offers']['offers'], 'name' ) );
		$this->assertSame( 'per month', $node['offers']['offers'][0]['priceSpecification']['unitText'] );
		$this->assertSame( 'per month, billed yearly', $node['offers']['offers'][1]['priceSpecification']['unitText'] );

		$range = self::structure(
			$page,
			array(
				'service' => array(
					'name'      => 'Website care',
					'price'     => 89,
					'max_price' => 149,
				),
			)
		);
		$this->assertSame( 'live', $range['mode'], wp_json_encode( $range ) );
		$node = Cluster::service_node( get_post( $page ), array( '@id' => 'org' ) );
		$this->assertSame( array( 'AggregateOffer', 89.0, 149.0 ), array( $node['offers']['@type'], $node['offers']['lowPrice'], $node['offers']['highPrice'] ) );

		// A package with a range carries it in its price specification, highPrice is the top of every range.
		$ranges = self::structure(
			$page,
			array(
				'service' => array(
					'name'   => 'Website care',
					'offers' => array(
						array(
							'name'      => 'Small',
							'price'     => 29,
							'max_price' => 59,
						),
						array(
							'name'      => 'Large',
							'price'     => 149,
							'max_price' => 490,
						),
					),
				),
			)
		);
		$this->assertSame( 'live', $ranges['mode'], wp_json_encode( $ranges ) );
		$node = Cluster::service_node( get_post( $page ), array( '@id' => 'org' ) );
		$this->assertSame( array( 29.0, 490.0 ), array( $node['offers']['lowPrice'], $node['offers']['highPrice'] ) );
		$this->assertSame( array( 29.0, 59.0 ), array( $node['offers']['offers'][0]['priceSpecification']['minPrice'], $node['offers']['offers'][0]['priceSpecification']['maxPrice'] ) );
		$single = self::structure(
			$page,
			array(
				'service' => array(
					'name'      => 'Website care',
					'max_price' => 990,
					'offers'    => array(
						array(
							'name'  => 'Project',
							'price' => 490,
						),
					),
				),
			)
		);
		$this->assertSame( 'live', $single['mode'], wp_json_encode( $single ) );
		$node = Cluster::service_node( get_post( $page ), array( '@id' => 'org' ) );
		$this->assertSame( array( 490.0, 990.0, 1 ), array( $node['offers']['lowPrice'], $node['offers']['highPrice'], $node['offers']['offerCount'] ), 'max_price of the service counts with one package' );
		$this->assert_rejected(
			'service',
			self::structure(
				$page,
				array(
					'service' => array(
						'name'   => 'Website care',
						'offers' => array(
							array(
								'name'      => 'Small',
								'price'     => 59,
								'max_price' => 29,
							),
						),
					),
				)
			)
		);
		$this->assert_rejected(
			'service',
			self::structure(
				$page,
				array(
					'service' => array(
						'name'      => 'Website care',
						'price'     => 149,
						'max_price' => 89,
					),
				)
			)
		);
	}

	/**
	 * A page below an overview page that names another hub shows the hub, and the hub of the hub, not its parent:
	 * Home, hub of the hub, hub, page; a page without a hub keeps its parents.
	 */
	public function test_hub_wins_over_parent(): void {
		$overview = $this->page( 'Overview' );
		$top      = $this->page( 'Services' );
		$hub      = $this->page( 'Care' );
		$page     = $this->page( 'Website care' );
		$plain    = $this->page( 'Imprint' );
		wp_update_post(
			array(
				'ID'          => $page,
				'post_parent' => $overview,
			)
		);
		wp_update_post(
			array(
				'ID'          => $plain,
				'post_parent' => $overview,
			)
		);
		update_post_meta( $page, Cluster::HUB_META, $hub );
		update_post_meta( $hub, Cluster::HUB_META, $top );
		$this->assertSame( array( $top, $hub ), Cluster::hubs( $page ) );
		$this->assertSame( array(), Cluster::hubs( $plain ) );

		$this->go_to( get_permalink( $page ) );
		$core = array(
			array(
				'label' => 'Home',
				'url'   => home_url( '/' ),
			),
			array(
				'label' => 'Overview',
				'url'   => get_permalink( $overview ),
			),
			array( 'label' => 'Website care' ),
		);
		$this->assertSame( array( 'Home', 'Services', 'Care', 'Website care' ), array_column( Cluster::breadcrumbs( $core ), 'label' ) );
		$this->assertSame( array( 'Bike Shop', 'Services', 'Care', 'Website care' ), array_column( Cluster::trail( get_post( $page ) )['itemListElement'], 'name' ) );
		$this->assertSame( array( 'Bike Shop', 'Overview', 'Imprint' ), array_column( Cluster::trail( get_post( $plain ) )['itemListElement'], 'name' ) );
	}

	/**
	 * The llms.txt names the site, the hubs and the pages with a price; robots.txt keeps AI crawlers allowed until
	 * set-llms switches them off.
	 */
	public function test_llms_and_robots(): void {
		$hub  = $this->page( 'Care' );
		$page = $this->page( 'Website care' );
		update_post_meta( $hub, Composition::FRAME_META, 'hub' );
		update_post_meta(
			$page,
			Cluster::SERVICE_META,
			array(
				'name'  => 'Website care',
				'price' => 29.5,
			)
		);
		update_option( 'blogdescription', 'Websites for small companies' );
		$text = Llms::text();
		$this->assertStringStartsWith( "# Bike Shop\n\n> Websites for small companies\n", $text );
		$this->assertStringContainsString( '- [Care](' . get_permalink( $hub ) . '): Care for small companies.', $text );
		$this->assertStringContainsString( '- [Website care](' . get_permalink( $page ) . '): Website care for small companies. From 29.50 EUR, plus VAT.', $text );
		$this->assertStringNotContainsString( 'GPTBot', Llms::robots( "User-agent: *\nDisallow:\n", true ) );

		$this->apply(
			wp_get_ability( 'leitvia/set-llms' )->execute(
				array(
					'summary'     => 'Care for websites.',
					'ai_crawlers' => false,
					'base_hash'   => '',
				)
			)
		);
		$this->assertStringContainsString( '> Care for websites.', Llms::text() );
		$robots = Llms::robots( "User-agent: *\nDisallow:\n", true );
		$this->assertStringContainsString( "User-agent: GPTBot\n", $robots );
		$this->assertStringContainsString( "Disallow: /\n# llms.txt: " . home_url( '/llms.txt' ), $robots );
		$this->assertSame( 'x', Llms::robots( 'x', false ) );
	}

	/**
	 * An add-on adds a section to llms.txt through leitvia_studio_llms_sections; drafts and entries that are no posts
	 * stay out, a page already listed is not repeated.
	 */
	public function test_llms_sections_filter(): void {
		$hub   = $this->page( 'Care' );
		$guide = $this->page( 'Guide' );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Draft guide',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $hub, Composition::FRAME_META, 'hub' );
		$add = static function ( array $sections ) use ( $hub, $guide, $draft ): array {
			$sections['Guides'] = array( get_post( $guide ), get_post( $draft ), get_post( $hub ), 'not a post' );
			return $sections;
		};
		add_filter( 'leitvia_studio_llms_sections', $add );
		$text = Llms::text();
		remove_filter( 'leitvia_studio_llms_sections', $add );
		$this->assertStringContainsString( "## Guides\n\n- [Guide](" . get_permalink( $guide ) . ')', $text );
		$this->assertStringNotContainsString( 'Draft guide', $text );
		$this->assertSame( 1, substr_count( $text, '[Care](' ) );
	}

	/**
	 * The ability set-page-structure stores the offerings a page names and clears them with null; an add-on gives the page its
	 * service through leitvia_studio_page_service, which the Service node and llms.txt then show; the term meta of an
	 * add-on joins the state of a term.
	 */
	public function test_offerings_and_service_filter(): void {
		$page = $this->page( 'Care' );
		$done = self::structure(
			$page,
			array(
				'offerings' => array(
					'line' => 'website-care',
					'skus' => array( 'CARE-S', 'CARE-M' ),
				),
			)
		);
		$this->assertSame( 'live', $done['mode'], wp_json_encode( $done ) );
		$this->assertSame(
			array(
				'line' => 'website-care',
				'skus' => array( 'CARE-S', 'CARE-M' ),
			),
			Cluster::offerings( $page )
		);
		$this->assertSame( 'website-care', wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['items'][0]['structure']['offerings']['line'] );
		$this->assertNull( Cluster::service( $page ) );
		$this->assertSame( array(), Cluster::service_pages() );

		$service = static fn( $stored, int $id ) => Cluster::offerings( $id )['line'] ? array(
			'name'         => 'Website care',
			'description'  => '',
			'price'        => 29.0,
			'max_price'    => null,
			'currency'     => 'EUR',
			'vat_included' => false,
			'unit'         => 'per month',
			'offers'       => array(),
		) : $stored;
		add_filter( 'leitvia_studio_page_service', $service, 10, 2 );
		$node    = Cluster::service_node( get_post( $page ), array( '@id' => 'org' ) );
		$pages   = Cluster::service_pages();
		$text    = Llms::text();
		remove_filter( 'leitvia_studio_page_service', $service, 10 );
		$this->assertSame( 29.0, $node['offers']['price'] );
		$this->assertSame( array( $page ), wp_list_pluck( $pages, 'ID' ) );
		$this->assertStringContainsString( '[Care](' . get_permalink( $page ) . '): Care for small companies. From 29 EUR per month, plus VAT.', $text );

		self::structure( $page, array( 'offerings' => null ) );
		$this->assertSame( '', get_post_meta( $page, Cluster::OFFERINGS_META, true ) );
		$this->assertWPError( self::structure( $page, array( 'offerings' => array( 'skus' => array( '-bad' ) ) ) ), 'the schema refuses an article number' );

		register_taxonomy( 'my_line', 'post' );
		$term = (int) wp_insert_term( 'Care', 'my_line' )['term_id'];
		update_term_meta( $term, 'my_icon', 'wrench' );
		$keys  = static fn( array $keys ): array => array( 'my_line' => array( 'my_icon' ) ) + $keys;
		add_filter( 'leitvia_studio_term_meta', $keys );
		$state = \LeitviaStudio\Gateway\Pipeline::term_state( $term );
		remove_filter( 'leitvia_studio_term_meta', $keys );
		$this->assertSame( 'wrench', $state['term']['my_icon'] );
		$add = static fn( array $taxonomies ): array => array_merge( $taxonomies, array( 'my_line' ) );
		add_filter( 'leitvia_studio_gateway_taxonomies', $add );
		$this->assertNotContains( 'my_line', \LeitviaStudio\Gateway\Scope::taxonomies(), 'only taxonomies of the content model join the scope' );
		remove_filter( 'leitvia_studio_gateway_taxonomies', $add );
		unregister_taxonomy( 'my_line' );
	}

	/**
	 * On a site closed to search engines set-llms saves the wish and says why the file waits; get-seo shows the wish
	 * apart from what takes effect.
	 */
	public function test_llms_held_back_on_a_closed_site(): void {
		update_option( 'blog_public', '0' );
		$result = wp_get_ability( 'leitvia/set-llms' )->execute(
			array(
				'enabled'   => true,
				'base_hash' => '',
			)
		);
		// llms.txt is on by default, so the call changes nothing, but the answer still says why the file waits.
		$this->assertStringContainsString( 'open to search engines', (string) ( $result['notice'] ?? '' ), wp_json_encode( $result ) );
		$llms = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $this->page( 'About' ) ) ) )['llms'];
		$this->assertTrue( $llms['requested'] );
		$this->assertFalse( $llms['enabled'] );
		$this->assertStringContainsString( 'open to search engines', $llms['held_back'] );
		update_option( 'blog_public', '1' );
		$this->assertTrue( Llms::enabled() );
		$this->assertSame( '', Llms::held_back() );
	}

	/**
	 * The ability set-permalinks waits for a person, warns when published posts move and sets the structure and the bases; a
	 * structure without a post tag is refused.
	 */
	public function test_set_permalinks(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_option( 'permalink_structure', '/%postname%/' );
		$result = wp_get_ability( 'leitvia/set-permalinks' )->execute(
			array(
				'structure'     => '/news/%postname%/',
				'category_base' => 'topic',
				'base_hash'     => '',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringContainsString( 'leitvia/set-redirects', (string) ( $result['notice'] ?? '' ) );
		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ), 'nothing before a person applies it' );
		$this->apply( $result );
		$this->assertSame( '/news/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertSame( 'topic', get_option( 'category_base' ) );
		$this->assertFalse( get_option( 'rewrite_rules' ), 'the rules are rebuilt on the next request' );
		$this->assert_rejected(
			'structure',
			wp_get_ability( 'leitvia/set-permalinks' )->execute(
				array(
					'structure' => '/%year%/',
					'base_hash' => '',
				)
			)
		);
	}

	/**
	 * Redirects keep valid rows, find loops, and set-redirects refuses invalid rows and loops.
	 */
	public function test_redirects(): void {
		$rows = Redirects::sanitize(
			array(
				array(
					'from' => '/Old-Page/',
					'to'   => '/new-page/',
				),
				array(
					'from' => '/',
					'to'   => '/x/',
				),
				array(
					'from' => '/same/',
					'to'   => '/same',
				),
				array(
					'from' => '/away/',
					'to'   => 'http://example.com/',
				),
				array(
					'from'   => '/gone/',
					'status' => 410,
				),
				array(
					'from'   => '/gone-with-target/',
					'to'     => '/x/',
					'status' => 410,
				),
				array(
					'from'   => '/for-now/',
					'to'     => '/sale/',
					'status' => 302,
				),
				array(
					'from'   => '/teapot/',
					'to'     => '/x/',
					'status' => 418,
				),
				array(
					'from'   => '/no-target/',
					'status' => 301,
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'from'   => '/old-page',
					'to'     => '/new-page/',
					'status' => 301,
				),
				array(
					'from'   => '/gone',
					'status' => 410,
				),
				array(
					'from'   => '/for-now',
					'to'     => '/sale/',
					'status' => 302,
				),
			),
			$rows
		);
		$this->assertSame(
			array(),
			Redirects::loops(
				array(
					array(
						'from'   => '/a',
						'to'     => '/b/',
						'status' => 301,
					),
					array(
						'from'   => '/b',
						'status' => 410,
					),
				)
			),
			'a 410 ends the chain'
		);
		$loop = array(
			array(
				'from' => '/a',
				'to'   => '/b/',
			),
			array(
				'from' => '/b',
				'to'   => '/A/',
			),
			array(
				'from' => '/c',
				'to'   => '/a/',
			),
		);
		$this->assertSame( array( '/a', '/b', '/c' ), Redirects::loops( $loop ) );

		$set = static fn( array $redirects ) => wp_get_ability( 'leitvia/set-redirects' )->execute(
			array(
				'redirects' => $redirects,
				'base_hash' => '',
			)
		);
		$this->assert_rejected( 'redirects', $set( $loop ) );
		$this->assert_rejected(
			'redirects',
			$set(
				array(
					array(
						'from' => '/',
						'to'   => '/x/',
					),
				)
			)
		);
		$this->assert_rejected(
			'redirects',
			$set(
				array(
					array(
						'from'   => '/gone/',
						'to'     => '/x/',
						'status' => 410,
					),
				)
			)
		);
		$this->apply( $set( $rows ) );
		$this->assertSame( $rows, leitvia_studio_setting( 'redirects' ) );

		// The answers: 410 turns the query into a 404 and says 410, 302 leads to its target.
		$codes = array();
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$codes ) {
				$codes[] = (int) $code;
				return $header;
			},
			5,
			2
		);
		$this->go_to( home_url( '/gone/' ) );
		$_SERVER['REQUEST_URI'] = '/gone/';
		Redirects::gone();
		$this->assertTrue( is_404() );
		$this->assertContains( 410, $codes );
		$this->assertStringContainsString( '410 Gone', Redirects::status_gone( 'HTTP/1.1 404 Not Found', 404 ) );
		$to = '';
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$to ) {
				$to = $status . ' ' . $location;
				throw new \RuntimeException( 'redirected' );
			},
			10,
			2
		);
		$_SERVER['REQUEST_URI'] = '/for-now/?x=1';
		try {
			Redirects::maybe_redirect();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$this->assertSame( '302 ' . home_url( '/sale/' ), $to );

		// A from with a query matches that query in any order of its parameters and comes before its bare path; other
		// queries fall back to the bare path. The bare start page stays no from, the start page with a query is one.
		$this->assertSame( '/?a=1&p=45', Redirects::from( '/?p=45&a=1' ) );
		$this->assertSame( '', Redirects::from( '/' ) );
		$this->assertSame( '', Redirects::from( '/x/?' ) );
		$query = Redirects::sanitize(
			array(
				array(
					'from' => '/?p=45',
					'to'   => '/news/first/',
				),
				array(
					'from' => '/media/?x=1&attachment_id=7',
					'to'   => '/photo/',
				),
				array(
					'from' => '/media/',
					'to'   => '/gallery/',
				),
			)
		);
		$this->assertCount( 3, $query );
		$this->apply( $set( $query ) );
		$go = static function ( string $uri ) use ( &$to ): string {
			$to                     = '';
			$_SERVER['REQUEST_URI'] = $uri;
			try {
				Redirects::maybe_redirect();
			} catch ( \RuntimeException $e ) {
				unset( $e );
			}
			return $to;
		};
		$this->assertSame( '301 ' . home_url( '/news/first/' ), $go( '/?p=45' ) );
		$this->assertSame( '301 ' . home_url( '/photo/' ), $go( '/media/?attachment_id=7&x=1' ) );
		$this->assertSame( '301 ' . home_url( '/gallery/' ), $go( '/media/?attachment_id=8' ) );
		$this->assertSame( '', $go( '/?p=46' ) );

		// A refused row is named by its from, a valid row with a query is not.
		$bad = $set(
			array(
				array(
					'from' => '/?p=45',
					'to'   => '/news/',
				),
				array(
					'from'   => '/?p=46',
					'to'     => '/news/',
					'status' => 410,
				),
			)
		);
		$this->assert_rejected( 'redirects', $bad );
		$this->assertStringContainsString( ': /?p=46.', $bad->get_error_message() );
		$this->assertStringNotContainsString( '/?p=45', $bad->get_error_message() );

		// At most MAX rows; the last one still answers.
		$many = array();
		for ( $i = 1; $i <= Redirects::MAX + 1; $i++ ) {
			$many[] = array(
				'from' => '/?attachment_id=' . $i,
				'to'   => '/media/',
			);
		}
		$many = Redirects::sanitize( $many );
		$this->assertCount( Redirects::MAX, $many );
		$this->apply( $set( $many ) );
		$this->assertSame( '301 ' . home_url( '/media/' ), $go( '/?attachment_id=' . Redirects::MAX ) );
		$this->assertSame( '', $go( '/?attachment_id=' . ( Redirects::MAX + 1 ) ) );
	}

	/**
	 * The rating shows only when a person confirmed it, with count and source; a star in an SEO title must equal it.
	 */
	public function test_rating_and_star_title(): void {
		$set = static fn( $rating ) => wp_get_ability( 'leitvia/set-organization' )->execute(
			array(
				'aggregate_rating' => $rating,
				'base_hash'        => '',
			)
		);
		$this->apply(
			$set(
				array(
					'value'      => '4.8',
					'count'      => 31,
					'source_url' => 'https://reviews.example/bike-shop',
					'confirmed'  => false,
				)
			)
		);
		$this->assertArrayNotHasKey( 'aggregateRating', Organization::node() );
		$page   = $this->page( 'Repairs' );
		$update = static fn( string $title ) => wp_get_ability( 'leitvia/update-seo' )->execute(
			array(
				'post'      => $page,
				'title'     => $title,
				'base_hash' => \LeitviaStudio\Gateway\Hashes::document( $page ),
			)
		);
		$this->assert_rejected( 'unsupported_fact', $update( 'Repairs ★ 4.8' ) );

		$this->apply(
			$set(
				array(
					'value'      => '4.8',
					'count'      => 31,
					'source_url' => 'https://reviews.example/bike-shop',
					'confirmed'  => true,
				)
			)
		);
		$this->assertSame( array( 4.8, 31 ), array( Organization::node()['aggregateRating']['ratingValue'], Organization::node()['aggregateRating']['reviewCount'] ) );
		$this->assert_rejected( 'unsupported_fact', $update( 'Repairs ★ 5.0' ) );
		$this->assertSame( 'live', $update( 'Repairs ★ 4,8 from 31 reviews' )['mode'] );
		$this->apply( $set( null ) );
		$this->assertNull( Organization::rating() );
		$this->assertSame( '', Settings::get( 'organization_rating_source' ) );
	}

	/**
	 * The ability compose-page with post rebuilds an existing page in one step, so two heroes in between never meet a check;
	 * the page keeps its address and remembers the frame.
	 */
	public function test_compose_rebuilds_a_page(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'about-us',
				'post_title'   => 'About',
				'post_content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Old</h1><!-- /wp:heading -->',
			)
		);
		$done = wp_get_ability( 'leitvia/compose-page' )->execute(
			array(
				'post'      => $page,
				'title'     => 'About us',
				'frame'     => 'about',
				'sections'  => array(
					array( 'pattern' => 'leitvia/hero-editorial' ),
					array( 'pattern' => 'leitvia/content-two-columns' ),
					array( 'pattern' => 'leitvia/cta-contact' ),
				),
				'base_hash' => \LeitviaStudio\Gateway\Hashes::document( $page ),
			)
		);
		$this->assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( 'live', $done['mode'], wp_json_encode( $done ) );
		$post = get_post( $page );
		$this->assertSame( array( 'About us', 'about-us' ), array( $post->post_title, $post->post_name ) );
		$this->assertStringNotContainsString( '>Old<', $post->post_content );
		$this->assertSame( array( 'leitvia/hero-editorial', 'leitvia/content-two-columns', 'leitvia/cta-contact' ), Composition::sections( $post->post_content ) );
		$this->assertSame( array( 'about', 'page-landing' ), array( get_post_meta( $page, Composition::FRAME_META, true ), get_page_template_slug( $page ) ) );
	}

	/**
	 * The frame article under a page address gets the template page-article with byline and author box, and the page
	 * prints an Article with author and dates; a page marked article by set-page-structure takes the template too.
	 */
	public function test_article_frame_as_page(): void {
		$done = wp_get_ability( 'leitvia/compose-page' )->execute(
			array(
				'title'     => 'Accessible websites explained',
				'frame'     => 'article',
				'type'      => 'page',
				'sections'  => array( array( 'pattern' => 'leitvia/content-text' ) ),
				'base_hash' => '',
			)
		);
		$this->assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() . wp_json_encode( $done->get_error_data() ) : '' );
		$page = (int) $done['target']['post'];
		$this->assertSame( array( 'page', 'article', 'page-article' ), array( get_post_type( $page ), get_post_meta( $page, Composition::FRAME_META, true ), get_page_template_slug( $page ) ) );
		$this->go_to( get_permalink( $page ) );
		$graph   = (array) ( \LeitviaStudio\News\Schema::current()['@graph'] ?? array() );
		$article = array_values( array_filter( $graph, static fn( array $node ): bool => 'Article' === $node['@type'] ) );
		$this->assertCount( 1, $article, wp_json_encode( $graph ) );
		$this->assertArrayHasKey( 'author', $article[0] );
		$this->assertArrayHasKey( 'datePublished', $article[0] );
		$other = $this->page( 'Long read' );
		update_post_meta( $other, Composition::FRAME_META, 'article' );
		$this->go_to( get_permalink( $other ) );
		$this->assertSame( 'page-article.php', apply_filters( 'page_template_hierarchy', array( 'page.php' ) )[0] );
	}

	/**
	 * Applies the change package of a write as an administrator: settings always wait for a person.
	 *
	 * @param mixed $result Result of the write.
	 */
	private function apply( $result ): void {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'package', $result['mode'], wp_json_encode( $result ) );
		$agent = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$report = Packages::apply( $result['package'] );
		wp_set_current_user( $agent );
		$this->assertSame( 'applied', $report['state'], wp_json_encode( $report ) );
	}

	/**
	 * Asserts a rejection for invalid input with the reason.
	 *
	 * @param string $reason Reason.
	 * @param mixed  $result Result.
	 */
	private function assert_rejected( string $reason, $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( $reason, $result->get_error_data()['reason'] ?? '', $result->get_error_message() );
	}
}
