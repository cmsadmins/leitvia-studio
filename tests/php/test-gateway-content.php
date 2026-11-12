<?php
/**
 * Gateway content abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Content;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * The ability create-content, the new inputs of update-content, list-content, list-media, get-site-health and applied.post of
 * get-package, through the pipeline with the events model as content model.
 */
class Test_Gateway_Content extends WP_UnitTestCase {

	/**
	 * Block markup of a short text.
	 */
	private const TEXT = '<!-- wp:paragraph --><p>The new trail opens in May.</p><!-- /wp:paragraph -->';

	/**
	 * Leitvia theme, events model, agent user, profile from the argument and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		self::profile( 'full' );
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities, the category and the model.
	 */
	public function tear_down(): void {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
		foreach ( array( 'company_phone', 'company_email' ) as $key ) {
			unregister_setting( Registrar::SETTINGS_GROUP, Registrar::SETTING_PREFIX . $key );
		}
		Model::forget();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Sets the autonomy profile.
	 *
	 * @param string $profile standard or full.
	 */
	private static function profile( string $profile ): void {
		update_option( Gateway::OPTION, array( 'profile' => $profile ) + Gateway::DEFAULTS );
	}

	/**
	 * Runs an ability; write abilities get an empty base_hash unless the input has one.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function call( string $name, array $input = array() ) {
		$ability = wp_get_ability( $name );
		$schema  = $ability->get_input_schema();
		if ( ! $schema ) {
			return $ability->execute();
		}
		return $ability->execute( isset( $schema['properties']['base_hash'] ) ? $input + array( 'base_hash' => '' ) : $input );
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
	 * An image of the library with alt text.
	 *
	 * @param string $alt Alt text.
	 */
	private function image( string $alt = 'Trail sign in the forest' ): int {
		$id = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return $id;
	}

	/**
	 * A news post live: terms by name (existing category, new tag), featured image, date, AI mark and sources as meta,
	 * the note before the text in the front end and nowhere else.
	 */
	public function test_create_post_live(): void {
		$category = self::factory()->category->create( array( 'name' => 'News' ) );
		$image    = $this->image();
		$result   = self::call(
			'leitvia/create-content',
			array(
				'post_type'      => 'post',
				'title'          => 'Trail opens',
				'content'        => self::TEXT,
				'excerpt'        => 'The trail opens in May.',
				'slug'           => 'trail-opens',
				'date'           => '2026-01-05T09:30',
				'status'         => 'publish',
				'terms'          => array(
					'category' => array( 'News' ),
					'post_tag' => array( 'hiking', 'spring' ),
				),
				'featured_image' => array( 'id' => $image ),
				'ai_generated'   => true,
				'sources'        => array( 'https://example.org/press/trail' ),
			)
		);
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'live', $result['mode'] );
		$post = get_post( $result['target']['post'] );
		$this->assertSame( array( 'publish', 'trail-opens', '2026-01-05 09:30:00' ), array( $post->post_status, $post->post_name, $post->post_date ) );
		$this->assertSame( array( $category ), wp_get_post_categories( $post->ID ) );
		$this->assertEqualsCanonicalizing( array( 'hiking', 'spring' ), wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) );
		$this->assertSame( $image, get_post_thumbnail_id( $post ) );
		$this->assertSame( '1', get_post_meta( $post->ID, Content::AI_META, true ) );
		$this->assertSame( array( 'https://example.org/press/trail' ), get_post_meta( $post->ID, Content::SOURCES_META, true ) );
		$this->assertSame( Hashes::document( $post->ID ), $result['hash'] );

