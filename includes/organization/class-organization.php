<?php
/**
 * The organization behind the site: kind of business, address and the details search engines read.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the organization details of the Studio settings (organization_*) and builds the schema.org node from them,
 * which News\Schema prints on the front page as a node of its own and in posts as publisher. Add-ons read the same
 * details through leitvia_studio_organization_details().
 */
final class Organization {

	/**
	 * Kinds of business the settings offer: Organization, or LocalBusiness and some of its types on schema.org.
	 */
	public const TYPES = array( 'Organization', 'LocalBusiness', 'HomeAndConstructionBusiness', 'GeneralContractor', 'Electrician', 'Plumber', 'HVACBusiness', 'RoofingContractor', 'HousePainter', 'AutoRepair', 'Restaurant', 'Store', 'HealthAndBeautyBusiness', 'LodgingBusiness' );

	/**
	 * Days of opening hours, as schema.org names them, in the order of the week.
	 */
	public const DAYS = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/**
	 * A time of opening hours, HH:MM from 00:00 to 23:59, as JSON Schema pattern (no delimiters).
	 */
	public const TIME = '^([01]\d|2[0-3]):[0-5]\d$';

	/**
	 * A founding date: YYYY, YYYY-MM or YYYY-MM-DD, or empty, as JSON Schema pattern (no delimiters).
	 */
	public const DATE = '^(\d{4}(-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?)?)?$';

	/**
	 * The first year of the copyright line, YYYY or empty, as JSON Schema pattern (no delimiters).
	 */
	public const YEAR = '^(\d{4})?$';

	/**
	 * Fields of leitvia/set-organization, each stored as the setting organization_<field>.
	 */
	public const FIELDS = array( 'type', 'street', 'postal_code', 'city', 'country', 'phone', 'email', 'founding_date', 'founder', 'copyright_since', 'copyright_holder', 'area_served', 'opening_hours', 'same_as' );

	/**
	 * Settings of the rating of the organization (field aggregate_rating of set-organization), by key of the field.
	 */
	public const RATING_FIELDS = array(
		'value'      => 'organization_rating_value',
		'count'      => 'organization_rating_count',
		'source_url' => 'organization_rating_source',
		'confirmed'  => 'organization_rating_confirmed',
	);

	/**
	 * Most rows of opening hours kept.
	 */
	private const MAX_HOURS = 14;

	/**
	 * The organization from the settings: kind of business (Organization unless the setting names one of TYPES), name
	 * and address of the site, address, phone, email, founding date, founder or owner, the start year and holder of the
	 * copyright line, area served, opening hours, profiles and the rating (rating()). Empty strings and lists where
	 * nothing is set.
	 *
	 * @return array{type: string, name: string, url: string, street: string, postal_code: string, city: string, country: string, phone: string, email: string, founding_date: string, founder: string, copyright_since: string, copyright_holder: string, area_served: list<string>, opening_hours: list<array{days: list<string>, opens: string, closes: string}>, same_as: list<string>, aggregate_rating: array{value: string, count: int, source_url: string, confirmed: bool}|null}
	 */
	public static function details(): array {
		$type    = leitvia_studio_setting( 'organization_type' );
		$text    = static fn( string $field ): string => (string) leitvia_studio_setting( 'organization_' . $field );
		$list    = static fn( string $field ): array => array_values( array_filter( (array) leitvia_studio_setting( 'organization_' . $field ), 'is_string' ) );
		$details = array(
			'type'             => is_string( $type ) && in_array( $type, self::TYPES, true ) ? $type : 'Organization',
			'name'             => \LeitviaStudio\News\Schema::text( get_bloginfo( 'name' ) ),
			'url'              => home_url( '/' ),
			'street'           => $text( 'street' ),
			'postal_code'      => $text( 'postal_code' ),
			'city'             => $text( 'city' ),
			'country'          => $text( 'country' ),
			'phone'            => $text( 'phone' ),
			'email'            => $text( 'email' ),
			'founding_date'    => $text( 'founding_date' ),
			'founder'          => $text( 'founder' ),
			'copyright_since'  => $text( 'copyright_since' ),
			'copyright_holder' => $text( 'copyright_holder' ),
			'area_served'      => $list( 'area_served' ),
			'opening_hours'    => self::hours( leitvia_studio_setting( 'organization_opening_hours' ) ),
			'same_as'          => $list( 'same_as' ),
			'aggregate_rating' => self::rating(),
		);
		return $details;
	}

	/**
	 * The rating of the organization on another site as set with set-organization: value (1 to 5), count of reviews,
	 * the https address where it is shown and whether a person confirmed it; null without a value. Structured data and
	 * the star in an SEO title use it only when confirmed, with a count and a source.
	 *
	 * @return array{value: string, count: int, source_url: string, confirmed: bool}|null
	 */
	public static function rating(): ?array {
		$value = (string) leitvia_studio_setting( self::RATING_FIELDS['value'] );
		return '' === $value ? null : array(
			'value'      => $value,
			'count'      => (int) leitvia_studio_setting( self::RATING_FIELDS['count'] ),
			'source_url' => (string) leitvia_studio_setting( self::RATING_FIELDS['source_url'] ),
			'confirmed'  => (bool) leitvia_studio_setting( self::RATING_FIELDS['confirmed'] ),
		);
	}

