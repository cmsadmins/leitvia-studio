import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import metadata from './block.json';
import './style.scss';

const effects = () => [
	{ value: 'fade', label: __( 'Fade in', 'leitvia-studio' ) },
	{ value: 'rise', label: __( 'Rise', 'leitvia-studio' ) },
	{ value: 'stagger', label: __( 'One after another', 'leitvia-studio' ) },
];

const className = ( effect ) => `is-effect-${ effect }`;

registerBlockType( metadata.name, {
	edit: function Edit( { attributes: { effect }, setAttributes } ) {
		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Effect', 'leitvia-studio' ) }>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Effect', 'leitvia-studio' ) }
							value={ effect }
							options={ effects() }
							onChange={ ( value ) =>
								setAttributes( { effect: value } )
							}
							help={ __(
								'Plays only while the page scrolls, never with reduced motion. The editor shows the content without the effect.',
								'leitvia-studio'
							) }
						/>
					</PanelBody>
				</InspectorControls>
				<div
					{ ...useInnerBlocksProps(
						useBlockProps( { className: className( effect ) } ),
						{ template: [ [ 'core/paragraph' ] ] }
					) }
				/>
			</>
		);
	},
	save: ( { attributes: { effect } } ) => (
		<div
			{ ...useInnerBlocksProps.save(
				useBlockProps.save( { className: className( effect ) } )
			) }
		/>
	),
} );
