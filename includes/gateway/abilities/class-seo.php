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
use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Organization\Organization;
use LeitviaStudio\Seo\Cluster;
use LeitviaStudio\Seo\Related_News;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/get-seo, leitvia/update-seo and leitvia/set-page-structure (page frame, hub and service of a page,
 * see Seo\Cluster). A small adapter maps title, description, robots and the social
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
	 * Option with SEO title and description of the archives of content types without an SEO plugin, by post type:
	 * array( '<type>' => array( 'title' => …, 'description' => … ) ). update-seo with archive writes it, News\Head prints it.
	 */
	public const ARCHIVE_OPTION = 'leitvia_studio_archive_seo';

	/**
	 * Supported SEO plugins: constant that marks them active, name, meta key per field and the value that switches
	 * noindex or nofollow on. Rank Math keeps robots as one list. All in One SEO keeps its data in a table of its own;
	 * it is detected so nothing writes beside it, but not read. leitvia is no plugin: without an SEO plugin Studio keeps
	 * the fields itself and News\Head prints them.
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
		'leitvia'   => array(
			'constant' => 'LEITVIA_STUDIO_NO_SEO_PLUGIN',
			'name'     => 'Leitvia Studio',
			'on'       => '1',
			'meta'     => array(
				'title'       => '_leitvia_seo_title',
				'description' => '_leitvia_seo_description',
				'noindex'     => '_leitvia_seo_noindex',
				'nofollow'    => '_leitvia_seo_nofollow',
			),
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
			__( 'Returns the SEO data of posts, pages and entries from the active SEO plugin. Supported are Yoast SEO, Rank Math, SEOPress and The SEO Framework. Per item: title, meta description, canonical, noindex, nofollow, Open Graph title, description and image, focus keyword and schema type. schema_type is the article type Leitvia Studio prints for a post (NewsArticle or BlogPosting), null where it prints none; structure holds the page frame, hub, service and offerings of a page (leitvia/set-page-structure). indexable says whether the site is open to search engines, news_sitemap is the address of the news sitemap of Leitvia Studio or null, organization holds the details of leitvia/set-organization: type, name, url, address, phone, email, founding date, founder, start year and holder of the copyright line, area served, opening hours, profiles and aggregate_rating. redirects lists the rows of leitvia/set-redirects, llms whether llms.txt was asked for (requested) and is served (enabled), held_back why a requested file is not served (site closed to search engines, SEO plugin), its address, summary, extra pages and whether AI crawlers may crawl (leitvia/set-llms). An empty value means the plugin falls back to its template. defaults holds what WordPress itself would print and a description suggested from the excerpt; without an SEO plugin plugin names Leitvia Studio, with an unsupported one seo is null, and notice says why. hash is the base_hash of leitvia/update-seo. IDs that are no readable content come back under skipped.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'posts' => array(
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => self::MAX_POSTS,
						'description' => __( 'IDs of posts, pages or entries from leitvia/list-content.', 'leitvia-studio' ),
						'items'       => array(
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
					'plugin'       => array(
						'type'       => array( 'object', 'null' ),
						'properties' => array(
							'key'      => $string,
							'name'     => $string,
							'writable' => $bool,
						),
					),
					'notice'       => $string,
					'items'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'post'        => array( 'type' => 'integer' ),
								'type'        => $string,
								'status'      => $string,
								'title'       => $string,
								'url'         => $string,
								'hash'        => $string,
								'seo'         => array( 'type' => array( 'object', 'null' ) ),
								'defaults'    => array( 'type' => 'object' ),
								'schema_type' => array(
									'type' => array( 'string', 'null' ),
									'enum' => array( 'NewsArticle', 'BlogPosting', null ),
								),
								'structure'   => array( 'type' => 'object' ),
							),
						),
					),
					'skipped'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'indexable'    => $bool,
					'news_sitemap' => array( 'type' => array( 'string', 'null' ) ),
					'redirects'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'llms'         => array( 'type' => 'object' ),
					'organization' => array(
						'type'       => 'object',
						'properties' => array(
							'type'             => array(
								'type' => 'string',
								'enum' => Organization::TYPES,
							),
							'name'             => $string,
							'url'              => $string,
							'street'           => $string,
							'postal_code'      => $string,
							'city'             => $string,
							'country'          => $string,
							'phone'            => $string,
							'email'            => $string,
							'founding_date'    => $string,
							'founder'          => $string,
							'copyright_since'  => $string,
							'copyright_holder' => $string,
							'area_served'      => array(
								'type'  => 'array',
								'items' => $string,
							),
							'opening_hours'    => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'days'   => array(
											'type'  => 'array',
											'items' => array(
												'type' => 'string',
												'enum' => Organization::DAYS,
											),
										),
										'opens'  => $string,
										'closes' => $string,
									),
								),
							),
							'same_as'          => array(
								'type'  => 'array',
								'items' => $string,
							),
							'aggregate_rating' => array( 'type' => array( 'object', 'null' ) ),
						),
					),
				),
			),
			array( self::class, 'get' )
		);
		Abilities::write(
			'leitvia/update-seo',
			__( 'Update SEO data', 'leitvia-studio' ),
			/* translators: 1: most characters of a title, 2: fewest characters of a description, 3: most characters of a description */
			sprintf( __( 'Writes SEO title, meta description and robots of one post through the active SEO plugin. The heading of the page stays. It covers posts, pages and entries in Yoast SEO, Rank Math, SEOPress or The SEO Framework; without an SEO plugin Leitvia Studio stores them and prints them in the head, the title as written, followed by " | " and the site name where it fits (leitvia/set-seo-titles); with another SEO plugin it is rejected with leitvia_no_seo_plugin. Read the post with leitvia/get-seo first and send its hash as base_hash. A title has at most %1$d characters, a description %2$d to %3$d; an empty string hands the field back to the template of the plugin, without a plugin to what WordPress prints. Say only what the page says: every number in title or description must appear in the title, excerpt or content of the post. A star rating (a number beside a star sign or written as 4.8/5) needs the confirmed aggregate_rating of leitvia/set-organization and must equal its value; its count of reviews counts as said. Autonomy and change packages work as for leitvia/update-content; a live write gets a change package for leitvia/rollback.', 'leitvia-studio' ), self::TITLE_MAX, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX ),
			'maintain',
			array(
				'destructive' => true,
				'idempotent'  => false,
			),
			array(
				'properties'           => array(
					'post'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Post ID from leitvia/list-content.', 'leitvia-studio' ),
					),
					'archive'     => array(
						'type'        => 'string',
						'maxLength'   => 20,
						'description' => __( 'In place of post: the key of a content type whose archive (the overview at its address, such as /docs/) takes the title and description, without an SEO plugin only. base_hash is empty then; like every setting it waits in a change package for a person.', 'leitvia-studio' ),
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
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update' )
		);
		Abilities::write(
			'leitvia/set-page-structure',
			__( 'Set page structure', 'leitvia-studio' ),
			__( 'Sets what a page is within the site, for breadcrumbs, structured data, llms.txt and the composition check. frame is the type of a page frame of the contract (hub for the overview of a topic, audience, service), so check-composition counts the sections that frame requires or recommends; compose-page sets it too. hub is the ID of the overview page the page belongs to: pages keep flat addresses, and the breadcrumbs (block and BreadcrumbList) show Home, the hub of the hub, the hub and the page; the hub wins over a parent page, and a page without a hub keeps the trail of its parents. service is what the page offers: name, description, price as a number, currency (EUR), vat_included (false for a net price) and unit (per month); with a price Leitvia Studio prints a Service with an Offer and a UnitPriceSpecification, without an SEO plugin. max_price makes the price a range (from price up to max_price, an AggregateOffer); offers lists the packages of the service, each with name, price and optional max_price (its price is a range), description and unit (up to 10), printed as one Offer per package in an AggregateOffer with the lowest price and the highest of all prices and ranges, and price may then stay empty. offerings links the page to offerings an add-on keeps (line: slug of a line, skus: article numbers); such an add-on shows them on the page and takes the service of the page from them. Use only what the page itself says. An empty string, 0 or null clears a field. Read the page with leitvia/get-page-outline first and send its hash as base_hash; autonomy and change packages work as for leitvia/update-content.', 'leitvia-studio' ),
			'maintain',
			array(
				'destructive' => false,
				'idempotent'  => true,
			),
			array(
				'properties'           => array(
					'post'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of a page from leitvia/list-content.', 'leitvia-studio' ),
					),
					'news'      => array(
						'type'                 => array( 'object', 'null' ),
						'description'          => __( 'Topic of the news of a page: categories and tags by ID or slug, as posts carry them (terms in leitvia/list-content). The pattern Related news on the page lists the latest posts with any of them, the latest posts when none has one. null clears it.', 'leitvia-studio' ),
						'properties'           => array(
							'categories' => array(
								'type'        => 'array',
								'maxItems'    => Related_News::MAX,
								'uniqueItems' => true,
								'items'       => array(
									'type'      => array( 'integer', 'string' ),
									'maxLength' => 200,
								),
							),
							'tags'       => array(
								'type'        => 'array',
								'maxItems'    => Related_News::MAX,
								'uniqueItems' => true,
								'items'       => array(
									'type'      => array( 'integer', 'string' ),
									'maxLength' => 200,
								),
							),
						),
						'additionalProperties' => false,
					),
					'frame'     => array(
						'type'        => 'string',
						'maxLength'   => 40,
						'description' => __( 'Type of a page frame of the contract, for example hub; empty clears it.', 'leitvia-studio' ),
					),
					'hub'       => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of the published overview page above this page; 0 clears it. For a post or an entry with a page of its own (such as documentation): the page of the service it is about, shown as a card beside or below it.', 'leitvia-studio' ),
					),
					'icon'      => array(
						'type'        => 'string',
						'maxLength'   => 40,
						'description' => __( 'Lead symbol of a hub: the name of an icon from leitvia/list-blocks (Lucide), shown by icon blocks bound to leitvia/page-symbol, such as the panel of the pattern hero-symbol; empty clears it.', 'leitvia-studio' ),
					),
					'service'   => array(
						'type'                 => array( 'object', 'null' ),
						'properties'           => array(
							'name'         => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 200,
								'description' => __( 'Name of the service as the page names it.', 'leitvia-studio' ),
							),
							'description'  => array(
								'type'      => 'string',
								'maxLength' => 500,
							),
							'price'        => array(
								'type'    => array( 'number', 'null' ),
								'minimum' => 0,
							),
							'currency'     => array(
								'type'    => 'string',
								'pattern' => '^[A-Z]{3}$',
								'default' => 'EUR',
							),
							'vat_included' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Whether the price includes VAT; false for a net price.', 'leitvia-studio' ),
							),
							'unit'         => array(
								'type'        => 'string',
								'maxLength'   => 40,
								'description' => __( 'What the price is for, as the page says it, for example per month.', 'leitvia-studio' ),
							),
							'max_price'    => array(
								'type'        => array( 'number', 'null' ),
								'minimum'     => 0,
								'description' => __( 'Highest price of a range that starts at price, as the page says it.', 'leitvia-studio' ),
							),
							'offers'       => array(
								'type'        => 'array',
								'maxItems'    => Cluster::MAX_OFFERS,
								'description' => __( 'The packages of the service as the page lists them, each with its own price.', 'leitvia-studio' ),
								'items'       => array(
									'type'                 => 'object',
									'properties'           => array(
										'name'        => array(
											'type'        => 'string',
											'minLength'   => 1,
											'maxLength'   => 120,
											'description' => __( 'Name of the package as the page names it.', 'leitvia-studio' ),
										),
										'price'       => array(
											'type'        => 'number',
											'minimum'     => 0,
											'description' => __( 'Price of the package as a number, in the currency of the service.', 'leitvia-studio' ),
										),
										'max_price'   => array(
											'type'        => 'number',
											'minimum'     => 0,
											'description' => __( 'Highest price of the package when its price is a range that starts at price, as the page says it.', 'leitvia-studio' ),
										),
										'description' => array(
											'type'      => 'string',
											'maxLength' => 300,
										),
										'unit'        => array(
											'type'      => 'string',
											'maxLength' => 40,
										),
									),
									'required'             => array( 'name', 'price' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'name' ),
						'additionalProperties' => false,
					),
					'offerings' => array(
						'type'                 => array( 'object', 'null' ),
						'description'          => __( 'Offerings an add-on keeps that this page presents: line is the slug of a line of offerings, skus lists article numbers of single offerings (they win over line). Leitvia Studio stores the link; an add-on that keeps offerings shows them on the page and gives the page its service from them. null clears it.', 'leitvia-studio' ),
						'properties'           => array(
							'line' => array(
								'type'      => 'string',
								'maxLength' => 200,
								'pattern'   => '^[a-z0-9-]*$',
							),
							'skus' => array(
								'type'        => 'array',
								'maxItems'    => Cluster::MAX_SKUS,
								'uniqueItems' => true,
								'items'       => array(
									'type'    => 'string',
									'pattern' => Cluster::SKU,
								),
							),
						),
						'additionalProperties' => false,
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_structure' )
		);
		Abilities::write(
			'leitvia/set-seo-titles',
			__( 'Set SEO titles', 'leitvia-studio' ),
			/* translators: %d: most characters of a title. */
			sprintf( __( 'Sets whether titles in search results end with " | " and the site name, without an SEO plugin. site_name true (the default) adds it to the titles of pages, posts, entries and archives, stored with leitvia/update-seo or built by WordPress, as long as the whole title keeps to %d characters and does not name the site already; the front page keeps its title. false leaves a stored title as written and the others as WordPress builds them. base_hash is empty. Like every setting it waits in a change package for a person.', 'leitvia-studio' ), self::TITLE_MAX ),
			'setup',
			array(
				'destructive' => true,
				'idempotent'  => true,
			),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'site_name' => array(
						'type'        => 'boolean',
						'description' => __( 'true adds the site name where it fits, false leaves titles without it.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'site_name' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_titles' )
		);
	}

	/**
	 * Operation of set-seo-titles: the setting title_site_name.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_titles( array $input ): array {
		$on = (bool) $input['site_name'];
		return array(
			'area'      => 'structure',
			'target'    => array( 'option' => \LeitviaStudio\Admin\Settings::OPTION ),
			'base_hash' => \LeitviaStudio\Gateway\Hashes::of( (string) wp_json_encode( get_option( \LeitviaStudio\Admin\Settings::OPTION ) ) ),
			'change'    => static fn( array $state ): array => array( 'value' => array( 'title_site_name' => $on ) + (array) $state['value'] ),
		);
	}

	/**
	 * Operation of set-page-structure: the meta Composition::FRAME_META, Cluster::HUB_META, Cluster::SERVICE_META and
	 * Cluster::OFFERINGS_META of a page, checked before anything changes.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_structure( array $input ) {
		$post   = (int) ( $input['post'] ?? 0 );
		$target = array( 'post' => $post );
		$type   = get_post_type( $post );
		// Posts and entries with a page of their own (docs, a type of the content model) take only the hub.
		$entry = is_string( $type ) && 'page' !== $type && in_array( $type, Content::types(), true ) && is_post_type_viewable( $type );
		if ( $entry && Scope::readable( $post ) && array_diff( array_keys( $input ), array( 'post', 'hub', 'base_hash', 'package' ) ) ) {
			return Block_Edit::invalid( 'post', __( 'A post or an entry takes only hub, the page of the service it is about.', 'leitvia-studio' ), $target );
		}
		if ( ! ( 'page' === $type || $entry ) || ! Scope::readable( $post ) ) {
			return Block_Edit::invalid( 'post', __( 'Set the structure of a page agents can read.', 'leitvia-studio' ), $target, 'leitvia/list-content' );
		}
		$meta = array();
		if ( array_key_exists( 'frame', $input ) ) {
			$frame = (string) $input['frame'];
			if ( '' !== $frame && ! Composition::frame( $frame, leitvia_studio_contract()->data() ) ) {
				return Block_Edit::invalid( 'frame', __( 'The contract has no page frame of this type.', 'leitvia-studio' ), $target, 'leitvia/get-contract' );
			}
			$meta[ Composition::FRAME_META ] = $frame;
		}
		if ( array_key_exists( 'hub', $input ) ) {
			$hub = (int) $input['hub'];
			if ( $hub && ( 'page' !== get_post_type( $hub ) || 'publish' !== get_post_status( $hub ) || (int) get_option( 'page_on_front' ) === $hub ) ) {
				return Block_Edit::invalid( 'hub', __( 'The hub is a published page other than the front page.', 'leitvia-studio' ), $target + array( 'hub' => $hub ), 'leitvia/list-content' );
			}
			if ( $hub && Cluster::loops( $post, $hub ) ) {
				return Block_Edit::invalid( 'hub', __( 'This hub would lead back to the page itself; a page cannot be above its own hub.', 'leitvia-studio' ), $target + array( 'hub' => $hub ) );
			}
			$meta[ Cluster::HUB_META ] = $hub ? $hub : '';
		}
		if ( array_key_exists( 'icon', $input ) ) {
			$icon = (string) $input['icon'];
			if ( '' !== $icon && ! isset( \LeitviaStudio\Blocks\Icon::icons()[ $icon ] ) ) {
				return Block_Edit::invalid( 'icon', __( 'There is no icon of this name; leitvia/list-blocks names the icons.', 'leitvia-studio' ), $target + array( 'icon' => $icon ), 'leitvia/list-blocks' );
			}
			$meta[ Cluster::ICON_META ] = $icon;
		}
		if ( array_key_exists( 'service', $input ) ) {
			$service = is_array( $input['service'] ) ? $input['service'] : array();
			$price   = isset( $service['price'] ) && is_numeric( $service['price'] ) ? (float) $service['price'] : null;
			$max     = isset( $service['max_price'] ) && is_numeric( $service['max_price'] ) ? (float) $service['max_price'] : null;
			$prices  = array_map( static fn( $offer ): float => (float) ( $offer['price'] ?? 0 ), (array) ( $service['offers'] ?? array() ) );
			$low     = $price ?? ( $prices ? min( $prices ) : null );
			if ( null !== $max && ( null === $low || $max < $low ) ) {
				return Block_Edit::invalid( 'service', __( 'max_price is the top of a range: send price as its start, not higher than max_price.', 'leitvia-studio' ), $target );
			}
			$offers = array();
			foreach ( array_slice( (array) ( $service['offers'] ?? array() ), 0, Cluster::MAX_OFFERS ) as $offer ) {
				$cost = (float) ( $offer['price'] ?? 0 );
				$top  = isset( $offer['max_price'] ) && is_numeric( $offer['max_price'] ) ? (float) $offer['max_price'] : null;
				if ( null !== $top && $top < $cost ) {
					return Block_Edit::invalid( 'service', __( 'max_price of a package is the top of its range: send price as its start, not higher than max_price.', 'leitvia-studio' ), $target );
				}
				$offers[] = array_filter(
					array(
						'name'        => sanitize_text_field( (string) ( $offer['name'] ?? '' ) ),
						'price'       => $cost,
						'max_price'   => $top,
						'description' => sanitize_text_field( (string) ( $offer['description'] ?? '' ) ),
						'unit'        => sanitize_text_field( (string) ( $offer['unit'] ?? '' ) ),
					),
					static fn( $value ): bool => '' !== $value && null !== $value
				);
			}
			$meta[ Cluster::SERVICE_META ] = $service ? array_filter(
				array(
					'name'         => sanitize_text_field( (string) $service['name'] ),
					'description'  => sanitize_text_field( (string) ( $service['description'] ?? '' ) ),
					'price'        => $price,
					'max_price'    => $max,
					'currency'     => (string) ( $service['currency'] ?? 'EUR' ),
					'vat_included' => ! empty( $service['vat_included'] ),
					'unit'         => sanitize_text_field( (string) ( $service['unit'] ?? '' ) ),
					'offers'       => $offers,
				),
				static fn( $value ): bool => null !== $value && '' !== $value && array() !== $value
			) : '';
		}
		if ( array_key_exists( 'offerings', $input ) ) {
			$offerings                       = is_array( $input['offerings'] ) ? $input['offerings'] : array();
			$line                            = sanitize_title( (string) ( $offerings['line'] ?? '' ) );
			$skus                            = array_values( array_unique( array_map( 'strval', (array) ( $offerings['skus'] ?? array() ) ) ) );
			$meta[ Cluster::OFFERINGS_META ] = '' === $line && ! $skus ? '' : array_filter(
				array(
					'line' => $line,
					'skus' => $skus,
				)
			);
		}
		if ( array_key_exists( 'news', $input ) ) {
			if ( 'page' !== get_post_field( 'post_type', $post ) ) {
				return Block_Edit::invalid( 'news', __( 'Only a page names the topic of its news; a post takes the related posts of its categories.', 'leitvia-studio' ), $target );
			}
			$news = array();
			foreach ( Related_News::TAXONOMIES as $key => $taxonomy ) {
				$ids = array();
				foreach ( (array) ( $input['news'][ $key ] ?? array() ) as $name ) {
					$term = is_int( $name ) ? get_term( $name, $taxonomy ) : get_term_by( 'slug', sanitize_title( (string) $name ), $taxonomy );
					if ( ! $term instanceof \WP_Term ) {
						return Block_Edit::invalid( 'news', __( 'Name categories and tags that exist, by ID or slug as posts carry them in leitvia/list-content.', 'leitvia-studio' ), $target + array( $key => $name ), 'leitvia/list-content' );
					}
					$ids[] = $term->term_id;
				}
				$news[ $key ] = array_slice( array_values( array_unique( $ids ) ), 0, Related_News::MAX );
			}
			$meta[ Related_News::META ] = array_filter( $news ) ? array_filter( $news ) : '';
		}
		if ( ! $meta ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: frame, hub, icon, service, offerings or news.', 'leitvia-studio' ), $target );
		}
		return array(
			'area'   => 'content',
			'target' => $target,
			'record' => true,
			'change' => static fn( array $state ): array => array( 'meta_input' => $meta + (array) ( $state['meta_input'] ?? array() ) ) + $state,
		);
	}

	/**
	 * Key of the active SEO plugin, leitvia without one, '' when an SEO plugin is active that Studio does not know.
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
		 * '' for none, which means Studio itself unless an SEO plugin it does not know is active.
		 *
		 * @param string $found Key of the detected plugin.
		 */
		$key = (string) apply_filters( 'leitvia_studio_seo_adapter', $found );
		if ( isset( self::PLUGINS[ $key ] ) ) {
			return $key;
		}
		return leitvia_studio_seo_plugin_active() ? '' : 'leitvia';
	}

	/**
	 * Whether an SEO plugin is active whose meta Leitvia Studio writes.
	 */
	public static function writable(): bool {
		$key = self::plugin();
		return '' !== $key && array() !== self::PLUGINS[ $key ]['meta'];
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
		$writable = self::writable();
		$items    = array();
		$skipped  = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! Scope::readable( $post ) || ! in_array( $post->post_type, Content::types(), true ) ) {
				$skipped[] = $id;
				continue;
			}
			$items[] = array(
				'post'        => $id,
				'type'        => $post->post_type,
				'status'      => $post->post_status,
				'title'       => $post->post_title,
				'url'         => (string) get_permalink( $post ),
				'hash'        => Hashes::document( $id ),
				'seo'         => $writable ? self::values( $id, $key ) : null,
				'defaults'    => self::defaults( $post ),
				'schema_type' => self::schema_type( $post ),
			) + ( 'page' === $post->post_type ? array(
				'structure' => array(
					'frame'     => (string) get_post_meta( $id, Composition::FRAME_META, true ),
					'hub'       => (int) get_post_meta( $id, Cluster::HUB_META, true ),
					'icon'      => (string) get_post_meta( $id, Cluster::ICON_META, true ),
					'service'   => Cluster::service( $id ),
					'offerings' => Cluster::offerings( $id ),
					'news'      => Related_News::of( $id ),
				),
			) : array() ) + ( 'page' !== $post->post_type && is_post_type_viewable( $post->post_type ) ? array(
				'structure' => array(
					'hub' => (int) get_post_meta( $id, Cluster::HUB_META, true ),
				),
			) : array() );
		}
		$notice = match ( true ) {
			'' === $key => __( 'The active SEO plugin is not supported. defaults shows what WordPress prints; the description is only a suggestion from the excerpt. leitvia/update-seo needs Yoast SEO, Rank Math, SEOPress or The SEO Framework.', 'leitvia-studio' ),
			'leitvia' === $key => __( 'No SEO plugin is active. Leitvia Studio stores title, description and robots from leitvia/update-seo and prints them; an empty title means page title and site name. Canonical, Open Graph and focus keyword need an SEO plugin.', 'leitvia-studio' ),
			! $writable => __( 'All in One SEO keeps its data in a table of its own, which Leitvia Studio does not read or write. defaults shows what WordPress would print.', 'leitvia-studio' ),
			default     => '',
		};
		return array(
			'plugin'       => '' === $key ? null : array(
				'key'      => $key,
				'name'     => self::PLUGINS[ $key ]['name'],
				'writable' => $writable,
			),
			'notice'       => $notice,
			'items'        => $items,
			'skipped'      => $skipped,
			'indexable'    => (bool) get_option( 'blog_public' ),
			'news_sitemap' => \LeitviaStudio\News\Sitemap::enabled() ? \LeitviaStudio\News\Sitemap::url() : null,
			'organization' => Organization::details(),
			'redirects'    => array_values( (array) leitvia_studio_setting( 'redirects' ) ),
			'llms'         => array(
				'requested'   => (bool) leitvia_studio_setting( 'llms_txt' ),
				'enabled'     => \LeitviaStudio\Seo\Llms::enabled(),
				'held_back'   => leitvia_studio_setting( 'llms_txt' ) ? \LeitviaStudio\Seo\Llms::held_back() : '',
				'url'         => \LeitviaStudio\Seo\Llms::url(),
				'summary'     => (string) leitvia_studio_setting( 'llms_summary' ),
				'pages'       => array_map( 'intval', (array) leitvia_studio_setting( 'llms_pages' ) ),
				'ai_crawlers' => (bool) leitvia_studio_setting( 'ai_crawlers' ),
			),
		);
	}

	/**
	 * Article type Leitvia Studio prints for a post, null for other content, with the setting news_schema off or an
	 * SEO plugin active.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function schema_type( \WP_Post $post ): ?string {
		return 'post' === $post->post_type && leitvia_studio_setting( 'news_schema' ) && ! leitvia_studio_seo_plugin_active() ? ( \LeitviaStudio\News\Schema::is_news( $post ) ? 'NewsArticle' : 'BlogPosting' ) : null;
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
		$schema = array_filter( array( self::schema_type( $post ) ) );
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
		if ( isset( $input['archive'] ) ) {
			return self::describe_archive( $input );
		}
		$post   = (int) ( $input['post'] ?? 0 );
		$target = array( 'post' => $post );
		if ( ! $post ) {
			return Block_Edit::invalid( 'post', __( 'Name the post with post, or a content type with archive.', 'leitvia-studio' ), $target, 'leitvia/list-content' );
		}
		$key = self::plugin();
		if ( '' === $key || array() === self::PLUGINS[ $key ]['meta'] ) {
			return Rejection::make( 'leitvia_no_seo_plugin', __( 'No SEO plugin Leitvia Studio can write to is active (Yoast SEO, Rank Math, SEOPress or The SEO Framework). Change the excerpt with leitvia/update-content instead, or ask a person to install one.', 'leitvia-studio' ), 422, 'seo_plugin', $target, 'leitvia/update-content', 'never' );
		}
		$texts = self::texts( $input, $target );
		if ( is_wp_error( $texts ) ) {
			return $texts;
		}
		$flags = array_map( 'boolval', array_intersect_key( $input, array_flip( array( 'noindex', 'nofollow' ) ) ) );
		if ( ! $texts && ! $flags ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: title, description, noindex or nofollow.', 'leitvia-studio' ), $target );
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
				$state = self::write_texts( $state, $texts, $target );
				if ( is_wp_error( $state ) ) {
					return $state;
				}
				$meta = (array) $state['meta_input'];
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
	 * Operation of update-seo with archive: title and description of the archive of a public content type in
	 * ARCHIVE_OPTION, an empty string removes one. Without an SEO plugin only: a plugin keeps archive titles in its own
	 * settings.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_archive( array $input ) {
		$type   = (string) $input['archive'];
		$target = array( 'archive' => $type );
		if ( 'leitvia' !== self::plugin() ) {
			return Rejection::make( 'leitvia_no_seo_plugin', __( 'The SEO plugin keeps the titles of archives in its own settings; report the wish to the person.', 'leitvia-studio' ), 422, 'seo_plugin', $target, '', 'never' );
		}
		$object = get_post_type_object( $type );
		if ( ! $object || ! $object->public || ! $object->has_archive ) {
			return Block_Edit::invalid( 'archive', __( 'archive names a public content type with an archive, as leitvia/get-content-model lists them.', 'leitvia-studio' ), $target, 'leitvia/get-content-model' );
		}
		if ( array_intersect_key( $input, array_flip( array( 'post', 'noindex', 'nofollow' ) ) ) ) {
			return Block_Edit::invalid( 'archive', __( 'An archive takes title and description only.', 'leitvia-studio' ), $target );
		}
		$texts = self::texts( $input, $target );
		if ( is_wp_error( $texts ) ) {
			return $texts;
		}
		if ( ! $texts ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: title or description.', 'leitvia-studio' ), $target );
		}
		return array(
			'area'      => 'content',
			'target'    => array( 'option' => self::ARCHIVE_OPTION ) + $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( self::ARCHIVE_OPTION ) ) ),
			'change'    => static function ( array $state ) use ( $type, $texts ): array {
				$all          = is_array( $state['value'] ?? null ) ? $state['value'] : array();
				$all[ $type ] = array_filter( $texts + (array) ( $all[ $type ] ?? array() ), static fn( $text ): bool => '' !== $text );
				return array( 'value' => array_filter( $all ) );
			},
		);
	}

	/**
	 * SEO title and description of an input with whitespace collapsed, checked for length; shared by update-seo and
	 * the seo field of create-content.
	 *
	 * @param array<string, mixed> $input    Input with title and description.
	 * @param array<string, mixed> $target   Target for rejections.
	 * @param list<string>|null    $warnings With an array, lengths that are off are added there instead of refusing.
	 * @return array<string, string>|\WP_Error
	 */
	public static function texts( array $input, array $target, ?array &$warnings = null ) {
		$texts = array_filter( array_intersect_key( $input, array_flip( array( 'title', 'description' ) ) ), 'is_string' );
		$texts = array_map( static fn( string $text ): string => trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), $texts );
		$found = array();
		if ( isset( $texts['title'] ) && mb_strlen( $texts['title'] ) > self::TITLE_MAX ) {
			/* translators: %d: most characters */
			$found[] = sprintf( __( 'The title is too long; search results cut it after about %d characters. Shorten it.', 'leitvia-studio' ), self::TITLE_MAX );
		}
		$length = isset( $texts['description'] ) ? mb_strlen( $texts['description'] ) : 0;
		if ( $length && ( $length < self::DESCRIPTION_MIN || $length > self::DESCRIPTION_MAX ) ) {
			/* translators: 1: fewest characters, 2: most characters */
			$found[] = sprintf( __( 'A description has %1$d to %2$d characters. Summarize what the page offers in one or two sentences.', 'leitvia-studio' ), self::DESCRIPTION_MIN, self::DESCRIPTION_MAX );
		}
		// A migration takes the texts of the live site as they are and only reports what is off (warnings given).
		if ( $found && null === $warnings ) {
			return Block_Edit::invalid( 'length', $found[0], $target );
		}
		$warnings = array_merge( (array) $warnings, $found );
		return $texts;
	}

	/**
	 * State with SEO title and description in the meta of the active plugin, after the fact check against the title,
	 * excerpt and content of the state. A state without a writable plugin comes back unchanged. With warnings given (a
	 * migration takes the texts of the live site), a fact the page does not say is a warning and the texts are written;
	 * a text with a star rating that is not the confirmed one stays out, as a rating nobody checked is never shown.
	 *
	 * @param array<string, mixed>  $state    State of a post.
	 * @param array<string, string> $texts    Texts from texts().
	 * @param array<string, mixed>  $target   Target for rejections.
	 * @param list<string>|null     $warnings Warnings instead of rejections, null to reject.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function write_texts( array $state, array $texts, array $target, ?array &$warnings = null ) {
		if ( ! $texts || ! self::writable() ) {
			return $state;
		}
		$meta  = self::PLUGINS[ self::plugin() ]['meta'];
		$stars = self::stars( implode( ' ', $texts ) );
		if ( $stars ) {
			/* translators: %s: numbers */
			$message = sprintf( __( 'The star rating %s is not the confirmed rating of the organization. Set it with aggregate_rating in leitvia/set-organization after a person checked it at its source, or leave the star out.', 'leitvia-studio' ), implode( ', ', $stars ) );
			if ( null === $warnings ) {
				return Block_Edit::invalid( 'unsupported_fact', $message, $target, 'leitvia/set-organization' );
			}
			$warnings[] = $message;
			$texts      = array_filter( $texts, static fn( string $text ): bool => ! self::stars( $text ) );
		}
		$unsupported = self::unsupported( implode( ' ', $texts ), $state, $target );
		if ( $unsupported ) {
			/* translators: %s: numbers */
			$message = sprintf( __( 'The page does not say %s. Use only facts from the title, excerpt or content of the post.', 'leitvia-studio' ), implode( ', ', $unsupported ) );
			if ( null === $warnings ) {
				return Block_Edit::invalid( 'unsupported_fact', $message, $target, 'leitvia/get-page-outline' );
			}
			$warnings[] = $message;
		}
		foreach ( $texts as $field => $text ) {
			if ( isset( $meta[ $field ] ) ) {
				$state['meta_input'][ $meta[ $field ] ] = $text;
			}
		}
		return $state;
	}

	/**
	 * Star ratings of a text (a number beside ★ or written as n/5) that differ from the confirmed rating of the
	 * organization; every one of them while no rating is confirmed.
	 *
	 * @param string $text New title and description.
	 * @return list<string>
	 */
	private static function stars( string $text ): array {
		preg_match_all( '#★\s*(\d+(?:[.,]\d+)?)|(\d+(?:[.,]\d+)?)\s*(?:★|/\s*5(?![\d.,]))#u', $text, $matches, PREG_SET_ORDER );
		$rating = Organization::confirmed_rating();
		$wrong  = array();
		foreach ( $matches as $match ) {
			$number = '' !== ( $match[1] ?? '' ) ? $match[1] : (string) ( $match[2] ?? '' );
			if ( ! $rating || abs( (float) str_replace( ',', '.', $number ) - (float) $rating['value'] ) > 0.001 ) {
				$wrong[] = $number;
			}
		}
		return array_values( array_unique( $wrong ) );
	}

	/**
	 * Numbers of a text that the title, excerpt and content of the post do not contain. A number is the commonest fact
	 * a summary invents (prices, years, counts). The year the post appears in and the year of its last change count as
	 * said: the page shows its date, and a new post without a date appears now.
	 * A confirmed rating of the organization counts as said, see stars() for the star itself.
	 * ponytail: numbers only; names and claims without digits pass, the skill asks agents to keep to the page.
	 *
	 * @param string               $text   New title and description.
	 * @param array<string, mixed> $state  State of the post.
	 * @param array<string, mixed> $target Target with the post ID, 0 for a new post.
	 * @return list<string>
	 */
	private static function unsupported( string $text, array $state, array $target ): array {
		$post   = (int) ( $target['post'] ?? 0 );
		$date   = (string) ( $state['post_date'] ?? '' );
		$years  = substr( '' !== $date ? $date : current_time( 'mysql' ), 0, 4 ) . ' ' . ( $post ? substr( (string) get_post_field( 'post_modified', $post, 'raw' ), 0, 4 ) : '' );
		$source = (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( ( $state['post_title'] ?? '' ) . ' ' . ( $state['post_excerpt'] ?? '' ) . ' ' . ( $state['post_content'] ?? '' ) . ' ' . get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' ' . $years;
		// A confirmed rating is a fact of the site: its value (also with a comma, and the 5 of n/5) and its count.
		$rating = Organization::confirmed_rating();
		if ( $rating ) {
			$source .= ' ' . $rating['value'] . ' ' . str_replace( '.', ',', $rating['value'] ) . ' 5 ' . $rating['count'];
		}
		preg_match_all( '/\d+(?:[.,:]\d+)*/u', $text, $numbers );
		return array_values( array_unique( array_filter( $numbers[0], static fn( string $number ): bool => ! str_contains( $source, $number ) ) ) );
	}
}
