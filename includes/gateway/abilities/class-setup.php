<?php
/**
 * Setup abilities: token sets, style variations, content types, navigation, template parts and templates.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Admin\Settings;
use LeitviaStudio\Consent\Consent;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Recipes;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;
use LeitviaStudio\Organization\Organization;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/create-token-set, leitvia/activate-token-set, leitvia/set-variation, leitvia/define-content-type,
 * leitvia/update-site-values, leitvia/update-navigation, leitvia/update-template-part, leitvia/update-template,
 * leitvia/set-site-identity, leitvia/set-motion, leitvia/set-front-page, leitvia/get-consent-providers and leitvia/set-consent-providers.
 * Each one describes an operation for Pipeline::run() with the Studio building blocks: token sets with the contrast
 * lock, the compiler, the content model. Token sets and global styles belong to the area design, the rest to structure.
 */
final class Setup {

	/**
	 * Templates of WooCommerce for product categories and tags, open to update-template while WooCommerce is active.
	 */
	private const SHOP_TERM_TEMPLATES = array( 'taxonomy-product_cat', 'taxonomy-product_tag' );

	/**
	 * Settings of a core/navigation in a template part that update-template-part keeps, see keep_navigation().
	 */
	/**
	 * The model a building block adds right now (Building_Blocks::model_step()), so define-content-type takes the
	 * showInAbilities the block declares itself: the person who applies the building block decides on it with the block.
	 *
	 * @var array<string, mixed>|null
	 */
	public static ?array $building_block = null;

	public const NAVIGATION_KEEP = array( 'submenuVisibility', 'overlay', 'overlayMenu', 'overlayBackgroundColor', 'overlayTextColor', 'ariaLabel' );

