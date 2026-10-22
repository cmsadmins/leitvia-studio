import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { RichText, useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';

function Edit( { attributes: { text }, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<RichText
				tagName="span"
				className="wp-element-button"
				aria-label={ __( 'Button text', 'leitvia-studio' ) }
				placeholder={ __( 'Load more', 'leitvia-studio' ) }
				allowedFormats={ [] }
				withoutInteractiveFormatting
				value={ text }
				onChange={ ( value ) => setAttributes( { text: value } ) }
			/>
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit } );
