<?php
/**
 * Gateway autonomy levels.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Operation;

/**
 * Defaults per area, profile full, overrides, and the content kinds that cap the level at "suggest".
 */
class Test_Gateway_Autonomy extends WP_UnitTestCase {

	/**
	 * Clean settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Gateway::OPTION );
	}

	/**
	 * Saves settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function settings( array $settings ): void {
		update_option( Gateway::OPTION, $settings + Gateway::DEFAULTS );
	}

	/**
	 * Kinds of a change to a post.
	 *
	 * @param int                  $post   Post ID, 0 for a new one.
	 * @param array<string, mixed> $before State before.
	 * @param array<string, mixed> $after  State after.
	 * @return list<string>
	 */
	private static function kinds( int $post, array $before, array $after ): array {
		$op = new Operation(
			'leitvia/test-write',
			'content',
			array(
				'post'      => $post,
				'post_type' => 'page',
			),
			'',
			static fn( array $state ): array => $state
		);
		return Autonomy::kinds( $op, $before, $after );
	}

	/**
	 * Defaults of spec 7.5, profile full opens all but admin, overrides win, admin and unknown areas stay locked.
	 */
	public function test_area_levels(): void {
		$this->assertSame(
			array(
				'content'     => 'draft',
				'corrections' => 'autonomous',
				'structure'   => 'draft',
				'design'      => 'suggest',
				'admin'       => 'locked',
				// Without WooCommerce nothing opens the products area.
				'products'    => 'locked',
			),
			array_combine( Gateway::AREAS, array_map( array( Autonomy::class, 'area_level' ), Gateway::AREAS ) )
		);
		$this->assertSame( 'locked', Autonomy::area_level( 'unknown' ) );
		self::settings( array( 'profile' => 'full' ) );
		$this->assertSame(
			array( 'autonomous', 'autonomous', 'autonomous', 'autonomous', 'locked', 'locked' ),
			array_map( array( Autonomy::class, 'area_level' ), Gateway::AREAS )
		);
		self::settings(
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(
						'design' => 'suggest',
						'admin'  => 'autonomous',
					),
					'kinds' => array(),
				),
			)
		);
		$this->assertSame( 'suggest', Autonomy::area_level( 'design' ) );
		$this->assertSame( 'locked', Autonomy::area_level( 'admin' ), 'admin stays locked' );
	}

	/**
	 * Each kind caps at "suggest", also in profile full, unless unlocked; lower levels stay.
	 */
	public function test_kinds_cap_level(): void {
		self::settings( array( 'profile' => 'full' ) );
		foreach ( Gateway::KINDS as $kind ) {
			$this->assertSame( 'suggest', Autonomy::level( 'content', array( $kind ) ), $kind );
		}
		$this->assertSame( 'autonomous', Autonomy::level( 'content', array() ) );
		self::settings(
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => array( 'slugs' ),
				),
			)
		);
		$this->assertSame( 'autonomous', Autonomy::level( 'content', array( 'slugs' ) ) );
		$this->assertSame( 'suggest', Autonomy::level( 'content', array( 'slugs', 'legal' ) ) );
		$this->assertSame( 'locked', Autonomy::level( 'admin', array( 'slugs' ) ) );
	}

	/**
	 * Legal: page kinds of legal texts and the privacy policy page of core.
	 */
	public function test_legal(): void {
		$imprint = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $imprint, '_leitvia_page_kind', 'imprint' );
		$contact = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $contact, '_leitvia_page_kind', 'contact' );
		$privacy = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$this->assertSame( array( 'legal' ), self::kinds( $imprint, array(), array() ) );
		$this->assertSame( array( 'legal' ), self::kinds( $privacy, array(), array() ) );
		$this->assertSame( array(), self::kinds( $contact, array(), array() ) );
	}

	/**
	 * Prices: any change to pricing blocks, to price columns of comparison tables and to price fields, not other changes.
	 */
	public function test_prices(): void {
		$pricing = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph --><!-- wp:leitvia/pricing --><div class="wp-block-leitvia-pricing"><!-- wp:leitvia/pricing-plan {"name":"Basic","monthly":"9"} /--></div><!-- /wp:leitvia/pricing -->';
		$before  = array( 'post_content' => $pricing );
		$this->assertSame( array(), self::kinds( 0, $before, array( 'post_content' => str_replace( 'Intro', 'Welcome', $pricing ) ) ) );
		$this->assertSame( array( 'prices' ), self::kinds( 0, $before, array( 'post_content' => str_replace( '"9"', '"19"', $pricing ) ) ) );
		$this->assertSame( array(), self::kinds( 0, $before, array( 'post_content' => '<!-- wp:paragraph --><p>Moved</p><!-- /wp:paragraph -->' . substr( $pricing, strlen( '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->' ) ) ) ), 'moving other blocks' );

		$table  = static fn( string $feature, string $price ): string => serialize_block(
			array(
				'blockName'    => 'leitvia/comparison-table',
				'attrs'        => array(
					'columns' => array( 'Plan', 'Feature', 'Price' ),
					'rows'    => array( array( 'Basic', $feature, $price ) ),
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$before = array( 'post_content' => $table( 'Search', '9 €' ) );
		$this->assertSame( array(), self::kinds( 0, $before, array( 'post_content' => $table( 'Search and tags', '9 €' ) ) ), 'other column' );
		$this->assertSame( array( 'prices' ), self::kinds( 0, $before, array( 'post_content' => $table( 'Search', '12 €' ) ) ) );

		// Price and fee fields of entries: a changed value is a price, an unchanged one or another field is not.
		$dish = array(
			'meta_input' => array(
				'price'   => '8.5',
				'summary' => 'Soup',
			),
		);
		$this->assertSame( array( 'prices' ), self::kinds( 0, $dish, array( 'meta_input' => array( 'price' => 9 ) ) ) );
		$this->assertSame( array( 'prices' ), self::kinds( 0, array( 'meta_input' => array( 'menu_price' => '48' ) ), array( 'meta_input' => array( 'menu_price' => 52 ) ) ) );
		$this->assertSame(
			array(),
			self::kinds(
				0,
				$dish,
				array(
					'meta_input' => array(
						'price'   => 8.5,
						'summary' => 'Pumpkin soup',
					),
				)
			)
		);
		$this->assertSame( array(), self::kinds( 0, array(), array( 'meta_input' => array( 'price' => 9 ) ) ), 'a new entry brings its price' );
	}

	/**
	 * Slugs: post_name of an existing post changes. Organization: option targets. The filter adds kinds.
	 */
	public function test_slugs_organization_and_filter(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( array( 'slugs' ), self::kinds( $page, array( 'post_name' => 'a' ), array( 'post_name' => 'b' ) ) );
		$this->assertSame( array(), self::kinds( 0, array(), array( 'post_name' => 'b' ) ), 'a new post has no old slug' );
		$option = new Operation( 'leitvia/test-write', 'content', array( 'option' => 'blogname' ), '', static fn( array $state ): array => $state );
		$this->assertSame( array( 'organization' ), Autonomy::kinds( $option, array( 'value' => 'A' ), array( 'value' => 'B' ) ) );
		add_filter( 'leitvia_studio_gateway_content_kind', static fn( array $kinds ): array => array_merge( $kinds, array( 'prices' ) ) );
		$this->assertSame( array( 'prices' ), self::kinds( 0, array(), array() ) );
	}
}
