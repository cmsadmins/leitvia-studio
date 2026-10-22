<?php
/**
 * Gateway verification of the front end after a live write.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Store;
use LeitviaStudio\Gateway\Verify;

/**
 * Scheduling, the request, PHP messages, the HTML checks and what follows a result. HTTP is filtered.
 */
class Test_Gateway_Verify extends WP_UnitTestCase {

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Requests seen by the HTTP filter: url and args.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $requests = array();

	/**
	 * A page that passes.
	 */
	private const GOOD = '<!doctype html><html><body><header>Site</header><main><h1>Title</h1><p>Text</p></main><footer>Foot</footer></body></html>';

	/**
	 * Leitvia theme, an agent at "autonomous" in the content area, no cron events.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_clean_theme_json_cache();
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		self::settings( 'autonomous' );
		wp_set_current_user( $this->agent );
		_set_cron_array( array() );
	}

	/**
	 * Clears the token arguments.
	 */
	public function tear_down(): void {
		unset( $_GET[ Verify::ARG ], $_SERVER['HTTP_X_LEITVIA_VERIFY'] );
		_set_cron_array( array() );
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * One level for the content area, every content kind unlocked.
	 *
	 * @param string $level  Level.
	 * @param bool   $verify Setting verify.
	 */
	private static function settings( string $level, bool $verify = true ): void {
		update_option(
			Gateway::OPTION,
			array(
				'verify'    => $verify,
				'overrides' => array(
					'areas' => array( 'content' => $level ),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
	}

	/**
	 * Answers every request with the body and status, before the loopback filter of the dev mu-plugin; the checked request reports these PHP messages, null for none.
	 *
	 * @param string                          $body     Body.
	 * @param int                             $status   Status.
	 * @param list<array<string, mixed>>|null $messages Messages the request reports.
	 * @param string                          $before   Body of a request for the state before the write (200).
	 */
	private function respond( string $body, int $status = 200, ?array $messages = array(), string $before = self::GOOD ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $status, $messages, $before ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( is_array( get_transient( 'leitvia_verify_' . $args['headers'][ Verify::HEADER ] ) ) ) {
					$body   = $before;
					$status = 200;
				}
				if ( null !== $messages ) {
					set_transient( 'leitvia_verify_' . $args['headers'][ Verify::HEADER ], $messages, 60 );
				}
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			1,
			3
		);
	}

	/**
	 * A published post and a live change of its paragraph by the agent.
	 *
	 * @param string $text New paragraph text.
	 * @return array{0: int, 1: array<string, mixed>} Post ID and the job planned for the check.
	 */
	private function live_write( string $text = 'New' ): array {
		$post   = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Title',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			)
		);
		$result = Pipeline::run(
			new Operation(
				'leitvia/update-content',
				'content',
				array( 'post' => $post ),
				Hashes::document( $post ),
				static fn( array $state ): array => array( 'post_content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->' ) + $state
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'live', $result['mode'] );
		return array( $post, $this->job() );
	}

	/**
	 * Argument of the only planned check, null for none.
	 *
	 * @return array<string, mixed>|null
	 */
	private function job(): ?array {
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Verify::CRON ] ?? array() ) as $event ) {
				return $event['args'][0];
			}
		}
		return null;
	}

	/**
	 * Newest audit row of leitvia/verify.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function audit(): ?array {
		global $wpdb;
		$table = Store::table( 'audit' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ability = %s ORDER BY id DESC LIMIT 1", 'leitvia/verify' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A live write plans the check for now and requests nothing; packages and the setting verify off plan nothing.
	 */
	public function test_live_write_schedules_check_without_request(): void {
		$this->respond( self::GOOD );
		list( $post, $job ) = $this->live_write();
		$this->assertSame( array(), $this->requests );
		$this->assertNotNull( $job );
		$this->assertSame( $post, $job['post'] );
		$this->assertSame( $this->agent, $job['user'] );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Verify::CRON, array( $job ) ) );

		_set_cron_array( array() );
		self::settings( 'suggest' );
		$this->live_write_expect_package();
		$this->assertNull( $this->job() );

		self::settings( 'autonomous', false );
		$this->live_write();
		$this->assertNull( $this->job() );
	}

	/**
	 * A change at "suggest" goes into a package.
	 */
	private function live_write_expect_package(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result = Pipeline::run( new Operation( 'leitvia/update-content', 'content', array( 'post' => $post ), Hashes::document( $post ), static fn( array $state ): array => array( 'post_title' => 'Other' ) + $state ) );
		$this->assertSame( 'package', $result['mode'] );
	}

	/**
	 * The request bypasses caches with token argument and header, waits 10 seconds at most, follows no redirect.
	 */
	public function test_request_carries_token_and_limits(): void {
		$this->respond( self::GOOD );
		$report = Verify::check( home_url( '/sample/' ) );
		$this->assertSame( 'ok', $report['status'], wp_json_encode( $report ) );
		$this->assertCount( 1, $this->requests, wp_json_encode( array_column( $this->requests, 'url' ) ) );
		$request = $this->requests[0];
		parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
		$this->assertNotEmpty( $query[ Verify::ARG ] );
		$this->assertSame( $query[ Verify::ARG ], $request['args']['headers'][ Verify::HEADER ] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( home_url( '/sample/' ), $report['url'] );
	}

	/**
	 * Another host ends the check without a request.
	 */
	public function test_foreign_host_is_not_requested(): void {
		$this->respond( self::GOOD );
		$report = Verify::check( 'https://elsewhere.example/page/' );
		$this->assertSame( 'unverifiable', $report['status'] );
		$this->assertSame( 'leitvia_verify_foreign_host', $report['code'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * PHP messages reported by the checked request fail the check; a response without a report is unverifiable.
	 */
	public function test_php_messages_fail_and_missing_report_is_unverifiable(): void {
		$this->respond(
			self::GOOD,
			200,
			array(
				array(
					'type'    => E_WARNING,
					'message' => 'Undefined variable $x',
					'file'    => 'leitvia/functions.php',
					'line'    => 3,
				),
			)
		);
		$report = Verify::check( home_url( '/' ) );
		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'php_message', $report['findings'][0]['code'] );
		$this->assertSame( 'leitvia/functions.php', $report['findings'][0]['file'] );

		remove_all_filters( 'pre_http_request' );
		$this->respond( self::GOOD, 200, null );
		$this->assertSame( 'leitvia_verify_no_report', Verify::check( home_url( '/' ) )['code'] );
	}

	/**
	 * The checked request records messages from plugin files only when header and argument carry a pending token,
	 * skips deprecations and core files, and stores them at shutdown.
	 */
	public function test_listen_records_messages_of_plugins(): void {
		$_GET[ Verify::ARG ]              = 'abc123';
		$_SERVER['HTTP_X_LEITVIA_VERIFY'] = 'abc123';
		Verify::listen();
		$handler = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Reads the handler Verify set.
		restore_error_handler();
		$this->assertNotInstanceOf( Closure::class, $handler, 'No pending token, no handler.' );

		set_transient( 'leitvia_verify_abc123', 'pending', 60 );
		Verify::listen();
		$handler = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Reads the handler Verify set.
		restore_error_handler();
		restore_error_handler();
		$this->assertInstanceOf( Closure::class, $handler );
		$plugin = WP_PLUGIN_DIR . '/leitvia-studio/includes/example.php';
		$this->assertFalse( $handler( E_USER_WARNING, 'Plugin warning', $plugin, 7 ) );
		$handler( E_USER_DEPRECATED, 'Old function', $plugin, 8 );
		$handler( E_USER_NOTICE, 'Core notice', ABSPATH . WPINC . '/functions.php', 9 );
		Verify::store( 'abc123' );
		$messages = get_transient( 'leitvia_verify_abc123' );
		$this->assertCount( 1, $messages );
		$this->assertSame( 'leitvia-studio/includes/example.php', $messages[0]['file'] );
		$this->assertSame( 'Plugin warning', $messages[0]['message'] );
	}

	/**
	 * HTML findings: h1 count, landmarks, raw block comments, visible JSON; JSON in code is fine.
	 */
	public function test_html_findings(): void {
		$this->assertSame( array(), Verify::html( self::GOOD ) );
		$this->assertSame( array(), Verify::html( '<div role="banner"></div><div role="main"><h1>T</h1><pre><code>{"a": 1}</code></pre></div><div role="contentinfo"></div>' ) );
		$codes = array_column( Verify::html( '<main><p>{"blockName": "core/paragraph"}</p><!-- wp:paragraph --></main>' ), 'code' );
		$this->assertSame( array( 'visible_json', 'block_comment', 'h1_count', 'landmark_missing', 'landmark_missing' ), $codes );
		$this->assertSame( 2, Verify::html( '<header></header><main><h1>A</h1><h1>B</h1></main><footer></footer>' )[0]['count'] );
	}

	/**
	 * A failed check of an autonomous write rolls it back through a package, notes the result and logs failed.
	 */
	public function test_failed_autonomous_write_is_rolled_back(): void {
		list( $post, $job ) = $this->live_write();
		$this->respond( '<main><p>No h1</p></main>', 500 );
		wp_set_current_user( 0 );
		$report = Verify::verify( $job );
		$this->assertSame( 0, get_current_user_id(), 'The cron user comes back.' );
		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'rolled_back', $report['action'] );
		$this->assertStringContainsString( '<p>Old</p>', get_post_field( 'post_content', $post, 'raw' ) );
		$audit = self::audit();
		$this->assertSame( 'failed', $audit['result'] );
		$this->assertSame( $this->agent, (int) $audit['user_id'] );
		$this->assertSame( 'rolled_back', json_decode( $audit['details'], true )['action'] );
		$this->assertContains( 'http_status', json_decode( $audit['details'], true )['findings'] );
		$package = (int) $audit['package_id'];
		$this->assertSame( 'rolled_back', Packages::state( $package ) );
		$this->assertSame( 'failed', get_post_meta( $package, Verify::NOTE_META, true )['status'] );
	}

	/**
	 * A person's change after the write blocks the rollback (conflict check of the package); the inbox gets the note.
	 */
	public function test_rollback_conflict_goes_to_inbox(): void {
		list( $post, $job ) = $this->live_write();
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Changed by a person',
			)
		);
		$this->respond( '<main></main>' );
		$report = Verify::verify( $job );
		$this->assertSame( 'inbox', $report['action'] );
		$this->assertSame( 'leitvia_rollback_conflict', $report['rollback_error'] );
		$this->assertSame( 'Changed by a person', get_the_title( $post ) );
		$this->assertSame( 'applied', Packages::state( (int) self::audit()['package_id'] ) );
	}

	/**
	 * A failed check of a package a person applied only notes the result on that package.
	 */
	public function test_failed_package_write_is_noted(): void {
		list( $post, $job ) = $this->live_write();
		$package            = Packages::add( 'key', array( 'ability' => 'leitvia/update-content' ) );
		$job['package']     = $package;
		$this->respond( '<main></main>' );
		$report = Verify::verify( $job );
		$this->assertSame( 'inbox', $report['action'] );
		$this->assertStringContainsString( '<p>New</p>', get_post_field( 'post_content', $post, 'raw' ) );
		$this->assertSame( 'failed', get_post_meta( $package, Verify::NOTE_META, true )['status'] );
	}

	/**
	 * A blocked loopback or timeout is unverifiable: no rollback, a note for the inbox, logged as unverifiable.
	 */
	public function test_unverifiable_never_rolls_back(): void {
		list( $post, $job ) = $this->live_write();
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 1 );
		$report = Verify::verify( $job );
		$this->assertSame( 'unverifiable', $report['status'] );
		$this->assertSame( 'inbox', $report['action'] );
		$this->assertStringContainsString( '<p>New</p>', get_post_field( 'post_content', $post, 'raw' ) );
		$audit = self::audit();
		$this->assertSame( 'unverifiable', $audit['result'] );
		$this->assertSame( 'applied', Packages::state( (int) $audit['package_id'] ) );
	}

	/**
	 * Findings the page had before the write are known: a second request renders the post in its snapshot state, and
	 * when nothing new is left the write stays and the report lists the known findings.
	 */
	public function test_findings_from_before_the_write_do_not_fail(): void {
		list( $post, $job ) = $this->live_write();
		$broken             = '<main><p>No h1, no header</p></main>';
		$this->respond( $broken, 200, array(), $broken );
		$report = Verify::verify( $job );
		$this->assertSame( 'ok', $report['status'], wp_json_encode( $report ) );
		$this->assertSame( 'none', $report['action'] );
		$this->assertSame( array( 'h1_count', 'landmark_missing', 'landmark_missing' ), array_column( $report['known'], 'code' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( '<p>New</p>', get_post_field( 'post_content', $post, 'raw' ) );
		$this->assertSame( 'ok', self::audit()['result'] );

		// Only what the write added fails: the page had its landmarks before, now it lost the footer.
		_set_cron_array( array() );
		list( , $job ) = $this->live_write();
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();
		$this->respond( '<header></header><main><p>x</p></main>', 200, array(), '<header></header><main><p>x</p></main><footer></footer>' );
		$report = Verify::verify( $job );
		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( array( 'landmark_missing' ), array_column( $report['findings'], 'code' ) );
		$this->assertSame( array( 'h1_count' ), array_column( $report['known'], 'code' ) );
		$this->assertSame( 'rolled_back', $report['action'] );
	}

	/**
	 * The request for the state before renders the posts of its token with the snapshot fields, in memory only.
	 */
	public function test_listen_renders_snapshot_state(): void {
		$post = self::factory()->post->create( array( 'post_content' => 'Now' ) );
		set_transient( 'leitvia_verify_def456', array( 'pending' => array( $post => array( 'post_content' => 'Before' ) ) ), 60 );
		$_GET[ Verify::ARG ]              = 'def456';
		$_SERVER['HTTP_X_LEITVIA_VERIFY'] = 'def456';
		Verify::listen();
		restore_error_handler();
		$posts = apply_filters( 'the_posts', array( get_post( $post ) ), new WP_Query() );
		$this->assertSame( 'Before', $posts[0]->post_content );
		$this->assertSame( 'Now', get_post_field( 'post_content', $post, 'raw' ) );
	}

	/**
	 * Contrast pairs that already failed before the write are known; pairs the write breaks fail it.
	 */
	public function test_contrast_failing_before_the_write_is_known(): void {
		$gray = static function ( WP_Theme_JSON_Data $data ): WP_Theme_JSON_Data {
			$palette = array();
			foreach ( leitvia_studio_contract()->color_roles() as $slug ) {
				$palette[] = array(
					'slug'  => $slug,
					'name'  => $slug,
					'color' => '#777777',
				);
			}
			return $data->update_with(
				array(
					'version'  => 3,
					'settings' => array( 'color' => array( 'palette' => $palette ) ),
				)
			);
		};
		add_filter( 'wp_theme_json_data_theme', $gray );
		wp_clean_theme_json_cache();
		list( , $job ) = $this->live_write();
		$this->assertNotEmpty( $job['known'] );
		$this->respond( self::GOOD );
		$report = Verify::verify( $job );
		$this->assertSame( 'ok', $report['status'], wp_json_encode( $report ) );
		$this->assertContains( 'contrast', array_column( $report['known'], 'code' ) );

		remove_filter( 'wp_theme_json_data_theme', $gray );
		wp_clean_theme_json_cache();
		_set_cron_array( array() );
		list( , $job ) = $this->live_write();
		$this->assertSame( array(), $job['known'] );
		add_filter( 'wp_theme_json_data_theme', $gray );
		wp_clean_theme_json_cache();
		$report = Verify::verify( $job );
		remove_filter( 'wp_theme_json_data_theme', $gray );
		$this->assertSame( 'failed', $report['status'] );
		$this->assertContains( 'contrast', array_column( $report['findings'], 'code' ) );
	}

	/**
	 * A passing check logs ok and creates no package.
	 */
	public function test_ok_is_logged_without_package(): void {
		list( , $job ) = $this->live_write();
		$this->respond( self::GOOD );
		$this->assertSame( 'ok', Verify::verify( $job )['status'] );
		$audit = self::audit();
		$this->assertSame( 'ok', $audit['result'] );
		$this->assertSame( 0, (int) $audit['package_id'] );
	}
}
