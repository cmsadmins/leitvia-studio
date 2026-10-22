// Script module @leitvia/code-copy (class-code-copy.php): shows the "Copy code" button of every code block and
// copies the text of its <code>. The result goes to the live region next to the button; the button name stays.
for ( const wrap of document.querySelectorAll( '.leitvia-code-copy' ) ) {
	const button = wrap.querySelector( '.leitvia-code-copy__button' );
	const status = wrap.querySelector( '.leitvia-code-copy__status' );
	const code =
		wrap.querySelector( 'pre code' ) ?? wrap.querySelector( 'pre' );
	let timer;
	button.hidden = false;
	button.addEventListener( 'click', async () => {
		let ok = true;
		try {
			await window.navigator.clipboard.writeText( code.textContent );
		} catch {
			ok = false;
		}
		status.textContent = ok ? status.dataset.copied : status.dataset.failed;
		window.clearTimeout( timer );
		timer = window.setTimeout( () => ( status.textContent = '' ), 5000 );
	} );
}
