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
 * Abilities update-content, repair-links, set-alt-text, replace-pattern, trash-content and delete-media through the
 * pipeline, each live write undone with leitvia/rollback.
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
		return (array) $result->get_error_data();
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

		// In a network core maps manage_privacy_options to manage_network: only a super admin edits the privacy page.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
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
	 * requested; nothing to repair is refused with the report. Links without an address are listed under empty.
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
		$content = self::paragraph( '<a href="/old-name/#part">Moved article</a>, <a href="https://example.org/page">Example site</a>, <a href="' . $home . '/gone/">Gone page</a>, <a href="">Shipping</a> and <a href="' . $home . '/about">About us</a>' );
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
		$empty = array_values( wp_list_filter( $result['report']['empty'], array( 'text' => 'Shipping' ) ) );
		$this->assertCount( 1, $empty, 'a link without address is listed, from the content' );
		$this->assertSame( '', $empty[0]['href'] );
		$this->assertSame( 'content', $empty[0]['in'] );

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
		$this->assertNotEmpty( wp_list_filter( $data['report']['empty'], array( 'text' => 'Shipping' ) ) );

		$this->roll_back( $result );
		$this->assertSame( $before, get_post_field( 'post_content', $page ) );
		$this->assertSame( 0, $requests, 'no link is requested' );
	}

	/**
	 * The audit reports internal links without a target and links to the old address of a renamed post, once per
	 * link; posts, the posts page, archives, files, the home page, the admin and external links pass. Nothing is
	 * requested.
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
		// The posts page answers with the blog, so url_to_postid() gives 0 for it; it is a target all the same.
		$journal = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'journal',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', $journal );
		$home  = home_url();
		$links = array(
			'<a href="/journal/">Journal</a>',
			'<a href="' . $home . '/journal/page/2">Older posts</a>',
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
	 * A link a binding fills counts with its bound value, in the context of the block around it: the saved "#" of the
	 * button is no finding, the bound address that leads nowhere is.
	 */
	public function test_audit_checks_bound_links_with_the_bound_value(): void {
		$this->set_permalink_structure( '/%postname%/' );
		register_block_type(
			'test/posting',
			array(
				'attributes'       => array( 'applyUrl' => array( 'type' => 'string' ) ),
				'provides_context' => array( 'test/applyUrl' => 'applyUrl' ),
				'render_callback'  => static fn( array $attrs, string $content ): string => $content,
			)
		);
		register_block_bindings_source(
			'test/apply',
			array(
				'label'              => 'Apply',
				'uses_context'       => array( 'test/applyUrl' ),
				'get_value_callback' => static fn( array $args, WP_Block $block ): ?string => $block->context['test/applyUrl'] ?? null,
			)
		);
		$page  = $this->page( '<!-- wp:test/posting {"applyUrl":"/gone-bound/"} --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"test/apply"}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Apply for the position</a></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- /wp:test/posting -->' );
		$items = array_column( \LeitviaStudio\Gateway\Abilities\Read_Audit::audit( 0, 20 )['items'], null, 'post' );
		unregister_block_bindings_source( 'test/apply' );
		unregister_block_type( 'test/posting' );
		$messages = array_column( $items[ $page ]['findings'], 'message' );
		$this->assertContains( 'The link /gone-bound/ leads to no page of this site.', $messages );
		$this->assertNotContains( 'The link points to "#" and goes nowhere.', $messages );
	}

	/**
	 * The audit reports uneven motion: a strength other than the site setting, fade beside rise, mixed striking
	 * effects, too many striking or animated blocks. One motion with stagger and parallax passes, and nothing counts
	 * while the site setting is off.
	 */
	public function test_audit_reports_uneven_motion(): void {
		$block = static fn( string $attrs ): string => '<!-- wp:paragraph ' . $attrs . ' --><p>A</p><!-- /wp:paragraph -->';
		$calm  = $block( '{"leitviaAnimation":"fade"}' ) . $block( '{"leitviaAnimation":"stagger"}' ) . '<!-- wp:cover {"leitviaAnimation":"parallax"} --><div class="wp-block-cover"></div><!-- /wp:cover -->' . $block( '{"leitviaAnimation":"mask"}' );
		$this->assertSame( array(), \LeitviaStudio\Gateway\Abilities\Read_Audit::motion( $calm ) );

		$mixed = \LeitviaStudio\Gateway\Abilities\Read_Audit::motion( $block( '{"leitviaAnimation":"fade"}' ) . $block( '{"leitviaAnimation":"rise","leitviaMotion":"bold"}' ) . $block( '{"leitviaAnimation":"zoom"}' ) . $block( '{"leitviaAnimation":"blur"}' ) );
		$this->assertSame(
			array(
				'1 animated block has a strength of its own (leitviaMotion) instead of the site setting subtle, so its duration differs.',
				'Sections fade in and rise on the same page.',
				'The page mixes striking effects: zoom, blur.',
			),
			$mixed
		);
		$this->assertSame( array(), \LeitviaStudio\Gateway\Abilities\Read_Audit::motion( $block( '{"leitviaAnimation":"fade","leitviaMotion":"subtle"}' ) ), 'the strength of the site setting is no strength of its own' );
		$this->assertSame( array( 'Sections fade in and rise on the same page.' ), \LeitviaStudio\Gateway\Abilities\Read_Audit::motion( '<!-- wp:leitvia/reveal {"effect":"fade"} --><div class="wp-block-leitvia-reveal"></div><!-- /wp:leitvia/reveal -->' . $block( '{"leitviaAnimation":"rise"}' ) ), 'the reveal block counts by its effect' );

		$many = \LeitviaStudio\Gateway\Abilities\Read_Audit::motion( str_repeat( $block( '{"leitviaAnimation":"mask"}' ), 4 ) . str_repeat( $block( '{"leitviaAnimation":"fade"}' ), 3 ) );
		$this->assertSame( array( '4 blocks have a striking effect, more than 3.', '7 blocks are animated, more than 6.' ), $many );

		$page  = $this->page( str_repeat( $block( '{"leitviaAnimation":"mask"}' ), 7 ) );
		$items = array_column( \LeitviaStudio\Gateway\Abilities\Read_Audit::audit( 0, 20 )['items'], null, 'post' );
		$found = wp_list_filter( $items[ $page ]['findings'], array( 'check' => 'leitvia/motion' ) );
		$this->assertCount( 2, $found );
		$this->assertSame( array( 'warning' ), array_values( array_unique( array_column( $found, 'severity' ) ) ) );

		update_option( \LeitviaStudio\Admin\Settings::OPTION, array( 'motion' => 'off' ) + \LeitviaStudio\Admin\Settings::DEFAULTS );
		$this->assertSame( array(), \LeitviaStudio\Gateway\Abilities\Read_Audit::motion( str_repeat( $block( '{"leitviaAnimation":"mask"}' ), 7 ) ) );
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
	 * Runs set-alt-text with a list of items.
	 *
	 * @param list<array<string, mixed>> $items   Items.
	 * @param string                     $package Package key, '' for none.
	 * @return mixed
	 */
	private static function alt_texts( array $items, string $package = '' ) {
		return wp_get_ability( 'leitvia/set-alt-text' )->execute(
			array_filter(
				array(
					'items'   => $items,
					'package' => $package,
				)
			) + array( 'base_hash' => '' )
		);
	}

	/**
	 * Alt texts as a list: attachments and blocks in one call, two blocks of the same page on the hash read before the
	 * first write, a failing item refused alone; the items that went live share one package that rolls back all of them.
	 */
	public function test_set_alt_text_list(): void {
		$upload = static fn(): int => self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$first  = $upload();
		$second = $upload();
		$image  = static fn( int $id ): string => '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . wp_get_attachment_url( $id ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
		$page   = $this->page( $image( $first ) . $image( $second ) );
		$hash   = Hashes::document( $page );
		$result = $this->assert_ok(
			self::alt_texts(
				array(
					array(
						'image'     => $first,
						'alt'       => 'Portrait of the author',
						'base_hash' => Hashes::document( $first ),
					),
					array(
						'image'     => $second,
						'alt'       => 'avatar.png',
						'base_hash' => Hashes::document( $second ),
					),
					array(
						'image'     => $first,
						'alt'       => 'Portrait of the author',
						'post'      => $page,
						'block'     => 'p:0',
						'base_hash' => $hash,
					),
					array(
						'image'     => $second,
						'alt'       => 'Red bicycle leaning on a brick wall',
						'post'      => $page,
						'block'     => 'p:1',
						'base_hash' => $hash,
					),
				)
			)
		);
		$this->assertSame( 'live', $result['mode'] );
		$this->assertCount( 4, $result['items'] );
		$this->assertSame( 'leitvia_check_failed', $result['items'][1]['code'] );
		$this->assertSame( array( 'live', 'live', 'live' ), array( $result['items'][0]['mode'], $result['items'][2]['mode'], $result['items'][3]['mode'] ) );
		$this->assertSame( array( $result['package'] ), array_values( array_unique( array( $result['items'][0]['package'], $result['items'][2]['package'], $result['items'][3]['package'] ) ) ), 'one package' );
		$this->assertCount( 3, Packages::operations( $result['package'] ) );
		$this->assertSame( 'Portrait of the author', get_post_meta( $first, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $second, '_wp_attachment_image_alt', true ) );
		$content = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'alt="Portrait of the author"', $content );
		$this->assertStringContainsString( 'alt="Red bicycle leaning on a brick wall"', $content );

		$this->assertSame( 'applied', Packages::state( $result['package'] ) );
		$rolled = $this->assert_ok( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertCount( 3, $rolled['rolled_back'] );
		$this->assertSame( '', get_post_meta( $first, '_wp_attachment_image_alt', true ) );
		$this->assertSame( $hash, Hashes::document( $page ) );
	}

	/**
	 * At the level draft two items on the same draft both land: the second builds on the hash the first left.
	 */
	public function test_set_alt_text_list_on_a_draft(): void {
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array( 'corrections' => 'draft' ),
					'kinds' => array(),
				),
			) + Gateway::DEFAULTS
		);
		$upload = static fn(): int => self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$first  = $upload();
		$second = $upload();
		$image  = static fn( int $id ): string => '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . wp_get_attachment_url( $id ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
		$draft  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => wp_slash( $image( $first ) . $image( $second ) ),
			)
		);
		$hash   = Hashes::document( $draft );
		$item   = static fn( int $id, string $alt, string $block ): array => array(
			'image'     => $id,
			'alt'       => $alt,
			'post'      => $draft,
			'block'     => $block,
			'base_hash' => $hash,
		);
		$result = $this->assert_ok( self::alt_texts( array( $item( $first, 'Portrait of the author', 'p:0' ), $item( $second, 'Red bicycle leaning on a brick wall', 'p:1' ) ) ) );
		$this->assertSame( array( 'draft', 'draft' ), array_column( $result['items'], 'mode' ) );
		$content = get_post_field( 'post_content', $draft );
		$this->assertStringContainsString( 'alt="Portrait of the author"', $content );
		$this->assertStringContainsString( 'alt="Red bicycle leaning on a brick wall"', $content );
		update_option( Gateway::OPTION, Gateway::DEFAULTS );
	}

	/**
	 * A list with a package key waits in that one package; a list counts once for the rate limit; items and a single
	 * image exclude each other, and a list holds at most 100 items.
	 */
	public function test_set_alt_text_list_package_and_rate_limit(): void {
		$images = array_map( static fn(): int => self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 ), range( 1, 3 ) );
		$items  = array_map(
			static fn( int $id ): array => array(
				'image'     => $id,
				'alt'       => 'Trail sign number ' . $id . ' in the forest',
				'base_hash' => Hashes::document( $id ),
			),
			$images
		);
		$queued = $this->assert_ok( self::alt_texts( $items, 'alt-texts' ) );
		$this->assertSame( 'package', $queued['mode'] );
		$this->assertCount( 3, Packages::operations( $queued['package'] ) );
		$this->assertSame( '', get_post_meta( $images[0], '_wp_attachment_image_alt', true ) );
		Packages::reject( $queued['package'] );

		// The list with the key counted once; the next list and one single call fit into three writes a minute.
		update_option( Gateway::OPTION, array( 'rate_per_minute' => 3 ) + Gateway::DEFAULTS );
		$this->assert_ok( self::alt_texts( $items ) );
		$this->assert_ok(
			self::call(
				'leitvia/set-alt-text',
				$images[0],
				array(
					'image' => $images[0],
					'alt'   => 'Trail sign at the start of the path',
				)
			)
		);
		$this->assert_rejected( 'leitvia_rate_limited', self::alt_texts( $items ) );
		update_option( Gateway::OPTION, Gateway::DEFAULTS );

		$this->assertSame(
			'items',
			$this->assert_rejected(
				'leitvia_invalid_input',
				self::call(
					'leitvia/set-alt-text',
					$images[0],
					array(
						'items' => $items,
						'image' => $images[0],
						'alt'   => 'Sign',
					)
				)
			)['reason']
		);
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/set-alt-text', $images[0], array() ) );
		$this->assert_rejected( 'ability_invalid_input', self::alt_texts( array_fill( 0, 101, $items[0] ) ) );
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

	/**
	 * An older pattern with a successor in the contract (teaser-services, content-steps) switches with block and
	 * without pattern: the new section gets as many entries as the old one, head and entries take headings, texts,
	 * links with their address and the number of the step by role; sample texts of the successor without a
	 * counterpart go (section mark, closing line), numbers such as 01 have none. With pattern the same happens for an
	 * old section whose steps carry their title as eyebrow: it becomes the h3, an eyebrow that only counts stays and
	 * the h3 of that step goes. Audit-site names the successor in a notice.
	 */
	public function test_replace_pattern_moves_entries_to_the_successor(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$edits = array(
			array(
				'block' => 'p:0.0.0',
				'text'  => 'Our services',
			),
			array(
				'block' => 'p:0.0.1',
				'text'  => 'Four things we do.',
			),
		);
		foreach ( array( 'A', 'B', 'C', 'D' ) as $i => $letter ) {
			$entry   = 'p:0.1.' . intdiv( $i, 2 ) . '.' . ( $i % 2 );
			$edits[] = array(
				'block' => $entry . '.1',
				'text'  => 'Service ' . $letter,
			);
			$edits[] = array(
				'block' => $entry . '.2',
				'text'  => 'About service ' . $letter . '.',
			);
			$edits[] = array(
				'block'     => $entry . '.3',
				'text'      => 'More about ' . $letter,
				'link_text' => 'More about ' . $letter,
				'url'       => '/service-' . strtolower( $letter ) . '/',
			);
		}
		$services = Build::edit( Build::pattern( 'leitvia/teaser-services' )['content'], $edits, array() );
		$this->assertIsString( $services );
		$page = $this->page( self::paragraph( 'Intro text' ) . "\n\n" . Build::name_sections( $services, 'leitvia/teaser-services' ) );

		$audit = \LeitviaStudio\Gateway\Abilities\Read_Audit::audit( 0, 50 );
		$this->assertContains( 'leitvia/successor-pattern', array_column( array_merge( ...array_column( $audit['items'], 'findings' ) ), 'check' ) );
		$this->assert_rejected( 'leitvia_no_replacement', self::call( 'leitvia/replace-pattern', $page, array( 'post' => $page ) ), 'all sections: only deprecated ones' );
		$this->assert_ok(
			self::call(
				'leitvia/replace-pattern',
				$page,
				array(
					'post'  => $page,
					'block' => 'p:1',
				)
			)
		);
		$blocks = array_values( array_filter( parse_blocks( get_post_field( 'post_content', $page ) ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertSame( 'leitvia-studio/teaser-icon-areas', $blocks[1]['attrs']['metadata']['patternName'] );
		$markup = serialize_block( $blocks[1] );
		$this->assertStringContainsString( 'Our services</h2>', $markup );
		$this->assertStringContainsString( 'Four things we do.</p>', $markup );
		foreach ( array( 'A', 'B', 'C', 'D' ) as $letter ) {
			$this->assertStringContainsString( 'Service ' . $letter . '</h3>', $markup );
			$this->assertStringContainsString( 'About service ' . $letter . '.</p>', $markup );
			$this->assertStringContainsString( '<a href="/service-' . strtolower( $letter ) . '/">More about ' . $letter . '</a>', $markup );
		}
		$this->assertSame( 4, substr_count( $markup, '<!-- wp:leitvia/icon {"icon"' ), 'an icon per entry, no section mark' );
		$this->assertStringNotContainsString( 'is-style-eyebrow', $markup, 'the sample eyebrow of the section mark went' );
		$this->assertStringNotContainsString( '>01<', $markup );

		// Four numbered steps into the three of content-process-icons: a fourth one, counted labels, no closing line.
		$edits = array(
			array(
				'block' => 'p:0.0',
				'text'  => 'How we work',
			),
		);
		foreach ( array( 1, 2, 3, 4 ) as $i => $n ) {
			$entry   = 'p:0.1.' . intdiv( $i, 2 ) . '.' . ( $i % 2 );
			$edits[] = array(
				'block' => $entry . '.1',
				'text'  => 'Stage ' . $n,
			);
			$edits[] = array(
				'block' => $entry . '.2',
				'text'  => 'What stage ' . $n . ' does.',
			);
		}
		$steps = Build::edit( Build::pattern( 'leitvia/content-steps' )['content'], $edits, array() );
		$this->assertIsString( $steps );
		$page   = $this->page( Build::name_sections( $steps, 'leitvia/content-steps' ) );
		$result = $this->assert_ok(
			self::call(
				'leitvia/replace-pattern',
				$page,
				array(
					'post'  => $page,
					'block' => 'p:0',
				)
			)
		);
		$this->assertSame( 'live', $result['mode'], 'the copied step takes a spare icon, no repeated icon holds the write' );
		$markup = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( '"patternName":"leitvia-studio/content-process-icons"', $markup );
		$this->assertStringContainsString( '"icon":"circle-check"', $markup );
		$this->assertStringContainsString( 'How we work</h2>', $markup );
		foreach ( array( 1, 2, 3, 4 ) as $n ) {
			$this->assertStringContainsString( '>Step ' . $n . '</p>', $markup );
			$this->assertStringContainsString( 'Stage ' . $n . '</h3>', $markup );
			$this->assertStringContainsString( 'What stage ' . $n . ' does.</p>', $markup );
		}
		$this->assertSame( 4, substr_count( $markup, 'is-style-field-filled' ) );
		$this->assertStringNotContainsString( 'Ask for free advice', $markup, 'the closing line with a sample link went' );

		// Steps that carry their title as eyebrow, with pattern given.
		$entry = static fn( string $eyebrow, string $text ): string => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph {"className":"is-style-eyebrow"} --><p class="is-style-eyebrow">' . $eyebrow . '</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$old   = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull"><!-- wp:heading --><h2 class="wp-block-heading">Our way</h2><!-- /wp:heading --><!-- wp:group {"layout":{"type":"grid"}} --><div class="wp-block-group">' . $entry( 'Call us', 'We listen.' ) . $entry( 'Plan', 'We plan.' ) . $entry( 'Schritt 3', 'We build.' ) . '</div><!-- /wp:group --></div><!-- /wp:group -->';
		$page  = $this->page( $old );
		$this->assert_ok(
			self::call(
				'leitvia/replace-pattern',
				$page,
				array(
					'post'    => $page,
					'block'   => 'p:0',
					'pattern' => 'leitvia-studio/content-process-icons',
				)
			)
		);
		$markup = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'Call us</h3>', $markup );
		$this->assertStringContainsString( 'Plan</h3>', $markup );
		$this->assertStringContainsString( '>Step 1</p>', $markup );
		$this->assertStringContainsString( '>Schritt 3</p>', $markup, 'a label that only counts stays the eyebrow' );
		$this->assertSame( 2, substr_count( $markup, '</h3>' ), 'the step without a title has no h3' );
		$this->assertStringContainsString( 'We build.</p>', $markup );
	}

	/**
	 * A write whose save drops block attributes (here a filter in place of core's KSES reading broken JSON) fails with
	 * leitvia_attributes_lost, names block, position and keys, and leaves the page as it was; inner blocks count, added
	 * attributes such as the block IDs do not.
	 */
	public function test_lost_attributes_fail_the_write(): void {
		$this->assertSame( array(), \LeitviaStudio\Gateway\Pipeline::lost_attributes( '<!-- wp:paragraph {"align":"center"} --><p>A</p><!-- /wp:paragraph -->', '<!-- wp:paragraph {"align":"center","metadata":{"leitviaId":"par00001"}} --><p>A</p><!-- /wp:paragraph -->' ) );
		$this->assertSame(
			array(
				array(
					'block'      => 'core/paragraph',
					'index'      => 1,
					'attributes' => array( 'style.color' ),
				),
			),
			\LeitviaStudio\Gateway\Pipeline::lost_attributes(
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph {"style":{"color":{"text":"#000"},"typography":{"fontSize":"1rem"}}} --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph {"style":{"typography":{"fontSize":"1rem"}}} --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			)
		);

		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$page   = $this->page( self::paragraph( 'Before' ) );
		$before = get_post_field( 'post_content', $page );
		// Only the new text loses its attribute; restoring the stored one works.
		$drop = static function ( $content ) {
			if ( ! str_contains( (string) $content, 'After' ) ) {
				return $content;
			}
			$blocks = parse_blocks( wp_unslash( (string) $content ) );
			unset( $blocks[0]['attrs']['align'] );
			return wp_slash( serialize_blocks( $blocks ) );
		};
		add_filter( 'content_save_pre', $drop, 99 );
		$data = $this->assert_rejected(
			'leitvia_attributes_lost',
			self::call(
				'leitvia/update-content',
				$page,
				array(
					'post'    => $page,
					'content' => '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">After</p><!-- /wp:paragraph -->',
				)
			)
		);
		remove_filter( 'content_save_pre', $drop, 99 );
		$this->assertSame( 'attributes', $data['reason'] );
		$this->assertSame( array( 'align' ), $data['lost'][0]['attributes'] );
		$this->assertSame( $before, get_post_field( 'post_content', $page ) );
	}
	/**
	 * The field parent moves a page below another page: a URL change, so at most a suggestion until slugs are unlocked, then
	 * live and undone by a rollback. Itself, a subpage, another type and a post type without hierarchy are refused.
	 */
	public function test_update_content_parent(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$top    = $this->page( self::paragraph( 'Top' ) );
		$child  = $this->page( self::paragraph( 'Child' ) );
		$queued = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$child,
				array(
					'post'   => $child,
					'parent' => $top,
				)
			)
		);
		$this->assertSame( 'package', $queued['mode'] );
		$this->assertContains( 'slugs', $queued['kinds'] );
		$this->assertSame( 0, wp_get_post_parent_id( $child ) );

		update_option(
			Gateway::OPTION,
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		$result = $this->assert_ok(
			self::call(
				'leitvia/update-content',
				$child,
				array(
					'post'   => $child,
					'parent' => $top,
				)
			)
		);
		$this->assertSame( $top, wp_get_post_parent_id( $child ) );
		foreach ( array( $top, $child ) as $below ) {
			$data = $this->assert_rejected(
				'leitvia_invalid_input',
				self::call(
					'leitvia/update-content',
					$top,
					array(
						'post'   => $top,
						'parent' => $below,
					)
				)
			);
			$this->assertSame( 'parent', $data['reason'] );
		}
		$post = self::factory()->post->create();
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-content',
				$top,
				array(
					'post'   => $top,
					'parent' => $post,
				)
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-content',
				$post,
				array(
					'post'   => $post,
					'parent' => 0,
				)
			)
		);
		$this->roll_back( $result );
		$this->assertSame( 0, wp_get_post_parent_id( $child ) );
	}

	/**
	 * With pattern any top-level section gets another pattern: texts of the old section move over, edits apply after
	 * them, carry false keeps the texts of the pattern. Edits without pattern, a pattern without block and an unknown
	 * pattern are refused.
	 */
	public function test_replace_section_with_another_pattern(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$banner = Build::pattern( 'leitvia/cta-banner' );
		$banner = Build::edit(
			$banner['content'],
			array(
				array(
					'block' => 'p:0.0.0.1',
					'text'  => 'Our heading',
				),
			),
			array()
		);
		$this->assertIsString( $banner );
		$markup = self::paragraph( 'Intro text' ) . "\n\n" . Build::name_sections( $banner, 'leitvia/cta-banner' );
		$page   = $this->page( $markup );
		$swap   = static fn( array $input ): array => $input + array(
			'post'  => $page,
			'block' => 'p:1',
		);

		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/replace-pattern', $page, $swap( array( 'carry' => false ) ) ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/replace-pattern',
				$page,
				array(
					'post'    => $page,
					'pattern' => 'leitvia/cta-split',
				)
			)
		);
		$this->assert_rejected( 'leitvia_unknown_pattern', self::call( 'leitvia/replace-pattern', $page, $swap( array( 'pattern' => 'leitvia/does-not-exist' ) ) ) );

		$result = $this->assert_ok(
			self::call(
				'leitvia/replace-pattern',
				$page,
				$swap(
					array(
						'pattern' => 'leitvia/cta-split',
						'edits'   => array(
							array(
								'block'  => 'p:0',
								'anchor' => 'contact',
							),
							// The pattern links its button to "#", which the link check refuses.
							array(
								'block' => 'p:0.0.1.0.3.0',
								'url'   => '/contact/',
							),
						),
					)
				)
			)
		);
		$this->assertSame( 'live', $result['mode'] );
		$blocks = array_values( array_filter( parse_blocks( get_post_field( 'post_content', $page ) ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertCount( 2, $blocks );
		$this->assertSame( 'Intro text', trim( wp_strip_all_tags( $blocks[0]['innerHTML'] ) ) );
		$this->assertSame( 'leitvia/cta-split', $blocks[1]['attrs']['metadata']['patternName'] );
		$this->assertStringContainsString( 'Our heading</h2>', serialize_block( $blocks[1] ) );
		$this->assertStringContainsString( 'id="contact"', serialize_block( $blocks[1] ) );
		$this->roll_back( $result );

		$plain = $this->page( $markup );
		$this->assert_ok(
			self::call(
				'leitvia/replace-pattern',
				$plain,
				array(
					'post'    => $plain,
					'block'   => 'p:1',
					'pattern' => 'leitvia/cta-split',
					'carry'   => false,
					'edits'   => array(
						array(
							'block' => 'p:0.0.1.0.3.0',
							'url'   => '/contact/',
						),
					),
				)
			)
		);
		$this->assertStringNotContainsString( 'Our heading', get_post_field( 'post_content', $plain ) );
		$this->assertStringContainsString( '"patternName":"leitvia/cta-split"', get_post_field( 'post_content', $plain ) );
	}

	/**
	 * A section back into its own pattern with carry: a group of the pattern the old section has no counterpart for
	 * (the key figure of the timeline) goes as a whole instead of a rejection, and stays with the texts of edits that
	 * name its blocks by the path IDs of the pattern outline (leitvia/get-pattern).
	 */
	public function test_replace_pattern_restores_a_group_with_edits(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$slug    = 'leitvia-studio/content-process-timeline';
		$outline = \LeitviaStudio\Gateway\Abilities\Read_Design::pattern( $slug )['outline'];
		$this->assertSame( 'p:0.0.1.0', $outline[0]['children'][0]['children'][1]['children'][0]['id'], 'outline with the path IDs of the pattern' );
		$old = Build::edit(
			Build::pattern( $slug )['content'],
			array(
				array(
					'block' => 'p:0.0.0.1',
					'text'  => 'How the switch works',
				),
				array(
					'block'  => 'p:0.0.1',
					'remove' => true,
				),
			),
			array()
		);
		$this->assertIsString( $old );
		$this->assertStringNotContainsString( 'from review to switch-over', $old );
		$page  = $this->page( Build::name_sections( $old, $slug ) );
		$input = array(
			'post'    => $page,
			'block'   => 'p:0',
			'pattern' => $slug,
		);

		$result = $this->assert_ok( self::call( 'leitvia/replace-pattern', $page, $input ) );
		$markup = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'How the switch works</h2>', $markup );
		$this->assertStringNotContainsString( 'from review to switch-over', $markup, 'the key figure without counterpart goes as a whole' );
		$this->assertStringNotContainsString( '"name":"Key figure"', $markup );
		$this->roll_back( $result );

		$this->assert_ok(
			self::call(
				'leitvia/replace-pattern',
				$page,
				$input + array(
					'edits' => array(
						array(
							'block' => 'p:0.0.1.0',
							'text'  => '19 €',
						),
						array(
							'block' => 'p:0.0.1.1',
							'text'  => 'per mailbox for the move',
						),
					),
				)
			)
		);
		$markup = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( 'How the switch works</h2>', $markup, 'carry still moves the heading' );
		$this->assertStringContainsString( '>19 €</p>', $markup );
		$this->assertStringContainsString( '>per mailbox for the move</p>', $markup );
	}

	/**
	 * Insert-section puts a new section before, after, at the start or (without a place) at the end of the top-level
	 * sections and moves one of them; remove-section takes one out, but never the last. The rest of the content stays.
	 * Sections with move, two places and unknown IDs are refused.
	 */
	public function test_insert_move_and_remove_section(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$page  = $this->page( self::paragraph( 'First' ) . "\n\n" . self::paragraph( 'Last' ) );
		$quote = array(
			array(
				'pattern' => 'leitvia/content-quote',
				'edits'   => array(
					array(
						'block' => 'p:0.0',
						'text'  => 'Jane Roe, Walking Club',
					),
				),
			),
		);
		$order = static fn(): array => array_map(
			static fn( array $block ): string => (string) ( $block['attrs']['metadata']['patternName'] ?? trim( wp_strip_all_tags( $block['innerHTML'] ) ) ),
			array_values( array_filter( parse_blocks( get_post_field( 'post_content', $page ) ), static fn( array $block ): bool => null !== $block['blockName'] ) )
		);
		$call  = static fn( string $name, array $input ) => self::call( $name, $page, $input + array( 'post' => $page ) );

		$this->assert_rejected( 'leitvia_invalid_input', $call( 'leitvia/insert-section', array( 'position' => 'end' ) ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			$call(
				'leitvia/insert-section',
				array(
					'sections' => $quote,
					'move'     => 'p:0',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			$call(
				'leitvia/insert-section',
				array(
					'sections' => $quote,
					'after'    => 'p:0',
					'position' => 'end',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_not_found',
			$call(
				'leitvia/insert-section',
				array(
					'sections' => $quote,
					'after'    => 'p:7',
				)
			)
		);
		$this->assert_rejected( 'leitvia_unknown_pattern', $call( 'leitvia/insert-section', array( 'sections' => array( array( 'pattern' => 'leitvia/does-not-exist' ) ) ) ) );

		$result = $this->assert_ok(
			$call(
				'leitvia/insert-section',
				array(
					'sections' => $quote,
					'after'    => 'p:0',
				)
			)
		);
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( array( 'First', 'leitvia/content-quote', 'Last' ), $order() );
		$this->assertStringContainsString( 'Jane Roe, Walking Club', get_post_field( 'post_content', $page ) );

		$this->assert_ok(
			$call(
				'leitvia/insert-section',
				array(
					'move'   => 'p:2',
					'before' => 'p:1',
				)
			)
		);
		$this->assertSame( array( 'First', 'Last', 'leitvia/content-quote' ), $order() );
		$this->assert_ok(
			$call(
				'leitvia/insert-section',
				array(
					'move'     => 'p:2',
					'position' => 'start',
				)
			)
		);
		$this->assertSame( array( 'leitvia/content-quote', 'First', 'Last' ), $order() );

		$this->assert_rejected( 'leitvia_not_found', $call( 'leitvia/remove-section', array( 'block' => 'p:7' ) ) );
		$removed = $this->assert_ok( $call( 'leitvia/remove-section', array( 'block' => 'p:0' ) ) );
		$this->assertSame( array( 'First', 'Last' ), $order() );
		$this->roll_back( $removed );
		$this->assertSame( array( 'leitvia/content-quote', 'First', 'Last' ), $order() );

		$single = $this->page( self::paragraph( 'Only' ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/remove-section',
				$single,
				array(
					'post'  => $single,
					'block' => 'p:0',
				)
			)
		);
		$this->assert_ok(
			self::call(
				'leitvia/insert-section',
				$single,
				array(
					'post'     => $single,
					'sections' => $quote,
				)
			)
		);
		$blocks = array_values( array_filter( parse_blocks( get_post_field( 'post_content', $single ) ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertSame( 'leitvia/content-quote', $blocks[1]['attrs']['metadata']['patternName'] );
	}

	/**
	 * The faq of insert-section turns questions as heading, bold start or paragraph with a question mark and the blocks after
	 * them into one content-faq section at their place; answers keep code, italics and links, the id of a heading moves
	 * to its question, the sample texts of the pattern are gone. A range that starts with an answer is refused.
	 */
	public function test_insert_section_faq_from_blocks(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$page = $this->page(
			implode(
				"\n\n",
				array(
					self::paragraph( 'Intro' ),
					'<!-- wp:heading {"level":3} --><h3 class="wp-block-heading" id="how-long">How long does it take?</h3><!-- /wp:heading -->',
					self::paragraph( 'About <code>two</code> weeks, see <a href="/plan/">the plan</a>.' ),
					self::paragraph( '<strong>Can I pay later?</strong> Yes, <em>within 14 days</em>.' ),
					self::paragraph( 'What about support?' ),
					'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Mail</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
					self::paragraph( 'Outro' ),
				)
			)
		);
		$faq  = static fn( string $from ): array => array(
			'post' => $page,
			'faq'  => array(
				'from'    => $from,
				'to'      => 'p:5',
				'heading' => 'Questions',
			),
		);
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/insert-section', $page, $faq( 'p:2' ) ) );
		$this->assert_ok( self::call( 'leitvia/insert-section', $page, $faq( 'p:1' ) ) );
		$content = (string) get_post_field( 'post_content', $page );
		$blocks  = array_values( array_filter( parse_blocks( $content ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertCount( 3, $blocks );
		$this->assertSame( 'leitvia/content-faq', $blocks[1]['attrs']['metadata']['patternName'] );
		$this->assertSame( 3, substr_count( $content, '<!-- wp:details' ) );
		$this->assertStringContainsString( '<summary>How long does it take?</summary>', $content );
		$this->assertStringContainsString( '<summary>Can I pay later?</summary>', $content );
		$this->assertStringContainsString( '<summary>What about support?</summary>', $content );
		$this->assertStringContainsString( 'About <code>two</code> weeks, see <a href="/plan/">the plan</a>.', $content );
		$this->assertStringContainsString( '>Yes, <em>within 14 days</em>.</p>', $content );
		if ( block_has_support( WP_Block_Type_Registry::get_instance()->get_registered( 'core/details' ), 'anchor' ) ) {
			$this->assertStringContainsString( 'id="how-long"', $content );
		}
		$this->assertStringContainsString( '<h2 class="wp-block-heading has-heading-m-font-size">Questions</h2>', $content );
		$this->assertStringNotContainsString( 'How long does a typical project take', $content );
		$this->assertSame( 1, substr_count( $content, ' open>' ), 'the first question is open' );
		$this->assertStringContainsString( 'has-ink-muted-color', (string) $blocks[1]['innerBlocks'][0]['innerBlocks'][1]['innerBlocks'][0]['innerBlocks'][0]['innerHTML'] );
	}

	/**
	 * Taking out an h3 before its h4 does not stop the change half way: the h4 was there before, so its skipped level
	 * is a warning that names the heading and its level. A heading new in the write is no skip from above.
	 */
	public function test_remove_section_above_subheadings(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$heading = static fn( int $level, string $text ): string => '<!-- wp:heading {"level":' . $level . '} --><h' . $level . ' class="wp-block-heading">' . $text . '</h' . $level . '><!-- /wp:heading -->';
		$page    = $this->page( $heading( 2, 'Questions' ) . "\n\n" . $heading( 3, 'Billing' ) . "\n\n" . $heading( 4, 'Invoices' ) . "\n\n" . self::paragraph( 'Answer' ) );
		$removed = $this->assert_ok(
			self::call(
				'leitvia/remove-section',
				$page,
				array(
					'post'  => $page,
					'block' => 'p:1',
				)
			)
		);
		$this->assertSame( array( 'warning' ), array_column( $removed['findings'], 'severity' ) );
		$this->assertStringContainsString( 'h4 "Invoices" follows h2', $removed['findings'][0]['message'] );
		$this->assertNotSame( '', $removed['findings'][0]['block'], 'the finding names the block' );
		$this->assertStringContainsString( 'from the last to the first', $removed['findings'][0]['fix'] );
		$this->assertSame(
			array(),
			\LeitviaStudio\Gateway\Content_Checks::skips_from_above( '', $heading( 2, 'A' ) . $heading( 4, 'New' ) ),
			'a heading that was not there before is no skip from above'
		);
	}

	/**
	 * A page with the template page gets a hero with its h1 and the template page-landing in one operation: alone each
	 * would leave the page with two h1 or none. A shortcode block goes unchanged into a new section with wrap, on the
	 * ground inverse and with an h2; wrap with a place or together with sections is refused.
	 */
	public function test_insert_section_with_template_and_wrap(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$shortcode = '<!-- wp:shortcode -->' . "\n" . '[my_booking id="1"]' . "\n" . '<!-- /wp:shortcode -->';
		$page      = $this->page( self::paragraph( 'Intro' ) . "\n\n" . $shortcode );
		$hero      = array(
			array(
				'pattern' => 'leitvia/hero-split-image',
				'edits'   => array(
					array(
						'block' => 'p:0.0.0.3.0',
						'url'   => '/work/',
					),
					array(
						'block' => 'p:0.0.0.3.1',
						'url'   => '/contact/',
					),
				),
			),
		);
		$call      = static fn( array $input ) => self::call( 'leitvia/insert-section', $page, $input + array( 'post' => $page ) );
		$roots     = static fn(): array => array_values( array_filter( parse_blocks( get_post_field( 'post_content', $page ) ), static fn( array $block ): bool => null !== $block['blockName'] ) );

		$this->assertWPError(
			$call(
				array(
					'sections' => $hero,
					'position' => 'start',
				)
			),
			'the template page prints the title as h1 already'
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			$call(
				array(
					'sections' => $hero,
					'template' => 'page-nothing',
				)
			)
		);
		$this->assert_ok(
			$call(
				array(
					'sections' => $hero,
					'position' => 'start',
					'template' => 'page-landing',
				)
			)
		);
		$this->assertSame( 'page-landing', get_page_template_slug( $page ) );
		$this->assertSame( 'leitvia/hero-split-image', $roots()[0]['attrs']['metadata']['patternName'] );
		$this->assertSame( 'core/shortcode', $roots()[2]['blockName'] );
		$before = serialize_block( $roots()[2] );

		$this->assert_rejected(
			'leitvia_invalid_input',
			$call(
				array(
					'wrap'     => array( 'block' => 'p:2' ),
					'position' => 'end',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			$call(
				array(
					'wrap'     => array( 'block' => 'p:2' ),
					'sections' => $hero,
				)
			)
		);
		$this->assert_rejected( 'leitvia_not_found', $call( array( 'wrap' => array( 'block' => 'p:9' ) ) ) );
		$this->assert_ok(
			$call(
				array(
					'wrap' => array(
						'block'   => 'p:2',
						'ground'  => 'inverse',
						'heading' => 'Book a <call>',
					),
				)
			)
		);
		$section = $roots()[2];
		$this->assertCount( 3, $roots() );
		$this->assertSame( 'core/group', $section['blockName'] );
		$this->assertSame( 'full', $section['attrs']['align'] );
		$this->assertSame( 'is-style-inverse', $section['attrs']['className'] );
		$this->assertSame( 'var:preset|spacing|xl', $section['attrs']['style']['spacing']['padding']['top'] );
		$this->assertSame( 'core/heading', $section['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'wide', $section['innerBlocks'][0]['attrs']['align'], 'the heading starts at the wide edge like those of the patterns' );
		$this->assertStringContainsString( '<h2 class="wp-block-heading alignwide has-heading-l-font-size">Book a &lt;call&gt;</h2>', $section['innerHTML'] . serialize_block( $section['innerBlocks'][0] ) );
		$this->assertSame( $before, serialize_block( $section['innerBlocks'][1] ), 'the shortcode block stays as it was' );
		$this->assertTrue( has_blocks( get_post_field( 'post_content', $page ) ) );

		$this->assert_ok( $call( array( 'wrap' => array( 'block' => 'p:1' ) ) ) );
		$this->assertSame( 'surface', $roots()[1]['attrs']['backgroundColor'] );
		$this->assertArrayNotHasKey( 'className', $roots()[1]['attrs'] );
	}

	/**
	 * Trash-content waits for a person even in the profile full; the agent cannot apply it. Applied by an
	 * administrator the page is in the trash of core, and a rollback brings it back with its address. Links to it
	 * are named. The front page, a page with subpages and a file are refused.
	 */
	public function test_trash_content(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$page  = $this->page( self::paragraph( 'About' ) );
		$slug  = (string) get_post_field( 'post_name', $page );
		$other = $this->page( self::paragraph( '<a href="' . esc_url( (string) get_permalink( $page ) ) . '">About us</a>' ) );

		$result = $this->assert_ok( self::call( 'leitvia/trash-content', $page, array( 'post' => $page ) ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( 'publish', get_post_status( $page ) );
		$this->assertStringContainsString( $other . ' (Page, Published)', (string) $result['notice'], 'type and status by their labels, which a language pack translates' );
		$this->assertSame( $other, $result['report']['links'][0]['post'] );
		$this->assertNotEmpty( Packages::apply( $result['package'] )['errors'], 'the agent cannot apply it' );
		$this->assertSame( 'publish', get_post_status( $page ) );

		wp_set_current_user( $admin );
		$this->assertSame( 'applied', Packages::apply( $result['package'] )['state'] );
		$this->assertSame( 'trash', get_post_status( $page ) );
		$this->assertSame( 'publish', get_post_meta( $page, '_wp_trash_meta_status', true ) );
		$rolled = Packages::rollback( $result['package'] );
		$this->assertIsArray( $rolled, is_wp_error( $rolled ) ? $rolled->get_error_code() . ' ' . $rolled->get_error_message() : '' );
		$this->assertSame( 'publish', get_post_status( $page ) );
		$this->assertSame( $slug, get_post_field( 'post_name', $page ) );
		wp_set_current_user( $this->agent );

		update_option( 'page_on_front', $other );
		$data = $this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/trash-content', $other, array( 'post' => $other ) ) );
		$this->assertSame( 'setting', $data['reason'] );
		delete_option( 'page_on_front' );
		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $page,
			)
		);
		$data  = $this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/trash-content', $page, array( 'post' => $page ) ) );
		$this->assertSame( 'children', $data['reason'] );
		$this->assertSame( array( $child ), $data['target']['children'] );
		$image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$this->assert_rejected( 'leitvia_not_found', self::call( 'leitvia/trash-content', $image, array( 'post' => $image ) ) );
	}

	/**
	 * Delete-media refuses a file that published content shows and names the place; one only a draft uses waits in a
	 * package with a notice, whose diff says that the file goes for good, and an administrator deletes it with its file.
	 * force deletes a file in use as well.
	 */
	public function test_delete_media(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$file  = (string) get_attached_file( $image );
		$page  = $this->page( '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $image ) ) . '" alt="Portrait" class="wp-image-' . $image . '"/></figure><!-- /wp:image -->' );

		$data = $this->assert_rejected( 'leitvia_media_in_use', self::call( 'leitvia/delete-media', $image, array( 'id' => $image ) ) );
		$this->assertSame( $page, $data['usage'][0]['post'] );
		$this->assertTrue( $data['usage'][0]['public'] );
		$this->assertSame( 'content', $data['usage'][0]['where'] );

		$forced = $this->assert_ok(
			self::call(
				'leitvia/delete-media',
				$image,
				array(
					'id'    => $image,
					'force' => true,
				)
			)
		);
		$this->assertSame( 'package', $forced['mode'] );

		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'draft',
			)
		);
		$result = $this->assert_ok( self::call( 'leitvia/delete-media', $image, array( 'id' => $image ) ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertStringContainsString( (string) $page, (string) $result['notice'] );
		$this->assertFileExists( $file );
		// The diff names the deletion with file and size, not the status the state carries as its mark.
		$items = Packages::detail( $result['package'] )['items'];
		$diff  = end( $items )['diff'];
		$this->assertSame( array( 'file' ), array_column( $diff, 'id' ) );
		$this->assertSame( 'removed', $diff[0]['change'] );
		$this->assertSame( wp_basename( $file ) . ' (' . size_format( filesize( $file ) ) . ')', $diff[0]['before_text'] );
		$this->assertStringContainsString( 'for good', $diff[0]['after_text'] );
		$this->assertNotEmpty( Packages::apply( $result['package'] )['errors'], 'the agent cannot apply it' );

		wp_set_current_user( $admin );
		$this->assertSame( 'applied', Packages::apply( $result['package'] )['state'] );
		$this->assertNull( get_post( $image ) );
		$this->assertFileDoesNotExist( $file );
		wp_set_current_user( $this->agent );
		$this->assert_rejected( 'leitvia_not_found', self::call( 'leitvia/delete-media', $image, array( 'id' => $image ) ) );
	}
}
