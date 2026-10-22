<?php
/**
 * Autonomy levels of the gateway.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Decides how far an agent may go: the level of the area (defaults of spec 7.5, profile, overrides), lowered to at most
 * "suggest" for prices, legal texts, slugs and organization data unless a human unlocked the kind.
 */
final class Autonomy {

	/**
	 * Default level per area, profile "standard".
	 */
	public const DEFAULTS = array(
		'content'     => 'draft',
		'corrections' => 'autonomous',
		'structure'   => 'draft',
		'design'      => 'suggest',
		'admin'       => 'locked',
	);

	/**
	 * Page kinds (meta _leitvia_page_kind) that hold legal texts.
	 */
	public const LEGAL_PAGES = array( 'imprint', 'privacy', 'terms', 'withdrawal', 'accessibility' );

	/**
	 * Blocks that are prices as a whole.
	 */
	public const PRICE_BLOCKS = array( 'leitvia/pricing', 'leitvia/pricing-plan' );

	/**
	 * A cell or header that names a currency or a price marks its column of leitvia/comparison-table as prices.
	 */
	private const PRICE_PATTERN = '/[€$£¥]|\b(?:EUR|USD|GBP|CHF)\b|price|preis/iu';

	/**
	 * Levels from lowest to highest.
	 */
	private const RANK = array(
		'locked'     => 0,
		'suggest'    => 1,
		'draft'      => 2,
		'autonomous' => 3,
	);

	/**
	 * Level of an area: profile "full" opens every area but admin, an override of a human wins. Admin (plugins, users,
	 * settings) and unknown areas stay locked whatever the settings say.
	 *
	 * @param string $area Area.
	 */
	public static function area_level( string $area ): string {
		$settings = Gateway::settings();
		$levels   = array();
		foreach ( self::DEFAULTS as $name => $default ) {
			$levels[ $name ] = (string) ( $settings['overrides']['areas'][ $name ] ?? ( 'full' === $settings['profile'] ? 'autonomous' : $default ) );
		}
		/**
		 * Filters the autonomy level per area after profile and overrides, for example to add the area of an add-on or
		 * to apply the policy of a fleet of sites. Levels are locked, suggest, draft or autonomous; admin and areas
		 * without a valid level stay locked.
		 *
		 * @param array<string, string> $levels   Level by area.
		 * @param array<string, mixed>  $settings Gateway settings.
		 */
		$level = (string) ( ( (array) apply_filters( 'leitvia_studio_gateway_areas', $levels, $settings ) )[ $area ] ?? 'locked' );
		return 'admin' === $area || ! isset( self::RANK[ $level ] ) ? 'locked' : $level;
	}

	/**
	 * Level of an operation in an area that touches these content kinds.
	 *
	 * @param string   $area  Area.
	 * @param string[] $kinds Content kinds.
	 */
	public static function level( string $area, array $kinds ): string {
		$level  = self::area_level( $area );
		$locked = array_diff( $kinds, (array) Gateway::settings()['overrides']['kinds'] );
		return $locked && self::RANK[ $level ] > self::RANK['suggest'] ? 'suggest' : $level;
	}

	/**
	 * Whether level a is higher than level b.
	 *
	 * @param string $a Level.
	 * @param string $b Level.
	 */
	public static function above( string $a, string $b ): bool {
		return self::RANK[ $a ] > self::RANK[ $b ];
	}

	/**
	 * Content kinds an operation touches: the ones the ability declares plus the detected ones.
	 *
	 * @param Operation            $op     Operation.
	 * @param array<string, mixed> $before State before, empty for a new object.
	 * @param array<string, mixed> $after  State after.
	 * @return list<string>
	 */
	public static function kinds( Operation $op, array $before, array $after ): array {
		$kinds = $op->kinds;
		if ( in_array( $op->option(), Pipeline::OPTIONS, true ) ) {
			$kinds[] = 'organization';
		}
		if ( $op->post_id() ) {
			$id = $op->post_id();
			if ( in_array( get_post_meta( $id, '_leitvia_page_kind', true ), self::LEGAL_PAGES, true ) || (int) get_option( 'wp_page_for_privacy_policy' ) === $id ) {
				$kinds[] = 'legal';
			}
			if ( (string) ( $before['post_name'] ?? '' ) !== (string) ( $after['post_name'] ?? '' ) ) {
				$kinds[] = 'slugs';
			}
		}
		if ( self::prices( (string) ( $before['post_content'] ?? '' ) ) !== self::prices( (string) ( $after['post_content'] ?? '' ) ) ) {
			$kinds[] = 'prices';
		}
		/**
		 * Filters the content kinds of an operation, for example product prices of a commerce add-on. Known kinds are
		 * prices, legal, slugs and organization; each caps the level at "suggest" unless unlocked in the settings.
		 *
		 * @param string[]             $kinds  Content kinds.
		 * @param Operation            $op     Operation.
		 * @param array<string, mixed> $before State before.
		 * @param array<string, mixed> $after  State after.
		 */
		$kinds = (array) apply_filters( 'leitvia_studio_gateway_content_kind', $kinds, $op, $before, $after );
		return array_values( array_unique( array_map( 'strval', $kinds ) ) );
	}

	/**
	 * Everything in block markup that counts as a price, in document order: whole pricing blocks and the price columns
	 * of comparison tables. Moving other blocks around leaves it unchanged.
	 *
	 * @param string $content Block markup.
	 * @return list<mixed>
	 */
	private static function prices( string $content ): array {
		$found = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$found ): void {
			foreach ( $blocks as $block ) {
				$name = (string) ( $block['blockName'] ?? '' );
				if ( in_array( $name, self::PRICE_BLOCKS, true ) ) {
					$found[] = serialize_block( $block );
				} elseif ( 'leitvia/comparison-table' === $name ) {
					$found[] = self::price_columns( (array) $block['attrs'] );
				} else {
					$walk( (array) ( $block['innerBlocks'] ?? array() ) );
				}
			}
		};
		if ( has_blocks( $content ) ) {
			$walk( parse_blocks( $content ) );
		}
		return $found;
	}

	/**
	 * The computed column and every column whose header or cells name a currency or a price.
	 *
	 * @param array<string, mixed> $attrs Attributes of leitvia/comparison-table.
	 * @return array<string, mixed>
	 */
	private static function price_columns( array $attrs ): array {
		$rows    = array_merge( array( (array) ( $attrs['columns'] ?? array() ) ), (array) ( $attrs['rows'] ?? array() ) );
		$columns = array();
		foreach ( $rows as $row ) {
			foreach ( array_values( (array) $row ) as $index => $cell ) {
				$columns[ $index ][] = is_scalar( $cell ) ? (string) $cell : '';
			}
		}
		$prices = array_filter( $columns, static fn( array $cells ): bool => (bool) preg_match( self::PRICE_PATTERN, implode( ' ', $cells ) ) );
		return array(
			'columns'  => $prices,
			'computed' => $attrs['computed'] ?? null,
		);
	}
}
