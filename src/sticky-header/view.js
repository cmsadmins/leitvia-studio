// Script module @leitvia/sticky-header (includes/blocks/class-sticky-header.php): measures the header the theme keeps
// at the top of the screen. Its height goes to --leitvia-sticky-header-height on html, the scroll padding of the theme
// that keeps focused elements and jump targets below the header (WCAG 2.4.11); a header taller than a quarter of the
// window gets data-leitvia-header-tall on html, and the theme lets it scroll away (WCAG 1.4.10). The height does not
// depend on whether the header sticks, so nothing moves on the page. A service bar on top of the header (class
// leitvia-service-bar) hands its height in as --leitvia-service-bar-height: on phones the theme lets it scroll away
// and only the row below it sticks. An address with an anchor (a link from another page) scrolls to its target before
// the height is known, with the padding of the theme for an unmeasured header (a quarter of the window): once the
// first height is set, the target scrolls into view again with the measured padding, so it stops right below the
// header. Only on a fresh visit, never after a reload or back and forward, where the browser restores where the
// visitor was.
const root = document.documentElement;
const header = document.querySelector( '.wp-site-blocks > header' );
const bar = header?.querySelector(
	':scope > * > .leitvia-service-bar:first-child'
);

if ( header ) {
	let jumped = false;
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
		if ( bar ) {
			// Rounded like the header: the offset (header minus bar) is the visible row rounded up, never a pixel
			// short of it.
			const row = Math.ceil(
				height - bar.getBoundingClientRect().height
			);
			root.style.setProperty(
				'--leitvia-service-bar-height',
				`${ Math.ceil( height ) - row }px`
			);
		}
		if ( ! jumped ) {
			jumped = true;
			const [ visit ] =
				window.performance.getEntriesByType( 'navigation' );
			if ( ! visit || 'navigate' === visit.type ) {
				document.querySelector( ':target' )?.scrollIntoView();
			}
		}
	};
	new window.ResizeObserver( update ).observe( header );
	window.addEventListener( 'resize', update );
}
