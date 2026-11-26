<?php
/**
 * MCP phase 3: questions before a tool runs (MRTR), tasks, progress and prompts.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Mcp\Completion;
use LeitviaStudio\Mcp\Context;
use LeitviaStudio\Mcp\Dispatcher;
use LeitviaStudio\Mcp\Input_Required;
use LeitviaStudio\Mcp\Prompts;
use LeitviaStudio\Mcp\Protocol_Error;
use LeitviaStudio\Mcp\Resources;
use LeitviaStudio\Mcp\Stream;
use LeitviaStudio\Mcp\Subscriptions;
use LeitviaStudio\Mcp\Tasks;
use LeitviaStudio\Mcp\Tools;

/**
 * Input required, tasks, progress and prompts of the server leitvia.
 */
class Test_Mcp_Interaction extends WP_UnitTestCase {

	/**
	 * Client capabilities with form elicitation.
	 */
	private const ELICIT = array( 'elicitation' => array( 'form' => array() ) );

	/**
	 * Client capabilities with the tasks extension.
	 */
	private const TASKS = array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => array() ) );

	/**
	 * Abilities handed to core, an agent signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * Studio state of the next test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Protocol state of a 2026-07-28 request.
	 *
	 * @param array<string, mixed> $capabilities Client capabilities.
	 */
	private static function context( array $capabilities = array() ): Context {
		return new Context( '2026-07-28', true, $capabilities );
	}

	/**
	 * Result of tools/call.
	 *
	 * @param string               $tool         Tool name.
	 * @param mixed                $arguments    Arguments.
	 * @param array<string, mixed> $capabilities Client capabilities.
	 * @param array<string, mixed> $extra        Further params: requestState, inputResponses.
	 * @return array<string, mixed>
	 */
	private static function call( string $tool, $arguments, array $capabilities = array(), array $extra = array() ): array {
		return Tools::call(
			array(
				'name'      => $tool,
				'arguments' => $arguments,
			) + $extra,
			self::context( $capabilities )
		);
	}

	/**
	 * The protocol error a call throws, null when it throws none.
	 *
	 * @param callable $call Call.
	 */
	private static function fault( callable $call ): ?Protocol_Error {
		try {
			$call();
			return null;
		} catch ( Protocol_Error $error ) {
			return $error;
		}
	}

	/**
	 * A REST request of a 2026-07-28 client to the server.
	 *
	 * @param string                $method       Method.
	 * @param array<string, mixed>  $params       Params; _meta is merged.
	 * @param array<string, mixed>  $capabilities Client capabilities.
	 * @param array<string, string> $headers      Headers that replace the defaults.
	 */
	private static function request( string $method, array $params = array(), array $capabilities = array(), array $headers = array() ): WP_REST_Request {
		$request         = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$params['_meta'] = (array) ( $params['_meta'] ?? array() ) + array(
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => (object) $capabilities,
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'test',
				'version' => '1.0.0',
			),
		);
		$headers        += array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json, text/event-stream',
			'MCP-Protocol-Version' => '2026-07-28',
			'Mcp-Method'           => $method,
			'Mcp-Name'             => (string) ( $params['name'] ?? $params['uri'] ?? $params['taskId'] ?? '' ),
		);
		foreach ( array_filter( $headers, 'strlen' ) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 7,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);
		return $request;
	}

	/**
	 * JSON-RPC messages of an event stream, in order.
	 *
	 * @param string $stream Text of the stream.
	 * @return list<array<string, mixed>>
	 */
	private static function events( string $stream ): array {
		preg_match_all( '/^data: (.+)$/m', $stream, $matches );
		return array_map( static fn( string $json ): array => json_decode( $json, true ), $matches[1] );
	}

	/**
	 * Studio offers its eleven skills and three agent workflows as prompts; the text is the skill without its
	 * frontmatter, links point to resources, the arguments follow below.
	 */
	public function test_prompts_list_and_get(): void {
		$prompts = array_column( Prompts::list()['prompts'], null, 'name' );
		$this->assertCount( 14, $prompts );
		foreach ( array( 'leitvia-page', 'leitvia-setup', 'leitvia-a11y', 'leitvia-new-site', 'leitvia-maintenance-cycle', 'leitvia-migrate-site' ) as $name ) {
			$this->assertArrayHasKey( $name, $prompts );
			$this->assertNotSame( '', $prompts[ $name ]['title'] );
			$this->assertNotSame( '', $prompts[ $name ]['description'] );
		}
		$this->assertArrayNotHasKey( 'leitvia-new-site-operator', $prompts, 'Steps of a person on the server are no prompt.' );
		$this->assertSame( array( 'goal', 'points' ), array_column( $prompts['leitvia-page']['arguments'], 'name' ) );
		$this->assertSame( array( 'briefing', 'recipe' ), array_column( $prompts['leitvia-setup']['arguments'], 'name' ) );
		$this->assertSame( array( 'task' ), array_column( $prompts['leitvia-a11y']['arguments'], 'name' ) );
		$this->assertArrayNotHasKey( 'complete', $prompts['leitvia-setup']['arguments'][1] );
		$this->assertSame( 'Workflow: maintenance-cycle', $prompts['leitvia-maintenance-cycle']['title'] );

		$page = Prompts::get(
			array(
				'name'      => 'leitvia-page',
				'arguments' => array( 'goal' => 'Visitors book a consultation' ),
			)
		);
		$this->assertSame( 'user', $page['messages'][0]['role'] );
		$text = $page['messages'][0]['content']['text'];
		$this->assertStringStartsNotWith( '---', $text );
		$this->assertStringNotContainsString( "\nname: leitvia-page", $text );
		$this->assertStringContainsString( "## Input\n\n- goal: Visitors book a consultation", $text );
		$this->assertStringContainsString( '](leitvia://skills/leitvia-studio/skills/leitvia-page/references/', $text );
		$this->assertStringContainsString( '](leitvia://skills/leitvia-studio/handbook/README.md', $text );
		$this->assertStringNotContainsString( '](references/', $text );
		$this->assertSame( -32602, self::fault( static fn() => Prompts::get( array( 'name' => 'leitvia-nothing' ) ) )?->rpc_code );
	}

	/**
	 * Entries of other add-ons need a Markdown file, arguments with name and description, and known sources.
	 */
	public function test_prompt_entries_are_checked(): void {
		$root = Prompts::root();
		$md   = $root . '/skills/leitvia-page/SKILL.md';
		$this->assertSame( '', Prompts::invalid( array( 'path' => $md ) ) );
		$this->assertSame(
			'',
			Prompts::invalid(
				array(
					'path'      => $md,
					'root'      => $root,
					'arguments' => array(
						array(
							'name'        => 'recipe',
							'description' => 'Recipe',
							'complete'    => 'recipes',
						),
					),
				)
			)
		);
		foreach ( array(
			array( 'path' => LEITVIA_STUDIO_FILE ),
			array( 'path' => $root . '/missing.md' ),
			array(
				'path' => $md,
				'root' => 5,
			),
			array(
				'path' => $md,
				'root' => $root . '/missing',
			),
			array(
				'path' => $md,
				'root' => $root . '/workflows',
			),
			array(
				'path'  => $md,
				'title' => '',
			),
			array(
				'path'      => $md,
				'arguments' => 'goal',
			),
			array(
				'path'      => $md,
				'arguments' => array( array( 'name' => 'Goal!' ) ),
			),
			array(
				'path'      => $md,
				'arguments' => array(
					array(
						'name'        => 'goal',
						'description' => 'Goal',
						'complete'    => 'users',
					),
				),
			),
		) as $item ) {
			$this->assertNotSame( '', Prompts::invalid( $item ), (string) wp_json_encode( $item ) );
		}
	}

	/**
	 * The recipe of leitvia-setup completes from the recipes; links of a prompt read as resources, nothing outside
	 * the copy of the skills.
	 */
	public function test_prompt_completion_and_files(): void {
		$values = Completion::complete(
			array(
				'ref'      => array(
					'type' => 'ref/prompt',
					'name' => 'leitvia-setup',
				),
				'argument' => array(
					'name'  => 'recipe',
					'value' => 'blo',
				),
			)
		)['completion']['values'];
		$this->assertSame( array( 'leitvia/blog' ), $values );
		$this->assertContains( 'leitvia/company', Completion::source( 'recipes', '' ) );
		$this->assertSame( array(), Completion::source( 'users', '' ) );

		$modern = self::context();
		$file   = Resources::read( array( 'uri' => 'leitvia://skills/leitvia-studio/skills/leitvia-page/SKILL.md' ), $modern )['contents'][0];
		$this->assertSame( 'text/markdown', $file['mimeType'] );
		$this->assertStringContainsString( '](leitvia://skills/leitvia-studio/', $file['text'] );
		$this->assertContains( Prompts::FILES, array_column( Resources::templates()['resourceTemplates'], 'uriTemplate' ) );
		foreach ( array(
			'leitvia://skills/leitvia-studio/../leitvia-studio.php',
			'leitvia://skills/leitvia-studio/skills/../../leitvia-studio.md',
			'leitvia://skills/leitvia-studio/%2e%2e/readme.md',
			'leitvia://skills/other-addon/skills/leitvia-page/SKILL.md',
			'leitvia://skills/leitvia-studio/handbook/pro.md',
		) as $uri ) {
			$this->assertSame( -32602, self::fault( static fn() => Resources::read( array( 'uri' => $uri ), $modern ) )?->rpc_code, $uri );
		}
	}

	/**
	 * Acceptance of phase 3: delete-media asks first, runs after yes, and still waits in the Inbox; the state works
	 * once.
	 */
	public function test_destructive_tool_asks_first(): void {
		$image     = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$arguments = array(
			'id'        => $image,
			'base_hash' => Hashes::document( $image ),
		);
		$asked     = self::call( 'leitvia-delete-media', $arguments, self::ELICIT );
		$this->assertSame( 'input_required', $asked['resultType'] );
		$this->assertSame( array( 'confirm' ), array_keys( $asked['inputRequests'] ) );
		$form = $asked['inputRequests']['confirm'];
		$this->assertSame( 'elicitation/create', $form['method'] );
		$this->assertSame( 'form', $form['params']['mode'] );
		$this->assertSame( 'boolean', $form['params']['requestedSchema']['properties']['confirm']['type'] );
		$this->assertSame( array( 'confirm' ), $form['params']['requestedSchema']['required'] );
		$this->assertStringContainsString( '"id":' . $image, $form['params']['message'] );
		$this->assertStringNotContainsString( 'base_hash', $form['params']['message'] );
		$this->assertCount( 0, Packages::all( 0, 'open' ), 'Nothing ran before the answer.' );

		$answer = array(
			'requestState'   => $asked['requestState'],
			'inputResponses' => array(
				'confirm' => array(
					'action'  => 'accept',
					'content' => array( 'confirm' => true ),
				),
			),
		);
		$done   = self::call( 'leitvia-delete-media', $arguments, self::ELICIT, $answer );
		$this->assertArrayNotHasKey( 'isError', $done, (string) wp_json_encode( $done ) );
		$this->assertSame( 'package', $done['structuredContent']['mode'], 'A yes in the client is no approval: the deletion waits in the Inbox.' );
		$this->assertNotNull( get_post( $image ) );
		$this->assertSame( -32602, self::fault( static fn() => self::call( 'leitvia-delete-media', $arguments, self::ELICIT, $answer ) )?->rpc_code, 'A state works once.' );
	}

	/**
	 * A missing argument with a closed list is asked for next to the confirmation (here switched on by the filter); a
	 * missing answer asks again with the choice kept, a decline ends the call with leitvia_cancelled.
	 */
	public function test_choice_answers_again_and_decline(): void {
		add_filter( 'leitvia_studio_mcp_confirm', static fn( bool $ask, string $ability ): bool => $ask || 'leitvia/set-motion' === $ability, 10, 2 );
		$arguments = array( 'base_hash' => 'x' );
		$asked     = self::call( 'leitvia-set-motion', $arguments, self::ELICIT );
		$this->assertSame( array( 'choose', 'confirm' ), array_keys( $asked['inputRequests'] ) );
		$motion = $asked['inputRequests']['choose']['params']['requestedSchema']['properties']['motion'];
		$this->assertSame( array( 'off', 'subtle', 'bold' ), $motion['enum'] );
		$this->assertSame( 'string', $motion['type'] );

		$again = self::call(
			'leitvia-set-motion',
			$arguments,
			self::ELICIT,
			array(
				'requestState'   => $asked['requestState'],
				'inputResponses' => array(
					'choose' => array(
						'action'  => 'accept',
						'content' => array( 'motion' => 'subtle' ),
					),
				),
			)
		);
		$this->assertSame( array( 'confirm' ), array_keys( $again['inputRequests'] ) );
		$this->assertNotSame( $asked['requestState'], $again['requestState'] );
		$this->assertSame( array( 'motion' => 'subtle' ), Input_Required::open( $again['requestState'] )['c'] );

		$ran = self::call(
			'leitvia-set-motion',
			$arguments,
			self::ELICIT,
			array(
				'requestState'   => $again['requestState'],
				'inputResponses' => array(
					'confirm' => array(
						'action'  => 'accept',
						'content' => array( 'confirm' => true ),
					),
				),
			)
		);
		$this->assertArrayNotHasKey( 'resultType', $ran, 'The kept choice ran without a third question.' );
		$this->assertArrayNotHasKey( 'isError', $ran, 'The chosen motion reached the ability: ' . $ran['content'][0]['text'] );

		$first    = self::call( 'leitvia-set-motion', $arguments, self::ELICIT );
		$declined = self::call(
			'leitvia-set-motion',
			$arguments,
			self::ELICIT,
			array(
				'requestState'   => $first['requestState'],
				'inputResponses' => array(
					'confirm' => array( 'action' => 'decline' ),
				),
			)
		);
		$this->assertTrue( $declined['isError'] );
		$this->assertSame( 'leitvia_cancelled', json_decode( $declined['content'][0]['text'], true )['code'] );
		$no = self::call( 'leitvia-set-motion', $arguments, self::ELICIT );
		$this->assertTrue(
			self::call(
				'leitvia-set-motion',
				$arguments,
				self::ELICIT,
				array(
					'requestState'   => $no['requestState'],
					'inputResponses' => array(
						'confirm' => array(
							'action'  => 'accept',
							'content' => array( 'confirm' => false ),
						),
					),
				)
			)['isError']
		);
	}

	/**
	 * A yes given while a choice is still open is kept: the next round asks only for the choice. The filter gets the
	 * input of the call.
	 */
	public function test_yes_is_kept_while_a_choice_is_open(): void {
		$inputs = array();
		add_filter(
			'leitvia_studio_mcp_confirm',
			static function ( bool $ask, string $ability, array $input ) use ( &$inputs ): bool {
				$inputs[] = $input;
				return $ask || 'leitvia/set-motion' === $ability;
			},
			10,
			3
		);
		$arguments = array( 'base_hash' => 'x' );
		$asked     = self::call( 'leitvia-set-motion', $arguments, self::ELICIT );
		$this->assertSame( array( 'base_hash' => 'x' ), $inputs[0] );
		$yes = self::call(
			'leitvia-set-motion',
			$arguments,
			self::ELICIT,
			array(
				'requestState'   => $asked['requestState'],
				'inputResponses' => array(
					'confirm' => array(
						'action'  => 'accept',
						'content' => array( 'confirm' => true ),
					),
				),
			)
		);
		$this->assertSame( array( 'choose' ), array_keys( $yes['inputRequests'] ), 'The yes is not asked again.' );
		$ran = self::call(
			'leitvia-set-motion',
			$arguments,
			self::ELICIT,
			array(
				'requestState'   => $yes['requestState'],
				'inputResponses' => array(
					'choose' => array(
						'action'  => 'accept',
						'content' => array( 'motion' => 'subtle' ),
					),
				),
			)
		);
		$this->assertArrayNotHasKey( 'resultType', $ran );
		$this->assertArrayNotHasKey( 'isError', $ran, $ran['content'][0]['text'] ?? '' );
	}

	/**
	 * A choice from the recipes; a dry run needs no confirmation.
	 */
	public function test_choice_from_recipes(): void {
		$asked = self::call( 'leitvia-apply-recipe', array( 'dryRun' => true ), self::ELICIT );
		$this->assertSame( array( 'choose' ), array_keys( $asked['inputRequests'] ) );
		$this->assertContains( 'leitvia/blog', $asked['inputRequests']['choose']['params']['requestedSchema']['properties']['recipe']['enum'] );
		$done = self::call(
			'leitvia-apply-recipe',
			array( 'dryRun' => true ),
			self::ELICIT,
			array(
				'requestState'   => $asked['requestState'],
				'inputResponses' => array(
					'choose' => array(
						'action'  => 'accept',
						'content' => array( 'recipe' => 'leitvia/blog' ),
					),
				),
			)
		);
		$this->assertSame( array( 'leitvia/blog', true ), array( $done['structuredContent']['recipe'], $done['structuredContent']['dryRun'] ) );
		$packaged = array(
			'id'        => 999999,
			'base_hash' => 'x',
			'package'   => 'k1',
		);
		$this->assertArrayNotHasKey( 'inputRequests', self::call( 'leitvia-delete-media', $packaged, self::ELICIT ), 'A package key needs no confirmation.' );
	}

	/**
	 * Only abilities that remove or revert ask by default: update-content does not, delete-media does; the filter
	 * changes both ways.
	 */
	public function test_confirm_only_for_removals(): void {
		$update = array(
			'post'      => 999999,
			'base_hash' => 'x',
			'title'     => 'T',
		);
		$delete = array(
			'id'        => 999999,
			'base_hash' => 'x',
		);
		$this->assertArrayNotHasKey( 'resultType', self::call( 'leitvia-update-content', $update, self::ELICIT ) );
		$this->assertSame( array( 'confirm' ), array_keys( self::call( 'leitvia-delete-media', $delete, self::ELICIT )['inputRequests'] ) );

		$flip = static fn( bool $ask, string $ability ): bool => in_array( $ability, array( 'leitvia/update-content', 'leitvia/delete-media' ), true ) ? ! $ask : $ask;
		add_filter( 'leitvia_studio_mcp_confirm', $flip, 10, 2 );
		$this->assertSame( array( 'confirm' ), array_keys( self::call( 'leitvia-update-content', $update, self::ELICIT )['inputRequests'] ) );
		$this->assertArrayNotHasKey( 'resultType', self::call( 'leitvia-delete-media', $delete, self::ELICIT ) );
	}

	/**
	 * The state belongs to its user, call and time and cannot be changed; without form elicitation, under 2025-11-25
	 * and without a state, answers are not taken and nothing is asked.
	 */
	public function test_state_is_bound_and_capability_needed(): void {
		$arguments = array( 'base_hash' => 'x' );
		$asked     = self::call( 'leitvia-set-motion', $arguments, self::ELICIT );
		$state     = $asked['requestState'];
		$fail      = fn( string $given, array $args = array( 'base_hash' => 'x' ) ): ?int => self::fault(
			static fn() => self::call( 'leitvia-set-motion', $args, self::ELICIT, array( 'requestState' => $given ) )
		)?->rpc_code;
		$this->assertSame( -32602, $fail( $state, array( 'base_hash' => 'y' ) ), 'other arguments' );
		$this->assertSame( -32602, $fail( 'A' . $state ), 'changed state' );
		$this->assertSame( -32602, $fail( 'no-state' ) );
		$payload      = Input_Required::open( $state );
		$payload['e'] = time() - 1;
		$this->assertSame( -32602, $fail( Input_Required::seal( $payload ) ), 'expired' );
		$owner = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertSame( -32602, $fail( $state ), 'other user' );
		wp_set_current_user( $owner );
		$this->assertSame( 'input_required', self::call( 'leitvia-set-motion', $arguments, self::ELICIT, array( 'requestState' => $state ) )['resultType'], 'Failed checks did not use the state up.' );

		$skipped = array(
			array( array(), true ),
			array( array( 'elicitation' => array( 'url' => array() ) ), true ),
			array( self::ELICIT, false ),
		);
		foreach ( $skipped as [ $capabilities, $modern ] ) {
			$result = Tools::call(
				array(
					'name'           => 'leitvia-set-motion',
					'arguments'      => $arguments,
					'inputResponses' => array( 'confirm' => array( 'action' => 'decline' ) ),
				),
				new Context( $modern ? '2026-07-28' : '2025-11-25', $modern, $capabilities )
			);
			$this->assertArrayNotHasKey( 'resultType', $result );
			$this->assertNotSame( 'leitvia_cancelled', json_decode( $result['content'][0]['text'], true )['code'] ?? '' );
		}
	}

	/**
	 * Prompts in both revisions, list changes and the tasks extension only under 2026-07-28; tasks/get repeats the
	 * task ID in Mcp-Name, input_required passes the route unchanged.
	 */
	public function test_capabilities_and_routes(): void {
		$modern = Dispatcher::handle( 'server/discover', array(), self::context() );
		$this->assertSame( array( 'listChanged' => true ), $modern['capabilities']['prompts'] );
		$this->assertEquals( array( Tasks::EXTENSION => new stdClass() ), $modern['capabilities']['extensions'] );
		$legacy = Dispatcher::handle( 'initialize', array(), new Context( '2025-11-25', false ) );
		$this->assertSame( array( 'listChanged' => false ), $legacy['capabilities']['prompts'] );
		$this->assertArrayNotHasKey( 'extensions', $legacy['capabilities'] );
		$this->assertCount( 14, Dispatcher::handle( 'prompts/list', array(), new Context( '2025-11-25', false ) )['prompts'] );
		$this->assertSame( 'private', Dispatcher::handle( 'prompts/list', array(), self::context() )['cacheScope'] );

		$asked = rest_get_server()->dispatch(
			self::request(
				'tools/call',
				array(
					'name'      => 'leitvia-set-motion',
					'arguments' => array( 'base_hash' => 'x' ),
				),
				self::ELICIT
			)
		)->get_data();
		$this->assertSame( 'input_required', $asked['result']['resultType'] );
		$this->assertSame( 'leitvia', $asked['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] );

		$id    = str_repeat( 'a', 32 );
		$wrong = rest_get_server()->dispatch( self::request( 'tasks/get', array( 'taskId' => $id ), self::TASKS, array( 'Mcp-Name' => 'other' ) ) );
		$this->assertSame( -32020, $wrong->get_data()['error']['code'] );
		$none = rest_get_server()->dispatch( self::request( 'tasks/get', array( 'taskId' => $id ), self::TASKS ) );
		$this->assertSame( array( 404, -32602 ), array( $none->get_status(), $none->get_data()['error']['code'] ) );
		$prompt = rest_get_server()->dispatch(
			self::request(
				'prompts/get',
				array(
					'name'      => 'leitvia-page',
					'arguments' => array( 'goal' => 'Bookings' ),
				)
			)
		);
		$this->assertSame( 'complete', $prompt->get_data()['result']['resultType'] );
	}

	/**
	 * A plugin switched on reaches a subscriber with promptsListChanged; the acknowledgment names the filter.
	 */
	public function test_prompts_list_change_reaches_a_subscriber(): void {
		$out   = '';
		$ticks = 0;
		Subscriptions::listen(
			array( 'notifications' => array( 'promptsListChanged' => true ) ),
			9,
			Subscriptions::acquire(),
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static function () use ( &$ticks ): bool {
				if ( 2 === ++$ticks ) {
					do_action( 'activated_plugin', 'other/other.php', false );
				}
				return $ticks < 4;
			}
		);
		$events = self::events( $out );
		$this->assertTrue( $events[0]['params']['notifications']['promptsListChanged'] );
		$this->assertSame( array( 'notifications/subscriptions/acknowledged', 'notifications/prompts/list_changed' ), array_column( $events, 'method' ) );
		$this->assertSame( 9, $events[1]['params']['_meta']['io.modelcontextprotocol/subscriptionId'] );
	}

	/**
	 * Acceptance of phase 3: apply-recipe with the tasks extension answers with a task at once, runs in cron as the
	 * user who called, not as an MCP-free request, and tasks/get returns the tool result; the task belongs to its user.
	 */
	public function test_task_runs_in_the_background(): void {
		$hosts = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$hosts ) {
				unset( $pre, $args );
				$hosts[] = wp_parse_url( $url, PHP_URL_HOST );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 200 ),
				);
			},
			10,
			3
		);
		$arguments = array(
			'recipe' => 'leitvia/blog',
			'dryRun' => true,
		);
		$sync      = self::call( 'leitvia-apply-recipe', $arguments );
		$this->assertTrue( $sync['structuredContent']['dryRun'], 'Without the extension the call runs in the request.' );

		$task = self::call( 'leitvia-apply-recipe', $arguments, self::TASKS );
		$this->assertSame( array( 'task', 'working' ), array( $task['resultType'], $task['status'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $task['taskId'] );
		$this->assertSame( array( Tasks::TTL * 1000, 2000 ), array( $task['ttlMs'], $task['pollIntervalMs'] ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $task['createdAt'] );
		foreach ( $hosts as $host ) {
			$this->assertSame( wp_parse_url( site_url(), PHP_URL_HOST ), $host, 'Only the loopback to wp-cron.php of the site.' );
		}
		$get     = static fn( array $capabilities = self::TASKS ) => Tasks::get( array( 'taskId' => $task['taskId'] ), self::context( $capabilities ) );
		$this->assertSame( 'working', $get()['status'] );
		$missing = self::fault( static fn() => $get( array() ) );
		$this->assertSame( array( -32021, 400 ), array( $missing?->rpc_code, $missing?->status ) );
		$this->assertArrayHasKey( Tasks::EXTENSION, $missing->data['requiredCapabilities']['extensions'] );
		$owner = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertSame( -32602, self::fault( $get )?->rpc_code, 'Another user learns nothing of the task.' );
		wp_set_current_user( 0 );

		$serving = array();
		add_action(
			'leitvia_studio_progress',
			static function () use ( &$serving ): void {
				$serving[] = \LeitviaStudio\Gateway\Mcp::serving();
			},
			20
		);
		do_action( Tasks::HOOK, $task['taskId'] );
		$this->assertSame( 0, get_current_user_id(), 'The runner gives the user back.' );
		$this->assertNotEmpty( $serving );
		$this->assertSame( array( true ), array_values( array_unique( $serving ) ), 'A task never settles packages: it counts as MCP.' );
		wp_set_current_user( $owner );
		$done = $get();
		$this->assertSame( 'completed', $done['status'] );
		$this->assertSame( 'complete', $done['result']['resultType'] );
		$this->assertSame( 'leitvia/blog', $done['result']['structuredContent']['recipe'] );
		$this->assertNotSame( '', $done['statusMessage'] ?? '', 'Progress of the recipe shows as status message.' );
		$this->assertSame( array(), Tasks::update( array( 'taskId' => $task['taskId'] ), self::context( self::TASKS ) ) );
		$this->assertSame( array(), Tasks::cancel( array( 'taskId' => $task['taskId'] ), self::context( self::TASKS ) ) );
		$this->assertSame( 'completed', $get()['status'], 'A finished task stays finished.' );
		$this->assertNotFalse( wp_next_scheduled( Tasks::EXPIRE ), 'One hourly event cleans up.' );
		$this->assertFalse( wp_next_scheduled( Tasks::EXPIRE, array( $task['taskId'] ) ), 'No event per task.' );
		$record            = get_option( 'leitvia_studio_mcp_task_' . $task['taskId'] );
		$record['created'] = time() - Tasks::TTL - 1;
		update_option( 'leitvia_studio_mcp_task_' . $task['taskId'], $record, false );
		do_action( Tasks::EXPIRE );
		$this->assertFalse( get_option( 'leitvia_studio_mcp_task_' . $task['taskId'] ) );
		$this->assertSame( -32602, self::fault( $get )?->rpc_code );
	}

	/**
	 * A user who may not call the ability gets neither a question nor a task; a user holds three working tasks, the
	 * fourth call runs in the request.
	 */
	public function test_task_permission_and_limit(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
		$arguments = array(
			'recipe' => 'leitvia/blog',
			'dryRun' => true,
		);
		$agent     = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( array( 'leitvia-apply-recipe', $arguments ), array( 'leitvia-delete-media', array( 'id' => 1 ) ) ) as [ $tool, $args ] ) {
			$denied = self::call( $tool, $args, self::TASKS + self::ELICIT );
			$this->assertTrue( $denied['isError'] ?? false, $tool );
			$this->assertSame( 'ability_invalid_permissions', json_decode( $denied['content'][0]['text'], true )['code'], $tool );
		}
		wp_set_current_user( $agent );

		$ids = array();
		for ( $i = 0; $i < Tasks::MAX_OPEN; $i++ ) {
			$task = self::call( 'leitvia-apply-recipe', $arguments, self::TASKS );
			$this->assertSame( 'task', $task['resultType'] );
			$ids[] = $task['taskId'];
		}
		$fourth = self::call( 'leitvia-apply-recipe', $arguments, self::TASKS );
		$this->assertArrayNotHasKey( 'resultType', $fourth, 'Above the limit the call runs in the request.' );
		$this->assertTrue( $fourth['structuredContent']['dryRun'] );
		Tasks::cancel( array( 'taskId' => $ids[0] ), self::context( self::TASKS ) );
		$this->assertSame( 'task', self::call( 'leitvia-apply-recipe', $arguments, self::TASKS )['resultType'], 'A cancelled task frees its place.' );
	}

	/**
	 * A lock older than the time limit was left by a run that died: the task fails. A fresh lock keeps a running
	 * task past its TTL.
	 */
	public function test_orphaned_lock_fails_the_task(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
		$start  = function ( int $lock, int $created ): string {
			$task   = self::call(
				'leitvia-apply-recipe',
				array(
					'recipe' => 'leitvia/blog',
					'dryRun' => true,
				),
				self::TASKS
			);
			$option = 'leitvia_studio_mcp_task_' . $task['taskId'];
			update_option(
				$option,
				array(
					'started' => true,
					'created' => $created,
				) + get_option( $option ),
				false
			);
			update_option( $option . '_lock', $lock, false );
			return $task['taskId'];
		};
		$get    = static fn( string $id ) => Tasks::get( array( 'taskId' => $id ), self::context( self::TASKS ) );
		$dead   = $start( time() - 2000, time() );
		$failed = $get( $dead );
		$this->assertSame( array( 'failed', -32603 ), array( $failed['status'], $failed['error']['code'] ) );
		$this->assertFalse( get_option( 'leitvia_studio_mcp_task_' . $dead . '_lock' ), 'The left lock is gone.' );

		$alive = $start( time(), time() - Tasks::TTL - 10 );
		$this->assertSame( 'working', $get( $alive )['status'], 'A run with a fresh lock keeps its task.' );
	}

	/**
	 * Cheap errors of a tool call with progressToken answer as JSON with their status, before any stream; a listen
	 * for tasks needs the tasks extension.
	 */
	public function test_stream_errors_answer_as_json(): void {
		$progress = array( '_meta' => array( 'progressToken' => 1.5 ) );
		$unknown  = rest_get_server()->dispatch( self::request( 'tools/call', array( 'name' => 'leitvia-nothing' ) + $progress ) );
		$this->assertNotInstanceOf( Stream::class, $unknown );
		$this->assertSame( array( 400, -32602 ), array( $unknown->get_status(), $unknown->get_data()['error']['code'] ) );
		$state = rest_get_server()->dispatch(
			self::request(
				'tools/call',
				array(
					'name'         => 'leitvia-set-motion',
					'arguments'    => array( 'base_hash' => 'x' ),
					'requestState' => 'no-state',
				) + $progress,
				self::ELICIT
			)
		);
		$this->assertNotInstanceOf( Stream::class, $state );
		$this->assertSame( array( 400, -32602 ), array( $state->get_status(), $state->get_data()['error']['code'] ) );

		$listen = rest_get_server()->dispatch( self::request( 'subscriptions/listen', array( 'notifications' => array( 'taskIds' => array( str_repeat( 'a', 32 ) ) ) ) ) );
		$this->assertSame( array( 400, -32021 ), array( $listen->get_status(), $listen->get_data()['error']['code'] ) );
	}

	/**
	 * A float token is a token; the first report counts also at 0, then progress only grows.
	 */
	public function test_progress_token_and_first_report(): void {
		$this->assertSame( 1.5, \LeitviaStudio\Mcp\Progress::token( array( '_meta' => array( 'progressToken' => 1.5 ) ) ) );
		$this->assertNull( \LeitviaStudio\Mcp\Progress::token( array( '_meta' => array( 'progressToken' => true ) ) ) );
		$out = '';
		\LeitviaStudio\Mcp\Progress::during(
			't',
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static function (): void {
				do_action( 'leitvia_studio_progress', 0, 2 );
				do_action( 'leitvia_studio_progress', 0, 2 );
				do_action( 'leitvia_studio_progress', 1, 2 );
			}
		);
		$this->assertSame( array( 0, 1 ), array_column( array_column( self::events( $out ), 'params' ), 'progress' ) );
	}

	/**
	 * A task that has not started can be cancelled and never runs; a short ability never becomes a task.
	 */
	public function test_task_cancel_and_short_abilities(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
		$task = self::call(
			'leitvia-apply-recipe',
			array(
				'recipe' => 'leitvia/blog',
				'dryRun' => true,
			),
			self::TASKS
		);
		Tasks::cancel( array( 'taskId' => $task['taskId'] ), self::context( self::TASKS ) );
		do_action( Tasks::HOOK, $task['taskId'] );
		$state = Tasks::get( array( 'taskId' => $task['taskId'] ), self::context( self::TASKS ) );
		$this->assertSame( 'cancelled', $state['status'] );
		$this->assertArrayNotHasKey( 'result', $state );
		$this->assertFalse( wp_next_scheduled( Tasks::HOOK, array( $task['taskId'] ) ) );
		$this->assertArrayNotHasKey( 'resultType', self::call( 'leitvia-get-contract', null, self::TASKS ) );
		foreach ( array( 'leitvia/apply-recipe', 'leitvia/audit-site', 'leitvia/export' ) as $name ) {
			$this->assertTrue( wp_get_ability( $name )->get_meta_item( 'long_running', false ), $name );
		}
	}

	/**
	 * The call tasks/cancel never turns a running task into cancelled: asked in the middle of the run, the task still completes.
	 */
	public function test_cancel_leaves_a_running_task(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
		$task      = self::call(
			'leitvia-apply-recipe',
			array(
				'recipe' => 'leitvia/blog',
				'dryRun' => true,
			),
			self::TASKS
		);
		$cancelled = 0;
		add_action(
			'leitvia_studio_progress',
			static function () use ( &$cancelled, $task ): void {
				++$cancelled;
				Tasks::cancel( array( 'taskId' => $task['taskId'] ), self::context( self::TASKS ) );
			},
			20
		);
		do_action( Tasks::HOOK, $task['taskId'] );
		$this->assertGreaterThan( 0, $cancelled );
		$state = Tasks::get( array( 'taskId' => $task['taskId'] ), self::context( self::TASKS ) );
		$this->assertSame( 'completed', $state['status'] );
		$this->assertArrayHasKey( 'result', $state );
	}

	/**
	 * A tools/call with progressToken and an event stream accepted answers as stream: progress of the recipe, then the
	 * response. Without the token it stays JSON.
	 */
	public function test_progress_streams(): void {
		$params   = array(
			'name'      => 'leitvia-apply-recipe',
			'arguments' => array(
				'recipe' => 'leitvia/blog',
				'dryRun' => true,
			),
			'_meta'     => array( 'progressToken' => 'p1' ),
		);
		$response = rest_get_server()->dispatch( self::request( 'tools/call', $params ) );
		$this->assertInstanceOf( Stream::class, $response );
		$this->assertNull( $response->time_limit, 'A tool call keeps the time limit of the server.' );
		$out = '';
		$response->run(
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static fn(): bool => true
		);
		$events   = self::events( $out );
		$progress = array_values( array_filter( $events, static fn( array $event ): bool => 'notifications/progress' === ( $event['method'] ?? '' ) ) );
		$this->assertNotEmpty( $progress );
		$this->assertSame( 'p1', $progress[0]['params']['progressToken'] );
		$values = array_column( array_column( $progress, 'params' ), 'progress' );
		$sorted = $values;
		sort( $sorted );
		$this->assertSame( $sorted, $values, 'Progress only grows.' );
		$this->assertCount( count( array_unique( $values ) ), $values );
		$last = end( $events );
		$this->assertSame( array( 7, 'complete' ), array( $last['id'], $last['result']['resultType'] ) );
		$this->assertSame( 'leitvia/blog', $last['result']['structuredContent']['recipe'] );

		unset( $params['_meta'] );
		$plain = rest_get_server()->dispatch( self::request( 'tools/call', $params ) );
		$this->assertNotInstanceOf( Stream::class, $plain );
		$json = rest_get_server()->dispatch( self::request( 'tools/call', $params + array( '_meta' => array( 'progressToken' => 'p2' ) ), array(), array( 'Accept' => 'application/json' ) ) );
		$this->assertNotInstanceOf( Stream::class, $json, 'A client that takes no stream gets JSON.' );
	}
}
