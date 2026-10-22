/**
 * WCAG 2.2 relative luminance and contrast ratio. Colors: #rgb, #rrggbb, rgb(), rgba(), hsl() and hsla() in comma or
 * space syntax, without transparency. src/lib/contrast.mjs and includes/tokens/class-contrast.php follow the same steps,
 * tests/fixtures/contrast-cases.json keeps them in step.
 *
 * @param {number} c Channel value 0 to 255.
 */
const channel = ( c ) => {
	const s = c / 255;
	return s <= 0.04045 ? s / 12.92 : ( ( s + 0.055 ) / 1.055 ) ** 2.4;
};

const clamp = ( x, max ) => Math.min( max, Math.max( 0, x ) );

/**
 * Channels [ r, g, b ] from 0 to 255, or null for an unsupported or transparent color.
 *
 * @param {string} value CSS color.
 * @return {number[]|null} Channels.
 */
export const parseColor = ( value ) => {
	const v = String( value ).trim().toLowerCase();
	const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/.exec( v );
	if ( hex ) {
		const h =
			hex[ 1 ].length === 3
				? [ ...hex[ 1 ] ].map( ( c ) => c + c ).join( '' )
				: hex[ 1 ];
		return [ 0, 2, 4 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) );
	}
	const fn = /^(rgb|hsl)a?\(([^()]*)\)$/.exec( v );
	if ( ! fn ) {
		return null;
	}
	const args = fn[ 2 ]
		.split( /[\s,/]+/ )
		.filter( Boolean )
		.map( ( a ) => /^(-?\d*\.?\d+)(%|deg)?$/.exec( a ) );
	const units =
		fn[ 1 ] === 'rgb' ? [ '%', '%', '%', '%' ] : [ 'deg', '%', '%', '%' ];
	if (
		args.length < 3 ||
		args.length > 4 ||
		args.some( ( a, i ) => ! a || ( a[ 2 ] && a[ 2 ] !== units[ i ] ) )
	) {
		return null;
	}
	const num = args.map( ( a ) => Number( a[ 1 ] ) );
	if (
		args.length === 4 &&
		( args[ 3 ][ 2 ] ? num[ 3 ] / 100 : num[ 3 ] ) < 1
	) {
		return null;
	}
	if ( fn[ 1 ] === 'rgb' ) {
		return [ 0, 1, 2 ].map( ( i ) =>
			clamp( args[ i ][ 2 ] ? ( num[ i ] * 255 ) / 100 : num[ i ], 255 )
		);
	}
	const h = ( ( num[ 0 ] % 360 ) + 360 ) % 360;
	const s = clamp( num[ 1 ], 100 ) / 100;
	const l = clamp( num[ 2 ], 100 ) / 100;
	const f = ( n ) => {
		const k = ( n + h / 30 ) % 12;
		return (
			l -
			s *
				Math.min( l, 1 - l ) *
				Math.max( -1, Math.min( k - 3, 9 - k, 1 ) )
		);
	};
	return [ f( 0 ) * 255, f( 8 ) * 255, f( 4 ) * 255 ];
};

const rgbLuminance = ( [ r, g, b ] ) =>
	0.2126 * channel( r ) + 0.7152 * channel( g ) + 0.0722 * channel( b );

const ratioOf = ( la, lb ) =>
	( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );

export const luminance = ( color ) => {
	const rgb = parseColor( color );
	if ( ! rgb ) {
		throw new Error(
			`Unsupported color "${ color }", use #rgb, #rrggbb, rgb() or hsl()`
		);
	}
	return rgbLuminance( rgb );
};

export const contrast = ( a, b ) => ratioOf( luminance( a ), luminance( b ) );

/**
 * Role pairs that must pass. min: 4.5 for text, 3 for UI components and focus indicators.
 * ring: the focus indicator is a double ring (outline in focus, inner ring in surface, see core--button.css).
 * Accent needs 4.5:1 on surface and focus 3:1 on surface, so no single focus color can also reach 3:1 on accent
 * or ink. On those backgrounds either ring may carry the contrast: this follows the WCAG 2.4.13 reading that the
 * focused pixels must change by 3:1 against their unfocused state, and the ring pixels were the background before.
 * Hard rule for ring pairs: the outline itself must reach 3:1 against the inner ring (ringRatio), otherwise the two
 * rings merge into one. The inner ring must also stay distinct from the fill of the focused component; surface
 * works because every component that sits on accent or ink uses accent, ink or transparent fills.
 * accent on surface-alt and stroke on ink: patterns set card labels in accent on surface-alt
 * and eyebrow text in stroke on ink sections.
 */
