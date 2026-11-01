// Inbox list: the change packages of agents ("changes" on screen), newest first, as a table with a filter by status.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { errorText } from '../common';
import { notify } from '../notify';
import {
	STATES,
	checksText,
	formatDate,
	inboxUrl,
	operationStatus,
	verifyText,
} from './common';

const FIELDS = [
	{
		id: 'title',
		label: __( 'Title', 'leitvia-studio' ),
		enableHiding: false,
		enableGlobalSearch: true,
		render: ( { item } ) => (
			<a href={ inboxUrl( item.id ) }>
				{ item.title ||
					sprintf(
						/* translators: %d: change package ID. */
						__( 'Change %d', 'leitvia-studio' ),
						item.id
					) }
			</a>
		),
	},
	{
		id: 'agent',
		label: __( 'Agent', 'leitvia-studio' ),
		enableGlobalSearch: true,
	},
	{
		id: 'created',
		label: __( 'Created', 'leitvia-studio' ),
		type: 'text',
		render: ( { item } ) => formatDate( item.created ),
	},
	{
		id: 'state',
		label: __( 'Status', 'leitvia-studio' ),
		elements: STATES,
		filterBy: { operators: [ 'is' ] },
		enableSorting: false,
	},
	{
		id: 'operations',
		label: __( 'Operations', 'leitvia-studio' ),
		enableSorting: false,
		getValue: ( { item } ) =>
			Object.entries( item.operations )
				.filter( ( [ , count ] ) => count > 0 )
				.map( ( [ status, count ] ) =>
					sprintf(
						/* translators: 1: number of operations, 2: their status such as Open. */
						__( '%1$d %2$s', 'leitvia-studio' ),
						count,
						operationStatus( status ).toLowerCase()
					)
				)
				.join( ', ' ),
	},
	{
		id: 'checks',
		label: __( 'Check result', 'leitvia-studio' ),
		enableSorting: false,
		getValue: ( { item } ) => checksText( item.checks ),
	},
	{
		id: 'verify',
		label: __( 'Verify after writing', 'leitvia-studio' ),
		enableSorting: false,
		getValue: ( { item } ) => verifyText( item.verify ),
	},
];

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'created', direction: 'desc' },
	titleField: 'title',
	fields: [ 'agent', 'created', 'state', 'operations', 'checks', 'verify' ],
	layout: {},
};

const ACTIONS = [
	{
		id: 'open',
		label: __( 'Open', 'leitvia-studio' ),
		isPrimary: true,
		callback: ( [ item ] ) => window.location.assign( inboxUrl( item.id ) ),
	},
];

export default function InboxList() {
	const [ items, setItems ] = useState( null );
	const [ view, setView ] = useState( DEFAULT_VIEW );

	useEffect( () => {
		apiFetch( { path: '/leitvia-studio/v1/packages' } )
			.then( setItems )
			.catch( ( error ) => {
				setItems( [] );
				notify(
					'error',
					errorText(
						error,
						__( 'The Inbox could not be loaded.', 'leitvia-studio' )
					)
				);
			} );
	}, [] );

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items ?? [], view, FIELDS ),
		[ items, view ]
	);

	return (
		<>
			<p>
				{ __(
					'Changes that agents propose wait here until you apply or reject them.',
					'leitvia-studio'
				) }
			</p>
			<DataViews
				data={ data }
				fields={ FIELDS }
				view={ view }
				onChangeView={ setView }
				actions={ ACTIONS }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ null === items }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				searchLabel={ __( 'Search changes', 'leitvia-studio' ) }
				empty={
					<p>
						{ __(
							'No changes to review. Agents put a change here when the autonomy settings leave the decision to you.',
							'leitvia-studio'
						) }
					</p>
				}
			/>
		</>
	);
}
