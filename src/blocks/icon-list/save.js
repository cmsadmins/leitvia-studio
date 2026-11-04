import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

// role="list": Safari drops the list semantics of a list without bullets (list-style: none) otherwise.
export default function save() {
	return (
		<ul
			{ ...useInnerBlocksProps.save(
				useBlockProps.save( { role: 'list' } )
			) }
		/>
	);
}
