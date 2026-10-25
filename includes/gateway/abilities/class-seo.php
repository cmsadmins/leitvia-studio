<?php
/**
 * SEO abilities: read and write the search data of posts, pages and entries through the active SEO plugin.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/get-seo and leitvia/update-seo. A small adapter maps title, description, robots and the social
 * fields to the post meta of Yoast SEO, Rank Math, SEOPress or The SEO Framework. The meta keys that update-seo writes
 * belong to the state of a content type (Content::state()), so they are hashed, packaged and rolled back like a title.
 */
final class Seo {

	/**
	 * Most posts per call of get-seo.
	 */
	public const MAX_POSTS = 50;

	/**
	 * Most characters of a title and range of a description; search engines cut longer ones.
	 */
	public const TITLE_MAX = 60;

	public const DESCRIPTION_MIN = 50;

	public const DESCRIPTION_MAX = 160;

	/**
	 * Supported SEO plugins: constant that marks them active, name, meta key per field and the value that switches
	 * noindex or nofollow on. Rank Math keeps robots as one list. All in One SEO keeps its data in a table of its own;
	 * it is detected so nothing writes beside it, but not read.
	 */
	public const PLUGINS = array(
		'yoast'     => array(
			'constant' => 'WPSEO_VERSION',
			'name'     => 'Yoast SEO',
			'on'       => '1',
			'meta'     => array(
				'title'          => '_yoast_wpseo_title',
				'description'    => '_yoast_wpseo_metadesc',
				'noindex'        => '_yoast_wpseo_meta-robots-noindex',
				'nofollow'       => '_yoast_wpseo_meta-robots-nofollow',
				'canonical'      => '_yoast_wpseo_canonical',
				'og_title'       => '_yoast_wpseo_opengraph-title',
				'og_description' => '_yoast_wpseo_opengraph-description',
				'og_image'       => '_yoast_wpseo_opengraph-image',
				'focus_keyword'  => '_yoast_wpseo_focuskw',
				'schema'         => '_yoast_wpseo_schema_page_type',
			),
		),
		'rank-math' => array(
			'constant' => 'RANK_MATH_VERSION',
			'name'     => 'Rank Math',
			'on'       => '',
			'meta'     => array(
				'title'          => 'rank_math_title',
				'description'    => 'rank_math_description',
				'robots'         => 'rank_math_robots',
				'canonical'      => 'rank_math_canonical_url',
				'og_title'       => 'rank_math_facebook_title',
				'og_description' => 'rank_math_facebook_description',
				'og_image'       => 'rank_math_facebook_image',
				'focus_keyword'  => 'rank_math_focus_keyword',
				'schema'         => 'rank_math_rich_snippet',
			),
		),
		'seopress'  => array(
			'constant' => 'SEOPRESS_VERSION',
			'name'     => 'SEOPress',
			'on'       => 'yes',
			'meta'     => array(
				'title'          => '_seopress_titles_title',
				'description'    => '_seopress_titles_desc',
				'noindex'        => '_seopress_robots_index',
				'nofollow'       => '_seopress_robots_follow',
				'canonical'      => '_seopress_robots_canonical',
				'og_title'       => '_seopress_social_fb_title',
				'og_description' => '_seopress_social_fb_desc',
				'og_image'       => '_seopress_social_fb_img',
				'focus_keyword'  => '_seopress_analysis_target_kw',
			),
		),
		'tsf'       => array(
			'constant' => 'THE_SEO_FRAMEWORK_VERSION',
			'name'     => 'The SEO Framework',
			'on'       => '1',
			'meta'     => array(
				'title'          => '_genesis_title',
				'description'    => '_genesis_description',
				'noindex'        => '_genesis_noindex',
				'nofollow'       => '_genesis_nofollow',
				'canonical'      => '_genesis_canonical_uri',
				'og_title'       => '_open_graph_title',
				'og_description' => '_open_graph_description',
				'og_image'       => '_social_image_url',
			),
		),
		'aioseo'    => array(
			'constant' => 'AIOSEO_VERSION',
			'name'     => 'All in One SEO',
			'on'       => '',
			'meta'     => array(),
		),
	);

