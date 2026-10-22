// Content Model screen: tabs for the declared content types, the JSON declaration and the site settings.
import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, Notice, TabPanel } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import JsonEditor from './json-editor';
import SettingsForm from './settings-form';
import { notify } from '../admin/notify';
import Loading from '../admin/loading';
import './style.scss';

const KINDS = [
	{ value: 'postType', label: __( 'Post type', 'leitvia-studio' ) },
	{ value: 'repeater', label: __( 'Repeater', 'leitvia-studio' ) },
	{ value: 'taxonomy', label: __( 'Taxonomy', 'leitvia-studio' ) },
];

const SOURCES = [
	{ value: 'code', label: __( 'Code', 'leitvia-studio' ) },
	{ value: 'ui', label: __( 'Declaration', 'leitvia-studio' ) },
];

const YES_NO = [
	{ value: 'yes', label: __( 'Yes', 'leitvia-studio' ) },
	{ value: 'no', label: __( 'No', 'leitvia-studio' ) },
];

// The label opens the field list; a button in the always visible title column works without hover and on phones.
const fieldsWith = ( open ) => [
	{
		id: 'label',
		label: __( 'Label', 'leitvia-studio' ),
		enableHiding: false,
		render: ( { item } ) => (
			<Button
				variant="link"
				className="leitvia-studio-model__open"
				onClick={ () => open( item ) }
				aria-haspopup="dialog"
				aria-describedby="leitvia-studio-model-open-hint"
			>
				{ item.label }
			</Button>
		),
		getValue: ( { item } ) => item.label,
	},
	{ id: 'key', label: __( 'Key', 'leitvia-studio' ) },
	{
		id: 'kind',
		label: __( 'Kind', 'leitvia-studio' ),
		elements: KINDS,
		filterBy: { operators: [ 'isAny' ] },
	},
	{
		id: 'fieldCount',
		label: __( 'Fields', 'leitvia-studio' ),
		type: 'integer',
		render: ( { item } ) => item.fields.length,
	},
	{
		id: 'count',
		label: __( 'Entries', 'leitvia-studio' ),
		type: 'integer',
	},
	{
		id: 'source',
		label: __( 'Source', 'leitvia-studio' ),
		elements: SOURCES,
		filterBy: { operators: [ 'is' ] },
	},
	{
		id: 'abilities',
		label: __( 'Available to AI abilities', 'leitvia-studio' ),
		elements: YES_NO,
		filterBy: false,
	},
];

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	titleField: 'label',
	fields: [ 'key', 'kind', 'fieldCount', 'count', 'source', 'abilities' ],
	layout: {},
};

/**
 * One table row per post type, repeater type and taxonomy.
 *
 * @param {Object} data        Response of GET /leitvia-studio/v1/model.
 * @param {Object} data.model  Declaration, every entry marked with source.
 * @param {Object} data.counts Entries per post type, terms per taxonomy.
 * @return {Object[]} Rows.
 */
function toRows( { model, counts } ) {
	const rows = [];
	const relations = Object.entries( model.relations ?? {} );
	for ( const [ key, postType ] of Object.entries( model.postTypes ?? {} ) ) {
		const fields = Object.entries( postType.fields ?? {} ).map(
			( [ name, field ] ) => ( {
				name,
				label: field.label,
				type: field.type,
			} )
		);
		for ( const [ name, relation ] of relations ) {
			if ( relation.from === key ) {
				fields.push( {
					name,
					label: relation.label,
					type: sprintf(
						/* translators: %s: post type key. */
						__( 'relation to %s', 'leitvia-studio' ),
						relation.to
					),
				} );
			}
		}
		rows.push( {
			id: `postType:${ key }`,
			key,
			kind: 'postType',
			label: postType.label,
			fields,
			count: counts.postTypes?.[ key ] ?? 0,
			source: postType.source,
			abilities: postType.showInAbilities ? 'yes' : 'no',
		} );
		for ( const repeater of Object.values( postType.repeaters ?? {} ) ) {
			rows.push( {
				id: `postType:${ repeater.postType }`,
				key: repeater.postType,
				kind: 'repeater',
				label: repeater.label,
				fields: Object.entries( repeater.fields ?? {} ).map(
					( [ name, field ] ) => ( {
						name,
						label: field.label,
						type: field.type,
					} )
				),
				count: counts.postTypes?.[ repeater.postType ] ?? 0,
				source: postType.source,
				abilities: 'no',
			} );
		}
	}
	for ( const [ key, taxonomy ] of Object.entries(
		model.taxonomies ?? {}
	) ) {
		rows.push( {
			id: `taxonomy:${ key }`,
			key,
			kind: 'taxonomy',
			label: taxonomy.label,
			fields: [],
			count: counts.taxonomies?.[ key ] ?? 0,
			source: taxonomy.source,
			abilities: taxonomy.showInAbilities ? 'yes' : 'no',
		} );
	}
	return rows;
}

