<?php
/**
 * Gateway block diff.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Diff;

/**
 * Block diff over IDs and hashes, fields, memory on large pages, size limit.
 */
class Test_Gateway_Diff extends WP_UnitTestCase {

	/**
	 * A paragraph block.
	 *
	 * @param string $text Text.
	 * @param string $id   leitviaId, '' for none.
	 */
	private static function paragraph( string $text, string $id = '' ): string {
		$attrs = '' !== $id ? ' {"metadata":{"leitviaId":"' . $id . '"}}' : '';
		return "<!-- wp:paragraph{$attrs} --><p>{$text}</p><!-- /wp:paragraph -->";
	}

	/**
	 * Changes by ID.
	 *
	 * @param list<array<string, mixed>> $changes Diff.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_id( array $changes ): array {
		return array_column( $changes, null, 'id' );
	}

	/**
	 * Added, removed, moved and changed blocks with texts and changed attributes; unchanged blocks are left out.
	 */
	public function test_block_changes(): void {
		$before = self::paragraph( 'One', 'aaaaaaaa' ) . self::paragraph( 'Two', 'bbbbbbbb' ) . '<!-- wp:heading {"metadata":{"leitviaId":"cccccccc"}} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->' . self::paragraph( 'Gone', 'dddddddd' ) . self::paragraph( 'Same', 'ffffffff' );
		$after  = self::paragraph( 'Two', 'bbbbbbbb' ) . self::paragraph( 'One <strong>changed</strong>', 'aaaaaaaa' ) . '<!-- wp:heading {"level":3,"metadata":{"leitviaId":"cccccccc"}} --><h3 class="wp-block-heading">Title</h3><!-- /wp:heading -->' . self::paragraph( 'Same', 'ffffffff' ) . self::paragraph( 'New', 'eeeeeeee' );
		$diff   = Diff::states( array( 'post_content' => $before ), array( 'post_content' => $after ) );
		$this->assertIsArray( $diff );
		$changes = self::by_id( $diff );
		$this->assertSame( array( 'bbbbbbbb', 'aaaaaaaa', 'cccccccc', 'eeeeeeee', 'dddddddd' ), array_keys( $changes ) );
		$this->assertSame(
			array(
				'id'            => 'aaaaaaaa',
				'change'        => 'changed',
				'before_text'   => 'One',
				'after_text'    => 'One changed',
				'attrs_changed' => array(),
			),
			$changes['aaaaaaaa']
		);
		$this->assertSame( 'moved', $changes['bbbbbbbb']['change'] );
		$this->assertSame( array( 'level' ), $changes['cccccccc']['attrs_changed'] );
		$this->assertSame( 'added', $changes['eeeeeeee']['change'] );
		$this->assertSame( 'New', $changes['eeeeeeee']['after_text'] );
		$this->assertSame( 'removed', $changes['dddddddd']['change'] );
		$this->assertSame( 'Gone', $changes['dddddddd']['before_text'] );
	}

	/**
	 * IDs assigned on save, inner blocks and moves into a group: blocks without ID match by content, a block that
	 * lands in another parent counts as moved.
	 */
	public function test_ids_and_nesting(): void {
		$plain = self::paragraph( 'One' ) . self::paragraph( 'Two' );
		$ided  = self::paragraph( 'One', 'aaaaaaaa' ) . self::paragraph( 'Two', 'bbbbbbbb' );
		$this->assertSame( array(), Diff::states( array( 'post_content' => $plain ), array( 'post_content' => $ided ) ) );

		$group = '<!-- wp:group {"metadata":{"leitviaId":"gggggggg"}} --><div class="wp-block-group">' . self::paragraph( 'Two', 'bbbbbbbb' ) . '</div><!-- /wp:group -->';
		$diff  = self::by_id( Diff::states( array( 'post_content' => $ided ), array( 'post_content' => self::paragraph( 'One', 'aaaaaaaa' ) . $group ) ) );
		$this->assertSame( 'added', $diff['gggggggg']['change'] );
		$this->assertSame( 'moved', $diff['bbbbbbbb']['change'] );
		$this->assertArrayNotHasKey( 'aaaaaaaa', $diff );
	}

