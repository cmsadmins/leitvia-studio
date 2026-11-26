<?php
/**
 * Registered patterns without an entry in the design contract, and their review in the Lab.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Patterns of the child theme or theme (source site) and of core or other plugins (source plugin) that the contract does
 * not describe. They stay unreviewed until a person approves them in the Lab; the approval may add the composition
 * metadata and section kinds the contract would carry. Studio keeps reviews in one option by pattern slug, never in the
 * theme. Titles and descriptions of these patterns are data of others; clean() limits and strips them.
 */
final class Foreign_Patterns {

	public const OPTION = 'leitvia_studio_pattern_reviews';

	public const MAX_TITLE = 120;

	public const MAX_DESCRIPTION = 500;

	/**
	 * Keys of the composition metadata an approval sets, all or none; Composition::check() reads each of them.
	 */
	public const KEYS = array( 'role', 'tone', 'layout', 'density', 'weight', 'width' );

	/**
	 * Names of the contract and of building blocks, and the namespaces of registered add-ons; kept for the request.
	 *
	 * @var array{slugs: array<string, true>, prefixes: list<string>}|null
	 */
	private static ?array $known = null;

	/**
	 * Slugs of the block pattern files of a theme and its parent, by stylesheet.
	 *
	 * @var array<string, array<string, true>>
	 */
	private static array $theme_slugs = array();

	/**
	 * Drops what is kept for the request. Runs with Status::flush(), which runs when add-ons register again too.
	 */
	public static function flush(): void {
		self::$known       = null;
		self::$theme_slugs = array();
	}

	/**
	 * Whether a registered pattern is foreign: not in the namespace of Studio or of a registered add-on, not in the
	 * contract of the theme, of Studio or of an add-on, and no pattern of a building block.
	 *
	 * @param array<string, mixed> $pattern Registered pattern.
	 */
	public static function is_foreign_pattern( array $pattern ): bool {
		return self::is_foreign_name( (string) ( $pattern['name'] ?? '' ) );
	}

