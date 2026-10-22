// Profile screens: picks the profile picture from the media library. Without JavaScript the choose button stays hidden
// and the remove checkbox still works (LeitviaStudio\Avatars\Avatars::profile_field()).
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import domReady from '@wordpress/dom-ready';

domReady( () => {
	const button = document.getElementById( 'leitvia-avatar-choose' );
	const input = document.getElementById( 'leitvia-avatar-id' );
	if ( ! button || ! input || ! window.wp?.media ) {
		return;
	}
	let frame = null;

	const choose = () => {
		const image = frame.state().get( 'selection' ).first().toJSON();
		input.value = image.id;
		const preview = document.querySelector(
			'.user-profile-picture img.avatar'
		);
		if ( preview ) {
			const sizes = image.sizes ?? {};
			preview.src = ( sizes.thumbnail ?? image ).url;
			preview.srcset = `${ ( sizes.medium ?? image ).url } 2x`;
			preview.alt = __( 'Selected profile picture', 'leitvia-studio' );
		}
		speak(
			__(
				'Profile picture selected. Save the profile to apply it.',
				'leitvia-studio'
			)
		);
	};

	// Core's .button sets display, so the markup also carries core's .hidden class.
	button.hidden = false;
	button.classList.remove( 'hidden' );
	button.addEventListener( 'click', () => {
		if ( ! frame ) {
			frame = window.wp.media( {
				title: __( 'Choose a profile picture', 'leitvia-studio' ),
				button: {
					text: __( 'Use as profile picture', 'leitvia-studio' ),
				},
				library: {
					type: 'image',
					...( window.leitviaStudioProfile?.author
						? { author: window.leitviaStudioProfile.author }
						: {} ),
				},
				multiple: false,
			} );
			frame.on( 'select', choose );
			// Selection and Escape both close the modal; focus goes back to the button either way.
			frame.on( 'close', () => button.focus() );
		}
		frame.open();
	} );
} );
