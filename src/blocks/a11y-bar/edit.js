import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

const TEMPLATE = [ [ 'leitvia/language-switch' ] ];
const LINKS = {
	statementText: 'wp-block-leitvia-a11y-bar__statement',
	signLanguageText: 'wp-block-leitvia-a11y-bar__sign-language',
};

export default function Edit( { attributes, setAttributes } ) {
	const { label, statementUrl, signLanguageUrl } = attributes;
	useEffect( () => {
		const defaults = {
			label: __( 'Accessibility', 'leitvia-studio' ),
			statementText: __( 'Accessibility statement', 'leitvia-studio' ),
			signLanguageText: __( 'Sign language', 'leitvia-studio' ),
		};
		const missing = Object.fromEntries(
			Object.entries( defaults ).filter(
				( [ key ] ) => undefined === attributes[ key ]
			)
		);
		if ( Object.keys( missing ).length ) {
			setAttributes( missing );
		}
	} );
	const { children, ...innerBlocksProps } = useInnerBlocksProps(
		useBlockProps( { 'aria-label': label || undefined } ),
		{
			template: TEMPLATE,
			templateLock: 'all',
			allowedBlocks: [ 'leitvia/language-switch' ],
		}
	);
	const link = ( key, url ) =>
		url && (
			<RichText
				tagName="span"
				className={ LINKS[ key ] }
				aria-label={ __( 'Link text', 'leitvia-studio' ) }
				value={ attributes[ key ] }
				onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
				allowedFormats={ [] }
				withoutInteractiveFormatting
			/>
		);
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Links', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						type="url"
						label={ __(
							'Address of the accessibility statement',
							'leitvia-studio'
						) }
						help={ __(
							'For example the page made from the pattern "Accessibility statement". Without an address the link is left out.',
							'leitvia-studio'
						) }
						value={ statementUrl ?? '' }
						onChange={ ( value ) =>
							setAttributes( { statementUrl: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="url"
						label={ __(
							'Address of the sign language page (optional)',
							'leitvia-studio'
						) }
						value={ signLanguageUrl ?? '' }
						onChange={ ( value ) =>
							setAttributes( { signLanguageUrl: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Navigation name', 'leitvia-studio' ) }
						help={ __(
							'Screen readers announce the bar as a navigation with this name.',
							'leitvia-studio'
						) }
						value={ label ?? '' }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<nav { ...innerBlocksProps }>
				{ children }
				{ link( 'statementText', statementUrl ) }
				{ link( 'signLanguageText', signLanguageUrl ) }
			</nav>
		</>
	);
}
