// Lab screen: the gallery, or the detail view of one pattern with ?pattern=<ref>.
import { __ } from '@wordpress/i18n';
import { getQueryArg } from '@wordpress/url';
import Gallery from '../../lab/gallery';
import Detail from '../../lab/detail';

export const title = __( 'Pattern Lab', 'leitvia-studio' );

export default function Lab() {
	const ref = getQueryArg( window.location.href, 'pattern' );
	return ref ? <Detail patternRef={ String( ref ) } /> : <Gallery />;
}
