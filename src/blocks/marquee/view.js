import { store, getContext, getElement } from '@wordpress/interactivity';

const { state } = store( 'leitvia/marquee', {
	state: {
		get label() {
			return getContext().paused ? state.play : state.pause;
		},
	},
	actions: {
		toggle() {
			const context = getContext();
			context.paused = ! context.paused;
		},
	},
	callbacks: {
		// Motion only without reduced motion. The copy of the track closes the loop; it is hidden from assistive
		// technology and out of the focus order.
		init() {
			if (
				window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
			) {
				return;
			}
			const { ref } = getElement();
			const track = ref.querySelector(
				'.wp-block-leitvia-marquee__track'
			);
			const copy = track.cloneNode( true );
			copy.setAttribute( 'aria-hidden', 'true' );
			copy.inert = true;
			copy.querySelectorAll( '[id]' ).forEach( ( el ) =>
				el.removeAttribute( 'id' )
			);
			track.after( copy );
			getContext().running = true;
		},
	},
} );
