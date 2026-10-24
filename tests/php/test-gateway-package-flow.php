<?php
/**
 * Gateway change packages: apply, reject, roll back, preview, abilities and REST.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Preview;
use LeitviaStudio\Gateway\Store;

/**
 * Whole and partial application with conflicts, rollback with conflict check, rejection, media in the private area,
 * the preview, the abilities with autonomy and the REST routes of the inbox.
 */
class Test_Gateway_Package_Flow extends WP_UnitTestCase {

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Leitvia theme, the abilities handed to core, an agent at "suggest" everywhere.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::settings( 'suggest' );
		wp_set_current_user( $this->agent );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
		unset( $_GET[ Preview::ARG ], $_GET['_wpnonce'] );
		wp_clean_theme_json_cache();
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
	 * One level for the content, structure and design areas, every content kind unlocked.
	 *
	 * @param string $level Level.
	 */
	private static function settings( string $level ): void {
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), $level ),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
	}

	/**
	 * A published post by an editor.
	 *
	 * @param string $text Paragraph text.
	 */
	private function post( string $text = 'Old' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
				'post_content' => wp_slash( '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->' ),
				'post_author'  => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
	}

	/**
	 * Queues a change of post fields in the package with this key, as the agent.
	 *
	 * @param int                  $post   Post ID.
	 * @param array<string, mixed> $fields Fields.
	 * @param string               $key    Package key.
	 * @return int Package ID.
	 */
	private function queue( int $post, array $fields, string $key = 'task' ): int {
		wp_set_current_user( $this->agent );
		$result = Pipeline::run( new Operation( 'leitvia/test-write', 'content', array( 'post' => $post ), Hashes::document( $post ), static fn( array $state ): array => $fields + $state, array(), $key ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 'package', $result['mode'] );
		return $result['package'];
	}

	/**
	 * A paragraph with this text.
	 *
	 * @param string $text Text.
	 */
	private static function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Counts an action.
	 *
	 * @param string $hook Action.
	 * @return ArrayObject<int, int> IDs the action fired with.
	 */
	private static function listen( string $hook ): ArrayObject {
		$calls = new ArrayObject();
		add_action(
			$hook,
			static function ( int $id ) use ( $calls ): void {
				$calls[] = $id;
			}
		);
		return $calls;
	}

	/**
	 * Partial application: the chosen operations run, one whose post a person changed stays open with a conflict
	 * (op, base hash, current hash), the others are written with snapshot and audit entry per operation.
	 */
	public function test_apply_whole_and_partial(): void {
		$first   = $this->post( 'First' );
		$second  = $this->post( 'Second' );
		$third   = $this->post( 'Third' );
		$package = $this->queue( $first, array( 'post_content' => self::paragraph( 'First new' ) ) );
		$this->assertSame( $package, $this->queue( $second, array( 'post_content' => self::paragraph( 'Second new' ) ) ) );
		$this->assertSame( $package, $this->queue( $third, array( 'post_content' => self::paragraph( 'Third new' ) ) ) );
		$base = Hashes::document( $second );
		wp_update_post(
			array(
				'ID'           => $second,
				'post_content' => self::paragraph( 'Changed by a person' ),
			)
		);
		$applied = self::listen( 'leitvia_studio_package_applied' );

		wp_set_current_user( $this->admin );
		$report = Packages::apply( $package, array( 1, 2 ) );
		$this->assertIsArray( $report );
		$this->assertSame( 'partial', $report['state'] );
		$this->assertSame( array( 1 ), $report['applied'] );
		$this->assertSame(
			array(
				array(
					'op'           => 2,
					'base_hash'    => $base,
					'current_hash' => Hashes::document( $second ),
				),
			),
			$report['conflicts']
		);
		$this->assertStringContainsString( 'First new', get_post( $first )->post_content );
		$this->assertStringContainsString( 'Changed by a person', get_post( $second )->post_content );
		$this->assertStringContainsString( 'Third</p>', get_post( $third )->post_content );
		$this->assertSame( 'partial', Packages::state( $package ) );
		$this->assertSame( array( $package ), $applied->getArrayCopy() );

		$operations = Packages::operations( $package );
		$snapshot   = Store::get_snapshot( $operations[0]['applied']['snapshot'] );
		$this->assertSame( $package, (int) $snapshot['package_id'] );
		$this->assertStringContainsString( 'First</p>', $snapshot['state']['post_content'] );
		$this->assertSame( Hashes::document( $first ), $operations[0]['applied']['hash'] );
		$this->assertSame( 2, $operations[1]['conflict']['op'] );
		global $wpdb;
		$audit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'audit' ) . ' WHERE user_id = %d AND package_id = %d ORDER BY id DESC LIMIT 1', $this->admin, $package ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
		$this->assertSame( 'ok', $audit['result'] );
		$this->assertSame( $first, (int) $audit['object_id'] );

		$detail = Packages::detail( $package );
		$this->assertSame( array( 'applied', 'conflict', 'open' ), array_column( $detail['items'], 'status' ) );
		$this->assertSame( 'changed', $detail['items'][2]['diff'][0]['change'] );
		$this->assertArrayNotHasKey( 'diff', $detail['items'][0] );

		$report = Packages::apply( $package );
		$this->assertSame( array( 3 ), $report['applied'] );
		$this->assertSame( 'partial', $report['state'] );
		$this->assertStringContainsString( 'Third new', get_post( $third )->post_content );
		$this->assertSame( array( $package, $package ), $applied->getArrayCopy() );
	}

	/**
	 * Two writes to one post in one package: the second builds on the state of the first, shows only its own change,
	 * applies on the hash the first left (alone it conflicts) and rolls back with it, newest first. Repeating it adds
	 * nothing.
	 */
	public function test_operations_on_one_post_build_on_each_other(): void {
		$post    = $this->post( 'Old' );
		$package = $this->queue( $post, array( 'post_title' => 'Agent title' ) );
		$this->assertSame( $package, $this->queue( $post, array( 'post_content' => self::paragraph( 'Agent text' ) ) ) );
		$this->assertSame( $package, $this->queue( $post, array( 'post_content' => self::paragraph( 'Agent text' ) ) ), 'the same change again' );
		$operations = Packages::operations( $package );
		$this->assertCount( 2, $operations );
		$this->assertSame( 1, $operations[1]['follows'] );
		$this->assertSame( 'Agent title', $operations[1]['state']['post_title'] );
		$this->assertSame( array( 'field:post_title' ), array_column( Packages::detail( $package )['items'][0]['diff'], 'id' ) );
		$this->assertNotContains( 'field:post_title', array_column( Packages::detail( $package )['items'][1]['diff'], 'id' ) );

		wp_set_current_user( $this->admin );
		$report = Packages::apply( $package, array( 2 ) );
		$this->assertSame( array(), $report['applied'] );
		$this->assertSame( '', $report['conflicts'][0]['base_hash'], 'without the first it conflicts' );
		$this->assertSame( 'Original title', get_the_title( $post ) );

		$report = Packages::apply( $package );
		$this->assertSame( array( 1, 2 ), $report['applied'], wp_json_encode( $report ) );
		$this->assertSame( array(), $report['conflicts'] );
		$this->assertSame( 'applied', $report['state'] );
		$this->assertSame( 'Agent title', get_the_title( $post ) );
		$this->assertStringContainsString( 'Agent text', get_post( $post )->post_content );
		$this->assertSame( Hashes::document( $post ), Packages::operations( $package )[1]['applied']['hash'] );

		$report = Packages::rollback( $package );
		$this->assertIsArray( $report, is_wp_error( $report ) ? wp_json_encode( $report->get_error_data() ) : '' );
		$this->assertSame( array( 2, 1 ), $report['rolled_back'] );
		$this->assertSame( 'Original title', get_the_title( $post ) );
		$this->assertStringContainsString( 'Old</p>', get_post( $post )->post_content );
	}

	/**
	 * Rollback refuses while a person changed a target after the package, with both hashes and the diff, and writes
	 * nothing. Afterwards it restores content, title, status and slug (quotes and backslashes intact), deletes the
	 * post the package created, restores the option and the global styles, and fires the action.
	 */
	public function test_rollback_with_conflict_check(): void {
		$original = 'Path C:\\dir "quoted"';
		$post     = $this->post( $original );
		$before   = get_post( $post, ARRAY_A );
		$package  = $this->queue(
			$post,
			array(
				'post_title'   => 'Agent title',
				'post_content' => self::paragraph( 'Agent text' ),
				'post_status'  => 'draft',
				'post_name'    => 'agent-slug',
			)
		);
		$page     = array(
			'post_title'   => 'New page',
			'post_content' => self::paragraph( 'Hello' ),
		);
		$target   = array(
			'post'      => 0,
			'post_type' => 'page',
		);
		$result   = Pipeline::run( new Operation( 'leitvia/test-write', 'content', $target, '', static fn( array $state ): array => $page + $state, array(), 'task' ) );
		$this->assertSame( $package, $result['package'] );
		$tagline = get_option( 'blogdescription' );
		$result  = Pipeline::run( new Operation( 'leitvia/test-write', 'structure', array( 'option' => 'blogdescription' ), Hashes::of( (string) wp_json_encode( $tagline ) ), static fn(): array => array( 'value' => 'Agent tagline' ), array(), 'task' ) );
		$this->assertSame( $package, $result['package'] );
		$styles = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$json   = (string) get_post_field( 'post_content', $styles['ID'], 'raw' );
		$result = Pipeline::run( new Operation( 'leitvia/test-write', 'design', array( 'post' => (int) $styles['ID'] ), Hashes::document( (int) $styles['ID'] ), static fn( array $state ): array => array( 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"text":"#111111"}}}' ) + $state, array(), 'task' ) );
		$this->assertSame( $package, $result['package'] );

		wp_set_current_user( $this->admin );
		$report = Packages::apply( $package );
		$this->assertSame( array( 1, 2, 3, 4 ), $report['applied'], wp_json_encode( $report ) );
		$this->assertSame( 'applied', Packages::state( $package ) );
		$created = Packages::operations( $package )[1]['applied']['post'];
		$this->assertSame( 'New page', get_the_title( $created ) );
		$this->assertSame( 'Agent tagline', get_option( 'blogdescription' ) );
		$this->assertSame( 'agent-slug', get_post_field( 'post_name', $post ) );

		$agent_state = get_post( $post, ARRAY_A );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => self::paragraph( 'Person after agent' ),
			)
		);
		$rolled = self::listen( 'leitvia_studio_package_rolled_back' );
		$denied = Packages::rollback( $package );
		$this->assertWPError( $denied );
		$this->assertSame( 'leitvia_rollback_conflict', $denied->get_error_code() );
		$conflict = $denied->get_error_data()['conflicts'][0];
		$this->assertSame( 1, $conflict['op'] );
		$this->assertSame( Hashes::document( $post ), $conflict['current_hash'] );
		$this->assertNotSame( $conflict['applied_hash'], $conflict['current_hash'] );
		$this->assertSame( 'Person after agent', $conflict['diff'][0]['after_text'] );
		$this->assertStringContainsString( 'Person after agent', get_post( $post )->post_content );
		$this->assertNotNull( get_post( $created ), 'nothing rolled back' );
		$this->assertSame( 'Agent tagline', get_option( 'blogdescription' ) );
		$this->assertSame( array(), $rolled->getArrayCopy() );

		wp_update_post( wp_slash( array_intersect_key( $agent_state, array_flip( array( 'ID', 'post_content' ) ) ) ) );
		$report = Packages::rollback( $package );
		$this->assertIsArray( $report, is_wp_error( $report ) ? wp_json_encode( $report->get_error_data() ) : '' );
		$this->assertSame( array( 4, 3, 2, 1 ), $report['rolled_back'] );
		$after = get_post( $post );
		$this->assertSame( $before['post_content'], $after->post_content );
		$this->assertStringContainsString( $original, $after->post_content );
		$this->assertSame( 'Original title', $after->post_title );
		$this->assertSame( 'publish', $after->post_status );
		$this->assertSame( $before['post_name'], $after->post_name );
		$this->assertNull( get_post( $created ) );
		$this->assertSame( $tagline, get_option( 'blogdescription' ) );
		$this->assertSame( $json, get_post_field( 'post_content', $styles['ID'], 'raw' ) );
		$this->assertSame( 'rolled_back', Packages::state( $package ) );
		$this->assertSame( array( $package ), $rolled->getArrayCopy() );
		$this->assertWPError( Packages::rollback( $package ), 'a rolled back package stays closed' );
	}

	/**
	 * Media at "suggest": the file from add-media waits in the private area, not in the library. Rejecting writes nothing and
	 * deletes it; applying moves it into the uploads folder.
	 */
	public function test_media_waits_privately(): void {
		$png   = base64_encode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/avatar.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
		$input = array(
			'data'      => $png,
			'filename'  => 'portrait.png',
			'alt'       => 'Portrait of the author',
			'base_hash' => '',
		);
		$count = (int) wp_count_posts( 'attachment' )->inherit;
		$first = wp_get_ability( 'leitvia/add-media' )->execute( $input + array( 'package' => 'media-1' ) );
		$this->assertIsArray( $first, is_wp_error( $first ) ? wp_json_encode( $first->get_error_data() ) : '' );
		$this->assertSame( 'package', $first['mode'] );
		$file = Packages::operations( $first['package'] )[0]['state']['file'];
		$this->assertFileExists( $file );
		$this->assertStringContainsString( '/' . Packages::PRIVATE_DIR . '/', $file );
		$this->assertFileExists( dirname( $file, 2 ) . '/.htaccess' );
		$this->assertSame( $count, (int) wp_count_posts( 'attachment' )->inherit );

		wp_set_current_user( $this->admin );
		$rejected = Packages::reject( $first['package'] );
		$this->assertSame( 'rejected', $rejected['state'] );
		$this->assertFileDoesNotExist( $file );
		$this->assertDirectoryDoesNotExist( dirname( $file ) );
		$this->assertWPError( Packages::apply( $first['package'] ) );

		wp_set_current_user( $this->agent );
		$second = wp_get_ability( 'leitvia/add-media' )->execute( $input + array( 'package' => 'media-2' ) );
		$file   = Packages::operations( $second['package'] )[0]['state']['file'];
		wp_set_current_user( $this->admin );
		$report = Packages::apply( $second['package'] );
		$this->assertSame( array( 1 ), $report['applied'] );
		$id = Packages::operations( $second['package'] )[0]['applied']['post'];
		$this->assertFileDoesNotExist( $file );
		$this->assertFileExists( get_attached_file( $id ) );
		$this->assertStringNotContainsString( Packages::PRIVATE_DIR, get_attached_file( $id ) );
		$this->assertSame( _wp_relative_upload_path( get_attached_file( $id ) ), wp_get_attachment_metadata( $id )['file'] );
		$this->assertSame( 'Portrait of the author', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		$this->assertNull( Packages::promote_file( array( 'file' => get_attached_file( $id ) ) ), 'only files of the private area move' );
	}

	/**
	 * Rejecting sets rejected and writes nothing.
	 */
	public function test_reject(): void {
		$post    = $this->post();
		$hash    = Hashes::document( $post );
		$package = $this->queue( $post, array( 'post_content' => self::paragraph( 'New' ) ) );
		wp_set_current_user( $this->admin );
		$this->assertSame( 'rejected', Packages::reject( $package )['state'] );
		$this->assertSame( $hash, Hashes::document( $post ) );
		$this->assertSame( 'leitvia_package_closed', Packages::reject( $package )->get_error_code() );
		$this->assertSame( 'leitvia_unknown_package', Packages::apply( $post )->get_error_code() );
	}

	/**
	 * The preview shows the new state in memory for a user who may edit the target and holds the nonce; anyone else
	 * sees the normal post. Nothing is saved, robots get noindex.
	 */
	public function test_preview(): void {
		$post    = $this->post();
		$package = $this->queue( $post, array( 'post_content' => self::paragraph( 'Preview text' ) ) );
		$stored  = get_post( $post )->post_content;

		wp_set_current_user( $this->admin );
		$url = Preview::url( $package );
		$this->assertStringContainsString( Preview::ARG . '=' . $package, $url );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$_GET[ Preview::ARG ] = (string) $package;
		$this->assertSame( $stored, Preview::posts( array( get_post( $post ) ) )[0]->post_content, 'no nonce, no preview' );
		$this->assertSame( array(), Preview::robots( array() ) );

		$_GET['_wpnonce'] = $query['_wpnonce'];
		$shown            = Preview::posts( array( get_post( $post ) ) );
		$this->assertStringContainsString( 'Preview text', $shown[0]->post_content );
		$this->assertTrue( Preview::robots( array() )['noindex'] );
		$this->assertSame( $stored, get_post( $post )->post_content, 'nothing saved' );
		$this->assertSame( $stored, get_post_field( 'post_content', $post, 'raw' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( $stored, Preview::posts( array( get_post( $post ) ) )[0]->post_content, 'nonce of another user' );
		wp_set_current_user( $this->admin );
		$styles = self::factory()->post->create(
			array(
				'post_type'   => 'wp_global_styles',
				'post_status' => 'publish',
			)
		);
		$design = $this->queue( $styles, array( 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}' ) );
		$this->assertStringStartsWith( home_url( '/?' ), Preview::url( $design ), 'a post without an address previews on the front page' );
	}

	/**
	 * Abilities: an agent applies only autonomous operations (else leitvia_needs_human with the inbox), sees its own
	 * packages with the diff, and rolls back under the same rules; another agent gets nothing.
	 */
	public function test_abilities(): void {
		$post    = $this->post();
		$package = $this->queue( $post, array( 'post_content' => self::paragraph( 'Agent text' ) ) );
		$apply   = wp_get_ability( 'leitvia/apply-package' );
		$this->assertFalse( $apply->get_meta()['annotations']['readonly'] );

		$human = $apply->execute( array( 'id' => $package ) );
		$this->assertWPError( $human );
		$this->assertSame( 'leitvia_needs_human', $human->get_error_code() );
		$this->assertSame( array( 1 ), $human->get_error_data()['operations'] );
		$this->assertStringContainsString( 'page=leitvia-studio-inbox', $human->get_error_data()['inbox'] );
		$this->assertSame( 'open', Packages::state( $package ) );

		$list = wp_get_ability( 'leitvia/list-packages' )->execute( array() );
		$this->assertSame( array( $package ), array_column( $list, 'id' ) );
		$detail = wp_get_ability( 'leitvia/get-package' )->execute( array( 'id' => $package ) );
		$this->assertSame( 'Agent text', $detail['items'][0]['diff'][0]['after_text'] );

		self::settings( 'autonomous' );
		$report = $apply->execute( array( 'id' => $package ) );
		$this->assertIsArray( $report, is_wp_error( $report ) ? wp_json_encode( $report->get_error_data() ) : '' );
		$this->assertSame( 'applied', $report['state'] );
		$this->assertStringContainsString( 'Agent text', get_post( $post )->post_content );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertSame( 'leitvia_forbidden', wp_get_ability( 'leitvia/get-package' )->execute( array( 'id' => $package ) )->get_error_code() );
		$this->assertSame( array(), wp_get_ability( 'leitvia/list-packages' )->execute( array() ) );

		wp_set_current_user( $this->agent );
		self::settings( 'draft' );
		$this->assertSame( 'leitvia_needs_human', wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $package ) )->get_error_code() );
		self::settings( 'autonomous' );
		$rolled = wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $package ) );
		$this->assertSame( 'rolled_back', $rolled['state'] );
		$this->assertStringContainsString( 'Old', get_post( $post )->post_content );
	}

	/**
	 * REST routes of the inbox: edit_theme_options only; list, detail, apply with a selection, reject.
	 */
	public function test_rest(): void {
		$post    = $this->post();
		$package = $this->queue( $post, array( 'post_content' => self::paragraph( 'Via REST' ) ) );
		do_action( 'rest_api_init', rest_get_server() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/packages' ) )->get_status() );

		wp_set_current_user( $this->admin );
		$list = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/packages' ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( $package, array_column( $list->get_data(), 'id' ) );
		$this->assertSame( 1, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/packages/' . $package ) )->get_data()['operations']['open'] );

		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/packages/' . $package . '/apply' );
		$request->set_body_params( array( 'operations' => array( 1 ) ) );
		$applied = rest_do_request( $request );
		$this->assertSame( 200, $applied->get_status(), wp_json_encode( $applied->get_data() ) );
		$this->assertSame( 'applied', $applied->get_data()['state'] );
		$this->assertSame( 409, rest_do_request( new WP_REST_Request( 'POST', '/leitvia-studio/v1/packages/' . $package . '/reject' ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'POST', '/leitvia-studio/v1/packages/' . $package . '/rollback' ) )->get_status() );
	}

	/**
	 * A recipe at "suggest": child pages and the front page wait until the package is applied; the next run adds them.
	 */
	public function test_recipe_waits_for_applied_package(): void {
		$recipe = wp_get_ability( 'leitvia/apply-recipe' );
		$first  = $recipe->execute( array( 'recipe' => 'leitvia/documentation' ) );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$waiting = array_values( array_filter( $first['steps'], static fn( array $step ): bool => 'waiting' === $step['status'] ) );
		// The front page setting goes into the package with its page; only the child pages wait for their parent.
		$this->assertSame( array( 'parent_missing', 'parent_missing' ), array_column( $waiting, 'code' ) );

		wp_set_current_user( $this->admin );
		$report = Packages::apply( $first['package'] );
		$this->assertIsArray( $report );
		$this->assertSame( array(), $report['conflicts'], wp_json_encode( $report ) );
		$this->assertNotNull( get_page_by_path( 'docs' ) );

		wp_set_current_user( $this->agent );
		$second = $recipe->execute( array( 'recipe' => 'leitvia/documentation' ) );
		$this->assertNotContains( 'waiting', array_column( $second['steps'], 'status' ) );
		$this->assertNotSame( $first['package'], $second['package'] );
		$children = array_filter( $second['steps'], static fn( array $step ): bool => in_array( $step['target']['slug'] ?? '', array( 'getting-started', 'configuration' ), true ) );
		$this->assertSame( array( 'package', 'package' ), array_values( array_column( $children, 'status' ) ) );

		wp_set_current_user( $this->admin );
		Packages::apply( $second['package'] );
		$this->assertSame( get_page_by_path( 'home' )->ID, (int) get_option( 'page_on_front' ) );
		$this->assertSame( get_page_by_path( 'docs' )->ID, get_page_by_path( 'docs/getting-started' )->post_parent );
	}
}
