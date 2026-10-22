<?php
/**
 * Gateway AI: plan-page without AI, and with AI through the core AI client and a fake connector answered by filters.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Ai;
use LeitviaStudio\Gateway\Gateway;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

require_once __DIR__ . '/stubs/ai-provider.php';

/**
 * Requests never leave the test: pre_http_request answers or fails each one and records it.
 */
class Test_Gateway_Ai extends WP_UnitTestCase {

	/**
	 * Requests seen by pre_http_request: URL and arguments.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Leitvia theme, the abilities, an agent, the fake connector registered once and switched on.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( Test_Leitvia_Ai_Provider::class ) ) {
			$registry->registerProvider( Test_Leitvia_Ai_Provider::class );
			$registry->setProviderRequestAuthentication( Test_Leitvia_Ai_Provider::class, new ApiKeyRequestAuthentication( 'test' ) );
		}
		Test_Leitvia_Ai_Provider::$configured = true;
		$this->requests                       = array();
	}

	/**
	 * Connector off, abilities removed.
	 */
	public function tear_down(): void {
		Test_Leitvia_Ai_Provider::$configured = false;
		self::unregister();
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
	 * Answers every HTTP request with the result of $answer and records it.
	 *
	 * @param callable $answer Receives the request body as array, returns the response or a WP_Error.
	 */
	private function answer( callable $answer ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $answer ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				return $answer( json_decode( (string) ( $args['body'] ?? '' ), true ) );
			},
			10,
			3
		);
	}

	/**
	 * A chat completion of the fake model with this content.
	 *
	 * @param string $content Message content.
	 * @return array<string, mixed>
	 */
	private static function completion( string $content ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode(
				array(
					'id'      => 'test',
					'choices' => array(
						array(
							'index'         => 0,
							'message'       => array(
								'role'    => 'assistant',
								'content' => $content,
							),
							'finish_reason' => 'stop',
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Runs plan-page.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function plan( array $input ) {
		return wp_get_ability( 'leitvia/plan-page' )->execute( $input );
	}

	/**
	 * Without AI the plan is the same for the same input, scores at least 80 for every frame and uses only registered
	 * patterns that are not deprecated; points land on sections. No request is sent (spy on pre_http_request).
	 */
	public function test_plan_without_ai_is_deterministic_and_sends_nothing(): void {
		$this->answer( static fn() => new WP_Error( 'http_request_failed', 'No requests in this test.' ) );
		$points   = array( 'Fast setup', 'Fixed prices', 'Local support', 'Accessible by default', 'Own fonts', 'No tracking' );
		$contract = leitvia_studio_contract()->data();
		$replaced = array();
		foreach ( array_merge( $contract['patterns'], $contract['studioPatterns'] ) as $pattern ) {
			if ( ! empty( $pattern['docs']['replacedBy'] ) ) {
				$replaced[] = $pattern['slug'];
			}
		}
		foreach ( array_column( $contract['pageFrames'], 'type' ) as $frame ) {
			foreach ( array( array(), $points ) as $given ) {
				$input = array(
					'goal'   => 'A page for a small agency',
					'points' => $given,
					'frame'  => $frame,
				);
				$plan  = self::plan( $input );
				$this->assertIsArray( $plan, $frame );
				$this->assertSame( $plan, self::plan( $input ), $frame );
				$this->assertSame( 'rules', $plan['source'] );
				$this->assertSame( $frame, $plan['frame'] );
				$this->assertGreaterThanOrEqual( 80, $plan['score'], $frame . ': ' . wp_json_encode( $plan['issues'] ) );
				$this->assertNotEmpty( $plan['sections'], $frame );
				foreach ( $plan['sections'] as $section ) {
					$this->assertTrue( WP_Block_Patterns_Registry::get_instance()->is_registered( $section['pattern'] ), $section['pattern'] );
					$this->assertNotContains( $section['pattern'], $replaced );
				}
				$this->assertEqualsCanonicalizing( $given, array_merge( ...array_column( $plan['sections'], 'points' ) ), $frame );
			}
		}
		$this->assertSame( 'service', self::plan( array( 'goal' => 'Explain our Service in detail' ) )['frame'] );
		$this->assertSame( 'home', self::plan( array( 'goal' => 'Something else' ) )['frame'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * With ai: true but without AI support in WordPress or without a connector: leitvia_ai_unavailable (424) pointing back to
	 * plan-page with ai: false, retry never, and the plan without AI in the data.
	 */
	public function test_ai_unavailable_without_support_or_connector(): void {
		$this->answer( static fn() => new WP_Error( 'http_request_failed', 'No requests in this test.' ) );
		add_filter( 'wp_supports_ai', '__return_false' );
		$error = self::plan(
			array(
				'goal' => 'Home',
				'ai'   => true,
			)
		);
		remove_filter( 'wp_supports_ai', '__return_false' );
		$this->assertWPError( $error );
		$this->assertSame( 'leitvia_ai_unavailable', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertSame( 424, $data['status'] );
		$this->assertSame( 'leitvia/plan-page', $data['next_ability'] );
		$this->assertSame( 'never', $data['retry'] );
		$this->assertSame( array( 'ai' => false ), $data['hint'] );
		$this->assertSame( 'rules', $data['plan']['source'] );

		Test_Leitvia_Ai_Provider::$configured = false;
		$error                                = self::plan(
			array(
				'goal' => 'Home',
				'ai'   => true,
			)
		);
		$this->assertSame( 'leitvia_ai_unavailable', $error->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A connector approval refused by the AI plugin (a WP_Error from pre_http_request) arrives as prompt_network_error;
	 * the rejection explains where an administrator approves it. The request ran with the 20 second limit.
	 */
	public function test_refused_approval_is_explained(): void {
		$this->answer( static fn() => new WP_Error( 'wpai_connector_not_approved', 'This connector request waits for approval.', array( 'status' => 403 ) ) );
		$error = self::plan(
			array(
				'goal' => 'Home',
				'ai'   => true,
			)
		);
		$this->assertWPError( $error );
		$this->assertSame( 'leitvia_ai_not_approved', $error->get_error_code() );
		$this->assertStringContainsString( 'approves', $error->get_error_message() );
		$this->assertSame( 'prompt_network_error', $error->get_error_data()['ai_error'] );
		$this->assertCount( 1, $this->requests );
		$this->assertStringStartsWith( Test_Leitvia_Ai_Provider::URL, $this->requests[0]['url'] );
		$this->assertEquals( Ai::TIMEOUT, $this->requests[0]['args']['timeout'] );
	}

	/**
	 * A timeout: leitvia_ai_timeout, retry once.
	 */
	public function test_timeout(): void {
		$this->answer( static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' ) );
		$error = self::plan(
			array(
				'goal' => 'Home',
				'ai'   => true,
			)
		);
		$this->assertSame( 'leitvia_ai_timeout', $error->get_error_code() );
		$this->assertSame( 'once', $error->get_error_data()['retry'] );
		$this->assertSame( 504, $error->get_error_data()['status'] );
	}

	/**
	 * The answer is checked against the schema; patterns that do not exist fall out; the result stays a plan and
	 * writes nothing. The prompt holds goal, points and pattern names with metadata, no content of the database.
	 */
	public function test_ai_answer_is_checked_and_prompt_holds_no_site_content(): void {
		$secret = self::factory()->post->create(
			array(
				'post_title'   => 'Secret title',
				'post_content' => 'Secret content of the database',
				'post_status'  => 'publish',
			)
		);
		$prompt = '';
		$this->answer(
			static function ( $body ) use ( &$prompt ) {
				$prompt = (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
				return self::completion(
					(string) wp_json_encode(
						array(
							'sections' => array(
								array(
									'pattern' => 'leitvia/hero-centered',
									'points'  => array(),
								),
								array(
									'pattern' => 'leitvia/does-not-exist',
									'points'  => array( 'Lost' ),
								),
								array(
									'pattern' => 'leitvia/content-text-media',
									'points'  => array( 'Fast setup' ),
								),
								array(
									'pattern' => 'leitvia/cta-contact',
									'points'  => array(),
								),
							),
						)
					)
				);
			}
		);
		$posts = (int) wp_count_posts( 'page' )->publish + (int) wp_count_posts()->publish;
		$plan  = self::plan(
			array(
				'goal'   => 'A service page for web design',
				'points' => array( 'Fast setup' ),
				'ai'     => true,
			)
		);
		$this->assertIsArray( $plan );
		$this->assertSame( 'ai', $plan['source'] );
		$this->assertSame( 'service', $plan['frame'] );
		$this->assertSame( array( 'leitvia/hero-centered', 'leitvia/content-text-media', 'leitvia/cta-contact' ), array_column( $plan['sections'], 'pattern' ) );
		$this->assertSame( array( 'leitvia/does-not-exist' ), $plan['dropped'] );
		$this->assertSame( array( 'Fast setup' ), $plan['sections'][1]['points'] );
		$this->assertIsInt( $plan['score'] );
		$this->assertSame( $posts, (int) wp_count_posts( 'page' )->publish + (int) wp_count_posts()->publish );
		$this->assertStringContainsString( 'A service page for web design', $prompt );
		$this->assertStringContainsString( 'leitvia/hero-centered', $prompt );
		$this->assertStringNotContainsString( 'Secret', $prompt );
		$this->assertStringContainsString( 'json_schema', $prompt );
		wp_delete_post( $secret, true );

		remove_all_filters( 'pre_http_request' );
		$this->answer( static fn() => self::completion( '{"sections": "not a list"}' ) );
		$error = self::plan(
			array(
				'goal' => 'Home',
				'ai'   => true,
			)
		);
		$this->assertSame( 'leitvia_ai_invalid_response', $error->get_error_code() );
		$this->assertSame( 'once', $error->get_error_data()['retry'] );
	}
}
