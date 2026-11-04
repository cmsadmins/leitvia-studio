// Reveal (leitvia/reveal) and the animation of any block (has-lv-animation, includes/blocks/class-animation.php): hides
// only the blocks that start below the visible area and lets each appear once when it scrolls in (style.scss). What
// is in view on load stays untouched. Nothing happens with reduced motion; without JavaScript the content is simply
// there.
const PENDING = 'is-lv-pending';
const reveal = ( el ) => el.classList.replace( PENDING, 'is-lv-revealed' );

if ( ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
	const observer = new window.IntersectionObserver( ( entries ) => {
		for ( const { target, isIntersecting } of entries ) {
			if ( isIntersecting ) {
				observer.unobserve( target );
				reveal( target );
			}
		}
	} );
	// Measure all first, then write, so the page lays out once.
	const below = [
		...document.querySelectorAll(
			'.wp-block-leitvia-reveal, .has-lv-animation'
		),
	].filter( ( el ) => el.getBoundingClientRect().top >= window.innerHeight );
	for ( const el of below ) {
		el.classList.add( PENDING );
		observer.observe( el );
	}
	// Keyboard focus inside a hidden block shows it at once (focus visible).
	document.addEventListener( 'focusin', ( { target } ) => {
		const el = target.closest?.( `.${ PENDING }` );
		if ( el ) {
			observer.unobserve( el );
			reveal( el );
		}
	} );
}
