import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

// The empty summary stays hidden until view.js finds errors; Studio adds the translated headings when it renders the
// block (class-form-errors.php). The inner blocks are saved as they are, so their forms work without Studio.
export default function save() {
	const { children, ...props } = useInnerBlocksProps.save(
		useBlockProps.save()
	);
	return (
		<div { ...props }>
			<div
				className="wp-block-leitvia-form-errors__summary"
				role="region"
				tabIndex="-1"
				hidden
			>
				<h2 className="wp-block-leitvia-form-errors__title"></h2>
				<ul className="wp-block-leitvia-form-errors__list"></ul>
			</div>
			{ children }
		</div>
	);
}
