import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

const TEMPLATE = [ [ 'leitvia/wizard-step' ], [ 'leitvia/wizard-step' ] ];

export default function Edit( {
	attributes: { action, submit },
	setAttributes,
} ) {
	const { children, ...props } = useInnerBlocksProps( useBlockProps(), {
		template: TEMPLATE,
	} );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Wizard', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Target address', 'leitvia-studio' ) }
						help={ __(
							'The page that receives the answers as URL parameters, for example a page with a query filter. Empty sends them to this page.',
							'leitvia-studio'
						) }
						value={ action }
						onChange={ ( value ) =>
							setAttributes( { action: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...props }>
				{ children }
				<p className="wp-block-leitvia-wizard__submit">
					<RichText
						tagName="span"
						className="wp-element-button"
						aria-label={ __(
							'Submit button text',
							'leitvia-studio'
						) }
						allowedFormats={ [] }
						withoutInteractiveFormatting
						value={ submit }
						onChange={ ( value ) =>
							setAttributes( { submit: value } )
						}
					/>
				</p>
			</div>
		</>
	);
}
