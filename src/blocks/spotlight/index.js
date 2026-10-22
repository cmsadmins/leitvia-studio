import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import metadata from './block.json';
import './style.scss';

const TEMPLATE = [
	[ 'core/image' ],
	[ 'leitvia/spotlight-hotspot', { x: 30, y: 40 } ],
	[ 'leitvia/spotlight-hotspot', { x: 65, y: 60 } ],
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
	// The view module closes the other points when one opens and closes the open one on Escape.
	save: () => (
		<div
			{ ...useInnerBlocksProps.save(
				useBlockProps.save( {
					'data-wp-interactive': 'leitvia/spotlight',
					'data-wp-on--keydown': 'actions.keydown',
				} )
			) }
		/>
	),
} );
