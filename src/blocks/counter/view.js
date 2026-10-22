// Counter (leitvia/counter): a copy hidden from assistive technology counts up over the end value, which stays in the
// text for screen readers. Only with JavaScript and without reduced motion; transparency only, so nothing moves.
const DURATION = 1500;

function count( value ) {
	const lang = document.documentElement.lang || undefined;
	const decimal = ( 1.5 ).toLocaleString( lang ).charAt( 1 );
	const text = value.textContent.trim();
	const [ whole, fraction = '' ] = text.split( decimal );
	const end = parseFloat(
		`${ whole.replace( /\D/g, '' ) }.${ fraction.replace( /\D/g, '' ) }`
	);
	if ( ! Number.isFinite( end ) ) {
		return;
	}
	// ponytail: assumes the end value uses the separators of the page language; otherwise the copy shows them
	// differently while counting and the end value takes over when done.
	const format = new Intl.NumberFormat( lang, {
		minimumFractionDigits: fraction.length,
		maximumFractionDigits: fraction.length,
		useGrouping: /\D/.test( whole ),
	} );
	const copy = document.createElement( 'span' );
	copy.className = 'wp-block-leitvia-counter__count';
	copy.setAttribute( 'aria-hidden', 'true' );
	copy.textContent = format.format( 0 );
	copy.style.color = window.getComputedStyle( value ).color;
	value.style.color = 'transparent';
	value.append( copy );
	const start = performance.now();
	const step = ( now ) => {
		const t = Math.min( 1, ( now - start ) / DURATION );
		copy.textContent = format.format( end * ( 1 - ( 1 - t ) ** 3 ) );
		if ( t < 1 ) {
			window.requestAnimationFrame( step );
		} else {
			copy.remove();
			value.style.color = '';
		}
	};
	window.requestAnimationFrame( step );
}

if ( ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
	const observer = new window.IntersectionObserver(
		( entries ) => {
			for ( const { target, isIntersecting } of entries ) {
				if ( isIntersecting ) {
					observer.unobserve( target );
					count( target );
				}
			}
		},
		{ threshold: 1 }
	);
	document
		.querySelectorAll( '.wp-block-leitvia-counter__value' )
		.forEach( ( value ) => observer.observe( value ) );
}
