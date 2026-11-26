<?php
/**
 * The file llms.txt and the AI crawlers in robots.txt.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Seo;

use LeitviaStudio\Gateway\Outline;
use LeitviaStudio\News\Schema;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt (llmstxt.org) from what the site knows, so it never goes stale: the site name as title, the summary
 * of the setting llms_summary or else the tagline as quote, then the hubs (pages of the page frame hub), the pages with
 * a service and price (Cluster), the pages of the setting llms_pages and, with the setting llms_news, the latest posts,
 * each a Markdown link with its excerpt. Built on
 * request, no file and no rewrite rule: the address answers like a 404 of WordPress would, before the template loads.
 * Off with the setting llms_txt, on a site closed to search engines and while an SEO plugin is active, which may serve
 * its own.
 *
 * The robots.txt keeps the crawlers of AI search and assistants allowed, as WordPress does for every crawler; with the
 * setting ai_crawlers off it disallows the agents of AI_CRAWLERS for the whole site. The paths of the setting
 * robots_disallow are closed to every crawler (group User-agent: *).
 */
final class Llms {

	/**
	 * User agents of crawlers that train or feed AI models and assistants, as their operators document them.
	 */
	public const AI_CRAWLERS = array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Meta-ExternalAgent', 'Amazonbot', 'Bytespider', 'cohere-ai', 'MistralAI-User' );

	/**
	 * Most pages listed per section.
	 */
	public const MAX_PAGES = 50;

	/**
	 * Most latest posts llms.txt lists (setting llms_news, field news of set-llms).
	 */
	public const MAX_NEWS = 50;

	/**
	 * Most Disallow paths of the setting robots_disallow.
	 */
	public const MAX_DISALLOW = 20;

	/**
	 * A Disallow path of robots.txt: a leading slash, at most 200 characters, no spaces, line breaks or #, as JSON
	 * Schema pattern (no delimiters). * and $ are wildcards for the crawlers that know them.
	 */
	public const DISALLOW_PATTERN = '^/[^\s#]{0,199}$';

	/**
	 * Whether the site serves llms.txt.
	 */
	public static function enabled(): bool {
		return (bool) leitvia_studio_setting( 'llms_txt' ) && '' === self::held_back();
	}

	/**
	 * Why a requested llms.txt is not served, as a sentence, or '' when nothing holds it back. The setting llms_txt is
	 * what a person or agent asked for; this is what keeps it from taking effect.
	 */
	public static function held_back(): string {
		if ( ! get_option( 'blog_public' ) ) {
			return __( 'llms.txt is saved as on and takes effect once the site is open to search engines (Settings, Reading); until then /llms.txt answers 404.', 'leitvia-studio' );
		}
		if ( leitvia_studio_seo_plugin_active() ) {
			return __( 'llms.txt is saved as on, but an SEO plugin is active and may serve its own file, so Leitvia Studio serves none.', 'leitvia-studio' );
		}
		return '';
	}

	/**
	 * Address of llms.txt at the root of the site.
	 */
	public static function url(): string {
		return home_url( '/llms.txt' );
	}

