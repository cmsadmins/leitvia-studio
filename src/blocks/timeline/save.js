import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

export default function save() {
	return <ol { ...useInnerBlocksProps.save( useBlockProps.save() ) } />;
}
