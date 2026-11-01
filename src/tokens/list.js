// Token set list: every set with status and revisions, creation from token sources, import, activation and export.
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import {
	Button,
	Flex,
	Icon,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { errorText } from '../admin/common';
import ConfirmDialog from '../admin/confirm-dialog';
import More from '../admin/more';
import { notify } from '../admin/notify';
import ImportDialog from './import-dialog';
import { OVERLAY, overlaySummary } from './overlay-form';
import {
	ActivateDialog,
	ActivateModal,
	Findings,
	downloadSet,
	errorFindings,
	tokensUrl,
} from './common';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'modified', direction: 'desc' },
	titleField: 'title',
	fields: [ 'status', 'overlay', 'modified', 'author', 'revisions' ],
	layout: {},
};

function DeleteModal( { items: [ item ], closeModal, onDone } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const confirm = async () => {
		setIsBusy( true );
		try {
			await apiFetch( {
				path: `/wp/v2/leitvia-tokens/${ item.id }?force=true`,
				method: 'DELETE',
			} );
			notify( 'success', __( 'Token set deleted.', 'leitvia-studio' ) );
			closeModal();
			onDone();
		} catch ( error ) {
			setIsBusy( false );
			notify( 'error', errorFindings( error )[ 0 ].message );
		}
	};
	return (
		<ConfirmDialog
			modal={ false }
			confirmLabel={ __( 'Delete', 'leitvia-studio' ) }
			isDestructive
			isBusy={ isBusy }
			onCancel={ closeModal }
			onConfirm={ confirm }
		>
			<p>
				{ sprintf(
					/* translators: %s: token set title. */
					__(
						'Delete “%s” and its revisions permanently?',
						'leitvia-studio'
					),
					item.title
				) }
			</p>
		</ConfirmDialog>
	);
}

function CreateDialog( { onClose } ) {
	const [ sources, setSources ] = useState( null );
	const [ choice, setChoice ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ findings, setFindings ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/leitvia-studio/v1/token-sources' } )
			.then( ( response ) => {
				setSources( response );
				if ( response[ 0 ]?.documents.length ) {
					setChoice( `${ response[ 0 ].name }|0` );
				}
			} )
			.catch( ( error ) => {
				setSources( [] );
				setFindings( errorFindings( error ) );
			} );
	}, [] );

	const options = ( sources ?? [] ).flatMap( ( source ) =>
		source.documents.map( ( entry, index ) => ( {
			value: `${ source.name }|${ index }`,
			label: entry.title
				? sprintf(
						/* translators: 1: token source, 2: document title. */
						__( '%1$s: %2$s', 'leitvia-studio' ),
						source.label,
						entry.title
					)
				: source.label,
		} ) )
	);

	const submit = async ( event ) => {
		event.preventDefault();
		const [ source, index ] = choice.split( '|' );
		setIsBusy( true );
		try {
			const response = await apiFetch( {
				path: '/leitvia-studio/v1/token-sets',
				method: 'POST',
				data: { source, index: Number( index ), title },
			} );
			window.location.assign( tokensUrl( response.id ) );
		} catch ( error ) {
			setIsBusy( false );
			setFindings( errorFindings( error ) );
		}
	};

	return (
		<Modal
			title={ __( 'Create token set', 'leitvia-studio' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ submit }>
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 4 }
					align="stretch"
				>
					{ findings && (
						<Findings
							heading={ __(
								'The token set was not created',
								'leitvia-studio'
							) }
							findings={ findings }
						/>
					) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Source', 'leitvia-studio' ) }
						help={ __(
							'The new set starts with the values of this source.',
							'leitvia-studio'
						) }
						value={ choice }
						options={ options }
						onChange={ setChoice }
						disabled={ ! options.length }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'leitvia-studio' ) }
						help={ __(
							'Leave empty to use the name of the source.',
							'leitvia-studio'
						) }
						value={ title }
						onChange={ setTitle }
					/>
					<Flex justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'leitvia-studio' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ ! choice || isBusy }
							accessibleWhenDisabled
						>
							{ __( 'Create', 'leitvia-studio' ) }
						</Button>
					</Flex>
				</Flex>
			</form>
		</Modal>
	);
}

