// Content Model screen: content types, the JSON declaration and the declared site details.
import { __ } from '@wordpress/i18n';
import ModelScreen from '../../model/screen';

export const title = __( 'Content Model', 'leitvia-studio' );

export const intro = __(
	'Declare the kinds of content your site needs, such as events or team members, and site details such as the phone number.',
	'leitvia-studio'
);

export default ModelScreen;
