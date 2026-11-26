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
	public const FIELDS = array( 'type', 'description', 'logo', 'street', 'postal_code', 'city', 'country', 'phone', 'email', 'contact_type', 'founding_date', 'founder', 'copyright_since', 'copyright_holder', 'area_served', 'opening_hours', 'same_as', 'people' );

	/**
	 * Most characters of the description of the organization (field description of set-organization).
	 */
	public const DESCRIPTION_MAX = 500;

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
	 * Most people of the organization (field people of set-organization).
	 */
	public const MAX_PEOPLE = 10;

	/**
	 * Most profiles and most topics of one person.
	 */
	public const MAX_PERSON_ENTRIES = 20;

	/**
	 * The organization from the settings: kind of business (Organization unless the setting names one of TYPES), name
	 * and address of the site, its description, the media ID of the logo for structured data (0 for the site logo), address, phone, email, founding date, founder or owner, the start year and holder of the
	 * copyright line, area served, opening hours, profiles, the people (people()) and the rating (rating()). Empty strings and lists where
	 * nothing is set.
	 *
	 * @return array{type: string, name: string, url: string, description: string, logo: int, street: string, postal_code: string, city: string, country: string, phone: string, email: string, contact_type: string, founding_date: string, founder: string, copyright_since: string, copyright_holder: string, area_served: list<string>, opening_hours: list<array{days: list<string>, opens: string, closes: string}>, same_as: list<string>, people: list<array<string, mixed>>, aggregate_rating: array{value: string, count: int, source_url: string, confirmed: bool}|null}
	 */
	public static function details(): array {
		$type    = leitvia_studio_setting( 'organization_type' );
		$text    = static fn( string $field ): string => (string) leitvia_studio_setting( 'organization_' . $field );
		$list    = static fn( string $field ): array => array_values( array_filter( (array) leitvia_studio_setting( 'organization_' . $field ), 'is_string' ) );
		$details = array(
			'type'             => is_string( $type ) && in_array( $type, self::TYPES, true ) ? $type : 'Organization',
			'name'             => \LeitviaStudio\News\Schema::text( get_bloginfo( 'name' ) ),
			'url'              => home_url( '/' ),
			'description'      => $text( 'description' ),
			'logo'             => (int) leitvia_studio_setting( 'organization_logo' ),
			'street'           => $text( 'street' ),
			'postal_code'      => $text( 'postal_code' ),
			'city'             => $text( 'city' ),
			'country'          => $text( 'country' ),
			'phone'            => $text( 'phone' ),
			'email'            => $text( 'email' ),
			'contact_type'     => '' === $text( 'contact_type' ) ? 'customer service' : $text( 'contact_type' ),
			'founding_date'    => $text( 'founding_date' ),
			'founder'          => $text( 'founder' ),
			'copyright_since'  => $text( 'copyright_since' ),
			'copyright_holder' => $text( 'copyright_holder' ),
			'area_served'      => $list( 'area_served' ),
			'opening_hours'    => self::hours( leitvia_studio_setting( 'organization_opening_hours' ) ),
			'same_as'          => $list( 'same_as' ),
			'people'           => self::people(),
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
	 * The organization as schema.org node with @id: type, name, address of the site, description, postal address, phone, email,
	 * founding date, the founder (person()), area served and profiles where they are set, a confirmed rating (confirmed_rating()) as
	 * aggregateRating, the logo (logo()), and for a local business the
	 * opening hours and the logo as image. Opening hours stay out of an Organization, which schema.org does not give
	 * them; with a phone or an email every type gets a ContactPoint (contactType from the setting, customer service by
	 * default) with the opening hours as hoursAvailable.
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
		if ( '' !== $details['description'] ) {
			$node['description'] = $details['description'];
		}
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
			$node['founder'] = self::person( $details['founder'] );
		}
		$local = 'Organization' !== $details['type'];
		if ( $local && $details['opening_hours'] ) {
			$node['openingHoursSpecification'] = self::hours_specification( $details['opening_hours'] );
		}
		// A ContactPoint for every type, so an Organization names its service times too (hoursAvailable).
		if ( '' !== $details['phone'] || '' !== $details['email'] ) {
			$node['contactPoint'] = array( '@type' => 'ContactPoint' ) + array_filter(
				array(
					'telephone'      => $details['phone'],
					'email'          => $details['email'],
					'contactType'    => $details['contact_type'],
					'hoursAvailable' => self::hours_specification( $details['opening_hours'] ),
				)
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
	 * Opening hours as schema.org OpeningHoursSpecification nodes.
	 *
	 * @param list<array{days: list<string>, opens: string, closes: string}> $rows Rows of hours().
	 * @return list<array<string, mixed>>
	 */
	private static function hours_specification( array $rows ): array {
		return array_map(
			static fn( array $row ): array => array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $row['days'],
				'opens'     => $row['opens'],
				'closes'    => $row['closes'],
			),
			$rows
		);
	}

	/**
	 * Logo: the logo for structured data (setting organization_logo, field logo of set-organization), else the custom
	 * logo of the header, each when it is a raster image of at least News\Schema::LOGO_MIN pixels in both directions,
	 * else the site icon in its 512 px size under the same rule, else none. An SVG has no pixel size and never
	 * qualifies. A separate logo lets the header keep a light logo for a dark background while search engines, which
	 * show logos on white, get a dark one.
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
		$own = (int) leitvia_studio_setting( 'organization_logo' );
		foreach ( array( $own ? wp_get_attachment_image_src( $own, 'full' ) : false, wp_get_attachment_image_src( (int) get_theme_mod( 'custom_logo' ), 'full' ), $icon ) as $logo ) {
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
	 * The people of the organization as set-organization stores them (setting organization_people), see clean_people().
	 *
	 * @return list<array{name: string, job_title: string, description: string, image: int, same_as: list<string>, knows_about: list<string>, page: int}>
	 */
	public static function people(): array {
		return self::clean_people( leitvia_studio_setting( 'organization_people' ) );
	}

	/**
	 * Valid profiles of people: name (required, each name once), job title, description, image (media ID), profiles on
	 * other sites (same_as, https addresses), topics (knows_about) and page (ID of the page about the person, such as
	 * the author page of leitvia/update-content author_page). At most MAX_PEOPLE, each list at most MAX_PERSON_ENTRIES.
	 *
	 * @param mixed $rows Submitted rows.
	 * @return list<array{name: string, job_title: string, description: string, image: int, same_as: list<string>, knows_about: list<string>, page: int}>
	 */
	public static function clean_people( $rows ): array {
		$list  = static function ( $items, bool $urls ): array {
			$items = array_map(
				static fn( $item ): string => ! is_string( $item ) ? '' : ( $urls ? ( str_starts_with( $item, 'https://' ) ? esc_url_raw( $item, array( 'https' ) ) : '' ) : sanitize_text_field( $item ) ),
				is_array( $items ) ? $items : array()
			);
			return array_slice( array_values( array_unique( array_filter( $items ) ) ), 0, self::MAX_PERSON_ENTRIES );
		};
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row  = is_array( $row ) ? $row : array();
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$key  = mb_strtolower( $name );
			if ( '' === $name || isset( $clean[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'name'        => $name,
				'job_title'   => sanitize_text_field( (string) ( $row['job_title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
				'image'       => absint( $row['image'] ?? 0 ),
				'same_as'     => $list( $row['same_as'] ?? array(), true ),
				'knows_about' => $list( $row['knows_about'] ?? array(), false ),
				'page'        => absint( $row['page'] ?? 0 ),
			);
		}
		return array_slice( array_values( $clean ), 0, self::MAX_PEOPLE );
	}

	/**
	 * The profile of a person by name, the case aside; null without one.
	 *
	 * @param string $name Name of the person.
	 * @return array{name: string, job_title: string, description: string, image: int, same_as: list<string>, knows_about: list<string>, page: int}|null
	 */
	public static function profile( string $name ): ?array {
		foreach ( self::people() as $profile ) {
			if ( 0 === strcasecmp( $profile['name'], trim( $name ) ) ) {
				return $profile;
			}
		}
		return null;
	}

	/**
	 * The @id of a person: the address of the site with #person- and the name as slug. The founder of the organization,
	 * the author of an article and the main entity of a profile page share it.
	 *
	 * @param string $name Name of the person.
	 */
	public static function person_id( string $name ): string {
		return home_url( '/#person-' . sanitize_title( $name ) );
	}

	/**
	 * Person node of a name with its @id (person_id()) and, with a profile (people()), job title, description, image,
	 * profiles (sameAs), topics (knowsAbout), the organization as employer (worksFor) and the address of its published
	 * page as url. $url stands in for a profile without a page and a person without a profile; $extra fills what the
	 * profile leaves empty, such as the biography of a user account.
	 *
	 * @param string               $name  Name of the person.
	 * @param string               $url   Address of a page about the person, '' for none.
	 * @param array<string, mixed> $extra Schema.org properties for where the profile has none.
	 * @return array<string, mixed>
	 */
	public static function person( string $name, string $url = '', array $extra = array() ): array {
		$profile = self::profile( $name );
		$page    = $profile && $profile['page'] && 'publish' === get_post_status( $profile['page'] ) ? (string) get_permalink( $profile['page'] ) : '';
		$node    = array(
			'@type' => 'Person',
			'@id'   => self::person_id( $name ),
			'name'  => $profile ? $profile['name'] : trim( $name ),
		) + array_filter( array( 'url' => '' !== $page ? $page : $url ) );
		if ( $profile ) {
			$image = $profile['image'] ? wp_get_attachment_image_url( $profile['image'], 'large' ) : '';
			$node += array_filter(
				array(
					'jobTitle'    => $profile['job_title'],
					'description' => $profile['description'],
					'image'       => is_string( $image ) ? $image : '',
					'sameAs'      => $profile['same_as'],
					'knowsAbout'  => $profile['knows_about'],
					'worksFor'    => array( '@id' => self::id() ),
				)
			);
		}
		return $node + array_filter( $extra );
	}

	/**
	 * Name of the person a page is about: the profile whose page it is, else the author without an account of a
	 * published post that links to it (leitvia/update-content author_page) while that author has no profile with a page
	 * of its own; '' when it is about no person. So a person gets one ProfilePage: a post that names another page, such
	 * as a booking page, as author page does not make that page a second profile.
	 *
	 * @param int $page Page ID.
	 */
	public static function person_of_page( int $page ): string {
		$profiled = array();
		foreach ( self::people() as $profile ) {
			if ( $page === $profile['page'] ) {
				return $profile['name'];
			}
			if ( $profile['page'] ) {
				$profiled[] = mb_strtolower( $profile['name'] );
			}
		}
		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => \LeitviaStudio\News\Schema::AUTHOR_PAGE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few posts name an author page.
				'meta_value'  => (string) $page, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);
		$name  = $posts ? trim( (string) get_post_meta( (int) $posts[0], \LeitviaStudio\News\Schema::AUTHOR_NAME_META, true ) ) : '';
		return in_array( mb_strtolower( $name ), $profiled, true ) ? '' : $name;
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
