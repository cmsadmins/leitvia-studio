<?php
/**
 * Add-on registration, Studio registries, abilities hand-over and the extensions route.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Extensions;
use LeitviaStudio\Extension\Studio;

/**
 * Extension API tests.
 */
class Test_Extensions extends WP_UnitTestCase {

	/**
	 * Abilities this test registered with core.
	 *
	 * @var list<string>
	 */
	private array $abilities = array();

	/**
	 * Starts every test with the core abilities registry built and Studio empty.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Removes abilities and leaves Studio as it was after boot.
	 */
	public function tear_down(): void {
		foreach ( $this->abilities as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Runs leitvia_studio_init again with $callback hooked in.
	 *
	 * @param callable $callback Receives the Studio instance and registers add-ons and entries.
	 */
	private function init_with( callable $callback ): void {
		add_action( 'leitvia_studio_init', $callback );
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Registration arguments with overrides.
	 *
	 * @param array<string, mixed> $args Overrides.
	 * @return array<string, mixed>
	 */
	private function addon( array $args = array() ): array {
		return $args + array(
			'label'           => 'Test add-on',
			'version'         => '1.2.3',
			'requires_studio' => '0.1.0',
			'requires_api'    => 1,
		);
	}

	/**
	 * Version and API rules decide compatibility; incompatible add-ons stay listed with a reason.
	 */
	public function test_version_rules(): void {
		$results = array();
		$this->init_with(
			function ( Studio $studio ) use ( &$results ): void {
				$results['ok']  = $studio->extensions()->register( 'ok-addon', $this->addon() );
				$results['new'] = $studio->extensions()->register( 'new-addon', $this->addon( array( 'requires_studio' => '9.0.0' ) ) );
				$results['api'] = $studio->extensions()->register( 'api-addon', $this->addon( array( 'requires_api' => 2 ) ) );
				$results['old'] = $studio->extensions()->register( 'old-addon', $this->addon( array( 'requires_api' => LEITVIA_STUDIO_API_MIN - 1 ) ) );
			}
		);
		$this->assertSame(
			array(
				'ok'  => true,
				'new' => false,
				'api' => false,
				'old' => false,
			),
			$results
		);

		$all = leitvia_studio()->extensions()->all();
		$this->assertSame( array( 'leitvia-studio', 'ok-addon', 'new-addon', 'api-addon', 'old-addon' ), array_keys( $all ) );
		$this->assertSame( '', $all['ok-addon']['reason'] );
		$this->assertSame( 'requires_studio', $all['new-addon']['reason'] );
		$this->assertSame( 'requires_api', $all['api-addon']['reason'] );
		$this->assertSame( 'requires_api', $all['old-addon']['reason'] );
		foreach ( array( 'slug', 'label', 'version', 'compatible', 'reason' ) as $key ) {
			$this->assertArrayHasKey( $key, $all['new-addon'] );
		}
		$this->assertFalse( $all['new-addon']['compatible'] );
	}

	/**
	 * Registries accept entries only from compatible, registered owners.
	 */
	public function test_registries_accept_only_compatible_owners(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$item = array(
			'label'    => 'A',
			'scope'    => 'token_set',
			'callback' => '__return_empty_array',
		);
		$this->init_with(
			function ( Studio $studio ) use ( $item ): void {
				$studio->extensions()->register( 'old-addon', $this->addon( array( 'requires_api' => 2 ) ) );
				$studio->registry( 'checks' )->register( 'old-addon/a', $item, 'old-addon' );
				$studio->registry( 'checks' )->register( 'stranger/a', $item, 'stranger' );
			}
		);
		$this->assertNull( leitvia_studio()->registry( 'checks' )->get( 'old-addon/a' ) );
		$this->assertNull( leitvia_studio()->registry( 'checks' )->get( 'stranger/a' ) );
	}

	/**
	 * A duplicate slug is rejected.
	 */
	public function test_rejects_duplicate_slug(): void {
		$this->setExpectedIncorrectUsage( Extensions::class . '::register' );
		$second = null;
		$this->init_with(
			function ( Studio $studio ) use ( &$second ): void {
				$studio->extensions()->register( 'dup-addon', $this->addon() );
				$second = $studio->extensions()->register( 'dup-addon', $this->addon() );
			}
		);
		$this->assertFalse( $second );
	}

	/**
	 * The slugs of Studio cannot be taken by an add-on.
	 */
	public function test_rejects_reserved_slugs(): void {
		$this->setExpectedIncorrectUsage( Extensions::class . '::register' );
		$results = array();
		$this->init_with(
			function ( Studio $studio ) use ( &$results ): void {
				$results[] = $studio->extensions()->register( 'leitvia', $this->addon() );
				$results[] = $studio->extensions()->register( 'leitvia-studio', $this->addon() );
			}
		);
		$this->assertSame( array( false, false ), $results );
		$this->assertSame( array( 'leitvia-studio' ), array_keys( leitvia_studio()->extensions()->all() ) );
		$this->assertSame( 'Leitvia Studio', leitvia_studio()->extensions()->all()['leitvia-studio']['label'] );
	}

	/**
	 * Registering outside leitvia_studio_init is wrong.
	 */
	public function test_register_outside_init_is_doing_it_wrong(): void {
		$this->setExpectedIncorrectUsage( Extensions::class . '::register' );
		$this->assertFalse( leitvia_studio()->extensions()->register( 'late-addon', $this->addon() ) );
		$this->assertArrayNotHasKey( 'late-addon', leitvia_studio()->extensions()->all() );
	}

	/**
	 * An unknown registry type throws.
	 */
	public function test_unknown_registry_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		leitvia_studio()->registry( 'widgets' );
	}

	/**
	 * The example add-on docks on without changes to Studio.
	 */
	public function test_example_addon_docks_on(): void {
		require_once dirname( __DIR__ ) . '/fixtures/addon/leitvia-studio-example-addon.php';
		leitvia_studio()->reset_for_tests();

		$studio = leitvia_studio();
		$this->assertTrue( $studio->extensions()->is_compatible( 'leitvia-studio-example-addon' ) );
		$check = $studio->registry( 'checks' )->get( 'leitvia-studio-example-addon/always-ok' );
		$this->assertSame( 'leitvia-studio-example-addon', $check['owner'] );
		$this->assertSame( array(), call_user_func( $check['callback'] ) );
		$this->assertSame( 'leitvia-studio-example-addon-notes', $studio->registry( 'lab_panels' )->get( 'leitvia-studio-example-addon/notes' )['script'] );
	}

	/**
	 * An ability with full annotations reaches core without being made public; one without annotations does not.
	 */
	public function test_abilities_hand_over(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$this->abilities = array( 'leitvia/site-tokens', 'leitvia/no-annotations' );
		$args            = array(
			'label'               => 'Site tokens',
			'description'         => 'Reads the design tokens of the site.',
			'execute_callback'    => '__return_empty_array',
			'permission_callback' => '__return_true',
			'output_schema'       => array( 'type' => 'array' ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
		$this->init_with(
			static function ( Studio $studio ) use ( $args ): void {
				$studio->registry( 'abilities' )->register(
					'leitvia/site-tokens',
					array(
						'args'  => $args,
						'group' => 'read',
					),
					'leitvia-studio'
				);
				$args['meta'] = array();
				$studio->registry( 'abilities' )->register(
					'leitvia/no-annotations',
					array(
						'args'  => $args,
						'group' => 'read',
					),
					'leitvia-studio'
				);
			}
		);
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'leitvia/site-tokens' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'leitvia', $ability->get_category() );
		$this->assertFalse( $ability->get_meta_item( 'public' ) );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertNull( $ability->get_meta()['mcp']['public'] ?? null );
		$this->assertArrayNotHasKey( 'leitvia/no-annotations', wp_get_abilities() );
		$this->assertNull( leitvia_studio()->registry( 'abilities' )->get( 'leitvia/no-annotations' ), 'rejected on register()' );
	}

	/**
	 * An ability group outside read, setup, build and maintain is rejected.
	 */
	public function test_rejects_unknown_ability_group(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$this->init_with(
			static function ( Studio $studio ): void {
				$studio->registry( 'abilities' )->register(
					'leitvia/grouped',
					array(
						'args'  => array(
							'permission_callback' => '__return_true',
							'meta'                => array(
								'annotations' => array(
									'readonly'    => true,
									'destructive' => false,
									'idempotent'  => true,
								),
							),
						),
						'group' => 'admin',
					),
					'leitvia-studio'
				);
			}
		);
		$this->assertNull( leitvia_studio()->registry( 'abilities' )->get( 'leitvia/grouped' ) );
	}

	/**
	 * Entries of the blocks registry are registered with core.
	 */
	public function test_register_blocks(): void {
		$this->init_with(
			static function ( Studio $studio ): void {
				$studio->registry( 'blocks' )->register( 'leitvia/test-block', array( 'path' => dirname( __DIR__ ) . '/fixtures/block' ), 'leitvia-studio' );
			}
		);
		// Studio's own blocks are in core since init; register_blocks() registers them again.
		foreach ( array_keys( leitvia_studio()->registry( 'blocks' )->all() ) as $name ) {
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_type( $name );
			}
		}
		leitvia_studio()->register_blocks();
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'leitvia/test-block' ) );
		unregister_block_type( 'leitvia/test-block' );
	}

	/**
	 * The route lists add-ons with their reason and registry entries with their owner, for administrators only.
	 */
	public function test_rest_route(): void {
		$this->init_with(
			function ( Studio $studio ): void {
				$studio->extensions()->register( 'new-addon', $this->addon( array( 'requires_studio' => '9.0.0' ) ) );
			}
		);
		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/extensions' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( 'requires_studio', array_column( $data['extensions'], 'reason', 'slug' )['new-addon'] );
		$this->assertContains(
			array(
				'name'  => 'leitvia/contrast',
				'owner' => 'leitvia-studio',
			),
			$data['registries']['checks']
		);
		$this->assertSame( array_keys( Studio::FIELDS ), array_keys( $data['registries'] ) );
	}
}
