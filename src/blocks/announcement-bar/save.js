import { RichText, useBlockProps } from '@wordpress/block-editor';

// Studio adds the dismiss button and the hash of this markup when it renders the block (class-announcement-bar.php).
export default function save( { attributes: { text, label } } ) {
	return (
		<section { ...useBlockProps.save() } aria-label={ label || undefined }>
			<RichText.Content
				tagName="p"
				className="wp-block-leitvia-announcement-bar__text"
				value={ text }
			/>
		</section>
	);
}
