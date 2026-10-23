<?php
/**
 * Theme contract tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Contract\Contract;

/**
 * Loading, validation, child theme order and the REST route.
 *
 * @group multisite
 */
class Test_Contract extends WP_UnitTestCase {

	/**
	 * Fixture directory.
	 */
	private const FIXTURES = __DIR__ . '/../fixtures/contract/';

	/**
	 * Activates the Leitvia theme.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
	}

	/**
	 * Drops the cached contract so filters of one test do not leak into the next.
	 */
	public function tear_down(): void {
		\LeitviaStudio\Plugin::forget_contract();
		parent::tear_down();
	}

	/**
	 * The Leitvia contract loads and exposes roles and pairs.
	 */
	public function test_leitvia_contract_is_valid(): void {
		$contract = leitvia_studio_contract();
		$raw      = wp_json_file_decode( get_template_directory() . '/leitvia-contract.json', array( 'associative' => true ) );

		$this->assertTrue( $contract->is_valid(), $contract->error() );
		$this->assertSame( $raw['contractVersion'], $contract->version() );
		$this->assertStringStartsWith( '1.', $contract->version() );
		$this->assertSame(
			array( 'surface', 'surface-alt', 'ink', 'ink-muted', 'accent', 'on-accent', 'stroke', 'focus', 'success', 'warning', 'danger' ),
			$contract->color_roles()
		);
		$this->assertCount( 16, $contract->pairs() );
		$ring = wp_list_filter(
			$contract->pairs(),
			array(
				'fg' => 'focus',
				'bg' => 'accent',
			)
		);
		$this->assertSame( 'surface', array_values( $ring )[0]['ring'] ?? null );
		$this->assertNotEmpty( $contract->variations() );
	}

	/**
	 * Pattern lookup by slug.
	 */
	public function test_pattern_lookup(): void {
		$pattern = leitvia_studio_contract()->pattern( 'leitvia/hero-split-image' );
		$this->assertIsArray( $pattern );
		$this->assertSame( 'hero', $pattern['area'] );
		$this->assertNull( leitvia_studio_contract()->pattern( 'leitvia/does-not-exist' ) );
	}

	/**
	 * A newer minor version is accepted.
	 */
	public function test_valid_fixture_with_newer_minor(): void {
		$contract = Contract::from_file( self::FIXTURES . 'valid.json' );
		$this->assertTrue( $contract->is_valid(), $contract->error() );
		$this->assertSame( array( 'surface', 'ink' ), $contract->color_roles() );
	}

	/**
	 * Another major version is rejected.
	 */
	public function test_major_2_is_rejected(): void {
		$contract = Contract::from_file( self::FIXTURES . 'major-2.json' );
		$this->assertTrue( $contract->exists() );
		$this->assertFalse( $contract->is_valid() );
		$this->assertStringContainsStringIgnoringCase( 'unsupported major version 2', $contract->error() );
		$this->assertSame( array(), $contract->data() );
	}

	/**
	 * Broken JSON is invalid and raises no PHP notice (PHPUnit would turn it into an error).
	 */
	public function test_broken_json_is_rejected_quietly(): void {
		$contract = Contract::from_file( self::FIXTURES . 'broken.json' );
		$this->assertTrue( $contract->exists() );
		$this->assertFalse( $contract->is_valid() );
		$this->assertNotSame( '', $contract->error() );
		$this->assertSame( array(), $contract->pairs() );
	}

	/**
	 * A theme without contract file.
	 */
	public function test_missing_contract(): void {
		add_filter( 'leitvia_studio_contract_path', static fn() => array( self::FIXTURES . 'missing.json' ) );
		$contract = Contract::for_active_theme();
		$this->assertFalse( $contract->exists() );
		$this->assertFalse( $contract->is_valid() );
		$this->assertSame( array(), $contract->color_roles() );
	}

	/**
	 * The child theme directory comes before the parent theme directory.
	 */
	public function test_child_theme_is_searched_first(): void {
		$child = '/tmp/leitvia-child';
		add_filter( 'stylesheet_directory', static fn() => $child );
		$seen = array();
		add_filter(
			'leitvia_studio_contract_path',
			static function ( array $candidates ) use ( &$seen ): array {
				$seen = $candidates;
				return $candidates;
			}
		);

		$contract = Contract::for_active_theme();

		$this->assertSame( array( $child . '/leitvia-contract.json', get_template_directory() . '/leitvia-contract.json' ), $seen );
		$this->assertTrue( $contract->is_valid(), 'Falls back to the parent theme.' );
	}

	/**
	 * Switching the theme drops the cached contract.
	 */
	public function test_switch_theme_drops_cache(): void {
		$this->assertTrue( leitvia_studio_contract()->is_valid() );
		add_filter( 'leitvia_studio_contract_path', '__return_empty_array' );
		$this->assertTrue( leitvia_studio_contract()->is_valid(), 'Cached within the request.' );
		switch_theme( 'leitvia' );
		$this->assertFalse( leitvia_studio_contract()->exists() );
	}

	/**
	 * Version strings must be complete semver.
	 */
	public function test_version_format(): void {
		$base = wp_json_file_decode( self::FIXTURES . 'valid.json', array( 'associative' => true ) );
		foreach ( array(
			'1.0.0'         => true,
			'1.2.3-beta.1'  => true,
			'1.2.3+build.5' => true,
			'1.2'           => false,
			'1.2.3x'        => false,
			"1.2.3\n"       => false,
			'v1.2.3'        => false,
		) as $version => $valid ) {
			$this->assertSame( $valid, $this->contract_from( array( 'contractVersion' => (string) $version ) + $base )->is_valid(), (string) $version );
		}
	}