export const REQUIRED_PAIRS = [
	{ fg: 'ink', bg: 'surface', min: 4.5 },
	{ fg: 'ink', bg: 'surface-alt', min: 4.5 },
	{ fg: 'ink-muted', bg: 'surface', min: 4.5 },
	{ fg: 'ink-muted', bg: 'surface-alt', min: 4.5 },
	{ fg: 'accent', bg: 'surface', min: 4.5 },
	{ fg: 'accent', bg: 'surface-alt', min: 4.5 },
	{ fg: 'on-accent', bg: 'accent', min: 4.5 },
	{ fg: 'focus', bg: 'surface', min: 3 },
	{ fg: 'focus', bg: 'surface-alt', min: 3 },
	{ fg: 'focus', bg: 'accent', min: 3, ring: 'surface' },
	{ fg: 'focus', bg: 'ink', min: 3, ring: 'surface' },
	{ fg: 'stroke', bg: 'ink', min: 4.5 },
	{ fg: 'stroke', bg: 'surface', min: 1.3 },
	{ fg: 'success', bg: 'surface', min: 4.5 },
	{ fg: 'warning', bg: 'surface', min: 4.5 },
	{ fg: 'danger', bg: 'surface', min: 4.5 },
];

export const COLOR_ROLES = [
	'surface',
	'surface-alt',
	'ink',
	'ink-muted',
	'accent',
	'on-accent',
	'stroke',
	'focus',
	'success',
	'warning',
	'danger',
];

/**
 * Failing pairs [{ fg, bg, ratio, min, ringRatio? }] for a palette array [{ slug, color }], in pair order.
 * A missing role fails with ratio 0. Colors may reference other presets, var(--wp--preset--color--slug); a color that
 * cannot be read (currentColor, other variables, transparency) gives ratio null: not checkable, reported, not failed.
 *
 * @param {Array<{slug: string, color: string}>} palette Palette presets.
 * @param {Array<Object>}                        pairs   Contrast pairs, defaults to REQUIRED_PAIRS.
 */
export const checkPalette = ( palette, pairs = REQUIRED_PAIRS ) => {
	const map = Object.fromEntries(
		palette
			.filter( ( p ) => 'color' in p )
			.map( ( p ) => [ p.slug, p.color ] )
	);
	const has = ( slug ) => Object.hasOwn( map, slug );
	const rgbOf = ( slug ) => {
		let value = map[ slug ];
		for ( let depth = 0; depth < 10; depth++ ) {
			const ref =
				/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/i.exec(
					String( value ).trim()
				);
			if ( ! ref ) {
				break;
			}
			value = has( ref[ 1 ] ) ? map[ ref[ 1 ] ] : null;
		}
		return parseColor( value );
	};
	const ratio = ( a, b ) => {
		if ( ! has( a ) || ! has( b ) ) {
			return 0;
		}
		const [ x, y ] = [ rgbOf( a ), rgbOf( b ) ];
		return x && y ? ratioOf( rgbLuminance( x ), rgbLuminance( y ) ) : null;
	};
	const result = ( pair ) => {
		const { fg, bg, ring } = pair;
		if ( ! ring ) {
			return { ...pair, ratio: ratio( fg, bg ) };
		}
		const parts = [ ratio( fg, bg ), ratio( ring, bg ), ratio( fg, ring ) ];
		if ( parts.includes( null ) ) {
			return { ...pair, ratio: null, ringRatio: null };
		}
		return {
			...pair,
			ratio: Math.max( parts[ 0 ], parts[ 1 ] ),
			ringRatio: parts[ 2 ],
		};
	};
	return pairs
		.map( result )
		.filter(
			( r ) => r.ratio === null || r.ratio < r.min || r.ringRatio < 3
		);
};
