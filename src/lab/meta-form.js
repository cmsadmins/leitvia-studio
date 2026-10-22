// Lab metadata of a pattern on DataForm. wp_block patterns save through /wp/v2/blocks; theme patterns are read-only.
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataForm } from '@wordpress/dataviews/wp';
import { useDebounce } from '@wordpress/compose';
import {
	Button,
	ComboboxControl,
	Flex,
	CheckboxControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { STATUSES } from './common';
import { notify } from '../admin/notify';

const META = {
	status: '_leitvia_lab_status',
	version: '_leitvia_lab_version',
	replaced_by: '_leitvia_lab_replaced_by',
	changelog: '_leitvia_lab_changelog',
};

const today = () => new Date().toISOString().slice( 0, 10 );

function ChangelogEdit( { data, field, onChange } ) {
	const [ draft, setDraft ] = useState( null );
	const entries = data.changelog;
	const set = ( key ) => ( value ) =>
		setDraft( { ...draft, [ key ]: value } );
	return (
		<Flex direction="column" justify="flex-start" gap={ 3 } align="stretch">
			<span className="leitvia-studio-meta-form__label">
				{ field.label }
			</span>
			{ entries.length ? (
				<ul className="leitvia-studio-changelog">
					{ entries.map( ( entry, index ) => (
						<li key={ index }>
							<strong>{ entry.version }</strong> { entry.date }
							{ entry.breaking && (
								<>
									{ ' ' }
									{ __(
										'(breaking change)',
										'leitvia-studio'
									) }
								</>
							) }
							{ entry.note && <>: { entry.note }</> }
						</li>
					) ) }
				</ul>
			) : (
				<p>{ __( 'No entries yet.', 'leitvia-studio' ) }</p>
			) }
			{ draft ? (
				<fieldset className="leitvia-studio-changelog__new">
					<legend>{ __( 'New entry', 'leitvia-studio' ) }</legend>
					<Flex
						direction="column"
						justify="flex-start"
						gap={ 3 }
						align="stretch"
					>
						<TextControl
							id="leitvia-studio-changelog-version"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Version', 'leitvia-studio' ) }
							value={ draft.version }
							onChange={ set( 'version' ) }
						/>
						<TextControl
							id="leitvia-studio-changelog-date"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="date"
							label={ __( 'Date', 'leitvia-studio' ) }
							value={ draft.date }
							onChange={ set( 'date' ) }
						/>
						<CheckboxControl
							id="leitvia-studio-changelog-breaking"
							__nextHasNoMarginBottom
							label={ __( 'Breaking change', 'leitvia-studio' ) }
							checked={ draft.breaking }
							onChange={ set( 'breaking' ) }
						/>
						<TextareaControl
							id="leitvia-studio-changelog-note"
							__nextHasNoMarginBottom
							label={ __( 'Note', 'leitvia-studio' ) }
							value={ draft.note }
							onChange={ set( 'note' ) }
						/>
						<Flex justify="flex-start">
							<Button
								__next40pxDefaultSize
								variant="secondary"
								onClick={ () => {
									onChange( {
										changelog: [ draft, ...entries ],
									} );
									setDraft( null );
								} }
							>
								{ __( 'Add to changelog', 'leitvia-studio' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ () => setDraft( null ) }
							>
								{ __( 'Cancel', 'leitvia-studio' ) }
							</Button>
						</Flex>
					</Flex>
				</fieldset>
			) : (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					className="leitvia-studio-changelog__add"
					onClick={ () =>
						setDraft( {
							version: data.version,
							date: today(),
							breaking: false,
							note: '',
						} )
					}
				>
					{ __( 'Add entry', 'leitvia-studio' ) }
				</Button>
			) }
		</Flex>
	);
}

/**
 * Field of a failed save. Schema errors name the meta key; the server checks the fields together
 * (Pattern_Meta::check()) and its message is shown at the field the rule is about, in the order the server checks them.
 *
 * @param {Object} error Error response.
 * @param {Object} data  Data that was sent.
 * @return {string} Field ID.
 */
function fieldOfError( error, data ) {
	const text =
		JSON.stringify( error.data?.params ?? {} ) + ( error.message ?? '' );
	const named = Object.entries( META ).find( ( [ , key ] ) =>
		text.includes( key )
	);
	if ( named ) {
		return named[ 0 ];
	}
	if ( 'deprecated' === data.status && ! data.replaced_by ) {
		return 'replaced_by';
	}
	return 'approved' === data.status ? 'version' : 'changelog';
}

const fromMeta = ( meta ) =>
	Object.fromEntries(
		Object.entries( META ).map( ( [ id, key ] ) => [ id, meta[ key ] ] )
	);

/**
 * Replacement picker: searches all patterns as the user types instead of loading them all.
 *
 * @param {Object} props
 * @param {Object} props.data     Form data.
 * @param {Object} props.field    Field.
 * @param {Object} props.onChange Change handler of DataForm.
 * @param {string} props.exclude  Reference of the pattern itself.
 */
function ReplacementEdit( { data, field, onChange, exclude } ) {
	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const debouncedSearch = useDebounce( setSearch, 300 );

	useEffect( () => {
		let current = true;
		apiFetch( {
			path: addQueryArgs( '/leitvia-studio/v1/patterns', {
				search: search || undefined,
				per_page: 20,
			} ),
		} )
			.then( ( items ) => {
				if ( current ) {
					setOptions(
						items
							.filter( ( entry ) => entry.ref !== exclude )
							.map( ( entry ) => ( {
								value: entry.ref,
								label: `${ entry.title } (${ entry.ref })`,
							} ) )
					);
				}
			} )
			.catch( () => current && setOptions( [] ) );
		return () => {
			current = false;
		};
	}, [ search, exclude ] );

	const value = data.replaced_by;
	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ field.label }
			help={ __( 'Type to search all patterns.', 'leitvia-studio' ) }
			value={ value }
			options={
				value && ! options.some( ( option ) => option.value === value )
					? [ { value, label: value }, ...options ]
					: options
			}
			onFilterValueChange={ debouncedSearch }
			onChange={ ( next ) => onChange( { replaced_by: next ?? '' } ) }
		/>
	);
}

