// Shows "Copy link" where the Clipboard API is there and "Share…" where the Web Share API is, and handles both. The
// links work without this module; the result of copying goes to the polite live region of the block.
for ( const nav of document.querySelectorAll( '.wp-block-leitvia-share' ) ) {
	const status = nav.querySelector( '.wp-block-leitvia-share__status' );
	const { url, title } = nav.dataset;
	let timer;
	const say = ( text ) => {
		status.textContent = text;
		window.clearTimeout( timer );
		timer = window.setTimeout( () => ( status.textContent = '' ), 5000 );
	};
	const actions = {
		copy: [
			window.navigator.clipboard,
			async () => {
				try {
					await window.navigator.clipboard.writeText( url );
					say( status.dataset.copied );
				} catch {
					say( status.dataset.failed );
				}
			},
		],
		native: [
			window.navigator.share,
			// A cancelled share sheet rejects; nothing to report.
			() => window.navigator.share( { title, url } ).catch( () => {} ),
		],
	};
	for ( const button of nav.querySelectorAll( '[data-share]' ) ) {
		const [ supported, run ] = actions[ button.dataset.share ];
		if ( supported ) {
			button.parentElement.hidden = false;
			button.addEventListener( 'click', run );
		} else {
			button.parentElement.remove();
		}
	}
}