		$this->go_to( get_permalink( $post ) );
		the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		$this->assertStringStartsWith( '<p class="leitvia-ai-note">This text was written with the help of AI.</p>', $html );
		$this->assertStringNotContainsString( 'leitvia-ai-note', get_the_excerpt() );
		add_filter( 'leitvia_studio_ai_note', '__return_empty_string' );
		$this->assertStringNotContainsString( 'leitvia-ai-note', apply_filters( 'the_content', get_the_content() ) );
	}

	/**
	 * An entry of the content model with fields, a relation, a taxonomy term and sections of patterns; required fields
	 * before publishing; invalid fields and terms.
	 */
	public function test_create_entry_with_fields(): void {
		$type    = self::factory()->term->create(
			array(
				'taxonomy' => 'event_type',
				'name'     => 'Guided walk',
			)
		);
		$speaker = self::factory()->post->create( array( 'post_type' => 'person' ) );
		$poster  = $this->image();
		$input   = array(
			'post_type' => 'event',
			'title'     => 'Spring walk',
			'sections'  => array( array( 'pattern' => 'leitvia/content-quote' ) ),
			'status'    => 'publish',
			'terms'     => array( 'event_type' => array( $type ) ),
			'fields'    => array(
				'event_venue'    => 'Old mill',
				'event_level'    => 'beginner',
				'event_poster'   => $poster,
				'event_speakers' => array( $speaker ),
			),
		);
		$data    = $this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/create-content', $input ) );
		$this->assertSame( 'required', $data['reason'] );

		$input['fields']['event_start'] = '2026-05-01T10:00:00';
		$result                         = self::call( 'leitvia/create-content', $input );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$id = $result['target']['post'];
		$this->assertSame( 'event', get_post_type( $id ) );
		$this->assertStringContainsString( '"patternName":"leitvia/content-quote"', get_post( $id )->post_content );
		$this->assertSame( array( $type ), wp_get_object_terms( $id, 'event_type', array( 'fields' => 'ids' ) ) );
		$this->assertSame( 'Old mill', get_post_meta( $id, 'event_venue', true ) );
		$this->assertSame( array( $speaker ), array_map( 'intval', (array) get_post_meta( $id, 'event_speakers', true ) ) );

		$cases = array(
			'unknown field'         => array( 'fields' => array( 'event_colour' => 'red' ) ),
			'option'                => array( 'fields' => array( 'event_level' => 'expert' ) ),
			'relation'              => array( 'fields' => array( 'event_speakers' => array( $poster ) ) ),
			'unknown term ID'       => array( 'terms' => array( 'event_type' => array( 999999 ) ) ),
			'foreign taxonomy'      => array( 'terms' => array( 'post_tag' => array( 'walks' ) ) ),
			'kind on an entry'      => array( 'kind' => 'imprint' ),
			'content + sections'    => array( 'content' => self::TEXT ),
			'future in the past'    => array(
				'status' => 'future',
				'date'   => '2020-01-01',
			),
			'parent of a flat type' => array( 'parent' => $id ),
		);
		foreach ( $cases as $name => $change ) {
			$result = self::call( 'leitvia/create-content', $change + $input );
			$this->assertWPError( $result, $name );
			$this->assertSame( 'leitvia_invalid_input', $result->get_error_code(), $name . ': ' . $result->get_error_message() );
		}
	}

	/**
	 * The draft of the privacy policy page every new site has shows up for an agent; a draft the user may not read is
	 * left out of items, total and pages alike.
	 */
	public function test_list_counts_what_it_shows(): void {
		$privacy = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$list = Content::list(
			array(
				'post_type' => 'page',
				'status'    => 'any',
			)
		);
		$this->assertSame( array( $privacy ), array_column( $list['items'], 'id' ) );
		$this->assertSame( 1, $list['total'] );

		$own = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
				'post_title'  => 'Mine',
			)
		);
		$list = Content::list(
			array(
				'post_type' => 'post',
				'status'    => 'draft',
				'limit'     => 1,
			)
		);
		$this->assertSame( array( 'Mine' ), array_column( $list['items'], 'title' ) );
		$this->assertSame( array( 1, 1 ), array( $list['total'], $list['pages'] ), 'The draft of the other author ' . $own . ' does not count.' );
	}

	/**
	 * A slug WordPress cannot give stands in the result at once, in a package, as a draft and after applying.
	 */
	public function test_slug_changed(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'kontakt',
			)
		);
		$input = array(
			'post_type' => 'page',
			'title'     => 'Kontakt',
			'content'   => self::TEXT,
			'slug'      => 'kontakt',
			'status'    => 'publish',
		);
		$live  = self::call( 'leitvia/create-content', $input );
		$this->assertSame(
			array(
				'requested' => 'kontakt',
				'slug'      => 'kontakt-2',
			),
			$live['slug_changed']
		);
		$this->assertSame( 'kontakt-2', get_post_field( 'post_name', $live['target']['post'] ) );

		$draft = self::call( 'leitvia/create-content', array( 'status' => 'draft' ) + $input );
		$this->assertSame( 'kontakt-3', $draft['slug_changed']['slug'], 'A draft says what it gets on publishing.' );

		$queued = self::call( 'leitvia/create-content', array( 'package' => 'slug' ) + $input );
		$this->assertArrayHasKey( 'slug_changed', $queued );
		Packages::apply( $queued['package'] );
		$this->assertArrayHasKey( 'slug_changed', Packages::detail( $queued['package'] )['items'][0]['applied'] );

		$free = self::call( 'leitvia/create-content', array( 'slug' => 'frei' ) + $input );
		$this->assertArrayNotHasKey( 'slug_changed', $free );
	}

	/**
	 * Create-content and update-content set the author, list-content names it, and a rollback brings it back.
	 */
	public function test_author(): void {
		$editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ada Lovelace',
			)
		);
		$input  = array(
			'post_type' => 'post',
			'title'     => 'Signed',
			'content'   => self::TEXT,
			'status'    => 'draft',
			'author'    => $editor,
		);
		$post   = (int) self::call( 'leitvia/create-content', $input )['target']['post'];
		$this->assertSame( $editor, (int) get_post_field( 'post_author', $post ) );
		$item = Content::list( array( 'post_type' => 'post' ) )['items'][0];
		$this->assertSame( array( $editor, 'Ada Lovelace' ), array( $item['author'], $item['author_name'] ) );

		$me     = get_current_user_id();
		$update = self::call(
			'leitvia/update-content',
			array(
				'post'      => $post,
				'base_hash' => Hashes::document( $post ),
				'author'    => $me,
			)
		);
		$this->assertSame( $me, (int) get_post_field( 'post_author', $post ) );
		$this->assertIsArray( Packages::rollback( $update['package'] ) );
		$this->assertSame( $editor, (int) get_post_field( 'post_author', $post ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( $subscriber, 999999 ) as $wrong ) {
			$this->assertSame( 'author', $this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/create-content', array( 'author' => $wrong ) + $input ) )['reason'] );
		}
	}

	/**
	 * A name no category has yet creates it with the post: live for an autonomous agent, by name in a change package and
	 * only when the package is applied otherwise. Needs edit_terms, as in core; update-content takes the same way.
	 */
	public function test_new_categories(): void {
		$input = array(
			'post_type' => 'post',
			'title'     => 'Notes',
			'content'   => self::TEXT,
			'status'    => 'draft',
			'terms'     => array( 'category' => array( 'Field notes' ) ),
		);
		$live  = self::call( 'leitvia/create-content', $input );
		$this->assertIsArray( $live );
		$term = get_term_by( 'name', 'Field notes', 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( array( $term->term_id ), wp_get_post_categories( $live['target']['post'] ) );

		$queued = self::call(
			'leitvia/create-content',
			array(
				'terms'   => array( 'category' => array( $term->term_id, 'Waits & sees' ) ),
				'package' => 'cat',
			) + $input
		);
		$this->assertSame( 'package', $queued['mode'] );
		$this->assertFalse( get_term_by( 'name', 'Waits &amp; sees', 'category' ) );
		$detail = Packages::detail( $queued['package'] );
		$this->assertStringContainsString( 'Waits & sees', (string) wp_json_encode( $detail['items'][0]['diff'], JSON_UNESCAPED_UNICODE ) );
		$report = Packages::apply( $queued['package'] );
		$this->assertSame( 'applied', $report['state'], wp_json_encode( $report ) );
		$post = (int) Packages::detail( $queued['package'] )['items'][0]['applied']['post'];
		$this->assertCount( 2, wp_get_post_categories( $post ) );

		$update = self::call(
			'leitvia/update-content',
			array(
				'post'      => $post,
				'base_hash' => Hashes::document( $post ),
				'terms'     => array( 'category' => array( 'Second thoughts' ) ),
			)
		);
		$this->assertIsArray( $update );
		$this->assertSame( array( 'Second thoughts' ), wp_get_post_categories( $post, array( 'fields' => 'names' ) ) );

		$deny = static fn( array $caps ): array => in_array( 'manage_categories', $caps, true ) ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny );
		$this->assert_rejected( 'leitvia_forbidden', self::call( 'leitvia/create-content', array( 'terms' => array( 'category' => array( 'Not allowed' ) ) ) + $input ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		remove_filter( 'map_meta_cap', $deny );
		$this->assertFalse( get_term_by( 'name', 'Not allowed', 'category' ) );
	}

	/**
	 * Only content types; the featured image needs alt text or the decorative mark; the content runs through the
	 * same safety checks as update-content.
	 */
	public function test_create_checks(): void {
		foreach ( array( 'wp_block', 'attachment', 'event_session', 'user_request' ) as $type ) {
			$this->assert_rejected(
				'leitvia_invalid_input',
				self::call(
					'leitvia/create-content',
					array(
						'post_type' => $type,
						'title'     => 'No',
					)
				)
			);
		}
		$base = array(
			'post_type' => 'post',
			'title'     => 'Checks',
			'content'   => self::TEXT,
		);
		$bare = $this->image( '' );
		$this->assert_rejected( 'leitvia_check_failed', self::call( 'leitvia/create-content', $base + array( 'featured_image' => array( 'id' => $bare ) ) ) );
		$this->assertIsArray( self::call( 'leitvia/create-content', $base + array( 'featured_image' => array( 'id' => $bare, 'decorative' => true ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		$this->assert_rejected( 'leitvia_unsafe_content', self::call( 'leitvia/create-content', array( 'content' => '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->' ) + $base ) );
		$this->assert_rejected( 'leitvia_unsafe_content', self::call( 'leitvia/create-content', array( 'content' => '<p>Classic</p>' ) + $base ) );
	}

	/**
	 * Rights: a contributor may not publish, a subscriber may not use the gateway, a user without assign_terms may
	 * not set terms; the editor reads no site health.
	 */
	public function test_rights(): void {
		$input = array(
			'post_type' => 'post',
			'title'     => 'Rights',
			'content'   => self::TEXT,
			'status'    => 'publish',
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assert_rejected( 'leitvia_forbidden', self::call( 'leitvia/create-content', $input ) );
		$draft = self::call( 'leitvia/create-content', array( 'status' => 'draft' ) + $input );
		$this->assertSame( 'draft', get_post_status( $draft['target']['post'] ) );
		add_filter(
			'map_meta_cap',
			static fn( array $caps, string $cap ): array => 'assign_post_tags' === $cap ? array( 'do_not_allow' ) : $caps,
			10,
			2
		);
		$this->assert_rejected( 'leitvia_forbidden', self::call( 'leitvia/create-content', array( 'status' => 'draft', 'terms' => array( 'post_tag' => array( 'x' ) ) ) + $input ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( self::call( 'leitvia/create-content', $input ) );
		$this->assertWPError( self::call( 'leitvia/list-content' ) );

		// Versions and updates: not for editors, also not with edit_theme_options; for whoever may view Site Health. The
		// permission callback says so, before the ability runs.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $editor )->add_cap( 'edit_theme_options' );
		wp_set_current_user( $editor );
		$this->assertFalse( wp_get_ability( 'leitvia/get-site-health' )->check_permissions() );
		$this->assertWPError( self::call( 'leitvia/get-site-health' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$this->assertArrayHasKey( 'plugins', self::call( 'leitvia/get-site-health' ) );
	}

	/**
	 * Standard profile: a new post becomes a draft; with a package key it waits in the package, together with the image
	 * of add-media it names by file name. Applying writes the image first, then the post with that image, and
	 * get-package shows the IDs in applied.post.
	 */
	public function test_package_with_waiting_image(): void {
		self::profile( 'standard' );
		$draft = self::call(
			'leitvia/create-content',
			array(
				'post_type' => 'post',
				'title'     => 'Draft by autonomy',
				'content'   => self::TEXT,
				'status'    => 'publish',
			)
		);
		$this->assertSame( array( 'draft', 'draft' ), array( $draft['mode'], get_post_status( $draft['target']['post'] ) ) );

		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/create-content',
				array(
					'post_type'      => 'post',
					'title'          => 'No image waits',
					'featured_image' => array( 'file' => 'trail.png' ),
					'package'        => 'news-1',
				)
			)
		);
		$media = self::call(
			'leitvia/add-media',
			array(
				'data'     => base64_encode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/avatar.png' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
				'filename' => 'trail.png',
				'alt'      => 'Trail sign in the forest',
				'package'  => 'news-1',
			)
		);
		$this->assertSame( 'package', $media['mode'] );
		$post = self::call(
			'leitvia/create-content',
			array(
				'post_type'      => 'post',
				'title'          => 'Trail news',
				'content'        => self::TEXT,
				'status'         => 'publish',
				'featured_image' => array( 'file' => 'trail.png' ),
				'package'        => 'news-1',
			)
		);
		$this->assertSame( array( 'package', $media['package'] ), array( $post['mode'], $post['package'] ) );

		$report = Packages::apply( $post['package'] );
		$this->assertSame( array( 1, 2 ), $report['applied'], (string) wp_json_encode( $report ) );
		$detail  = self::call( 'leitvia/get-package', array( 'id' => $post['package'] ) );
		$applied = array_column( array_column( $detail['items'], 'applied' ), 'post' );
		$this->assertSame( 'attachment', get_post_type( $applied[0] ) );
		$this->assertSame( array( 'post', 'publish' ), array( get_post_type( $applied[1] ), get_post_status( $applied[1] ) ) );
		$this->assertSame( $applied[0], get_post_thumbnail_id( $applied[1] ) );
		$this->assertSame( Hashes::document( $applied[1] ), $detail['items'][1]['applied']['hash'] );
	}

	/**
	 * The ability update-content sets date, terms, fields and featured image with the same rules; the hash covers them, so a term
	 * a person changed makes the old hash stale; rollback restores the terms.
	 */
	public function test_update_terms_fields_image(): void {
		$old   = self::factory()->term->create(
			array(
				'taxonomy' => 'event_type',
				'name'     => 'Talk',
			)
		);
		$new   = self::factory()->term->create(
			array(
				'taxonomy' => 'event_type',
				'name'     => 'Walk',
			)
		);
		$entry = self::factory()->post->create(
			array(
				'post_type'    => 'event',
				'post_content' => self::TEXT,
			)
		);
		wp_set_object_terms( $entry, array( $old ), 'event_type' );
		$image  = $this->image();
		$hash   = Hashes::document( $entry );
		$result = self::call(
			'leitvia/update-content',
			array(
				'post'           => $entry,
				'base_hash'      => $hash,
				'date'           => '2025-12-24',
				'terms'          => array( 'event_type' => array( 'walk' ) ),
				'fields'         => array( 'event_venue' => 'Harbour' ),
				'featured_image' => array( 'id' => $image ),
			)
		);
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( array( $new ), wp_get_object_terms( $entry, 'event_type', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( 'Harbour', $image, '2025-12-24 00:00:00' ), array( get_post_meta( $entry, 'event_venue', true ), get_post_thumbnail_id( $entry ), get_post( $entry )->post_date ) );
		$this->assertNotSame( $hash, $result['hash'] );

		wp_set_object_terms( $entry, array( $old ), 'event_type' );
		$this->assert_rejected(
			'leitvia_stale_hash',
			self::call(
				'leitvia/update-content',
				array(
					'post'      => $entry,
					'base_hash' => $result['hash'],
					'title'     => 'Late',
				)
			)
		);
		wp_set_object_terms( $entry, array( $new ), 'event_type' );
		$this->assertSame( 'rolled_back', Packages::rollback( $result['package'] )['state'] );
		$this->assertSame( array( $old ), wp_get_object_terms( $entry, 'event_type', array( 'fields' => 'ids' ) ) );
		$this->assertSame( '', get_post_meta( $entry, 'event_venue', true ) );
		$this->assertSame( 0, get_post_thumbnail_id( $entry ) );

		$template = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-content',
				array(
					'post'      => $template,
					'base_hash' => Hashes::document( $template ),
					'terms'     => array( 'category' => array( 1 ) ),
				)
			)
		);
	}

	/**
	 * Text colors as rgba(), rgb(), hsl() and preset variables in a block style pass the pipeline unchanged; other
	 * parentheses in a style stay refused, and the color filter is gone after the check.
	 */
	public function test_create_with_css_colors(): void {
		$content = '';
		$colors  = array( 'rgba(0, 0, 0, 0.87)', 'rgb(17 17 17)', 'hsl(0 0% 10%)', 'var(--wp--preset--color--contrast)' );
		foreach ( $colors as $color ) {
			$content .= '<!-- wp:paragraph {"style":{"color":{"text":"' . $color . '"}}} --><p class="has-text-color" style="color:' . $color . '">Muted text</p><!-- /wp:paragraph -->';
		}
		$result = self::call(
			'leitvia/create-content',
			array(
				'post_type' => 'post',
				'title'     => 'Colors',
				'content'   => $content,
				'status'    => 'publish',
			)
		);
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		// The pipeline adds a leitviaId to each block; the styles stay as written, a core preset alias becomes its role.
		$saved = get_post( $result['target']['post'] )->post_content;
		foreach ( $colors as $color ) {
			$this->assertStringContainsString( 'style="color:' . str_replace( '--contrast)', '--ink)', $color ) . '"', $saved );
		}
		$this->assertFalse( has_filter( 'safecss_filter_attr_allow_css' ) );
		$this->assert_rejected(
			'leitvia_unsafe_content',
			self::call(
				'leitvia/create-content',
				array(
					'post_type' => 'post',
					'title'     => 'Expression',
					'content'   => '<!-- wp:paragraph --><p style="color:expression(alert(1))">Bad</p><!-- /wp:paragraph -->',
				)
			)
		);
	}

	/**
	 * The comment status: create-content sets it, update-content changes it and the hash with it, values other than
	 * open and closed are refused.
	 */
	public function test_comment_status(): void {
		$result = self::call(
			'leitvia/create-content',
			array(
				'post_type'      => 'post',
				'title'          => 'Quiet',
				'content'        => self::TEXT,
				'status'         => 'publish',
				'comment_status' => 'closed',
			)
		);
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$post = $result['target']['post'];
		$this->assertSame( 'closed', get_post( $post )->comment_status );
		$update = self::call(
			'leitvia/update-content',
			array(
				'post'           => $post,
				'base_hash'      => $result['hash'],
				'comment_status' => 'open',
			)
		);
		$this->assertIsArray( $update, $update instanceof WP_Error ? $update->get_error_message() : '' );
		$this->assertSame( 'live', $update['mode'] );
		$this->assertSame( 'open', get_post( $post )->comment_status );
		$this->assertNotSame( $result['hash'], $update['hash'] );
		$this->assertWPError(
			self::call(
				'leitvia/update-content',
				array(
					'post'           => $post,
					'base_hash'      => $update['hash'],
					'comment_status' => 'maybe',
				)
			)
		);
		$this->assertSame( 'open', get_post( $post )->comment_status );
	}

	/**
	 * The ability list-content: content types only, filters, the waiting package, fields of entries; nothing private, password
	 * protected or of another type.
	 */
	public function test_list_content(): void {
		$tag = self::factory()->tag->create( array( 'name' => 'trails' ) );
		$old = self::factory()->post->create(
			array(
				'post_title' => 'Old trail',
				'post_date'  => '2025-01-01 10:00:00',
			)
		);
		$new = self::factory()->post->create(
			array(
				'post_title' => 'New trail',
				'post_date'  => '2026-03-01 10:00:00',
			)
		);
		wp_set_object_terms( $new, array( $tag ), 'post_tag' );
		self::factory()->post->create( array( 'post_status' => 'private' ) );
		self::factory()->post->create( array( 'post_password' => 'secret' ) );
		self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$event = self::factory()->post->create(
			array(
				'post_type'  => 'event',
				'meta_input' => array( 'event_venue' => 'Old mill' ),
			)
		);
		self::profile( 'standard' );
		$queued = self::call(
			'leitvia/update-content',
			array(
				'post'      => $new,
				'base_hash' => Hashes::document( $new ),
				'title'     => 'Newer trail',
				'package'   => 'list-1',
			)
		);

		$all = self::call( 'leitvia/list-content' );
		$ids = array_column( $all['items'], 'id' );
		$this->assertEqualsCanonicalizing( array( $old, $new, $draft, $event ), $ids );
		$item = $all['items'][ array_search( $new, $ids, true ) ];
		$this->assertSame( array( 'New trail', 'publish', array( 'trails' ), $queued['package'], Hashes::document( $new ) ), array( $item['title'], $item['status'], $item['terms']->post_tag, $item['package'], $item['hash'] ) );
		$this->assertSame( 'Old mill', $all['items'][ array_search( $event, $ids, true ) ]['fields']['event_venue'] );

		$this->assertSame( array( $new ), array_column( self::call( 'leitvia/list-content', array( 'post_type' => 'post', 'after' => '2026-01-01', 'status' => 'publish' ) )['items'], 'id' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		$this->assertSame( array( $draft ), array_column( self::call( 'leitvia/list-content', array( 'status' => 'draft' ) )['items'], 'id' ) );
		$this->assertSame( array( $old ), array_column( self::call( 'leitvia/list-content', array( 'search' => 'Old trail' ) )['items'], 'id' ) );
		$page = self::call( 'leitvia/list-content', array( 'limit' => 2 ) );
		$this->assertSame( array( 2, 4, 2 ), array( count( $page['items'] ), $page['total'], $page['pages'] ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/list-content', array( 'post_type' => 'wp_block' ) ) );
	}

	/**
	 * The ability list-media: images with file name, MIME type, file size, alt text, size and the hash for set-alt-text; MIME filter.
	 */
	public function test_list_media(): void {
		$image = $this->image();
		$list  = self::call( 'leitvia/list-media', array( 'mime' => 'image' ) );
		$this->assertSame( array( $image ), array_column( $list['items'], 'id' ) );
		$item = $list['items'][0];
		$this->assertSame( array( 'Trail sign in the forest', wp_basename( (string) get_attached_file( $image ) ), Hashes::document( $image ) ), array( $item['alt'], $item['filename'], $item['hash'] ) );
		$this->assertGreaterThan( 0, $item['width'] );
		// Format and size for the link text of a file to download.
		$this->assertSame( array( 'image/png', wp_filesize( (string) get_attached_file( $image ) ) ), array( $item['mime'], $item['filesize'] ) );
		$this->assertSame( array(), self::call( 'leitvia/list-media', array( 'mime' => 'video' ) )['items'] );
	}

	/**
	 * The ability list-media with include returns the listed IDs in their order and nothing else; search finds a file by its name
	 * even when the title says something else.
	 */
	public function test_list_media_by_ids_and_file_name(): void {
		$first  = $this->image();
		$second = $this->image( 'Second sign' );
		$third  = $this->image( 'Third sign' );
		wp_update_post(
			array(
				'ID'         => $second,
				'post_title' => 'Portrait',
			)
		);
		$file = pathinfo( (string) get_attached_file( $second ), PATHINFO_FILENAME );
		$this->assertSame( array( $third, $first ), array_column( self::call( 'leitvia/list-media', array( 'include' => array( $third, $first ) ) )['items'], 'id' ) );
		$this->assertSame( array( $first ), array_column( self::call( 'leitvia/list-media', array( 'include' => array( $first, 999999 ) ) )['items'], 'id' ) );
		$this->assertContains( $second, array_column( self::call( 'leitvia/list-media', array( 'search' => $file ) )['items'], 'id' ) );
		$this->assertSame( array(), self::call( 'leitvia/list-media', array( 'search' => 'no-such-file-name' ) )['items'] );
		$this->assert_rejected( 'ability_invalid_input', self::call( 'leitvia/list-media', array( 'include' => array_fill( 0, 101, $first ) ) ) );
	}

	/**
	 * The ability get-site-health: versions and known updates for the agent, from the stored update check only.
	 */
	public function test_site_health(): void {
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'response'     => array( 'leitvia-studio/leitvia-studio.php' => (object) array( 'new_version' => '9.9.9' ) ),
			)
		);
		$health = self::call( 'leitvia/get-site-health' );
		$this->assertSame( PHP_VERSION, $health['php'] );
		$this->assertSame( 'leitvia', $health['theme']['slug'] );
		$this->assertSame( get_bloginfo( 'version' ), $health['wordpress']['version'] );
		$studio = array_values( array_filter( $health['plugins'], static fn( array $plugin ): bool => 'leitvia-studio/leitvia-studio.php' === $plugin['file'] ) );
		$this->assertSame( '9.9.9', $studio[0]['update'] ?? null );
	}

	/**
	 * Rows of a repeater: create-content adds them in order, list-content reads them, update-content replaces them
	 * (fewer rows delete the rest), rollback brings them back. An unknown repeater, a key the repeater has not and a
	 * value its field refuses change nothing.
	 */
	public function test_repeater_rows(): void {
		$rows   = static fn( int $entry ): array => array_map(
			static fn( WP_Post $row ): array => array( $row->post_title, (string) get_post_meta( $row->ID, 'session_time', true ), (string) get_post_meta( $row->ID, 'session_speaker', true ), $row->post_status ),
			get_posts(
				array(
					'post_type'   => 'event_session',
					'post_parent' => $entry,
					'post_status' => 'any',
					'numberposts' => -1,
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				)
			)
		);
		$agenda = array(
			array(
				'title'           => 'Welcome',
				'session_time'    => '09:00',
				'session_speaker' => 'Mara',
			),
			array(
				'title'        => 'Walk',
				'session_time' => '10:30',
			),
		);
		$result = self::call(
			'leitvia/create-content',
			array(
				'post_type' => 'event',
				'title'     => 'Walk day',
				'content'   => self::TEXT,
				'rows'      => array( 'event_agenda' => $agenda ),
			)
		);
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$id = (int) $result['target']['post'];
		$this->assertSame(
			array(
				array( 'Welcome', '09:00', 'Mara', 'inherit' ),
				array( 'Walk', '10:30', '', 'inherit' ),
			),
			$rows( $id )
		);
		$this->assertSame( Hashes::document( $id ), $result['hash'] );
		$listed = Content::list( array( 'post_type' => 'event' ) )['items'][0];
		$this->assertSame( array( 'Welcome', 'Walk' ), array_column( $listed['rows']['event_agenda'], 'title' ) );

		$cases = array(
			'unknown repeater' => array( 'event_extras' => array() ),
			'unknown key'      => array( 'event_agenda' => array( array( 'session_room' => 'Hall' ) ) ),
			'invalid value'    => array( 'event_agenda' => array( array( 'session_time' => 'nine' ) ) ),
		);
		foreach ( $cases as $case => $change ) {
			$this->assert_rejected(
				'leitvia_invalid_input',
				self::call(
					'leitvia/update-content',
					array(
						'post'      => $id,
						'rows'      => $change,
						'base_hash' => Hashes::document( $id ),
					)
				)
			);
			$this->assertCount( 2, $rows( $id ), $case );
		}
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/create-content',
				array(
					'post_type' => 'page',
					'title'     => 'No rows',
					'content'   => self::TEXT,
					'rows'      => array( 'event_agenda' => $agenda ),
				)
			)
		);

		$update = self::call(
			'leitvia/update-content',
			array(
				'post'      => $id,
				'rows'      => array(
					'event_agenda' => array(
						array(
							'title'        => 'Opening',
							'session_time' => '08:45',
						),
					),
				),
				'base_hash' => Hashes::document( $id ),
			)
		);
		$this->assertIsArray( $update, $update instanceof WP_Error ? $update->get_error_message() : '' );
		$this->assertSame( array( array( 'Opening', '08:45', '', 'inherit' ) ), $rows( $id ) );

		$this->assertIsArray( Packages::rollback( (int) $update['package'] ) );
		$this->assertSame(
			array(
				array( 'Welcome', '09:00', 'Mara', 'inherit' ),
				array( 'Walk', '10:30', '', 'inherit' ),
			),
			$rows( $id )
		);
	}
}
