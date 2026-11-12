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

	/**
	 * Optional roles of the group size: token => layout setting, smallest and largest value in rem. Without them the
	 * widths of theme.json stay; size.content may not be wider than size.wide.
	 */
	public const SIZES = array(
		'content' => array( 'contentSize', 30, 80 ),
		'wide'    => array( 'wideSize', 60, 100 ),
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
	 * Group under color with up to MAX_EXTRA named surfaces without a role, palette slugs EXTRA-<slug>.
	 */
	public const EXTRA       = 'surface-extra';
	public const MAX_EXTRA   = 8;
	private const EXTRA_NAME = '/^[a-z]+(-[a-z]+)*$/D';

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
			// The extra surfaces of an activated set go back into the document, so a copy of it keeps them. Only presets
			// extra_surfaces() accepts (name, at most eight), so the copy can be imported again.
			foreach ( 'color' === $group ? $presets : array() as $slug => $preset ) {
				$name = substr( (string) $slug, strlen( self::EXTRA ) + 1 );
				if ( str_starts_with( (string) $slug, self::EXTRA . '-' ) && preg_match( self::EXTRA_NAME, $name ) && is_string( $preset['color'] ?? null )
					&& count( $doc['color'][ self::EXTRA ] ?? array() ) < self::MAX_EXTRA ) {
					$doc['color'][ self::EXTRA ][ $name ] = array(
						'$type'       => 'color',
						'$value'      => self::parse_color( $preset['color'] ),
						'$extensions' => array( self::EXT => array( 'name' => (string) ( $preset['name'] ?? $slug ) ) ),
					);
				}
			}
		}
		// The widths only when they differ from theme.json, so an export of the theme keeps following the theme.
		$theme = (array) ( \WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['layout'] ?? array() );
		foreach ( self::SIZES as $slug => list( $setting, $min, $max ) ) {
			$css = (string) ( $settings['layout'][ $setting ] ?? '' );
			if ( '' === $css || ( $theme[ $setting ] ?? null ) === $css || ! preg_match( '/^(\d*\.?\d+)rem$/D', $css, $match ) || $match[1] < $min || $match[1] > $max ) {
				continue;
			}
			$doc['size'][ $slug ] = array(
				'$type'  => 'dimension',
				'$value' => array(
					'value' => self::number( $match[1] ),
					'unit'  => 'rem',
				),
			);
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
		$raw = $doc;
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
			// The raw node, not the one of tokens(): a value that is no group must fail, not count as an empty group.
			$has_extra = 'color' === $group && array_key_exists( self::EXTRA, $tokens );
			$extras    = $has_extra ? $doc['color'][ self::EXTRA ] : null;
			if ( $has_extra ) {
				unset( $tokens[ self::EXTRA ] );
			}
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
			$presets = self::choices( $c, $group, $presets, $key, is_array( $raw[ $group ] ?? null ) ? $raw[ $group ] : array() );
			if ( is_wp_error( $presets ) ) {
				return $presets;
			}
			if ( $has_extra ) {
				$extras = self::extra_surfaces( $extras, $presets );
				if ( is_wp_error( $extras ) ) {
					return $extras;
				}
				$presets = array_merge( $presets, $extras );
			}
			$settings[ $section ][ $list ]['theme'] = $presets;
		}
		$layout = self::sizes( $doc['size'] ?? null, array_key_exists( 'size', $doc ) );
		if ( is_wp_error( $layout ) ) {
			return $layout;
		}
		return $settings + ( $layout ? array( 'layout' => $layout ) : array() );
	}

	/**
	 * Layout settings contentSize and wideSize from the group size (SIZES), empty without it.
	 *
	 * @param mixed $group Resolved node size.
	 * @param bool  $given Whether the document has the node.
	 * @return array<string, string>|WP_Error
	 */
	private static function sizes( $group, bool $given ) {
		if ( ! $given ) {
			return array();
		}
		if ( ! is_array( $group ) || array_key_exists( '$value', $group ) ) {
			/* translators: %s: token group, size. */
			return self::error( 'size_group', __( '%s must be a group of dimension tokens, not a single token.', 'leitvia-studio' ), 'size' );
		}
		$layout = array();
		$rem    = array();
		foreach ( self::tokens( $group ) as $slug => $token ) {
			if ( ! isset( self::SIZES[ $slug ] ) ) {
				/* translators: %s: token path, for example color.brand. */
				return self::error( 'unknown_role', __( 'The token %s is not a role of the theme contract.', 'leitvia-studio' ), "size.$slug" );
			}
			[ $setting, $min, $max ] = self::SIZES[ $slug ];
			$value                   = $token['$value'] ?? null;
			if ( ( isset( $token['$type'] ) && 'dimension' !== $token['$type'] ) || ! is_array( $value ) || 'rem' !== ( $value['unit'] ?? null ) || ! is_numeric( $value['value'] ?? null )
				|| $value['value'] < $min || $value['value'] > $max ) {
				/* translators: 1: token path, for example size.content, 2: smallest value, 3: largest value. */
				return self::error( 'size_range', __( 'The token %1$s must be a dimension in rem from %2$s to %3$s.', 'leitvia-studio' ), "size.$slug", (string) $min, (string) $max );
			}
			$rem[ $slug ]       = (float) $value['value'];
			$layout[ $setting ] = (string) self::dimension( $value );
		}
		if ( isset( $rem['content'], $rem['wide'] ) && $rem['content'] > $rem['wide'] ) {
			return self::error( 'size_order', __( 'The token size.content may not be wider than size.wide.', 'leitvia-studio' ) );
		}
		return $layout;
	}

	/**
	 * Palette presets of the group color.surface-extra: up to eight surfaces, slugs of lowercase letters and hyphens,
	 * each readable with ink at 4.5:1 since text on them stays ink. They get no role, so no contract pair covers them.
	 *
	 * @param mixed                      $group Node color.surface-extra, a group of color tokens.
	 * @param list<array<string, mixed>> $roles Color presets of the roles.
	 * @return list<array<string, string>>|WP_Error
	 */
	private static function extra_surfaces( $group, array $roles ) {
		$path = 'color.' . self::EXTRA;
		if ( ! is_array( $group ) || array_key_exists( '$value', $group ) ) {
			/* translators: %s: token group, color.surface-extra. */
			return self::error( 'extra_group', __( '%s must be a group of color tokens, not a single token.', 'leitvia-studio' ), $path );
		}
		// The role presets hold ink as #rrggbb (hex() above), so ink is only missing when the contract has no such role.
		// Then the surfaces cannot be checked and are refused.
		$ink = array_column( $roles, 'color', 'slug' )['ink'] ?? null;
		if ( ! is_string( $ink ) ) {
			/* translators: 1: foreground role, 2: background role, 3: missing role. */
			return self::error( 'extra_contrast', __( '%1$s on %2$s: the color role %3$s is missing', 'leitvia-studio' ), 'ink', $path, 'ink' );
		}
		$tokens = self::tokens( $group );
		if ( count( $tokens ) > self::MAX_EXTRA ) {
			/* translators: 1: token group, 2: maximum number of colors. */
			return self::error( 'extra_limit', __( 'The group %1$s holds more than %2$s colors.', 'leitvia-studio' ), $path, (string) self::MAX_EXTRA );
		}
		$presets = array();
		foreach ( $tokens as $slug => $token ) {
			$slug = (string) $slug;
			$path = 'color.' . self::EXTRA . '.' . $slug;
			if ( ! preg_match( self::EXTRA_NAME, $slug ) ) {
				/* translators: %s: token path, for example color.surface-extra.mint. */
				return self::error( 'extra_slug', __( 'The token %s needs a name of lowercase letters and hyphens.', 'leitvia-studio' ), $path );
			}
			if ( isset( $token['$type'] ) && 'color' !== $token['$type'] ) {
				/* translators: %s: token path, for example color.focus. */
				return self::error( 'type', __( 'The token %s has the wrong $type.', 'leitvia-studio' ), $path );
			}
			$hex = self::hex( $token['$value'] ?? null );
			if ( is_wp_error( $hex ) ) {
				/* translators: 1: token path, 2: error message. */
				return self::error( (string) $hex->get_error_code(), __( 'The token %1$s is invalid: %2$s', 'leitvia-studio' ), $path, $hex->get_error_message() );
			}
			// Text on these surfaces stays ink, so ink has to be readable on each of them.
			$ratio = Contrast::ratio( $ink, $hex );
			$ratio = is_wp_error( $ratio ) ? 0.0 : $ratio;
			if ( $ratio < 4.5 ) {
				/* translators: 1: token path, 2: contrast ratio. */
				return self::error( 'extra_contrast', __( 'ink on %1$s: %2$s:1, needs 4.5:1', 'leitvia-studio' ), $path, number_format_i18n( floor( $ratio * 100 ) / 100, 2 ) );
			}
			$name      = $token['$extensions'][ self::EXT ]['name'] ?? null;
			$presets[] = array(
				'slug'  => self::EXTRA . '-' . $slug,
				'name'  => is_string( $name ) ? sanitize_text_field( $name ) : $slug,
				'color' => $hex,
			);
		}
		return $presets;
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
		return (string) wp_json_encode( self::plain( json_decode( $value ) ), self::JSON_FLAGS );
	}

	/**
	 * Every string of a decoded document as plain text, keys and structure unchanged: token values and descriptions
	 * never hold tags or line breaks.
	 *
	 * @param mixed $node Decoded JSON.
	 * @return mixed
	 */
	private static function plain( $node ) {
		if ( is_string( $node ) ) {
			return sanitize_text_field( $node );
		}
		if ( is_array( $node ) ) {
			return array_map( array( self::class, 'plain' ), $node );
		}
		if ( $node instanceof \stdClass ) {
			foreach ( get_object_vars( $node ) as $key => $child ) {
				$node->$key = self::plain( $child );
			}
		}
		return $node;
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
	 * A role with the contract field "choices" takes the value of one of those roles of its group: radius.media is
	 * radius.none, s, m or l, never a radius of its own. An alias such as "{radius.m}" takes the preset value of its
	 * target (the target may carry a css extension the alias does not see); a literal value, as in an exported set,
	 * must equal the value of one of the choices.
	 *
	 * @param Contract                   $c       Contract of the active theme.
	 * @param string                     $group   Role group.
	 * @param list<array<string, mixed>> $presets Presets of the group, built above.
	 * @param string                     $key     Preset value key, for example size.
	 * @param array<mixed>               $raw     The group in the document before aliases were resolved.
	 * @return list<array<string, mixed>>|WP_Error
	 */
	private static function choices( Contract $c, string $group, array $presets, string $key, array $raw ) {
		$values = array_column( $presets, $key, 'slug' );
		foreach ( (array) ( $c->data()['roles'][ $group ] ?? array() ) as $role ) {
			$slug    = is_string( $role['slug'] ?? null ) ? $role['slug'] : '';
			$choices = is_array( $role['choices'] ?? null ) ? array_map( 'strval', $role['choices'] ) : null;
			$index   = array_search( $slug, array_column( $presets, 'slug' ), true );
			if ( null === $choices || false === $index ) {
				continue;
			}
			$alias  = is_string( $raw[ $slug ]['$value'] ?? null ) && preg_match( '/^\{' . preg_quote( $group, '/' ) . '\.([a-z0-9-]+)\}$/D', $raw[ $slug ]['$value'], $m ) ? $m[1] : null;
			$target = null !== $alias && in_array( $alias, $choices, true ) ? $alias : array_search( $values[ $slug ], array_intersect_key( $values, array_flip( $choices ) ), true );
			if ( ! is_string( $target ) || ! isset( $values[ $target ] ) ) {
				return self::error(
					'invalid_choice',
					/* translators: 1: token path, for example radius.media, 2: list of token paths. */
					__( 'The token %1$s must take the value of one of %2$s.', 'leitvia-studio' ),
					"$group.$slug",
					implode( ', ', array_map( static fn( string $choice ): string => "$group.$choice", $choices ) )
				);
			}
			$presets[ $index ][ $key ] = $values[ $target ];
		}
		return $presets;
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
	 * Font faces bundled with the theme, its style variations or Leitvia Studio, or uploaded to the Font Library of
	 * this site, by lowercased first family name.
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
		$fonts += self::studio_fonts() + self::library_fonts();
		/**
		 * Filters the font faces a token set can use, by lowercased first family name. An add-on that ships its own
		 * fonts adds them here with absolute URLs of this site, so a token set that names the family gets the font
		 * faces instead of the system fallback. Faces of the theme win: add-ons only add families the theme lacks.
		 *
		 * @param array<string, array<mixed>> $fonts Font faces by lowercased family name.
		 */
		return $fonts + (array) apply_filters( 'leitvia_studio_bundled_fonts', $fonts );
	}

	/**
	 * Font faces Leitvia Studio ships for token sets (assets/fonts/fonts.json), with URLs of this site, by lowercased
	 * family name.
	 *
	 * @return array<string, array<mixed>>
	 */
	private static function studio_fonts(): array {
		$data  = wp_json_file_decode( dirname( LEITVIA_STUDIO_FILE ) . '/assets/fonts/fonts.json', array( 'associative' => true ) );
		$fonts = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['families'] ?? array() ) : array() ) as $family => $faces ) {
			$fonts[ strtolower( (string) $family ) ] = array_map(
				static function ( $face ): array {
					$face        = (array) $face;
					$face['src'] = array_map( static fn( $src ): string => plugins_url( 'assets/fonts/' . $src, LEITVIA_STUDIO_FILE ), (array) ( $face['src'] ?? array() ) );
					return $face;
				},
				(array) $faces
			);
		}
		return $fonts;
	}

	/**
	 * Font faces of the Font Library of WordPress: files on this site (wp_font_dir()), so a brand font a person or
	 * operator uploaded needs no other host. By lowercased first family name; families without a face are left out.
	 *
	 * @return array<string, array<mixed>>
	 */
	private static function library_fonts(): array {
		$posts = static fn( array $args ): array => array_filter(
			get_posts(
				$args + array(
					'post_status' => 'publish',
					'numberposts' => -1,
				)
			),
			static fn( $post ): bool => $post instanceof \WP_Post
		);
		$fonts = array();
		foreach ( $posts( array( 'post_type' => 'wp_font_family' ) ) as $family ) {
			$settings = json_decode( $family->post_content, true );
			$first    = strtolower( self::split( (string) ( $settings['fontFamily'] ?? '' ) )[0] ?? '' );
			$faces    = array();
			foreach ( $posts(
				array(
					'post_type'   => 'wp_font_face',
					'post_parent' => $family->ID,
				)
			) as $face ) {
				$face = json_decode( $face->post_content, true );
				if ( ! empty( $face['src'] ) ) {
					$face['src'] = (array) $face['src'];
					$faces[]     = $face;
				}
			}
			if ( '' !== $first && $faces ) {
				$fonts[ $first ] = $faces;
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
