import { store } from '@wordpress/interactivity';

store( 'leitvia/spotlight', {
	actions: {
		// One open point per spotlight. toggle does not bubble, so every <details> carries the directive.
		toggle( event ) {
			const details = event.target;
			if ( ! details.open ) {
				return;
			}
			details
				.closest( '.wp-block-leitvia-spotlight' )
				.querySelectorAll( 'details[open]' )
				.forEach( ( other ) => {
					if ( other !== details ) {
						other.open = false;
					}
				} );
		},
		// Escape closes the open point and returns focus to its summary.
		keydown( event ) {
			const details = event.target.closest( 'details[open]' );
			if ( 'Escape' === event.key && details ) {
				details.open = false;
				details.querySelector( 'summary' ).focus();
				event.preventDefault();
			}
		},
	},
} );
