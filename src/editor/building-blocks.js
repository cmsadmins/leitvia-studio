// Building blocks in the editor (window.leitviaStudioNeeds, Model\Building_Block_Views::editor_data()). A section whose
// metadata.patternName needs a building block that is missing, and a block whose attributes.postType (or
// query.postType) is a type of a missing building block, get a warning above them, outside the saved content:
// "This section needs the building block <title>." with the button "Set up <title>". The button runs the ability
// leitvia/add-building-block with samples and settle (a person sets the block up in one go); afterwards the block list
// of core data is reloaded and the section mounts
// again, so server-side renders and query loops fetch the new entries. Without edit_theme_options the warning asks
// for an administrator. A link with ?leitvia-inserter=<category> opens the inserter on the patterns of a block.
import { __, _n, sprintf } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { dispatch, select, subscribe, useSelect } from '@wordpress/data';
import { useState, useSyncExternalStore } from '@wordpress/element';
import { store as blockEditorStore, Warning } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { Button } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';

const RUN = '/wp-abilities/v1/abilities/leitvia/add-building-block/run';
const LIST = '/leitvia-studio/v1/building-blocks';

export function registerBuildingBlocks( needs ) {
	const blocks = new Map(
		needs.blocks.map( ( block ) => [ block.name, block ] )
	);
	// Set-up count per block: the key of the sections of that block, so they mount again once it is set up.
	const mounts = new Map();
	const listeners = new Set();
	const subscribeMounts = ( listener ) => {
		listeners.add( listener );
		return () => listeners.delete( listener );
	};
	const markInstalled = ( name ) => {
		blocks.set( name, { ...blocks.get( name ), installed: true } );
		mounts.set( name, ( mounts.get( name ) ?? 0 ) + 1 );
		listeners.forEach( ( listener ) => listener() );
	};

	/**
	 * The building block a block depends on: from its pattern name, else from the post type it lists.
	 *
	 * @param {Object} attributes Block attributes.
	 * @return {{name: string, viaType: boolean}|null} Block name and whether it came from the post type.
	 */
	const dependency = ( attributes ) => {
		const pattern = needs.patterns[ attributes?.metadata?.patternName ];
		if ( pattern ) {
			return { name: pattern.buildingBlock, viaType: false };
		}
		const type = attributes?.postType ?? attributes?.query?.postType;
		const name = 'string' === typeof type ? needs.postTypes[ type ] : '';
		return name && blocks.has( name ) ? { name, viaType: true } : null;
	};

	// Core data learnt the post types when the editor loaded; the new ones and their entries load again.
	const refresh = () => {
		const core = dispatch( coreStore );
		core.invalidateResolution( 'getEntitiesConfig', [ 'postType' ] );
		core.invalidateResolutionForStoreSelector( 'getPostTypes' );
		core.invalidateResolutionForStoreSelector( 'getEntityRecords' );
	};

	function SetUp( { name, clientId } ) {
		const [ busy, setBusy ] = useState( false );
		const [ status, setStatus ] = useState( '' );
		const { title } = blocks.get( name );
		if ( ! needs.canSetup ) {
			return (
				<Warning className="leitvia-studio-needs">
					{ sprintf(
						/* translators: %1$s: title of the building block, for example Features. */
						__(
							'This section needs the building block %1$s. Ask an administrator to set up %1$s.',
							'leitvia-studio'
						),
						title
					) }
				</Warning>
			);
		}
		const setUp = async () => {
			setBusy( true );
			setStatus(
				/* translators: %s: title of the building block. */
				sprintf( __( 'Setting up %s.', 'leitvia-studio' ), title )
			);
			try {
				const answer = await apiFetch( {
					path: RUN,
					method: 'POST',
					data: { input: { name, samples: true, settle: true } },
				} );
				const rejected = answer.steps.find(
					( step ) => 'rejected' === step.status
				);
				if ( rejected ) {
					throw new Error( rejected.message ?? '' );
				}
				// A package applied in part: the first error says why, the rest waits in the Inbox.
				const failed = answer.partial?.[ 0 ]?.errors?.[ 0 ];
				if ( failed ) {
					throw new Error( failed.message ?? '' );
				}
				const list = await apiFetch( { path: LIST } );
				if (
					! list.find( ( block ) => name === block.name )?.installed
				) {
					setBusy( false );
					setStatus(
						sprintf(
							/* translators: %s: title of the building block. */
							__(
								'The set-up of %s waits for approval in the Inbox.',
								'leitvia-studio'
							),
							title
						)
					);
					return;
				}
				const entries = answer.steps.filter(
					( step ) =>
						'blockEntries' === step.step &&
						[ 'live', 'draft' ].includes( step.status )
				).length;
				refresh();
				markInstalled( name );
				speak(
					sprintf(
						/* translators: 1: title of the building block, 2: number of sample entries. */
						_n(
							'%1$s is set up: %2$d sample entry as draft.',
							'%1$s is set up: %2$d sample entries as drafts.',
							entries,
							'leitvia-studio'
						),
						title,
						entries
					)
				);
				// The button is gone; focus goes back to the section, in the canvas frame when there is one.
				dispatch( blockEditorStore ).selectBlock( clientId, null );
				window.requestAnimationFrame( () => {
					const canvas =
						document.querySelector( 'iframe[name="editor-canvas"]' )
							?.contentDocument ?? document;
					canvas
						.querySelector( `[data-block="${ clientId }"]` )
						?.focus();
				} );
			} catch ( error ) {
				setBusy( false );
				setStatus(
					error?.message ||
						sprintf(
							/* translators: %s: title of the building block. */
							__( '%s could not be set up.', 'leitvia-studio' ),
							title
						)
				);
			}
		};
		return (
			<Warning
				className="leitvia-studio-needs"
				actions={ [
					<Button
						key="set-up"
						__next40pxDefaultSize
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ setUp }
					>
						{ sprintf(
							/* translators: %s: title of the building block. */
							__( 'Set up %s', 'leitvia-studio' ),
							title
						) }
					</Button>,
				] }
			>
				{ sprintf(
					/* translators: %s: title of the building block. */
					__(
						'This section needs the building block %s.',
						'leitvia-studio'
					),
					title
				) }
				<span className="leitvia-studio-needs__status" role="status">
					{ status }
				</span>
			</Warning>
		);
	}

	function WithNeeds( { BlockEdit, props, name, viaType } ) {
		const mount = useSyncExternalStore(
			subscribeMounts,
			() => mounts.get( name ) ?? 0
		);
		// A list inside a section of the same block leaves the warning to the section.
		const covered = useSelect(
			( store ) =>
				viaType &&
				store( blockEditorStore )
					.getBlockParents( props.clientId )
					.some(
						( parent ) =>
							name ===
							dependency(
								store( blockEditorStore ).getBlockAttributes(
									parent
								)
							)?.name
					),
			[ viaType, name, props.clientId ]
		);
		const missing = ! blocks.get( name ).installed && ! covered;
		return (
			<>
				{ missing && (
					<SetUp name={ name } clientId={ props.clientId } />
				) }
				<BlockEdit key={ mount } { ...props } />
			</>
		);
	}

	addFilter(
		'editor.BlockEdit',
		'leitvia-studio/building-blocks',
		createHigherOrderComponent(
			( BlockEdit ) => ( props ) => {
				const found = dependency( props.attributes );
				return found ? (
					<WithNeeds
						BlockEdit={ BlockEdit }
						props={ props }
						name={ found.name }
						viaType={ found.viaType }
					/>
				) : (
					<BlockEdit { ...props } />
				);
			},
			'withLeitviaStudioNeeds'
		)
	);

	// "Insert in the editor" from the list of a type: the inserter opens on the patterns of its block once the post
	// is loaded.
	const category = new URLSearchParams( window.location.search ).get(
		'leitvia-inserter'
	);
	const block = needs.blocks.find( ( item ) => category === item.category );
	if ( block ) {
		const unsubscribe = subscribe( () => {
			if ( ! select( editorStore ).getCurrentPostId() ) {
				return;
			}
			unsubscribe();
			dispatch( editorStore ).setIsInserterOpened( {
				tab: 'patterns',
				category: { name: block.category, label: block.title },
			} );
		} );
	}
}
