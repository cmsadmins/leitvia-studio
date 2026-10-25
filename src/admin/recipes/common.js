// Labels shared by the recipe list and the recipe detail.
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

export const STATUSES = [
	{ value: 'available', label: __( 'Available', 'leitvia-studio' ) },
	{ value: 'unavailable', label: __( 'Not available', 'leitvia-studio' ) },
];

export const statusOf = ( item ) =>
	item.available ? 'available' : 'unavailable';

export const statusLabel = ( item ) =>
	STATUSES.find( ( status ) => status.value === statusOf( item ) ).label;

export const sourceLabel = ( item ) =>
	'core' === item.source
		? __( 'Built in', 'leitvia-studio' )
		: sprintf(
				/* translators: %s: name of the add-on that registered the recipe. */
				__( 'Add-on: %s', 'leitvia-studio' ),
				item.sourceLabel
			);

export const recipeTitle = ( item ) => item.title || item.label || item.name;

export const recipesUrl = ( name ) =>
	addQueryArgs( 'admin.php', {
		page: 'leitvia-studio-recipes',
		...( name ? { recipe: name } : {} ),
	} );
