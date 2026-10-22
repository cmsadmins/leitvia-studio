<?php
/**
 * Leitvia profile of the Design Tokens format (DTCG 2025.10) and its mapping to theme.json settings.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use LeitviaStudio\Contract\Contract;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses token documents, resolves aliases and maps the six role groups to theme.json preset lists.
 */
final class Dtcg {

	/**
	 * Role group => settings path, preset value key and DTCG type.
	 */
	public const GROUPS = array(
		'color'      => array( 'color', 'palette', 'color', 'color' ),
		'fontSize'   => array( 'typography', 'fontSizes', 'size', 'dimension' ),
		'fontFamily' => array( 'typography', 'fontFamilies', 'fontFamily', 'fontFamily' ),
		'spacing'    => array( 'spacing', 'spacingSizes', 'size', 'dimension' ),
		'radius'     => array( 'border', 'radiusSizes', 'size', 'dimension' ),
		'shadow'     => array( 'shadow', 'presets', 'shadow', 'shadow' ),
	);

	public const MAX_BYTES     = 512 * 1024;
	public const MAX_DEPTH     = 32;
	private const ALIAS_DEPTH  = 10;
	private const EXT          = 'com.leitvia';
	private const NUMBER_UNIT  = '(-?\d*\.?\d+)(px|rem)';
	private const CSS_CHARS    = '/^[a-z0-9 .,%()+\-*\/#"\']*$/iD';
	private const CSS_DENY     = '/\/\*|\bexpression\b|\bimport\b|image-set|url\s*\(/i';
	private const CSS_FUNCS    = array( 'clamp', 'min', 'max', 'calc', 'rgb', 'rgba', 'hsl', 'hsla', 'var' );
	private const JSON_FLAGS   = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION;
	private const ALIAS_FORMAT = '/^\{([^{}]+)\}$/D';

	/**
	 * Builds a token document from merged theme.json settings. Only contract roles are exported.
	 *
	 * @param array<string, mixed> $settings Settings as returned by wp_get_global_settings().
	 * @param Contract             $c        Contract of the active theme.
	 * @return array<string, mixed>
	 */
	public static function from_settings( array $settings, Contract $c ): array {
		$doc = array(
			'$extensions' => array(
				self::EXT => array(
					'format'          => 1,
					'contractVersion' => $c->version(),
					'source'          => 'theme:' . get_stylesheet(),
				),
			),
		);
		foreach ( self::GROUPS as $group => list( $section, $list, $key, $type ) ) {
			$presets = self::effective( $settings, $section, $list );
			foreach ( self::role_slugs( $c, $group ) as $slug ) {
				if ( ! isset( $presets[ $slug ][ $key ] ) || ! is_string( $presets[ $slug ][ $key ] ) ) {
					continue;
				}
				$preset = $presets[ $slug ];
				$ext    = array( 'name' => (string) ( $preset['name'] ?? $slug ) );
				if ( 'fontSize' === $group && array_key_exists( 'fluid', $preset ) ) {
					$ext['fluid'] = $preset['fluid'];
				}
				$value = self::export_value( $type, $preset[ $key ] );
				if ( 'color' !== $type && self::css( $type, $value['value'] ) !== $value['css'] ) {
					$ext['css'] = $value['css'];
				}
				$doc[ $group ][ $slug ] = array(
					'$type'       => $type,
					'$value'      => $value['value'],
					'$extensions' => array( self::EXT => $ext ),
				);
			}
		}
		return $doc;
	}

	/**
	 * Presets of a list as the stylesheet emits them, by slug: the origin custom over the origin theme. A style
	 * variation in the user global styles holds plain lists, which core files under custom, so they outrank theme.
	 *
	 * @param array<string, mixed> $settings Settings as returned by wp_get_global_settings().
	 * @param string               $section  Settings section, for example color.
	 * @param string               $name     Preset list, for example palette.
	 * @return array<string, array<string, mixed>>
	 */
	public static function effective( array $settings, string $section, string $name ): array {
		$lists = (array) ( $settings[ $section ][ $name ] ?? array() );
		// Union, not array_merge(): numeric slugs such as 50 must keep their keys.
		return array_column( (array) ( $lists['custom'] ?? array() ), null, 'slug' ) + array_column( (array) ( $lists['theme'] ?? array() ), null, 'slug' );
	}

