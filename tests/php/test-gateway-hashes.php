<?php
/**
 * Block IDs and content hashes of the gateway.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Block_Ids;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Model\Registrar;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * IDs on save, duplicates, untouched bytes and types, wp_slash; block and document hashes.
 */
class Test_Gateway_Hashes extends WP_UnitTestCase {

	/**
	 * Nested content without IDs; the image block keeps its own metadata.
	 */
	private const CONTENT = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"metadata\":{\"name\":\"Intro\"}} -->\n<p>Text</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n<!-- wp:separator /-->";

	/**
	 * Saved content of a new post.
	 *
	 * @param string $content   Content, unslashed.
	 * @param string $post_type Post type.
	 */
	private function saved( string $content, string $post_type = 'page' ): string {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_title'   => 'IDs',
					'post_content' => $content,
				)
			)
		);
		return get_post_field( 'post_content', $id, 'raw' );
	}

	/**
	 * IDs in document order.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	private static function ids( string $content ): array {
		preg_match_all( '/"leitviaId":"([^"]*)"/', $content, $found );
		return $found[1];
	}

	/**
	 * Every block gets an ID of 8 base36 characters; nothing else in the content changes.
	 */
	public function test_save_assigns_ids(): void {
		$saved = $this->saved( self::CONTENT );
		$ids   = self::ids( $saved );
		$this->assertCount( 4, $ids );
		$this->assertCount( 4, array_unique( $ids ) );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-z]{8}$/', $id );
		}
		$stripped = preg_replace( array( '/ \{"metadata":\{"leitviaId":"[0-9a-z]{8}"\}\}/', '/,"metadata":\{"leitviaId":"[0-9a-z]{8}"\}/', '/"leitviaId":"[0-9a-z]{8}",/' ), '', $saved );
		$this->assertSame( self::CONTENT, $stripped, 'byte for byte apart from the IDs' );
		$this->assertSame( 'Intro', parse_blocks( $saved )[0]['innerBlocks'][1]['attrs']['metadata']['name'] );
		$this->assertSame( $saved, $this->saved( $saved ), 'existing IDs stay' );
	}

	/**
	 * The second of two blocks with the same ID gets a new one; an invalid ID is replaced.
	 */
	public function test_duplicates_get_new_ids(): void {
		$block = '<!-- wp:paragraph {"metadata":{"leitviaId":"dup00001"}} --><p>Same</p><!-- /wp:paragraph -->';
		$ids   = self::ids( $this->saved( $block . $block . '<!-- wp:paragraph {"metadata":{"leitviaId":"BAD"}} --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( 'dup00001', $ids[0] );
		$this->assertMatchesRegularExpression( '/^[0-9a-z]{8}$/', $ids[1] );
		$this->assertNotSame( 'dup00001', $ids[1] );
		$this->assertMatchesRegularExpression( '/^[0-9a-z]{8}$/', $ids[2] );
	}

	/**
	 * The same content gets the same IDs again, so an editor session that saves twice keeps them.
	 */
	public function test_ids_are_reproducible(): void {
		$this->assertSame( Block_Ids::assign( self::CONTENT, 7 ), Block_Ids::assign( self::CONTENT, 7 ) );
		$this->assertNotSame( self::ids( Block_Ids::assign( self::CONTENT, 7 ) ), self::ids( Block_Ids::assign( self::CONTENT, 8 ) ) );
	}

	/**
	 * Post types out of scope, patterns and revisions stay untouched.
	 */
	public function test_types_out_of_scope_stay_untouched(): void {
		register_post_type( 'lv_other' );
		$this->assertSame( self::CONTENT, $this->saved( self::CONTENT, 'lv_other' ) );
		$this->assertSame( self::CONTENT, $this->saved( self::CONTENT, 'wp_block' ) );
		unregister_post_type( 'lv_other' );
		$data = array(
			'post_type'    => 'revision',
			'post_content' => wp_slash( self::CONTENT ),
		);
		$this->assertSame( $data, Block_Ids::on_save( $data ) );
	}

	/**
	 * Backslashes and quotes in attributes survive the unslash and slash around the filter.
	 */
	public function test_slash_safe(): void {
		$content = '<!-- wp:paragraph {"metadata":{"name":"back\\\\slash \\u0022quote\\u0022"}} --><p>a\\b "c"</p><!-- /wp:paragraph -->';
		$saved   = $this->saved( $content );
		$block   = parse_blocks( $saved )[0];
		$this->assertSame( 'back\\slash "quote"', $block['attrs']['metadata']['name'] );
		$this->assertSame( '<p>a\\b "c"</p>', $block['innerHTML'] );
	}

	/**
	 * Priority 20, after the filters of the content model and the token sets, which keep working.
	 */
	public function test_other_insert_filters_still_work(): void {
		$this->assertSame( 20, has_filter( 'wp_insert_post_data', array( Block_Ids::class, 'on_save' ) ) );
		$this->assertSame( 10, has_filter( 'wp_insert_post_data', array( Registrar::class, 'force_inherit' ) ) );
		$this->assertSame( 10, has_filter( 'wp_insert_post_data', array( Token_Sets::class, 'force_private' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$set = wp_insert_post(
			array(
				'post_type'    => Token_Sets::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Set',
				'post_content' => '{"color":{}}',
			)
		);
		$this->assertSame( 'private', get_post_status( $set ) );
		$this->assertSame( '{"color":{}}', get_post_field( 'post_content', $set, 'raw' ) );
	}

	/**
	 * The block hash follows attributes, inner blocks and markup, not the position among siblings.
	 */
	public function test_block_hash(): void {
		$blocks = parse_blocks( self::CONTENT );
		$group  = $blocks[0];
		$base   = Hashes::block( $group );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $base );
		$this->assertSame( $base, Hashes::block( parse_blocks( self::CONTENT )[0] ), 'reproducible' );

		$changed                            = $group;
		$changed['attrs']['layout']['type'] = 'flex';
		$this->assertNotSame( $base, Hashes::block( $changed ), 'attribute' );
		$changed                            = $group;
		$changed['innerBlocks'][1]['attrs'] = array( 'align' => 'center' );
		$this->assertNotSame( $base, Hashes::block( $changed ), 'inner block' );
		$changed                    = $group;
		$changed['innerContent'][0] = '<div class="wp-block-group is-new">';
		$this->assertNotSame( $base, Hashes::block( $changed ), 'markup' );

		$moved = parse_blocks( "<!-- wp:separator /-->\n\n" . str_replace( "\n\n<!-- wp:separator /-->", '', self::CONTENT ) );
		$this->assertSame( $base, Hashes::block( $moved[2] ), 'sibling moved' );
	}

	/**
	 * The document hash covers title, content, excerpt, status, slug, parent, order and featured image.
	 */
	public function test_document_hash(): void {
		$parent = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => self::CONTENT,
			)
		);
		$hash   = Hashes::document( $page );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $hash );
		$this->assertSame( $hash, Hashes::document( $page ) );
		$this->assertSame( '', Hashes::document( 0 ) );
		$changes = array(
			'post_title'   => 'Other',
			'post_content' => '<!-- wp:separator {"metadata":{"leitviaId":"aaaaaaaa"}} /-->',
			'post_excerpt' => 'Short',
			'post_status'  => 'draft',
			'post_name'    => 'other-slug',
			'post_parent'  => $parent,
			'menu_order'   => 3,
		);
		foreach ( $changes as $field => $value ) {
			wp_update_post(
				array(
					'ID'   => $page,
					$field => $value,
				)
			);
			$next = Hashes::document( $page );
			$this->assertNotSame( $hash, $next, $field );
			$hash = $next;
		}
		set_post_thumbnail( $page, self::factory()->attachment->create_object( 'image.jpg', $page, array( 'post_mime_type' => 'image/jpeg' ) ) );
		$this->assertNotSame( $hash, Hashes::document( $page ), 'featured image' );
	}
}
