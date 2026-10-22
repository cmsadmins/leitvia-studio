import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { useDispatch, useRegistry } from '@wordpress/data';
import metadata from './block.json';
import './style.scss';

const TEMPLATE = [ [ 'core/heading', { level: 3 } ], [ 'core/paragraph' ] ];

const sizes = () => [
	{ value: '1x1', label: __( 'Small (1 × 1)', 'leitvia-studio' ) },
	{ value: '2x1', label: __( 'Wide (2 × 1)', 'leitvia-studio' ) },
	{ value: '1x2', label: __( 'Tall (1 × 2)', 'leitvia-studio' ) },
	{ value: '2x2', label: __( 'Large (2 × 2)', 'leitvia-studio' ) },
];

const className = ( size ) => `is-size-${ size }`;

function Edit( {
	attributes: { size, mobilePriority },
	setAttributes,
	clientId,
} ) {
	const registry = useRegistry();
	const { moveBlockToPosition } = useDispatch( blockEditorStore );
	// Mobile shows the tiles in the editor order, like every other screen. A tile that should come first on mobile
	// moves behind the other prioritised tiles at the start of the grid; the grid never reorders with CSS.
	const prioritise = ( value ) => {
		setAttributes( { mobilePriority: value } );
		if ( ! value ) {
			return;
		}
		const { getBlockRootClientId, getBlocks } =
			registry.select( blockEditorStore );
		const root = getBlockRootClientId( clientId );
		const siblings = getBlocks( root ).filter(
			( block ) => block.clientId !== clientId
		);
		const index = siblings.findIndex(
			( block ) => ! block.attributes.mobilePriority
		);
		moveBlockToPosition(
			clientId,
			root,
			root,
			-1 === index ? siblings.length : index
		);
	};
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Tile', 'leitvia-studio' ) }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Size', 'leitvia-studio' ) }
						value={ size }
						options={ sizes() }
						onChange={ ( value ) =>
							setAttributes( { size: value } )
						}
						help={ __(
							'Columns × rows. Below 600 px every tile takes the full width.',
							'leitvia-studio'
						) }
					/>
					<ToggleControl
						label={ __( 'Show first on mobile', 'leitvia-studio' ) }
						checked={ mobilePriority }
						onChange={ prioritise }
						help={ __(
							'The tiles keep one order on every screen, so keyboard and screen reader users meet them as they are seen. Switching this on moves the tile to the start of the grid.',
							'leitvia-studio'
						) }
					/>
				</PanelBody>
			</InspectorControls>
			<div
				{ ...useInnerBlocksProps(
					useBlockProps( { className: className( size ) } ),
					{ template: TEMPLATE }
				) }
			/>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: ( { attributes: { size } } ) => (
		<div
			{ ...useInnerBlocksProps.save(
				useBlockProps.save( { className: className( size ) } )
			) }
		/>
	),
} );
