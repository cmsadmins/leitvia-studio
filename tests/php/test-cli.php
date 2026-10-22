<?php
/**
 * WP-CLI commands wp leitvia ….
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Cli\Command;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Store;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * The command class called directly: user required for writes, writes through the abilities with audit entries,
 * package export and import with hash manifest and embedded media, the audit following its cursor, exit codes and
 * JSON rejections.
 */
class Test_Cli extends WP_UnitTestCase {

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
	 * Temporary files.
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Leitvia theme, the abilities handed to core, every area at "suggest".
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::settings( 'suggest' );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes abilities, category and files.
	 */
	public function tear_down(): void {
		self::unregister();
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
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
	 * One level for content, structure and design, every content kind unlocked.
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
	 * Runs a command.
	 *
	 * @param string               $method Method of the command class.
	 * @param string[]             $args   Positional arguments.
	 * @param array<string, mixed> $assoc  Options.
	 * @return array{0: int, 1: list<string>, 2: string} Exit code, printed lines, error message.
	 */
	private static function cli( string $method, array $args = array(), array $assoc = array() ): array {
		WP_CLI::$out = array();
		try {
			( new Command() )->$method( $args, $assoc );
		} catch ( Leitvia_Studio_Cli_Halt $halt ) {
			return array( $halt->getCode(), WP_CLI::$out, $halt->getMessage() );
		}
		return array( 0, WP_CLI::$out, '' );
	}

	/**
	 * A temporary JSON file.
	 *
	 * @param mixed $data Data.
	 */
	private function file( $data = null ): string {
		$file          = wp_tempnam( 'leitvia-cli' );
		$this->files[] = $file;
		if ( null !== $data ) {
			file_put_contents( $file, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test.
		}
		return $file;
	}

	/**
	 * Audit entries of a user for an ability.
	 *
	 * @param int    $user    User ID.
	 * @param string $ability Ability.
	 */
	private static function audited( int $user, string $ability ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::table( 'audit' ) . ' WHERE user_id = %d AND ability = %s', $user, $ability ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
	}

	/**
	 * A published post.
	 *
	 * @param string $content Content.
	 */
	private function post( string $content = '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => wp_slash( $content ),
			)
		);
	}

	/**
	 * Writes stop without a user and say so; nothing is audited for user 0. With a user they run through the ability
	 * and leave audit entries.
	 */
	public function test_writes_need_a_user(): void {
		wp_set_current_user( 0 );
		$patterns = $this->file(
			array(
				array(
					'title'   => 'CLI pattern',
					'content' => '<!-- wp:paragraph --><p>From the shell</p><!-- /wp:paragraph -->',
				),
			)
		);
		$model    = $this->file( array( 'postTypes' => array() ) );
		$package  = $this->file( array( 'operations' => array() ) );
		$before   = self::audited( 0, 'leitvia/create-pattern' );
		foreach ( array(
			array( 'tokens_activate', array( '1' ) ),
			array( 'tokens_import', array( $model ) ),
			array( 'patterns_import', array( $patterns ) ),
			array( 'model_apply', array( $model ) ),
			array( 'audit', array() ),
			array( 'package_apply', array( '1' ) ),
			array( 'package_reject', array( '1' ) ),
			array( 'package_rollback', array( '1' ) ),
			array( 'package_export', array( '1', $this->file() ) ),
			array( 'package_import', array( $package ) ),
			array( 'recipe_apply', array( 'leitvia/blog' ) ),
		) as [ $method, $args ] ) {
			[ $code, , $message ] = self::cli( $method, $args );
			$this->assertSame( 1, $code, $method );
			$this->assertStringContainsString( Command::NO_USER, $message, $method );
		}
		$this->assertSame( $before, self::audited( 0, 'leitvia/create-pattern' ) );

		wp_set_current_user( $this->admin );
		[ $code, $out ] = self::cli( 'patterns_import', array( $patterns ), array( 'format' => 'json' ) );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$rows = json_decode( $out[0], true );
		$this->assertSame( 'CLI pattern', $rows[0]['title'] );
		$this->assertContains( $rows[0]['result'], array( 'live', 'draft', 'package' ) );
		$this->assertSame( 1, self::audited( $this->admin, 'leitvia/create-pattern' ) );
	}

