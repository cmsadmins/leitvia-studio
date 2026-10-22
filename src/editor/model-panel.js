// Document settings panel of a content model post type: the declared fields on DataForm, stored as post meta with
// the post, then one list per repeater. Saving stays locked while a field is invalid, a required one empty.
import { __, sprintf } from '@wordpress/i18n';
import { Suspense, lazy, useEffect, useMemo } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { Notice, Spinner } from '@wordpress/components';
import { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
import { toFields, toForm } from './fields.mjs';
import ImageField from './image-field';
import relationField, { relationTitles } from './relation-field';

// The repeater list loads only for types that declare repeaters.
const Repeater = lazy( () => import( './repeater' ) );

const LOCK = 'leitvia-studio';

/**
 * Fields of a post type with the editor controls for images and relations.
 *
 * @param {Object} declaration Declaration from Registrar::editor_declaration().
 * @param {string} postType    Post type or repeater post type.
 * @return {Object[]} DataForm fields.
 */
function fieldsFor( declaration, postType ) {
	return toFields( declaration, postType ).map( ( field ) => {
		if ( 'image' === field.Edit ) {
			return { ...field, Edit: ImageField };
		}
		if ( 'relation' === field.Edit ) {
			return {
				...field,
				Edit: relationField( field.relation ),
				render: relationTitles( field.relation.to ),
			};
		}
		return field;
	} );
}

const isInvalid = ( validity ) =>
	Object.values( validity ?? {} ).some(
		( rule ) => rule?.type === 'invalid'
	);

/**
 * The panel of the declaration's post type. Fields and controls are built once, outside of rendering.
 *
 * @param {Object} declaration Declaration from Registrar::editor_declaration().
 * @return {import('react').ComponentType} Panel component for registerPlugin().
 */
export default function modelPanel( declaration ) {
	const { postType } = declaration;
	const type = declaration.postTypes[ postType ];
	const fields = fieldsFor( declaration, postType );
	const form = toForm( declaration, postType );
	const repeaters = Object.entries( type.repeaters ?? {} ).map(
		( [ key, repeater ] ) => ( {
			key,
			repeater,
			fields: fieldsFor( declaration, repeater.postType ),
		} )
	);

	return function ModelPanel() {
		const meta = useSelect(
			( select ) =>
				select( editorStore ).getEditedPostAttribute( 'meta' ),
			[]
		);
		const { editPost, lockPostSaving, unlockPostSaving } =
			useDispatch( editorStore );
		const data = useMemo( () => ( { meta: meta ?? {} } ), [ meta ] );
		const { validity, isValid } = useFormValidity( data, fields, form );
		const invalid = fields
			.filter( ( field ) => isInvalid( validity?.[ field.id ] ) )
			.map( ( field ) => field.label );

		useEffect( () => {
			if ( isValid ) {
				unlockPostSaving( LOCK );
			} else {
				lockPostSaving( LOCK );
			}
		}, [ isValid, lockPostSaving, unlockPostSaving ] );
		useEffect( () => () => unlockPostSaving( LOCK ), [ unlockPostSaving ] );

		return (
			<PluginDocumentSettingPanel
				name="leitvia-studio-model"
				className="leitvia-studio-model-panel"
				title={ sprintf(
					/* translators: %s: singular label of the post type, for example Event. */
					__( '%s details', 'leitvia-studio' ),
					type.singularLabel
				) }
			>
				{ invalid.length > 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: comma separated field labels. */
							__(
								'Saving is locked until these fields are valid: %s.',
								'leitvia-studio'
							),
							invalid.join( ', ' )
						) }
					</Notice>
				) }
				{ fields.length > 0 && (
					<DataForm
						data={ data }
						fields={ fields }
						form={ form }
						validity={ validity }
						onChange={ ( edits ) =>
							editPost( { meta: edits.meta } )
						}
					/>
				) }
				{ repeaters.length > 0 && (
					<Suspense fallback={ <Spinner /> }>
						{ repeaters.map( ( item ) => (
							<Repeater
								key={ item.key }
								repeater={ item.repeater }
								fields={ item.fields }
								entryLabel={ type.singularLabel }
							/>
						) ) }
					</Suspense>
				) }
			</PluginDocumentSettingPanel>
		);
	};
}