	/**
	 * Prints llms.txt for a request of its address and stops. Runs on template_redirect, before canonical redirects.
	 */
	public static function maybe_render(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared only.
		if ( ! self::enabled() || untrailingslashit( (string) wp_parse_url( self::url(), PHP_URL_PATH ) ) !== untrailingslashit( $path ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text, not HTML.
		exit;
	}

	/**
	 * The text of llms.txt.
	 */
	public static function text(): string {
		$summary = trim( (string) leitvia_studio_setting( 'llms_summary' ) );
		$summary = '' !== $summary ? $summary : Schema::text( get_bloginfo( 'description' ) );
		$lines   = array( '# ' . self::line( Schema::text( get_bloginfo( 'name' ) ) ), '' );
		if ( '' !== $summary ) {
			$lines[] = '> ' . self::line( $summary );
			$lines[] = '';
		}
		$extra = array_values( array_filter( array_map( 'get_post', array_slice( (array) leitvia_studio_setting( 'llms_pages' ), 0, self::MAX_PAGES ) ), static fn( $post ): bool => $post instanceof WP_Post && 'publish' === $post->post_status && ! post_password_required( $post ) ) );
		$seen  = array();
		/**
		 * Sections of llms.txt below the summary: a heading each with its pages, as Markdown links with their excerpts.
		 * A page listed in an earlier section is left out of later ones, only published pages without a password count,
		 * at most MAX_PAGES per section. An add-on adds a section of its own, for example the sections of a
		 * documentation: $sections[ __( 'Guides', 'my-addon' ) ] = $pages.
		 *
		 * @param array<string, mixed> $sections Lists of WP_Post by heading: Topics, Services and prices, Pages, Latest posts.
		 */
		$sections = (array) apply_filters(
			'leitvia_studio_llms_sections',
			array(
				__( 'Topics', 'leitvia-studio' )       => Cluster::hub_pages(),
				__( 'Services and prices', 'leitvia-studio' ) => Cluster::service_pages(),
				__( 'Pages', 'leitvia-studio' )        => $extra,
				__( 'Latest posts', 'leitvia-studio' ) => self::news(),
			)
		);
		foreach ( $sections as $heading => $pages ) {
			$links = array();
			foreach ( array_slice( (array) $pages, 0, self::MAX_PAGES ) as $page ) {
				if ( ! $page instanceof WP_Post || isset( $seen[ $page->ID ] ) || 'publish' !== $page->post_status || post_password_required( $page ) ) {
					continue;
				}
				$seen[ $page->ID ] = true;
				$links[]           = self::link( $page );
			}
			if ( $links ) {
				array_push( $lines, '## ' . $heading, '', ...$links );
				$lines[] = '';
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * The latest published posts without a password, as many as the setting llms_news asks for (at most MAX_NEWS),
	 * none by default.
	 *
	 * @return list<WP_Post>
	 */
	private static function news(): array {
		$count = min( self::MAX_NEWS, (int) leitvia_studio_setting( 'llms_news' ) );
		if ( $count < 1 ) {
			return array();
		}
		return array_values(
			get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $count,
					'has_password'   => false,
				)
			)
		);
	}

	/**
	 * The Disallow paths of the setting robots_disallow that fit DISALLOW_PATTERN, once each, at most MAX_DISALLOW.
	 *
	 * @param mixed $paths Submitted paths.
	 * @return list<string>
	 */
	public static function disallow_paths( $paths ): array {
		$kept = array();
		foreach ( (array) $paths as $path ) {
			if ( is_string( $path ) && preg_match( '#' . str_replace( '#', '\#', self::DISALLOW_PATTERN ) . '#u', $path ) ) {
				$kept[] = $path;
			}
		}
		return array_slice( array_values( array_unique( $kept ) ), 0, self::MAX_DISALLOW );
	}

	/**
	 * A Markdown link to a page with its excerpt and, for a service, its price. A post without an excerpt gets the
	 * first 40 words of its text.
	 *
	 * @param WP_Post $page Page.
	 */
	private static function link( WP_Post $page ): string {
		$service = Cluster::service( $page->ID );
		$note    = Schema::text( (string) $page->post_excerpt );
		if ( '' === $note && 'post' === $page->post_type ) {
			$note = wp_trim_words( Schema::text( strip_shortcodes( excerpt_remove_blocks( $page->post_content ) ) ), 40 );
		}
		if ( $service && null !== $service['price'] ) {
			$note .= ' ' . sprintf(
				/* translators: 1: price, 2: currency code, 3: what the price is for such as per month, may be empty, 4: plus VAT or VAT included. */
				__( 'From %1$s %2$s %3$s, %4$s.', 'leitvia-studio' ),
				number_format_i18n( $service['price'], floor( $service['price'] ) === $service['price'] ? 0 : 2 ),
				$service['currency'],
				$service['unit'],
				$service['vat_included'] ? __( 'VAT included', 'leitvia-studio' ) : __( 'plus VAT', 'leitvia-studio' )
			);
		}
		$text = '- [' . self::line( Schema::text( get_the_title( $page ) ) ) . '](' . get_permalink( $page ) . ')';
		$note = (string) preg_replace( '/\s+,/u', ',', self::line( $note ) );
		return '' !== $note ? $text . ': ' . $note : $text;
	}

	/**
	 * A text on one line, without the characters that would end a Markdown link or heading.
	 *
	 * @param string $text Text.
	 */
	private static function line( string $text ): string {
		return Outline::one_line( str_replace( array( '[', ']' ), array( '(', ')' ), $text ) );
	}

	/**
	 * The robots.txt with the paths of the setting robots_disallow closed in the group User-agent: * (a new group when
	 * the file has none), the AI crawlers disallowed while the setting ai_crawlers is off, and the address of llms.txt
	 * while it is served. Runs on robots_txt.
	 *
	 * @param string $output    robots.txt so far.
	 * @param mixed  $is_public Whether the site is open to search engines.
	 */
	public static function robots( $output, $is_public = true ): string {
		$output = (string) $output;
		if ( ! $is_public ) {
			return $output;
		}
		$paths = self::disallow_paths( leitvia_studio_setting( 'robots_disallow' ) );
		if ( $paths ) {
			$lines = implode( "\n", array_map( static fn( string $path ): string => 'Disallow: ' . $path, $paths ) );
			// Right below the first User-agent: * line, so the paths join the group core writes; else a group of their own.
			if ( preg_match( '/^User-agent:[ \t]*\*[ \t]*$/mi', $output, $match, PREG_OFFSET_CAPTURE ) ) {
				$end    = (int) $match[0][1] + strlen( $match[0][0] );
				$output = substr( $output, 0, $end ) . "\n" . $lines . substr( $output, $end );
			} else {
				$output = ( '' === trim( $output ) ? '' : rtrim( $output ) . "\n\n" ) . "User-agent: *\n" . $lines . "\n";
			}
		}
		if ( ! leitvia_studio_setting( 'ai_crawlers' ) ) {
			$rules = array();
			foreach ( self::AI_CRAWLERS as $agent ) {
				$rules[] = 'User-agent: ' . $agent;
			}
			$output = rtrim( $output ) . "\n\n" . implode( "\n", $rules ) . "\nDisallow: /\n";
		}
		return self::enabled() ? rtrim( $output ) . "\n# llms.txt: " . self::url() . "\n" : $output;
	}
}
