// Relation field: post IDs of the target type. A combobox searches posts by title and stores the ID of the chosen one;
// with several posts allowed, the linked ones are listed with a remove button each. The search lists published posts;
// Registrar::check_rest_meta() checks newly added IDs again on save.
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, ComboboxControl, VisuallyHidden } from '@wordpress/components';
import { useDebounce, useInstanceId } from '@wordpress/compose';
import { speak } from '@wordpress/a11y';
import { useEntityRecords } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';

const titleOf = ( record ) =>
	decodeEntities( record.title?.raw || record.title?.rendered || '' ) ||
	__( '(no title)', 'leitvia-studio' );

// Linked posts by ID, loaded when the field has IDs.
function useLinked( to, ids ) {
	const { records } = useEntityRecords(
		'postType',
		to,
		{ include: ids, per_page: ids.length || 1 },
		{ enabled: ids.length > 0 }
	);
	return useMemo(
		() =>
			new Map(
				( records ?? [] ).map( ( record ) => [ record.id, record ] )
			),
		[ records ]
	);
}

/**
 * Display of a relation: the titles of the linked posts.
 *
 * @param {string} to Target post type.
 * @return {import('react').ComponentType<Object>} DataForm render component.
 */
export function relationTitles( to ) {
	return function RelationTitles( { item, field } ) {
		const ids = field.getValue( { item } ) ?? [];
		const linked = useLinked( to, ids );
		return ids
			.filter( ( id ) => linked.has( id ) )
			.map( ( id ) => titleOf( linked.get( id ) ) )
			.join( ', ' );
	};
}

/**
 * Edit control of a relation, bound to its target post type.
 *
 * @param {Object}  relation          Relation of the declaration.
 * @param {string}  relation.to       Target post type.
 * @param {boolean} relation.multiple Whether several posts may be linked.
 * @return {import('react').ComponentType<Object>} DataForm Edit component.
 */
export default function relationField( { to, multiple } ) {
	return function RelationField( {
		data,
		field,
		onChange,
		hideLabelFromVision,
	} ) {
		const ids = field.getValue( { item: data } ) ?? [];
		const labelId = useInstanceId(
			RelationField,
			'leitvia-studio-relation-label'
		);
		const [ search, setSearch ] = useState( '' );
		// One search request after typing pauses, not one per key.
		const searchLater = useDebounce( setSearch, 300 );
		const group = useRef();
		const focusAfterRemove = useRef( null );
		const { records: found } = useEntityRecords( 'postType', to, {
			search,
			per_page: 20,
		} );
		const linked = useLinked( to, ids );
		const set = ( value ) =>
			onChange( field.setValue( { item: data, value } ) );
		// A post just picked is in the search results before the linked posts reload.
		const known = ( id ) =>
			linked.get( id ) ?? found?.find( ( record ) => record.id === id );
		const label = ( id ) =>
			known( id )
				? titleOf( known( id ) )
				: /* translators: %d: post ID. */
					sprintf( __( 'Post %d', 'leitvia-studio' ), id );

		const options = [
			// The linked post of a single relation stays an option, so the combobox can show it.
			...( multiple ? [] : [ ...linked.values() ] ),
			...( found ?? [] ).filter(
				( record ) =>
					! ids.includes( record.id ) ||
					( ! multiple && record.id === ids[ 0 ] )
			),
		]
			.filter(
				( record, index, all ) =>
					all.findIndex( ( other ) => other.id === record.id ) ===
					index
			)
			.map( ( record ) => ( {
				value: String( record.id ),
				label: titleOf( record ),
			} ) );

		const combobox = (
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={
					multiple
						? sprintf(
								/* translators: %s: relation label, for example Speakers. */
								__( 'Add to %s', 'leitvia-studio' ),
								field.label
							)
						: field.label
				}
				help={ field.description }
				hideLabelFromVision={ hideLabelFromVision }
				value={ ! multiple && ids[ 0 ] ? String( ids[ 0 ] ) : null }
				options={ options }
				onFilterValueChange={ searchLater }
				onChange={ ( value ) => {
					if ( ! multiple ) {
						set( value ? [ Number( value ) ] : [] );
					} else if ( value ) {
						set( [ ...ids, Number( value ) ] );
						speak(
							sprintf(
								/* translators: %s: title of a linked post. */
								__( '%s added.', 'leitvia-studio' ),
								label( Number( value ) )
							)
						);
					}
				} }
			/>
		);
		// After a removal the focus goes to the next remove button, else the previous one, else the combobox.
		useEffect( () => {
			const index = focusAfterRemove.current;
			if ( null === index || ! group.current ) {
				return;
			}
			focusAfterRemove.current = null;
			const buttons = group.current.querySelectorAll(
				'.leitvia-studio-relation__list button'
			);
			(
				buttons[ Math.min( index, buttons.length - 1 ) ] ??
				group.current.querySelector( 'input' )
			)?.focus();
		}, [ ids.length ] );

		if ( ! multiple ) {
			return combobox;
		}

		return (
			<div
				ref={ group }
				role="group"
				aria-labelledby={ labelId }
				className="leitvia-studio-relation"
			>
				{ hideLabelFromVision ? (
					<VisuallyHidden id={ labelId }>
						{ field.label }
					</VisuallyHidden>
				) : (
					<p
						id={ labelId }
						className="leitvia-studio-relation__label"
					>
						{ field.label }
					</p>
				) }
				{ ids.length > 0 && (
					<ul className="leitvia-studio-relation__list">
						{ ids.map( ( id, index ) => (
							<li key={ id }>
								<span>{ label( id ) }</span>
								<Button
									variant="link"
									isDestructive
									aria-label={ sprintf(
										/* translators: %s: title of a linked post. */
										__( 'Remove %s', 'leitvia-studio' ),
										label( id )
									) }
									onClick={ () => {
										focusAfterRemove.current = index;
										set(
											ids.filter(
												( other ) => other !== id
											)
										);
										speak(
											sprintf(
												/* translators: %s: title of a linked post. */
												__(
													'%s removed.',
													'leitvia-studio'
												),
												label( id )
											)
										);
									} }
								>
									{ __( 'Remove', 'leitvia-studio' ) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
				{ combobox }
			</div>
		);
	};
}
