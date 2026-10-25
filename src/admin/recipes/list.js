// Recipe list: title, description, source and status of every recipe as a table with a filter by status.
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import {
	STATUSES,
	recipeTitle,
	recipesUrl,
	sourceLabel,
	statusLabel,
	statusOf,
} from './common';

const FIELDS = [
	{
		id: 'title',
		label: __( 'Title', 'leitvia-studio' ),
		enableHiding: false,
		enableGlobalSearch: true,
		getValue: ( { item } ) => recipeTitle( item ),
		render: ( { item } ) => (
			<a href={ recipesUrl( item.name ) }>{ recipeTitle( item ) }</a>
		),
	},
	{
		id: 'description',
		label: __( 'Description', 'leitvia-studio' ),
		enableGlobalSearch: true,
		enableSorting: false,
	},
	{
		id: 'source',
		label: __( 'Source', 'leitvia-studio' ),
		getValue: ( { item } ) => sourceLabel( item ),
	},
	{
		id: 'status',
		label: __( 'Status', 'leitvia-studio' ),
		elements: STATUSES,
		filterBy: { operators: [ 'is' ] },
		enableSorting: false,
		getValue: ( { item } ) => statusOf( item ),
		render: ( { item } ) =>
			item.available ? (
				statusLabel( item )
			) : (
				<>
					{ statusLabel( item ) }
					<br />
					<span className="leitvia-studio-recipes__reason">
						{ item.reason }
					</span>
				</>
			),
	},
];

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'title', direction: 'asc' },
	titleField: 'title',
	fields: [ 'description', 'source', 'status' ],
	layout: {},
};

const ACTIONS = [
	{
		id: 'open',
		label: __( 'Open', 'leitvia-studio' ),
		isPrimary: true,
		callback: ( [ item ] ) =>
			window.location.assign( recipesUrl( item.name ) ),
	},
];

export default function RecipeList( { items } ) {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items ?? [], view, FIELDS ),
		[ items, view ]
	);
	return (
		<div className="leitvia-studio-recipes">
			<p>
				{ __(
					'A recipe sets up the site in one go: pages from patterns, header and footer, navigation, style and settings. Open a recipe to see its plan before you apply it.',
					'leitvia-studio'
				) }
			</p>
			<DataViews
				data={ data }
				fields={ FIELDS }
				view={ view }
				onChangeView={ setView }
				actions={ ACTIONS }
				getItemId={ ( item ) => item.name }
				isLoading={ null === items }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				searchLabel={ __( 'Search recipes', 'leitvia-studio' ) }
				empty={
					<p>
						{ __( 'No recipes are registered.', 'leitvia-studio' ) }
					</p>
				}
			/>
		</div>
	);
}
