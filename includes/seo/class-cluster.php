<?php
/**
 * Topic clusters of pages: the hub a page belongs to, its breadcrumbs and the service it offers.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Seo;

use LeitviaStudio\News\Schema;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses of a site often stay flat (/website-care/, /hosting/), so a cluster of pages cannot come from paths or page
 * parents. A page names its hub in the meta HUB_META instead (leitvia/set-page-structure): the overview page of its
 * topic. The breadcrumbs block shows Home, the hubs and the page, and the BreadcrumbList of Schema names the same trail.
 * A page with a parent keeps the trail of its parents, which core builds; the hub only counts for pages without one.
 *
 * A page may also offer a service with a price (SERVICE_META): Schema prints a Service with its provider and an Offer
 * whose UnitPriceSpecification says whether VAT is included. Only what the page states belongs there, the skill asks
 * for it; the price is never invented by the checks.
 *
 * A page may name offerings an add-on keeps (OFFERINGS_META, also leitvia/set-page-structure): a line of offerings by
 * its slug or single offerings by article number. Studio only stores the link; an add-on that keeps offerings with
 * prices gives the page its service from them through the filter leitvia_studio_page_service, so a price lives in one
 * place.
 */
final class Cluster {

	/**
	 * Post meta with the ID of the hub page.
	 */
	public const HUB_META = '_leitvia_hub';

	/**
	 * Post meta with the lead symbol of a page, an icon name (leitvia/set-page-structure icon), read by the binding
	 * source leitvia/page-symbol (Model\Bindings::page_symbol()).
	 */
	public const ICON_META = '_leitvia_page_icon';

	/**
	 * Post meta with the service of the page: name, description, price, currency, vat_included and unit.
	 */
	public const SERVICE_META = '_leitvia_service';

	/**
	 * Post meta with the offerings an add-on keeps that the page presents: line (a slug) and skus (article numbers).
	 */
	public const OFFERINGS_META = '_leitvia_offerings';

	/**
	 * Post meta of a page that shows no breadcrumbs ('1', leitvia/set-page-structure breadcrumbs false), such as a page
	 * whose title section stands right below the header; the BreadcrumbList goes with the block.
	 */
	public const NO_BREADCRUMBS_META = '_leitvia_no_breadcrumbs';

	/**
	 * Article numbers of offerings: a letter or digit, then letters, digits, dots, hyphens and underscores.
	 */
	public const SKU = '^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$';

	/**
	 * Most article numbers a page names.
	 */
	public const MAX_SKUS = 20;

	/**
	 * Most hubs above a page; deeper chains and loops end there.
	 */
	public const MAX_DEPTH = 3;

	/**
	 * Most packages (offers) per service.
	 */
	public const MAX_OFFERS = 10;

	/**
	 * Types of what a page offers (service type of set-page-structure): a Service or a SoftwareApplication.
	 */
	public const SERVICE_TYPES = array( 'service', 'software' );

	/**
	 * Fields of a service of the type software.
	 */
	private const SOFTWARE_FIELDS = array( 'application_category', 'operating_system', 'download_url' );

	/**
	 * The hubs above a page, the closest last: its hub, the hub of that one and so on, published pages only, without
	 * the page itself, the front page and repeats. The hub a page names comes before its parent page: a page below an
	 * overview page that names another hub shows that hub. Above the first hub a hub without a hub of its own continues
	 * with its parent page. Empty for a page without a hub.
	 *
	 * @param int $page Page ID.
	 * @return list<int>
	 */
	public static function hubs( int $page ): array {
		if ( ! $page ) {
			return array();
		}
		$chain = array();
		$seen  = array( $page, (int) get_option( 'page_on_front' ) );
		$hub   = (int) get_post_meta( $page, self::HUB_META, true );
		for ( $depth = 0; $hub && $depth < self::MAX_DEPTH; $depth++ ) {
			if ( in_array( $hub, $seen, true ) || 'page' !== get_post_type( $hub ) || 'publish' !== get_post_status( $hub ) ) {
				break;
			}
			array_unshift( $chain, $hub );
			$seen[] = $hub;
			$next   = (int) get_post_meta( $hub, self::HUB_META, true );
			$hub    = $next ? $next : (int) wp_get_post_parent_id( $hub );
		}
		return $chain;
	}

