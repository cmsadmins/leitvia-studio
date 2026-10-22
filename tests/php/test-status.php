<?php
/**
 * Pattern status tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Status;

/**
 * Own and effective status, cycles, theme patterns and parts.
 */
class Test_Status extends WP_UnitTestCase {

	/**
	 * Creates a wp_block with a status.
	 *
	 * @param string $status  Lab status.
	 * @param string $content Block markup.
	 */
	private function pattern( string $status, string $content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		update_post_meta( $id, '_leitvia_lab_status', $status );
		return $id;
	}

	/**
	 * Drops cached contracts.
	 */
	public function tear_down(): void {
		\LeitviaStudio\Plugin::forget_contract();
		Status::flush();
		parent::tear_down();
	}

	/**
	 * The effective status is the least mature part; a deprecated part counts as draft.
	 */
	public function test_effective_status_is_the_least_mature_part(): void {
		$child  = $this->pattern( 'review' );
		$parent = $this->pattern( 'approved', '<!-- wp:block {"ref":' . $child . '} /-->' );
		$this->assertSame( 'review', Status::effective( 'block:' . $parent ) );
		update_post_meta( $child, '_leitvia_lab_status', 'deprecated' );
		$this->assertSame( 'draft', Status::effective( 'block:' . $parent ), 'a deprecated part counts as draft' );
		$this->assertSame( 'deprecated', Status::effective( 'block:' . $child ) );
	}

	/**
	 * Deleting a part drops the cached result; a missing part counts as draft.
	 */
	public function test_deleting_a_part_updates_the_status(): void {
		$child  = $this->pattern( 'approved' );
		$parent = $this->pattern( 'approved', '<!-- wp:block {"ref":' . $child . '} /-->' );
		$this->assertSame( 'approved', Status::effective( 'block:' . $parent ) );
		wp_delete_post( $child, true );
		$this->assertSame( 'draft', Status::effective( 'block:' . $parent ) );
	}

	/**
	 * Cycles end and theme patterns are approved.
	 */
	public function test_cycles_and_theme_patterns(): void {
		$a = $this->pattern( 'approved' );
		$b = $this->pattern( 'approved', '<!-- wp:block {"ref":' . $a . '} /-->' );
		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => '<!-- wp:block {"ref":' . $b . '} /-->',
			)
		);
		$this->assertSame( 'approved', Status::effective( 'block:' . $a ) );
		$this->assertSame( 'approved', Status::effective( 'pattern:leitvia/hero-split-image' ) );
	}

	/**
	 * A draft deep inside a cycle lowers every pattern of the cycle.
	 */
	public function test_cycle_with_a_draft_member(): void {
		$a = $this->pattern( 'approved' );
		$b = $this->pattern( 'draft', '<!-- wp:block {"ref":' . $a . '} /-->' );
		$c = $this->pattern( 'approved', '<!-- wp:block {"ref":' . $b . '} /-->' );
		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => '<!-- wp:block {"ref":' . $c . '} /-->',
			)
		);
		foreach ( array( $a, $b, $c ) as $id ) {
			$this->assertSame( 'draft', Status::effective( 'block:' . $id ) );
		}
	}

	/**
	 * A section that compose-page took from the Lab carries core/block/<ID> as patternName, as core names Lab patterns,
	 * and counts as that Lab pattern.
	 */
	public function test_lab_sections_by_pattern_name(): void {
		$draft = $this->pattern( 'draft' );
		$page  = '<!-- wp:paragraph {"metadata":{"patternName":"core/block/' . $draft . '"}} --><p>x</p><!-- /wp:paragraph -->';
		$this->assertSame( array( 'block:' . $draft ), Status::refs( $page ) );
		$this->assertSame( 'pattern:leitvia/hero-centered', Status::of_name( 'leitvia/hero-centered' ) );
		$this->assertSame( 'draft', Status::effective( 'block:' . $this->pattern( 'approved', $page ) ) );
	}

	/**
	 * Theme patterns nest through core/pattern and metadata.patternName; the contract can deprecate them.
	 */
	public function test_theme_patterns_as_parts(): void {
		$content = '<!-- wp:pattern {"slug":"leitvia/cta-banner"} /-->'
			. '<!-- wp:group {"metadata":{"patternName":"leitvia/hero-centered","name":"Hero"}} --><div class="wp-block-group">'
			. '<!-- wp:block {"ref":999999} /--></div><!-- /wp:group -->'
			. '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":42} /--></div><!-- /wp:group -->';
		$id      = $this->pattern( 'approved', $content );
		$this->assertSame( array( 'pattern:leitvia/cta-banner', 'pattern:leitvia/hero-centered', 'block:42' ), Status::parts( 'block:' . $id ) );

		$uses_banner = $this->pattern( 'approved', '<!-- wp:pattern {"slug":"leitvia/cta-banner"} /-->' );
		$this->assertSame( 'approved', Status::effective( 'block:' . $uses_banner ) );
		$this->assertSame( 'draft', Status::effective( 'block:' . $id ), 'block:42 does not exist and counts as draft' );

		add_filter(
			'leitvia_studio_contract_path',
			static function (): array {
				$file = get_temp_dir() . 'leitvia-status-contract.json';
				$data = wp_json_file_decode( get_theme_root() . '/leitvia/leitvia-contract.json', array( 'associative' => true ) );
				foreach ( $data['patterns'] as &$pattern ) {
					if ( 'leitvia/cta-banner' === $pattern['slug'] ) {
						$pattern['docs']['replacedBy'] = 'leitvia/cta-split';
					}
				}
				file_put_contents( $file, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
				return array( $file );
			}
		);
		\LeitviaStudio\Plugin::forget_contract();
		Status::flush();
		$this->assertSame( 'deprecated', Status::own( 'pattern:leitvia/cta-banner' ) );
		$this->assertSame( 'draft', Status::effective( 'block:' . $uses_banner ) );
	}
}
