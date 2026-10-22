import { InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';

// Links only. Studio adds text size, high contrast and read aloud when it renders the bar (class-easy-language.php).
export default function save( {
	attributes: {
		label,
		statementUrl,
		statementText,
		signLanguageUrl,
		signLanguageText,
	},
} ) {
	return (
		<nav { ...useBlockProps.save() } aria-label={ label || undefined }>
			<InnerBlocks.Content />
			{ statementUrl && (
				<RichText.Content
					tagName="a"
					className="wp-block-leitvia-a11y-bar__statement"
					href={ statementUrl }
					value={ statementText }
				/>
			) }
			{ signLanguageUrl && (
				<RichText.Content
					tagName="a"
					className="wp-block-leitvia-a11y-bar__sign-language"
					href={ signLanguageUrl }
					value={ signLanguageText }
				/>
			) }
		</nav>
	);
}