function StatusCell( { item } ) {
	const parts = [];
	if ( item.active ) {
		parts.push(
			<span key="active" className="leitvia-studio-tokens__status">
				<Icon icon="yes-alt" size={ 20 } aria-hidden="true" />
				{ __( 'Active', 'leitvia-studio' ) }
			</span>
		);
	}
	if ( false === item.valid ) {
		parts.push(
			<span key="invalid" className="leitvia-studio-tokens__status">
				<Icon icon="warning" size={ 20 } aria-hidden="true" />
				{ __( 'Invalid', 'leitvia-studio' ) }
			</span>
		);
	}
	return parts.length ? parts : null;
}

const FIELDS = [
	{
		id: 'title',
		label: __( 'Title', 'leitvia-studio' ),
		enableHiding: false,
		enableGlobalSearch: true,
		render: ( { item } ) => (
			<a href={ tokensUrl( item.id ) }>{ item.title }</a>
		),
	},
	{
		id: 'status',
		label: __( 'Status', 'leitvia-studio' ),
		enableSorting: false,
		render: StatusCell,
	},
	{
		id: 'overlay',
		label: __( 'Overlay', 'leitvia-studio' ),
		enableSorting: false,
		filterBy: false,
		getValue: ( { item } ) => overlaySummary( item.overlay, item.valid ),
	},
	{
		id: 'modified',
		label: __( 'Last modified', 'leitvia-studio' ),
		type: 'datetime',
		filterBy: false,
	},
	{
		id: 'author',
		label: __( 'Author', 'leitvia-studio' ),
		filterBy: false,
	},
	{
		id: 'revisions',
		label: __( 'Revisions', 'leitvia-studio' ),
		type: 'integer',
		filterBy: false,
	},
];

/**
 * Sets with author name, revision count and latest revision from the links core adds to each post.
 *
 * @param {Array} posts Posts of /wp/v2/leitvia-tokens.
 */
const toItems = ( posts ) =>
	posts.map( ( post ) => ( {
		id: post.id,
		title: post.title.raw || __( '(no title)', 'leitvia-studio' ),
		modified: post.modified,
		author: post._embedded?.author?.[ 0 ]?.name ?? '',
		revisions: post._links?.[ 'version-history' ]?.[ 0 ]?.count ?? 0,
		revision: post._links?.[ 'predecessor-version' ]?.[ 0 ]?.id ?? 0,
		overlay: post.meta?.[ OVERLAY ],
	} ) );

