// URLs and error texts shared by the Studio screens.
import { addQueryArgs } from '@wordpress/url';

/**
 * URL of a Studio screen, for links between the screens and back from a detail view.
 *
 * @param {string} slug   Page slug, for example leitvia-studio-tokens.
 * @param {Object} [args] More query arguments such as { set: 12 }; empty values are left out.
 * @return {string} URL.
 */
export const screenUrl = ( slug, args = {} ) =>
	addQueryArgs( 'admin.php', {
		page: slug,
		...Object.fromEntries(
			Object.entries( args ).filter( ( [ , value ] ) => value )
		),
	} );

/**
 * Message of a failed request, else the fallback.
 *
 * @param {?Object} error    Error thrown by apiFetch.
 * @param {string}  fallback Text when the error has no message.
 * @return {string} Text.
 */
export const errorText = ( error, fallback ) => error?.message ?? fallback;
