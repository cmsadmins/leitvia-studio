import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

// The summary only exists on the front end, so the editor shows the wrapped blocks alone.
export default function Edit() {
	return <div { ...useInnerBlocksProps( useBlockProps() ) } />;
}
