import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';

const TEMPLATE = [ [ 'core/details' ], [ 'core/details' ], [ 'core/details' ] ];

export default function Edit( {
	attributes: { schema, exclusive },
	setAttributes,
} ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Questions', 'leitvia-studio' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'One answer open at a time',
							'leitvia-studio'
						) }
						help={ __(
							'Opening a question closes the others. Browsers without support keep all of them open.',
							'leitvia-studio'
						) }
						checked={ exclusive }
						onChange={ ( next ) =>
							setAttributes( { exclusive: next } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'FAQ structured data', 'leitvia-studio' ) }
						help={ __(
							'Describes the questions and answers for search engines. Left out while an SEO plugin is active.',
							'leitvia-studio'
						) }
						checked={ schema }
						onChange={ ( next ) =>
							setAttributes( { schema: next } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div
				{ ...useInnerBlocksProps( useBlockProps(), {
					template: TEMPLATE,
				} ) }
			/>
		</>
	);
}