	/**
	 * Export writes the manifest (objects with type, id and base hash, operations, created, studio version) and embeds
	 * the file of pending media. Import creates an open package with the file in the private area again; after a person
	 * changed a target it refuses with the conflict list and creates nothing.
	 */
	public function test_package_export_and_import(): void {
		$post = $this->post();
		$base = Hashes::document( $post );
		wp_set_current_user( $this->agent );
		$queued = Pipeline::run( new Operation( 'leitvia/update-content', 'content', array( 'post' => $post ), $base, static fn( array $state ): array => array( 'post_content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' ) + $state, array(), 'cli' ) );
		$this->assertSame( 'package', $queued['mode'] );
		$png   = base64_encode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/avatar.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
		$media = wp_get_ability( 'leitvia/add-media' )->execute(
			array(
				'data'      => $png,
				'filename'  => 'portrait.png',
				'alt'       => 'Portrait of the author',
				'base_hash' => '',
				'package'   => 'cli',
			)
		);
		$this->assertIsArray( $media, is_wp_error( $media ) ? wp_json_encode( array( $media->get_error_code(), $media->get_error_data() ) ) : '' );
		$this->assertSame( $queued['package'], $media['package'] );
		$original = Packages::operations( $queued['package'] )[1]['state']['file'];

		wp_set_current_user( $this->admin );
		$file           = $this->file();
		[ $code, $out ] = self::cli( 'package_export', array( (string) $queued['package'], $file ) );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$export = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		$this->assertSame( array( 'objects', 'operations', 'created', 'studio_version', 'title' ), array_keys( $export ) );
		$this->assertSame(
			array(
				array(
					'type'      => 'post',
					'id'        => $post,
					'base_hash' => $base,
				),
			),
			$export['objects']
		);
		$this->assertCount( 2, $export['operations'] );
		$this->assertSame( LEITVIA_STUDIO_VERSION, $export['studio_version'] );
		$this->assertArrayNotHasKey( 'file', $export['operations'][1]['state'] );
		$this->assertSame( 'portrait.png', $export['operations'][1]['media']['name'] );
		$this->assertStringNotContainsString( Packages::PRIVATE_DIR, (string) wp_json_encode( $export ) );

		[ $code, $out ] = self::cli( 'package_import', array( $file ), array( 'format' => 'json' ) );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$imported = json_decode( $out[0], true );
		$this->assertSame( 2, $imported['operations'] );
		$this->assertSame( 'open', Packages::state( $imported['package'] ) );
		$this->assertSame( $this->admin, (int) get_post_field( 'post_author', $imported['package'] ) );
		$operations = Packages::operations( $imported['package'] );
		$this->assertSame( $base, $operations[0]['base_hash'] );
		$this->assertFileExists( $operations[1]['state']['file'] );
		$this->assertNotSame( $original, $operations[1]['state']['file'] );
		$this->assertSame( 1, self::audited( $this->admin, 'cli/package-import' ) );

		// Applying runs through leitvia/apply-package; at "autonomous" both operations are written.
		self::settings( 'autonomous' );
		[ $code, $out ] = self::cli( 'package_apply', array( (string) $imported['package'] ), array( 'format' => 'json' ) );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$this->assertSame( array( 1, 2 ), json_decode( implode( "\n", $out ), true )['applied'] );
		$this->assertStringContainsString( 'New', get_post( $post )->post_content );
		$attachment = Packages::operations( $imported['package'] )[1]['applied']['post'];
		$this->assertFileExists( get_attached_file( $attachment ) );

		// The post changed since the export: the import refuses and creates nothing.
		$count          = count( get_posts( array( 'post_type' => Packages::POST_TYPE, 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => -1 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test.
		[ $code, $out ] = self::cli( 'package_import', array( $file ), array( 'format' => 'json' ) );
		$this->assertSame( 1, $code );
		$error = json_decode( implode( "\n", $out ), true );
		$this->assertSame( 'leitvia_import_conflict', $error['code'] );
		$this->assertSame( 409, $error['data']['status'] );
		$this->assertSame(
			array(
				array(
					'type'         => 'post',
					'id'           => $post,
					'base_hash'    => $base,
					'current_hash' => Hashes::document( $post ),
				),
			),
			$error['data']['conflicts']
		);
		$this->assertSame( $count, count( get_posts( array( 'post_type' => Packages::POST_TYPE, 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => -1 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test.
	}

	/**
	 * Import refuses operations a package could not hold, such as the rollback-only target key delete.
	 */
	public function test_import_refuses_invalid_operations(): void {
		wp_set_current_user( $this->admin );
		$post                 = $this->post();
		$file                 = $this->file(
			array(
				'operations' => array(
					array(
						'ability'   => 'leitvia/update-content',
						'area'      => 'content',
						'target'    => array(
							'post'   => $post,
							'delete' => true,
						),
						'base_hash' => Hashes::document( $post ),
						'kinds'     => array(),
						'state'     => array(),
					),
				),
			)
		);
		[ $code, , $message ] = self::cli( 'package_import', array( $file ) );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( '[leitvia_invalid_package]', $message );
	}

	/**
	 * Audit follows the cursor page by page to the end and sums the findings per check.
	 */
	public function test_audit_follows_the_cursor(): void {
		$image = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.org/a.jpg"/></figure><!-- /wp:image -->';
		$posts = array( $this->post( $image ), $this->post( $image ), $this->post( $image ) );
		wp_set_current_user( $this->admin );
		[ $code, $out ] = self::cli(
			'audit',
			array(),
			array(
				'limit'  => 1,
				'format' => 'json',
			)
		);
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$result = json_decode( implode( "\n", $out ), true );
		$this->assertEmpty( array_diff( $posts, array_column( $result['items'], 'post' ) ) );
		$this->assertSame( 1, self::audited( $this->admin, 'leitvia/audit-site' ), 'The command counts as one call.' );
		$this->assertGreaterThanOrEqual( 3, $result['summary']['leitvia/alt-text'] );
		$findings = array_sum( array_map( static fn( array $item ): int => count( $item['findings'] ), $result['items'] ) );
		$this->assertSame( $findings, array_sum( $result['summary'] ) );

		[ $code, $out ] = self::cli( 'audit', array(), array( 'after' => (string) $posts[1] ) );
		$this->assertSame( 0, $code );
		$table = json_decode( $out[0], true );
		$this->assertSame( array( 'post', 'type', 'title', 'findings' ), $table['fields'] );
		$this->assertNotContains( $posts[0], array_column( $table['items'], 'post' ) );
		$this->assertContains( $posts[2], array_column( $table['items'], 'post' ) );
		$this->assertSame( array( 'check', 'count' ), json_decode( $out[1], true )['fields'] );
	}

	/**
	 * A rejection exits with 1; with --format=json it prints code, message and the rejection data.
	 */
	public function test_rejection_exit_code_and_json(): void {
		wp_set_current_user( $this->admin );
		[ $code, $out ] = self::cli( 'recipe_apply', array( 'leitvia/none' ), array( 'format' => 'json' ) );
		$this->assertSame( 1, $code );
		$error = json_decode( implode( "\n", $out ), true );
		$this->assertSame( 'leitvia_unknown_recipe', $error['code'] );
		$this->assertSame( 404, $error['data']['status'] );
		$this->assertSame( 'leitvia/list-recipes', $error['data']['next_ability'] );

		[ $code, $out, $message ] = self::cli( 'package_show', array( '999999' ) );
		$this->assertSame( 1, $code );
		$this->assertSame( array(), $out );
		$this->assertStringContainsString( '[leitvia_unknown_package]', $message );
	}

	/**
	 * Reads of design data work without a user: recipes and patterns as table.
	 */
	public function test_reads_without_user(): void {
		wp_set_current_user( 0 );
		[ $code, $out ] = self::cli( 'recipe_list' );
		$this->assertSame( 0, $code );
		$this->assertContains( 'leitvia/blog', array_column( json_decode( $out[0], true )['items'], 'name' ) );
		[ $code, $out ] = self::cli( 'patterns_list', array(), array( 'format' => 'json' ) );
		$this->assertSame( 0, $code );
		$this->assertNotEmpty( json_decode( $out[0], true ) );
	}
}
