import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

export default function Edit( {
	attributes: { prefix, value, suffix, label },
	setAttributes,
} ) {
	const field = ( key, text, help ) => (
		<TextControl
			__next40pxDefaultSize
			label={ text }
			help={ help }
			value={ { prefix, value, suffix }[ key ] }
			onChange={ ( next ) => setAttributes( { [ key ]: next } ) }
		/>
	);
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Figure', 'leitvia-studio' ) }>
					{ field(
						'value',
						__( 'End value', 'leitvia-studio' ),
						__(
							'Digits with the separators of the site language, for example 1,250 or 98.5.',
							'leitvia-studio'
						)
					) }
					{ field( 'prefix', __( 'Prefix', 'leitvia-studio' ) ) }
					{ field( 'suffix', __( 'Suffix', 'leitvia-studio' ) ) }
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<p className="wp-block-leitvia-counter__number">
					{ prefix }
					<span className="wp-block-leitvia-counter__value">
						{ value || '0' }
					</span>
					{ suffix }
				</p>
				<RichText
					tagName="p"
					className="wp-block-leitvia-counter__label"
					aria-label={ __( 'Label', 'leitvia-studio' ) }
					placeholder={ __(
						'What the figure counts',
						'leitvia-studio'
					) }
					value={ label }
					onChange={ ( next ) => setAttributes( { label: next } ) }
				/>
			</div>
		</>
	);
}
