// Repeater: the rows of the edited entry (child posts, see Model\Entries) as a list of small forms. Rows are part of
// the entry: they come with it in the REST field leitvia_rows, every change is an edit of the entry, and Save writes
// entry and rows together, also for a new entry. Saving stays locked while a row lacks a required value or a field
// declared unique repeats.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { Button, Flex, Notice } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { speak } from '@wordpress/a11y';

const empty = ( value ) =>
	value === '' || value === null || value === undefined || value === false;

/**
 * Problems of the rows: a missing title or required value, a unique value used twice.
 *
 * @param {Object[]} rows     Rows with title and meta.
 * @param {Object}   repeater Repeater declaration.
 * @return {string[]} Messages, empty when the rows can be saved.
 */
export function rowProblems( rows, repeater ) {
	const problems = [];
	const seen = {};
	rows.forEach( ( row, index ) => {
		const name = `${ repeater.singularLabel } ${ index + 1 }`;
		if ( empty( row.title?.trim?.() ) ) {
			problems.push(
				sprintf(
					/* translators: 1: row name, for example Day 2; 2: label of the title, for example Dish. */
					__( '%1$s: %2$s is required.', 'leitvia-studio' ),
					name,
					repeater.titleLabel ?? __( 'Title', 'leitvia-studio' )
				)
			);
		}
		for ( const [ key, field ] of Object.entries(
			repeater.fields ?? {}
		) ) {
			const value = row.meta?.[ key ];
			if ( field.required && empty( value ) ) {
				problems.push(
					sprintf(
						/* translators: 1: row name, for example Day 2; 2: field label. */
						__( '%1$s: %2$s is required.', 'leitvia-studio' ),
						name,
						field.label
					)
				);
			}
			if ( field.unique && ! empty( value ) ) {
				seen[ key ] ??= new Set();
				if ( seen[ key ].has( value ) ) {
					problems.push(
						sprintf(
							/* translators: 1: field label, for example Weekday; 2: the value, for example Monday. */
							__( '%1$s %2$s appears twice.', 'leitvia-studio' ),
							field.label,
							field.options?.[ value ] ?? value
						)
					);
				}
				seen[ key ].add( value );
			}
		}
	} );
	return problems;
}

/**
 * A new row: for a unique choice field the next option not used yet after the last row, such as the next weekday.
 *
 * @param {Object[]} rows     Rows.
 * @param {Object}   repeater Repeater declaration.
 * @return {Object} Row with title and meta.
 */
export function nextRow( rows, repeater ) {
	const meta = {};
	for ( const [ key, field ] of Object.entries( repeater.fields ?? {} ) ) {
		if ( ! field.unique || ! field.options ) {
			continue;
		}
		const options = Object.keys( field.options );
		const used = rows.map( ( row ) => row.meta?.[ key ] );
		const last = options.indexOf( used.at( -1 ) );
		const next = [
			...options.slice( last + 1 ),
			...options.slice( 0, last + 1 ),
		].find( ( option ) => ! used.includes( option ) );
		if ( next ) {
			meta[ key ] = next;
		}
	}
	return { title: '', meta };
}

const LOCK = 'leitvia-studio-rows';

