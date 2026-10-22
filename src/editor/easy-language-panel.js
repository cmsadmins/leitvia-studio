// Document settings panel of pages: marks the edited page as the easy language version of another page (meta
// _leitvia_easy_language_of, registered in class-easy-language.php). The language switch links both pages.
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';
import { ComboboxControl } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';

const META = '_leitvia_easy_language_of';

export default function EasyLanguagePanel() {
	const [ search, setSearch ] = useState( '' );
	const { postType, postId, original } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType(),
			postId: editor.getCurrentPostId(),
			original: editor.getEditedPostAttribute( 'meta' )?.[ META ] || 0,
		};
	}, [] );
	const pages = useSelect(
		( select ) => {
			if ( 'page' !== postType ) {
				return [];
			}
			const { getEntityRecord, getEntityRecords } = select( coreStore );
			const found =
				getEntityRecords( 'postType', 'page', {
					per_page: 20,
					search,
					exclude: [ postId ],
					status: [ 'publish', 'draft', 'future', 'private' ],
					context: 'view',
				} ) ?? [];
			const current = original
				? getEntityRecord( 'postType', 'page', original )
				: null;
			return current && ! found.some( ( p ) => p.id === original )
				? [ current, ...found ]
				: found;
		},
		[ postType, postId, original, search ]
	);
	const { editPost } = useDispatch( editorStore );
	if ( 'page' !== postType ) {
		return null;
	}
	return (
		<PluginDocumentSettingPanel
			name="leitvia-studio-easy-language"
			title={ __( 'Easy language', 'leitvia-studio' ) }
		>
			<ComboboxControl
				__next40pxDefaultSize
				label={ __(
					'This page is the easy language version of',
					'leitvia-studio'
				) }
				help={ __(
					'Choose the original page. The Easy language switch then links both pages once both are published. Leave empty for a page in standard language.',
					'leitvia-studio'
				) }
				value={ original ? String( original ) : null }
				options={ pages.map( ( page ) => ( {
					value: String( page.id ),
					label:
						decodeEntities( page.title?.rendered ?? '' ) ||
						__( '(no title)', 'leitvia-studio' ),
				} ) ) }
				onFilterValueChange={ setSearch }
				onChange={ ( value ) =>
					editPost( {
						meta: { [ META ]: value ? Number( value ) : 0 },
					} )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}
