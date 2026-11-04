import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl } from '@wordpress/components';
import { IconPicker, Svg } from './picker';

export default function Edit( {
	attributes: { icon, label, url },
	setAttributes,
} ) {
	// Icon::render() drops a link without a label, so it never renders a link without an accessible name.
	const unnamedLink = url.trim() && ! label.trim();
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Icon', 'leitvia-studio' ) }>
					<IconPicker
						label={ __( 'Icon', 'leitvia-studio' ) }
						value={ icon }
						onChange={ ( next ) => setAttributes( { icon: next } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Label and link', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Label', 'leitvia-studio' ) }
						help={ __(
							'What the icon means, read by screen readers. Leave it empty when the text next to the icon says the same; the icon is then hidden from them.',
							'leitvia-studio'
						) }
						value={ label }
						onChange={ ( next ) =>
							setAttributes( { label: next } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="url"
						label={ __( 'Link', 'leitvia-studio' ) }
						value={ url }
						onChange={ ( next ) => setAttributes( { url: next } ) }
					/>
					{ unnamedLink && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'A linked icon needs a label as the name of the link. Without a label the icon is shown without the link.',
								'leitvia-studio'
							) }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Svg name={ icon } />
			</div>
		</>
	);
}
