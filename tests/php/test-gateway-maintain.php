<?php
/**
 * Gateway maintenance abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Build;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;

/**
 * Abilities update-content, repair-links, set-alt-text and replace-pattern through the pipeline, each live write
 * undone with leitvia/rollback.
 */
class Test_Gateway_Maintain extends WP_UnitTestCase {

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Leitvia theme, agent user, standard profile and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $this->agent );
		update_option( Gateway::OPTION, Gateway::DEFAULTS );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities, the category and a test contract.
	 */
	public function tear_down(): void {
		self::unregister();
		remove_all_filters( 'leitvia_studio_contract_path' );
		remove_all_filters( 'pre_http_request' );
		\LeitviaStudio\Plugin::forget_contract();
		\LeitviaStudio\Lab\Status::flush();
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
	 * Runs an ability with the current hash of the post.
	 *
	 * @param string               $name  Ability name.
	 * @param int                  $post  Post whose hash is the base hash.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function call( string $name, int $post, array $input ) {
		return wp_get_ability( $name )->execute( $input + array( 'base_hash' => Hashes::document( $post ) ) );
	}

	/**
	 * Asserts a result without error and returns it.
	 *
	 * @param mixed $result Result.
	 * @return array<string, mixed>
	 */
	private function assert_ok( $result ): array {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		return $result;
	}

	/**
	 * Asserts a rejection and returns its data.
	 *
	 * @param string $code   Code.
	 * @param mixed  $result Result.
	 * @return array<string, mixed>
	 */
	private function assert_rejected( string $code, $result ): array {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_data();
	}

	/**
	 * Rolls back the package of a live write as the agent and asserts it worked.
	 *
	 * @param array<string, mixed> $result Result of the write.
	 */
	private function roll_back( array $result ): void {
		$this->assertSame( 'live', $result['mode'] );
		$this->assertGreaterThan( 0, $result['snapshot'] );
		$this->assertSame( 'applied', Packages::state( $result['package'] ) );
		$rolled = $this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertSame( 'rolled_back', $rolled['state'] );
	}

	/**
	 * A published page by an editor.
	 *
	 * @param string $content Block markup.
	 */
	private function page( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
				'post_excerpt' => 'Original excerpt',
				'post_name'    => 'original',
				'post_content' => wp_slash( $content ),
				'post_author'  => self::factory()->user->create( array( 'role' => 'editor' ) ),
				'post_date'    => '2026-01-02 03:04:05',
			)
		);
	}

	/**
	 * Paragraph markup.
	 *
	 * @param string $html Inner HTML.
	 */
	private static function paragraph( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Title, excerpt and two blocks in one operation: content area, a draft-level change of a published page becomes a
	 * package; at "autonomous" it goes live keeping author and date and rolls back. A slug change is at most a
	 * suggestion even then.
	 */
	public function test_update_content(): void {
		$page   = $this->page( self::paragraph( 'First' ) . "\n\n" . self::paragraph( 'Second' ) );
		$author = (int) get_post_field( 'post_author', $page );
		$input  = array(
			'post'    => $page,
			'title'   => 'New "title"',
			'excerpt' => 'New excerpt',
			'blocks'  => array(
				array(
					'block' => 'p:0',
					'text'  => 'One',
				),
				array(
					'block' => 'p:1',
					'text'  => 'Two',
				),
			),
		);
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/update-content', $page, array( 'post' => $page ) ) );
		$package = $this->assert_ok( self::call( 'leitvia/update-content', $page, $input ) );
		$this->assertSame( array( 'package', 'draft' ), array( $package['mode'], $package['level'] ) );
		$this->assertSame( 'Original title', get_the_title( $page ) );

		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$live = $this->assert_ok( self::call( 'leitvia/update-content', $page, $input ) );
		$post = get_post( $page );
		$this->assertSame( array( 'New "title"', 'New excerpt' ), array( $post->post_title, $post->post_excerpt ) );
		$this->assertStringContainsString( '<p>One</p>', $post->post_content );
		$this->assertStringContainsString( '<p>Two</p>', $post->post_content );
		$this->assertSame( array( $author, '2026-01-02 03:04:05' ), array( (int) $post->post_author, $post->post_date ), 'the update keeps author and date' );
		$this->roll_back( $live );
		$this->assertSame( 'Original title', get_the_title( $page ) );
		$this->assertStringContainsString( '<p>First</p>', get_post_field( 'post_content', $page ) );

		$slug = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post' => $page,
					'slug' => 'renamed',
				)
			)
		);
		$this->assertSame( array( 'package', 'suggest' ), array( $slug['mode'], $slug['level'] ) );
		$this->assertContains( 'slugs', $slug['kinds'] );
		$this->assertSame( 'original', get_post_field( 'post_name', $page ) );
	}

	/**
	 * The template of a page changes to another page template of the contract, live at "autonomous" and back with the
	 * rollback; slugs outside the contract and templates of another type (single for a page, page for a post) are
	 * refused.
	 */
	public function test_update_content_template(): void {
		$page = $this->page( '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Welcome</h1><!-- /wp:heading -->' . self::paragraph( 'Text' ) );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$invalid = array( array( $page, 'page-unknown' ), array( $page, 'single' ), array( $post, 'page-landing' ), array( $post, 'single-product' ), array( $page, 'single-product' ) );
		if ( ! class_exists( 'WooCommerce' ) ) {
			$invalid[] = array( $page, 'page-cart' );
		}
		foreach ( $invalid as [ $id, $template ] ) {
			$data = $this->assert_rejected(
				'leitvia_invalid_input',
				self::call(
					'leitvia/update-content',
					$id,
					array(
						'post'     => $id,
						'template' => $template,
					)
				)
			);
			$this->assertSame( 'template', $data['reason'], $template );
		}
		$this->assertSame( '', get_page_template_slug( $page ) );

		$live = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'     => $page,
					'template' => 'page-landing',
				)
			)
		);
		$this->assertSame( 'page-landing', get_page_template_slug( $page ) );
		$this->roll_back( $live );
		$this->assertSame( '', get_page_template_slug( $page ) );

		// Back to the default template, which shows the title as h1: the h1 of the landing page is refused there, a page
		// whose text starts at h2 goes back.
		update_post_meta( $page, '_wp_page_template', 'page-landing' );
		$data = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'     => $page,
					'template' => 'default',
				)
			)
		);
		$this->assertContains( 'leitvia/headings', array_column( $data['findings'], 'check' ) );
		delete_post_meta( $page, '_wp_page_template' );
		$plain = $this->page( '<!-- wp:heading --><h2 class="wp-block-heading">Welcome</h2><!-- /wp:heading -->' . self::paragraph( 'Text' ) );
		foreach ( array( 'default', '' ) as $reset ) {
			update_post_meta( $plain, '_wp_page_template', 'page-landing' );
			$this->assert_ok(
				self::call(
					'leitvia/update-content',
					$plain,
					array(
						'post'     => $plain,
						'template' => $reset,
					)
				)
			);
			$this->assertSame( '', get_page_template_slug( $plain ), "reset with '$reset'" );
		}
		$this->assert_ok(
			self::call(
				'leitvia/update-content',
				$post,
				array(
					'post'     => $post,
					'template' => 'single',
				)
			)
		);
		$this->assertSame( 'single', get_page_template_slug( $post ) );

		// At the level draft the switch of a published page waits in a package.
		update_option( Gateway::OPTION, Gateway::DEFAULTS );
		$package = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'     => $page,
					'template' => 'page-landing',
				)
			)
		);
		$this->assertSame( 'package', $package['mode'] );
		$this->assertSame( '', get_page_template_slug( $page ) );
		$this->assertSame( 'page-landing', Packages::operations( $package['package'] )[0]['state']['meta_input']['_wp_page_template'] );
	}

	/**
	 * Hashes from before the template joined the state of content types still match: a package applied before the
	 * update rolls back and an old base hash is accepted; a later change still is a conflict.
	 */
	public function test_hash_without_template_still_matches(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$legacy = static fn( int $post ): string => ( new ReflectionMethod( Hashes::class, 'post' ) )->invoke( null, $post, true );
		$age    = static function ( array $result ) use ( $legacy ): void {
			$operations                       = Packages::operations( $result['package'] );
			$operations[0]['applied']['hash'] = $legacy( (int) $result['target']['post'] );
			( new ReflectionMethod( Packages::class, 'save' ) )->invoke( null, $result['package'], $operations );
		};
		$page   = $this->page( self::paragraph( 'Text' ) );
		$this->assertNotSame( Hashes::document( $page ), $legacy( $page ) );
		$live = $this->assert_ok(
			wp_get_ability( 'leitvia/update-content' )->execute(
				array(
					'post'      => $page,
					'title'     => 'Changed',
					'base_hash' => $legacy( $page ),
				)
			)
		);
		$age( $live );
		$this->roll_back( $live );
		$this->assertSame( 'Original title', get_the_title( $page ) );

		$live = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'  => $page,
					'title' => 'Changed again',
				)
			)
		);
		$age( $live );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_excerpt' => 'Changed by a person',
			)
		);
		$this->assert_rejected( 'leitvia_rollback_conflict', wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $live['package'] ) ) );
		$this->assertSame( 'Changed again', get_the_title( $page ) );

		// An old hash cannot see the template: a write that changes it with one is stale, one that keeps it is not.
		$switch = array(
			'post'      => $page,
			'template'  => 'page-landing',
			'base_hash' => $legacy( $page ),
		);
		$this->assert_rejected( 'leitvia_stale_hash', wp_get_ability( 'leitvia/update-content' )->execute( $switch ) );
		$this->assertSame( '', get_page_template_slug( $page ) );
		$this->assert_ok( wp_get_ability( 'leitvia/update-content' )->execute( array( 'template' => '' ) + $switch ) );
	}

	/**
	 * A block edit of update-content keeps the one link of a text around link_text, as update-block does; without it a
	 * text that drops the link is refused.
	 */
	public function test_update_content_keeps_link_with_link_text(): void {
		$page = $this->page( self::paragraph( 'Write to <a href="mailto:old@example.org">old@example.org</a> today.' ) );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$edit = array(
			'block' => 'p:0',
			'text'  => 'Mail new@example.org any time.',
		);

		$data = $this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'   => $page,
					'blocks' => array( $edit ),
				)
			)
		);
		$this->assertSame( 'link_text', $data['reason'] );

		$live = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'   => $page,
					'blocks' => array(
						$edit + array(
							'link_text' => 'new@example.org',
							'url'       => 'mailto:new@example.org',
						),
					),
				)
			)
		);
		$this->assertStringContainsString( '<p>Mail <a href="mailto:new@example.org">new@example.org</a> any time.</p>', get_post_field( 'post_content', $page ) );
		$this->roll_back( $live );
	}

	/**
	 * Core asks manage_privacy_options to edit the privacy policy page, which the agent role lacks: a change without a
	 * package key is forbidden, with a key it is a proposal in the package, and an administrator applies it.
	 */
	public function test_update_content_proposes_a_change_of_the_privacy_page(): void {
		$page = $this->page( self::paragraph( 'Placeholder' ) );
		update_option( 'wp_page_for_privacy_policy', $page );
		$this->assertFalse( current_user_can( 'edit_post', $page ) );
		$input = array(
			'post'   => $page,
			'blocks' => array(
				array(
					'block' => 'p:0',
					'text'  => 'We keep no logs.',
				),
			),
		);
		$this->assert_rejected( 'leitvia_forbidden', self::call( 'leitvia/update-content', $page, $input ) );

		$result = $this->assert_ok( self::call( 'leitvia/update-content', $page, $input + array( 'package' => 'privacy-2026' ) ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertStringContainsString( 'Placeholder', get_post_field( 'post_content', $page ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$report = Packages::apply( $result['package'] );
		$this->assertSame( 'applied', $report['state'], wp_json_encode( $report ) );
		$this->assertStringContainsString( '<p>We keep no logs.</p>', get_post_field( 'post_content', $page ) );
		delete_option( 'wp_page_for_privacy_policy' );
	}

	/**
	 * Content replaces classic content whole and rolls back; it may not come with blocks, and it may hold no classic
	 * block, raw HTML or Custom HTML even though the old content was classic.
	 */
	public function test_update_content_replaces_classic_content(): void {
		$classic = "<p>Old text</p>\n\n<img src=\"https://example.org/a.jpg\" alt=\"A loaf\">";
		$page    = $this->page( $classic );
		$blocks  = self::paragraph( 'New text' );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );

		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'    => $page,
					'content' => $blocks,
					'blocks'  => array(
						array(
							'block' => 'p:0',
							'text'  => 'One',
						),
					),
				)
			)
		);
		foreach ( array(
			array( 'classic_block', $blocks . "\n<p>Left over</p>" ),
			array( 'classic_block', '<p>Only raw HTML</p>' ),
			array( 'html_block', $blocks . '<!-- wp:html --><p>Custom</p><!-- /wp:html -->' ),
			array( 'markup', '<!-- wp:paragraph --><p onclick="x()">Hi</p><!-- /wp:paragraph -->' ),
		) as [ $reason, $content ] ) {
			$data = $this->assert_rejected(
				'leitvia_unsafe_content',
				self::call(
					'leitvia/update-content',
					$page,
					array(
						'post'    => $page,
						'content' => $content,
					)
				)
			);
			$this->assertSame( $reason, $data['reason'], $content );
		}

		$live = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'    => $page,
					'title'   => 'Migrated',
					'content' => $blocks,
				)
			)
		);
		$this->assertSame( 'Migrated', get_the_title( $page ) );
		$this->assertMatchesRegularExpression( '#^<!-- wp:paragraph \{"metadata":\{"leitviaId":"[a-z0-9]+"\}\} --><p>New text</p><!-- /wp:paragraph -->$#', get_post_field( 'post_content', $page ) );
		$this->roll_back( $live );
		$this->assertSame( $classic, get_post_field( 'post_content', $page ) );
	}

	/**
	 * Pairs and auto through old slugs repair internal links live as a correction; external links are reported and never
	 * requested; nothing to repair is refused with the report.
	 */
	public function test_repair_links(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				++$requests;
				return new WP_Error( 'blocked', 'No requests in this test.' );
			}
		);
		$moved = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'old-name',
			)
		);
		wp_update_post(
			array(
				'ID'        => $moved,
				'post_name' => 'new-name',
			)
		);
		$home    = home_url();
		$content = self::paragraph( '<a href="/old-name/#part">Moved article</a>, <a href="https://example.org/page">Example site</a>, <a href="' . $home . '/gone/">Gone page</a> and <a href="' . $home . '/about">About us</a>' );
		$page    = $this->page( $content );
		$before  = get_post_field( 'post_content', $page );

		$data = $this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/repair-links',
				$page,
				array(
					'post'  => $page,
					'links' => array(
						array(
							'from' => 'https://example.org/page',
							'to'   => '/x/',
						),
					),
				)
			)
		);
		$this->assertSame( 'external_link', $data['reason'] );

		$result = $this->assert_ok(
			self::call(
				'leitvia/repair-links',
				$page,
				array(
					'post'  => $page,
					'auto'  => true,
					'links' => array(
						array(
							'from' => '/about/',
							'to'   => '/team/',
						),
					),
				)
			)
		);
		$this->assertSame( 'autonomous', $result['level'] );
		$after = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'href="' . get_permalink( $moved ) . '#part"', $after );
		$this->assertStringContainsString( 'href="/team/"', $after );
		$this->assertStringContainsString( 'href="https://example.org/page"', $after );
		$this->assertCount( 2, $result['report']['repaired'] );
		$this->assertSame( array( 'https://example.org/page' ), $result['report']['external'] );
		$this->assertSame( array( $home . '/gone/' ), $result['report']['unresolved'] );

		$data = $this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/repair-links',
				$page,
				array(
					'post' => $page,
					'auto' => true,
				)
			)
		);
		$this->assertSame( 'nothing_to_repair', $data['reason'] );
		$this->assertSame( array( 'https://example.org/page' ), $data['report']['external'] );

		$this->roll_back( $result );
		$this->assertSame( $before, get_post_field( 'post_content', $page ) );
		$this->assertSame( 0, $requests, 'no link is requested' );
	}

	/**
	 * The audit reports internal links without a target and links to the old address of a renamed post, once per
	 * link; posts, archives, files, the home page, the admin and external links pass. Nothing is requested.
	 */
	public function test_audit_reports_broken_links(): void {
		$this->set_permalink_structure( '/%postname%/' );
		// The test suite resets the rewrite rules without the taxonomy permastructs; core's tests add them back like this.
		create_initial_taxonomies();
		flush_rewrite_rules();
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				++$requests;
				return new WP_Error( 'blocked', 'No requests in this test.' );
			}
		);
		$moved = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'old-name',
			)
		);
		wp_update_post(
			array(
				'ID'        => $moved,
				'post_name' => 'new-name',
			)
		);
		$home  = home_url();
		$links = array(
			'<a href="/gone/">Gone page</a>',
			'<a href="' . $home . '/gone/">Gone again</a>',
			'<a href="/old-name/">Moved article</a>',
			'<a href="' . get_permalink( $moved ) . '">Article</a>',
			'<a href="/category/uncategorized/">News</a>',
			'<a href="/wp-content/uploads/menu.pdf">Menu as PDF</a>',
			'<a href="' . $home . '/">Home page</a>',
			'<a href="' . admin_url() . '">Dashboard</a>',
			'<a href="https://example.org/gone/">Example site</a>',
			'<a href="/gone/">Gone page twice</a>',
		);
		$page  = $this->page( self::paragraph( implode( ', ', $links ) ) );
		$items = array_column( \LeitviaStudio\Gateway\Abilities\Read_Audit::audit( 0, 20 )['items'], null, 'post' );
		$found = array_values( array_filter( $items[ $page ]['findings'], static fn( array $finding ): bool => 'leitvia/broken-link' === $finding['check'] ) );
		$this->assertSame(
			array(
				'The link /gone/ leads to no page of this site.',
				'The link ' . $home . '/gone/ leads to no page of this site.',
				'The link /old-name/ leads to the old address of ' . get_permalink( $moved ) . '.',
			),
			array_column( $found, 'message' )
		);
		$this->assertSame( array( 'warning' ), array_unique( array_column( $found, 'severity' ) ) );
		$this->assertSame( 0, $requests, 'no link is requested' );
	}

	/**
	 * Alt text on the attachment (hash, snapshot and rollback include it, the MIME type stays) and on the image block
	 * that shows it; file names fail the alt text check, another image's block is refused.
	 */
	public function test_set_alt_text(): void {
		$image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$hash  = Hashes::document( $image );

		$this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/set-alt-text',
				$image,
				array(
					'image' => $image,
					'alt'   => 'avatar.png',
				)
			)
		);
		$result = $this->assert_ok(
			self::call(
				'leitvia/set-alt-text',
				$image,
				array(
					'image' => $image,
					'alt'   => 'Portrait of the author',
				)
			)
		);
		$this->assertSame( array( 'live', 'autonomous' ), array( $result['mode'], $result['level'] ) );
		$this->assertSame( 'Portrait of the author', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'image/png', get_post_mime_type( $image ) );
		$this->assertNotSame( $hash, Hashes::document( $image ), 'the alt text is part of the hash' );
		$this->roll_back( $result );
		$this->assertSame( '', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		$this->assertSame( $hash, Hashes::document( $image ) );

		$url  = wp_get_attachment_url( $image );
		$page = $this->page( '<!-- wp:image {"id":' . $image . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . $url . '" alt="" class="wp-image-' . $image . '"/></figure><!-- /wp:image -->' );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/set-alt-text',
				$page,
				array(
					'image' => $image,
					'alt'   => 'Portrait',
					'post'  => $page,
				)
			)
		);
		$other = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$data  = $this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/set-alt-text',
				$page,
				array(
					'image' => $other,
					'alt'   => 'Portrait',
					'post'  => $page,
					'block' => 'p:0',
				)
			)
		);
		$this->assertSame( 'image', $data['reason'] );

		$block = $this->assert_ok(
			self::call(
				'leitvia/set-alt-text',
				$page,
				array(
					'image' => $image,
					'alt'   => 'Portrait of the author',
					'post'  => $page,
					'block' => 'p:0',
				)
			)
		);
		$this->assertSame( 'live', $block['mode'] );
		$this->assertStringContainsString( 'alt="Portrait of the author"', get_post_field( 'post_content', $page ) );
		$this->roll_back( $block );
		$this->assertStringContainsString( 'alt=""', get_post_field( 'post_content', $page ) );
	}

	/**
	 * Uses a contract in which cta-banner is deprecated in favour of the given replacement.
	 *
	 * @param string $replacement Replacement slug.
	 */
	private static function deprecate_banner( string $replacement ): void {
		remove_all_filters( 'leitvia_studio_contract_path' );
		add_filter(
			'leitvia_studio_contract_path',
			static function () use ( $replacement ): array {
				$file = get_temp_dir() . 'leitvia-maintain-contract.json';
				$data = wp_json_file_decode( get_theme_root() . '/leitvia/leitvia-contract.json', array( 'associative' => true ) );
				foreach ( $data['patterns'] as &$pattern ) {
					if ( 'leitvia/cta-banner' === $pattern['slug'] ) {
						$pattern['docs']['replacedBy'] = $replacement;
					}
				}
				file_put_contents( $file, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
				return array( $file );
			}
		);
		\LeitviaStudio\Plugin::forget_contract();
		\LeitviaStudio\Lab\Status::flush();
	}

	/**
	 * A deprecated section becomes its replacement with patternName, texts and button link carried over in order per
	 * block type; other sections stay; a missing replacement or no deprecated section is leitvia_no_replacement.
	 */
	public function test_replace_pattern(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$banner = Build::pattern( 'leitvia/cta-banner' );
		$banner = Build::edit(
			$banner['content'],
			array(
				array(
					'block' => 'p:0.0.0.1',
					'text'  => 'Our heading',
				),
				array(
					'block' => 'p:0.0.1.1.0',
					'text'  => 'Get in touch',
					'url'   => '/contact/',
				),
			),
			array()
		);
		$this->assertIsString( $banner );
		$intro = self::paragraph( 'Intro text' );
		$page  = $this->page( $intro . "\n\n" . Build::name_sections( $banner, 'leitvia/cta-banner' ) );

		$this->assert_rejected( 'leitvia_no_replacement', self::call( 'leitvia/replace-pattern', $page, array( 'post' => $page ) ) );
		self::deprecate_banner( 'leitvia/does-not-exist' );
		$data = $this->assert_rejected( 'leitvia_no_replacement', self::call( 'leitvia/replace-pattern', $page, array( 'post' => $page ) ) );
		$this->assertSame( 'leitvia/cta-banner', $data['pattern'] );
		self::deprecate_banner( 'leitvia/cta-split' );
		$this->assert_rejected(
			'leitvia_no_replacement',
			self::call(
				'leitvia/replace-pattern',
				$page,
				array(
					'post'  => $page,
					'block' => 'p:0',
				)
			)
		);

		$result = $this->assert_ok( self::call( 'leitvia/replace-pattern', $page, array( 'post' => $page ) ) );
		$this->assertSame( 'live', $result['mode'] );
		$blocks = array_values( array_filter( parse_blocks( get_post_field( 'post_content', $page ) ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertSame( 'Intro text', trim( wp_strip_all_tags( $blocks[0]['innerHTML'] ) ) );
		$this->assertSame( 'leitvia/cta-split', $blocks[1]['attrs']['metadata']['patternName'] );
		$markup = serialize_block( $blocks[1] );
		$this->assertStringContainsString( 'Our heading</h2>', $markup );
		$this->assertStringContainsString( 'Taking on two projects for spring', $markup, 'the first paragraph moved over' );
		$this->assertStringContainsString( 'href="/contact/"', $markup );
		$this->assertStringContainsString( '>Get in touch</a>', $markup );
		$this->assertStringNotContainsString( 'Make your next website', $markup );

		$this->roll_back( $result );
		$this->assertStringContainsString( '"patternName":"leitvia/cta-banner"', get_post_field( 'post_content', $page ) );
	}
}
