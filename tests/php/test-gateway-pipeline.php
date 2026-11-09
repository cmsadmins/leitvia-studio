<?php
/**
 * Gateway pipeline.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Normalize;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Store;

/**
 * The eight cases of the pipeline: identity, scope, base hash, content safety, autonomy, checks, snapshot and audit,
 * and a failed write. Plus the write helper.
 *
 * @group multisite
 */
class Test_Gateway_Pipeline extends WP_UnitTestCase {

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Agent user and clean settings.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $this->agent );
		delete_option( Gateway::OPTION );
	}

	/**
	 * Removes the test checks and abilities.
	 */
	public function tear_down(): void {
		if ( wp_has_ability( 'leitvia/test-write' ) ) {
			wp_unregister_ability( 'leitvia/test-write' );
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * An operation on a post with the current hash.
	 *
	 * @param array<string, mixed> $target Target.
	 * @param callable             $change Change.
	 * @param string               $area   Area.
	 */
	private static function op( array $target, callable $change, string $area = 'content' ): Operation {
		$base = isset( $target['post'] ) ? Hashes::document( (int) $target['post'] ) : '';
		return new Operation( 'leitvia/test-write', $area, $target, $base, $change );
	}

	/**
	 * A change that sets post fields.
	 *
	 * @param array<string, mixed> $fields Fields.
	 */
	private static function set( array $fields ): Closure {
		return static fn( array $state ): array => $fields + $state;
	}

	/**
	 * A published page by another user.
	 *
	 * @param string $content Content.
	 */
	private function page( string $content = '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
				'post_author'  => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
	}

	/**
	 * Adds a check to the registry; scope content arrives with Task 6, so it goes in past the field check.
	 *
	 * @param list<array<string, mixed>>|callable $findings Findings the check returns, or the check callback.
	 */
	private static function add_check( $findings ): void {
		$registry = leitvia_studio()->registry( 'checks' );
		$items    = new ReflectionProperty( $registry, 'items' );
		$items->setValue(
			$registry,
			$items->getValue( $registry ) + array(
				'test/check' => array(
					'owner'    => 'test',
					'name'     => 'test/check',
					'label'    => 'Test',
					'scope'    => 'content',
					'callback' => is_callable( $findings ) ? $findings : static fn(): array => $findings,
				),
			)
		);
	}

	/**
	 * Audit rows of the agent.
	 *
	 * @return list<array<string, string>>
	 */
	private function audit(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'audit' ) . ' WHERE user_id = %d ORDER BY id', $this->agent ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
	}

	/**
	 * Asserts a rejection with code and the fixed data keys.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Result.
	 * @return array<string, mixed> Error data.
	 */
	private function assert_rejected( string $code, $result ): array {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$data = $result->get_error_data();
		foreach ( array( 'status', 'reason', 'target', 'next_ability', 'retry' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, $key );
		}
		return $data;
	}

	/**
	 * 1. User 0 and users without the capability are rejected; the rate limit applies.
	 */
	public function test_identity(): void {
		$page = $this->page();
		$op   = self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'New' ) ) );
		wp_set_current_user( 0 );
		$this->assertSame( 'never', $this->assert_rejected( 'leitvia_forbidden', Pipeline::run( $op ) )['retry'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assert_rejected( 'leitvia_forbidden', Pipeline::run( $op ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 'capability', $this->assert_rejected( 'leitvia_forbidden', Pipeline::run( $op ) )['reason'], 'contributors may not edit others\' pages' );
		wp_set_current_user( $this->agent );
		update_option( Gateway::OPTION, array( 'rate_per_minute' => 1 ) );
		Store::audit(
			array(
				'ability' => 'leitvia/get-contract',
				'result'  => 'ok',
			)
		);
		$this->assertSame( 429, $this->assert_rejected( 'leitvia_rate_limited', Pipeline::run( $op ) )['status'] );
	}

	/**
	 * 2. Targets outside the scope are rejected: private posts, blocked types, other options, a private status.
	 */
	public function test_out_of_scope(): void {
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$data    = $this->assert_rejected( 'leitvia_out_of_scope', Pipeline::run( self::op( array( 'post' => $private ), self::set( array() ) ) ) );
		$this->assertSame( array( 'post' => $private ), $data['target'] );
		$this->assert_rejected(
			'leitvia_out_of_scope',
			Pipeline::run(
				self::op(
					array(
						'post'      => 0,
						'post_type' => 'user_request',
					),
					self::set( array() )
				)
			)
		);
		$this->assert_rejected( 'leitvia_out_of_scope', Pipeline::run( self::op( array( 'option' => 'admin_email' ), static fn(): array => array( 'value' => 'x@example.com' ) ) ) );
		$page = $this->page();
		$this->assert_rejected( 'leitvia_out_of_scope', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_status' => 'private' ) ), 'corrections' ) ) );
		$this->assertSame( 'publish', get_post_status( $page ) );
	}

	/**
	 * 2b. An option of an add-on is open only through the filter leitvia_studio_gateway_options, and its check runs on
	 * every write, a replay of a package as well: an agent proposes, the check refuses a bad value, a person applies.
	 */
	public function test_addon_option(): void {
		$option = 'my_addon_value';
		add_option( $option, array(), '', false );
		$op      = static fn( $value ): Operation => new Operation( 'leitvia/test-write', 'design', array( 'option' => $option ), Hashes::of( (string) wp_json_encode( get_option( $option ) ) ), static fn(): array => array( 'value' => $value ), array(), 'addon-test' );
		$checked = array();
		$this->assert_rejected( 'leitvia_out_of_scope', Pipeline::run( $op( array( 'a' => 1 ) ) ) );
		// Outside the arrow function, which would capture $checked by value.
		$check = static function ( $value ) use ( &$checked ) {
			$checked[] = $value;
			return is_array( $value ) && ! isset( $value['bad'] ) ? true : new WP_Error( 'my_addon_bad', 'Bad value.' );
		};
		add_filter(
			'leitvia_studio_gateway_options',
			static fn( array $options ): array => $options + array(
				$option    => $check,
				'blogname' => static fn(): bool => false,
			)
		);
		$bad = Pipeline::run( $op( array( 'bad' => 1 ) ) );
		$this->assertSame( 'option', $this->assert_rejected( 'leitvia_invalid_input', $bad )['reason'] );
		$this->assertSame( 'Bad value.', $bad->get_error_message() );
		$result = Pipeline::run( $op( array( 'a' => 1 ) ) );
		$this->assertSame( 'package', $result['mode'], 'agents propose settings' );
		$this->assertSame( array(), get_option( $option ) );
		$this->assertNotContains( 'organization', $result['kinds'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Packages::apply( (int) $result['package'] );
		$this->assertSame( array( 'a' => 1 ), get_option( $option ) );
		$this->assertSame( array( array( 'bad' => 1 ), array( 'a' => 1 ), array( 'a' => 1 ) ), $checked, 'checked when proposed and when applied' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( $option, wp_load_alloptions(), 'the write keeps autoload off' );
		$blog = get_option( 'blogname' );
		$this->assertIsArray( Pipeline::run( new Operation( 'leitvia/test-write', 'structure', array( 'option' => 'blogname' ), Hashes::of( (string) wp_json_encode( $blog ) ), static fn(): array => array( 'value' => 'Other' ) ) ), 'the filter cannot take over a core option' );
		remove_all_filters( 'leitvia_studio_gateway_options' );
	}

	/**
	 * 2c. Core options that matter for security never open, whatever the filter returns, and a check that throws
	 * refuses the write instead of breaking it.
	 *
	 * @throws RuntimeException Only inside the check of my_addon_throws, which the pipeline catches.
	 */
	public function test_addon_option_guards(): void {
		global $wpdb;
		$closed = array( 'siteurl', 'home', 'active_plugins', 'auto_update_plugins', 'auto_update_core_major', 'users_can_register', 'default_role', $wpdb->prefix . 'user_roles', 'admin_email', 'template', 'stylesheet', 'auth_key', 'secure_auth_salt', 'cron', Gateway::OPTION, '_transient_doing_cron', 'my_addon_api_key' );
		add_filter( 'leitvia_studio_gateway_options', static fn( array $options ): array => $options + array_fill_keys( $closed, '__return_true' ) + array( 'my_addon_throws' => static fn() => throw new RuntimeException( 'boom' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( $closed as $option ) {
			$this->assertTrue( Pipeline::protected_option( $option ), $option );
			$this->assertArrayNotHasKey( $option, Pipeline::addon_options(), $option );
			$value = get_option( $option );
			$this->assert_rejected( 'leitvia_out_of_scope', Pipeline::run( new Operation( 'leitvia/test-write', 'design', array( 'option' => $option ), Hashes::of( (string) wp_json_encode( $value ) ), static fn(): array => array( 'value' => 'x' ) ) ) );
			$this->assertSame( $value, get_option( $option ), $option );
		}
		add_option( 'my_addon_throws', array(), '', false );
		$thrown = Pipeline::run( new Operation( 'leitvia/test-write', 'design', array( 'option' => 'my_addon_throws' ), Hashes::of( (string) wp_json_encode( array() ) ), static fn(): array => array( 'value' => array( 'a' => 1 ) ) ) );
		$this->assertSame( 'option', $this->assert_rejected( 'leitvia_invalid_input', $thrown )['reason'] );
		$this->assertSame( array(), get_option( 'my_addon_throws' ) );
		remove_all_filters( 'leitvia_studio_gateway_options' );
	}

	/**
	 * 3. A wrong base hash is rejected with the current hash, status 409, the outline to read and retry once.
	 */
	public function test_stale_hash(): void {
		$page = $this->page();
		$op   = new Operation(
			'leitvia/test-write',
			'corrections',
			array(
				'post'  => $page,
				'block' => 'k3f9a2c1',
			),
			'0000000000000000',
			self::set( array( 'post_title' => 'New' ) )
		);
		$data = $this->assert_rejected( 'leitvia_stale_hash', Pipeline::run( $op ) );
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( Hashes::document( $page ), $data['current_hash'] );
		$this->assertSame( 'leitvia/get-page-outline', $data['next_ability'] );
		$this->assertSame( 'once', $data['retry'] );
		$this->assertSame(
			array(
				'post'  => $page,
				'block' => 'k3f9a2c1',
			),
			$data['target']
		);
		$this->assert_rejected( 'leitvia_stale_hash', Pipeline::run( new Operation( 'leitvia/test-write', 'corrections', array( 'post' => $page ), '', self::set( array() ) ) ) );
	}

	/**
	 * 4. Scripts, Custom HTML, Base64 and event attributes are rejected with the block; allowed markup arrives as the
	 * KSES of core leaves it, with the same meaning: entities and self-closing tags in its spelling, special characters
	 * in attributes as entities, backslashes and quotes intact.
	 */
	public function test_content_safety(): void {
		$page  = $this->page();
		$cases = array(
			'markup'        => '<!-- wp:paragraph --><p>Hi<script>alert(1)</script></p><!-- /wp:paragraph -->',
			'html_block'    => '<!-- wp:html --><p>Raw</p><!-- /wp:html -->',
			'base64'        => '<!-- wp:image --><figure class="wp-block-image"><img src="data:image/png;base64,iVBORw0KGgo=" alt="Dot"/></figure><!-- /wp:image -->',
			'classic_block' => '<p>Loose markup</p>',
		);
		foreach ( $cases as $reason => $content ) {
			$data = $this->assert_rejected( 'leitvia_unsafe_content', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $content ) ), 'corrections' ) ) );
			$this->assertSame( $reason, $data['reason'], $reason );
			$this->assertSame( 'p:0', $data['target']['block'], $reason );
		}
		$nested = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Fine</p><!-- /wp:paragraph --><!-- wp:paragraph {"metadata":{"leitviaId":"ab12cd34"}} --><p onclick="steal()">Click</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$data   = $this->assert_rejected( 'leitvia_unsafe_content', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $nested ) ), 'corrections' ) ) );
		$this->assertSame( 'ab12cd34', $data['target']['block'] );
		$attribute = serialize_block(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'name' => '<script>x</script>' ) ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>x</p>',
				'innerContent' => array( '<p>x</p>' ),
			)
		);
		$this->assert_rejected( 'leitvia_unsafe_content', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $attribute ) ), 'corrections' ) ) );

		$safe   = serialize_block(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(
					'metadata' => array(
						'leitviaId' => 'safe0001',
						'name'      => 'a < b "quoted" -- back\\slash \\" & more',
					),
					'anchor'   => 'x--y',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p id="x--y">5 > 3 &amp; "q" back\\slash<br/></p>',
				'innerContent' => array( '<p id="x--y">5 > 3 &amp; "q" back\\slash<br/></p>' ),
			)
		);
		$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $safe ) ), 'corrections' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( wp_unslash( wp_filter_post_kses( wp_slash( $safe ) ) ), get_post_field( 'post_content', $page, 'raw' ), 'what core stores for a user without unfiltered_html, wp_slash round trip' );
		$this->assertSame( 'a < b "quoted" -- back\\slash \\" & more', html_entity_decode( parse_blocks( get_post_field( 'post_content', $page, 'raw' ) )[0]['attrs']['metadata']['name'], ENT_QUOTES | ENT_HTML5 ) );
		$this->assertSame( Hashes::document( $page ), $result['hash'] );
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'KSES is back after the write' );
	}

	/**
	 * A rejection names block, tag, attribute and an excerpt, also in the message; mso-* styles and empty o:p of a word
	 * processor are dropped instead of rejected, an o:p with text is rejected with its tag.
	 */
	public function test_unsafe_place_and_office_rests(): void {
		$page  = $this->page();
		$cases = array(
			'event'     => array( '<!-- wp:paragraph {"metadata":{"leitviaId":"ev000001"}} --><p>Hi <span onclick="steal()">there</span></p><!-- /wp:paragraph -->', 'ev000001', 'span', 'onclick', '<span onclick="steal()">' ),
			'script'    => array( '<!-- wp:paragraph --><p>Hi<script>alert(1)</script></p><!-- /wp:paragraph -->', 'p:0', 'script', '', '<script>' ),
			'o:p'       => array( '<!-- wp:paragraph --><p>Text<o:p>Rest</o:p></p><!-- /wp:paragraph -->', 'p:0', 'o:p', '', '<o:p>' ),
			'attribute' => array( '<!-- wp:paragraph {"metadata":{"name":"<script>x</script>"}} --><p>x</p><!-- /wp:paragraph -->', 'p:0', '', 'metadata.name', '<script>x</script>' ),
			'nested'    => array( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Fine</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><a href="javascript:alert(1)">Go</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->', 'p:0.1', 'a', 'href', '<a href="javascript:alert(1)">' ),
		);
		foreach ( $cases as $case => [ $content, $block, $tag, $attribute, $excerpt ] ) {
			$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $content ) ), 'corrections' ) );
			$data   = $this->assert_rejected( 'leitvia_unsafe_content', $result );
			$this->assertSame( $block, $data['found']['block'], $case );
			$this->assertSame( $tag, $data['found']['tag'], $case );
			$this->assertSame( $attribute, $data['found']['attribute'], $case );
			$this->assertSame( $excerpt, $data['found']['excerpt'], $case );
			$this->assertStringContainsString( $block, $result->get_error_message(), $case );
			$this->assertStringContainsString( $excerpt, $result->get_error_message(), $case );
		}
		$long = $this->assert_rejected( 'leitvia_unsafe_content', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => '<!-- wp:html --><p>' . str_repeat( 'a', 200 ) . '</p><!-- /wp:html -->' ) ), 'corrections' ) ) );
		$this->assertSame( 'html_block', $long['reason'] );
		$this->assertSame( 81, mb_strlen( $long['found']['excerpt'] ), 'excerpt cut at 80 characters plus ellipsis' );

		$word   = '<!-- wp:paragraph --><p class="has-text-align-left"><b style="mso-bidi-font-weight: normal">Bold</b> <span style="mso-fareast-language: DE; text-decoration: underline">red</span>.<o:p></o:p><o:p>&nbsp;</o:p></p><!-- /wp:paragraph -->';
		$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $word ) ), 'corrections' ) );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$stored = (string) get_post_field( 'post_content', $page, 'raw' );
		$this->assertStringContainsString( '<p class="has-text-align-left"><b>Bold</b> <span style="text-decoration: underline">red</span>.</p>', $stored );
		$this->assertStringNotContainsString( 'mso-', $stored );
		$this->assertSame( '<p>Plain</p>', Pipeline::office( '<p>Plain</p>' ), 'content without rests stays byte for byte' );
	}

	/**
	 * Markup repairs before the checks: the vertical alignment of columns reaches columns without their own, and the
	 * inner blocks of an add-on block get the box of its catalog example; the stored content is the repaired one.
	 * Content without anything to repair stays byte for byte, a column keeps its own alignment, and a hull with text
	 * in the example is left alone.
	 */
	public function test_normalize_columns_and_static_hull(): void {
		$columns = '<!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column {"verticalAlignment":"top"} --><div class="wp-block-column is-vertically-aligned-top"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$fixed   = Normalize::markup( $columns );
		$inner   = parse_blocks( $fixed )[0]['innerBlocks'];
		$this->assertSame( 'center', $inner[0]['attrs']['verticalAlignment'] );
		$this->assertStringContainsString( '<div class="wp-block-column is-vertically-aligned-center">', $fixed );
		$this->assertSame( 'top', $inner[1]['attrs']['verticalAlignment'], 'its own value stays' );
		$plain = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$this->assertSame( $plain, Normalize::markup( $plain ) );

		add_action(
			'leitvia_studio_init',
			static function ( $studio ): void {
				$studio->extensions()->register(
					'hull-addon',
					array(
						'label'           => 'Hull Add-on',
						'version'         => '1.0.0',
						'requires_studio' => '0.1.0',
						'requires_api'    => 1,
					)
				);
				$studio->registry( 'blocks' )->register( 'hull-addon/aside', array( 'path' => dirname( __DIR__ ) . '/fixtures/hull-block' ), 'hull-addon' );
			}
		);
		register_block_type( dirname( __DIR__ ) . '/fixtures/hull-block' );
		leitvia_studio()->reset_for_tests();
		$loose = '<!-- wp:hull-addon/aside {"align":"wide"} --><div class="wp-block-hull-addon-aside alignwide"><!-- wp:heading --><h2 class="wp-block-heading">Facts</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><!-- /wp:hull-addon/aside -->';
		$boxed = '<!-- wp:hull-addon/aside {"align":"wide"} --><div class="wp-block-hull-addon-aside alignwide"><div class="wp-block-hull-addon-aside__box"><!-- wp:heading --><h2 class="wp-block-heading">Facts</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div></div><!-- /wp:hull-addon/aside -->';
		$this->assertSame( $boxed, Normalize::markup( $loose ) );
		$this->assertSame( $boxed, Normalize::markup( $boxed ), 'a block with its box stays' );

		// Only blocks the write changes: a stored block keeps its spelling (escaped slashes that serialize_block() would
		// drop) and stored columns keep their alignment as they are, while a new block next to them is repaired.
		$kept   = '<!-- wp:acf/decor {"url":"https:\/\/example.com\/"} --><div class="decor"></div><!-- /wp:acf/decor -->' . "\n\n" . $columns;
		$result = Normalize::markup( $kept . "\n\n" . $loose, $kept );
		$this->assertStringStartsWith( $kept . "\n\n", $result, 'stored blocks stay byte for byte' );
		$this->assertStringEndsWith( $boxed, $result );
		$this->assertSame( $kept . $columns, Normalize::markup( $kept . $columns, $kept ), 'a copy of stored columns stays' );

		$page   = $this->page();
		$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => $loose . $columns ) ), 'corrections' ) );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$stored = (string) get_post_field( 'post_content', $page, 'raw' );
		$this->assertStringContainsString( '<div class="wp-block-hull-addon-aside__box">', $stored );
		$this->assertStringContainsString( 'is-vertically-aligned-center"><!-- wp:paragraph', $stored, 'the first column took the alignment (the paragraph carries its block ID)' );
		unregister_block_type( 'hull-addon/aside' );
	}


	/**
	 * Office rests: office() cleans only a text the write changes: a change of the title alone leaves Word rests a person stored as
	 * they are instead of rewriting the content on the side. The stored block is not checked again (see Pipeline::unsafe()), so
	 * the title changes and the rests stay; only the block ID lands in the delimiter.
	 */
	public function test_office_rests_stay_on_a_write_without_content(): void {
		$page = $this->page();
		$word = '<!-- wp:paragraph --><p><b style="mso-bidi-font-weight: normal">Bold</b></p><!-- /wp:paragraph -->';
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $word ), array( 'ID' => $page ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Content written as by a person.
		clean_post_cache( $page );

		$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'New title' ) ), 'corrections' ) );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertStringContainsString( '<p><b style="mso-bidi-font-weight: normal">Bold</b></p>', get_post_field( 'post_content', $page, 'raw' ) );
		$this->assertSame( 'New title', get_post_field( 'post_title', $page, 'raw' ) );
		$this->assertNotNull( Pipeline::unsafe( $word ), 'the same rests in new content are still found' );
	}

	/**
	 * New content of an agent goes through the KSES of core and the hash is the one of what was stored; a rollback puts
	 * a snapshot back past KSES, so markup a person with unfiltered_html wrote survives it.
	 */
	public function test_kses_on_new_content_and_off_for_snapshots(): void {
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'agents have no unfiltered_html' );
		$page = $this->page();
		// What a person with unfiltered_html may store: a Custom HTML block with an event attribute, past KSES.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<!-- wp:html --><div onclick="x()">Kept</div><!-- /wp:html -->' ), array( 'ID' => $page ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Content written as by a user with unfiltered_html.
		clean_post_cache( $page );

		$written = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => '<!-- wp:paragraph --><p>Line<br/>break</p><!-- /wp:paragraph -->' ) ), 'corrections' ) );
		$this->assertIsArray( $written, $written instanceof WP_Error ? $written->get_error_message() : '' );
		$this->assertSame( 'live', $written['mode'] );
		$stored = (string) get_post_field( 'post_content', $page, 'raw' );
		$this->assertStringContainsString( '<p>Line<br />break</p>', $stored, 'KSES ran and normalized the tag' );
		$this->assertSame( Hashes::document( $page ), $written['hash'], 'the hash is the one of the stored content' );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		$this->assertFalse( has_filter( 'safecss_filter_attr_allow_css', array( Pipeline::class, 'allow_colors' ) ) );

		// The rollback puts the snapshot back as it was stored, the block of the person included.
		$state = (array) Store::get_snapshot( (int) $written['snapshot'] )['state'];
		$undo  = new Operation( 'leitvia/rollback', 'corrections', array( 'post' => $page ), $written['hash'], static fn(): array => $state );
		$back  = Pipeline::run( $undo, 0, true );
		$this->assertIsArray( $back, $back instanceof WP_Error ? $back->get_error_message() : '' );
		$this->assertStringContainsString( '<div onclick="x()">Kept</div>', (string) get_post_field( 'post_content', $page, 'raw' ) );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'KSES is back after the rollback' );
	}

	/**
	 * 5. Locked rejects, suggest packages, draft creates drafts and packages changes to published posts, autonomous
	 * writes live with snapshot and audit.
	 */
	public function test_levels(): void {
		$page = $this->page();
		$old  = get_post_field( 'post_content', $page, 'raw' );
		$new  = self::set( array( 'post_content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 'never', $this->assert_rejected( 'leitvia_locked', Pipeline::run( self::op( array( 'post' => $page ), $new, 'admin' ) ) )['retry'] );

		$suggest = Pipeline::run( self::op( array( 'post' => $page ), $new, 'design' ) );
		$this->assertSame( array( 'package', 'suggest' ), array( $suggest['mode'], $suggest['level'] ) );
		$this->assertSame( 'open', Packages::state( $suggest['package'] ) );
		$this->assertSame( $old, get_post_field( 'post_content', $page, 'raw' ), 'suggest writes nothing' );

		$changed = Pipeline::run( self::op( array( 'post' => $page ), $new ) );
		$this->assertSame( array( 'package', 'draft' ), array( $changed['mode'], $changed['level'] ) );
		$this->assertSame( $old, get_post_field( 'post_content', $page, 'raw' ), 'draft packages changes to published posts' );

		$created = Pipeline::run(
			self::op(
				array(
					'post'      => 0,
					'post_type' => 'page',
				),
				self::set( array( 'post_title' => 'Fresh' ) )
			)
		);
		$this->assertSame( 'draft', $created['mode'] );
		$this->assertSame( 'draft', get_post_status( $created['target']['post'] ) );
		$this->assertSame( $this->agent, (int) get_post_field( 'post_author', $created['target']['post'] ) );

		$draft = Pipeline::run( self::op( array( 'post' => $created['target']['post'] ), self::set( array( 'post_title' => 'Fresher' ) ) ) );
		$this->assertSame( 'draft', $draft['mode'], 'draft writes to unpublished posts' );
		$this->assertSame( 'Fresher', get_the_title( $created['target']['post'] ) );

		$live = Pipeline::run( self::op( array( 'post' => $page ), $new, 'corrections' ) );
		$this->assertSame( array( 'live', 'autonomous' ), array( $live['mode'], $live['level'] ) );
		$this->assertStringContainsString( 'New', get_post_field( 'post_content', $page, 'raw' ) );
		$this->assertSame( Hashes::document( $page ), $live['hash'] );
		$this->assertGreaterThan( 0, $live['snapshot'] );

		update_option( Gateway::OPTION, array( 'profile' => 'full' ) );
		$published = Pipeline::run(
			self::op(
				array(
					'post'      => 0,
					'post_type' => 'page',
				),
				self::set( array( 'post_title' => 'Published' ) )
			)
		);
		$this->assertSame( 'publish', get_post_status( $published['target']['post'] ), 'autonomous creates and publishes' );
	}

	/**
	 * 6. An error finding the write brings rejects with the findings; a warning turns autonomous into a package and
	 * passes elsewhere.
	 */
	public function test_checks(): void {
		$page    = $this->page();
		$finding = array(
			'block'   => 'p:0',
			'message' => 'Heading level skipped.',
			'fix'     => 'Use h2.',
		);
		$new     = array( 'post_content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' );
		self::add_check( static fn( string $content ): array => str_contains( $content, 'New' ) ? array( $finding ) : array() );
		$data = $this->assert_rejected( 'leitvia_check_failed', Pipeline::run( self::op( array( 'post' => $page ), self::set( $new ), 'corrections' ) ) );
		$this->assertSame(
			array(
				$finding + array(
					'check'    => 'test/check',
					'severity' => 'error',
				),
			),
			$data['findings']
		);

		leitvia_studio()->reset_for_tests();
		self::add_check( static fn( string $content ): array => str_contains( $content, 'New' ) ? array( $finding + array( 'severity' => 'warning' ) ) : array() );
		$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'X' ) + $new ), 'corrections' ) );
		$this->assertSame( array( 'package', 'autonomous' ), array( $result['mode'], $result['level'] ) );
		$this->assertNotSame( 'X', get_the_title( $page ) );
		$this->assertCount( 1, $result['findings'] );
		$created = Pipeline::run(
			self::op(
				array(
					'post'      => 0,
					'post_type' => 'post',
				),
				self::set( array( 'post_title' => 'Y' ) + $new )
			)
		);
		$this->assertSame( 'draft', $created['mode'], 'a warning does not stop a draft' );
		$this->assertCount( 1, $created['findings'], 'a new post brings every finding' );
	}

	/**
	 * 6. Findings the post had before do not stop a write: the same check, block and message count as the same
	 * finding, once per finding before. A changed message or one more of the same counts as new.
	 */
	public function test_checks_count_only_new_findings(): void {
		$page    = $this->page( '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->' );
		$finding = array(
			'block'   => 'p:0',
			'message' => 'Heading level h3 follows h1 and skips a level.',
			'fix'     => 'Use h2.',
		);
		self::add_check( array( $finding ) );
		$result = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'Fixed elsewhere' ) ), 'corrections' ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( array( 'live', array() ), array( $result['mode'], $result['findings'] ), 'the finding was there before' );
		$this->assertSame( 'Fixed elsewhere', get_the_title( $page ) );

		leitvia_studio()->reset_for_tests();
		$worse = array( 'message' => 'Heading level h4 follows h1 and skips a level.' ) + $finding;
		self::add_check( static fn( string $content ): array => str_contains( $content, 'Worse' ) ? array( $worse ) : array( $finding ) );
		$data = $this->assert_rejected( 'leitvia_check_failed', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => '<!-- wp:paragraph --><p>Worse</p><!-- /wp:paragraph -->' ) ), 'corrections' ) ) );
		$this->assertSame( array( 'Heading level h4 follows h1 and skips a level.' ), array_column( $data['findings'], 'message' ) );

		leitvia_studio()->reset_for_tests();
		self::add_check( static fn( string $content ): array => str_contains( $content, 'Twice' ) ? array( $finding, $finding ) : array( $finding ) );
		$data = $this->assert_rejected( 'leitvia_check_failed', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_content' => '<!-- wp:paragraph --><p>Twice</p><!-- /wp:paragraph -->' ) ), 'corrections' ) ) );
		$this->assertCount( 1, $data['findings'], 'one more of the same finding is new' );
		$this->assertStringContainsString( 'Old', get_post( $page )->post_content );
	}

	/**
	 * 7. The snapshot holds the previous state and the base hash; the audit entry names object and package; the
	 * action gets operation and result.
	 */
	public function test_snapshot_audit_and_action(): void {
		$page   = $this->page();
		$before = Pipeline::state( $page );
		$op     = self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'Corrected' ) ), 'corrections' );
		$seen   = array();
		add_action(
			'leitvia_studio_gateway_executed',
			static function ( Operation $operation, array $result ) use ( &$seen ): void {
				$seen[] = array( $operation, $result );
			},
			10,
			2
		);
		$result   = Pipeline::run( $op );
		$snapshot = Store::get_snapshot( $result['snapshot'] );
		$this->assertSame( $op->base_hash, $snapshot['base_hash'] );
		$this->assertEquals( $before, $snapshot['state'] );
		$this->assertSame( array( 'page', (string) $page ), array( $snapshot['object_type'], $snapshot['object_id'] ) );
		$this->assertSame( array( array( $op, $result ) ), $seen );

		$package = Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'Redesigned' ) ), 'design' ) );
		$rows    = $this->audit();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'leitvia/test-write', 'ok', 'page', (string) $page, '0' ), array( $rows[0]['ability'], $rows[0]['result'], $rows[0]['object_type'], $rows[0]['object_id'], $rows[0]['package_id'] ) );
		$this->assertSame( (string) $package['package'], $rows[1]['package_id'] );
		$this->assertStringNotContainsString( 'Redesigned', implode( ' ', $rows[1] ), 'no content in the audit log' );
		$this->assertCount( 2, $seen );
	}

	/**
	 * 8. A failing write leaves nothing half written: the previous state comes back, the audit says failed.
	 *
	 * @throws RuntimeException From the hooks it adds, caught by the pipeline.
	 */
	public function test_failed_write(): void {
		$page  = $this->page();
		$title = get_the_title( $page );
		$throw = static function (): void {
			static $thrown = false;
			if ( ! $thrown ) {
				$thrown = true;
				throw new RuntimeException( 'disk full' );
			}
		};
		add_action( 'save_post', $throw );
		$data = $this->assert_rejected( 'leitvia_execution_failed', Pipeline::run( self::op( array( 'post' => $page ), self::set( array( 'post_title' => 'Half' ) ), 'corrections' ) ) );
		$this->assertSame( 500, $data['status'] );
		$this->assertSame( $title, get_the_title( $page ) );
		$this->assertSame( 'failed', $this->audit()[0]['result'] );
		remove_action( 'save_post', $throw );

		$count = (int) wp_count_posts( 'post' )->draft;
		add_action( 'save_post_post', static fn() => throw new RuntimeException( 'disk full' ) );
		$this->assert_rejected(
			'leitvia_execution_failed',
			Pipeline::run(
				self::op(
					array(
						'post'      => 0,
						'post_type' => 'post',
					),
					self::set( array( 'post_title' => 'Half new' ) )
				)
			)
		);
		wp_cache_flush();
		$this->assertSame( $count, (int) wp_count_posts( 'post' )->draft, 'the half created post is gone' );
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	/**
	 * Abilities::write() registers a write ability with base_hash and package in the schema, readonly false, and runs
	 * through the pipeline.
	 */
	public function test_write_helper(): void {
		wp_get_abilities();
		add_action(
			'leitvia_studio_init',
			static function (): void {
				Abilities::write(
					'leitvia/test-write',
					'Test write',
					'Changes a title.',
					'maintain',
					array(
						'destructive' => false,
						'idempotent'  => true,
					),
					array(
						'type'       => 'object',
						'properties' => array(
							'post'  => array( 'type' => 'integer' ),
							'title' => array( 'type' => 'string' ),
						),
						'required'   => array( 'post' ),
					),
					static fn( array $input ): array => array(
						'area'   => 'design',
						'target' => array( 'post' => (int) $input['post'] ),
						'change' => static fn( array $state ): array => array( 'post_title' => (string) ( $input['title'] ?? '' ) ) + $state,
					)
				);
			}
		);
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$ability = wp_get_ability( 'leitvia/test-write' );
		$this->assertSame( 'maintain', leitvia_studio()->registry( 'abilities' )->get( 'leitvia/test-write' )['group'] );
		$this->assertSame( array( 'post', 'base_hash' ), $ability->get_input_schema()['required'] );
		$this->assertArrayHasKey( 'package', $ability->get_input_schema()['properties'] );
		$this->assertFalse( $ability->get_meta_item( 'annotations' )['readonly'] );
		$this->assertFalse( $ability->get_meta_item( 'public' ) );

		$page  = $this->page();
		$input = array(
			'post'      => $page,
			'title'     => 'Via ability',
			'base_hash' => Hashes::document( $page ),
			'package'   => 'task-1',
		);
		$first = $ability->execute( $input );
		$again = $ability->execute( array( 'title' => 'Via ability again' ) + $input );
		$this->assertSame( 'package', $first['mode'] );
		$this->assertSame( $first['package'], $again['package'], 'same package key, same package' );
		$this->assertCount( 2, Packages::operations( $first['package'] ) );
		$this->assertWPError( $ability->execute( array( 'post' => $page ) ), 'base_hash is required' );
	}
}
