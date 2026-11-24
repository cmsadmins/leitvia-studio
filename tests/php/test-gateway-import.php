<?php
/**
 * Ability leitvia/import-post: HTML and builder shortcodes into core blocks, images into the media library once,
 * author mapping, the update of a post imported before, the dry run and the hosts images may come from.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Import;
use LeitviaStudio\Gateway\Gateway;

/**
 * Images come from a fake HTTP answer with the bytes of the avatar fixture; nothing leaves the test site.
 */
class Test_Gateway_Import extends WP_UnitTestCase {

	const SOURCE = 'https://old.example/news/opening/';

	/**
	 * Addresses the HTTP API was asked for.
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	/**
	 * Agent with the profile full, the abilities and the fake image host.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) {
				$this->requests[] = $url;
				if ( ! empty( $args['filename'] ) ) {
					copy( dirname( __DIR__ ) . '/fixtures/avatar.png', $args['filename'] );
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
					'headers'  => array( 'content-type' => 'image/png' ),
					'cookies'  => array(),
					'filename' => $args['filename'] ?? null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Abilities off again.
	 */
	public function tear_down(): void {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Content of an Impreza page with WPBakery rows, a heading that skips levels, a list, a table, a quote, a video,
	 * a link to the old host and shortcodes of other plugins.
	 */
	private static function builder(): string {
		return '[vc_row][vc_column width="1/2"][vc_column_text]<h1>Opening</h1><p>We open in <strong>May</strong>. <a href="https://old.example/about/">About us</a> and <a href="#">nothing</a>.</p>[/vc_column_text][/vc_column]'
			. '[vc_column width="1/2"][vc_single_image image="55"][vc_btn title="Contact" link="url:%2Fcontact%2F|title:Contact|"][/vc_column][/vc_row]'
			. '[vc_row][vc_column][vc_empty_space height="32px"][us_text text="Our hours" tag="h4"]<ul><li>Monday<ul><li>Morning</li></ul></li><li>Friday</li></ul>'
			. '<table><tr><th>Day</th><th>Time</th></tr><tr><td>Mon</td><td>9-17</td></tr></table>'
			. '<blockquote><p>Great place.</p></blockquote>[us_btn label="Book" link="%7B%22url%22%3A%22https%3A%2F%2Fbook.example%2F%22%7D"]'
			. '[vc_video link="https://www.youtube.com/watch?v=abcdef12345"][contact-form-7 id="12"][plugin_box]Kept text[/plugin_box][/vc_column][/vc_row]';
	}

	/**
	 * Every part becomes a core block, no shortcode stays, and what did not carry over is in the report.
	 */
	public function test_converts_html_and_builder_shortcodes(): void {
		$import = new Import( self::SOURCE, array( 'old.example' ), false, array( '55' => 'https://old.example/wp-content/uploads/hall.png' ), true );
		$blocks = $import->convert( self::builder() );
		$this->assertStringNotContainsString( '[', $blocks, 'no shortcode left' );
		$this->assertSame( $blocks, serialize_blocks( parse_blocks( $blocks ) ), 'well formed block markup' );
		$names = array();
		$walk  = static function ( array $items ) use ( &$walk, &$names ): void {
			foreach ( $items as $block ) {
				if ( $block['blockName'] ) {
					$names[] = $block['blockName'];
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( $blocks ) );
		$this->assertSame( array(), array_filter( $names, static fn( string $name ): bool => ! str_starts_with( $name, 'core/' ) ) );
		foreach ( array( 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/image', 'core/buttons', 'core/list', 'core/list-item', 'core/table', 'core/quote', 'core/embed' ) as $name ) {
			$this->assertContains( $name, $names, $name );
		}
		$this->assertSame( 2, substr_count( $blocks, '<!-- wp:column -->' ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Opening</h2>', $blocks, 'h1 becomes h2' );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Our hours</h3>', $blocks, 'h4 after h2 becomes h3' );
		$this->assertStringContainsString( '<a href="/about/">About us</a>', $blocks );
		$this->assertStringContainsString( 'and nothing.', $blocks, 'a link to # keeps its text' );
		$this->assertStringContainsString( 'href="/contact/">Contact</a>', $blocks );
		$this->assertStringContainsString( 'href="https://book.example/">Book</a>', $blocks );
		$this->assertStringContainsString( '<li>Monday<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Morning</li>', $blocks );
		$this->assertStringContainsString( '<thead><tr><th>Day</th><th>Time</th></tr></thead><tbody><tr><td>Mon</td><td>9-17</td></tr></tbody>', $blocks );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=abcdef12345', $blocks );
		$this->assertStringContainsString( 'Kept text', $blocks );
		$this->assertStringContainsString( 'src="https://old.example/wp-content/uploads/hall.png"', $blocks, 'the dry run shows the source image' );
		$report = $import->report();
		$this->assertSame( 1, $report['links'], 'the link to the old host; /contact/ is a path already' );
		$this->assertSame( array( 'vc_empty_space' => 1 ), $report['dropped'] );
		$this->assertContains( 'contact-form-7', array_column( $report['unmapped'], 'name' ) );
		$this->assertSame( array( 1, 4 ), array_column( $report['headings'], 'from' ) );
	}

	/**
	 * Rendered HTML of a slider or form plugin: scripts, styles, noscript, templates and forms leave no visible text,
	 * also inside a paragraph or an svg, and the report counts them.
	 */
	public function test_code_elements_leave_no_text(): void {
		$import = new Import( self::SOURCE, array( 'old.example' ), false, array(), true );
		$blocks = $import->convert(
			'<p>Welcome<script>var revapi = jQuery("#rev_slider").show();</script></p>'
			. '<style>.rs-layer{color:red}</style><noscript>Enable JavaScript</noscript>'
			. '<form id="gform_1"><label>Your name</label><input name="n"><button>Send</button></form>'
			. '<template><p>Hidden row</p></template><svg><script>alert(1)</script><title>Logo</title></svg>'
			. '<p>Open daily.</p><script type="text/javascript">gform.initializeOnLoaded( function() {'
		);
		$this->assertStringContainsString( 'Welcome', $blocks );
		$this->assertStringContainsString( 'Open daily.', $blocks );
		foreach ( array( 'revapi', 'rs-layer', 'Enable JavaScript', 'Your name', 'Send', 'Hidden row', 'alert', 'gform' ) as $code ) {
			$this->assertStringNotContainsString( $code, $blocks, $code );
		}
		$this->assertSame(
			array(
				'script'   => 3,
				'style'    => 1,
				'noscript' => 1,
				'form'     => 1,
				'template' => 1,
			),
			$import->report()['removed']
		);
	}

	/**
	 * A migration keeps the texts of the live site: SEO texts of the wrong length, a linked image without alt and an
	 * author without an account go through with notes in the report instead of failing the import.
	 */
	public function test_import_keeps_live_values_with_notes(): void {
		$result = wp_get_ability( Import::NAME )->execute(
			array(
				'source'      => self::SOURCE,
				'post_type'   => 'post',
				'title'       => 'Opening in May',
				'content'     => '<p>We open in May.</p><p><a href="https://book.example/"><img src="https://old.example/wp-content/uploads/2024/05/hall.png" alt=""></a></p>',
				'status'      => 'publish',
				'author'      => 'Maria Example',
				'author_name' => 'Maria Example',
				'seo'         => array(
					'title'       => 'Opening in May: the new hall of the shop is open for everyone from Monday',
					'description' => 'Short.',
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 'created', $result['report']['action'] );
		$this->assertCount( 2, $result['report']['seo'], wp_json_encode( $result['report'] ) );
		$this->assertSame( 'https://book.example/', $result['report']['unlinked'][0]['link'] );
		$this->assertSame( 1, $result['report']['unlinked'][0]['image'] );
		$post = Import::find( self::SOURCE );
		$this->assertStringNotContainsString( 'book.example', get_post_field( 'post_content', $post ) );
		$this->assertSame( 'Maria Example', get_post_meta( $post, \LeitviaStudio\News\Schema::AUTHOR_NAME_META, true ) );
		$this->go_to( get_permalink( $post ) );
		the_post();
		$this->assertSame( 'Maria Example', get_the_author() );
	}

	/**
	 * The first call creates the post with its images in the library, the author found by login and the source kept;
	 * the second call with the same source updates it and reuses the images.
	 */
	public function test_import_creates_then_updates(): void {
		self::factory()->category->create( array( 'name' => 'News' ) );
		$editor = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'robin',
			)
		);
		$input  = array(
			'source'         => self::SOURCE,
			'post_type'      => 'post',
			'title'          => 'Opening in May',
			'content'        => '<p>We open in May.</p><p><img src="https://old.example/wp-content/uploads/2024/05/hall-1024x683.png" alt="The new hall"></p>',
			'slug'           => 'opening',
			'date'           => '2024-05-02T10:00:00',
			'status'         => 'publish',
			'author'         => 'robin',
			'terms'          => array( 'category' => array( 'News' ) ),
			'featured_image' => array(
				'url' => 'https://old.example/wp-content/uploads/2024/05/hall.png',
				'alt' => 'The new hall',
			),
		);
		$result = wp_get_ability( Import::NAME )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 'created', $result['report']['action'] );
		$this->assertSame( array( 'https://old.example/wp-content/uploads/2024/05/hall.png' ), array_values( array_unique( $this->requests ) ), 'the original without the size suffix, once ' . wp_json_encode( $result['report'] ) );
		$post = Import::find( self::SOURCE );
		$this->assertGreaterThan( 0, $post );
		$this->assertSame( $editor, (int) get_post_field( 'post_author', $post ) );
		$this->assertSame( '2024-05-02 10:00:00', get_post_field( 'post_date', $post ) );
		$this->assertSame( 'opening', get_post_field( 'post_name', $post ) );
		$this->assertSame( array( 'News' ), wp_get_post_terms( $post, 'category', array( 'fields' => 'names' ) ) );
		$this->assertGreaterThan( 0, Import::find( 'https://old.example/wp-content/uploads/2024/05/hall.png', 'attachment' ) );
		$content = Import::find( 'https://old.example/wp-content/uploads/2024/05/hall-1024x683.png', 'attachment' );
		$this->assertGreaterThan( 0, $content );
		$this->assertStringContainsString( '"id":' . $content, get_post_field( 'post_content', $post ) );
		$this->assertStringContainsString( 'alt="The new hall"', get_post_field( 'post_content', $post ) );
		$this->assertStringNotContainsString( 'old.example', get_post_field( 'post_content', $post ) );
		$this->assertGreaterThan( 0, (int) get_post_thumbnail_id( $post ) );

		$this->requests = array();
		$again          = wp_get_ability( Import::NAME )->execute( array( 'title' => 'Opening in June' ) + $input );
		$this->assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		$this->assertSame( 'updated', $again['report']['action'] );
		$this->assertSame( $post, Import::find( self::SOURCE ) );
		$this->assertSame( 'Opening in June', get_the_title( $post ) );
		$this->assertSame( array(), $this->requests, 'images imported before are reused' );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'post',
					'title'       => 'Opening in June',
					'post_status' => 'any',
				)
			)
		);
	}

	/**
	 * A child page finds its parent by the old address; an unknown author leaves the post with the agent.
	 */
	public function test_parent_source_and_unknown_author(): void {
		$parent = wp_get_ability( Import::NAME )->execute(
			array(
				'source'    => 'https://old.example/services/',
				'post_type' => 'page',
				'title'     => 'Services',
				'content'   => '<p>What we do.</p>',
			)
		);
		$this->assertIsArray( $parent, is_wp_error( $parent ) ? $parent->get_error_message() : '' );
		$child = wp_get_ability( Import::NAME )->execute(
			array(
				'source'        => 'https://old.example/services/repairs/',
				'post_type'     => 'page',
				'title'         => 'Repairs',
				'content'       => '<p>We repair.</p>',
				'parent_source' => 'https://old.example/services/',
				'author'        => 'nobody@example.org',
			)
		);
		$this->assertIsArray( $child, is_wp_error( $child ) ? $child->get_error_message() : '' );
		$this->assertSame( Import::find( 'https://old.example/services/' ), (int) get_post_field( 'post_parent', Import::find( 'https://old.example/services/repairs/' ) ) );
		$this->assertArrayHasKey( 'author', $child['report'] );
	}

	/**
	 * Text in square brackets that is no shortcode stays: a rewrite flag, a placeholder, a character class, a key name;
	 * inside pre and code nothing changes, not even a builder shortcode. Known shortcodes still go.
	 */
	public function test_square_brackets_that_are_no_shortcodes(): void {
		$import = new Import( self::SOURCE, array( 'old.example' ), false, array(), true );
		$blocks = $import->convert(
			'<p>RewriteRule ^old$ /new [R=301,L] and the folder [web_root]/wp.</p>'
			. '<pre><code>location ~* [a-z0-9_-]+ { [vc_row]kept[/vc_row] }</code></pre>'
			. '<p>Sign with [signed-by=/etc/apt/key.gpg] and <code>[gallery]</code>.</p>'
			. '<p>Before[gallery ids="1,2"][plugin_box id="3"]Box text[/plugin_box] after.</p>'
		);
		foreach ( array( '[R=301,L]', '[web_root]/wp', '[a-z0-9_-]+', '[vc_row]kept[/vc_row]', '[signed-by=/etc/apt/key.gpg]', '<code>[gallery]</code>' ) as $text ) {
			$this->assertStringContainsString( $text, $blocks, $text );
		}
		$this->assertStringNotContainsString( 'ids="1,2"', $blocks );
		$this->assertStringNotContainsString( 'plugin_box', $blocks );
		$this->assertStringContainsString( 'Box text', $blocks );
		$this->assertTrue( Import::is_shortcode( 'vc_column_text' ) );
		$this->assertTrue( Import::is_shortcode( 'et_pb_section' ) );
		$this->assertFalse( Import::is_shortcode( 'web_root' ) );
	}

	/**
	 * The author_name holds on every import, also on a second one with the same source, and update-content takes it;
	 * an empty one gives the post its user again.
	 */
	public function test_author_name_on_update(): void {
		$input = array(
			'source'    => self::SOURCE,
			'post_type' => 'post',
			'title'     => 'Opening in May',
			'content'   => '<p>We open in May.</p>',
			'status'    => 'publish',
		);
		$first = wp_get_ability( Import::NAME )->execute( $input );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$post = Import::find( self::SOURCE );
		$this->assertSame( '', get_post_meta( $post, \LeitviaStudio\News\Schema::AUTHOR_NAME_META, true ) );
		$again = wp_get_ability( Import::NAME )->execute( $input + array( 'author_name' => 'Maria Example' ) );
		$this->assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		$this->assertNotSame( 'unchanged', $again['mode'] );
		$this->assertSame( 'Maria Example', get_post_meta( $post, \LeitviaStudio\News\Schema::AUTHOR_NAME_META, true ) );
		$update = wp_get_ability( 'leitvia/update-content' )->execute(
			array(
				'post'        => $post,
				'author_name' => 'Robin Example',
				'base_hash'   => \LeitviaStudio\Gateway\Hashes::document( $post ),
			)
		);
		$this->assertIsArray( $update, is_wp_error( $update ) ? $update->get_error_message() : '' );
		$this->assertSame( 'Robin Example', get_post_meta( $post, \LeitviaStudio\News\Schema::AUTHOR_NAME_META, true ) );
		$clear = wp_get_ability( 'leitvia/update-content' )->execute(
			array(
				'post'        => $post,
				'author_name' => '',
				'base_hash'   => \LeitviaStudio\Gateway\Hashes::document( $post ),
			)
		);
		$this->assertIsArray( $clear, is_wp_error( $clear ) ? $clear->get_error_message() : '' );
		$this->assertSame( '', get_post_meta( $post, \LeitviaStudio\News\Schema::AUTHOR_NAME_META, true ) );
	}

	/**
	 * A parent that waits in the same open package gives its child the parent when the package is applied, also for
	 * children with the same slug in two branches; a parent nobody imported rejects the call instead of a page at the
	 * top level.
	 */
	public function test_parent_source_in_open_package(): void {
		$import = static fn( string $source, string $title, string $slug, string $above = '' ) => wp_get_ability( Import::NAME )->execute(
			array(
				'source'    => 'https://old.example/' . $source,
				'post_type' => 'page',
				'title'     => $title,
				'slug'      => $slug,
				'content'   => '<p>' . $title . '.</p>',
				'status'    => 'publish',
				'package'   => 'migration',
			) + ( '' !== $above ? array( 'parent_source' => 'https://old.example/' . $above ) : array() )
		);
		foreach ( array( array( 'a/', 'Guide A', 'guide-a' ), array( 'b/', 'Guide B', 'guide-b' ), array( 'a/faq/', 'FAQ', 'faq', 'a/' ), array( 'b/faq/', 'FAQ', 'faq', 'b/' ) ) as $args ) {
			$result = $import( ...$args );
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
			$this->assertSame( 'package', $result['mode'] );
		}
		$this->assertArrayHasKey( 'parent', $result['report'] );
		$applied = \LeitviaStudio\Gateway\Packages::apply( (int) $result['package'] );
		$this->assertIsArray( $applied );
		$this->assertSame( array(), $applied['errors'], wp_json_encode( $applied ) );
		foreach ( array( 'a', 'b' ) as $branch ) {
			$parent = Import::find( 'https://old.example/' . $branch . '/' );
			$child  = Import::find( 'https://old.example/' . $branch . '/faq/' );
			$this->assertSame( $parent, (int) get_post_field( 'post_parent', $child ), $branch );
			$this->assertSame( 'faq', get_post_field( 'post_name', $child ), $branch );
		}
		$orphan = $import( 'c/faq/', 'FAQ', 'faq', 'c/' );
		$this->assertWPError( $orphan );
		$this->assertSame( 'parent_source', $orphan->get_error_data()['reason'] ?? '' );
	}

	/**
	 * A description of the live site with a fact the page does not say warns in report.seo; the import goes on.
	 */
	public function test_seo_fact_check_warns(): void {
		$result = wp_get_ability( Import::NAME )->execute(
			array(
				'source'    => self::SOURCE,
				'post_type' => 'post',
				'title'     => 'Opening in May',
				'content'   => '<p>We open in May with a new hall for our customers and a café.</p>',
				'seo'       => array( 'description' => 'Since 1998 we serve 4000 customers a year in our shop; from May with a new hall for everyone and a café.' ),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertStringContainsString( '1998', implode( ' ', (array) $result['report']['seo'] ) );
		$this->assertGreaterThan( 0, Import::find( self::SOURCE ) );
	}

	/**
	 * The dry run writes and loads nothing and shows the markup.
	 */
	public function test_dry_run(): void {
		$count  = (int) wp_count_posts( 'post' )->draft;
		$result = wp_get_ability( Import::NAME )->execute(
			array(
				'source'    => self::SOURCE,
				'post_type' => 'post',
				'title'     => 'Opening',
				'content'   => self::builder(),
				'dry_run'   => true,
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'unchanged', $result['mode'] );
		$this->assertSame( 'create', $result['report']['action'] );
		$this->assertStringContainsString( '<!-- wp:columns -->', $result['report']['content'] );
		$this->assertSame( $count, (int) wp_count_posts( 'post' )->draft );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Images of other hosts are not loaded unless media_hosts names them.
	 */
	public function test_foreign_image_hosts(): void {
		$input  = array(
			'source'    => 'https://old.example/gallery/',
			'post_type' => 'post',
			'title'     => 'Gallery',
			'content'   => '<p>Our team.</p><p><img src="https://cdn.example/team.png" alt="The team"></p>',
		);
		$result = wp_get_ability( Import::NAME )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'cdn.example', wp_json_encode( $result['report']['unmapped'] ) );
		$this->assertStringNotContainsString( 'cdn.example', get_post_field( 'post_content', Import::find( 'https://old.example/gallery/' ) ) );
		$again = wp_get_ability( Import::NAME )->execute( $input + array( 'media_hosts' => array( 'cdn.example' ) ) );
		$this->assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		$this->assertSame( array( 'https://cdn.example/team.png' ), $this->requests );
	}
}
