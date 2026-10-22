import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import metadata from './block.json';
import './style.scss';

const TEMPLATE = [
	[ 'leitvia/bento-item', { size: '2x2' } ],
	[ 'leitvia/bento-item', { size: '2x1' } ],
	[ 'leitvia/bento-item' ],
	[ 'leitvia/bento-item' ],
];

registerBlockType( metadata.name, {
	edit: function Edit() {
		return (
			<div
				{ ...useInnerBlocksProps( useBlockProps(), {
					template: TEMPLATE,
				} ) }
			/>
		);
	},
	save: () => <div { ...useInnerBlocksProps.save( useBlockProps.save() ) } />,
} );