	/**
	 * Whether a hub for a page would close a loop: the hub is the page or has the page among its own hubs.
	 *
	 * @param int $page Page ID.
	 * @param int $hub  ID of the hub page.
	 */
	public static function loops( int $page, int $hub ): bool {
		for ( $depth = 0; $hub && $depth <= self::MAX_DEPTH; $depth++ ) {
			if ( $hub === $page ) {
				return true;
			}
			$hub = (int) get_post_meta( $hub, self::HUB_META, true );
		}
		return false;
	}

	/**
	 * Renders no breadcrumbs block on a page that hides them (NO_BREADCRUMBS_META): the block of the template stays,
	 * every other page keeps its trail, and without the block no BreadcrumbList is printed either. Runs on
	 * pre_render_block.
	 *
	 * @param string|null          $pre   Markup an earlier filter returned, null to render the block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null
	 */
	public static function hide_breadcrumbs( $pre, array $block ) {
		if ( null !== $pre || 'core/breadcrumbs' !== ( $block['blockName'] ?? '' ) || ! is_singular() ) {
			return $pre;
		}
		return '1' === get_post_meta( (int) get_queried_object_id(), self::NO_BREADCRUMBS_META, true ) ? '' : null;
	}

	/**
	 * Puts the hubs of the current page between Home and the page in the breadcrumbs block, in place of its parent
	 * pages: the hub of the page wins over the hierarchy. Runs on block_core_breadcrumbs_items.
	 *
	 * @param mixed $items Breadcrumb items, each with label and, except for the current page, url.
	 * @return mixed
	 */
	public static function breadcrumbs( $items ) {
		$hubs = is_page() ? self::hubs( (int) get_queried_object_id() ) : array();
		if ( ! $hubs || ! is_array( $items ) || ! $items ) {
			return $items;
		}
		$crumbs = array_map(
			static fn( int $hub ): array => array(
				'label' => get_the_title( $hub ),
				'url'   => (string) get_permalink( $hub ),
			),
			$hubs
		);
		return array_merge( array( $items[0] ), $crumbs, count( $items ) > 1 ? array( end( $items ) ) : array() );
	}

