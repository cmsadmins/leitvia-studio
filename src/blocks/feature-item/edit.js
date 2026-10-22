import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

export default function Edit( {
	attributes: { title, text, plan },
	setAttributes,
} ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Plan', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Plan badge', 'leitvia-studio' ) }
						help={ __(
							'The plan that includes this feature, for example Pro. Features with the same badge are filtered together.',
							'leitvia-studio'
						) }
						value={ plan }
						onChange={ ( value ) =>
							setAttributes( { plan: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<li { ...useBlockProps() }>
				<RichText
					tagName="h3"
					className="wp-block-leitvia-feature-item__title"
					aria-label={ __( 'Feature title', 'leitvia-studio' ) }
					placeholder={ __( 'Feature', 'leitvia-studio' ) }
					value={ title }
					onChange={ ( value ) => setAttributes( { title: value } ) }
					allowedFormats={ [] }
				/>
				<RichText
					tagName="p"
					className="wp-block-leitvia-feature-item__text"
					aria-label={ __( 'Feature text', 'leitvia-studio' ) }
					placeholder={ __( 'What it does', 'leitvia-studio' ) }
					value={ text }
					onChange={ ( value ) => setAttributes( { text: value } ) }
				/>
				{ plan && (
					<p className="wp-block-leitvia-feature-item__badge">
						{ plan }
					</p>
				) }
			</li>
		</>
	);
}