	/**
	 * Roles, pairs and patterns need the expected shape.
	 */
	public function test_structure_is_checked(): void {
		$base  = wp_json_file_decode( self::FIXTURES . 'valid.json', array( 'associative' => true ) );
		$cases = array(
			'roles.color' => array( 'roles' => array( 'color' => array( array( 'name' => 'surface' ) ) ) ),
			'pairs'       => array( 'pairs' => array( array( 'fg' => 'ink' ) ) ),
			'patterns'    => array( 'patterns' => array( 'a' => array( 'slug' => 'x' ) ) ),
		);
		foreach ( $cases as $field => $override ) {
			$contract = $this->contract_from( $override + $base );
			$this->assertFalse( $contract->is_valid(), $field );
			$this->assertStringContainsString( $field, $contract->error() );
		}
	}

	/**
	 * Files above 2 MB are not parsed.
	 */
	public function test_size_limit(): void {
		$path = wp_tempnam( 'leitvia-contract' );
		file_put_contents( $path, '{"pad":"' . str_repeat( 'x', 2 * MB_IN_BYTES ) . '"}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$contract = Contract::from_file( $path );
		wp_delete_file( $path );
		$this->assertTrue( $contract->exists() );
		$this->assertFalse( $contract->is_valid() );
		$this->assertStringContainsString( '2 MB', $contract->error() );
	}

	/**
	 * The path filter only accepts local files, no stream wrappers.
	 */
	public function test_stream_wrappers_are_ignored(): void {
		$file = realpath( self::FIXTURES . 'valid.json' );
		add_filter( 'leitvia_studio_contract_path', static fn() => array( 'php://filter/resource=' . $file, 'file://' . $file, 'phar://' . $file . '/x.json' ) );
		$this->assertFalse( Contract::for_active_theme()->exists() );
		$this->assertFalse( Contract::from_file( 'php://filter/resource=' . $file )->exists() );
	}

	/**
	 * A broken child theme contract does not fall back to the parent theme; its error stays visible.
	 */
	public function test_broken_child_does_not_fall_back(): void {
		add_filter( 'leitvia_studio_contract_path', static fn() => array( self::FIXTURES . 'broken.json', get_template_directory() . '/leitvia-contract.json' ) );
		$contract = Contract::for_active_theme();
		$this->assertTrue( $contract->exists() );
		$this->assertFalse( $contract->is_valid() );
		$this->assertNotSame( '', $contract->error() );
	}

	/**
	 * Guests get 401.
	 */
	public function test_rest_guest(): void {
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( '/leitvia-studio/v1/contract' )->get_status() );
	}

	/**
	 * An invalid contract reports its error and no content; a newer minor version passes.
	 */
	public function test_rest_invalid_and_minor(): void {
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'leitvia_studio_contract_path', static fn() => array( self::FIXTURES . 'major-2.json' ) );
		\LeitviaStudio\Plugin::forget_contract();
		$data = rest_do_request( '/leitvia-studio/v1/contract' )->get_data();
		$this->assertTrue( $data['exists'] );
		$this->assertFalse( $data['valid'] );
		$this->assertNull( $data['contract'] );
		$this->assertStringContainsStringIgnoringCase( 'unsupported major version 2', $data['error'] );

		remove_all_filters( 'leitvia_studio_contract_path' );
		add_filter( 'leitvia_studio_contract_path', static fn() => array( self::FIXTURES . 'valid.json' ) );
		\LeitviaStudio\Plugin::forget_contract();
		$data = rest_do_request( '/leitvia-studio/v1/contract' )->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertSame( '1.4.0', $data['version'] );
		$this->assertSame( '', $data['error'] );
	}

	/**
	 * The route publishes a schema.
	 */
	public function test_rest_schema(): void {
		do_action( 'rest_api_init' );
		$properties = rest_do_request( new WP_REST_Request( 'OPTIONS', '/leitvia-studio/v1/contract' ) )->get_data()['schema']['properties'];
		$this->assertSame( array( 'exists', 'valid', 'error', 'version', 'contract', 'active', 'drift', 'blocks' ), array_keys( $properties ) );
	}

	/**
	 * Writes data to a temporary contract file and loads it.
	 *
	 * @param array<string, mixed> $data Contract data.
	 */
	private function contract_from( array $data ): Contract {
		$path = wp_tempnam( 'leitvia-contract' );
		file_put_contents( $path, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$contract = Contract::from_file( $path );
		wp_delete_file( $path );
		return $contract;
	}

	/**
	 * Admins read the route, subscribers get 403.
	 */
	public function test_rest_route(): void {
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/contract' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['valid'] );
		$this->assertSame( leitvia_studio_contract()->version(), $data['version'] );
		$this->assertCount( 16, $data['contract']['pairs'] );
		$this->assertSame( 0, $data['active'] );
		$this->assertFalse( $data['drift'] );
	}

	/**
	 * Each site reads the contract of its own theme.
	 *
	 * @group ms-required
	 */
	public function test_switch_blog_drops_cache(): void {
		$this->assertTrue( leitvia_studio_contract()->is_valid() );
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		update_option( 'stylesheet', 'leitvia-missing' );
		update_option( 'template', 'leitvia-missing' );
		$this->assertFalse( leitvia_studio_contract()->exists() );
		restore_current_blog();
		$this->assertTrue( leitvia_studio_contract()->is_valid() );
	}
}
