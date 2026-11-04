import { RichText, useBlockProps } from '@wordpress/block-editor';

// The icon is not saved: the server puts it in front of the text (Icon::render_item()).
export default function save( { attributes: { text } } ) {
	return (
		<li { ...useBlockProps.save() }>
			<RichText.Content
				tagName="span"
				className="wp-block-leitvia-icon-list-item__text"
				value={ text }
			/>
		</li>
	);
}
