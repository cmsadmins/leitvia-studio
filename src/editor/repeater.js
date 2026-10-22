// Repeater: child posts of the edited entry (post_parent, menu_order), listed as a DataViews table. Rows are saved
// right away through core data, independent of the entry; Registrar gives them the status inherit.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore, useEntityRecords } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { Button, Flex, Modal, Notice } from '@wordpress/components';
import { DataForm, DataViews, useFormValidity } from '@wordpress/dataviews/wp';
import { speak } from '@wordpress/a11y';

const TITLE = {
	id: 'title',
	label: __( 'Title', 'leitvia-studio' ),
	type: 'text',
	isValid: { required: true },
	enableHiding: false,
	getValue: ( { item } ) => item.title?.raw ?? item.title ?? '',
};

const NO_LAYOUTS = { table: {} };

// The row being edited: title as text, meta as stored.
const draftOf = ( record ) => ( {
	title: record.title?.raw ?? '',
	meta: record.meta ?? {},
} );

function RowForm( { fields, form, item, saveLabel, onSave, onCancel } ) {
	const [ draft, setDraft ] = useState( item );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const { validity, isValid } = useFormValidity( draft, fields, form );

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		try {
			// Only changed meta: core refuses some empty stored values when they are sent back unchanged.
			await onSave( {
				title: draft.title,
				meta: Object.fromEntries(
					Object.entries( draft.meta ).filter(
						( [ key, value ] ) => value !== item.meta[ key ]
					)
				),
			} );
		} catch ( caught ) {
			setError(
				caught.message ??
					__( 'The entry could not be saved.', 'leitvia-studio' )
			);
			setBusy( false );
		}
	};

	return (
		<form onSubmit={ submit } noValidate>
			<Flex direction="column" gap={ 4 } align="stretch">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<DataForm
					data={ draft }
					fields={ fields }
					form={ form }
					validity={ validity }
					onChange={ ( edits ) =>
						setDraft( ( current ) => ( {
							...current,
							...edits,
							meta: { ...current.meta, ...edits.meta },
						} ) )
					}
				/>
				<Flex justify="flex-end">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'leitvia-studio' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! isValid }
						accessibleWhenDisabled
					>
						{ saveLabel }
					</Button>
				</Flex>
			</Flex>
		</form>
	);
}