	/**
	 * Blocks a navigation may hold at its own level and inside submenus.
	 */
	public const NAVIGATION_BLOCKS = array( 'core/navigation-link', 'core/navigation-submenu', 'core/page-list', 'leitvia/mega-menu' );

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$replace = array(
			'destructive' => true,
			'idempotent'  => false,
		);
		$add     = array(
			'destructive' => false,
			'idempotent'  => false,
		);
		$object  = array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
		Abilities::write(
			'leitvia/create-token-set',
			__( 'Create token set', 'leitvia-studio' ),
			__( 'Creates a token set from a DTCG document. Start from the document leitvia/get-tokens returns; base_hash is empty for the new set. It must compile against the contract and pass every token set check; contrast findings name the failing pair and the ratio it needs. Optional group size: page widths. Look at it with leitvia/preview-token-set, activate it with leitvia/activate-token-set.', 'leitvia-studio' ),
			'setup',
			$add,
			array(
				'properties' => array(
					'title'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Name of the new token set.', 'leitvia-studio' ),
					),
					'document' => array(
						'type'        => 'object',
						'description' => __( 'DTCG token document, as leitvia/get-tokens returns it.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'title', 'document' ),
			) + $object,
			array( self::class, 'describe_create_token_set' )
		);
		Abilities::write(
			'leitvia/activate-token-set',
			__( 'Activate token set', 'leitvia-studio' ),
			__( 'Writes the presets of a token set into the global styles of the active theme. The set ID comes from leitvia/create-token-set or the sets of leitvia/get-tokens. The base hash is the hash of the global styles. leitvia/get-tokens returns it as hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'set' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Token set ID from the sets of leitvia/get-tokens or from leitvia/create-token-set.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'set' ),
			) + $object,
			array( self::class, 'describe_activate_token_set' )
		);
		Abilities::write(
			'leitvia/set-variation',
			__( 'Set style variation', 'leitvia-studio' ),
			__( 'Applies a style variation of the contract to the global styles of the active theme: a full variation replaces them, a colors or typography variation replaces only its part. Names come from the style variations of leitvia/get-contract. The base hash is the hash of the global styles. leitvia/get-tokens returns it as hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'variation' => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]+$',
						'description' => __( 'Slug of a style variation from leitvia/get-contract.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'variation' ),
			) + $object,
			array( self::class, 'describe_set_variation' )
		);
		Abilities::write(
			'leitvia/set-posts-page',
			__( 'Set posts page', 'leitvia-studio' ),
			__( 'Makes an existing page the posts page of the reading settings, the address that lists all posts; 0 clears it. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied. Like every setting it waits in a change package for a person. It takes effect with a static front page; notice says so while the site has none, or while the page is not published.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'page' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of a page from leitvia/list-content, 0 to clear the setting.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'page' ),
			) + $object,
			array( self::class, 'describe_set_posts_page' )
		);
		Abilities::write(
			'leitvia/set-permalinks',
			__( 'Set permalinks', 'leitvia-studio' ),
			/* translators: %postname%, %post_id%, %year%, %monthnum% and %category% are permalink tags of WordPress, keep them as they are. */
			__( 'Sets the permalink structure of posts and the bases of category and tag addresses, as in Settings, Permalinks, for a migration that keeps the addresses of the old site: structure such as /news/%postname%/ or /%postname%/ (needs %postname% or %post_id%), category_base and tag_base such as topic (empty for the default). Pages keep their addresses, posts take the structure. Only the fields you send change; the rewrite rules are rebuilt on the next request. base_hash is empty. Like every setting it waits in a change package for a person; notice warns when published posts change their address, which leitvia/set-redirects can lead from the old addresses.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'structure'     => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'pattern'     => '^/[A-Za-z0-9%_/.-]*$',
						/* translators: The words between percent signs are permalink tags of WordPress, keep them as they are. */
						'description' => __( 'Permalink structure of posts with tags such as %postname%, %post_id%, %year%, %monthnum%, %category%, starting with a slash.', 'leitvia-studio' ),
					),
					'category_base' => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'pattern'     => '^[a-z0-9-]*$',
						'description' => __( 'Base of category addresses, empty for category.', 'leitvia-studio' ),
					),
					'tag_base'      => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'pattern'     => '^[a-z0-9-]*$',
						'description' => __( 'Base of tag addresses, empty for tag.', 'leitvia-studio' ),
					),
				),
			) + $object,
			array( self::class, 'describe_set_permalinks' )
		);
		Abilities::write(
			'leitvia/set-privacy-page',
			__( 'Set privacy policy page', 'leitvia-studio' ),
			__( 'Makes an existing page the privacy policy page of the site; 0 clears it. Contact forms, comment forms and the footer link to it. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied. Like every setting it waits in a change package for a person. notice says so while the page is not published, and leitvia/audit-site reports contact forms without such a page.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'page' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of a page from leitvia/list-content, 0 to clear the setting.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'page' ),
			) + $object,
			array( self::class, 'describe_set_privacy_page' )
		);
		Abilities::write(
			'leitvia/set-organization',
			__( 'Set organization details', 'leitvia-studio' ),
			__( 'Sets the details of the organization behind the site: kind of business (type, Organization or a type of LocalBusiness; type falls back to Organization), address, phone, email, founding date (founding_date, YYYY, YYYY-MM or YYYY-MM-DD), founder or owner (founder, a person, printed as schema.org founder), the copyright line of the footer (copyright_since, the first year as YYYY, and copyright_holder, the name after the years; the theme Leitvia then shows the first year, a dash, the current year and the holder), area served (area_served, places), opening hours (opening_hours, rows of days with opens and closes as HH:MM, only printed for a local business), profiles on other sites (same_as, https addresses) and the rating on a review site (aggregate_rating: value 1 to 5, count of reviews, source_url where it is shown, confirmed true only after a person checked it there; printed as aggregateRating and allowed as a star in SEO titles only when confirmed, with count and source; null removes it). Leitvia Studio prints them as structured data on the front page and as publisher of posts while no SEO plugin is active; the name and the logo stay those of the site. Only the fields you send change, an empty string or an empty list clears one. leitvia/get-seo returns the current values. base_hash is empty. Use only facts the person gave or the site shows; never guess opening hours. Like every setting it waits in a change package for a person.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'type'             => array(
						'type' => 'string',
						'enum' => Organization::TYPES,
					),
					'street'           => array( 'type' => 'string' ),
					'postal_code'      => array( 'type' => 'string' ),
					'city'             => array( 'type' => 'string' ),
					'country'          => array(
						'type'    => 'string',
						'pattern' => '^([A-Z]{2})?$',
					),
					'phone'            => array( 'type' => 'string' ),
					'email'            => array( 'type' => 'string' ),
					'founding_date'    => array(
						'type'    => 'string',
						'pattern' => Organization::DATE,
					),
					'founder'          => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'copyright_since'  => array(
						'type'    => 'string',
						'pattern' => Organization::YEAR,
					),
					'copyright_holder' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'area_served'      => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array( 'type' => 'string' ),
					),
					'opening_hours'    => array(
						'type'     => 'array',
						'maxItems' => 14,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'days'   => array(
									'type'        => 'array',
									'description' => __( 'Days of the week the times apply to, in English: Monday to Sunday.', 'leitvia-studio' ),
									'minItems'    => 1,
									'items'       => array(
										'type' => 'string',
										'enum' => Organization::DAYS,
									),
								),
								'opens'  => array(
									'type'        => 'string',
									'description' => __( 'Opening time as HH:MM, 24-hour clock.', 'leitvia-studio' ),
									'pattern'     => Organization::TIME,
								),
								'closes' => array(
									'type'        => 'string',
									'description' => __( 'Closing time as HH:MM, 24-hour clock.', 'leitvia-studio' ),
									'pattern'     => Organization::TIME,
								),
							),
							'required'             => array( 'days', 'opens', 'closes' ),
							'additionalProperties' => false,
						),
					),
					'same_as'          => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^https://',
						),
					),
					'aggregate_rating' => array(
						'type'                 => array( 'object', 'null' ),
						'properties'           => array(
							'value'      => array(
								'type'        => 'string',
								'pattern'     => '^([1-4](\\.\\d)?|5(\\.0)?)$',
								'description' => __( 'Average rating from 1 to 5 with a dot, for example 4.8.', 'leitvia-studio' ),
							),
							'count'      => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'Number of reviews the rating comes from.', 'leitvia-studio' ),
							),
							'source_url' => array(
								'type'        => 'string',
								'pattern'     => '^https://',
								'description' => __( 'Address of the page where the rating is shown, such as the profile on a review site.', 'leitvia-studio' ),
							),
							'confirmed'  => array(
								'type'        => 'boolean',
								'description' => __( 'true only when a person compared value and count with the source.', 'leitvia-studio' ),
							),
						),
						'required'             => array( 'value', 'count', 'source_url', 'confirmed' ),
						'additionalProperties' => false,
					),
				),
			) + $object,
			array( self::class, 'describe_set_organization' )
		);
		Abilities::write(
			'leitvia/set-redirects',
			__( 'Set redirects', 'leitvia-studio' ),
			/* translators: %d: most rows */
			sprintf( __( 'Sets the redirects of moved and removed addresses, for a migration or a new structure. Rows of from, a path of this site such as /old-page/, status and to. A from with a query, such as /?p=45 or /?attachment_id=123 of an old site, matches only that query (in any order of its parameters) and comes before the row of its bare path; a from without a query matches its path with any query. status 301 (the default) moves for good, 302 for a while; both need to, a path of this site or an https address. status 410 says the page is gone for good (search engines drop it faster than a 404) and takes no to; the visitor sees the 404 page of the theme. The list replaces the saved one (at most %d rows, read the current one with leitvia/get-seo); an empty list removes all. A request of a from path gets its answer before the page loads; a trailing slash and the case do not matter. Rows that lead back to a path they passed (a loop) are refused, as are the start page as from, a target equal to its from and a 410 with a target. Inactive while an SEO plugin is active, which brings its own redirects. base_hash is empty. Like every setting it waits in a change package for a person.', 'leitvia-studio' ), \LeitviaStudio\Seo\Redirects::MAX ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'redirects' => array(
						'type'        => 'array',
						'maxItems'    => \LeitviaStudio\Seo\Redirects::MAX,
						'description' => __( 'Every redirect of the site; the list replaces the saved one.', 'leitvia-studio' ),
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'from'   => array(
									'type'        => 'string',
									'pattern'     => '^/',
									'maxLength'   => 300,
									'description' => __( 'Old path of this site, for example /old-page/, with a query when the old address had one, for example /?p=45.', 'leitvia-studio' ),
								),
								'to'     => array(
									'type'        => 'string',
									'pattern'     => '^(/|https://)',
									'maxLength'   => 500,
									'description' => __( 'New path of this site or an https address; needed for 301 and 302, left out for 410.', 'leitvia-studio' ),
								),
								'status' => array(
									'type'        => 'integer',
									'enum'        => \LeitviaStudio\Seo\Redirects::STATUSES,
									'default'     => 301,
									'description' => __( '301 moved for good, 302 moved for a while, 410 gone for good.', 'leitvia-studio' ),
								),
							),
							'required'             => array( 'from' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'   => array( 'redirects' ),
			) + $object,
			array( self::class, 'describe_set_redirects' )
		);
		Abilities::write(
			'leitvia/set-llms',
			__( 'Set llms.txt and AI crawlers', 'leitvia-studio' ),
			/* translators: %d: most characters of the summary */
			sprintf( __( 'Sets llms.txt, the file at the root of the site that tells AI assistants what the site is about, and whether robots.txt lets AI crawlers in. llms.txt is built on each request: the site name, summary (at most %d characters, empty for the tagline), the pages with the page frame hub, the pages with a service and price (leitvia/set-page-structure) and the extra pages of pages (IDs, published). enabled switches the file off and on, ai_crawlers false disallows the crawlers of AI search and assistants in robots.txt (default true, they may crawl as every other crawler). Without an SEO plugin and only on a site open to search engines: otherwise the setting is saved and notice says what holds the file back, get-seo shows llms.requested, llms.enabled and llms.held_back. Only the fields you send change. base_hash is empty. Like every setting it waits in a change package for a person.', 'leitvia-studio' ), \LeitviaStudio\Admin\Settings::SUMMARY_MAX ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'enabled'     => array( 'type' => 'boolean' ),
					'summary'     => array(
						'type'      => 'string',
						'maxLength' => \LeitviaStudio\Admin\Settings::SUMMARY_MAX,
					),
					'pages'       => array(
						'type'     => 'array',
						'maxItems' => \LeitviaStudio\Seo\Llms::MAX_PAGES,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'ai_crawlers' => array(
						'type'        => 'boolean',
						'description' => __( 'false disallows the crawlers of AI search and assistants in robots.txt.', 'leitvia-studio' ),
					),
				),
			) + $object,
			array( self::class, 'describe_set_llms' )
		);
		Abilities::write(
			'leitvia/set-motion',
			__( 'Set motion', 'leitvia-studio' ),
			__( 'Sets the motion of the whole site: off (no effect on scroll and no hover motion of the theme), subtle (800 ms, the default) or bold (twice the way, 1200 ms). It is the strength and duration of every effect of leitviaAnimation without leitviaMotion, so one setting keeps the motion of all pages the same. When a person wants more or less motion, change this setting instead of giving single blocks effects or leitviaMotion. base_hash is empty. Like every setting it waits in a change package for a person.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'motion' => array(
						'type'        => 'string',
						'enum'        => \LeitviaStudio\Blocks\Animation::SITE,
						'description' => __( 'off, subtle or bold.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'motion' ),
			) + $object,
			array( self::class, 'describe_set_motion' )
		);
		$image = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		Abilities::write(
			'leitvia/set-site-identity',
			__( 'Set site identity', 'leitvia-studio' ),
			__( 'Sets the site title, the tagline, the logo and the site icon. logo and icon are image IDs from leitvia/list-media or leitvia/add-media, 0 removes them. The logo is the one core/site-logo shows in the header and WordPress hands out as custom logo, the title is the text of core/site-title and the start of every page title in the browser, the tagline that of core/site-tagline; so the header needs no image block for the logo. The icon is the small image of browser tabs and bookmarks, square and at least 512 pixels wide; notice says so otherwise. Only the fields you send change, an empty string clears the tagline. base_hash is empty. Like every setting it waits in a change package for a person.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'title'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'tagline' => array(
						'type'      => 'string',
						'maxLength' => 300,
					),
					'logo'    => $image,
					'icon'    => $image,
				),
			) + $object,
			array( self::class, 'describe_set_site_identity' )
		);
		Abilities::write(
			'leitvia/set-front-page',
			__( 'Set front page', 'leitvia-studio' ),
			__( 'Makes an existing page the static front page; 0 shows the latest posts there again. The front page is the page the address of the site shows. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied; the posts page cannot be the front page as well. Like every setting it waits in a change package for a person. notice says so while the page is not published. Set the posts page with leitvia/set-posts-page.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'page' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of a page from leitvia/list-content, 0 for the latest posts.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'page' ),
			) + $object,
			array( self::class, 'describe_set_front_page' )
		);
		$provider = array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array(
					'type'        => 'string',
					'description' => __( 'Key from leitvia/get-consent-providers; send it to keep a provider, so "Always allow" of visitors stays with it. A new provider gets one from its label.', 'leitvia-studio' ),
				),
				'label'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Name of the provider, as the consent placeholder shows it.', 'leitvia-studio' ),
				),
				'hosts'   => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => Consent::MAX_HOSTS,
					'description' => __( 'Host names of the embedded content, such as maps.example.com; subdomains count as well, the own site never.', 'leitvia-studio' ),
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 253,
					),
				),
				'purpose' => array(
					'type'        => 'string',
					'description' => __( 'One sentence on what the content does, shown in the placeholder.', 'leitvia-studio' ),
				),
				'privacy' => array(
					'type'        => 'string',
					'pattern'     => '^(https://.*)?$',
					'description' => __( 'https address of the privacy policy of the provider.', 'leitvia-studio' ),
				),
			),
			'required'             => array( 'label', 'hosts' ),
			'additionalProperties' => false,
		);
		Abilities::read(
			'leitvia/get-consent-providers',
			__( 'Get consent providers', 'leitvia-studio' ),
			__( 'Returns the providers of embedded content from the privacy settings. providers holds key, label, hosts, purpose and privacy of each, services the services the consent placeholder knows without an entry (key and label, such as YouTube), hash the base_hash of leitvia/set-consent-providers. Content from a host of a provider or service waits behind a consent placeholder named after it.', 'leitvia-studio' ),
			$object + array( 'properties' => array() ),
			array(
				'type'       => 'object',
				'required'   => array( 'providers', 'services', 'hash' ),
				'properties' => array(
					'providers' => array(
						'type'        => 'array',
						'description' => __( 'Providers saved under Settings, Privacy.', 'leitvia-studio' ),
						'items'       => $provider,
					),
					'services'  => array(
						'type'        => 'array',
						'description' => __( 'Services the consent placeholder knows without an entry.', 'leitvia-studio' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'key'   => array( 'type' => 'string' ),
								'label' => array( 'type' => 'string' ),
							),
						),
					),
					'hash'      => array(
						'type'        => 'string',
						'description' => __( 'Hash of the providers, the base_hash of leitvia/set-consent-providers.', 'leitvia-studio' ),
					),
				),
			),
			static fn(): array => self::consent_providers()
		);
		Abilities::write(
			'leitvia/set-consent-providers',
			__( 'Set consent providers', 'leitvia-studio' ),
			/* translators: %d: most providers */
			sprintf( __( 'Replaces the providers of embedded content in the privacy settings. Send the whole list, at most %d; a provider you leave out is removed. Read them with leitvia/get-consent-providers first, send its hash as base_hash and the key of every provider that stays. A built-in service such as YouTube needs no entry. Hosts lose scheme, port and path; a provider without a valid host of another site is rejected. Use only providers the site embeds and privacy policies the provider publishes. Like every setting it waits in a change package for a person.', 'leitvia-studio' ), Consent::MAX_PROVIDERS ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'providers' => array(
						'type'        => 'array',
						'maxItems'    => Consent::MAX_PROVIDERS,
						'description' => __( 'The whole new list of providers; an empty list removes all.', 'leitvia-studio' ),
						'items'       => $provider,
					),
				),
				'required'   => array( 'providers' ),
			) + $object,
			array( self::class, 'describe_set_consent_providers' )
		);
		Abilities::write(
			'leitvia/define-content-type',
			__( 'Define content type', 'leitvia-studio' ),
			__( 'Adds entries to the content model (postTypes, taxonomies, relations, settings) and keeps the others. An entry that exists is merged: the input sets its own keys and fields, fields, repeaters and supports it does not name stay, and the postTypes of a taxonomy are joined; relations and settings are replaced whole. Nothing is removed through this ability: an entry, field, repeater or supports value can only be removed by a person on the Model screen. Read the declaration with leitvia/get-content-model first. The base hash is the hash of the content model. leitvia/get-content-model returns it as hash. showInAbilities stays a decision of a person: agents may not set it. A new address (slug, or the key) that a published page or another public type already has is refused: the subpages of such a page would lead to a 404.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'declaration' => array(
						'type'        => 'object',
						'description' => __( 'Content model declaration with the entries to add or replace, see leitvia/get-content-model.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'declaration' ),
			) + $object,
			array( self::class, 'describe_define_content_type' )
		);
		Abilities::write(
			'leitvia/update-site-values',
			__( 'Update site values', 'leitvia-studio' ),
			__( 'Sets values of site settings of the content model declared with bindable true, the values blocks show on every page through the binding source leitvia/setting, such as opening hours or a phone number. values names them by key; the settings you leave out stay. Each value follows the field type of its setting, an empty string empties a text. leitvia/get-content-model returns the values as settings_values and their hash as settings_hash, the base_hash. Like every setting it waits in a change package for a person, and leitvia/rollback undoes it once applied. Declare a new setting with leitvia/define-content-type first.', 'leitvia-studio' ),
			'setup',
			array(
				'destructive' => true,
				'idempotent'  => false,
			),
			array(
				'properties' => array(
					'values' => array(
						'type'                 => 'object',
						'minProperties'        => 1,
						'maxProperties'        => 50,
						'description'          => __( 'New values by setting key, for example {"opening_hours": "Mon to Fri 8 to 18"}; only settings with bindable true.', 'leitvia-studio' ),
						'additionalProperties' => array( 'type' => array( 'string', 'number', 'boolean' ) ),
					),
				),
				'required'   => array( 'values' ),
			) + $object,
			array( self::class, 'describe_update_site_values' )
		);
		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'label' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'description' => __( 'Text of the link; it names the target.', 'leitvia-studio' ),
				),
				'url'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 2000,
					'description' => __( 'Address of this site, https, mailto: or tel:. An item with sub items may leave it out: it then only opens its submenu.', 'leitvia-studio' ),
				),
				'items' => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'label' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 100,
								'description' => __( 'Text of the link; it names the target.', 'leitvia-studio' ),
							),
							'url'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 2000,
								'description' => __( 'Address of this site, https, mailto: or tel:.', 'leitvia-studio' ),
							),
						),
						'required'             => array( 'label', 'url' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'label' ),
			'additionalProperties' => false,
		);
		Abilities::write(
			'leitvia/update-navigation',
			__( 'Update navigation', 'leitvia-studio' ),
			__( 'Replaces the links of a navigation menu, by default the one published last, which headers without a chosen menu show. Send items (with one level of sub items) or block markup of navigation links, submenus, page lists and mega menus. Links point to this site, use https, mailto: or tel:, and need a text that names their target. The change publishes the menu, a draft one too, since core shows no draft menu. The base hash is the hash of the menu, empty when the site has none. leitvia/get-contract returns it per published menu under hashes.navigation.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'navigation' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'items'      => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 30,
						'items'    => $item,
					),
					'content'    => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
			) + $object,
			array( self::class, 'describe_update_navigation' )
		);
		Abilities::write(
			'leitvia/update-template-part',
			__( 'Update template part', 'leitvia-studio' ),
			__( 'Replaces a template part of the contract, such as header, footer or sidebar, with a pattern made for its area or with changed block markup. Part names come from leitvia/get-contract; with Polylang <part>-<language> (header-de) is the part the theme shows for that language and is created in the area of its part. Patterns come from leitvia/list-patterns; edits change the pattern at block IDs of leitvia/get-pattern first, as the sections of leitvia/compose-page do, so a footer or header needs no markup of its own. The change publishes the part, a draft one too: core shows no draft part, so where the area level is draft it waits in a change package. The base hash is the hash of the template part. leitvia/get-contract returns it per part under hashes.templateParts.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'part'    => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]+$',
						'description' => __( 'Name of a template part from leitvia/get-contract, such as header or footer.', 'leitvia-studio' ),
					),
					'pattern' => array( 'type' => 'string' ),
					'edits'   => array( 'description' => __( 'Only with pattern: edits at block IDs of the pattern, as in the sections of leitvia/compose-page.', 'leitvia-studio' ) ) + Build::edits_schema(),
					'content' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'   => array( 'part' ),
			) + $object,
			array( self::class, 'describe_update_template_part' )
		);
		Abilities::write(
			'leitvia/update-template',
			__( 'Update template', 'leitvia-studio' ),
			__( 'Replaces a template with a template pattern made for it or with changed block markup. Slugs, such as single, single-product or taxonomy-product_cat, come from the templates of leitvia/get-contract and the templates the theme ships; WooCommerce templates need WooCommerce. A pattern fits when its templateTypes hold the slug; the rejection leitvia_unknown_pattern lists the patterns that fit in patterns. Templates for single posts and pages keep a core/post-content block. The change publishes the template; where the area level is draft a new one waits in a change package. A rollback restores the template as it was, the one of the theme when there was no saved one. The base hash is the hash of the saved template, empty when the theme template has not been saved yet. leitvia/get-contract returns it per template under hashes.templates.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					// Template slugs of core and WooCommerce may hold an underscore: taxonomy-product_cat, taxonomy-product_tag.
					'template' => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9_-]+$',
						'description' => __( 'Template slug from leitvia/get-contract or of the theme, such as single or taxonomy-product_cat.', 'leitvia-studio' ),
					),
					'pattern'  => array( 'type' => 'string' ),
					'content'  => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'   => array( 'template' ),
			) + $object,
			array( self::class, 'describe_update_template' )
		);
	}

	/**
	 * Operation of create-token-set.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_create_token_set( array $input ): array {
		$json = (string) wp_json_encode( $input['document'] ?? array() );
		// The same title and document again (a recipe applied twice) target the set that exists; nothing is added.
		$same = array_filter(
			get_posts(
				array(
					'post_type'      => Token_Sets::POST_TYPE,
					'post_status'    => 'private',
					'title'          => (string) ( $input['title'] ?? '' ),
					'fields'         => 'ids',
					'posts_per_page' => 20,
					'no_found_rows'  => true,
				)
			),
			static fn( int $id ): bool => json_decode( (string) get_post_meta( $id, Token_Sets::META, true ), true ) == ( $input['document'] ?? array() ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Key order of the stored JSON may differ.
		);
		if ( $same ) {
			return array(
				'area'   => 'design',
				'target' => array( 'post' => (int) reset( $same ) ),
				'change' => static fn( array $state ): array => $state,
			);
		}
		return array(
			'area'   => 'design',
			'target' => array(
				'post'      => 0,
				'post_type' => Token_Sets::POST_TYPE,
			),
			'change' => static function ( array $state ) use ( $json, $input ) {
				$valid = Token_Sets::validate( $json );
				if ( is_wp_error( $valid ) ) {
					return self::token_findings( $valid );
				}
				return array(
					'post_title'  => (string) $input['title'],
					'post_status' => 'private',
					'meta_input'  => array( Token_Sets::META => $json ),
				) + $state;
			},
		);
	}

	/**
	 * Operation of activate-token-set.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_activate_token_set( array $input ) {
		$set = (int) ( $input['set'] ?? 0 );
		if ( Token_Sets::POST_TYPE !== get_post_type( $set ) || ! Scope::readable( $set ) ) {
			return Block_Edit::invalid( 'set', __( 'There is no token set with this ID.', 'leitvia-studio' ), array( 'post' => $set ), 'leitvia/get-tokens' );
		}
		return self::styles(
			array( 'token_set' => $set ),
			static function ( array $data ) use ( $set ) {
				$styles = Compiler::styles( $set, $data );
				if ( is_wp_error( $styles ) && 'leitvia_studio_token_set_invalid' === $styles->get_error_code() ) {
					return self::token_findings( $styles );
				}
				return is_wp_error( $styles ) ? Block_Edit::invalid( 'set', $styles->get_error_message(), array( 'post' => $set ), 'leitvia/get-tokens' ) : $styles;
			}
		);
	}

	/**
	 * Operation of set-variation.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_variation( array $input ) {
		$slug = (string) ( $input['variation'] ?? '' );
		$kind = '';
		foreach ( leitvia_studio_contract()->variations() as $variation ) {
			if ( ( $variation['slug'] ?? null ) === $slug ) {
				$kind = (string) ( $variation['kind'] ?? 'full' );
			}
		}
		$data = null;
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			if ( ( $variation['slug'] ?? sanitize_title( (string) ( $variation['title'] ?? '' ) ) ) === $slug ) {
				$data = array_intersect_key( $variation, array_flip( array( 'settings', 'styles' ) ) );
			}
		}
		if ( '' === $kind || null === $data ) {
			return Block_Edit::invalid( 'variation', __( 'The contract has no style variation with this slug.', 'leitvia-studio' ), array( 'variation' => $slug ), 'leitvia/get-contract' );
		}
		return self::styles(
			array(),
			static fn( array $current ): array => array(
				'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
				'isGlobalStylesUserThemeJSON' => true,
			) + ( 'full' === $kind ? $data : self::merge( $current, $data ) )
		);
	}

	/**
	 * Operation of set-posts-page: the option page_for_posts, a page of the site or 0.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_posts_page( array $input ) {
		$page   = (int) ( $input['page'] ?? 0 );
		$target = array( 'option' => 'page_for_posts' );
		if ( $page && ( 'page' !== get_post_type( $page ) || ! Scope::readable( $page ) ) ) {
			return Block_Edit::invalid( 'page', __( 'There is no page with this ID that agents can read. Take the ID from leitvia/list-content.', 'leitvia-studio' ), $target + array( 'page' => $page ), 'leitvia/list-content' );
		}
		if ( $page && (int) get_option( 'page_on_front' ) === $page ) {
			return Block_Edit::invalid( 'page', __( 'The front page cannot be the posts page as well. Use another page.', 'leitvia-studio' ), $target + array( 'page' => $page ) );
		}
		$notices = array(
			$page && 'page' !== get_option( 'show_on_front' ) ? __( 'The posts page takes effect once the site has a static front page.', 'leitvia-studio' ) : '',
			$page && 'publish' !== get_post_status( $page ) ? __( 'The page is not published yet, so its address shows nothing to visitors.', 'leitvia-studio' ) : '',
		);
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( 'page_for_posts' ) ) ),
			'notice'    => implode( ' ', array_filter( $notices ) ),
			'change'    => static fn(): array => array( 'value' => (string) $page ),
		);
	}

	/**
	 * Operation of set-privacy-page: the option wp_page_for_privacy_policy, a page of the site or 0.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_privacy_page( array $input ) {
		$page   = (int) ( $input['page'] ?? 0 );
		$target = array( 'option' => 'wp_page_for_privacy_policy' );
		if ( $page && ( 'page' !== get_post_type( $page ) || ! Scope::readable( $page ) ) ) {
			return Block_Edit::invalid( 'page', __( 'There is no page with this ID that agents can read. Take the ID from leitvia/list-content.', 'leitvia-studio' ), $target + array( 'page' => $page ), 'leitvia/list-content' );
		}
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( 'wp_page_for_privacy_policy' ) ) ),
			'notice'    => $page && 'publish' !== get_post_status( $page ) ? __( 'The page is not published yet, so forms show no link to it.', 'leitvia-studio' ) : '',
			'change'    => static fn(): array => array( 'value' => (string) $page ),
		);
	}

	/**
	 * Operation of set-organization: the fields of the input over the saved Studio settings, which sanitize them
	 * (Settings::sanitize()).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_organization( array $input ) {
		$fields = array();
		foreach ( Organization::FIELDS as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$fields[ 'organization_' . $field ] = $input[ $field ];
			}
		}
		if ( array_key_exists( 'aggregate_rating', $input ) ) {
			$rating = is_array( $input['aggregate_rating'] ) ? $input['aggregate_rating'] : array();
			foreach ( Organization::RATING_FIELDS as $key => $setting ) {
				$fields[ $setting ] = $rating[ $key ] ?? Settings::DEFAULTS[ $setting ];
			}
		}
		$target = array( 'option' => Settings::OPTION );
		if ( ! $fields ) {
			/* translators: %s: list of field names. */
			return Block_Edit::invalid( 'change', sprintf( __( 'Name what to change: %s.', 'leitvia-studio' ), implode( ', ', array_merge( Organization::FIELDS, array( 'aggregate_rating' ) ) ) ), $target );
		}
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( Settings::OPTION ) ) ),
			'change'    => static fn( array $state ): array => array( 'value' => $fields + (array) $state['value'] ),
		);
	}

	/**
	 * Operation of set-redirects: the setting redirects over the saved Studio settings, after Redirects::sanitize()
	 * and the check for loops; a row the sanitizer drops is refused, so nothing changes silently.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_redirects( array $input ) {
		$target = array( 'option' => Settings::OPTION );
		$given  = array_values( (array) ( $input['redirects'] ?? array() ) );
		$rows   = \LeitviaStudio\Seo\Redirects::sanitize( $given );
		if ( count( $rows ) !== count( $given ) ) {
			$kept = array_column( $rows, 'from' );
			$bad  = array_values( array_filter( array_map( static fn( $row ): string => is_array( $row ) ? (string) ( $row['from'] ?? '' ) : '', $given ), static fn( string $from ): bool => ! in_array( \LeitviaStudio\Seo\Redirects::from( $from ), $kept, true ) ) );
			/* translators: %s: paths */
			return Block_Edit::invalid( 'redirects', sprintf( __( 'These rows are no valid redirect: %s. from is a path of the site, with or without a query, other than the bare start page, used once; status is 301, 302 or 410; to is another path or an https address for 301 and 302 and left out for 410.', 'leitvia-studio' ), implode( ', ', $bad ) ), $target );
		}
		$loops = \LeitviaStudio\Seo\Redirects::loops( $rows );
		if ( $loops ) {
			/* translators: %s: paths */
			return Block_Edit::invalid( 'redirects', sprintf( __( 'These redirects lead back to themselves: %s. Point each to the final address.', 'leitvia-studio' ), implode( ', ', $loops ) ), $target );
		}
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( Settings::OPTION ) ) ),
			'change'    => static fn( array $state ): array => array( 'value' => array( 'redirects' => $rows ) + (array) $state['value'] ),
		);
	}

	/**
	 * Operation of set-llms: llms_txt, llms_summary, llms_pages and ai_crawlers over the saved Studio settings.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_llms( array $input ) {
		$target = array( 'option' => Settings::OPTION );
		$fields = array();
		foreach ( array(
			'enabled'     => 'llms_txt',
			'summary'     => 'llms_summary',
			'pages'       => 'llms_pages',
			'ai_crawlers' => 'ai_crawlers',
		) as $key => $setting ) {
			if ( array_key_exists( $key, $input ) ) {
				$fields[ $setting ] = $input[ $key ];
			}
		}
		foreach ( (array) ( $fields['llms_pages'] ?? array() ) as $page ) {
			if ( 'publish' !== get_post_status( (int) $page ) || ! Scope::readable( (int) $page ) ) {
				return Block_Edit::invalid( 'pages', __( 'llms.txt lists published pages and posts agents can read only.', 'leitvia-studio' ), $target + array( 'post' => (int) $page ), 'leitvia/list-content' );
			}
		}
		if ( ! $fields ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: enabled, summary, pages or ai_crawlers.', 'leitvia-studio' ), $target );
		}
		$on = array_key_exists( 'llms_txt', $fields ) ? (bool) $fields['llms_txt'] : (bool) leitvia_studio_setting( 'llms_txt' );
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( Settings::OPTION ) ) ),
			'notice'    => $on ? \LeitviaStudio\Seo\Llms::held_back() : '',
			'change'    => static fn( array $state ): array => array( 'value' => $fields + (array) $state['value'] ),
		);
	}

	/**
	 * Operation of set-motion: the site setting motion over the saved Studio settings.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_set_motion( array $input ): array {
		$motion = (string) $input['motion'];
		return array(
			'area'      => 'structure',
			'target'    => array( 'option' => Settings::OPTION ),
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( Settings::OPTION ) ) ),
			'change'    => static fn( array $state ): array => array( 'value' => array( 'motion' => $motion ) + (array) $state['value'] ),
		);
	}

	/**
	 * Operations of set-permalinks: permalink_structure, category_base and tag_base, one per field sent. A structure
	 * needs a tag that names one post. While the site has published posts and the structure changes, notice says that
	 * their addresses move.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_permalinks( array $input ) {
		$target = array( 'option' => 'permalink_structure' );
		$ops    = array();
		if ( array_key_exists( 'structure', $input ) ) {
			$structure = '/' . trim( (string) $input['structure'], '/' ) . '/';
			if ( ! preg_match( '/%(postname|post_id)%/', $structure ) ) {
				return Block_Edit::invalid( 'structure', __( 'A permalink structure names one post with %postname% or %post_id%, such as /news/%postname%/.', 'leitvia-studio' ), $target );
			}
			$posts  = (int) wp_count_posts( 'post' )->publish;
			$notice = (string) get_option( 'permalink_structure' ) !== $structure && $posts
				/* translators: %d: number of published posts. */
				? sprintf( _n( '%d published post changes its address. Lead the old address to the new one with leitvia/set-redirects, so links and search results keep working.', '%d published posts change their addresses. Lead the old addresses to the new ones with leitvia/set-redirects, so links and search results keep working.', $posts, 'leitvia-studio' ), $posts )
				: '';
			$ops[] = self::option_op( 'permalink_structure', $structure, $notice );
		}
		foreach ( array(
			'category_base' => 'category_base',
			'tag_base'      => 'tag_base',
		) as $key => $option ) {
			if ( array_key_exists( $key, $input ) ) {
				$ops[] = self::option_op( $option, sanitize_title( (string) $input[ $key ] ) );
			}
		}
		if ( ! $ops ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: structure, category_base or tag_base.', 'leitvia-studio' ), $target );
		}
		return 1 === count( $ops ) ? $ops[0] : array( 'batch' => $ops );
	}

	/**
	 * One operation on an option of Pipeline::OPTIONS that sets it to a value, with the hash of its current value.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @param string $notice Notice for the result, '' for none.
	 * @return array<string, mixed>
	 */
	private static function option_op( string $option, $value, string $notice = '' ): array {
		return array(
			'area'      => 'structure',
			'target'    => array( 'option' => $option ),
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( $option ) ) ),
			'notice'    => $notice,
			'change'    => static fn(): array => array( 'value' => $value ),
		);
	}

	/**
	 * Operations of set-site-identity: blogname, blogdescription, site_logo and site_icon, one per field sent. Core
	 * returns site_logo as theme mod custom_logo as well (_override_custom_logo_theme_mod()), so core/site-logo and
	 * get_custom_logo() show the same image. Images are checked before anything runs, so a call changes all its fields
	 * or none.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_site_identity( array $input ) {
		$options = array(
			'title'   => 'blogname',
			'tagline' => 'blogdescription',
			'logo'    => 'site_logo',
			'icon'    => 'site_icon',
		);
		$batch   = array();
		foreach ( array_intersect_key( $options, $input ) as $field => $option ) {
			$target = array( 'option' => $option );
			if ( 'title' === $field || 'tagline' === $field ) {
				$value = sanitize_text_field( (string) $input[ $field ] );
				if ( 'title' === $field && '' === $value ) {
					return Block_Edit::invalid( 'title', __( 'The site title cannot be empty.', 'leitvia-studio' ), $target );
				}
				$batch[] = self::option_op( $option, $value );
				continue;
			}
			$id = (int) $input[ $field ];
			if ( $id && ( ! wp_attachment_is_image( $id ) || ! Scope::readable( $id ) ) ) {
				return Block_Edit::invalid( $field, __( 'The ID is no image of the media library.', 'leitvia-studio' ), $target + array( 'image' => $id ), 'leitvia/list-media' );
			}
			$size    = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
			$small   = 'icon' === $field && $size && ( (int) $size[1] !== (int) $size[2] || (int) $size[1] < 512 );
			$batch[] = self::option_op( $option, $id, $small ? __( 'The site icon should be square and at least 512 pixels wide, or browsers show it blurred or cut.', 'leitvia-studio' ) : '' );
		}
		if ( ! $batch ) {
			/* translators: %s: list of field names. */
			return Block_Edit::invalid( 'change', sprintf( __( 'Name what to change: %s.', 'leitvia-studio' ), implode( ', ', array_keys( $options ) ) ), array( 'option' => 'blogname' ) );
		}
		return array( 'batch' => $batch );
	}

	/**
	 * Operations of set-front-page: show_on_front page with page_on_front the page, or show_on_front posts with
	 * page_on_front 0.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_front_page( array $input ) {
		$page   = (int) ( $input['page'] ?? 0 );
		$target = array( 'option' => 'page_on_front' );
		if ( $page && ( 'page' !== get_post_type( $page ) || ! Scope::readable( $page ) ) ) {
			return Block_Edit::invalid( 'page', __( 'There is no page with this ID that agents can read. Take the ID from leitvia/list-content.', 'leitvia-studio' ), $target + array( 'page' => $page ), 'leitvia/list-content' );
		}
		if ( $page && (int) get_option( 'page_for_posts' ) === $page ) {
			return Block_Edit::invalid( 'page', __( 'The posts page cannot be the front page as well. Use another page, or move the posts page first with leitvia/set-posts-page.', 'leitvia-studio' ), $target + array( 'page' => $page ), 'leitvia/set-posts-page' );
		}
		return array(
			'batch' => array(
				self::option_op( 'show_on_front', $page ? 'page' : 'posts' ),
				self::option_op( 'page_on_front', (string) $page, $page && 'publish' !== get_post_status( $page ) ? __( 'The page is not published yet, so the address of the site shows nothing to visitors.', 'leitvia-studio' ) : '' ),
			),
		);
	}

	/**
	 * Output of get-consent-providers: the saved providers, the other services the placeholder knows and the hash.
	 *
	 * @return array<string, mixed>
	 */
	public static function consent_providers(): array {
		$providers = Consent::sanitize_providers( leitvia_studio_setting( 'consent_providers' ) );
		$saved     = array_flip( array_column( $providers, 'key' ) );
		$services  = array();
		foreach ( array_diff_key( Consent::services(), $saved ) as $key => $service ) {
			$services[] = array(
				'key'   => (string) $key,
				'label' => (string) ( $service['label'] ?? $key ),
			);
		}
		return array(
			'providers' => $providers,
			'services'  => $services,
			'hash'      => Hashes::of( (string) wp_json_encode( $providers ) ),
		);
	}

	/**
	 * Operation of set-consent-providers: the list sanitized as the settings page does (Consent::sanitize_providers())
	 * in place of the saved one. A provider the sanitizing would drop is rejected, so nothing vanishes unnoticed.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_consent_providers( array $input ) {
		$target  = array( 'option' => Settings::OPTION );
		$current = (string) self::consent_providers()['hash'];
		if ( ! hash_equals( $current, (string) ( $input['base_hash'] ?? '' ) ) ) {
			return Rejection::make(
				'leitvia_stale_hash',
				/* translators: %s: current hash of the providers */
				sprintf( __( 'The providers changed since they were read. Read them with leitvia/get-consent-providers and repeat the change on the current list with base_hash %s.', 'leitvia-studio' ), $current ),
				409,
				'stale_hash',
				$target,
				'leitvia/get-consent-providers',
				'once',
				array( 'current_hash' => $current )
			);
		}
		$given   = array_values( (array) ( $input['providers'] ?? array() ) );
		$dropped = array();
		foreach ( $given as $i => $provider ) {
			if ( ! Consent::sanitize_providers( array( $provider ) ) ) {
				$dropped[] = (string) ( $i + 1 );
			}
		}
		if ( $dropped ) {
			return Block_Edit::invalid(
				'providers',
				/* translators: %s: positions of the providers in the list, such as 1, 3 */
				sprintf( __( 'These providers of the list have no name or no valid host of another site, such as maps.example.com: %s.', 'leitvia-studio' ), implode( ', ', $dropped ) ),
				$target,
				'leitvia/get-consent-providers'
			);
		}
		$clean = Consent::sanitize_providers( $given );
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( Settings::OPTION ) ) ),
			'change'    => static fn( array $state ): array => array( 'value' => array( 'consent_providers' => $clean ) + (array) $state['value'] ),
		);
	}

	/**
	 * Operation of define-content-type: the entries of the input over the stored declaration, see merge_entry(). An
	 * entry keeps the showInAbilities a person set.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_define_content_type( array $input ) {
		$decl   = (array) ( $input['declaration'] ?? array() );
		$opened = static fn( array $model ): array => array_map( static fn( $section ): array => array_filter( array_map( static fn( $entry ) => is_array( $entry ) ? ( $entry['showInAbilities'] ?? null ) : null, (array) $section ), static fn( $value ): bool => null !== $value ), array_intersect_key( $model, array_flip( Model::SECTIONS ) ) );
		if ( self::has_key( $decl, 'showInAbilities' ) && ( null === self::$building_block || $opened( $decl ) != $opened( self::$building_block ) ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same values per entry, order aside.
			return Rejection::make( 'leitvia_forbidden_field', __( 'Agents may not set showInAbilities. Whether content is open to AI abilities is the decision of a person; remove the field and try again.', 'leitvia-studio' ), 403, 'show_in_abilities', array( 'option' => Pipeline::MODEL_OPTION ) );
		}
		return array(
			'area'   => 'structure',
			'target' => array( 'option' => Pipeline::MODEL_OPTION ),
			'change' => static function ( array $state ) use ( $decl ) {
				$stored = is_array( $state['value'] ) ? $state['value'] : array();
				$merged = array( 'version' => 1 );
				foreach ( Model::SECTIONS as $section ) {
					$kept    = (array) ( $stored[ $section ] ?? array() );
					$entries = (array) ( $decl[ $section ] ?? array() );
					foreach ( $entries as $key => $entry ) {
						if ( is_array( $entry ) && isset( $kept[ $key ] ) && is_array( $kept[ $key ] ) ) {
							$entries[ $key ] = self::merge_entry( $section, $kept[ $key ], $entry );
						}
						if ( is_array( $entry ) && isset( $kept[ $key ]['showInAbilities'] ) ) {
							$entries[ $key ]['showInAbilities'] = $kept[ $key ]['showInAbilities'];
						}
					}
					if ( $entries + $kept ) {
						$merged[ $section ] = $entries + $kept;
					}
				}
				$valid = Model::save( $merged, true );
				if ( is_wp_error( $valid ) ) {
					$findings = array();
					foreach ( (array) ( $valid->get_error_data()['errors'] ?? array() ) as $error ) {
						$findings[] = array(
							'check'    => 'leitvia/content-model',
							'severity' => 'error',
							'block'    => (string) ( $error['path'] ?? '' ),
							'message'  => (string) ( $error['message'] ?? $valid->get_error_message() ),
							'fix'      => __( 'Change the entry at this path of the declaration.', 'leitvia-studio' ),
						);
					}
					return self::check_failed( array( 'option' => Pipeline::MODEL_OPTION ), $findings, 'leitvia/get-content-model' );
				}
				$clashes = self::address_clashes( $decl, $merged );
				if ( $clashes ) {
					return self::check_failed( array( 'option' => Pipeline::MODEL_OPTION ), $clashes, 'leitvia/list-content' );
				}
				return array( 'value' => $merged );
			},
		);
	}

	/**
	 * Findings for entries of the input that take an address another one has: the base of a published page (its
	 * subpages would lead to a 404, since the rules of the type come first) or of another public post type or
	 * taxonomy. Only an address the entry does not have yet counts, so a known clash does not block other changes.
	 *
	 * @param array<string, mixed> $decl   Declaration of the input.
	 * @param array<string, mixed> $merged Declaration after the change.
	 * @return list<array<string, string>>
	 */
	private static function address_clashes( array $decl, array $merged ): array {
		$taken = array();
		foreach ( array_merge( get_post_types( array( 'public' => true ), 'objects' ), get_taxonomies( array( 'public' => true ), 'objects' ) ) as $object ) {
			// A type the content model registered but the model after the change no longer holds leaves its address.
			$section = $object instanceof \WP_Post_Type ? 'postTypes' : 'taxonomies';
			$owned   = 'postTypes' === $section ? Registrar::owns_post_type( $object->name ) : Registrar::owns_taxonomy( $object->name );
			if ( $owned && ! isset( $merged[ $section ][ $object->name ] ) ) {
				continue;
			}
			$rewrite = $object->rewrite;
			if ( is_array( $rewrite ) && '' !== trim( (string) ( $rewrite['slug'] ?? '' ), '/' ) ) {
				$taken[ self::address( (string) $rewrite['slug'], ! empty( $rewrite['with_front'] ) ) ][ $object->name ] = $object->name;
			}
		}
		$planned = array();
		foreach ( array( 'postTypes', 'taxonomies' ) as $section ) {
			foreach ( (array) ( $merged[ $section ] ?? array() ) as $key => $entry ) {
				$entry = (array) $entry;
				$open  = ! empty( $entry['public'] ) && ( 'taxonomies' === $section || ( $entry['singlePages'] ?? true ) );
				if ( $open ) {
					$planned[ $section ][ (string) $key ] = self::address( (string) ( $entry['slug'] ?? $key ), ! empty( $entry['withFront'] ) );
				}
			}
		}
		$findings = array();
		foreach ( array( 'postTypes', 'taxonomies' ) as $section ) {
			foreach ( array_keys( (array) ( $decl[ $section ] ?? array() ) ) as $key ) {
				$key  = (string) $key;
				$base = $planned[ $section ][ $key ] ?? '';
				if ( '' === $base || isset( $taken[ $base ][ $key ] ) ) {
					continue;
				}
				$others = array_values( $taken[ $base ] ?? array() );
				foreach ( $planned as $other_section => $entries ) {
					foreach ( $entries as $other => $address ) {
						if ( $address === $base && ( $other_section !== $section || $other !== $key ) ) {
							$others[] = $other;
						}
					}
				}
				$page    = get_page_by_path( $base );
				$message = match ( true ) {
					$page instanceof \WP_Post && 'publish' === $page->post_status => sprintf(
						/* translators: 1: address such as /services/, 2: page title, 3: page ID */
						__( 'The address %1$s belongs to the published page %2$s (ID %3$d). Its subpages would lead to a 404 once the content type takes the address.', 'leitvia-studio' ),
						'/' . $base . '/',
						$page->post_title,
						$page->ID
					),
					/* translators: 1: address such as /services/, 2: names of post types or taxonomies */
					(bool) $others => sprintf( __( 'The address %1$s belongs to %2$s already.', 'leitvia-studio' ), '/' . $base . '/', implode( ', ', array_unique( $others ) ) ),
					default => '',
				};
				if ( '' !== $message ) {
					$findings[] = array(
						'check'    => 'leitvia/content-model',
						'severity' => 'error',
						'block'    => '/' . $section . '/' . $key . '/slug',
						'message'  => $message,
						'fix'      => $page instanceof \WP_Post && 'publish' === $page->post_status
							/* translators: %d: page ID */
							? sprintf( __( 'Give page %d another slug with leitvia/update-content (slug) or move it under another parent, then repeat the call; the content type then takes the address. Or choose another slug for the content type.', 'leitvia-studio' ), $page->ID )
							: __( 'Choose another slug, or move or rename the page first.', 'leitvia-studio' ),
					);
				}
			}
		}
		return $findings;
	}

	/**
	 * Path of the archive address of a slug, without slashes around it: with the prefix of the post permalinks when
	 * with_front is on.
	 *
	 * @param string $slug       Rewrite slug.
	 * @param bool   $with_front Whether the prefix of the post permalinks comes first.
	 */
	private static function address( string $slug, bool $with_front ): string {
		global $wp_rewrite;
		$front = $with_front && $wp_rewrite instanceof \WP_Rewrite ? trim( (string) $wp_rewrite->front, '/' ) : '';
		return trim( ( '' !== $front ? $front . '/' : '' ) . trim( $slug, '/' ), '/' );
	}

	/**
	 * Stored values of the site settings of the content model with bindable true, by key; null for a value never set.
	 * The binding source leitvia/setting shows them to every visitor, so reading them reveals nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_settings(): array {
		$values = array();
		foreach ( (array) ( Model::current()['settings'] ?? array() ) as $key => $setting ) {
			if ( ! empty( $setting['bindable'] ) ) {
				$values[ (string) $key ] = get_option( Registrar::SETTING_PREFIX . $key, null );
			}
		}
		return $values;
	}

	/**
	 * Hash of the values of the bindable site settings, the base hash of update-site-values.
	 *
	 * @param array<string, mixed>|null $values Values from site_settings(), read again when null.
	 */
	public static function settings_hash( ?array $values = null ): string {
		return Hashes::of( (string) wp_json_encode( (object) ( $values ?? self::site_settings() ) ) );
	}

	/**
	 * Operations of update-site-values: one option operation per value, checked against the field type of its
	 * setting before anything runs, so a call changes all its settings or none. The base hash covers all bindable
	 * settings; each operation then carries the hash of its option, as the pipeline checks it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_site_values( array $input ) {
		$settings = (array) ( Model::current()['settings'] ?? array() );
		$values   = (array) ( $input['values'] ?? array() );
		if ( ! $values ) {
			return Block_Edit::invalid( 'values', __( 'Name at least one setting in values.', 'leitvia-studio' ), array(), 'leitvia/get-content-model' );
		}
		$current = self::settings_hash();
		if ( ! hash_equals( $current, (string) ( $input['base_hash'] ?? '' ) ) ) {
			return Rejection::make(
				'leitvia_stale_hash',
				/* translators: %s: current hash of the site settings */
				sprintf( __( 'The site settings changed since they were read. Read them with leitvia/get-content-model and repeat the change on the current values with base_hash %s.', 'leitvia-studio' ), $current ),
				409,
				'stale_hash',
				array(),
				'leitvia/get-content-model',
				'once',
				array( 'current_hash' => $current )
			);
		}
		$batch = array();
		foreach ( $values as $key => $value ) {
			$key    = (string) $key;
			$option = Registrar::SETTING_PREFIX . $key;
			$target = array(
				'option'  => $option,
				'setting' => $key,
			);
			$field  = $settings[ $key ] ?? null;
			if ( ! is_array( $field ) || empty( $field['bindable'] ) ) {
				$bindable = array_map( 'strval', array_keys( self::site_settings() ) );
				return Block_Edit::invalid(
					'setting',
					$bindable
						/* translators: %s: list of setting keys */
						? sprintf( __( 'The content model has no bindable site setting with this key. Use one of these: %s.', 'leitvia-studio' ), implode( ', ', $bindable ) )
						: __( 'The content model has no bindable site setting. Declare one with bindable true through leitvia/define-content-type first.', 'leitvia-studio' ),
					$target,
					'leitvia/get-content-model'
				);
			}
			$schema = Registrar::schema_for( $field )[1];
			$valid  = rest_validate_value_from_schema( $value, $schema, $key );
			if ( is_wp_error( $valid ) ) {
				return Block_Edit::invalid( 'value', $valid->get_error_message(), $target, 'leitvia/get-content-model' );
			}
			$value = rest_sanitize_value_from_schema( $value, $schema, $key );
			if ( 'image' === $field['type'] && $value && ( ! wp_attachment_is_image( (int) $value ) || ! Scope::readable( (int) $value ) ) ) {
				return Block_Edit::invalid( 'value', __( 'The ID is no image of the media library.', 'leitvia-studio' ), $target, 'leitvia/list-media' );
			}
			$batch[] = array(
				'area'      => 'structure',
				'target'    => array( 'option' => $option ),
				'base_hash' => Hashes::of( (string) wp_json_encode( get_option( $option ) ) ),
				'change'    => static fn(): array => array( 'value' => $value ),
			);
		}
		return array( 'batch' => $batch );
	}

	/**
	 * A stored entry of the content model with the entry of the input over it. Post types and taxonomies merge at field
	 * level, so a declaration that is added again (a recipe or building block) keeps what a person added: the input sets
	 * its own keys and fields, every field, repeater and supports value it does not name stays, and the postTypes of a
	 * taxonomy are joined. Relations and settings are replaced whole, as they have no parts of their own.
	 *
	 * @param string               $section Section of the content model.
	 * @param array<string, mixed> $kept    Stored entry.
	 * @param array<string, mixed> $entry   Entry of the input.
	 * @return array<string, mixed>
	 */
	public static function merge_entry( string $section, array $kept, array $entry ): array {
		if ( ! in_array( $section, array( 'postTypes', 'taxonomies' ), true ) ) {
			return $entry;
		}
		$merged = $entry + $kept;
		$join   = static fn( string $key ): array => array_values( array_unique( array_merge( (array) ( $kept[ $key ] ?? array() ), (array) ( $entry[ $key ] ?? array() ) ) ) );
		if ( 'taxonomies' === $section ) {
			$merged['postTypes'] = $join( 'postTypes' );
			return $merged;
		}
		if ( isset( $kept['supports'], $entry['supports'] ) ) {
			$merged['supports'] = $join( 'supports' );
		}
		if ( isset( $kept['fields'] ) || isset( $entry['fields'] ) ) {
			$merged['fields'] = array_merge( (array) ( $kept['fields'] ?? array() ), (array) ( $entry['fields'] ?? array() ) );
		}
		if ( isset( $kept['repeaters'] ) || isset( $entry['repeaters'] ) ) {
			$repeaters = (array) ( $kept['repeaters'] ?? array() );
			foreach ( (array) ( $entry['repeaters'] ?? array() ) as $key => $repeater ) {
				$old               = (array) ( $repeaters[ $key ] ?? array() );
				$repeaters[ $key ] = (array) $repeater + $old;
				if ( isset( $old['fields'] ) || isset( $repeater['fields'] ) ) {
					$repeaters[ $key ]['fields'] = array_merge( (array) ( $old['fields'] ?? array() ), (array) ( $repeater['fields'] ?? array() ) );
				}
			}
			$merged['repeaters'] = $repeaters;
		}
		return $merged;
	}

	/**
	 * Operation of update-navigation.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_navigation( array $input ) {
		if ( isset( $input['items'] ) === isset( $input['content'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either items or content.', 'leitvia-studio' ) );
		}
		$id = (int) ( $input['navigation'] ?? 0 );
		if ( $id && 'wp_navigation' !== get_post_type( $id ) ) {
			return Block_Edit::invalid( 'navigation', __( 'There is no navigation menu with this ID.', 'leitvia-studio' ), array( 'post' => $id ) );
		}
		if ( ! $id ) {
			$latest = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$id     = (int) ( $latest[0] ?? 0 );
		}
		$content = isset( $input['content'] ) ? (string) $input['content'] : serialize_blocks( self::links( (array) $input['items'] ) );
		$target  = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_navigation',
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			'change' => static function ( array $state ) use ( $content, $target ) {
				$findings = self::navigation_findings( parse_blocks( $content ), 'p:' );
				if ( $findings ) {
					return self::check_failed( $target, $findings, 'leitvia/list-blocks' );
				}
				$new = $state['post_title'] ? array() : array( 'post_title' => __( 'Navigation', 'leitvia-studio' ) );
				// Core renders only a published menu: a draft one (from an earlier run) is published with the change.
				return array(
					'post_content' => $content,
					'post_status'  => 'publish',
				) + $new + $state;
			},
		);
	}

	/**
	 * Operation of update-template-part.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_template_part( array $input ) {
		$name  = (string) ( $input['part'] ?? '' );
		$area  = null;
		$title = '';
		// With Polylang the theme shows <part>-<language> (header-de) in place of <part> while that language is shown.
		$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		foreach ( (array) ( leitvia_studio_contract()->data()['templateParts'] ?? array() ) as $part ) {
			$base = (string) ( $part['name'] ?? '' );
			if ( $base === $name || ( str_starts_with( $name, $base . '-' ) && in_array( substr( $name, strlen( $base ) + 1 ), $languages, true ) ) ) {
				$area  = (string) ( $part['area'] ?? 'uncategorized' );
				$title = $base === $name ? '' : ucfirst( $base ) . ' (' . substr( $name, strlen( $base ) + 1 ) . ')';
			}
			if ( $base === $name ) {
				break;
			}
		}
		if ( null === $area ) {
			return Block_Edit::invalid( 'part', __( 'The contract has no template part with this name.', 'leitvia-studio' ), array( 'part' => $name ), 'leitvia/get-contract' );
		}
		if ( isset( $input['pattern'] ) === isset( $input['content'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either a pattern or content.', 'leitvia-studio' ), array( 'part' => $name ) );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( isset( $input['pattern'] ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $input['pattern'] );
			$fits    = $pattern && ( in_array( 'core/template-part/' . $area, (array) ( $pattern['blockTypes'] ?? array() ), true ) || array_intersect( array( $name, $area ), (array) ( $pattern['categories'] ?? array() ) ) );
			if ( ! $fits ) {
				return Rejection::make( 'leitvia_unknown_pattern', __( 'There is no pattern with this name for this template part. List the patterns of its area and use one of them.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => (string) $input['pattern'] ), 'leitvia/list-patterns' );
			}
			$content = (string) $pattern['content'];
			if ( ! empty( $input['edits'] ) ) {
				$content = Build::edit( $content, (array) $input['edits'], array( 'part' => $name ) );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
			}
		} elseif ( ! empty( $input['edits'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Edits change a pattern: send them with pattern, or send the changed markup as content.', 'leitvia-studio' ), array( 'part' => $name ) );
		}
		$template = get_block_template( get_stylesheet() . '//' . $name, 'wp_template_part' );
		if ( isset( $input['content'] ) && $template ) {
			$content = self::keep_navigation( $content, (string) $template->content );
		}
		$id     = $template && $template->wp_id ? (int) $template->wp_id : 0;
		$target = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_template_part',
		);
		// A part the theme ships as a file only gets a post with the terms core finds it by.
		$new = $id ? array() : array(
			'post_title' => $template ? (string) $template->title : ( '' !== $title ? $title : $name ),
			'post_name'  => $name,
			'tax_input'  => array(
				'wp_theme'              => array( get_stylesheet() ),
				'wp_template_part_area' => array( $area ),
			),
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			// Core renders only a published part: a draft one (from an earlier run) is published with the change.
			'change' => static fn( array $state ): array => array(
				'post_content' => $content,
				'post_status'  => 'publish',
			) + $new + $state,
		);
	}

	/**
	 * Content of a template part with the settings of its navigation kept: a core/navigation in the new content gets
	 * each of NAVIGATION_KEEP that the first navigation of the part had and it lacks (agents often send only the ref or
	 * the links, and the menu would lose its overlay, its submenus on click and its name). A value it sets stays.
	 * Patterns in the part are read through, since the theme ships its header as a pattern.
	 *
	 * @param string $content New content.
	 * @param string $old     Current content of the part.
	 */
	public static function keep_navigation( string $content, string $old ): string {
		$keep = array_intersect_key( self::first_navigation( parse_blocks( $old ) ), array_flip( self::NAVIGATION_KEEP ) );
		if ( ! $keep || ! str_contains( $content, 'wp:navigation' ) ) {
			return $content;
		}
		$blocks = parse_blocks( $content );
		// Serializing would drop attributes that are no valid JSON; the pipeline rejects such markup as sent.
		if ( Pipeline::broken_markup( $blocks ) ) {
			return $content;
		}
		$changed = false;
		$walk    = static function ( array $blocks ) use ( &$walk, $keep, &$changed ): array {
			foreach ( $blocks as &$block ) {
				if ( 'core/navigation' === $block['blockName'] && array_diff_key( $keep, (array) $block['attrs'] ) ) {
					$block['attrs'] = (array) $block['attrs'] + $keep;
					$changed        = true;
				}
				$block['innerBlocks'] = $walk( (array) $block['innerBlocks'] );
			}
			return $blocks;
		};
		$blocks  = $walk( $blocks );
		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * Attributes of the first core/navigation of blocks, core/pattern blocks read through; empty without one.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param int                                     $depth  Patterns read through so far.
	 * @return array<string, mixed>
	 */
	private static function first_navigation( array $blocks, int $depth = 0 ): array {
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === $block['blockName'] ) {
				return (array) $block['attrs'];
			}
			$inner = (array) $block['innerBlocks'];
			if ( 'core/pattern' === $block['blockName'] && $depth < 3 ) {
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $block['attrs']['slug'] ?? '' ) );
				$inner   = $pattern ? parse_blocks( (string) $pattern['content'] ) : array();
			}
			$found = self::first_navigation( $inner, $depth + ( 'core/pattern' === $block['blockName'] ? 1 : 0 ) );
			if ( $found ) {
				return $found;
			}
		}
		return array();
	}

	/**
	 * Operation of update-template. Like update-template-part for wp_template: slugs of the contract (shop templates
	 * only while their plugins are active) and of the templates the theme or a plugin registers, patterns whose
	 * templateTypes hold the slug.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_template( array $input ) {
		$slug     = (string) ( $input['template'] ?? '' );
		$contract = array_column( (array) ( leitvia_studio_contract()->data()['templates'] ?? array() ), null, 'slug' );
		$known    = isset( $contract[ $slug ] ) ? '' === Recipes::missing( (array) $contract[ $slug ] ) : in_array( $slug, wp_list_pluck( get_block_templates(), 'slug' ), true );
		// The templates of product categories and tags are in the hierarchy of WooCommerce, which falls back to
		// archive-product; WooCommerce may not list them before one is saved.
		$known = $known || ( in_array( $slug, self::SHOP_TERM_TEMPLATES, true ) && '' === Recipes::missing( array( 'requires' => array( 'woocommerce' ) ) ) );
		if ( ! $known ) {
			return Block_Edit::invalid( 'template', __( 'Use a template of the contract or of the theme. WooCommerce templates need WooCommerce.', 'leitvia-studio' ), array( 'template' => $slug ), 'leitvia/get-contract' );
		}
		if ( isset( $input['pattern'] ) === isset( $input['content'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either a pattern or content.', 'leitvia-studio' ), array( 'template' => $slug ) );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( isset( $input['pattern'] ) ) {
			$registry = \WP_Block_Patterns_Registry::get_instance();
			$fits     = static fn( $pattern ): bool => is_array( $pattern ) && in_array( $slug, (array) ( $pattern['templateTypes'] ?? array() ), true );
			$pattern  = $registry->get_registered( (string) $input['pattern'] );
			if ( ! is_array( $pattern ) || ! $fits( $pattern ) ) {
				// leitvia/list-patterns lists the patterns of the contract, template patterns of add-ons are not among them.
				$names = array_values( wp_list_pluck( array_filter( $registry->get_all_registered(), $fits ), 'name' ) );
				return Rejection::make( 'leitvia_unknown_pattern', __( 'There is no pattern with this name for this template. Use one of the patterns the rejection lists in patterns.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => (string) $input['pattern'] ), '', 'once', array( 'patterns' => $names ) );
			}
			$content = (string) $pattern['content'];
		}
		// A template for single posts and pages that drops the content block shows every post without its text.
		$file = get_block_file_template( get_stylesheet() . '//' . $slug );
		$show = preg_match( '/^(page|single)(-|$)/', $slug ) && ( ! $file || self::shows_content( parse_blocks( (string) $file->content ) ) );
		if ( $show && ! self::shows_content( parse_blocks( $content ) ) ) {
			return Block_Edit::invalid( 'post_content', __( 'This template shows a single post or page: keep a core/post-content block in it, also inside a group or pattern.', 'leitvia-studio' ), array( 'template' => $slug ) );
		}
		$trashed = get_posts(
			array(
				'post_type'      => 'wp_template',
				'post_status'    => 'trash',
				// Core adds __trashed to the slug of a post it moves to the trash.
				'post_name__in'  => array( $slug, $slug . '__trashed' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One template.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
		if ( $trashed ) {
			return Block_Edit::invalid( 'trashed', __( 'The saved version of this template is in the trash. A person restores it or empties the trash in the Site Editor first.', 'leitvia-studio' ), array( 'template' => $slug ) );
		}
		$template = get_block_template( get_stylesheet() . '//' . $slug );
		$id       = $template && $template->wp_id ? (int) $template->wp_id : 0;
		$target   = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_template',
		);
		// A template the theme ships as a file only gets a post with the term core finds it by.
		$new = $id ? array() : array(
			'post_title' => $template ? (string) $template->title : $slug,
			'post_name'  => $slug,
			'tax_input'  => array( 'wp_theme' => array( get_stylesheet() ) ),
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			// A live write gets a package too, so leitvia/rollback undoes it and the template of the theme comes back.
			'record' => true,
			'change' => static fn( array $state ): array => array(
				'post_content' => $content,
				'post_status'  => 'publish',
			) + $new + $state,
		);
	}

	/**
	 * Whether blocks hold core/post-content, also inside other blocks and registered patterns (core/pattern).
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param int          $depth  Patterns resolved so far, against patterns that include themselves.
	 */
	private static function shows_content( array $blocks, int $depth = 0 ): bool {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( 'core/post-content' === $name || self::shows_content( (array) ( $block['innerBlocks'] ?? array() ), $depth ) ) {
				return true;
			}
			$pattern = 'core/pattern' === $name && $depth < 5 ? \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $block['attrs']['slug'] ?? '' ) ) : null;
			if ( is_array( $pattern ) && self::shows_content( parse_blocks( (string) ( $pattern['content'] ?? '' ) ), $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Operation on the user global styles of the active theme, a new post when there is none yet.
	 *
	 * @param array<string, mixed> $target Further target keys, token_set for an activation.
	 * @param callable             $build  Gets the current data, returns the new data or a WP_Error.
	 * @return array<string, mixed>
	 */
	private static function styles( array $target, callable $build ): array {
		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$id   = (int) ( $post['ID'] ?? 0 );
		$new  = $id ? array() : array(
			'post_title'  => 'Custom Styles',
			'post_name'   => 'wp-global-styles-' . rawurlencode( get_stylesheet() ),
			'post_status' => 'publish',
			'tax_input'   => array( 'wp_theme' => array( get_stylesheet() ) ),
		);
		return array(
			'area'   => 'design',
			'target' => ( $id ? array( 'post' => $id ) : array(
				'post'      => 0,
				'post_type' => 'wp_global_styles',
			) ) + $target,
			'change' => static function ( array $state ) use ( $build, $new ) {
				$current = json_decode( (string) $state['post_content'], true );
				$data    = $build( is_array( $current ) ? $current : array() );
				return is_wp_error( $data ) ? $data : array( 'post_content' => (string) wp_json_encode( $data ) ) + $new + $state;
			},
		);
	}

	/**
	 * The data with each section of the partial variation laid over it; lists such as palettes are replaced whole.
	 *
	 * @param array<mixed> $data    Current data.
	 * @param array<mixed> $partial Variation data.
	 * @return array<mixed>
	 */
	private static function merge( array $data, array $partial ): array {
		foreach ( $partial as $key => $value ) {
			$data[ $key ] = is_array( $value ) && ! array_is_list( $value ) && is_array( $data[ $key ] ?? null ) ? self::merge( $data[ $key ], $value ) : $value;
		}
		return $data;
	}

	/**
	 * Navigation link blocks of items.
	 *
	 * @param array<mixed> $items Items with label, url and sub items.
	 * @return list<array{blockName: string, attrs: array<string, string>, innerBlocks: list<array<string, mixed>>, innerHTML: string, innerContent: list<null>}>
	 */
	private static function links( array $items ): array {
		$blocks = array();
		foreach ( $items as $item ) {
			$inner    = self::links( (array) ( $item['items'] ?? array() ) );
			$blocks[] = array(
				'blockName'    => $inner ? 'core/navigation-submenu' : 'core/navigation-link',
				'attrs'        => array_filter(
					array(
						'label' => (string) $item['label'],
						'url'   => (string) ( $item['url'] ?? '' ),
						'kind'  => 'custom',
					),
					static fn( string $value ): bool => '' !== $value
				),
				'innerBlocks'  => $inner,
				'innerHTML'    => '',
				'innerContent' => $inner ? array_fill( 0, count( $inner ), null ) : array(),
			);
		}
		return $blocks;
	}

	/**
	 * Findings for navigation markup: only NAVIGATION_BLOCKS at the navigation level and in submenus (a mega menu holds
	 * what it likes), link targets on this site, https, mailto: or tel:, and the link text check of the content checks.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                                  $path   Path prefix.
	 * @return list<array<string, mixed>>
	 */
	private static function navigation_findings( array $blocks, string $path ): array {
		$findings = array();
		$index    = 0;
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			$id = (string) ( $block['attrs']['metadata']['leitviaId'] ?? $path . $index );
			++$index;
			if ( ! in_array( $name, self::NAVIGATION_BLOCKS, true ) ) {
				$findings[] = self::finding( $id, __( 'A navigation holds only navigation links, submenus, page lists and mega menus.', 'leitvia-studio' ), __( 'Remove the block or turn it into a navigation link.', 'leitvia-studio' ) );
				continue;
			}
			$url = (string) ( $block['attrs']['url'] ?? '' );
			// A submenu without an address is a button that opens its links, as for a group like Specialities.
			if ( 'core/navigation-link' === $name || ( 'core/navigation-submenu' === $name && '' !== $url ) ) {
				if ( ! self::link_target( $url ) ) {
					$findings[] = self::finding( $id, __( 'The link goes to another site without https, or to no page.', 'leitvia-studio' ), __( 'Link to a page of this site, an https address, an email address (mailto:) or a phone number (tel:).', 'leitvia-studio' ) );
				}
				$link = '<!-- wp:paragraph --><p><a href="' . esc_url( $url ) . '">' . esc_html( wp_strip_all_tags( (string) ( $block['attrs']['label'] ?? '' ) ) ) . '</a></p><!-- /wp:paragraph -->';
				foreach ( Content_Checks::link_text( $link, array( 'post_type' => 'page' ) ) as $finding ) {
					$findings[] = array( 'block' => $id ) + $finding;
				}
			}
			if ( 'core/navigation-submenu' === $name ) {
				array_push( $findings, ...self::navigation_findings( (array) $block['innerBlocks'], $path . ( $index - 1 ) . '.' ) );
			}
		}
		return $findings;
	}

	/**
	 * Whether a link target is on this site (a path or the own host), uses https, or is an email address (mailto:) or a
	 * phone number (tel:). A backslash never: browsers read /\host as another host.
	 *
	 * @param string $url URL.
	 */
	private static function link_target( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( str_contains( $url, '\\' ) ) {
			return false;
		}
		if ( 'mailto' === $scheme ) {
			return (bool) is_email( rawurldecode( explode( '?', substr( $url, 7 ) )[0] ) );
		}
		if ( 'tel' === $scheme ) {
			return (bool) preg_match( '#^\+?[0-9][0-9 ().\-/]{2,30}$#D', rawurldecode( substr( $url, 4 ) ) );
		}
		if ( ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) || '#' . \LeitviaStudio\Consent\Consent_Link::ID === $url ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		return 'https' === $scheme || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host;
	}

	/**
	 * A navigation finding.
	 *
	 * @param string $block   Block ID.
	 * @param string $message Message.
	 * @param string $fix     Fix.
	 * @return array<string, string>
	 */
	private static function finding( string $block, string $message, string $fix ): array {
		return array(
			'check'    => 'leitvia/navigation',
			'severity' => 'error',
			'block'    => $block,
			'message'  => $message,
			'fix'      => $fix,
		);
	}

	/**
	 * Rejection leitvia_check_failed for the findings of a token set, each with a fix; contrast findings name the
	 * colors to change and the ratio the pair needs.
	 *
	 * @param \WP_Error $error Error of Token_Sets::validate().
	 */
	private static function token_findings( \WP_Error $error ): \WP_Error {
		$findings = array();
		foreach ( (array) ( $error->get_error_data()['findings'] ?? array() ) as $finding ) {
			$data       = (array) ( $finding['data'] ?? array() );
			$findings[] = $finding + array(
				'fix' => 'contrast' === ( $finding['code'] ?? '' )
					/* translators: 1: foreground role, 2: background role, 3: required ratio. */
					? sprintf( __( 'Darken or lighten %1$s or %2$s until the pair reaches %3$s:1.', 'leitvia-studio' ), (string) ( $data['fg'] ?? '' ), (string) ( $data['bg'] ?? '' ), (string) ( $data['min'] ?? '' ) )
					: __( 'Correct the token document as the message says.', 'leitvia-studio' ),
			);
		}
		return self::check_failed( array(), $findings, 'leitvia/get-tokens' );
	}

	/**
	 * Rejection leitvia_check_failed in the format of the pipeline.
	 *
	 * @param array<string, mixed>       $target       Target.
	 * @param list<array<string, mixed>> $findings     Findings.
	 * @param string                     $next_ability Next ability.
	 */
	private static function check_failed( array $target, array $findings, string $next_ability ): \WP_Error {
		return Rejection::make( 'leitvia_check_failed', __( 'Checks found problems. Fix them as each finding suggests and try again.', 'leitvia-studio' ), 422, 'checks', $target, $next_ability, 'once', array( 'findings' => $findings ) );
	}

	/**
	 * Whether a key occurs anywhere in nested arrays.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Key.
	 */
	private static function has_key( array $data, string $key ): bool {
		foreach ( $data as $name => $value ) {
			if ( $key === $name || ( is_array( $value ) && self::has_key( $value, $key ) ) ) {
				return true;
			}
		}
		return false;
	}
}
