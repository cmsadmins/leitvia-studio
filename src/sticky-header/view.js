// Script module @leitvia/sticky-header (includes/blocks/class-sticky-header.php): measures the header the theme keeps
// at the top of the screen. Its height goes to --leitvia-sticky-header-height on html, the scroll padding of the theme
// that keeps focused elements and jump targets below the header (WCAG 2.4.11); a header taller than a quarter of the
// window gets data-leitvia-header-tall on html, and the theme lets it scroll away (WCAG 1.4.10). The height does not
// depend on whether the header sticks, so nothing moves on the page.
const root = document.documentElement;
const header = document.querySelector( '.wp-site-blocks > header' );

if ( header ) {
	const update = () => {
		const height = header.getBoundingClientRect().height;
		root.style.setProperty(
			'--leitvia-sticky-header-height',
			`${ Math.ceil( height ) }px`
		);
		root.toggleAttribute(
			'data-leitvia-header-tall',
			height > window.innerHeight / 4
		);
	};
	new window.ResizeObserver( update ).observe( header );
	window.addEventListener( 'resize', update );
}