	/**
	 * Fields and options are one entry each; a new post shows its fields as added.
	 */
	public function test_fields(): void {
		$diff = self::by_id(
			Diff::states(
				array(
					'post_title'   => 'Old',
					'post_status'  => 'publish',
					'post_content' => self::paragraph( 'Same', 'aaaaaaaa' ),
				),
				array(
					'post_title'   => 'New <em>title</em>',
					'post_status'  => 'publish',
					'post_content' => self::paragraph( 'Same', 'aaaaaaaa' ),
				)
			)
		);
		$this->assertSame( array( 'field:post_title' ), array_keys( $diff ) );
		$this->assertSame( 'New title', $diff['field:post_title']['after_text'] );

		$option = Diff::states( array( 'value' => 'Old tagline' ), array( 'value' => 'New tagline' ) );
		$this->assertSame( 'field:value', $option[0]['id'] );
		$this->assertSame( 'Old tagline', $option[0]['before_text'] );

		$created = self::by_id(
			Diff::states(
				array(),
				array(
					'post_title'   => 'Page',
					'post_content' => self::paragraph( 'Hello' ),
				)
			)
		);
		$this->assertSame( 'added', $created['field:post_title']['change'] );
		$this->assertSame( 'added', $created['p:0']['change'] );

		$styles = Diff::states(
			array(
				'post_type'    => 'wp_global_styles',
				'post_content' => '{"version":3}',
			),
			array(
				'post_type'    => 'wp_global_styles',
				'post_content' => '{"version":3,"styles":{}}',
			)
		);
		$this->assertSame( 'field:post_content', $styles[0]['id'] );
	}

	/**
	 * A page with 500 blocks: the diff stays under 8 MB of memory.
	 */
	public function test_large_page_memory(): void {
		$before = '';
		$after  = '';
		for ( $i = 0; $i < 500; $i++ ) {
			$id      = str_pad( base_convert( (string) ( 100000 + $i ), 10, 36 ), 8, 'a', STR_PAD_LEFT );
			$text    = str_repeat( "Block {$i} text. ", 8 );
			$before .= self::paragraph( $text, $id );
			$after  .= self::paragraph( 0 === $i % 10 ? $text . 'Changed.' : $text, $id );
		}
		memory_reset_peak_usage();
		$start = memory_get_peak_usage();
		$diff  = Diff::states( array( 'post_content' => $before ), array( 'post_content' => $after ) );
		$used  = memory_get_peak_usage() - $start;
		$this->assertCount( 50, $diff );
		$this->assertLessThan( 8 * MB_IN_BYTES, $used );
	}

	/**
	 * More than the limit per operation (default 1 MB) is leitvia_too_large.
	 */
	public function test_size_limit(): void {
		$this->assertSame( MB_IN_BYTES, Diff::MAX_BYTES );
		$big = Diff::states( array(), array( 'post_content' => str_repeat( 'x', MB_IN_BYTES ) ) );
		$this->assertWPError( $big );
		$this->assertSame( 'leitvia_too_large', $big->get_error_code() );
		$this->assertSame( 413, $big->get_error_data()['status'] );
		$this->assertWPError( Diff::states( array( 'value' => 'a' ), array( 'value' => str_repeat( 'b', 200 ) ), 100 ) );
	}

	/**
	 * Global styles in words: changed color and font presets, theme presets as the base, unchanged ones left out.
	 */
	public function test_styles_summary(): void {
		$styles = static fn( array $palette, array $fonts = array() ): array => array(
			'post_type'    => 'wp_global_styles',
			'post_content' => (string) wp_json_encode(
				array(
					'settings' => array(
						'color'      => array( 'palette' => array( 'theme' => $palette ) ),
						'typography' => array( 'fontFamilies' => array( 'theme' => $fonts ) ),
					),
				)
			),
		);
		$theme  = array(
			'color'      => array(
				'palette' => array(
					'theme' => array(
						array(
							'slug'  => 'base',
							'name'  => 'Base',
							'color' => '#ffffff',
						),
						array(
							'slug'  => 'accent',
							'name'  => 'Accent',
							'color' => '#0b4f9c',
						),
					),
				),
			),
			'typography' => array(
				'fontFamilies' => array(
					'theme' => array(
						array(
							'slug'       => 'body',
							'name'       => 'Body',
							'fontFamily' => 'Inter, sans-serif',
						),
					),
				),
			),
		);
		$after  = $styles(
			array(
				array(
					'slug'  => 'accent',
					'name'  => 'Accent',
					'color' => '#b00020',
				),
			),
			array(
				array(
					'slug'       => 'body',
					'name'       => 'Body',
					'fontFamily' => 'Georgia, serif',
				),
			)
		);
		$this->assertSame(
			array(
				'colors' => array(
					array(
						'slug'   => 'accent',
						'name'   => 'Accent',
						'before' => '#0b4f9c',
						'after'  => '#b00020',
					),
				),
				'fonts'  => array(
					array(
						'slug'   => 'body',
						'name'   => 'Body',
						'before' => 'Inter, sans-serif',
						'after'  => 'Georgia, serif',
					),
				),
			),
			Diff::styles( array(), $after, $theme )
		);
		$this->assertSame(
			array(
				'colors' => array(),
				'fonts'  => array(),
			),
			Diff::styles( $after, $after, $theme )
		);
	}
}
