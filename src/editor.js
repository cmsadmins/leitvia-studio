// Entry point of the block editor integration: the format "Highlight", the block bindings sources, the "Easy language"
// panel of pages, the "Child entries" query variation and, in the editor of a content model type, the model panel.
// Registrar::enqueue_editor() loads it in
// the post and site editor, with window.leitviaStudioBindings while the content model declares something to bind, and
// prints window.leitviaStudioModel: in the post editor the declaration of the edited type, in the site editor every type with
// repeaters (no panel there). The panel with DataForm loads on demand.
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { registerBlockVariation } from '@wordpress/blocks';
import { registerBindings } from './editor/bindings';
import EasyLanguagePanel from './editor/easy-language-panel';
import './editor/style.scss';
import './formats/highlight';

// Shows itself only in the editor of a page.
registerPlugin( 'leitvia-studio-easy-language', { render: EasyLanguagePanel } );

const CHILDREN = 'leitvia/children';
const declaration = window.leitviaStudioModel;

if ( window.leitviaStudioBindings ) {
	registerBindings( window.leitviaStudioBindings );
}

if ( declaration ) {
	// One variation per repeater; the server limits it to the rows of the current entry
	// (Registrar::children_query_vars()). In the post editor the preview shows the rows of the edited entry.
	for ( const type of Object.values( declaration.postTypes ) ) {
		for ( const repeater of Object.values( type.repeaters ?? {} ) ) {
			registerBlockVariation( 'core/query', {
				name: `${ CHILDREN }/${ repeater.postType }`,
				title: sprintf(
					/* translators: %s: repeater label, for example Agenda. */
					__( 'Child entries: %s', 'leitvia-studio' ),
					repeater.label
				),
				description: declaration.postId
					? __(
							'Lists the child entries of this post in their order.',
							'leitvia-studio'
						)
					: __(
							'Lists the child entries of the shown post in their order. The preview in the site editor shows the entries of all posts.',
							'leitvia-studio'
						),
				isActive: ( attributes ) =>
					CHILDREN === attributes.namespace &&
					repeater.postType === attributes.query?.postType,
				attributes: {
					namespace: CHILDREN,
					query: {
						perPage: 100,
						pages: 0,
						offset: 0,
						postType: repeater.postType,
						order: 'asc',
						orderBy: 'menu_order',
						inherit: false,
						parents: declaration.postId
							? [ declaration.postId ]
							: [],
					},
				},
				allowedControls: [],
				innerBlocks: [
					[
						'core/post-template',
						{},
						[ [ 'core/post-title', { level: 3 } ] ],
					],
				],
				scope: [ 'inserter' ],
			} );
		}
	}

	// The panel with DataForm loads only in the editor of a content model type.
	if ( declaration.postType ) {
		import( './editor/model-panel' ).then( ( { default: modelPanel } ) =>
			registerPlugin( 'leitvia-studio-model', {
				render: modelPanel( declaration ),
			} )
		);
	}
}
