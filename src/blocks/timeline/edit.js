import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

const TEMPLATE = [ [ 'leitvia/timeline-item' ], [ 'leitvia/timeline-item' ] ];

export default function Edit() {
	return (
		<ol
			{ ...useInnerBlocksProps( useBlockProps(), {
				template: TEMPLATE,
			} ) }
		/>
	);
}