export default function List() {
	const [ state, setState ] = useState( null );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ dialog, setDialog ] = useState( '' );

	const load = useCallback( async () => {
		try {
			const [ contract, posts ] = await Promise.all( [
				apiFetch( { path: '/leitvia-studio/v1/contract' } ),
				apiFetch( {
					path: addQueryArgs( '/wp/v2/leitvia-tokens', {
						context: 'edit',
						per_page: 100,
						_embed: 'author',
						_fields: `id,title,modified,author,_links,_embedded,meta.${ OVERLAY }`,
					} ),
				} ),
			] );
			const items = toItems( posts );
			// The list shows at once; the checks fill in the Invalid status.
			setState( { contract, items } );
			const checked = await Promise.all(
				items.map( ( item ) =>
					apiFetch( {
						path: `/leitvia-studio/v1/token-sets/${ item.id }/check`,
					} )
						.then( ( result ) => result.valid )
						.catch( () => false )
				)
			);
			setState( {
				contract,
				items: items.map( ( item, index ) => ( {
					...item,
					valid: checked[ index ],
				} ) ),
			} );
		} catch ( error ) {
			setState( { contract: null, items: [], error } );
			notify(
				'error',
				errorText(
					error,
					__(
						'The token sets could not be loaded.',
						'leitvia-studio'
					)
				)
			);
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const active = state?.contract?.active ?? 0;
	const items = useMemo(
		() =>
			( state?.items ?? [] ).map( ( item ) => ( {
				...item,
				active: item.id === active,
			} ) ),
		[ state, active ]
	);
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, FIELDS ),
		[ items, view ]
	);
	const roles = useMemo(
		() => state?.contract?.contract?.roles ?? {},
		[ state ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'leitvia-studio' ),
				isPrimary: true,
				callback: ( [ item ] ) =>
					window.location.assign( tokensUrl( item.id ) ),
			},
			{
				id: 'activate',
				label: __( 'Activate', 'leitvia-studio' ),
				modalHeader: __( 'Activate token set', 'leitvia-studio' ),
				RenderModal: ( props ) => (
					<ActivateModal
						{ ...props }
						roles={ roles }
						onDone={ load }
					/>
				),
			},
			{
				id: 'export',
				label: __( 'Export', 'leitvia-studio' ),
				callback: ( [ item ] ) =>
					downloadSet( item.id ).catch( ( error ) =>
						notify( 'error', errorFindings( error )[ 0 ].message )
					),
			},
			// Exports that add-ons register as sync targets, labelled by them.
			...( window.leitviaStudioAdmin?.syncTargets ?? [] )
				.filter( ( target ) => target.name !== 'leitvia/download' )
				.map( ( target ) => ( {
					id: `export-${ target.name }`,
					label: target.label,
					callback: ( [ item ] ) =>
						downloadSet( item.id, target.name ).catch( ( error ) =>
							notify(
								'error',
								errorFindings( error )[ 0 ].message
							)
						),
				} ) ),
			{
				id: 'duplicate',
				label: __( 'Duplicate', 'leitvia-studio' ),
				callback: async ( [ item ] ) => {
					try {
						const doc = await apiFetch( {
							path: `/leitvia-studio/v1/token-sets/${ item.id }/export`,
						} );
						await apiFetch( {
							path: '/leitvia-studio/v1/token-sets/import',
							method: 'POST',
							data: {
								title: sprintf(
									/* translators: %s: token set title. */
									__( '%s (copy)', 'leitvia-studio' ),
									item.title
								),
								document: doc,
							},
						} );
						notify(
							'success',
							__( 'Token set duplicated.', 'leitvia-studio' )
						);
						load();
					} catch ( error ) {
						notify( 'error', errorFindings( error )[ 0 ].message );
					}
				},
			},
			{
				id: 'revisions',
				label: __( 'Revisions', 'leitvia-studio' ),
				isEligible: ( item ) => item.revision > 0,
				callback: ( [ item ] ) =>
					window.location.assign(
						addQueryArgs( 'revision.php', {
							revision: item.revision,
						} )
					),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'leitvia-studio' ),
				isDestructive: true,
				isEligible: ( item ) => ! item.active,
				modalHeader: __( 'Delete token set', 'leitvia-studio' ),
				RenderModal: ( props ) => (
					<DeleteModal { ...props } onDone={ load } />
				),
			},
		],
		[ roles, load ]
	);

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 4 }
			align="stretch"
			className="leitvia-studio-tokens"
		>
			<Flex justify="flex-start" gap={ 2 } wrap>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => setDialog( 'create' ) }
				>
					{ __( 'Create set', 'leitvia-studio' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () => setDialog( 'import' ) }
				>
					{ __( 'Import file', 'leitvia-studio' ) }
				</Button>
			</Flex>
			{ state?.contract && ! state.contract.valid && (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ __(
							'The active theme does not tell Studio its colors and fonts, so token sets cannot be checked or activated.',
							'leitvia-studio'
						) }
					</p>
					<More>
						<p>
							{ __(
								'The active theme has no valid contract (leitvia-contract.json).',
								'leitvia-studio'
							) }
						</p>
					</More>
				</Notice>
			) }
			{ state?.contract?.drift && active > 0 && (
				<Notice
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Apply again', 'leitvia-studio' ),
							onClick: () => setDialog( 'reapply' ),
							variant: 'secondary',
						},
					] }
				>
					{ __(
						'The site no longer looks like the active set: the set was saved again after activation, or someone changed the styles, for example in the Site Editor.',
						'leitvia-studio'
					) }
				</Notice>
			) }
			<DataViews
				data={ data }
				fields={ FIELDS }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ null === state }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				searchLabel={ __( 'Search token sets', 'leitvia-studio' ) }
				empty={
					<p>
						{ __(
							'No token sets yet. Create one from the theme or import a file.',
							'leitvia-studio'
						) }
					</p>
				}
			/>
			{ 'reapply' === dialog && (
				<ActivateDialog
					item={
						items.find( ( item ) => item.id === active ) ?? {
							id: active,
							title: '',
						}
					}
					roles={ roles }
					onClose={ () => setDialog( '' ) }
					onDone={ load }
				/>
			) }
			{ 'create' === dialog && (
				<CreateDialog onClose={ () => setDialog( '' ) } />
			) }
			{ 'import' === dialog && (
				<ImportDialog
					onClose={ () => setDialog( '' ) }
					onImported={ () => {
						setDialog( '' );
						notify(
							'success',
							__( 'Token set imported.', 'leitvia-studio' )
						);
						load();
					} }
				/>
			) }
		</Flex>
	);
}
