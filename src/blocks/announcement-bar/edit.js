import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

export default function Edit( { attributes: { text, label }, setAttributes } ) {
	useEffect( () => {
		if ( undefined === label ) {
			setAttributes( { label: __( 'Announcement', 'leitvia-studio' ) } );
		}
	} );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Accessibility', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Region name', 'leitvia-studio' ) }
						help={ __(
							'Screen readers announce the bar as a region with this name.',
							'leitvia-studio'
						) }
						value={ label ?? '' }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<section { ...useBlockProps() } aria-label={ label || undefined }>
				<RichText
					tagName="p"
					className="wp-block-leitvia-announcement-bar__text"
					aria-label={ __( 'Announcement text', 'leitvia-studio' ) }
					placeholder={ __(
						'Announcement with a link, for example: New plans from 1 November. See what changes.',
						'leitvia-studio'
					) }
					value={ text }
					onChange={ ( value ) => setAttributes( { text: value } ) }
					allowedFormats={ [
						'core/bold',
						'core/italic',
						'core/link',
					] }
				/>
				{ /* Preview of the dismiss button that Studio adds on the front end (class-announcement-bar.php). */ }
				<span
					className="wp-block-leitvia-announcement-bar__dismiss"
					aria-hidden="true"
				>
					<svg viewBox="0 0 12 12" focusable="false">
						<path d="M2 2l8 8M10 2l-8 8" />
					</svg>
				</span>
			</section>
		</>
	);
}
