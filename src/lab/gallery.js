// Lab gallery: every theme pattern and every own wp_block pattern, as a grid of previews or as a table, with pattern
// export and import and the one-time build of the usage index.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs, cleanForSlug } from '@wordpress/url';
import { speak } from '@wordpress/a11y';
import { DataViews } from '@wordpress/dataviews/wp';
import { Button, Flex, Modal, Notice, Spinner } from '@wordpress/components';
import PreviewFrame from './preview-frame';
import { STATUSES, labUrl, settings } from './common';
import { notify } from '../admin/notify';
import { countIssues, isOutdated } from './checks-panel';

const STORAGE_KEY = `leitvia-studio-lab-view-${ settings.userId }`;

const DEFAULT_LAYOUTS = {
	grid: {
		mediaField: 'preview',
		fields: [ 'effective_status', 'source' ],
		layout: { badgeFields: [ 'effective_status' ], aspectRatio: '4/3' },
	},
	table: {
		mediaField: undefined,
		fields: [
			'source',
			'status',
			'effective_status',
			'version',
			'categories',
			'variant_count',
			'checks',
		],
		layout: {},
	},
};

const DEFAULT_VIEW = {
	type: 'grid',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	titleField: 'title',
	...DEFAULT_LAYOUTS.grid,
};

function loadView() {
	try {
		const stored = JSON.parse( window.localStorage.getItem( STORAGE_KEY ) );
		return stored?.type ? stored : DEFAULT_VIEW;
	} catch {
		return DEFAULT_VIEW;
	}
}

function saveView( view ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( view ) );
	} catch {
		// Private windows and blocked storage only lose the remembered view.
	}
}

const FIELDS = [
	{
		id: 'title',
		label: __( 'Title', 'leitvia-studio' ),
		enableHiding: false,
		enableSorting: false,
		render: ( { item } ) => (
			<a href={ labUrl( item.ref ) }>{ item.title || item.ref }</a>
		),
	},
	{
		id: 'preview',
		label: __( 'Preview', 'leitvia-studio' ),
		enableSorting: false,
		render: ( { item } ) => (
			<PreviewFrame
				patternRef={ item.ref }
				width={ 1280 }
				title={ sprintf(
					/* translators: %s: pattern title. */
					__( 'Preview of %s', 'leitvia-studio' ),
					item.title
				) }
				decorative
			/>
		),
	},
	{
		id: 'source',
		label: __( 'Source', 'leitvia-studio' ),
		elements: [
			{ value: 'theme', label: __( 'Theme', 'leitvia-studio' ) },
			{ value: 'user', label: __( 'My patterns', 'leitvia-studio' ) },
		],
		filterBy: { operators: [ 'is' ] },
		enableSorting: false,
	},
	{
		id: 'status',
		label: __( 'Status', 'leitvia-studio' ),
		elements: STATUSES,
		filterBy: { operators: [ 'isAny' ] },
		enableSorting: false,
	},
	{
		id: 'effective_status',
		label: __( 'Effective status', 'leitvia-studio' ),
		elements: STATUSES,
		filterBy: false,
		enableSorting: false,
	},
	{
		id: 'version',
		label: __( 'Version', 'leitvia-studio' ),
		enableSorting: false,
	},
	{
		id: 'categories',
		label: __( 'Categories', 'leitvia-studio' ),
		enableSorting: false,
		render: ( { item } ) => item.categories.join( ', ' ),
	},
	{
		id: 'variant_count',
		label: __( 'Variants', 'leitvia-studio' ),
		type: 'integer',
		enableSorting: false,
	},
	{
		id: 'checks',
		label: __( 'Checks', 'leitvia-studio' ),
		enableSorting: false,
		// Last accessibility check of the Lab (see checks-panel.js) and the token lint, one line each.
		render: ( { item } ) => {
			const axe = item.checks?.axe;
			let accessibility = __( 'Not checked', 'leitvia-studio' );
			if ( axe && item.checks.axe_checked_at ) {
				const count = countIssues( axe );
				accessibility = sprintf(
					/* translators: 1: check result such as No issues, 2: date of the check. */
					__( '%1$s (%2$s)', 'leitvia-studio' ),
					count
						? sprintf(
								/* translators: %d: number of accessibility issues. */
								_n(
									'%d issue',
									'%d issues',
									count,
									'leitvia-studio'
								),
								count
							)
						: __( 'No issues', 'leitvia-studio' ),
					new Date( item.checks.axe_checked_at ).toLocaleDateString(
						document.documentElement.lang || undefined
					)
				);
				if ( isOutdated( item ) ) {
					accessibility = sprintf(
						/* translators: %s: result of the last check. */
						__( 'Outdated: %s', 'leitvia-studio' ),
						accessibility
					);
				}
			}
			const tokens = item.checks?.tokens?.count;
			let tokenText = __( 'Tokens not checked', 'leitvia-studio' );
			if ( undefined !== tokens ) {
				tokenText = tokens
					? sprintf(
							/* translators: %d: number of token findings. */
							_n(
								'%d token finding',
								'%d token findings',
								tokens,
								'leitvia-studio'
							),
							tokens
						)
					: __( 'No token findings', 'leitvia-studio' );
			}
			return (
				<>
					{ accessibility }
					<br />
					{ tokenText }
				</>
			);
		},
	},
];

