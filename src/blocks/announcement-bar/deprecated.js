import { RichText, useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';

// Version 0.1.0 saved the bar as an aside without a default alignment. It migrates to the full-width section.
const v1 = {
	attributes: {
		text: metadata.attributes.text,
		label: { ...metadata.attributes.label, selector: 'aside' },
		align: { type: 'string' },
	},
	supports: metadata.supports,
	migrate: ( attributes ) => ( {
		...attributes,
		align: attributes.align ?? 'full',
	} ),
	save( { attributes: { text, label } } ) {
		return (
			<aside
				{ ...useBlockProps.save() }
				aria-label={ label || undefined }
			>
				<RichText.Content
					tagName="p"
					className="wp-block-leitvia-announcement-bar__text"
					value={ text }
				/>
			</aside>
		);
	},
};

export default [ v1 ];
