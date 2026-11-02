<?php
/**
 * Google News sitemap.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /news.xml: the news posts (Schema::is_news()) of the last 48 hours, at most 1000, newest first, with
 * the news:news details Google News reads. One sitemap for all languages at the address of the site root, named in
 * robots.txt. Active with the setting news_sitemap on a site open to search engines while no other news sitemap
 * plugin is active; inactive the address registers no rule and answers 404. The address holds no "sitemap": the
 * nginx rules many hosts ship for Yoast SEO send every *sitemap*.xml to Yoast and would answer 404.
 */
final class Sitemap {

	/**
	 * Query var of the rewrite rule.
	 */
	public const QUERY_VAR = 'leitvia_news_sitemap';

	/**
	 * Transient that caches the XML.
	 */
	public const CACHE = 'leitvia_news_sitemap';

	/**
	 * Option that remembers the rule registered at the last flush, '0' for none; a change asks for a flush.
	 */
	public const RULES_OPTION = 'leitvia_studio_news_sitemap_rules';

	/**
	 * Rewrite rule of the address.
	 */
	public const RULE = '^news\.xml$';

	/**
	 * Most entries Google News reads from one sitemap.
	 */
	public const MAX = 1000;

	/**
	 * Age of the oldest entry in hours.
	 */
	public const HOURS = 48;

	/**
	 * Registers the rewrite rule while active and asks for a flush when that changed since the last request, after a
	 * plugin update, a change of the setting or of the search engine visibility. Runs on init, before
	 * Registrar::maybe_flush().
	 */
	public static function register(): void {
		$enabled = self::enabled();
		if ( $enabled ) {
			add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
		$rule = $enabled ? self::RULE : '0';
		if ( get_option( self::RULES_OPTION ) !== $rule ) {
			update_option( self::RULES_OPTION, $rule, true );
			update_option( \LeitviaStudio\Model\Model::FLUSH_OPTION, 1, true );
		}
	}

	/**
	 * Adds the query var.
	 *
	 * @param array<string> $vars Public query vars.
	 * @return array<string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Whether the news sitemap is served: setting news_sitemap, the site open to search engines and no news sitemap of
	 * Yoast News SEO, Rank Math Pro or XML Sitemap and Google News.
	 */
	public static function enabled(): bool {
		$enabled = leitvia_studio_setting( 'news_sitemap' ) && get_option( 'blog_public' )
			&& ! class_exists( 'WPSEO_News' ) && ! class_exists( '\RankMathPro\Sitemap\News_Sitemap' ) && ! defined( 'XMLSF_VERSION' );
		/**
		 * Whether Leitvia Studio serves /news.xml and names it in robots.txt.
		 *
		 * @param bool $enabled Setting on, site public and no other news sitemap plugin active.
		 */
		return (bool) apply_filters( 'leitvia_studio_news_sitemap_enabled', $enabled );
	}

	/**
	 * Address of the sitemap. The option home, not home_url(): under Polylang that carries a language prefix, and one
	 * sitemap lists all languages.
	 */
	public static function url(): string {
		return untrailingslashit( (string) get_option( 'home' ) ) . '/news.xml';
	}

	/**
	 * Prints the sitemap and ends the request, or answers 404 while inactive. Runs on template_redirect.
	 */
	public static function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::xml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped in xml().
		exit;
	}

	/**
	 * The sitemap XML, from the cache when it is there.
	 */
	public static function xml(): string {
		// ponytail: one transient for all languages; split per language if a site publishes hundreds of news a day.
		$xml = get_transient( self::CACHE );
		if ( is_string( $xml ) ) {
			return $xml;
		}
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => self::MAX,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', time() - self::HOURS * HOUR_IN_SECONDS ),
					),
				),
				// Every language: Polylang limits queries to the current one unless lang is given.
				'lang'             => '',
				'suppress_filters' => false,
			)
		);
		$name = self::escape( Schema::text( get_bloginfo( 'name' ) ) );
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
		foreach ( $posts as $post ) {
			if ( ! Schema::is_news( $post ) ) {
				continue;
			}
			$xml .= '<url><loc>' . self::escape( (string) get_permalink( $post ) ) . '</loc><news:news><news:publication>'
				. '<news:name>' . $name . '</news:name><news:language>' . self::escape( self::language( $post ) ) . '</news:language>'
				. '</news:publication><news:publication_date>' . self::escape( (string) get_the_date( 'c', $post ) ) . '</news:publication_date>'
				. '<news:title>' . self::escape( Schema::text( get_the_title( $post ) ) ) . '</news:title></news:news></url>' . "\n";
		}
		$xml .= '</urlset>' . "\n";
		set_transient( self::CACHE, $xml, 15 * MINUTE_IN_SECONDS );
		return $xml;
	}

	/**
	 * Language of a post for news:language: the ISO 639 code of its locale, zh-cn or zh-tw for Chinese as Google asks.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function language( WP_Post $post ): string {
		$locale = Schema::locale( $post );
		if ( str_starts_with( $locale, 'zh_' ) ) {
			return in_array( $locale, array( 'zh_TW', 'zh_HK' ), true ) ? 'zh-tw' : 'zh-cn';
		}
		return strtolower( explode( '_', $locale )[0] );
	}

	/**
	 * Adds the sitemap line to robots.txt while active.
	 *
	 * @param string $output Robots.txt.
	 */
	public static function robots( string $output ): string {
		return self::enabled() ? rtrim( $output ) . "\nSitemap: " . self::url() . "\n" : $output;
	}

	/**
	 * Drops the cache when a post changes status. Runs on transition_post_status.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function forget( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'post' === $post->post_type ) {
			delete_transient( self::CACHE );
		}
	}

	/**
	 * Text for an XML element.
	 *
	 * @param string $text Plain text.
	 */
	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
