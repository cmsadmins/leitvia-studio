// Shows the dismiss button. A click stores the hash of the bar's content (localStorage, only after the click) and
// hides it; the inline script before the bar (class-announcement-bar.php) hides it on the next page before the
// first paint as long as the content stays the same.
const FOCUSABLE =
	'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';

for ( const bar of document.querySelectorAll(
	'.wp-block-leitvia-announcement-bar[data-hash]'
) ) {
	const button = bar.querySelector(
		'.wp-block-leitvia-announcement-bar__dismiss'
	);
	button.hidden = false;
	button.addEventListener( 'click', () => {
		try {
			window.localStorage.setItem(
				'leitvia-announcement',
				bar.dataset.hash
			);
		} catch {}
		document.documentElement.setAttribute(
			'data-leitvia-announcement',
			'dismissed'
		);
		// The focused button is gone; focus moves on to the next visible control after the bar, usually the logo or
		// the first link of the header, so keyboard users keep their place (WCAG 2.4.3).
		[ ...document.querySelectorAll( FOCUSABLE ) ]
			.find(
				( el ) =>
					bar.compareDocumentPosition( el ) ===
						window.Node.DOCUMENT_POSITION_FOLLOWING &&
					el.checkVisibility()
			)
			?.focus();
	} );
}
