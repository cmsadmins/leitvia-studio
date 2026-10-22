// Lab panel "Usage": where the pattern is used and which patterns it uses. Two linked lists in both directions are
// the dependency graph; they stay usable with a screen reader.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Flex, Spinner } from '@wordpress/components';
import { labUrl, statusLabel } from './common';
import { notify } from '../admin/notify';

export default function UsagePanel( { pattern } ) {
	const [ data, setData ] = useState( null );

	useEffect( () => {
		apiFetch( {
			path: addQueryArgs( '/leitvia-studio/v1/usage', {
				ref: pattern.ref,
			} ),
		} )
			.then( setData )
			.catch( ( error ) =>
				notify(
					'error',
					error.message ??
						__( 'The usage could not be loaded.', 'leitvia-studio' )
				)
			);
	}, [ pattern.ref ] );

	if ( ! data ) {
		return <Spinner />;
	}

	return (
		<Flex direction="column" justify="flex-start" gap={ 4 } align="stretch">
			{ ! data.index_built && (
				<p>
					{ __(
						'The usage index is incomplete; build it in the pattern gallery to see every use.',
						'leitvia-studio'
					) }
				</p>
			) }
			<h4 className="leitvia-studio-usage__heading">
				{ __( 'Used in', 'leitvia-studio' ) }
			</h4>
			{ data.used_by.length ? (
				<ul className="leitvia-studio-usage">
					{ data.used_by.map( ( item ) => (
						<li key={ `${ item.type }:${ item.id }` }>
							{ item.edit_url ? (
								<a href={ item.edit_url }>
									{ item.title ||
										__( '(no title)', 'leitvia-studio' ) }
								</a>
							) : (
								item.title ||
								__( '(no title)', 'leitvia-studio' )
							) }{ ' ' }
							{ sprintf(
								/* translators: 1: content type, 2: publishing status. */
								__( '(%1$s, %2$s)', 'leitvia-studio' ),
								item.type_label,
								item.status_label
							) }
						</li>
					) ) }
				</ul>
			) : (
				<p>
					{ __(
						'This pattern is not used anywhere yet.',
						'leitvia-studio'
					) }
				</p>
			) }
			{ data.used_by_more && (
				<p>
					{ __(
						'More than 100 posts use this pattern; the list shows the first 100.',
						'leitvia-studio'
					) }
				</p>
			) }
			<h4 className="leitvia-studio-usage__heading">
				{ __( 'Uses', 'leitvia-studio' ) }
			</h4>
			{ data.uses.length ? (
				<ul className="leitvia-studio-usage">
					{ data.uses.map( ( item ) => (
						<li key={ item.ref }>
							<a href={ labUrl( item.ref ) }>{ item.title }</a>{ ' ' }
							{ sprintf(
								/* translators: %s: effective status. */
								__( '(%s)', 'leitvia-studio' ),
								statusLabel( item.effective_status )
							) }
							{ item.replaced_by && (
								<>
									{ ' ' }
									{ __(
										'Replace with',
										'leitvia-studio'
									) }{ ' ' }
									<a href={ labUrl( item.replaced_by ) }>
										{ item.replaced_by_title }
									</a>
								</>
							) }
						</li>
					) ) }
				</ul>
			) : (
				<p>
					{ __(
						'This pattern uses no other patterns.',
						'leitvia-studio'
					) }
				</p>
			) }
		</Flex>
	);
}
