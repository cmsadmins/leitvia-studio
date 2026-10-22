import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	useInnerBlocksProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import metadata from './block.json';
import './style.scss';

const TEMPLATE = [
	[
		'core/paragraph',
		{ placeholder: __( 'What is at this point?', 'leitvia-studio' ) },
	],
];

// Position in percent of the image, as custom properties for spotlight/style.scss.
const position = ( { x, y } ) => ( { '--x': `${ x }%`, '--y': `${ y }%` } );

function Edit( { attributes, setAttributes, isSelected, clientId } ) {
	const { x, y, label } = attributes;
	const open = useSelectOpen( isSelected, clientId );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Position', 'leitvia-studio' ) }>
					<RangeControl
						__next40pxDefaultSize
						label={ __(
							'From the start edge (%)',
							'leitvia-studio'
						) }
						value={ x }
						min={ 0 }
						max={ 100 }
						onChange={ ( value ) =>
							setAttributes( { x: value ?? 50 } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'From the top (%)', 'leitvia-studio' ) }
						value={ y }
						min={ 0 }
						max={ 100 }
						onChange={ ( value ) =>
							setAttributes( { y: value ?? 50 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div
				{ ...useBlockProps( {
					style: position( attributes ),
					className: open ? 'is-open' : undefined,
				} ) }
			>
				<div className="wp-block-leitvia-spotlight-hotspot__summary">
					<RichText
						tagName="span"
						className="wp-block-leitvia-spotlight-hotspot__label"
						aria-label={ __(
							'Name of the point',
							'leitvia-studio'
						) }
						placeholder={ __(
							'Name of the point',
							'leitvia-studio'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
						allowedFormats={ [] }
					/>
				</div>
				<div
					{ ...useInnerBlocksProps(
						{
							className:
								'wp-block-leitvia-spotlight-hotspot__content',
							hidden: ! open,
						},
						{ template: TEMPLATE }
					) }
				/>
			</div>
		</>
	);
}

// The editor shows the text of a point while the point or something in it is selected.
function useSelectOpen( isSelected, clientId ) {
	return (
		useSelect(
			( select ) =>
				select( blockEditorStore ).hasSelectedInnerBlock(
					clientId,
					true
				),
			[ clientId ]
		) || isSelected
	);
}

function save( { attributes } ) {
	return (
		<details
			{ ...useBlockProps.save( {
				style: position( attributes ),
				'data-wp-on--toggle': 'actions.toggle',
			} ) }
		>
			<summary className="wp-block-leitvia-spotlight-hotspot__summary">
				<RichText.Content
					tagName="span"
					className="wp-block-leitvia-spotlight-hotspot__label"
					value={ attributes.label }
				/>
			</summary>
			<div
				{ ...useInnerBlocksProps.save( {
					className: 'wp-block-leitvia-spotlight-hotspot__content',
				} ) }
			/>
		</details>
	);
}

registerBlockType( metadata.name, { edit: Edit, save } );