export default function Repeater( {
	repeater,
	fields: rowFields,
	entryLabel,
} ) {
	const { postType, label, singularLabel } = repeater;
	const { postId, isNew } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			isNew: editor.isEditedPostNew(),
		};
	}, [] );
	const { saveEntityRecord, deleteEntityRecord } = useDispatch( coreStore );
	const [ adding, setAdding ] = useState( false );
	const [ moved, setMoved ] = useState( null );
	const list = useRef();
	const [ view, setView ] = useState( {
		type: 'table',
		titleField: 'title',
		fields: rowFields.slice( 0, 2 ).map( ( field ) => field.id ),
		page: 1,
		perPage: 100,
		layout: {},
	} );
	const { records, isResolving } = useEntityRecords(
		'postType',
		postType,
		{
			parent: postId,
			status: 'inherit',
			orderby: 'menu_order',
			order: 'asc',
			per_page: 100,
		},
		{ enabled: ! isNew }
	);

	const fields = [ TITLE, ...rowFields ];
	const form = {
		layout: { type: 'regular', labelPosition: 'top' },
		fields: fields.map( ( field ) => field.id ),
	};
	// Moved rows keep their place in the cached query until it reloads; menu_order decides.
	const rows = [ ...( records ?? [] ) ].sort(
		( a, b ) => a.menu_order - b.menu_order
	);
	// After a move the focus goes to the actions button of the moved row, in its new place.
	useEffect( () => {
		const index = rows.findIndex( ( row ) => row.id === moved );
		if ( index < 0 ) {
			return;
		}
		list.current
			?.querySelectorAll( 'tbody tr.dataviews-view-table__row' )
			[ index ]?.querySelector(
				'.dataviews-view-table__actions-column button'
			)
			?.focus();
		setMoved( null );
	}, [ moved, rows ] );

	const save = ( record ) =>
		saveEntityRecord( 'postType', postType, record, {
			throwOnError: true,
		} );

	const move = async ( item, step ) => {
		const order = rows.filter( ( row ) => row.id !== item.id );
		const target = rows.findIndex( ( row ) => row.id === item.id ) + step;
		if ( target < 0 || target > order.length ) {
			return;
		}
		order.splice( target, 0, item );
		try {
			await Promise.all(
				order
					.map( ( row, index ) => [ row, index ] )
					.filter( ( [ row, index ] ) => row.menu_order !== index )
					.map( ( [ row, index ] ) =>
						save( { id: row.id, menu_order: index } )
					)
			);
			setMoved( item.id );
			speak(
				sprintf(
					/* translators: 1: entry title, 2: new position, 3: number of entries. */
					__(
						'%1$s moved to position %2$d of %3$d.',
						'leitvia-studio'
					),
					item.title?.raw,
					target + 1,
					rows.length
				)
			);
		} catch ( caught ) {
			speak( caught.message, 'assertive' );
		}
	};

	const actions = [
		{
			id: 'edit',
			label: __( 'Edit', 'leitvia-studio' ),
			modalHeader: sprintf(
				/* translators: %s: singular label of the repeater, for example Session. */
				__( 'Edit %s', 'leitvia-studio' ),
				singularLabel
			),
			RenderModal: ( { items: [ item ], closeModal } ) => (
				<RowForm
					fields={ fields }
					form={ form }
					item={ draftOf( item ) }
					saveLabel={ __( 'Save', 'leitvia-studio' ) }
					onCancel={ closeModal }
					onSave={ async ( changes ) => {
						await save( { id: item.id, ...changes } );
						closeModal();
					} }
				/>
			),
		},
		{
			id: 'move-up',
			label: __( 'Move up', 'leitvia-studio' ),
			isEligible: ( item ) => rows[ 0 ]?.id !== item.id,
			callback: ( [ item ] ) => move( item, -1 ),
		},
		{
			id: 'move-down',
			label: __( 'Move down', 'leitvia-studio' ),
			isEligible: ( item ) => rows[ rows.length - 1 ]?.id !== item.id,
			callback: ( [ item ] ) => move( item, 1 ),
		},
		{
			id: 'delete',
			label: __( 'Delete', 'leitvia-studio' ),
			isDestructive: true,
			modalHeader: sprintf(
				/* translators: %s: singular label of the repeater, for example Session. */
				__( 'Delete %s', 'leitvia-studio' ),
				singularLabel
			),
			RenderModal: ( { items: [ item ], closeModal } ) => (
				<Flex direction="column" gap={ 4 } align="stretch">
					<p>
						{ sprintf(
							/* translators: %s: entry title. */
							__( 'Move "%s" to the trash?', 'leitvia-studio' ),
							item.title?.raw
						) }
					</p>
					<Flex justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ closeModal }
						>
							{ __( 'Cancel', 'leitvia-studio' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							onClick={ async () => {
								try {
									await deleteEntityRecord(
										'postType',
										postType,
										item.id,
										{},
										{ throwOnError: true }
									);
									speak(
										__( 'Entry deleted.', 'leitvia-studio' )
									);
								} catch ( caught ) {
									speak( caught.message, 'assertive' );
								}
								closeModal();
							} }
						>
							{ __( 'Delete', 'leitvia-studio' ) }
						</Button>
					</Flex>
				</Flex>
			),
		},
	];

	return (
		<section className="leitvia-studio-repeater">
			<h3 className="leitvia-studio-repeater__title">{ label }</h3>
			{ isNew ? (
				<p>
					{ sprintf(
						/* translators: 1: singular label of the post type, for example Event; 2: repeater label, for example Agenda. */
						__(
							'Save the %1$s once to add entries to %2$s.',
							'leitvia-studio'
						),
						entryLabel,
						label
					) }
				</p>
			) : (
				<>
					<p className="leitvia-studio-repeater__note">
						{ sprintf(
							/* translators: %s: repeater label, for example Agenda. */
							__(
								'Changes to %s are saved immediately.',
								'leitvia-studio'
							),
							label
						) }
					</p>
					<div ref={ list }>
						<DataViews
							data={ rows }
							fields={ fields }
							view={ view }
							onChangeView={ setView }
							actions={ actions }
							getItemId={ ( item ) => String( item.id ) }
							isLoading={ isResolving && ! records }
							paginationInfo={ {
								totalItems: rows.length,
								totalPages: 1,
							} }
							defaultLayouts={ NO_LAYOUTS }
							search={ false }
							empty={
								<p>
									{ __(
										'No entries yet.',
										'leitvia-studio'
									) }
								</p>
							}
						/>
					</div>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () => setAdding( true ) }
					>
						{ sprintf(
							/* translators: %s: singular label of the repeater, for example Session. */
							__( 'Add %s', 'leitvia-studio' ),
							singularLabel
						) }
					</Button>
				</>
			) }
			{ adding && (
				<Modal
					title={ sprintf(
						/* translators: %s: singular label of the repeater, for example Session. */
						__( 'Add %s', 'leitvia-studio' ),
						singularLabel
					) }
					onRequestClose={ () => setAdding( false ) }
					focusOnMount="firstContentElement"
				>
					<RowForm
						fields={ fields }
						form={ form }
						item={ {
							title: `${ singularLabel } ${ rows.length + 1 }`,
							meta: {},
						} }
						saveLabel={ __( 'Add', 'leitvia-studio' ) }
						onCancel={ () => setAdding( false ) }
						onSave={ async ( changes ) => {
							await save( {
								...changes,
								parent: postId,
								menu_order: rows.length,
							} );
							setAdding( false );
							speak( __( 'Entry added.', 'leitvia-studio' ) );
						} }
					/>
				</Modal>
			) }
		</section>
	);
}