export default function MetaForm( { item, block, onSaved } ) {
	const readOnly = ! block;
	const [ data, setData ] = useState( () =>
		block
			? fromMeta( block.meta )
			: {
					status: item.status,
					version: item.version,
					replaced_by: item.replaced_by,
					changelog: [],
				}
	);
	const [ validity, setValidity ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	// One component per pattern; a new one on every render would reset the search.
	const ReplacementField = useMemo(
		() => ( props ) => (
			<ReplacementEdit { ...props } exclude={ item.ref } />
		),
		[ item.ref ]
	);

	const fields = [
		{
			id: 'status',
			label: __( 'Status', 'leitvia-studio' ),
			type: 'text',
			elements: STATUSES,
			Edit: 'select',
			readOnly,
		},
		{
			id: 'version',
			label: __( 'Version', 'leitvia-studio' ),
			type: 'text',
			readOnly,
		},
		{
			id: 'replaced_by',
			label: __( 'Replaced by', 'leitvia-studio' ),
			type: 'text',
			Edit: ReplacementField,
			render: ( { item: values } ) =>
				values.replaced_by || __( 'None', 'leitvia-studio' ),
			readOnly,
		},
		{
			id: 'changelog',
			label: __( 'Changelog', 'leitvia-studio' ),
			Edit: ChangelogEdit,
			render: ( { item: values } ) =>
				sprintf(
					/* translators: %d: number of changelog entries. */
					_n(
						'%d entry',
						'%d entries',
						values.changelog.length,
						'leitvia-studio'
					),
					values.changelog.length
				),
			readOnly,
		},
	];

	const save = async () => {
		setIsSaving( true );
		try {
			const saved = await apiFetch( {
				path: `/wp/v2/blocks/${ block.id }`,
				method: 'POST',
				data: {
					meta: Object.fromEntries(
						Object.entries( META ).map( ( [ id, key ] ) => [
							key,
							data[ id ],
						] )
					),
				},
			} );
			setData( fromMeta( saved.meta ) );
			setValidity( {} );
			notify(
				'success',
				__( 'Pattern details saved.', 'leitvia-studio' )
			);
			onSaved();
		} catch ( error ) {
			const message =
				error.message ??
				__(
					'The pattern details could not be saved.',
					'leitvia-studio'
				);
			setValidity( {
				[ fieldOfError( error, data ) ]: {
					custom: { type: 'invalid', message },
				},
			} );
			notify( 'error', message );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Flex
			direction="column"
			justify="flex-start"
			gap={ 4 }
			align="stretch"
			className="leitvia-studio-meta-form"
		>
			{ readOnly && (
				<p>
					{ __(
						'Theme patterns are maintained in the theme; their details are read-only here.',
						'leitvia-studio'
					) }
				</p>
			) }
			<DataForm
				data={ data }
				fields={ fields }
				form={ {
					// Read-only fields render as plain text; the panel layout would show them as disabled controls.
					layout: {
						type: readOnly ? 'regular' : 'panel',
						labelPosition: 'top',
					},
					fields: Object.keys( META ),
				} }
				validity={ validity }
				onChange={ ( edits ) =>
					setData( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			{ ! readOnly && (
				<Flex justify="flex-start">
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ save }
						isBusy={ isSaving }
						disabled={ isSaving }
						accessibleWhenDisabled
					>
						{ __( 'Save details', 'leitvia-studio' ) }
					</Button>
				</Flex>
			) }
		</Flex>
	);
}
