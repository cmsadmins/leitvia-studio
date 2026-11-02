<?php
/**
 * Read ability for the site audit.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;
use LeitviaStudio\Lab\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Ability leitvia/audit-site: the content checks over published content, page by page, plus classic content,
 * deprecated patterns, token drift, internal links without a target, pages that look like legal texts without a
 * page kind and placeholders left on legal pages. The first page adds findings about the whole site under site: a site
 * closed to search engines, a missing imprint, contact or editorial page, and recent news without a large image or
 * whose author has no biography.
 */
final class Read_Audit {

	public const MAX_LIMIT = 20;

	/**
	 * Slugs that look like a legal text, with the page kind they should carry.
	 */
	public const LEGAL_SLUGS = array(
		'imprint'                         => 'imprint',
		'impressum'                       => 'imprint',
		'legal-notice'                    => 'imprint',
		'privacy'                         => 'privacy',
		'privacy-policy'                  => 'privacy',
		'datenschutz'                     => 'privacy',
		'datenschutzerklaerung'           => 'privacy',
		'datenschutzerklarung'            => 'privacy',
		'terms'                           => 'terms',
		'terms-of-service'                => 'terms',
		'terms-and-conditions'            => 'terms',
		'agb'                             => 'terms',
		'withdrawal'                      => 'withdrawal',
		'cancellation-policy'             => 'withdrawal',
		'widerruf'                        => 'withdrawal',
		'widerrufsbelehrung'              => 'withdrawal',
		'accessibility'                   => 'accessibility',
		'accessibility-statement'         => 'accessibility',
		'barrierefreiheit'                => 'accessibility',
		'erklaerung-zur-barrierefreiheit' => 'accessibility',
		'barrierefreiheitserklaerung'     => 'accessibility',
	);

	/**
	 * Slugs of the editorial and contact page, with their page kind; site() also accepts the imprint slugs of
	 * LEGAL_SLUGS.
	 */
	public const PAGE_SLUGS = array(
		'redaktion'        => 'editorial',
		'editorial-policy' => 'editorial',
		'editorial'        => 'editorial',
		'wie-wir-arbeiten' => 'editorial',
		'kontakt'          => 'contact',
		'contact'          => 'contact',
	);

	/**
	 * Pages a site with news needs, see site().
	 */
	public const NEWS_PAGES = array( 'imprint', 'contact', 'editorial' );

	/**
	 * Recent news posts site() checks for image and author biography.
	 */
	public const NEWS_CHECKED = 20;

	/**
	 * Preset types in markup and the global settings that list their slugs.
	 */
	private const PRESETS = array(
		'color'     => array( 'color', 'palette' ),
		'font-size' => array( 'typography', 'fontSizes' ),
		'spacing'   => array( 'spacing', 'spacingSizes' ),
	);

	/**
	 * Registers the ability. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		Gateway\Abilities::read(
			'leitvia/audit-site',
			__( 'Audit site', 'leitvia-studio' ),
			__( 'Runs the content checks (headings, alt text, link text, block validity, tokens, contrast, one h1 and landmarks) over published content, at most 20 posts per call in ID order. Also reports classic content without blocks, deprecated patterns, preset slugs missing from the active tokens, internal links that lead to no page or to the old address of a renamed post, pages that look like legal texts without a page kind, and placeholders left on legal pages: square brackets such as [Organization name] and sentences that say they are a placeholder, as the recipes write them. The first page (after 0) also returns site: an error when the site is closed to search engines outside a local or staging site, warnings for a missing imprint, contact or editorial page while there are posts, and for the latest 20 news posts without a featured image of at least 1200 px width or whose author has no biography. Pass next as after for the following page; next is null at the end.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'after' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Start after this post ID, the next value of the previous call.', 'leitvia-studio' ),
					),
					'limit' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => 10,
						'description' => __( 'Posts per call.', 'leitvia-studio' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'items' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'next'  => array( 'type' => array( 'integer', 'null' ) ),
					'site'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			static fn( $input ) => self::audit( (int) ( $input['after'] ?? 0 ), (int) ( $input['limit'] ?? 10 ) )
		);
	}

	/**
	 * One page of the audit: per post its ID, type, title, slug, page kind and findings, and the cursor.
	 *
	 * @param int $after Post ID to start after.
	 * @param int $limit Posts per page, 1 to MAX_LIMIT.
	 * @return array{items: list<array<string, mixed>>, next: int|null, site?: list<array<string, mixed>>}
	 */
	public static function audit( int $after, int $limit ): array {
		$page = self::page( $after, $limit );
		return 0 === $after ? $page + array( 'site' => self::site() ) : $page;
	}