function FieldList( { item } ) {
	const kind = KINDS.find( ( entry ) => entry.value === item.kind )?.label;
	const abilities = YES_NO.find(
		( entry ) => entry.value === item.abilities
	)?.label;
	return (
		<>
			<dl className="leitvia-studio-model__facts">
				<dt>{ __( 'Key', 'leitvia-studio' ) }</dt>
				<dd>{ item.key }</dd>
				<dt>{ __( 'Kind', 'leitvia-studio' ) }</dt>
				<dd>{ kind }</dd>
				<dt>{ __( 'Available to AI abilities', 'leitvia-studio' ) }</dt>
				<dd>{ abilities }</dd>
			</dl>
			{ item.fields.length ? (
				<table className="widefat striped">
					<caption className="screen-reader-text">
						{ sprintf(
							/* translators: %s: label of a post type. */
							__( 'Fields of %s', 'leitvia-studio' ),
							item.label
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Key', 'leitvia-studio' ) }
							</th>
							<th scope="col">
								{ __( 'Label', 'leitvia-studio' ) }
							</th>
							<th scope="col">
								{ __( 'Type', 'leitvia-studio' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ item.fields.map( ( field ) => (
							<tr key={ field.name }>
								<td>{ field.name }</td>
								<td>{ field.label }</td>
								<td>{ field.type }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<p>{ __( 'No fields declared.', 'leitvia-studio' ) }</p>
			) }
		</>
	);
}

function ContentTypes( { data } ) {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ open, setOpen ] = useState( null );
	const fields = useMemo( () => fieldsWith( setOpen ), [] );
	const rows = useMemo( () => toRows( data ), [ data ] );
	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);
	return (
		<>
			<p id="leitvia-studio-model-open-hint" className="description">
				{ __(
					'Select a label to see its fields and whether it is available to AI abilities.',
					'leitvia-studio'
				) }
			</p>
			<DataViews
				data={ shown }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => item.id }
				search
				searchLabel={ __( 'Search content types', 'leitvia-studio' ) }
				empty={
					<p>
						{ __(
							'No content types declared yet. Add them on the Declaration tab.',
							'leitvia-studio'
						) }
					</p>
				}
			/>
			{ open && (
				<Modal
					title={ sprintf(
						/* translators: %s: label of a post type or taxonomy. */
						__( 'Fields of %s', 'leitvia-studio' ),
						open.label
					) }
					onRequestClose={ () => setOpen( null ) }
				>
					<FieldList item={ open } />
				</Modal>
			) }
		</>
	);
}

export default function ModelScreen() {
	const [ data, setData ] = useState( null );
	// Unsaved text of the declaration; it survives tab changes, and leaving the page asks first.
	const draft = useRef( null );
	useEffect( () => {
		const warn = ( event ) => {
			if ( null !== draft.current ) {
				event.preventDefault();
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [] );
	const load = useCallback(
		() =>
			apiFetch( { path: '/leitvia-studio/v1/model' } )
				.then( setData )
				.catch( ( error ) =>
					notify(
						'error',
						error.message ??
							__(
								'The content model could not be loaded.',
								'leitvia-studio'
							)
					)
				),
		[]
	);
	useEffect( () => {
		load();
	}, [ load ] );

	if ( ! data ) {
		return <Loading />;
	}
	return (
		<>
			{ data.errors.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'The content model has errors. Post types and taxonomies are not registered, or another plugin replaced them:',
							'leitvia-studio'
						) }
					</p>
					<ul>
						{ data.errors.map( ( error, index ) => (
							<li key={ index }>
								<code>{ error.path }</code> { error.message }
							</li>
						) ) }
					</ul>
				</Notice>
			) }
			<TabPanel
				className="leitvia-studio-model__tabs"
				tabs={ [
					{
						name: 'types',
						title: __( 'Content types', 'leitvia-studio' ),
					},
					{
						name: 'declaration',
						title: __( 'Declaration', 'leitvia-studio' ),
					},
					{
						name: 'settings',
						title: __( 'Site settings', 'leitvia-studio' ),
					},
				] }
			>
				{ ( tab ) => (
					<div className="leitvia-studio-model__panel">
						{ 'types' === tab.name && (
							<ContentTypes data={ data } />
						) }
						{ 'declaration' === tab.name && (
							<JsonEditor
								model={ data.model }
								onSaved={ setData }
								draft={ draft }
							/>
						) }
						{ 'settings' === tab.name && (
							<SettingsForm settings={ data.model.settings } />
						) }
					</div>
				) }
			</TabPanel>
		</>
	);
}
