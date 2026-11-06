import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { buttonText, successMessage, recipient, subject } = attributes;
	const { privacyNote, privacyText } = attributes;
	const set = ( name ) => ( value ) => setAttributes( { [ name ]: value } );
	const empty = __( 'Empty: the translated default.', 'leitvia-studio' );
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Delivery', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						type="email"
						label={ __( 'Recipient', 'leitvia-studio' ) }
						help={ __(
							'Never shown on the page. Empty: the administration email address of this site.',
							'leitvia-studio'
						) }
						value={ recipient }
						onChange={ set( 'recipient' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Subject', 'leitvia-studio' ) }
						help={ empty }
						value={ subject }
						onChange={ set( 'subject' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Texts', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Button text', 'leitvia-studio' ) }
						help={ empty }
						value={ buttonText }
						onChange={ set( 'buttonText' ) }
					/>
					<TextareaControl
						label={ __( 'Success message', 'leitvia-studio' ) }
						help={ empty }
						value={ successMessage }
						onChange={ set( 'successMessage' ) }
					/>
					<ToggleControl
						label={ __( 'Privacy note', 'leitvia-studio' ) }
						help={ __(
							'With a link to the privacy policy page, if the site has one.',
							'leitvia-studio'
						) }
						checked={ privacyNote }
						onChange={ set( 'privacyNote' ) }
					/>
					{ privacyNote && (
						<TextareaControl
							label={ __(
								'Text of the privacy note',
								'leitvia-studio'
							) }
							help={ empty }
							value={ privacyText }
							onChange={ set( 'privacyText' ) }
						/>
					) }
				</PanelBody>
			</InspectorControls>
			{ /* POST keeps the recipient out of URLs and server logs. */ }
			<Disabled>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
					httpMethod="POST"
				/>
			</Disabled>
		</div>
	);
}
