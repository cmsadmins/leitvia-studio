// Status messages of the Studio screens: a notice plus an announcement in the live region.
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { speak } from '@wordpress/a11y';

/**
 * Shows a status message and announces it in the live region.
 *
 * @param {'success'|'warning'|'error'} status  Kind of message; warnings stay as a notice until dismissed.
 * @param {string}                      message Text.
 */
export function notify( status, message ) {
	dispatch( noticesStore ).createNotice( status, message, {
		type: 'success' === status ? 'snackbar' : 'default',
		speak: false,
	} );
	speak( message, 'error' === status ? 'assertive' : 'polite' );
}
