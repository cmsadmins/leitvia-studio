import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

// A plain GET form, one fieldset per step and one submit button: complete without JavaScript and without Studio.
// The server adds Back, Next and the progress text and hides all steps but the first (includes/blocks/class-wizard.php).
export default function save( { attributes: { action, submit } } ) {
	const { children, ...props } = useInnerBlocksProps.save(
		useBlockProps.save( { method: 'get', action: action || undefined } )
	);
	return (
		<form { ...props }>
			{ children }
			<p className="wp-block-leitvia-wizard__submit">
				<RichText.Content
					tagName="button"
					type="submit"
					className="wp-element-button"
					value={ submit }
				/>
			</p>
		</form>
	);
}