	/**
	 * Whether a pattern name is foreign, see is_foreign_pattern().
	 *
	 * @param string $name Pattern name.
	 */
	public static function is_foreign_name( string $name ): bool {
		if ( '' === $name ) {
			return false;
		}
		if ( null === self::$known ) {
			$data  = leitvia_studio_contract()->data();
			$slugs = array();
			foreach ( array( 'patterns', 'studioPatterns', 'addonPatterns' ) as $key ) {
				foreach ( (array) ( $data[ $key ] ?? array() ) as $pattern ) {
					$slugs[ is_array( $pattern ) ? (string) ( $pattern['slug'] ?? '' ) : '' ] = true;
				}
			}
			foreach ( leitvia_studio()->registry( 'building_blocks' )->all() as $block ) {
				foreach ( (array) ( $block['patterns'] ?? array() ) as $slug ) {
					$slugs[ (string) $slug ] = true;
				}
			}
			$prefixes = array();
			foreach ( leitvia_studio()->extensions()->all() as $slug => $addon ) {
				if ( $addon['compatible'] ) {
					$prefixes[] = $slug . '/';
				}
			}
			self::$known = array(
				'slugs'    => $slugs,
				'prefixes' => $prefixes,
			);
		}
		if ( isset( self::$known['slugs'][ $name ] ) ) {
			return false;
		}
		foreach ( self::$known['prefixes'] as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the slug names a registered foreign pattern.
	 *
	 * @param string $slug Pattern slug.
	 */
	public static function is_foreign( string $slug ): bool {
		return \WP_Block_Patterns_Registry::get_instance()->is_registered( $slug ) && self::is_foreign_name( $slug );
	}

	/**
	 * Foreign section patterns by slug with their source: those of the active theme also when hidden from the inserter
	 * (as the Lab lists them), others only when the inserter shows them. Template patterns and patterns for a template
	 * part area stay out; leitvia/get-pattern returns them as before. Names that are no plain namespace/name of at
	 * most 100 characters stay out too; they stay unreviewed, so they never reach a page.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$items = array();
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );
			if ( strlen( $name ) > 100 || ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_.-]+$#D', $name ) || ! self::is_foreign_name( $name ) || ! empty( $pattern['templateTypes'] ) ) {
				continue;
			}
			$theme = self::in_theme( $pattern );
			$part  = array_filter( array_map( 'strval', (array) ( $pattern['blockTypes'] ?? array() ) ), static fn( string $type ): bool => str_starts_with( $type, 'core/template-part/' ) );
			if ( $part || ( ! $theme && false === ( $pattern['inserter'] ?? true ) ) ) {
				continue;
			}
			$items[ $name ] = $pattern + array( 'source' => $theme ? 'site' : 'plugin' );
		}
		return $items;
	}

	/**
	 * Whether a registered pattern belongs to the active theme or its parent: its slug is one of the pattern files of
	 * the theme (WP_Theme::get_block_patterns(), which still knows them once core dropped filePath), or its name is
	 * prefixed with the theme slug.
	 *
	 * @param array<string, mixed> $pattern Registered pattern.
	 */
	public static function in_theme( array $pattern ): bool {
		$name  = (string) ( $pattern['name'] ?? '' );
		$sheet = get_stylesheet();
		if ( ! isset( self::$theme_slugs[ $sheet ] ) ) {
			$theme  = wp_get_theme();
			$themes = $theme->parent() ? array( $theme, $theme->parent() ) : array( $theme );
			$slugs  = array();
			foreach ( $themes as $item ) {
				foreach ( $item->get_block_patterns() as $data ) {
					$slugs[ (string) ( $data['slug'] ?? '' ) ] = true;
				}
			}
			self::$theme_slugs[ $sheet ] = $slugs;
		}
		return isset( self::$theme_slugs[ $sheet ][ $name ] ) || str_starts_with( $name, $sheet . '/' ) || str_starts_with( $name, get_template() . '/' );
	}

	/**
	 * Fingerprint of the markup a person reviewed.
	 *
	 * @param string $slug Pattern slug.
	 */
	public static function hash( string $slug ): string {
		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		return md5( (string) ( $pattern['content'] ?? '' ) );
	}

	/**
	 * Lab status of a foreign pattern: approved after an approval of the markup as it is now, else unreviewed.
	 *
	 * @param string $slug Pattern slug.
	 */
	public static function status( string $slug ): string {
		$review = self::review( $slug );
		return null !== $review && 'approved' === $review['status'] && hash_equals( $review['hash'], self::hash( $slug ) ) ? 'approved' : 'unreviewed';
	}

	/**
	 * Stored approval of a pattern, null without one.
	 *
	 * @param string $slug Pattern slug.
	 * @return array{status: string, composition: array<string, string>, provides: list<string>, hash: string, at: string}|null
	 */
	public static function review( string $slug ): ?array {
		$reviews = get_option( self::OPTION, array() );
		$review  = is_array( $reviews ) ? ( $reviews[ $slug ] ?? null ) : null;
		if ( ! is_array( $review ) ) {
			return null;
		}
		return array(
			'status'      => (string) ( $review['status'] ?? '' ),
			'composition' => array_map( 'strval', (array) ( $review['composition'] ?? array() ) ),
			'provides'    => array_values( array_map( 'strval', (array) ( $review['provides'] ?? array() ) ) ),
			'hash'        => (string) ( $review['hash'] ?? '' ),
			'at'          => (string) ( $review['at'] ?? '' ),
		);
	}

	/**
	 * Approves a foreign pattern for agents, with composition metadata (all KEYS or none) and section kinds, or
	 * withdraws the approval (unreviewed). Fires leitvia_studio_patterns_changed.
	 *
	 * @param string               $slug        Pattern slug.
	 * @param string               $status      approved or unreviewed.
	 * @param array<string, mixed> $composition Composition metadata by key.
	 * @param array<mixed>         $provides    Section kinds of the contract.
	 * @return array<string, mixed>|null|\WP_Error The stored approval, null after a withdrawal.
	 */
	public static function save( string $slug, string $status, array $composition = array(), array $provides = array() ) {
		if ( ! self::is_foreign( $slug ) ) {
			return new \WP_Error( 'leitvia_studio_not_foreign', __( 'Only registered patterns without an entry in the design contract are reviewed here.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $status, array( 'approved', 'unreviewed' ), true ) ) {
			return new \WP_Error( 'leitvia_invalid_status', __( 'The status of a pattern review is approved or unreviewed.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		$choices     = self::choices();
		$composition = array_filter( array_map( 'strval', array_intersect_key( $composition, array_flip( self::KEYS ) ) ), static fn( string $value ): bool => '' !== $value );
		$invalid     = array_filter( $composition, static fn( string $value, string $key ): bool => ! in_array( $value, $choices['composition'][ $key ], true ), ARRAY_FILTER_USE_BOTH );
		if ( $composition && ( count( $composition ) !== count( self::KEYS ) || $invalid ) ) {
			return new \WP_Error( 'leitvia_studio_invalid_composition', __( 'Set all six composition values from the design contract, or none.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		$provides = array_values( array_unique( array_map( 'strval', $provides ) ) );
		if ( array_diff( $provides, $choices['kinds'] ) ) {
			return new \WP_Error( 'leitvia_studio_invalid_provides', __( 'A section kind is not in the design contract.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		$reviews = get_option( self::OPTION, array() );
		$reviews = is_array( $reviews ) ? $reviews : array();
		unset( $reviews[ $slug ] );
		if ( 'approved' === $status ) {
			$reviews[ $slug ] = array(
				'status'      => 'approved',
				'composition' => $composition,
				'provides'    => $provides,
				'hash'        => self::hash( $slug ),
				'at'          => gmdate( 'c' ),
			);
		}
		update_option( self::OPTION, $reviews, false );
		Status::flush();
		/**
		 * Fires when the patterns agents may use changed: a registered pattern outside the contract was approved in the
		 * Lab or its approval withdrawn. The MCP server tells subscribed clients.
		 *
		 * @param string $slug Pattern slug.
		 */
		do_action( 'leitvia_studio_patterns_changed', $slug );
		return self::review( $slug );
	}

	/**
	 * Values an approval may set: per composition key the values the patterns of the contract use (role without
	 * frame), and the section kinds of the contract.
	 *
	 * @return array{composition: array<string, list<string>>, kinds: list<string>}
	 */
	public static function choices(): array {
		$data   = leitvia_studio_contract()->data();
		$values = array_fill_keys( self::KEYS, array() );
		foreach ( array_merge( (array) ( $data['patterns'] ?? array() ), (array) ( $data['studioPatterns'] ?? array() ), (array) ( $data['addonPatterns'] ?? array() ) ) as $pattern ) {
			foreach ( self::KEYS as $key ) {
				$value = is_array( $pattern ) ? ( $pattern['composition'][ $key ] ?? null ) : null;
				if ( is_string( $value ) && ( 'role' !== $key || 'frame' !== $value ) ) {
					$values[ $key ][ $value ] = $value;
				}
			}
		}
		$values = array_map(
			static function ( array $options ): array {
				sort( $options );
				return $options;
			},
			$values
		);
		$kinds  = array_map( static fn( $kind ): string => is_array( $kind ) ? (string) ( $kind['kind'] ?? '' ) : '', (array) ( $data['sectionKinds'] ?? array() ) );
		return array(
			'composition' => $values,
			'kinds'       => array_values( array_filter( $kinds, static fn( string $kind ): bool => '' !== $kind ) ),
		);
	}

	/**
	 * Text of another source as plain data: tags and entities resolved, control and format characters (zero-width,
	 * bidi overrides) and line breaks turned into single spaces, at most $max characters.
	 *
	 * @param string $text Title or description.
	 * @param int    $max  Largest length in characters.
	 */
	public static function clean( string $text, int $max ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = trim( (string) preg_replace( '/[\p{Cc}\p{Cf}\s]+/u', ' ', $text ) );
		return mb_substr( $text, 0, $max );
	}
}
