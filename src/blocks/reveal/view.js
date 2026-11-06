// Reveal (leitvia/reveal) and the animation of any block (has-lv-animation, includes/blocks/class-animation.php): hides
// only what starts below the visible area and lets it appear once when it has clearly scrolled in (style.scss). What
// is in view on load stays untouched. Nothing happens with reduced motion; without JavaScript the content is simply
// there.
//
// Timing as with ScrollReveal: a block counts as in view once a fifth of it is above a line 12 % over the bottom edge
// of the window; a block taller than the window needs only a third of the window height (0.3 of it, as a share of the
// block), so tall blocks start as well. One after another watches each direct child on its own, so stacked cards on a
// phone come one by one; children that come into view together start 100 ms apart (--leitvia-reveal-step).
// Keyboard focus inside a hidden block and a jump to an anchor show the block at once, without the effect: the target
// and everything above it.
//
// Blocks that swap content in without a page load name the new elements in the event leitvia:content on the document
// (query-filter, load-more); those below the visible area appear the same way.
const ANIMATED = '.wp-block-leitvia-reveal, .has-lv-animation';
const PENDING = 'is-lv-pending';
const STAGGER =
	'.wp-block-leitvia-reveal.is-effect-stagger, .has-lv-animation.is-lv-animation-stagger';

if ( ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
	const observer = new window.IntersectionObserver(
		( entries ) => {
			let step = 0;
			for ( const {
				target,
				isIntersecting,
				intersectionRatio,
				boundingClientRect,
			} of entries ) {
				if (
					isIntersecting &&
					intersectionRatio >=
						Math.min(
							0.2,
							( window.innerHeight * 0.3 ) /
								boundingClientRect.height
						)
				) {
					observer.unobserve( target );
					if ( target.parentElement.matches( STAGGER ) ) {
						target.style.setProperty(
							'--leitvia-reveal-step',
							Math.min( step++, 5 )
						);
					}
					target.classList.replace( PENDING, 'is-lv-revealed' );
				}
			}
		},
		{
			rootMargin: '0px 0px -12% 0px',
			threshold: [ 0.02, 0.05, 0.1, 0.15, 0.2 ],
		}
	);
	// Shown at once and for good, without the effect.
	const show = ( el ) => {
		observer.unobserve( el );
		el.classList.remove( PENDING );
	};
	// Measure all first, then write, so the page lays out once.
	const hide = ( roots ) => {
		const below = roots
			.flatMap( ( root ) => [
				...root.querySelectorAll( ANIMATED ),
				...( root.matches?.( ANIMATED ) ? [ root ] : [] ),
				...( root.parentElement?.matches( STAGGER ) ? [ root ] : [] ),
			] )
			.flatMap( ( el ) =>
				el.matches( STAGGER ) ? [ ...el.children ] : el
			)
			.filter(
				( el ) => el.getBoundingClientRect().top >= window.innerHeight
			);
		for ( const el of below ) {
			el.classList.add( PENDING );
			observer.observe( el );
		}
	};
	hide( [ document ] );
	document.addEventListener( 'leitvia:content', ( { detail } ) =>
		hide( detail )
	);
	// A jump to an anchor (link in the page, hash in the address) shows the target and every hidden block that starts
	// above its end: the blocks above it, those inside it and those beside it in a row. Measured before the browser
	// scrolls, so they never fade in.
	const jump = ( hash ) => {
		let end;
		try {
			end = document
				.getElementById( decodeURIComponent( hash.slice( 1 ) ) )
				?.getBoundingClientRect().bottom;
		} catch {
			// A malformed hash names no target.
		}
		for ( const el of end
			? document.querySelectorAll( `.${ PENDING }` )
			: [] ) {
			if ( el.getBoundingClientRect().top < end ) {
				show( el );
			}
		}
	};
	jump( window.location.hash );
	window.addEventListener( 'hashchange', () => jump( window.location.hash ) );
	document.addEventListener( 'click', ( { target } ) => {
		const link = target.closest?.( 'a[href*="#"]' );
		if (
			link?.hash &&
			link.pathname === window.location.pathname &&
			link.host === window.location.host
		) {
			jump( link.hash );
		}
	} );
	// Keyboard focus inside a hidden block shows it at once (focus visible).
	document.addEventListener( 'focusin', ( { target } ) => {
		const el = target.closest?.( `.${ PENDING }` );
		if ( el ) {
			show( el );
		}
	} );
}
