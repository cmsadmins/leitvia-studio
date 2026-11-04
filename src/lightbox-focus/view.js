// Script module @leitvia/lightbox-focus (class-lightbox-focus.php): keeps Tab inside the open lightbox of core images.
// Core cycles between its close button and the previous and next buttons, but a single image hides the latter two,
// so Tab from "Close" left the dialog. This cycles over the buttons that are shown; core does the rest.
document.addEventListener(
	'keydown',
	( event ) => {
		const overlay = document.querySelector( '.wp-lightbox-overlay.active' );
		if ( 'Tab' !== event.key || ! overlay ) {
			return;
		}
		const buttons = [ ...overlay.querySelectorAll( 'button' ) ].filter(
			( button ) => ! button.hidden
		);
		const edge = event.shiftKey ? buttons[ 0 ] : buttons.at( -1 );
		const active = overlay.ownerDocument.activeElement;
		if (
			buttons.length &&
			( active === edge || ! overlay.contains( active ) )
		) {
			event.preventDefault();
			event.stopImmediatePropagation();
			( event.shiftKey ? buttons.at( -1 ) : buttons[ 0 ] ).focus();
		}
	},
	true
);