	/**
	 * Fields update-seo writes.
	 */
	private const WRITABLE = array( 'title', 'description', 'noindex', 'nofollow', 'robots' );

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$string = array( 'type' => 'string' );
		$bool   = array( 'type' => 'boolean' );
		Abilities::read(
			'leitvia/get-seo',
			__( 'Get SEO data', 'leitvia-studio' ),
			__( 'Returns the SEO data of posts, pages and entries from the active SEO plugin. Supported are Yoast SEO, Rank Math, SEOPress and The SEO Framework. Per item: title, meta description, canonical, noindex, nofollow, Open Graph title, description and image, focus keyword and schema type. An empty value means the plugin falls back to its template. defaults holds what WordPress itself would print and a description suggested from the excerpt; without a supported plugin seo is null and notice says why. hash is the base_hash of leitvia/update-seo. IDs that are no readable content come back under skipped.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'posts' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_POSTS,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'required'             => array( 'posts' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'plugin'  => array(
						'type'       => array( 'object', 'null' ),
						'properties' => array(
							'key'      => $string,
							'name'     => $string,
							'writable' => $bool,
						),
					),
					'notice'  => $string,
					'items'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'post'     => array( 'type' => 'integer' ),
								'type'     => $string,
								'status'   => $string,
								'title'    => $string,
								'url'      => $string,
								'hash'     => $string,
								'seo'      => array( 'type' => array( 'object', 'null' ) ),
								'defaults' => array( 'type' => 'object' ),
							),
						),
					),
					'skipped' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			),
			array( self::class, 'get' )
		);
		Abilities::write(
			'leitvia/update-seo',
			__( 'Update SEO data', 'leitvia-studio' ),
			/* translators: 1: most characters of a title, 2: fewest characters of a description, 3: most characters of a description */
			sprintf( __( 'Writes SEO title, meta description and robots of one post through the active SEO plugin. It covers posts, pages and entries in Yoast SEO, Rank Math, SEOPress or The SEO Framework; without one of them it is rejected with leitvia_no_seo_plugin. Read the post with leitvia/get-seo first and send its hash as base_hash. A title has at most %1$d characters, a description %2$d to %3$d; an empty string hands the field back to the template of the plugin. Say only what the page says: every number in title or description must appear in the title, excerpt or content of the post. Autonomy and change packages work as for leitvia/update-content; a live write gets a change package for leitvia/rollback.', 'leitvia-studio' ), self::TITLE_MAX, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX ),
			'maintain',
			array(
				'destructive' => true,
				'idempotent'  => false,
			),
			array(
				'properties'           => array(
					'post'        => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'title'       => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'description' => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
					'noindex'     => array(
						'type'        => 'boolean',
						'description' => __( 'Keep the page out of search results. false hands it back to the default of the plugin.', 'leitvia-studio' ),
					),
					'nofollow'    => array(
						'type'        => 'boolean',
						'description' => __( 'Ask search engines not to follow the links of the page.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update' )
		);
	}

	/**
	 * Key of the active SEO plugin, '' for none.
	 */
	public static function plugin(): string {
		$found = '';
		foreach ( self::PLUGINS as $key => $plugin ) {
			if ( defined( $plugin['constant'] ) ) {
				$found = $key;
				break;
			}
		}
		/**
		 * Filters the SEO plugin whose meta Leitvia Studio reads and writes: yoast, rank-math, seopress, tsf, aioseo, or
		 * '' for none.
		 *
		 * @param string $found Key of the detected plugin.
		 */
		$key = (string) apply_filters( 'leitvia_studio_seo_adapter', $found );
		return isset( self::PLUGINS[ $key ] ) ? $key : '';
	}

	/**
	 * Meta keys update-seo writes for the active plugin; part of the state of a content type.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		$key = self::plugin();
		return '' === $key ? array() : array_values( array_intersect_key( self::PLUGINS[ $key ]['meta'], array_flip( self::WRITABLE ) ) );
	}

	/**
	 * Execute callback of get-seo.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>
	 */
	public static function get( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$ids      = array_slice( array_values( array_unique( array_map( 'intval', (array) ( $input['posts'] ?? array() ) ) ) ), 0, self::MAX_POSTS );
		$key      = self::plugin();
		$writable = '' !== $key && array() !== self::PLUGINS[ $key ]['meta'];
		$items    = array();
		$skipped  = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! Scope::readable( $post ) || ! in_array( $post->post_type, Content::types(), true ) ) {
				$skipped[] = $id;
				continue;
			}
			$items[] = array(
				'post'     => $id,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'title'    => $post->post_title,
				'url'      => (string) get_permalink( $post ),
				'hash'     => Hashes::document( $id ),
				'seo'      => $writable ? self::values( $id, $key ) : null,
				'defaults' => self::defaults( $post ),
			);
		}
		$notice = match ( true ) {
			'' === $key => __( 'No supported SEO plugin is active. defaults shows what WordPress prints; the description is only a suggestion from the excerpt, WordPress prints no meta description. leitvia/update-seo needs Yoast SEO, Rank Math, SEOPress or The SEO Framework.', 'leitvia-studio' ),
			! $writable => __( 'All in One SEO keeps its data in a table of its own, which Leitvia Studio does not read or write. defaults shows what WordPress would print.', 'leitvia-studio' ),
			default     => '',
		};
		return array(
			'plugin'  => '' === $key ? null : array(
				'key'      => $key,
				'name'     => self::PLUGINS[ $key ]['name'],
				'writable' => $writable,
			),
			'notice'  => $notice,
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * Values the plugin stores for a post; empty strings where it uses its template.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Plugin key.
	 * @return array<string, mixed>
	 */
	private static function values( int $id, string $key ): array {
		$plugin = self::PLUGINS[ $key ];
		$get    = static fn( string $field ) => isset( $plugin['meta'][ $field ] ) ? get_post_meta( $id, $plugin['meta'][ $field ], true ) : '';
		$robots = self::robots( $plugin, static fn( string $field ) => $get( $field ) );
		$values = array();
		foreach ( array( 'title', 'description', 'canonical', 'og_title', 'og_description', 'og_image', 'focus_keyword', 'schema' ) as $field ) {
			$value            = $get( $field );
			$values[ $field ] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		}
		return $values + $robots;
	}

	/**
	 * Flags noindex and nofollow from the stored values of a plugin.
	 *
	 * @param array<string, mixed> $plugin Entry of PLUGINS.
	 * @param callable             $get    Stored value of a field.
	 * @return array{noindex: bool, nofollow: bool}
	 */
	private static function robots( array $plugin, callable $get ): array {
		if ( isset( $plugin['meta']['robots'] ) ) {
			$list = array_map( 'strval', (array) $get( 'robots' ) );
			return array(
				'noindex'  => in_array( 'noindex', $list, true ),
				'nofollow' => in_array( 'nofollow', $list, true ),
			);
		}
		return array(
			'noindex'  => $plugin['on'] === (string) $get( 'noindex' ),
			'nofollow' => $plugin['on'] === (string) $get( 'nofollow' ),
		);
	}

	/**
	 * What WordPress prints without an SEO plugin, and a description suggested from the excerpt or the text.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private static function defaults( \WP_Post $post ): array {
		$separator = (string) apply_filters( 'document_title_separator', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$site      = (string) get_bloginfo( 'name' );
		$front     = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post->ID;
		$title     = $front ? trim( $site . ' ' . $separator . ' ' . get_bloginfo( 'description' ), ' ' . $separator ) : $post->post_title . ' ' . $separator . ' ' . $site;
		$text      = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ), 30, '' );
		$text      = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( mb_strlen( $text ) > self::DESCRIPTION_MAX ) {
			$text = mb_substr( $text, 0, self::DESCRIPTION_MAX );
			$text = (string) preg_replace( '/\s+\S*$/u', '', $text );
		}
		$schema = array();
		if ( 'post' === $post->post_type && leitvia_studio_setting( 'news_schema' ) && ! leitvia_studio_seo_plugin_active() ) {
			$schema[] = (string) \LeitviaStudio\News\Schema::article( $post )['@type'];
		}
		return array(
			'title'       => html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'description' => $text,
			'canonical'   => (string) get_permalink( $post ),
			'noindex'     => ! get_option( 'blog_public' ) || 'publish' !== $post->post_status,
			'schema'      => $schema,
		);
	}

	/**
	 * Operation of update-seo.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update( array $input ) {
		$post   = (int) ( $input['post'] ?? 0 );
		$target = array( 'post' => $post );
		$key    = self::plugin();
		if ( '' === $key || array() === self::PLUGINS[ $key ]['meta'] ) {
			return Rejection::make( 'leitvia_no_seo_plugin', __( 'No SEO plugin Leitvia Studio can write to is active (Yoast SEO, Rank Math, SEOPress or The SEO Framework). Change the excerpt with leitvia/update-content instead, or ask a person to install one.', 'leitvia-studio' ), 422, 'seo_plugin', $target, 'leitvia/update-content', 'never' );
		}
		$texts = array_filter( array_intersect_key( $input, array_flip( array( 'title', 'description' ) ) ), 'is_string' );
		$texts = array_map( static fn( string $text ): string => trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), $texts );
		$flags = array_map( 'boolval', array_intersect_key( $input, array_flip( array( 'noindex', 'nofollow' ) ) ) );
		if ( ! $texts && ! $flags ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: title, description, noindex or nofollow.', 'leitvia-studio' ), $target );
		}
		if ( isset( $texts['title'] ) && mb_strlen( $texts['title'] ) > self::TITLE_MAX ) {
			/* translators: %d: most characters */
			return Block_Edit::invalid( 'length', sprintf( __( 'The title is too long; search results cut it after about %d characters. Shorten it.', 'leitvia-studio' ), self::TITLE_MAX ), $target );
		}
		$length = isset( $texts['description'] ) ? mb_strlen( $texts['description'] ) : 0;
		if ( $length && ( $length < self::DESCRIPTION_MIN || $length > self::DESCRIPTION_MAX ) ) {
			/* translators: 1: fewest characters, 2: most characters */
			return Block_Edit::invalid( 'length', sprintf( __( 'A description has %1$d to %2$d characters. Summarize what the page offers in one or two sentences.', 'leitvia-studio' ), self::DESCRIPTION_MIN, self::DESCRIPTION_MAX ), $target );
		}
		$plugin = self::PLUGINS[ $key ];
		return array(
			'area'   => 'content',
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $plugin, $texts, $flags, $target ) {
				$meta = (array) ( $state['meta_input'] ?? array() );
				if ( ! array_key_exists( $plugin['meta']['title'], $meta ) ) {
					return Block_Edit::invalid( 'post_type', __( 'SEO data can change on posts, pages and entries only.', 'leitvia-studio' ), $target );
				}
				$unsupported = self::unsupported( implode( ' ', $texts ), $state );
				if ( $unsupported ) {
					/* translators: %s: numbers */
					return Block_Edit::invalid( 'unsupported_fact', sprintf( __( 'The page does not say %s. Use only facts from the title, excerpt or content of the post.', 'leitvia-studio' ), implode( ', ', $unsupported ) ), $target, 'leitvia/get-page-outline' );
				}
				foreach ( $texts as $field => $text ) {
					$meta[ $plugin['meta'][ $field ] ] = $text;
				}
				if ( $flags && isset( $plugin['meta']['robots'] ) ) {
					$list  = array_values( array_filter( array_map( 'strval', (array) $meta[ $plugin['meta']['robots'] ] ) ) );
					$flags = $flags + self::robots( $plugin, static fn() => $list );
					$list  = array_values( array_diff( $list, array( 'index', 'noindex', 'nofollow' ) ) );
					array_unshift( $list, $flags['noindex'] ? 'noindex' : 'index' );
					$meta[ $plugin['meta']['robots'] ] = $flags['nofollow'] ? array_merge( $list, array( 'nofollow' ) ) : $list;
				}
				foreach ( $flags as $field => $on ) {
					if ( isset( $plugin['meta'][ $field ] ) ) {
						$meta[ $plugin['meta'][ $field ] ] = $on ? $plugin['on'] : '';
					}
				}
				$state['meta_input'] = $meta;
				return $state;
			},
		);
	}

	/**
	 * Numbers of a text that the title, excerpt and content of the post do not contain. A number is the commonest fact
	 * a summary invents (prices, years, counts).
	 * ponytail: numbers only; names and claims without digits pass, the skill asks agents to keep to the page.
	 *
	 * @param string               $text  New title and description.
	 * @param array<string, mixed> $state State of the post.
	 * @return list<string>
	 */
	private static function unsupported( string $text, array $state ): array {
		$source = (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $state['post_title'] . ' ' . $state['post_excerpt'] . ' ' . $state['post_content'] . ' ' . get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		preg_match_all( '/\d+(?:[.,:]\d+)*/u', $text, $numbers );
		return array_values( array_unique( array_filter( $numbers[0], static fn( string $number ): bool => ! str_contains( $source, $number ) ) ) );
	}
}
