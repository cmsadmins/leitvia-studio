<?php
/**
 * JSON-LD for posts, author archives and the front page.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one JSON-LD block in the head: BlogPosting or NewsArticle with author and publisher on single posts,
 * ProfilePage on author archives, WebSite with the site as publisher on the front page (site name and logo in search
 * results). Off with the setting news_schema and whenever an SEO plugin is active, so a page
 * never carries two article nodes. No Open Graph tags and no sitemap: that stays the job of SEO plugins.
 */
final class Schema {

	/**
	 * Smallest publisher logo, width and height in pixels.
	 */
	public const LOGO_MIN = 112;

	/**
	 * Smallest width of an original that stands in for missing crops, the width of the crops in Image_Sizes.
	 */
	public const IMAGE_MIN_WIDTH = 1200;

	/**
	 * Prints the JSON-LD for the current request. Runs on wp_head.
	 */
	public static function print(): void {
		$data = self::current();
		if ( $data ) {
			// JSON_HEX_TAG writes < and > as unicode escapes, so no title can close the script element.
			wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
		}
	}

	/**
	 * Structured data for the current request, null when there is none to print.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		if ( ! leitvia_studio_setting( 'news_schema' ) || leitvia_studio_seo_plugin_active() ) {
			return null;
		}
		$object = get_queried_object();
		if ( is_front_page() ) {
			return self::website();
		}
		if ( is_singular( 'post' ) && $object instanceof WP_Post ) {
			return self::article( $object );
		}
		if ( is_author() && $object instanceof WP_User ) {
			return self::profile_page( $object );
		}
		return null;
	}

	/**
	 * BlogPosting, or NewsArticle for posts in one of the categories of the filter leitvia_studio_news_categories.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function article( WP_Post $post ): array {
		/**
		 * Categories whose posts are news articles (NewsArticle instead of BlogPosting).
		 *
		 * @param array<int|string> $categories Category IDs, slugs or names. Default empty.
		 */
		$news      = (array) apply_filters( 'leitvia_studio_news_categories', array() );
		$data      = array(
			'@context'      => 'https://schema.org',
			'@type'         => $news && has_category( $news, $post ) ? 'NewsArticle' : 'BlogPosting',
			'headline'      => self::text( get_the_title( $post ) ),
			'url'           => get_permalink( $post ),
			// The same calls as the datetime attribute of the post date block, ISO 8601 with the site's offset.
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'author'        => array( self::person( (int) $post->post_author ) ),
			'publisher'     => self::organization(),
		);
		$thumbnail = (int) get_post_thumbnail_id( $post );
		$thumbnail = (int) get_theme_mod( 'custom_logo' ) !== $thumbnail ? $thumbnail : 0;
		$images    = $thumbnail ? Image_Sizes::urls( $thumbnail ) : array();
		// An image uploaded before Studio registered its crops has none of them: the original stands in when it is at
		// least as wide as the crops.
		$original = $thumbnail && ! $images ? wp_get_attachment_image_src( $thumbnail, 'full' ) : false;
		if ( $original && $original[1] >= self::IMAGE_MIN_WIDTH ) {
			$images = array( $original[0] );
		}
		if ( $images ) {
			$data['image'] = $images;
		}
		return $data;
	}

	/**
	 * ProfilePage of an author archive.
	 *
	 * @param WP_User $user Author.
	 * @return array<string, mixed>
	 */
	public static function profile_page( WP_User $user ): array {
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'ProfilePage',
			'mainEntity' => self::person( $user->ID ),
		);
	}

	/**
	 * WebSite of the front page: name, address, tagline when there is one, the site as publisher.
	 *
	 * @return array<string, mixed>
	 */
	public static function website(): array {
		$data        = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'WebSite',
			'name'      => self::text( get_bloginfo( 'name' ) ),
			'url'       => home_url( '/' ),
			'publisher' => self::organization(),
		);
		$description = self::text( get_bloginfo( 'description' ) );
		if ( '' !== $description ) {
			$data['description'] = $description;
		}
		return $data;
	}

	/**
	 * Person with name, author page and biography when there is one.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, string>
	 */
	private static function person( int $user_id ): array {
		$person      = array(
			'@type' => 'Person',
			'name'  => self::text( get_the_author_meta( 'display_name', $user_id ) ),
			'url'   => get_author_posts_url( $user_id ),
		);
		$description = self::text( get_the_author_meta( 'description', $user_id ) );
		if ( '' !== $description ) {
			$person['description'] = $description;
		}
		return $person;
	}

	/**
	 * The site as publisher. Logo: the custom logo when that is a raster image of at least LOGO_MIN pixels in both
	 * directions, else the site icon in its 512 px size under the same rule, else none. An SVG has no pixel size and
	 * never qualifies.
	 *
	 * @return array<string, mixed>
	 */
	private static function organization(): array {
		$organization = array(
			'@type' => 'Organization',
			'name'  => self::text( get_bloginfo( 'name' ) ),
			'url'   => home_url( '/' ),
		);
		$icon_url     = get_site_icon_url( 512 );
		$icon         = $icon_url ? wp_get_attachment_image_src( (int) get_option( 'site_icon' ), array( 512, 512 ) ) : false;
		if ( $icon ) {
			$icon[0] = $icon_url;
		}
		foreach ( array( wp_get_attachment_image_src( (int) get_theme_mod( 'custom_logo' ), 'full' ), $icon ) as $logo ) {
			if ( $logo && $logo[1] >= self::LOGO_MIN && $logo[2] >= self::LOGO_MIN ) {
				$organization['logo'] = array(
					'@type'  => 'ImageObject',
					'url'    => $logo[0],
					'width'  => $logo[1],
					'height' => $logo[2],
				);
				break;
			}
		}
		return $organization;
	}

	/**
	 * Plain text as readers see it: no tags, entities decoded.
	 *
	 * @param string $html Text that may contain markup or entities.
	 */
	private static function text( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
