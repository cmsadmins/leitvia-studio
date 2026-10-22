<?php
/**
 * WCAG 2.2 contrast of palette roles, the PHP twin of src/lib/contrast.mjs.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Same steps as src/lib/contrast.mjs; tests/fixtures/contrast-cases.json keeps both in step.
 */
final class Contrast {

	/**
	 * Channels [ r, g, b ] from 0 to 255 of #rgb, #rrggbb, rgb(), rgba(), hsl() or hsla(), comma or space syntax.
	 * Null for anything else and for transparent colors. Same steps as parseColor() in contrast.mjs.
	 *
	 * @param mixed $value CSS color.
	 * @return list<float>|null
	 */
	public static function parse( $value ): ?array {
		$v = strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/D', $v, $hex ) ) {
			$h = 3 === strlen( $hex[1] ) ? $hex[1][0] . $hex[1][0] . $hex[1][1] . $hex[1][1] . $hex[1][2] . $hex[1][2] : $hex[1];
			return array_map( static fn( string $c ): float => (float) hexdec( $c ), str_split( $h, 2 ) );
		}
		if ( ! preg_match( '/^(rgb|hsl)a?\(([^()]*)\)$/D', $v, $fn ) ) {
			return null;
		}
		$parts = (array) preg_split( '#[\s,/]+#', $fn[2], -1, PREG_SPLIT_NO_EMPTY );
		$units = 'rgb' === $fn[1] ? array( '%', '%', '%', '%' ) : array( 'deg', '%', '%', '%' );
		$count = count( $parts );
		if ( $count < 3 || $count > 4 ) {
			return null;
		}
		$num  = array();
		$unit = array();
		foreach ( $parts as $i => $part ) {
			if ( ! preg_match( '/^(-?\d*\.?\d+)(%|deg)?$/D', (string) $part, $arg ) || ( ! empty( $arg[2] ) && $arg[2] !== $units[ $i ] ) ) {
				return null;
			}
			$num[]  = (float) $arg[1];
			$unit[] = $arg[2] ?? '';
		}
		if ( 4 === $count && ( '' !== $unit[3] ? $num[3] / 100 : $num[3] ) < 1 ) {
			return null;
		}
		$clamp = static fn( float $x, float $max ): float => min( $max, max( 0.0, $x ) );
		if ( 'rgb' === $fn[1] ) {
			return array_map( static fn( int $i ): float => $clamp( '' !== $unit[ $i ] ? $num[ $i ] * 255 / 100 : $num[ $i ], 255.0 ), array( 0, 1, 2 ) );
		}
		$h = fmod( fmod( $num[0], 360 ) + 360, 360 );
		$s = $clamp( $num[1], 100.0 ) / 100;
		$l = $clamp( $num[2], 100.0 ) / 100;
		$f = static function ( int $n ) use ( $h, $s, $l ): float {
			$k = fmod( $n + $h / 30, 12 );
			return $l - $s * min( $l, 1 - $l ) * max( -1, min( $k - 3, 9 - $k, 1 ) );
		};
		return array( $f( 0 ) * 255, $f( 8 ) * 255, $f( 4 ) * 255 );
	}

	/**
	 * Relative luminance of a color.
	 *
	 * @param string $color Color, see parse().
	 * @return float|WP_Error
	 */
	public static function luminance( string $color ) {
		$rgb = self::parse( $color );
		if ( null === $rgb ) {
			/* translators: %s: color value. */
			return new WP_Error( 'leitvia_studio_color_format', sprintf( __( 'Unsupported color "%s", use #rgb, #rrggbb, rgb() or hsl().', 'leitvia-studio' ), $color ) );
		}
		return self::rgb_luminance( $rgb );
	}

	/**
	 * Contrast ratio of two colors, 1 to 21.
	 *
	 * @param string $a Color.
	 * @param string $b Color.
	 * @return float|WP_Error
	 */
	public static function ratio( string $a, string $b ) {
		$la = self::luminance( $a );
		if ( is_wp_error( $la ) ) {
			return $la;
		}
		$lb = self::luminance( $b );
		if ( is_wp_error( $lb ) ) {
			return $lb;
		}
		return self::ratio_of( $la, $lb );
	}

	/**
	 * Failing pairs, same rules as checkPalette() in src/lib/contrast.mjs including the double focus ring.
	 * A missing role fails with ratio 0. Colors may reference presets, var(--wp--preset--color--slug); a color that
	 * cannot be read gives ratio null: not checkable, reported, not failed.
	 *
	 * @param array<int, array<string, mixed>>                                         $palette Palette presets.
	 * @param array<int, array{fg: string, bg: string, min: float|int, ring?: string}> $pairs   Contract pairs.
	 * @return list<array<string, mixed>> Pairs with ratio (and ring_ratio), in pair order.
	 */
	public static function check_palette( array $palette, array $pairs ): array {
		$map      = array_column( $palette, 'color', 'slug' );
		$rgb_of   = static function ( string $slug ) use ( $map ): ?array {
			$value = $map[ $slug ];
			for ( $depth = 0; $depth < 10; $depth++ ) {
				if ( ! preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/iD', trim( is_scalar( $value ) ? (string) $value : '' ), $ref ) ) {
					break;
				}
				$value = array_key_exists( $ref[1], $map ) ? $map[ $ref[1] ] : null;
			}
			return self::parse( $value );
		};
		$ratio    = static function ( string $a, string $b ) use ( $map, $rgb_of ): ?float {
			if ( ! array_key_exists( $a, $map ) || ! array_key_exists( $b, $map ) ) {
				return 0.0;
			}
			$x = $rgb_of( $a );
			$y = $rgb_of( $b );
			return null === $x || null === $y ? null : self::ratio_of( self::rgb_luminance( $x ), self::rgb_luminance( $y ) );
		};
		$failures = array();
		foreach ( $pairs as $pair ) {
			$result = $pair + array( 'ratio' => $ratio( $pair['fg'], $pair['bg'] ) );
			if ( isset( $pair['ring'] ) ) {
				$parts                = array( $result['ratio'], $ratio( $pair['ring'], $pair['bg'] ), $ratio( $pair['fg'], $pair['ring'] ) );
				$checkable            = ! in_array( null, $parts, true );
				$result['ratio']      = $checkable ? max( $parts[0], $parts[1] ) : null;
				$result['ring_ratio'] = $checkable ? $parts[2] : null;
			}
			if ( null === $result['ratio'] || $result['ratio'] < $pair['min'] || ( $result['ring_ratio'] ?? 3 ) < 3 ) {
				$failures[] = $result;
			}
		}
		return $failures;
	}

	/**
	 * Registers the check leitvia/contrast. Runs on leitvia_studio_init.
	 *
	 * @param \LeitviaStudio\Extension\Studio $studio Studio.
	 */
	public static function register_check( \LeitviaStudio\Extension\Studio $studio ): void {
		$studio->registry( 'checks' )->register(
			'leitvia/contrast',
			array(
				'label'    => __( 'Color contrast', 'leitvia-studio' ),
				'scope'    => 'token_set',
				'callback' => array( self::class, 'check_token_set' ),
			),
			'leitvia-studio'
		);
	}

	/**
	 * Check leitvia/contrast (scope token_set): contract pairs on the palette of a token document.
	 *
	 * @param array<string, mixed> $doc Token document.
	 * @return list<array<string, mixed>>
	 */
	public static function check_token_set( array $doc ): array {
		$contract = leitvia_studio_contract();
		$settings = Dtcg::to_settings( $doc, $contract );
		if ( is_wp_error( $settings ) ) {
			return array();
		}
		return self::findings( $settings['color']['palette']['theme'] ?? array(), $contract->pairs() );
	}

	/**
	 * Failing pairs as check findings: errors for failing and missing roles, warnings for colors that cannot be read.
	 * Ratios are rounded down, so 4.496 never shows as 4.50.
	 *
	 * @param array<int, array<string, mixed>>                                         $palette Palette presets.
	 * @param array<int, array{fg: string, bg: string, min: float|int, ring?: string}> $pairs   Contract pairs.
	 * @return list<array<string, mixed>>
	 */
	public static function findings( array $palette, array $pairs ): array {
		$map      = array_column( $palette, 'color', 'slug' );
		$floor    = static fn( float $ratio ): float => floor( $ratio * 100 ) / 100;
		$findings = array();
		foreach ( self::check_palette( $palette, $pairs ) as $f ) {
			$data = array(
				'fg'    => $f['fg'],
				'bg'    => $f['bg'],
				'ratio' => null === $f['ratio'] ? null : $floor( $f['ratio'] ),
				'min'   => $f['min'],
			);
			if ( null === $f['ratio'] ) {
				$findings[] = array(
					'code'     => 'contrast_unchecked',
					'severity' => 'warning',
					/* translators: 1: foreground role, 2: background role. */
					'message'  => sprintf( __( '%1$s on %2$s: unsupported color format, contrast not checked', 'leitvia-studio' ), $f['fg'], $f['bg'] ),
					'data'     => $data,
				);
				continue;
			}
			$missing = array_values( array_diff( array_filter( array( $f['fg'], $f['bg'], $f['ring'] ?? '' ) ), array_keys( $map ) ) );
			if ( $missing ) {
				/* translators: 1: foreground role, 2: background role, 3: missing role. */
				$message = sprintf( __( '%1$s on %2$s: the color role %3$s is missing', 'leitvia-studio' ), $f['fg'], $f['bg'], $missing[0] );
			} elseif ( isset( $f['ring_ratio'] ) && $f['ring_ratio'] < 3 ) {
				/* translators: 1: foreground role, 2: ring role, 3: contrast ratio. */
				$message = sprintf( __( '%1$s against the inner ring %2$s: %3$s:1, needs 3:1', 'leitvia-studio' ), $f['fg'], $f['ring'], number_format_i18n( $floor( $f['ring_ratio'] ), 2 ) );
			} else {
				/* translators: 1: foreground role, 2: background role, 3: contrast ratio, 4: required ratio. */
				$message = sprintf( __( '%1$s on %2$s: %3$s:1, needs %4$s:1', 'leitvia-studio' ), $f['fg'], $f['bg'], number_format_i18n( $floor( $f['ratio'] ), 2 ), $f['min'] );
			}
			$findings[] = array(
				'code'     => 'contrast',
				'severity' => 'error',
				'message'  => $message,
				'data'     => $data,
			);
		}
		return $findings;
	}

	/**
	 * Relative luminance of channels from parse().
	 *
	 * @param float[] $rgb Channels.
	 */
	private static function rgb_luminance( array $rgb ): float {
		$channel = static function ( float $c ): float {
			$s = $c / 255;
			return $s <= 0.04045 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
	}

	/**
	 * Contrast ratio of two luminances.
	 *
	 * @param float $la Luminance.
	 * @param float $lb Luminance.
	 */
	private static function ratio_of( float $la, float $lb ): float {
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}
}
