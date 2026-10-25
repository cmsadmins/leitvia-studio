// Recipes screen: every registered recipe, or one recipe with ?recipe=<name> with its plan and the Apply button.
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { getQueryArg } from '@wordpress/url';
import Loading from '../loading';
import { notify } from '../notify';
import RecipeList from '../recipes/list';
import RecipeDetail from '../recipes/detail';
import '../recipes/style.scss';

export const title = __( 'Recipes', 'leitvia-studio' );

export default function Recipes() {
	const name = getQueryArg( window.location.href, 'recipe' );
	const [ items, setItems ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/leitvia-studio/v1/recipes' } )
			.then( setItems )
			.catch( ( error ) => {
				setItems( [] );
				notify(
					'error',
					error.message ??
						__(
							'The recipes could not be loaded.',
							'leitvia-studio'
						)
				);
			} );
	}, [] );

	if ( name ) {
		if ( null === items ) {
			return <Loading />;
		}
		return (
			<RecipeDetail
				recipe={ items.find( ( item ) => item.name === name ) }
			/>
		);
	}
	return <RecipeList items={ items } />;
}