export default function Repeater( {
	repeater,
	fields: rowFields,
	entryLabel,
	rows,
	onChange,
} ) {
	const { label, singularLabel } = repeater;
	const { lockPostSaving, unlockPostSaving } = useDispatch( editorStore );
	const title = {
		id: 'title',
		label: repeater.titleLabel ?? __( 'Title', 'leitvia-studio' ),
		type: 'text',
		isValid: { required: true },
		getValue: ( { item } ) => item.title ?? '',
		setValue: ( { value } ) => ( { title: value } ),
	};
	// Fields declared unique (such as the weekday) name the row, so they come first, then the title.
	const unique = rowFields.filter(
		( field ) => repeater.fields?.[ field.id ]?.unique
	);
	const fields = [
		...unique,
		title,
		...rowFields.filter( ( field ) => ! unique.includes( field ) ),
	];
	const form = {
		layout: { type: 'regular', labelPosition: 'top' },
		fields: fields.map( ( field ) => field.id ),
	};
	const problems = rowProblems( rows, repeater );
	const lockKey = `${ LOCK }-${ repeater.postType }`;
	const list = useRef();
	const [ added, setAdded ] = useState( false );

	// A row just added takes the focus in its first field, so keyboard users fill it in right away.
	useEffect( () => {
		if ( ! added ) {
			return;
		}
		list.current
			?.querySelector(
				'.leitvia-studio-repeater__row:last-child :is(input, select, textarea)'
			)
			?.focus();
		setAdded( false );
	}, [ added, rows.length ] );

	useEffect( () => {
		if ( problems.length ) {
			lockPostSaving( lockKey );
		} else {
			unlockPostSaving( lockKey );
		}
	}, [ problems.length, lockKey, lockPostSaving, unlockPostSaving ] );
	useEffect(
		() => () => unlockPostSaving( lockKey ),
		[ lockKey, unlockPostSaving ]
	);

	const name = ( row, index ) => {
		const key = unique[ 0 ]?.id;
		const option =
			key && repeater.fields[ key ].options?.[ row.meta?.[ key ] ];
		return option ?? `${ singularLabel } ${ index + 1 }`;
	};
	const change = ( index, edits ) =>
		onChange(
			rows.map( ( row, at ) =>
				at === index
					? {
							...row,
							...edits,
							meta: { ...row.meta, ...edits.meta },
						}
					: row
			)
		);
	const move = ( index, step ) => {
		const next = [ ...rows ];
		const [ row ] = next.splice( index, 1 );
		next.splice( index + step, 0, row );
		onChange( next );
		speak(
			sprintf(
				/* translators: 1: row name, 2: new position, 3: number of rows. */
				__( '%1$s moved to position %2$d of %3$d.', 'leitvia-studio' ),
				name( row, index ),
				index + step + 1,
				rows.length
			)
		);
	};

	return (
		<section className="leitvia-studio-repeater">
			<h3 className="leitvia-studio-repeater__title">{ label }</h3>
			<p className="leitvia-studio-repeater__note">
				{ sprintf(
					/* translators: 1: repeater label, for example Agenda; 2: singular label of the post type, for example Event. */
					__(
						'%1$s: saved together with the %2$s.',
						'leitvia-studio'
					),
					label,
					entryLabel
				) }
			</p>
			{ problems.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ problems.join( ' ' ) }
				</Notice>
			) }
			<ol className="leitvia-studio-repeater__rows" ref={ list }>
				{ rows.map( ( row, index ) => (
					<li
						key={ row.id ?? `new-${ index }` }
						className="leitvia-studio-repeater__row"
					>
						<fieldset>
							<legend className="leitvia-studio-repeater__legend">
								{ name( row, index ) }
							</legend>
							<DataForm
								data={ row }
								fields={ fields }
								form={ form }
								onChange={ ( edits ) => change( index, edits ) }
							/>
							<Flex justify="flex-start" gap={ 1 }>
								<Button
									size="small"
									variant="tertiary"
									disabled={ index === 0 }
									accessibleWhenDisabled
									onClick={ () => move( index, -1 ) }
								>
									{ __( 'Move up', 'leitvia-studio' ) }
								</Button>
								<Button
									size="small"
									variant="tertiary"
									disabled={ index === rows.length - 1 }
									accessibleWhenDisabled
									onClick={ () => move( index, 1 ) }
								>
									{ __( 'Move down', 'leitvia-studio' ) }
								</Button>
								<Button
									size="small"
									variant="tertiary"
									isDestructive
									onClick={ () => {
										onChange(
											rows.filter(
												( _, at ) => at !== index
											)
										);
										speak(
											sprintf(
												/* translators: %s: row name. */
												__(
													'%s removed.',
													'leitvia-studio'
												),
												name( row, index )
											)
										);
									} }
								>
									{ __( 'Remove', 'leitvia-studio' ) }
								</Button>
							</Flex>
						</fieldset>
					</li>
				) ) }
			</ol>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={ () => {
					onChange( [ ...rows, nextRow( rows, repeater ) ] );
					setAdded( true );
					speak( __( 'Entry added.', 'leitvia-studio' ) );
				} }
			>
				{ sprintf(
					/* translators: %s: singular label of the repeater, for example Session. */
					__( 'Add %s', 'leitvia-studio' ),
					singularLabel
				) }
			</Button>
		</section>
	);
}
