<?php
/**
 * Gateway read abilities for contract, tokens, patterns, content model and block catalog.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Registration, annotations, output against the output schema, permission and the content of each ability.
 */
class Test_Gateway_Read_Design extends WP_UnitTestCase {

	private const NAMES = array(
		'leitvia/get-contract',
		'leitvia/get-tokens',
		'leitvia/list-patterns',
		'leitvia/get-pattern',
		'leitvia/get-content-model',
		'leitvia/list-blocks',
	);

	private const PATTERN = 'leitvia/hero-split-image';

	/**
	 * Leitvia theme with its patterns, the abilities handed to core, an agent signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		Model::forget();
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
	 * Runs an ability, asserts success and that the output passes its output schema.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input, null for none.
	 * @return array<string, mixed>
	 */
	private function call( string $name, ?array $input = null ): array {
		$ability = wp_get_ability( $name );
		$result  = $ability->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema(), $name ) );
		return $result;
	}

	/**
	 * Every ability: group read, category leitvia, read annotations, an output schema; a valid output.
	 */
	public function test_registration_and_output(): void {
		$inputs = array(
			'leitvia/get-pattern' => array( 'slug' => self::PATTERN ),
			'leitvia/list-blocks' => array( 'post_type' => 'page' ),
		);
		foreach ( self::NAMES as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $ability, $name );
			$this->assertSame( 'read', leitvia_studio()->registry( 'abilities' )->get( $name )['group'] );
			$this->assertSame( 'leitvia', $ability->get_category() );
			$meta = $ability->get_meta();
			$this->assertTrue( $meta['annotations']['readonly'] );
			$this->assertFalse( $meta['annotations']['destructive'] );
			$this->assertTrue( $meta['annotations']['idempotent'] );
			$this->assertFalse( $meta['annotations']['openWorldHint'] );
			$this->assertFalse( $meta['public'] ?? false );
			$this->assertNotEmpty( $ability->get_output_schema() );
			$this->call( $name, $inputs[ $name ] ?? null );
		}
	}

	/**
	 * Users without edit_posts are refused, over PHP and REST; REST reads with GET.
	 */
	public function test_permission_and_rest_method(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$agent      = get_current_user_id();
		wp_set_current_user( $subscriber );
		foreach ( self::NAMES as $name ) {
			$this->assertWPError( wp_get_ability( $name )->execute( 'leitvia/get-pattern' === $name ? array( 'slug' => self::PATTERN ) : null ), $name );
		}
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/leitvia/get-contract/run' ) )->get_status() );
		wp_set_current_user( $agent );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/leitvia/get-contract/run' ) )->get_status() );
		$this->assertSame( 405, rest_do_request( new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/leitvia/get-contract/run' ) )->get_status() );
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/leitvia/list-patterns/run' );
		$request->set_query_params( array( 'input' => array( 'per_page' => '2' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data()['items'] );
	}

	/**
	 * The contract comes with rules, roles, pairs, composition rules, page frames, parts, templates and block styles,
	 * without patterns.
	 */
	public function test_contract(): void {
		$result = $this->call( 'leitvia/get-contract' );
		$this->assertSame( leitvia_studio_contract()->version(), $result['version'] );
		foreach ( array( 'rules', 'roles', 'pairs', 'compositionRules', 'pageFrames', 'templateParts', 'templates', 'blockStyles', 'variations' ) as $key ) {
			$this->assertNotEmpty( $result['contract'][ $key ], $key );
		}
		$this->assertArrayNotHasKey( 'patterns', $result['contract'] );
		$this->assertArrayNotHasKey( 'studioPatterns', $result['contract'] );
		// The site the connection works on, so an agent can check it before the first write.
		$this->assertSame(
			array(
				'url'       => home_url( '/' ),
				'rest'      => rest_url(),
				'name'      => get_bloginfo( 'name' ),
				'language'  => get_bloginfo( 'language' ),
				'multisite' => is_multisite(),
			),
			$result['site']
		);
	}

	/**
	 * Without a set the tokens come from the global styles; an active set is the source and listed as active, an
	 * applied variation shows, a changed set drifts.
	 */
	public function test_tokens(): void {
		$result = $this->call( 'leitvia/get-tokens' );
		$this->assertSame( 'global_styles', $result['source'] );
		$this->assertSame( array(), $result['variations'] );
		$this->assertArrayHasKey( 'color', $result['document'] );

		$set = Token_Sets::create_from_active_theme( 'Brand' );
		$this->assertIsInt( $set );
		$this->assertTrue( Compiler::activate( $set ) );
		$result = $this->call( 'leitvia/get-tokens' );
		$this->assertSame( 'set', $result['source'] );
		$this->assertSame( $set, $result['active'] );
		$this->assertSame( 'active', wp_list_pluck( $result['sets'], 'status', 'id' )[ $set ] );
		$this->assertFalse( $result['drift'] );
		$this->assertSame( array(), $result['contrast'] );

		// Full variation in the user global styles.
		$variation = wp_list_filter( WP_Theme_JSON_Resolver::get_style_variations(), array( 'slug' => 'corporate-dark' ) );
		$this->assertNotEmpty( $variation );
		$styles = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$data   = array(
			'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
		) + array_intersect_key( reset( $variation ), array_flip( array( 'settings', 'styles' ) ) );
		wp_update_post(
			array(
				'ID'           => $styles['ID'],
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		$result = $this->call( 'leitvia/get-tokens' );
		$this->assertContains( 'corporate-dark', $result['variations'] );
		$this->assertTrue( $result['drift'] );
	}

	/**
	 * With a style variation in the user global styles get-tokens returns its values, not the theme palette; after a
	 * set is activated over it, the document and the contrast findings are those of the set and there is no drift.
	 */
	public function test_tokens_over_a_style_variation(): void {
		Test_Compiler::apply_variation( 'corporate-light' );
		$result = $this->call( 'leitvia/get-tokens' );
		$this->assertSame( 'global_styles', $result['source'] );
		$this->assertSame( '#2152c7', $result['document']['color']['accent']['$value']['hex'] );
		$this->assertSame( 'IBM Plex Sans', $result['document']['fontFamily']['heading']['$value'][0] );

		$set = Token_Sets::create_from_active_theme( 'Brand' );
		$doc = Compiler::document( $set );
		$this->assertSame( '#2152c7', $doc['color']['accent']['$value']['hex'] );
		$doc['color']['accent']['$value'] = array(
			'colorSpace' => 'srgb',
			'components' => array( 0.7216, 0.2902, 0 ),
			'hex'        => '#b84a00',
		);
		update_post_meta( $set, Token_Sets::META, wp_slash( (string) wp_json_encode( $doc ) ) );
		$this->assertTrue( Compiler::activate( $set ) );

		$result = $this->call( 'leitvia/get-tokens' );
		$this->assertSame( 'set', $result['source'] );
		$this->assertFalse( $result['drift'] );
		$this->assertSame( '#b84a00', $result['document']['color']['accent']['$value']['hex'] );
		$this->assertSame( array(), $result['contrast'] );
		$this->assertStringContainsString( '--wp--preset--color--accent: #b84a00', wp_get_global_stylesheet( array( 'variables' ) ) );
	}

	/**
	 * Theme and Studio patterns with metadata and lab status; filters and pages.
	 */
	public function test_list_patterns(): void {
		$all = $this->call( 'leitvia/list-patterns', array( 'per_page' => 50 ) );
		$this->assertGreaterThan( 50, $all['total'] );
		$this->assertSame( 2, $all['pages'] );
		$item = wp_list_filter( $all['items'], array( 'slug' => self::PATTERN ) );
		$item = reset( $item );
		$this->assertSame( 'theme', $item['source'] );
		$this->assertSame( 'approved', $item['status'] );
		$this->assertNull( $item['replacedBy'] );
		$this->assertNotEmpty( $item['useWhen'] );
		$this->assertArrayHasKey( 'avoidWhen', $item );
		$this->assertArrayHasKey( 'role', $item['composition'] );
		$this->assertArrayHasKey( 'images', $item );
		$this->assertArrayNotHasKey( 'content', $item );

		$studio = $this->call(
			'leitvia/list-patterns',
			array(
				'per_page' => 50,
				'page'     => 2,
			)
		);
		$this->assertContains( 'studio', array_merge( wp_list_pluck( $all['items'], 'source' ), wp_list_pluck( $studio['items'], 'source' ) ) );

		$hooks = $this->call(
			'leitvia/list-patterns',
			array(
				'role'   => 'hook',
				'layout' => 'split',
			)
		);
		$this->assertNotEmpty( $hooks['items'] );
		foreach ( $hooks['items'] as $hook ) {
			$this->assertSame( array( 'hook', 'split' ), array( $hook['composition']['role'], $hook['composition']['layout'] ) );
		}
		$category = $item['categories'][0];
		foreach ( $this->call( 'leitvia/list-patterns', array( 'category' => $category ) )['items'] as $match ) {
			$this->assertContains( $category, $match['categories'] );
		}
		$page = $this->call(
			'leitvia/list-patterns',
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( array_slice( wp_list_pluck( $all['items'], 'slug' ), 2, 2 ), wp_list_pluck( $page['items'], 'slug' ) );
		$this->assertSame( 'ability_invalid_input', wp_get_ability( 'leitvia/list-patterns' )->execute( array( 'per_page' => 51 ) )->get_error_code() );
	}

	/**
	 * Lab patterns follow the registered ones as block:<ID> with lab status, version, document hash and the
	 * composition of the theme pattern they vary; get-pattern returns their markup. Private ones stay hidden.
	 */
	public function test_list_patterns_lab(): void {
		$approved = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Quote, shorter',
				'post_content' => '<!-- wp:paragraph --><p>Lab quote</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'_leitvia_lab_status'         => 'approved',
					'_leitvia_lab_version'        => '1.0.0',
					'_leitvia_lab_source_pattern' => 'leitvia/content-quote',
				),
			)
		);
		$draft    = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->',
				'meta_input'   => array( '_leitvia_lab_status' => 'draft' ),
			)
		);
		$hidden   = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'private',
			)
		);
		$items    = array();
		foreach ( array( 1, 2 ) as $page ) {
			$found = $this->call(
				'leitvia/list-patterns',
				array(
					'per_page' => 50,
					'page'     => $page,
				)
			);
			$items = array_merge( $items, array_column( $found['items'], null, 'slug' ) );
		}
		$lab = $items[ 'block:' . $approved ] ?? null;
		$this->assertIsArray( $lab );
		$this->assertSame( 'lab', $lab['source'] );
		$this->assertSame( 'Quote, shorter', $lab['title'] );
		$this->assertSame( 'approved', $lab['status'] );
		$this->assertSame( '1.0.0', $lab['version'] );
		$this->assertSame( Hashes::document( $approved ), $lab['hash'] );
		$this->assertSame( $items['leitvia/content-quote']['composition'], $lab['composition'] );
		$this->assertSame( 'draft', $items[ 'block:' . $draft ]['status'] );
		$this->assertArrayNotHasKey( 'block:' . $hidden, $items );
		$role = $this->call(
			'leitvia/list-patterns',
			array(
				'role'     => $lab['composition']['role'],
				'per_page' => 50,
			)
		);
		$this->assertContains( 'block:' . $approved, wp_list_pluck( $role['items'], 'slug' ) );

		$result = $this->call( 'leitvia/get-pattern', array( 'slug' => 'block:' . $approved ) );
		$this->assertStringContainsString( 'Lab quote', $result['content'] );
	}

	/**
	 * One pattern with markup and usage; at most 20 usages plus more; unknown slugs are refused.
	 */
	public function test_get_pattern(): void {
		$markup = '<!-- wp:group {"metadata":{"patternName":"' . self::PATTERN . '"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
		$posts  = self::factory()->post->create_many(
			21,
			array(
				'post_type'    => 'page',
				'post_content' => $markup,
			)
		);
		$result = $this->call( 'leitvia/get-pattern', array( 'slug' => self::PATTERN ) );
		$this->assertStringContainsString( '<!-- wp:', $result['content'] );
		$this->assertSame( 'hook', $result['composition']['role'] );
		$this->assertCount( 20, $result['usage']['items'] );
		$this->assertTrue( $result['usage']['more'] );
		$this->assertContains( $posts[0], wp_list_pluck( $result['usage']['items'], 'id' ) );

		$missing = wp_get_ability( 'leitvia/get-pattern' )->execute( array( 'slug' => 'leitvia/none' ) );
		$this->assertSame( 'leitvia_not_found', $missing->get_error_code() );
		$this->assertSame( 'leitvia/list-patterns', $missing->get_error_data()['next_ability'] );
	}

	/**
	 * The content model declaration with types and fields.
	 */
	public function test_content_model(): void {
		$filter = static fn(): array => array(
			'version'   => 1,
			'postTypes' => array(
				'event' => array(
					'label'         => 'Events',
					'singularLabel' => 'Event',
				),
			),
		);
		add_filter( 'leitvia_studio_content_model', $filter );
		Model::forget();
		$result = $this->call( 'leitvia/get-content-model' );
		remove_filter( 'leitvia_studio_content_model', $filter );
		$this->assertSame( 'Events', $result['declaration']['postTypes']['event']['label'] );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '', $result['error'] );
	}

	/**
	 * The whole catalog, or only the blocks the editor allows for a post type; post types outside the scope are refused.
	 */
	public function test_list_blocks(): void {
		$all = $this->call( 'leitvia/list-blocks' );
		$this->assertSame( \LeitviaStudio\Blocks\Catalog::get(), $all['blocks'] );
		$name   = $all['blocks'][0]['name'];
		$filter = static fn( $allowed, WP_Block_Editor_Context $context ) => 'page' === $context->post->post_type ? array( $name, 'core/paragraph' ) : $allowed;
		add_filter( 'allowed_block_types_all', $filter, 10, 2 );
		$page   = $this->call( 'leitvia/list-blocks', array( 'post_type' => 'page' ) );
		$post   = $this->call( 'leitvia/list-blocks', array( 'post_type' => 'post' ) );
		remove_filter( 'allowed_block_types_all', $filter );
		$this->assertSame( array( $name ), wp_list_pluck( $page['blocks'], 'name' ) );
		$this->assertCount( count( $all['blocks'] ), $post['blocks'] );
		$this->assertSame( 'leitvia_invalid_input', wp_get_ability( 'leitvia/list-blocks' )->execute( array( 'post_type' => 'user_request' ) )->get_error_code() );
	}
}
