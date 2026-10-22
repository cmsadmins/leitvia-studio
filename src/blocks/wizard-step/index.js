import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import metadata from './block.json';
import save from './save';

const TEMPLATE = [ [ 'core/paragraph' ] ];

// Choices are edited as lines "Label" or "Label|value".
const toLines = ( options ) =>
	options
		.map( ( { label, value } ) =>
			value && value !== label ? `${ label }|${ value }` : label
		)
		.join( '\n' );
const fromLines = ( text ) =>
	text
		.split( '\n' )
		.map( ( line ) => line.split( '|' ) )
		.filter( ( [ label ] ) => label.trim() )
		.map( ( [ label, value = '' ] ) => ( {
			label: label.trim(),
			value: value.trim(),
		} ) );

function Edit( { attributes, setAttributes } ) {
	const { legend, name, type, required, options } = attributes;
	const { children, ...props } = useInnerBlocksProps( useBlockProps(), {
		template: TEMPLATE,
	} );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Choices', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Field name', 'leitvia-studio' ) }
						help={ __(
							'The URL parameter that carries the choice, for example qf-1-category for the query filter of the Query Loop with the ID 1.',
							'leitvia-studio'
						) }
						value={ name }
						onChange={ ( value ) =>
							setAttributes( { name: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Type', 'leitvia-studio' ) }
						value={ type }
						options={ [
							{
								label: __(
									'One choice (radio buttons)',
									'leitvia-studio'
								),
								value: 'radio',
							},
							{
								label: __(
									'Several choices (checkboxes)',
									'leitvia-studio'
								),
								value: 'checkbox',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { type: value } )
						}
					/>
					<TextareaControl
						label={ __( 'Choices', 'leitvia-studio' ) }
						help={ __(
							'One per line. Add |value to send a value other than the label, for example Accessibility|accessibility.',
							'leitvia-studio'
						) }
						value={ toLines( options ) }
						onChange={ ( text ) =>
							setAttributes( { options: fromLines( text ) } )
						}
					/>
					{ 'radio' === type && (
						<ToggleControl
							label={ __( 'Required', 'leitvia-studio' ) }
							checked={ required }
							onChange={ ( value ) =>
								setAttributes( { required: value } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<fieldset { ...props }>
				<RichText
					tagName="legend"
					placeholder={ __( 'Step title', 'leitvia-studio' ) }
					value={ legend }
					onChange={ ( value ) => setAttributes( { legend: value } ) }
				/>
				{ children }
				{ options.length > 0 && (
					<div className="wp-block-leitvia-wizard-step__options">
						{ options.map( ( option, index ) => (
							// eslint-disable-next-line jsx-a11y/label-has-associated-control
							<label key={ index }>
								<input type={ type } disabled />{ ' ' }
								{ option.label }
							</label>
						) ) }
					</div>
				) }
			</fieldset>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save } );