	/**
	 * Maps a token document to theme.json settings with the preset lists under the origin key "theme".
	 *
	 * @param array<string, mixed> $doc Token document.
	 * @param Contract             $c   Contract of the active theme.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function to_settings( array $doc, Contract $c ) {
		if ( ! $c->is_valid() ) {
			return new WP_Error( 'leitvia_studio_no_contract', __( 'The active theme has no valid contract.', 'leitvia-studio' ), array( 'status' => 422 ) );
		}
		$doc = self::resolve( $doc );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$fonts    = self::bundled_fonts();
		$settings = array();
		foreach ( self::GROUPS as $group => list( $section, $list, $key, $type ) ) {
			$slugs = self::role_slugs( $c, $group );
			if ( ! $slugs ) {
				continue;
			}
			$tokens = is_array( $doc[ $group ] ?? null ) ? self::tokens( $doc[ $group ] ) : array();
			foreach ( array_diff( array_keys( $tokens ), $slugs ) as $extra ) {
				return self::error(
					'unknown_role',
					/* translators: %s: token path, for example color.brand. */
					__( 'The token %s is not a role of the theme contract.', 'leitvia-studio' ),
					"$group.$extra"
				);
			}
			$presets   = array();
			$fallbacks = self::role_fallbacks( $c, $group );
			foreach ( $slugs as $slug ) {
				// A role added in a minor contract version names an older role as fallback, so sets saved before stay valid.
				if ( ! isset( $tokens[ $slug ] ) && isset( $fallbacks[ $slug ], $tokens[ $fallbacks[ $slug ] ] ) ) {
					$tokens[ $slug ] = $tokens[ $fallbacks[ $slug ] ];
					unset( $tokens[ $slug ]['$extensions'][ self::EXT ]['name'] );
				}
				if ( ! isset( $tokens[ $slug ] ) ) {
					/* translators: %s: token path, for example color.focus. */
					return self::error( 'missing_role', __( 'The token %s is missing.', 'leitvia-studio' ), "$group.$slug" );
				}
				$token = $tokens[ $slug ];
				if ( isset( $token['$type'] ) && $type !== $token['$type'] ) {
					/* translators: %s: token path, for example color.focus. */
					return self::error( 'type', __( 'The token %s has the wrong $type.', 'leitvia-studio' ), "$group.$slug" );
				}
				$ext   = is_array( $token['$extensions'][ self::EXT ] ?? null ) ? $token['$extensions'][ self::EXT ] : array();
				$value = self::preset_value( $type, $token['$value'] ?? null, $ext['css'] ?? null );
				if ( is_wp_error( $value ) ) {
					/* translators: 1: token path, 2: error message. */
					return self::error( (string) $value->get_error_code(), __( 'The token %1$s is invalid: %2$s', 'leitvia-studio' ), "$group.$slug", $value->get_error_message() );
				}
				$preset = array(
					'slug' => $slug,
					'name' => is_string( $ext['name'] ?? null ) ? sanitize_text_field( $ext['name'] ) : $slug,
					$key   => $value,
				);
				if ( 'fontSize' === $group && array_key_exists( 'fluid', $ext ) ) {
					if ( ! self::is_fluid( $ext['fluid'] ) ) {
						/* translators: %s: token path, for example fontSize.body. */
						return self::error( 'invalid_value', __( 'The token %s has an invalid fluid setting.', 'leitvia-studio' ), "$group.$slug" );
					}
					$preset['fluid'] = $ext['fluid'];
				}
				if ( 'fontFamily' === $group ) {
					$first = strtolower( self::split( $value )[0] ?? '' );
					if ( isset( $fonts[ $first ] ) ) {
						$preset['fontFace'] = $fonts[ $first ];
					}
				}
				$presets[] = $preset;
			}
			$settings[ $section ][ $list ]['theme'] = $presets;
		}
		return $settings;
	}

	/**
	 * Replaces every alias value "{path.to.token}" with the value of its target, recursively.
	 *
	 * @param array<string, mixed> $doc Token document.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resolve( array $doc ) {
		return self::walk( $doc, $doc );
	}

	/**
	 * Hex color "#rrggbb" of a DTCG color value. Only sRGB is supported; "hex" wins over "components".
	 *
	 * @param mixed $value DTCG color value.
	 * @return string|WP_Error
	 */
	public static function hex( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'leitvia_studio_dtcg_invalid_value', __( 'A color must be an object with colorSpace and components.', 'leitvia-studio' ) );
		}
		if ( 'srgb' !== ( $value['colorSpace'] ?? null ) ) {
			return new WP_Error( 'leitvia_studio_dtcg_color_space', __( 'Only the srgb color space is supported.', 'leitvia-studio' ) );
		}
		if ( is_string( $value['hex'] ?? null ) && preg_match( '/^#[0-9a-f]{6}$/iD', $value['hex'] ) ) {
			return strtolower( $value['hex'] );
		}
		$components = $value['components'] ?? null;
		if ( ! is_array( $components ) || ! array_is_list( $components ) || 3 !== count( $components ) ) {
			return new WP_Error( 'leitvia_studio_dtcg_invalid_value', __( 'A color needs three components.', 'leitvia-studio' ) );
		}
		$hex = '#';
		foreach ( $components as $component ) {
			if ( ( ! is_int( $component ) && ! is_float( $component ) ) || $component < 0 || $component > 1 ) {
				return new WP_Error( 'leitvia_studio_dtcg_invalid_value', __( 'Color components must be numbers from 0 to 1.', 'leitvia-studio' ) );
			}
			$hex .= sprintf( '%02x', (int) round( $component * 255 ) );
		}
		return $hex;
	}

	/**
	 * Parses a CSS box-shadow into a list of DTCG shadow values. Used for export only.
	 *
	 * @param string $css CSS box-shadow value.
	 * @return list<array<string, mixed>>
	 */
	public static function parse_shadow_css( string $css ): array {
		$shadows = array();
		foreach ( self::split( $css ) as $part ) {
			$color = array(
				'colorSpace' => 'srgb',
				'components' => array( 0, 0, 0 ),
			);
			if ( preg_match( '/rgba?\([^)]*\)|#[0-9a-f]{3,6}\b/i', $part, $match ) ) {
				$color = self::parse_color( $match[0] );
				$part  = str_replace( $match[0], '', $part );
			}
			preg_match_all( '/' . self::NUMBER_UNIT . '?/', $part, $numbers, PREG_SET_ORDER );
			$dims = array();
			foreach ( array_slice( array_pad( $numbers, 4, array( '0', '0', 'px' ) ), 0, 4 ) as $number ) {
				$dims[] = array(
					'value' => self::number( $number[1] ),
					'unit'  => '' === ( $number[2] ?? '' ) ? 'px' : $number[2],
				);
			}
			$shadow = array(
				'color'   => $color,
				'offsetX' => $dims[0],
				'offsetY' => $dims[1],
				'blur'    => $dims[2],
				'spread'  => $dims[3],
			);
			if ( preg_match( '/\binset\b/', $part ) ) {
				$shadow['inset'] = true;
			}
			$shadows[] = $shadow;
		}
		return $shadows;
	}

	/**
	 * Checks size and JSON depth of a raw document.
	 *
	 * @param string $json Raw JSON.
	 * @return true|WP_Error
	 */
	public static function validate_size( string $json ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'leitvia_studio_dtcg_too_large', __( 'The token document is larger than 512 KB.', 'leitvia-studio' ), array( 'status' => 413 ) );
		}
		if ( ! is_array( json_decode( $json, true, self::MAX_DEPTH ) ) ) {
			return new WP_Error( 'leitvia_studio_dtcg_invalid_json', __( 'The token document is not valid JSON or nested deeper than 32 levels.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Sanitizes the stored document: decodes and encodes it again, or returns an empty string when it is too large
	 * or too deep in this stored form. Decoded as objects, so {} and [] stay apart; 1.0 keeps its fraction.
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function sanitize_json( $value ): string {
		if ( ! is_string( $value ) || true !== self::validate_size( $value ) ) {
			return '';
		}
		return (string) wp_json_encode( json_decode( $value ), self::JSON_FLAGS );
	}

	/**
	 * Sets $extensions.com.leitvia.contractVersion and leaves everything else of the document as it is.
	 *
	 * @param string $json    Raw DTCG JSON with an object at the top.
	 * @param string $version Contract version.
	 */
	public static function stamp( string $json, string $version ): string {
		$doc = json_decode( $json );
		if ( ! $doc instanceof \stdClass ) {
			return $json;
		}
		$ext = $doc->{'$extensions'} ?? null;
		$ext = $ext instanceof \stdClass ? $ext : new \stdClass();
		$own = $ext->{self::EXT} ?? null;
		$own = $own instanceof \stdClass ? $own : new \stdClass();

		$own->contractVersion = $version; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DTCG key.
		$ext->{self::EXT}     = $own;
		$doc->{'$extensions'} = $ext;
		return (string) wp_json_encode( $doc, self::JSON_FLAGS );
	}

	/**
	 * Role slugs of one group in contract order.
	 *
	 * @param Contract $c     Contract.
	 * @param string   $group Role group.
	 * @return list<string>
	 */
	private static function role_slugs( Contract $c, string $group ): array {
		$roles = $c->data()['roles'][ $group ] ?? array();
		return is_array( $roles ) ? array_values( array_filter( array_column( $roles, 'slug' ), 'is_string' ) ) : array();
	}

	/**
	 * Role slug => slug of the role whose token stands in when the token set has none (contract field "fallback").
	 *
	 * @param Contract $c     Contract of the active theme.
	 * @param string   $group Role group.
	 * @return array<string, string>
	 */
	private static function role_fallbacks( Contract $c, string $group ): array {
		$fallbacks = array();
		foreach ( (array) ( $c->data()['roles'][ $group ] ?? array() ) as $role ) {
			if ( is_string( $role['slug'] ?? null ) && is_string( $role['fallback'] ?? null ) ) {
				$fallbacks[ $role['slug'] ] = $role['fallback'];
			}
		}
		return $fallbacks;
	}

	/**
	 * Tokens of a group, without $-prefixed group properties.
	 *
	 * @param array<mixed> $group Group node.
	 * @return array<string, array<string, mixed>>
	 */
	private static function tokens( array $group ): array {
		$tokens = array();
		foreach ( $group as $name => $token ) {
			if ( ! str_starts_with( (string) $name, '$' ) ) {
				$tokens[ (string) $name ] = is_array( $token ) ? $token : array();
			}
		}
		return $tokens;
	}

	/**
	 * Resolves aliases in a node and its children.
	 *
	 * @param array<mixed> $node Current node.
	 * @param array<mixed> $doc  Whole document.
	 * @return array<mixed>|WP_Error
	 */
	private static function walk( array $node, array $doc ) {
		if ( array_key_exists( '$value', $node ) ) {
			$value = self::alias( $node['$value'], $doc, array() );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$node['$value'] = $value;
			return $node;
		}
		foreach ( $node as $name => $child ) {
			if ( is_array( $child ) && ! str_starts_with( (string) $name, '$' ) ) {
				$child = self::walk( $child, $doc );
				if ( is_wp_error( $child ) ) {
					return $child;
				}
				$node[ $name ] = $child;
			}
		}
		return $node;
	}

	/**
	 * Follows one alias chain.
	 *
	 * @param mixed        $value Token value, an alias or a literal.
	 * @param array<mixed> $doc   Whole document.
	 * @param string[]     $seen  Paths already followed.
	 * @return mixed|WP_Error
	 * @phpstan-param list<string> $seen
	 */
	private static function alias( $value, array $doc, array $seen ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $part ) {
				$part = self::alias( $part, $doc, $seen );
				if ( is_wp_error( $part ) ) {
					return $part;
				}
				$value[ $key ] = $part;
			}
			return $value;
		}
		if ( ! is_string( $value ) || ! preg_match( self::ALIAS_FORMAT, $value, $match ) ) {
			return $value;
		}
		$path = $match[1];
		if ( in_array( $path, $seen, true ) ) {
			/* translators: %s: alias path. */
			return self::error( 'alias_cycle', __( 'The alias {%s} refers to itself.', 'leitvia-studio' ), $path );
		}
		if ( count( $seen ) >= self::ALIAS_DEPTH ) {
			/* translators: %s: alias path. */
			return self::error( 'alias_depth', __( 'The alias chain at {%s} is longer than 10 steps.', 'leitvia-studio' ), $path );
		}
		$target = $doc;
		foreach ( explode( '.', $path ) as $part ) {
			$target = is_array( $target ) ? ( $target[ $part ] ?? null ) : null;
		}
		if ( ! is_array( $target ) || ! array_key_exists( '$value', $target ) ) {
			/* translators: %s: alias path. */
			return self::error( 'alias_missing', __( 'The alias {%s} points to no token.', 'leitvia-studio' ), $path );
		}
		$seen[] = $path;
		return self::alias( $target['$value'], $doc, $seen );
	}

	/**
	 * Preset value of a resolved token. The Leitvia css extension wins for every type but color.
	 *
	 * @param string $type  DTCG type.
	 * @param mixed  $value Resolved $value.
	 * @param mixed  $css   Value of $extensions.com.leitvia.css.
	 * @return string|WP_Error
	 */
	private static function preset_value( string $type, $value, $css ) {
		if ( 'color' === $type ) {
			return self::hex( $value );
		}
		if ( is_string( $css ) && '' !== trim( $css ) ) {
			return self::is_safe_css( trim( $css ) )
				? trim( $css )
				: new WP_Error( 'leitvia_studio_dtcg_invalid_value', __( 'The CSS value contains characters or functions that are not allowed.', 'leitvia-studio' ) );
		}
		$built = self::css( $type, $value );
		if ( null === $built || ! self::is_safe_css( $built ) ) {
			/* translators: %s: DTCG type. */
			return new WP_Error( 'leitvia_studio_dtcg_invalid_value', sprintf( __( 'The value is not a valid %s.', 'leitvia-studio' ), $type ) );
		}
		return $built;
	}

	/**
	 * Whether a CSS value only uses allowed characters and functions, so it cannot load anything or break out of
	 * its declaration: no url(), image-set(), comments, at-rules, !important or line breaks; balanced parentheses
	 * and quotes; var() only for --wp-- custom properties.
	 *
	 * @param string $css CSS value.
	 */
	public static function is_safe_css( string $css ): bool {
		if ( '' === $css || ! preg_match( self::CSS_CHARS, $css ) || preg_match( self::CSS_DENY, $css ) ) {
			return false;
		}
		preg_match_all( '/([a-z-]*)\(/i', $css, $calls );
		foreach ( $calls[1] as $name ) {
			if ( '' !== $name && ! in_array( strtolower( $name ), self::CSS_FUNCS, true ) ) {
				return false;
			}
		}
		if ( preg_match( '/var\((?!--wp--)/i', $css ) ) {
			return false;
		}
		$depth = 0;
		$quote = '';
		foreach ( str_split( $css ) as $char ) {
			if ( '' !== $quote ) {
				if ( '(' === $char || ')' === $char ) {
					return false;
				}
				$quote = $char === $quote ? '' : $quote;
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			}
			$depth += ( '(' === $char ) - ( ')' === $char );
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth && '' === $quote;
	}

	/**
	 * Whether a fluid setting of a font size is a boolean or { min?, max? } with safe CSS lengths.
	 *
	 * @param mixed $fluid Fluid setting.
	 */
	private static function is_fluid( $fluid ): bool {
		if ( is_bool( $fluid ) ) {
			return true;
		}
		if ( ! is_array( $fluid ) || array_diff( array_keys( $fluid ), array( 'min', 'max' ) ) ) {
			return false;
		}
		foreach ( $fluid as $length ) {
			if ( ! is_string( $length ) || ! self::is_safe_css( $length ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * CSS text of a DTCG value, or null when the value does not fit the type.
	 *
	 * @param string $type  DTCG type: dimension, fontFamily or shadow.
	 * @param mixed  $value DTCG value.
	 */
	private static function css( string $type, $value ): ?string {
		if ( 'dimension' === $type ) {
			return self::dimension( $value );
		}
		if ( 'fontFamily' === $type ) {
			if ( is_string( $value ) ) {
				return $value;
			}
			if ( ! is_array( $value ) || ! $value || array_filter( $value, 'is_string' ) !== $value ) {
				return null;
			}
			return implode(
				', ',
				array_map( static fn( string $name ): string => preg_match( '/^[a-z0-9-]+$/iD', $name ) ? $name : '"' . str_replace( '"', '', $name ) . '"', $value )
			);
		}
		if ( 'shadow' === $type && is_array( $value ) ) {
			$parts = array();
			foreach ( array_is_list( $value ) ? $value : array( $value ) as $shadow ) {
				$part = is_array( $shadow ) ? self::shadow( $shadow ) : null;
				if ( null === $part ) {
					return null;
				}
				$parts[] = $part;
			}
			return $parts ? implode( ', ', $parts ) : null;
		}
		return null;
	}

	/**
	 * CSS of one DTCG shadow object.
	 *
	 * @param array<mixed> $shadow Shadow object.
	 */
	private static function shadow( array $shadow ): ?string {
		$parts = ! empty( $shadow['inset'] ) ? array( 'inset' ) : array();
		foreach ( array( 'offsetX', 'offsetY', 'blur', 'spread' ) as $key ) {
			$parts[] = self::dimension(
				$shadow[ $key ] ?? array(
					'value' => 0,
					'unit'  => 'px',
				)
			);
		}
		$hex = self::hex( $shadow['color'] ?? null );
		if ( is_wp_error( $hex ) || in_array( null, $parts, true ) ) {
			return null;
		}
		$alpha = $shadow['color']['alpha'] ?? 1;
		if ( is_numeric( $alpha ) && $alpha < 1 ) {
			$rgb = array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
			$hex = sprintf( 'rgb(%d %d %d / %s)', $rgb[0], $rgb[1], $rgb[2], self::number( (string) $alpha ) );
		}
		$parts[] = $hex;
		return implode( ' ', $parts );
	}

	/**
	 * CSS of a DTCG dimension, or null.
	 *
	 * @param mixed $value Dimension object.
	 */
	private static function dimension( $value ): ?string {
		if ( ! is_array( $value ) || ! is_numeric( $value['value'] ?? null ) || ! in_array( $value['unit'] ?? null, array( 'px', 'rem' ), true ) ) {
			return null;
		}
		return self::number( (string) $value['value'] ) . $value['unit'];
	}

	/**
	 * DTCG value and original CSS of a preset value from theme.json.
	 *
	 * @param string $type DTCG type.
	 * @param string $css  Preset value.
	 * @return array{value: mixed, css: string}
	 */
	private static function export_value( string $type, string $css ): array {
		switch ( $type ) {
			case 'color':
				$value = self::parse_color( $css );
				break;
			case 'fontFamily':
				$value = self::split( $css );
				break;
			case 'shadow':
				$value = self::parse_shadow_css( $css );
				break;
			default:
				// clamp() and other expressions keep their first plain length as $value.
				$number = preg_match( '/' . self::NUMBER_UNIT . '/', $css, $match ) ? $match : array( '', '0', 'px' );
				$value  = array(
					'value' => self::number( $number[1] ),
					'unit'  => $number[2],
				);
		}
		return array(
			'value' => $value,
			'css'   => $css,
		);
	}

	/**
	 * DTCG color of a CSS hex or rgb() color. Other notations become black.
	 *
	 * @param string $css CSS color.
	 * @return array<string, mixed>
	 */
	private static function parse_color( string $css ): array {
		$css = strtolower( trim( $css ) );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/D', $css, $match ) ) {
			$hex = 3 === strlen( $match[1] ) ? preg_replace( '/(.)/', '$1$1', $match[1] ) : $match[1];
			return array(
				'colorSpace' => 'srgb',
				'components' => array_map( static fn( string $pair ) => self::number( (string) round( hexdec( $pair ) / 255, 3 ) ), str_split( (string) $hex, 2 ) ),
				'hex'        => '#' . $hex,
			);
		}
		// ponytail: hex and rgb() only; named colors, hsl() and var() export as black. Add parsers when a theme uses them.
		preg_match_all( '/-?\d*\.?\d+/', $css, $numbers );
		$numbers = array_map( 'floatval', $numbers[0] );
		$color   = array(
			'colorSpace' => 'srgb',
			'components' => str_starts_with( $css, 'rgb' ) && count( $numbers ) >= 3
				? array_map( static fn( float $n ) => self::number( (string) round( min( max( $n, 0 ), 255 ) / 255, 3 ) ), array_slice( $numbers, 0, 3 ) )
				: array( 0, 0, 0 ),
		);
		if ( isset( $numbers[3] ) ) {
			$color['alpha'] = $numbers[3];
		}
		return $color;
	}

	/**
	 * Splits a CSS list at top-level commas and trims quotes, for font stacks and shadow lists.
	 *
	 * @param string $css CSS list.
	 * @return list<string>
	 */
	private static function split( string $css ): array {
		$parts = array();
		$depth = 0;
		$part  = '';
		foreach ( str_split( $css ) as $char ) {
			$depth += ( '(' === $char ) - ( ')' === $char );
			if ( ',' === $char && 0 === $depth ) {
				$parts[] = $part;
				$part    = '';
				continue;
			}
			$part .= $char;
		}
		$parts[] = $part;
		return array_values( array_filter( array_map( static fn( string $p ): string => trim( $p, " \t\n\"'" ), $parts ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Number from a numeric string, as int when whole.
	 *
	 * @param string $number Numeric string.
	 * @return int|float
	 */
	private static function number( string $number ) {
		$float = (float) $number;
		return floor( $float ) === $float ? (int) $float : $float;
	}

	/**
	 * Font faces bundled with the theme or its style variations, by lowercased first family name.
	 *
	 * @return array<string, array<mixed>>
	 */
	private static function bundled_fonts(): array {
		$sources = array( \WP_Theme_JSON_Resolver::get_theme_data()->get_settings() );
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			$sources[] = $variation['settings'] ?? array();
		}
		$fonts = array();
		foreach ( $sources as $settings ) {
			$families = $settings['typography']['fontFamilies'] ?? array();
			$families = $families['theme'] ?? $families;
			foreach ( is_array( $families ) ? $families : array() as $family ) {
				$first = strtolower( self::split( (string) ( $family['fontFamily'] ?? '' ) )[0] ?? '' );
				if ( '' !== $first && ! empty( $family['fontFace'] ) && ! isset( $fonts[ $first ] ) ) {
					$fonts[ $first ] = $family['fontFace'];
				}
			}
		}
		return $fonts;
	}

	/**
	 * Error with code prefix leitvia_studio_dtcg_ and status 422.
	 *
	 * @param string $code    Code without prefix.
	 * @param string $message Message with sprintf placeholders.
	 * @param string ...$args Placeholder values.
	 */
	private static function error( string $code, string $message, string ...$args ): WP_Error {
		$code = str_starts_with( $code, 'leitvia_studio_' ) ? $code : 'leitvia_studio_dtcg_' . $code;
		return new WP_Error( $code, vsprintf( $message, $args ), array( 'status' => 422 ) );
	}
}