const ACTIONS = [
	{
		id: 'open',
		label: __( 'Open', 'leitvia-studio' ),
		isPrimary: true,
		callback: ( [ item ] ) => window.location.assign( labUrl( item.ref ) ),
	},
	{
		id: 'edit',
		label: __( 'Edit in editor', 'leitvia-studio' ),
		isEligible: ( item ) => 'user' === item.source && !! item.edit_url,
		callback: ( [ item ] ) => window.location.assign( item.edit_url ),
	},
	{
		id: 'export',
		label: __( 'Export', 'leitvia-studio' ),
		isEligible: ( item ) => 'user' === item.source,
		callback: ( [ item ] ) => exportPattern( item ),
	},
];

/**
 * Downloads the export file of a wp_block pattern, named like the files core exports.
 *
 * @param {Object} item Lab item.
 */
async function exportPattern( item ) {
	try {
		const data = await apiFetch( {
			path: `/leitvia-studio/v1/patterns/${ item.ref.slice( 6 ) }/export`,
		} );
		const url = URL.createObjectURL(
			new Blob( [ JSON.stringify( data, null, 2 ) ], {
				type: 'application/json',
			} )
		);
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = `${ cleanForSlug( item.title ) || 'pattern' }.json`;
		link.click();
		// Revoked after the download has started; some browsers read the URL asynchronously.
		setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	} catch ( error ) {
		notify(
			'error',
			error.message ??
				__( 'The pattern could not be exported.', 'leitvia-studio' )
		);
	}
}

function ImportDialog( { onClose, onImported } ) {
	const [ files, setFiles ] = useState( [] );
	const [ errors, setErrors ] = useState( [] );
	const [ isBusy, setIsBusy ] = useState( false );

	const submit = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		const failed = [];
		let count = 0;
		for ( const file of files ) {
			try {
				const result = await apiFetch( {
					path: '/leitvia-studio/v1/patterns/import',
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: await file.text(),
				} );
				count++;
				if ( result.failed_variants ) {
					failed.push(
						sprintf(
							/* translators: 1: file name, 2: number of variants. */
							_n(
								'%1$s: the pattern was imported, but %2$d variant could not be saved.',
								'%1$s: the pattern was imported, but %2$d variants could not be saved.',
								result.failed_variants,
								'leitvia-studio'
							),
							file.name,
							result.failed_variants
						)
					);
				}
			} catch ( error ) {
				failed.push(
					sprintf(
						/* translators: 1: file name, 2: error message. */
						__( '%1$s: %2$s', 'leitvia-studio' ),
						file.name,
						error.message ??
							__(
								'The file could not be imported.',
								'leitvia-studio'
							)
					)
				);
			}
		}
		setIsBusy( false );
		if ( count ) {
			notify(
				'success',
				sprintf(
					/* translators: %d: number of imported patterns. */
					_n(
						'%d pattern imported.',
						'%d patterns imported.',
						count,
						'leitvia-studio'
					),
					count
				)
			);
			onImported();
		}
		setErrors( failed );
		if ( failed.length ) {
			speak( failed.join( ' ' ), 'assertive' );
		} else {
			onClose();
		}
	};

	return (
		<Modal
			title={ __( 'Import patterns', 'leitvia-studio' ) }
			onRequestClose={ onClose }
		>
			<form onSubmit={ submit }>
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 4 }
					align="stretch"
				>
					<p>
						{ sprintf(
							/* translators: %s: product name. */
							__(
								'Choose pattern files exported from WordPress or %s, JSON of at most 1 MB each. Approved patterns arrive in review, because their origin is not checked.',
								'leitvia-studio'
							),
							settings.brand ?? 'Leitvia Studio'
						) }
					</p>
					{ !! errors.length && (
						<Notice status="error" isDismissible={ false }>
							<ul>
								{ errors.map( ( error ) => (
									<li key={ error }>{ error }</li>
								) ) }
							</ul>
						</Notice>
					) }
					<div>
						<label
							className="leitvia-studio-import__label"
							htmlFor="leitvia-studio-import-files"
						>
							{ __( 'Pattern files', 'leitvia-studio' ) }
						</label>
						<input
							id="leitvia-studio-import-files"
							type="file"
							accept=".json,application/json"
							multiple
							onChange={ ( event ) =>
								setFiles( Array.from( event.target.files ) )
							}
						/>
					</div>
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
							disabled={ ! files.length || isBusy }
							accessibleWhenDisabled
						>
							{ __( 'Import', 'leitvia-studio' ) }
						</Button>
					</Flex>
				</Flex>
			</form>
		</Modal>
	);
}

