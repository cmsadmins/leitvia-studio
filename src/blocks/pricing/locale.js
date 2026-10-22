import { useSelect } from '@wordpress/data';

/**
 * BCP 47 tag of the site language (Settings, General), for prices and figures saved as text. Falls back to the
 * language of the admin while the setting loads or the user may not read it.
 *
 * @return {string} For example de-DE.
 */
export default function useSiteLocale() {
	const language = useSelect(
		( select ) =>
			select( 'core' ).getEntityRecord( 'root', 'site' )?.language,
		[]
	);
	return ( language || document.documentElement.lang || 'en-US' ).replace(
		'_',
		'-'
	);
}