	/**
	 * The rating when it may be shown: confirmed, with a count and a source; else null.
	 *
	 * @return array{value: string, count: int, source_url: string, confirmed: bool}|null
	 */
	public static function confirmed_rating(): ?array {
		$rating = self::rating();
		return $rating && $rating['confirmed'] && $rating['count'] > 0 && '' !== $rating['source_url'] ? $rating : null;
	}

	/**
	 * Start year and holder of the copyright line of the theme Leitvia (filter leitvia_copyright of its binding
	 * leitvia/site-meta): the settings copyright_since and copyright_holder fill what the binding of the line leaves
	 * empty, so every footer shows the years and the holder without markup of its own.
	 *
	 * @param mixed $copyright since and holder from the binding arguments.
	 * @return array{since: string, holder: string}
	 */
	public static function copyright( $copyright ): array {
		$copyright = is_array( $copyright ) ? $copyright : array();
		$details   = self::details();
		$pick      = static fn( string $key, string $saved ): string => '' !== (string) ( $copyright[ $key ] ?? '' ) ? (string) $copyright[ $key ] : $saved;
		return array(
			'since'  => $pick( 'since', $details['copyright_since'] ),
			'holder' => $pick( 'holder', $details['copyright_holder'] ),
		);
	}

	/**
	 * The @id of the organization node: the address of the site with #organization.
	 */
	public static function id(): string {
		return home_url( '/#organization' );
	}

	/**
	 * The organization as schema.org node with @id: type, name, address of the site, postal address, phone, email,
	 * founding date, area served and profiles where they are set, a confirmed rating (confirmed_rating()) as
	 * aggregateRating, the logo (logo()), and for a local business the
	 * opening hours and the logo as image. Opening hours stay out of an Organization, which schema.org does not give
	 * them.
	 *
	 * @return array<string, mixed>
	 */
	public static function node(): array {
		$details = self::details();
		$node    = array(
			'@type' => $details['type'],
			'@id'   => self::id(),
			'name'  => $details['name'],
			'url'   => $details['url'],
		);
		$address = array_filter(
			array(
				'streetAddress'   => $details['street'],
				'postalCode'      => $details['postal_code'],
				'addressLocality' => $details['city'],
				'addressCountry'  => $details['country'],
			)
		);
		if ( $address ) {
			$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}
		$node += array_filter(
			array(
				'telephone'    => $details['phone'],
				'email'        => $details['email'],
				'foundingDate' => $details['founding_date'],
				'areaServed'   => $details['area_served'],
				'sameAs'       => $details['same_as'],
			)
		);
		if ( '' !== $details['founder'] ) {
			$node['founder'] = array(
				'@type' => 'Person',
				'name'  => $details['founder'],
			);
		}
		$local = 'Organization' !== $details['type'];
		if ( $local && $details['opening_hours'] ) {
			$node['openingHoursSpecification'] = array_map(
				static fn( array $row ): array => array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $row['days'],
					'opens'     => $row['opens'],
					'closes'    => $row['closes'],
				),
				$details['opening_hours']
			);
		}
		$rating = self::confirmed_rating();
		if ( $rating ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) $rating['value'],
				'bestRating'  => 5,
				'reviewCount' => $rating['count'],
				'url'         => $rating['source_url'],
			);
		}
		$logo = self::logo();
		if ( $logo ) {
			$node['logo'] = $logo;
			if ( $local ) {
				$node['image'] = $logo['url'];
			}
		}
		return $node;
	}

	/**
	 * Logo: the custom logo when that is a raster image of at least News\Schema::LOGO_MIN pixels in both directions,
	 * else the site icon in its 512 px size under the same rule, else none. An SVG has no pixel size and never
	 * qualifies.
	 *
	 * @return array{"@type": string, url: string, width: int, height: int}|null
	 */
	private static function logo(): ?array {
		$min      = \LeitviaStudio\News\Schema::LOGO_MIN;
		$icon_url = get_site_icon_url( 512 );
		$icon     = $icon_url ? wp_get_attachment_image_src( (int) get_option( 'site_icon' ), array( 512, 512 ) ) : false;
		if ( $icon ) {
			$icon[0] = $icon_url;
		}
		foreach ( array( wp_get_attachment_image_src( (int) get_theme_mod( 'custom_logo' ), 'full' ), $icon ) as $logo ) {
			if ( $logo && $logo[1] >= $min && $logo[2] >= $min ) {
				return array(
					'@type'  => 'ImageObject',
					'url'    => $logo[0],
					'width'  => $logo[1],
					'height' => $logo[2],
				);
			}
		}
		return null;
	}

	/**
	 * Valid rows of opening hours: known days in the order of the week, opens and closes as HH:MM. A row without a day
	 * or with a wrong time is dropped, at most MAX_HOURS stay.
	 *
	 * @param mixed $rows Submitted rows.
	 * @return list<array{days: list<string>, opens: string, closes: string}>
	 */
	public static function hours( $rows ): array {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row    = is_array( $row ) ? $row : array();
			$days   = array_values( array_intersect( self::DAYS, array_filter( (array) ( $row['days'] ?? array() ), 'is_string' ) ) );
			$opens  = is_string( $row['opens'] ?? null ) ? $row['opens'] : '';
			$closes = is_string( $row['closes'] ?? null ) ? $row['closes'] : '';
			if ( $days && preg_match( '#' . self::TIME . '#', $opens ) && preg_match( '#' . self::TIME . '#', $closes ) ) {
				$clean[] = array(
					'days'   => $days,
					'opens'  => $opens,
					'closes' => $closes,
				);
			}
		}
		return array_slice( $clean, 0, self::MAX_HOURS );
	}
}
