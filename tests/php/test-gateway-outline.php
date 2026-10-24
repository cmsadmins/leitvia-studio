<?php
/**
 * Page outline and single blocks for agents.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Block_Ids;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Outline;

/**
 * Abilities leitvia/get-page-outline and leitvia/get-block: tree, references, path IDs, rejections, budget.
 */
class Test_Gateway_Outline extends WP_UnitTestCase {

	/**
	 * Most milliseconds an outline of 500 blocks may take.
	 */
	private const MAX_MS = 200;

	/**
	 * Most bytes of memory an outline of 500 blocks may take.
	 */
	private const MAX_BYTES = 8 * MB_IN_BYTES;

	/**
	 * Agent user and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * A page, optionally saved without the ID filter as older content would be.
	 *
	 * @param string $content Content.
	 * @param bool   $ids     Whether the save assigns IDs.
	 * @param array  $args    Further post fields.
	 */
	private function page( string $content, bool $ids = true, array $args = array() ): int {
		if ( ! $ids ) {
			remove_filter( 'wp_insert_post_data', array( Block_Ids::class, 'on_save' ), 20 );
		}
		$id = self::factory()->post->create(
			$args + array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Outline',
				'post_content' => $content,
			)
		);
		add_filter( 'wp_insert_post_data', array( Block_Ids::class, 'on_save' ), 20, 2 );
		return $id;
	}

	/**
	 * Runs an ability.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function ability( string $name, array $input ) {
		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * Document fields and per block id, name, pattern, text, bytes, hash and children; references stay references.
	 */
	public function test_outline(): void {
		$long    = str_repeat( 'Lorem ipsum ', 20 );
		$content = '<!-- wp:group {"metadata":{"leitviaId":"grp00001","patternName":"leitvia/hero"}} --><div class="wp-block-group"><!-- wp:paragraph {"metadata":{"leitviaId":"par00001"}} --><p><strong>Bold</strong> &amp; ' . $long . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. "\n\n" . '<!-- wp:block {"ref":42,"metadata":{"leitviaId":"ref00001"}} /-->'
			. "\n\n" . '<!-- wp:pattern {"slug":"leitvia/cta","metadata":{"leitviaId":"pat00001"}} /-->'
			. "\n\n" . '<!-- wp:template-part {"slug":"footer","metadata":{"leitviaId":"tpl00001"}} /-->';
		$page    = $this->page( $content );
		update_post_meta( $page, '_leitvia_page_kind', 'contact' );
		update_post_meta( $page, '_wp_page_template', 'page-wide' );

		$outline = self::ability( 'leitvia/get-page-outline', array( 'post' => $page ) );
		$this->assertSame( Hashes::document( $page ), $outline['hash'] );
		$this->assertSame( array( $page, 'Outline', 'publish', 'page-wide', 'contact' ), array( $outline['id'], $outline['title'], $outline['status'], $outline['template'], $outline['kind'] ) );
		$this->assertSame( array( 'grp00001', 'ref00001', 'pat00001', 'tpl00001' ), array_column( $outline['blocks'], 'id' ) );
		$this->assertSame( array( 'pattern:leitvia/hero', 'block:42', 'pattern:leitvia/cta', null ), array_column( $outline['blocks'], 'pattern' ) );
		$this->assertSame( get_stylesheet() . '//footer', $outline['blocks'][3]['ref'] );
		$this->assertSame( array(), $outline['blocks'][1]['children'], 'synced pattern not inline' );

		$paragraph = $outline['blocks'][0]['children'][0];
		$block     = parse_blocks( get_post_field( 'post_content', $page, 'raw' ) )[0]['innerBlocks'][0];
		$this->assertSame( 'par00001', $paragraph['id'] );
		$this->assertTrue( $paragraph['id_stable'] );
		$this->assertSame( 'core/paragraph', $paragraph['name'] );
		$this->assertSame( mb_substr( 'Bold & ' . trim( $long ), 0, 80 ), $paragraph['text'] );
		$this->assertSame( strlen( serialize_block( $block ) ), $paragraph['bytes'] );
		$this->assertSame( Hashes::block( $block ), $paragraph['hash'] );
		$this->assertSame( array(), $paragraph['children'] );
	}

	/**
	 * A block with links lists their targets, its own only: the url of a navigation link and every href of its markup,
	 * sample links to "#" included. Blocks without links have no key links.
	 */
	public function test_outline_links(): void {
		$content = '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Imprint","url":"#","kind":"custom"} /--><!-- wp:navigation-link {"label":"About","url":"https://example.org/about/","kind":"custom"} /--><!-- /wp:navigation -->'
			. '<!-- wp:paragraph --><p>Write to <a href="mailto:hello@example.org">hello@example.org</a> or <a href="/contact/">use the form</a>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>No link.</p><!-- /wp:paragraph -->';
		$outline = self::ability( 'leitvia/get-page-outline', array( 'post' => $this->page( $content, false ) ) );
		$this->assertArrayNotHasKey( 'links', $outline['blocks'][0] );
		$this->assertSame( array( '#' ), $outline['blocks'][0]['children'][0]['links'] );
		$this->assertSame( array( 'https://example.org/about/' ), $outline['blocks'][0]['children'][1]['links'] );
		$this->assertSame( array( 'mailto:hello@example.org', '/contact/' ), $outline['blocks'][1]['links'] );
		$this->assertArrayNotHasKey( 'links', $outline['blocks'][2] );
	}

	/**
	 * Older content without IDs gets path IDs, marked unstable; reading never writes them.
	 */
	public function test_path_ids_without_writing(): void {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n<!-- wp:separator /-->";
		$page    = $this->page( $content, false );
		$outline = self::ability( 'leitvia/get-page-outline', array( 'post' => $page ) );
		$this->assertSame( array( 'p:0', 'p:1' ), array_column( $outline['blocks'], 'id' ) );
		$this->assertSame( array( 'p:0.0', 'p:0.1' ), array_column( $outline['blocks'][0]['children'], 'id' ) );
		$this->assertFalse( $outline['blocks'][0]['id_stable'] );
		$block = self::ability(
			'leitvia/get-block',
			array(
				'post'  => $page,
				'block' => 'p:0.1',
			)
		);
		$this->assertSame( "\n<p>B</p>\n", $block['inner_html'] );
		$this->assertFalse( $block['id_stable'] );
		$this->assertSame( $content, get_post_field( 'post_content', $page, 'raw' ) );
	}

	/**
	 * Ability get-block returns attributes, markup, hash and children; an unknown ID points to the outline.
	 */
	public function test_get_block(): void {
		$page  = $this->page( '<!-- wp:group {"metadata":{"leitviaId":"grp00001"},"align":"wide"} --><div class="wp-block-group alignwide"><!-- wp:paragraph {"metadata":{"leitviaId":"par00001"}} --><p>Hi</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$block = self::ability(
			'leitvia/get-block',
			array(
				'post'  => $page,
				'block' => 'grp00001',
			)
		);
		$this->assertSame( 'core/group', $block['name'] );
		$this->assertSame( 'wide', $block['attributes']->align );
		$this->assertSame( '<div class="wp-block-group alignwide"></div>', $block['inner_html'] );
		$this->assertSame( Hashes::block( parse_blocks( get_post_field( 'post_content', $page, 'raw' ) )[0] ), $block['hash'] );
		$this->assertSame( array( 'par00001' ), $block['children'] );
		$this->assertSame( array(), $block['attachments'] );
		$this->assertSame( Hashes::document( $page ), $block['document_hash'] );

		$missing = self::ability(
			'leitvia/get-block',
			array(
				'post'  => $page,
				'block' => 'zzzzzzzz',
			)
		);
		$this->assertWPError( $missing );
		$this->assertSame( 'leitvia_not_found', $missing->get_error_code() );
		$this->assertSame( 'leitvia/get-page-outline', $missing->get_error_data()['next_ability'] );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	/**
	 * Classic content is one block core/freeform in get-block as in the outline; get-block names the attachments of its
	 * images by URL or by the class wp-image-<id>, and leaves out foreign images and classes of non-attachments.
	 */
	public function test_get_block_classic_content(): void {
		$loaf    = self::factory()->attachment->create_object( 'loaf.png', 0, array( 'post_mime_type' => 'image/png' ) );
		$oven    = self::factory()->attachment->create_object( 'oven.png', 0, array( 'post_mime_type' => 'image/png' ) );
		$other   = self::factory()->post->create();
		$by_url  = wp_get_attachment_url( $loaf );
		$sized   = str_replace( 'oven.png', 'oven-1024x683.png', wp_get_attachment_url( $oven ) );
		$content = "Before.\n\n<img src=\"{$by_url}\" alt=\"\" />\n\n[caption id=\"attachment_{$oven}\"]<img class=\"size-large wp-image-{$oven}\" src=\"{$sized}\" alt=\"\" /> Oven[/caption]\n\n<img src=\"https://elsewhere.example/a.png\" alt=\"\" />\n\n<img class=\"wp-image-{$other}\" src=\"/b.png\" alt=\"\" />";
		$page    = $this->page( $content );
		$outline = self::ability( 'leitvia/get-page-outline', array( 'post' => $page ) );
		$block   = self::ability(
			'leitvia/get-block',
			array(
				'post'  => $page,
				'block' => 'p:0',
			)
		);
		$this->assertSame( 'core/freeform', $outline['blocks'][0]['name'] );
		$this->assertSame( 'core/freeform', $block['name'] );
		$this->assertSame( $content, $block['inner_html'] );
		$this->assertSame(
			array(
				array(
					'id'  => $loaf,
					'url' => $by_url,
				),
				array(
					'id'  => $oven,
					'url' => $sized,
				),
			),
			$block['attachments']
		);
	}

	/**
	 * Private, password protected, missing and out-of-scope posts are rejected alike.
	 */
	public function test_out_of_scope(): void {
		$content = '<!-- wp:paragraph --><p>Secret</p><!-- /wp:paragraph -->';
		register_post_type( 'lv_other' );
		$posts = array(
			'private'  => $this->page( $content, true, array( 'post_status' => 'private' ) ),
			'password' => $this->page( $content, true, array( 'post_password' => 'pw' ) ),
			'type'     => $this->page( $content, true, array( 'post_type' => 'lv_other' ) ),
			'missing'  => 999999,
		);
		foreach ( $posts as $case => $post ) {
			$inputs = array(
				'leitvia/get-page-outline' => array( 'post' => $post ),
				'leitvia/get-block'        => array(
					'post'  => $post,
					'block' => 'p:0',
				),
			);
			foreach ( $inputs as $ability => $input ) {
				$result = self::ability( $ability, $input );
				$this->assertWPError( $result, "{$case} {$ability}" );
				$this->assertSame( 'leitvia_out_of_scope', $result->get_error_code(), "{$case} {$ability}" );
			}
		}
		unregister_post_type( 'lv_other' );
	}

	/**
	 * 500 blocks stay inside the time and memory budget.
	 */
	public function test_budget(): void {
		$content = '';
		for ( $i = 0; $i < 100; $i++ ) {
			$content .= "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Section {$i}</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . str_repeat( 'Some text for the section. ', 8 ) . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div>\n<!-- /wp:group -->\n\n";
		}
		$page = $this->page( $content );
		$this->assertCount( 500, self::ids( get_post_field( 'post_content', $page, 'raw' ) ) );
		clean_post_cache( $page );
		memory_reset_peak_usage();
		$memory  = memory_get_usage();
		$start   = hrtime( true );
		$outline = Outline::page( $page );
		$ms      = ( hrtime( true ) - $start ) / 1e6;
		$bytes   = memory_get_peak_usage() - $memory;
		$this->assertCount( 100, $outline['blocks'] );
		$this->assertLessThan( self::MAX_MS, $ms, "{$ms} ms" );
		$this->assertLessThan( self::MAX_BYTES, $bytes, "{$bytes} bytes" );
	}

	/**
	 * IDs in a content.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	private static function ids( string $content ): array {
		preg_match_all( '/"leitviaId":"[0-9a-z]{8}"/', $content, $found );
		return $found[0];
	}
}