	/**
	 * One page of posts with their findings.
	 *
	 * @param int $after Post ID to start after.
	 * @param int $limit Posts per page.
	 * @return array{items: list<array<string, mixed>>, next: int|null}
	 */
	private static function page( int $after, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$types = array_values( array_filter( Gateway\Scope::post_types(), static fn( string $type ): bool => 'attachment' !== $type && is_post_type_viewable( $type ) && post_type_supports( $type, 'editor' ) ) );
		if ( ! $types ) {
			return array(
				'items' => array(),
				'next'  => null,
			);
		}
		// ponytail: one indexed query per page by ID; WP_Query has no "ID greater than" without a posts_where filter.
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor over IDs, posts are read through get_post().
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND ID > %d AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built per type.
				array_merge( array( $after ), $types, array( $limit + 1 ) )
			)
		);
		$more  = count( $ids ) > $limit;
		$ids   = array_slice( array_map( 'intval', $ids ), 0, $limit );
		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && Gateway\Scope::readable( $post ) ) {
				$items[] = self::item( $post );
			}
		}
		return array(
			'items' => $items,
			'next'  => $more ? (int) end( $ids ) : null,
		);
	}

	/**
	 * Audit result of one post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private static function item( \WP_Post $post ): array {
		$kind     = (string) get_post_meta( $post->ID, '_leitvia_page_kind', true );
		$findings = Gateway\Content_Checks::run(
			$post->post_content,
			array(
				'post'      => $post->ID,
				'post_type' => $post->post_type,
				'ability'   => 'leitvia/audit-site',
			)
		);
		foreach ( Status::refs( $post->post_content ) as $ref ) {
			if ( 'deprecated' === Status::own( $ref ) ) {
				/* translators: %s: pattern reference. */
				$findings[] = self::finding( 'leitvia/deprecated-pattern', sprintf( __( 'The page uses the deprecated pattern %s.', 'leitvia-studio' ), $ref ), __( 'Replace it with leitvia/replace-pattern.', 'leitvia-studio' ) );
			}
		}
		foreach ( self::drift( $post->post_content ) as $preset ) {
			/* translators: %s: preset type and slug. */
			$findings[] = self::finding( 'leitvia/token-drift', sprintf( __( 'The preset %s is missing from the active tokens.', 'leitvia-studio' ), $preset ), __( 'Use a preset from leitvia/get-tokens.', 'leitvia-studio' ) );
		}
		foreach ( self::broken_links( $post->post_content ) as $href => $to ) {
			$findings[] = '' === $to
				/* translators: %s: link. */
				? self::finding( 'leitvia/broken-link', sprintf( __( 'The link %s leads to no page of this site.', 'leitvia-studio' ), $href ), __( 'Repair it with leitvia/repair-links and the page the link text names, or ask the person where it should go.', 'leitvia-studio' ) )
				/* translators: 1: link, 2: current address. */
				: self::finding( 'leitvia/broken-link', sprintf( __( 'The link %1$s leads to the old address of %2$s.', 'leitvia-studio' ), $href, $to ), __( 'Repair it with leitvia/repair-links and auto.', 'leitvia-studio' ) );
		}
		if ( '' !== trim( $post->post_content ) && ! has_blocks( $post->post_content ) ) {
			$findings[] = self::finding( 'leitvia/classic-content', __( 'The page holds classic content without blocks.', 'leitvia-studio' ), __( 'Turn it into blocks with leitvia/update-content and content.', 'leitvia-studio' ) );
		}
		$legal = self::LEGAL_SLUGS[ $post->post_name ] ?? '';
		if ( in_array( '' !== $kind ? $kind : $legal, self::LEGAL_SLUGS, true ) ) {
			foreach ( self::placeholders( $post->post_content ) as $placeholder ) {
				/* translators: %s: placeholder in square brackets, or a sentence that says it is a placeholder. */
				$findings[] = self::finding( 'leitvia/legal-placeholder', sprintf( __( 'The legal text still holds the placeholder %s.', 'leitvia-studio' ), $placeholder ), __( 'Replace it with the fact the person supplied, or list it as open for the person.', 'leitvia-studio' ) );
			}
		}
		if ( '' === $kind && '' !== $legal ) {
			/* translators: %s: page kind. */
			$findings[] = self::finding( 'leitvia/page-kind', sprintf( __( 'The page looks like a legal text but has no page kind; expected %s.', 'leitvia-studio' ), $legal ), __( 'A person sets the page kind so legal texts stay at "suggest".', 'leitvia-studio' ) );
		}
		return array(
			'post'     => $post->ID,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'kind'     => $kind,
			'findings' => $findings,
		);
	}

	/**
	 * Findings about the whole site. Error: closed to search engines (blog_public 0) on a production site; local,
	 * development and staging sites and the Playground are left alone. Warning: no published imprint, contact or
	 * editorial page (by page kind or slug) while there are published posts; one of the latest news posts without a
	 * featured image of at least 1200 px width; an author of those without a biography.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function site(): array {
		$findings = array();
		/**
		 * Whether the site is a preview, test or staging copy, where a site closed to search engines is no finding.
		 *
		 * @param bool $preview Default: the environment type is not production.
		 */
		if ( ! get_option( 'blog_public' ) && ! apply_filters( 'leitvia_studio_preview_site', 'production' !== wp_get_environment_type() ) ) {
			$findings[] = self::finding( 'leitvia/search-blocked', __( 'The site asks search engines not to index it, so no page appears in search results or Google News.', 'leitvia-studio' ), __( 'A person turns off "Discourage search engines from indexing this site" under Settings, Reading.', 'leitvia-studio' ), 'error' );
		}
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => self::NEWS_CHECKED,
				'lang'             => '',
				'suppress_filters' => false,
			)
		);
		if ( ! $posts ) {
			return $findings;
		}
		$names = array(
			'imprint'   => __( 'imprint', 'leitvia-studio' ),
			'contact'   => __( 'contact page', 'leitvia-studio' ),
			'editorial' => __( 'editorial guidelines', 'leitvia-studio' ),
		);
		foreach ( array_diff( self::NEWS_PAGES, self::pages() ) as $kind ) {
			/* translators: %s: kind of page, for example imprint. */
			$findings[] = self::finding( 'leitvia/missing-page', sprintf( __( 'The site publishes posts but has no published %s; search engines and Google News look for it to judge who stands behind the news.', 'leitvia-studio' ), $names[ $kind ] ), __( 'Create it with leitvia/create-content and its kind, with facts the person supplies.', 'leitvia-studio' ) );
		}
		$authors = array();
		foreach ( $posts as $post ) {
			if ( ! \LeitviaStudio\News\Schema::is_news( $post ) || ! Gateway\Scope::readable( $post ) ) {
				continue;
			}
			$image = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $post ), 'full' );
			if ( ! $image || $image[1] < \LeitviaStudio\News\Schema::IMAGE_MIN_WIDTH ) {
				/* translators: 1: post title, 2: post ID, 3: width in pixels. */
				$findings[] = self::finding( 'leitvia/news-image', sprintf( __( 'The news post "%1$s" (%2$d) has no featured image of at least %3$d px width, which Google News and Discover need for a large preview.', 'leitvia-studio' ), $post->post_title, $post->ID, \LeitviaStudio\News\Schema::IMAGE_MIN_WIDTH ), __( 'Set a larger featured image with leitvia/update-content.', 'leitvia-studio' ) );
			}
			$author = (int) $post->post_author;
			if ( ! isset( $authors[ $author ] ) && '' === trim( (string) get_the_author_meta( 'description', $author ) ) ) {
				$authors[ $author ] = true;
				/* translators: %s: author name. */
				$findings[] = self::finding( 'leitvia/author-bio', sprintf( __( 'The author %s writes news but has no biography; readers and search engines cannot see who they are.', 'leitvia-studio' ), get_the_author_meta( 'display_name', $author ) ), __( 'The author adds a short biography in their profile.', 'leitvia-studio' ) );
			}
		}
		return $findings;
	}

	/**
	 * Kinds of NEWS_PAGES that a published page has, by page kind or by slug, in any language.
	 *
	 * @return list<string>
	 */
	private static function pages(): array {
		$slugs = array_filter( self::LEGAL_SLUGS + self::PAGE_SLUGS, static fn( string $kind ): bool => in_array( $kind, self::NEWS_PAGES, true ) );
		$query = array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'lang'             => '',
			'suppress_filters' => false,
		);
		$found = array();
		foreach ( get_posts( $query + array( 'post_name__in' => array_keys( $slugs ) ) ) as $id ) {
			$found[] = $slugs[ get_post_field( 'post_name', (int) $id ) ] ?? '';
		}
		$kinds = get_posts(
			$query + array(
				'meta_key'     => '_leitvia_page_kind', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few pages carry a kind.
				'meta_value'   => self::NEWS_PAGES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Few pages carry a kind.
				'meta_compare' => 'IN',
			)
		);
		foreach ( $kinds as $id ) {
			$found[] = (string) get_post_meta( (int) $id, '_leitvia_page_kind', true );
		}
		return array_values( array_unique( array_intersect( $found, self::NEWS_PAGES ) ) );
	}

	/**
	 * Internal links of the content that lead to no post, archive or file: link => '' for none, or the current address
	 * when the link names the old slug of a renamed post. Nothing is requested.
	 *
	 * @param string $content Block markup.
	 * @return array<string, string>
	 */
	private static function broken_links( string $content ): array {
		$tags   = new \WP_HTML_Tag_Processor( $content );
		$broken = array();
		// resolve() gives null for a link without a target, '' for one that leads somewhere.
		while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$href = $tags->get_attribute( 'href' );
			$href = is_string( $href ) ? trim( $href ) : '';
			$to   = '' !== $href && ! isset( $broken[ $href ] ) && Maintain::internal( $href ) ? Maintain::resolve( $href ) : '';
			if ( '' !== $to ) {
				$broken[ $href ] = (string) $to;
			}
		}
		return $broken;
	}

	/**
	 * Placeholders in the text of a legal page, each once: square brackets such as [Organization name] or [date], and
	 * sentences that say they are a placeholder. Shortcodes that are registered are no placeholders. Each block starts
	 * a new line, so a sentence ends with its block.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	private static function placeholders( string $content ): array {
		$text = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', "\n", $content ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		preg_match_all( '/\[([^\[\]\n]{2,120})\]/u', $text, $matches );
		$found = array();
		foreach ( $matches[1] as $inner ) {
			if ( ! shortcode_exists( strtok( $inner, ' ' ) ) ) {
				$found[ '[' . $inner . ']' ] = true;
			}
		}
		// Text that says it is a placeholder, as the legal pages of the recipes do ("Placeholder: …", "This page is a
		// placeholder …"): the sentence once.
		preg_match_all( '/[^.!?:\n]*\bplaceholder\b[^.!?\n]*/iu', $text, $said );
		foreach ( $said[0] as $sentence ) {
			$found[ mb_substr( trim( $sentence ), 0, 120 ) ] = true;
		}
		return array_keys( $found );
	}

	/**
	 * Presets the markup names that the active global settings do not define, as "type/slug".
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	private static function drift( string $content ): array {
		preg_match_all( '/(?:var:preset\|([a-z-]+)\||--wp--preset--([a-z-]+?)--)([a-z0-9-]+)/', $content, $matches, PREG_SET_ORDER );
		preg_match_all( '/"(textColor|backgroundColor|borderColor|fontSize)":"([a-z0-9-]+)"/', $content, $attrs, PREG_SET_ORDER );
		$used = array();
		foreach ( $matches as $match ) {
			$used[] = array( '' !== $match[1] ? $match[1] : $match[2], $match[3] );
		}
		foreach ( $attrs as $attr ) {
			$used[] = array( 'fontSize' === $attr[1] ? 'font-size' : 'color', $attr[2] );
		}
		$known   = array();
		$missing = array();
		foreach ( $used as [ $type, $slug ] ) {
			if ( ! isset( self::PRESETS[ $type ] ) ) {
				continue;
			}
			$known[ $type ] ??= self::slugs( self::PRESETS[ $type ] );
			if ( ! isset( $known[ $type ][ $slug ] ) ) {
				$missing[ "{$type}/{$slug}" ] = true;
			}
		}
		return array_keys( $missing );
	}

	/**
	 * Slugs of a preset list of the global settings, theme, default and custom origins together.
	 *
	 * @param string[] $path Settings path.
	 * @return array<string, true>
	 */
	private static function slugs( array $path ): array {
		$slugs = array();
		foreach ( (array) wp_get_global_settings( $path ) as $origin ) {
			foreach ( is_array( $origin ) ? $origin : array() as $preset ) {
				if ( isset( $preset['slug'] ) ) {
					$slugs[ (string) $preset['slug'] ] = true;
				}
			}
		}
		return $slugs;
	}

	/**
	 * One audit-only finding on the whole page or site, a warning unless said otherwise.
	 *
	 * @param string $check    Check name.
	 * @param string $message  What is wrong.
	 * @param string $fix      How to fix it.
	 * @param string $severity error or warning.
	 * @return array<string, string>
	 */
	private static function finding( string $check, string $message, string $fix, string $severity = 'warning' ): array {
		return array(
			'check'    => $check,
			'severity' => $severity,
			'block'    => '',
			'message'  => $message,
			'fix'      => $fix,
		);
	}
}