	/**
	 * BreadcrumbList of a page: Home, its hubs or else its parents, the page. Schema prints it on pages, unless the
	 * breadcrumbs block of the page gave one.
	 *
	 * @param WP_Post $page Page.
	 * @return array<string, mixed>
	 */
	public static function trail( WP_Post $page ): array {
		$hubs  = self::hubs( $page->ID );
		$ids   = array_merge( $hubs ? $hubs : array_reverse( get_post_ancestors( $page ) ), array( $page->ID ) );
		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => Schema::text( get_bloginfo( 'name' ) ),
				'item'     => home_url( '/' ),
			),
		);
		foreach ( $ids as $id ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => count( $items ) + 1,
				'name'     => Schema::text( get_the_title( $id ) ),
				'item'     => (string) get_permalink( $id ),
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * The service of a page from SERVICE_META, cleaned: name, description, price as a number, max_price for a range,
	 * currency (EUR unless set), vat_included, unit, setup_fee (a one-off fee above 0, else null), compare_price (a
	 * higher price it stands against, such as its parts bought one by one, else null) and offers, the packages with
	 * name, price, description, unit, setup_fee and compare_price; null without a name or with a broken value. With
	 * packages and no price, price is the lowest package price; a package has max_price when its price is a range. Goes through the filter leitvia_studio_page_service.
	 *
	 * @param int $page Page ID.
	 * @return array{type: string, application_category: string, operating_system: string, download_url: string, name: string, description: string, price: float|null, max_price: float|null, price_from: bool, currency: string, vat_included: bool, unit: string, setup_fee: float|null, compare_price: float|null, offers: list<array{name: string, price: float, max_price: float|null, price_from: bool, addon: bool, description: string, unit: string, setup_fee: float|null, compare_price: float|null}>}|null
	 */
	public static function service( int $page ): ?array {
		$stored = self::clean_service( get_post_meta( $page, self::SERVICE_META, true ) );
		/**
		 * Filters the service of a page or entry, for example to take its packages and prices from offerings an add-on
		 * keeps (see OFFERINGS_META), so the price lives in one place. Return the shape of Cluster::service() or null for
		 * none; it is cleaned as a stored service is, and Schema, llms.txt and leitvia/get-seo use it.
		 *
		 * @param array<string, mixed>|null $stored  The service stored with leitvia/set-page-structure, or null.
		 * @param int                       $page    Post ID.
		 */
		$service = apply_filters( 'leitvia_studio_page_service', $stored, $page );
		if ( $service === $stored ) {
			return $stored;
		}
		// Prices from an add-on keep the type and the software fields stored with the page.
		$kept = $stored ? array_intersect_key( $stored, array_flip( array_merge( array( 'type' ), self::SOFTWARE_FIELDS ) ) ) : array();
		return self::clean_service( is_array( $service ) ? array_filter( $service, static fn( $value ): bool => '' !== $value ) + $kept : $service );
	}

	/**
	 * The offerings a page names in OFFERINGS_META: line, a slug or '', and skus, article numbers.
	 *
	 * @param int $page Page ID.
	 * @return array{line: string, skus: list<string>}
	 */
	public static function offerings( int $page ): array {
		$stored = get_post_meta( $page, self::OFFERINGS_META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$skus   = array_values( array_filter( array_map( 'strval', (array) ( $stored['skus'] ?? array() ) ), static fn( string $sku ): bool => (bool) preg_match( '/' . self::SKU . '/', $sku ) ) );
		return array(
			'line' => sanitize_title( (string) ( $stored['line'] ?? '' ) ),
			'skus' => array_slice( $skus, 0, self::MAX_SKUS ),
		);
	}

	/**
	 * A service as SERVICE_META stores it, cleaned, see service(); null without a name.
	 *
	 * @param mixed $service Stored service.
	 * @return array{type: string, application_category: string, operating_system: string, download_url: string, name: string, description: string, price: float|null, max_price: float|null, price_from: bool, currency: string, vat_included: bool, unit: string, setup_fee: float|null, compare_price: float|null, offers: list<array{name: string, price: float, max_price: float|null, price_from: bool, addon: bool, description: string, unit: string, setup_fee: float|null, compare_price: float|null}>}|null
	 */
	private static function clean_service( $service ): ?array {
		$service = is_array( $service ) ? $service : array();
		$name    = trim( (string) ( $service['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$number = static fn( $value ): ?float => is_numeric( $value ) && (float) $value >= 0 ? (float) $value : null;
		$fee    = static fn( $value ): ?float => is_numeric( $value ) && (float) $value > 0 ? (float) $value : null;
		$offers = array();
		foreach ( array_slice( (array) ( $service['offers'] ?? array() ), 0, self::MAX_OFFERS ) as $offer ) {
			$offer = (array) $offer;
			$label = trim( (string) ( $offer['name'] ?? '' ) );
			$cost  = $number( $offer['price'] ?? null );
			if ( '' !== $label && null !== $cost ) {
				$top      = $number( $offer['max_price'] ?? null );
				$offers[] = array(
					'name'          => $label,
					'price'         => $cost,
					'max_price'     => null !== $top && $top > $cost ? $top : null,
					'price_from'    => ! empty( $offer['price_from'] ),
					'addon'         => ! empty( $offer['addon'] ),
					'description'   => trim( (string) ( $offer['description'] ?? '' ) ),
					'unit'          => trim( (string) ( $offer['unit'] ?? '' ) ),
					'setup_fee'     => $fee( $offer['setup_fee'] ?? null ),
					'compare_price' => $fee( $offer['compare_price'] ?? null ),
				);
			}
		}
		$price = $number( $service['price'] ?? null );
		$price = null === $price && $offers ? min( array_column( $offers, 'price' ) ) : $price;
		$max   = $number( $service['max_price'] ?? null );
		return array(
			'type'                 => 'software' === ( $service['type'] ?? '' ) ? 'software' : 'service',
			'application_category' => trim( (string) ( $service['application_category'] ?? '' ) ),
			'operating_system'     => trim( (string) ( $service['operating_system'] ?? '' ) ),
			'download_url'         => str_starts_with( (string) ( $service['download_url'] ?? '' ), 'https://' ) ? (string) $service['download_url'] : '',
			'name'                 => $name,
			'description'          => trim( (string) ( $service['description'] ?? '' ) ),
			'price'                => $price,
			'max_price'            => null !== $price && null !== $max && $max > $price ? $max : null,
			'price_from'           => ! empty( $service['price_from'] ),
			'currency'             => preg_match( '/^[A-Z]{3}$/', (string) ( $service['currency'] ?? '' ) ) ? (string) $service['currency'] : 'EUR',
			'vat_included'         => ! empty( $service['vat_included'] ),
			'unit'                 => trim( (string) ( $service['unit'] ?? '' ) ),
			'setup_fee'            => $fee( $service['setup_fee'] ?? null ),
			'compare_price'        => $fee( $service['compare_price'] ?? null ),
			'offers'               => $offers,
		);
	}

	/**
	 * Service node of a page with its provider (the organization) and, with a price, an Offer and a
	 * UnitPriceSpecification with valueAddedTaxIncluded; with packages one Offer per package inside an AggregateOffer
	 * with the lowest and highest price, with max_price an AggregateOffer for the range. highPrice is the top of every
	 * price and range, of the service and of its packages; a package with a range carries minPrice and maxPrice in its
	 * price specification, and so does a price from (price_from) with minPrice alone. An offer only sold with another
	 * (addon) is an Offer of its own but leaves lowPrice and highPrice to the others. A setup fee (setup_fee) adds a
	 * second UnitPriceSpecification with priceComponentType ActivationFee, a comparison price (compare_price) one with
	 * priceType StrikethroughPrice. A service of the type software is a SoftwareApplication with the organization as
	 * publisher, applicationCategory, operatingSystem and downloadUrl. null for a page without a service.
	 *
	 * @param WP_Post              $page         Page.
	 * @param array<string, mixed> $organization Organization node.
	 * @return array<string, mixed>|null
	 */
	public static function service_node( WP_Post $page, array $organization ): ?array {
		$service = self::service( $page->ID );
		if ( ! $service ) {
			return null;
		}
		$url      = (string) get_permalink( $page );
		$software = 'software' === $service['type'];
		// Software has a publisher, a service a provider.
		$node = array(
			'@type'                              => $software ? 'SoftwareApplication' : 'Service',
			'name'                               => $service['name'],
			'url'                                => $url,
			$software ? 'publisher' : 'provider' => $organization,
		) + array_filter(
			array(
				'description'         => $service['description'],
				'applicationCategory' => $software ? $service['application_category'] : '',
				'operatingSystem'     => $software ? $service['operating_system'] : '',
				'downloadUrl'         => $software ? $service['download_url'] : '',
			)
		);
		$spec = static fn( float $price, array $extra ): array => array(
			'@type'                 => 'UnitPriceSpecification',
			'price'                 => $price,
			'priceCurrency'         => $service['currency'],
			'valueAddedTaxIncluded' => $service['vat_included'],
		) + $extra;
		// A one-off setup fee is a second price specification of the type ActivationFee beside the price.
		// A comparison price, such as the parts bought one by one, is one of the type StrikethroughPrice.
		$offer = static function ( float $price, string $unit, array $extra = array(), ?float $max = null, bool $from = false, ?float $fee = null, ?float $compare = null ) use ( $service, $url, $spec ): array {
			$main  = $spec(
				$price,
				( null === $max ? array() : array(
					'minPrice' => $price,
					'maxPrice' => $max,
				) ) + ( null === $max && $from ? array( 'minPrice' => $price ) : array() ) + array_filter( array( 'unitText' => $unit ) )
			);
			$specs = array_merge(
				array( $main ),
				null === $fee ? array() : array( $spec( $fee, array( 'priceComponentType' => 'https://schema.org/ActivationFee' ) ) ),
				null === $compare || $compare <= $price ? array() : array( $spec( $compare, array( 'priceType' => 'https://schema.org/StrikethroughPrice' ) + array_filter( array( 'unitText' => $unit ) ) ) )
			);
			return $extra + array(
				'@type'              => 'Offer',
				'price'              => $price,
				'priceCurrency'      => $service['currency'],
				'url'                => $url,
				'priceSpecification' => 1 === count( $specs ) ? $main : $specs,
			);
		};
		if ( $service['offers'] ) {
			$base           = array_values( array_filter( $service['offers'], static fn( array $package ): bool => empty( $package['addon'] ) ) );
			$base           = $base ? $base : $service['offers'];
			$prices         = array_column( $base, 'price' );
			$tops           = array_merge( $prices, array_filter( array_column( $base, 'max_price' ), 'is_float' ), null === $service['max_price'] ? array() : array( $service['max_price'] ) );
			$node['offers'] = array(
				'@type'         => 'AggregateOffer',
				'lowPrice'      => min( $prices ),
				'highPrice'     => max( $tops ),
				'priceCurrency' => $service['currency'],
				'offerCount'    => count( $service['offers'] ),
				'offers'        => array_map(
					static fn( array $package ): array => $offer( $package['price'], '' !== $package['unit'] ? $package['unit'] : $service['unit'], array( 'name' => $package['name'] ) + array_filter( array( 'description' => $package['description'] ) ), $package['max_price'], ! empty( $package['price_from'] ), $package['setup_fee'] ?? null, $package['compare_price'] ?? null ),
					$service['offers']
				),
			);
		} elseif ( null !== $service['max_price'] ) {
			$node['offers'] = array(
				'@type'         => 'AggregateOffer',
				'lowPrice'      => $service['price'],
				'highPrice'     => $service['max_price'],
				'priceCurrency' => $service['currency'],
				'url'           => $url,
			);
		} elseif ( null !== $service['price'] ) {
			$node['offers'] = $offer( $service['price'], $service['unit'], array(), null, ! empty( $service['price_from'] ), $service['setup_fee'] ?? null, $service['compare_price'] ?? null );
		}
		return $node;
	}

	/**
	 * Published pages that are hubs: composed or marked with the page frame hub, in menu order and title order.
	 *
	 * @return list<WP_Post>
	 */
	public static function hub_pages(): array {
		return self::pages(
			array(
				'meta_key'   => \LeitviaStudio\Gateway\Composition::FRAME_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few pages carry a frame.
				'meta_value' => 'hub', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);
	}

	/**
	 * Published pages that offer a service with a price, stored or from the offerings they name.
	 *
	 * @return list<WP_Post>
	 */
	public static function service_pages(): array {
		$query = array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few pages carry a service.
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => self::SERVICE_META ),
				array( 'key' => self::OFFERINGS_META ),
			),
		);
		return array_values( array_filter( self::pages( $query ), static fn( WP_Post $page ): bool => null !== ( self::service( $page->ID )['price'] ?? null ) ) );
	}

	/**
	 * Published pages of a query, at most 50.
	 *
	 * @param array<string, mixed> $query Extra query arguments.
	 * @return list<WP_Post>
	 */
	private static function pages( array $query ): array {
		$posts = get_posts(
			$query + array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 50,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		return array_values( array_filter( $posts, static fn( $post ): bool => $post instanceof WP_Post ) );
	}
}
