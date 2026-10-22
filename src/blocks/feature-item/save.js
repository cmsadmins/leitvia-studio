import { RichText, useBlockProps } from '@wordpress/block-editor';

export default function save( {
	attributes: { title, text, plan, planIndex },
} ) {
	return (
		<li
			{ ...useBlockProps.save() }
			data-plan={ plan && planIndex ? planIndex : undefined }
		>
			<RichText.Content
				tagName="h3"
				className="wp-block-leitvia-feature-item__title"
				value={ title }
			/>
			<RichText.Content
				tagName="p"
				className="wp-block-leitvia-feature-item__text"
				value={ text }
			/>
			{ plan && (
				<p className="wp-block-leitvia-feature-item__badge">{ plan }</p>
			) }
		</li>
	);
}