// The usage index starts empty; the button indexes every post once, in batches, until the server reports done.
function UsageIndexNotice() {
	const [ state, setState ] = useState(
		settings.usageIndex ? 'done' : 'incomplete'
	);
	if ( 'done' === state ) {
		return null;
	}
	const build = async () => {
		setState( 'building' );
		speak( __( 'Building the usage index.', 'leitvia-studio' ) );
		try {
			let batch = { done: false, next: 0 };
			while ( ! batch.done ) {
				batch = await apiFetch( {
					path: '/leitvia-studio/v1/usage/reindex',
					method: 'POST',
					data: { after: batch.next },
				} );
			}
			setState( 'done' );
			notify( 'success', __( 'Usage index built.', 'leitvia-studio' ) );
		} catch ( error ) {
			setState( 'incomplete' );
			notify(
				'error',
				error.message ??
					__(
						'The usage index could not be built.',
						'leitvia-studio'
					)
			);
		}
	};
	const message = __( 'Usage index is incomplete', 'leitvia-studio' );
	// Notice turns children that are no string into text with renderToString, which runs the hooks of Flex, Button
	// and Spinner inside its own render; the spinner of the build then changes their number and React stops the
	// screen. The message as string avoids that.
	return (
		<Notice
			status="warning"
			isDismissible={ false }
			spokenMessage={ message }
		>
			<Flex justify="flex-start" wrap>
				<span>{ message }</span>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ build }
					isBusy={ 'building' === state }
					disabled={ 'building' === state }
					accessibleWhenDisabled
				>
					{ __( 'Build index', 'leitvia-studio' ) }
				</Button>
				{ 'building' === state && <Spinner /> }
			</Flex>
		</Notice>
	);
}

export default function Gallery() {
	const [ view, setView ] = useState( loadView );
	const [ data, setData ] = useState( {
		items: [],
		totalItems: 0,
		totalPages: 0,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isImporting, setIsImporting ] = useState( false );
	const [ reload, setReload ] = useState( 0 );

	const query = useMemo( () => {
		const filter = ( field ) =>
			view.filters?.find(
				( item ) => item.field === field && item.value?.length !== 0
			)?.value;
		return {
			search: view.search || undefined,
			status: filter( 'status' ),
			source: filter( 'source' ),
			page: view.page,
			per_page: view.perPage,
		};
	}, [ view ] );

	useEffect( () => {
		let current = true;
		setIsLoading( true );
		apiFetch( {
			path: addQueryArgs( '/leitvia-studio/v1/patterns', query ),
			parse: false,
		} )
			.then( async ( response ) => {
				const items = await response.json();
				if ( current ) {
					setData( {
						items,
						totalItems: Number(
							response.headers.get( 'X-WP-Total' )
						),
						totalPages: Number(
							response.headers.get( 'X-WP-TotalPages' )
						),
					} );
				}
			} )
			.catch(
				( error ) =>
					current &&
					notify(
						'error',
						error.message ??
							__(
								'The patterns could not be loaded.',
								'leitvia-studio'
							)
					)
			)
			.finally( () => current && setIsLoading( false ) );
		return () => {
			current = false;
		};
	}, [ query, reload ] );

	const onChangeView = ( next ) => {
		setView( next );
		saveView( next );
	};

	return (
		<>
			<Flex
				justify="flex-start"
				className="leitvia-studio-gallery__header"
			>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () => setIsImporting( true ) }
				>
					{ __( 'Import patterns', 'leitvia-studio' ) }
				</Button>
			</Flex>
			<UsageIndexNotice />
			{ isImporting && (
				<ImportDialog
					onClose={ () => setIsImporting( false ) }
					onImported={ () => setReload( reload + 1 ) }
				/>
			) }
			<DataViews
				data={ data.items }
				fields={ FIELDS }
				view={ view }
				onChangeView={ onChangeView }
				actions={ ACTIONS }
				getItemId={ ( item ) => item.ref }
				isLoading={ isLoading }
				paginationInfo={ {
					totalItems: data.totalItems,
					totalPages: data.totalPages,
				} }
				defaultLayouts={ DEFAULT_LAYOUTS }
				searchLabel={ __( 'Search patterns', 'leitvia-studio' ) }
				empty={
					<p>{ __( 'No patterns found.', 'leitvia-studio' ) }</p>
				}
			/>
		</>
	);
}
