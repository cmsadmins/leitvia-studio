import { RichText, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<RichText
				tagName="p"
				className="wp-block-leitvia-consent-settings__text"
				value={ attributes.text }
				onChange={ ( text ) => setAttributes( { text } ) }
				placeholder={ __(
					'Explain that content from other providers loads only after consent, and how to revoke it.',
					'leitvia-studio'
				) }
			/>
			<p>
				<em>
					{ __(
						'Visitors see the providers they always allow here, each with a Revoke button.',
						'leitvia-studio'
					) }
				</em>
			</p>
		</div>
	);
}
