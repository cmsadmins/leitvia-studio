// Marks the link of the section being read with aria-current="location". It moves no focus and announces nothing;
// the links work without this module.
for ( const nav of document.querySelectorAll(
	'.wp-block-leitvia-table-of-contents'
) ) {
	const links = [ ...nav.querySelectorAll( 'a[href^="#"]' ) ];
	const targets = links.map( ( link ) =>
		document.getElementById(
			decodeURIComponent( link.getAttribute( 'href' ).slice( 1 ) )
		)
	);
	// The current section is the last heading above the upper third of the viewport.
	const update = () => {
		const line = window.innerHeight / 3;
		let current = -1;
		targets.forEach( ( target, i ) => {
			if ( target && target.getBoundingClientRect().top <= line ) {
				current = i;
			}
		} );
		links.forEach( ( link, i ) =>
			i === current
				? link.setAttribute( 'aria-current', 'location' )
				: link.removeAttribute( 'aria-current' )
		);
	};
	const observer = new window.IntersectionObserver( update, {
		rootMargin: '0px 0px -66% 0px',
	} );
	targets.forEach( ( target ) => target && observer.observe( target ) );
}
