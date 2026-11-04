import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './editor.scss';
import './style.scss';

// Dynamic: the SVG comes from the bundled set when the page renders (Icon::render(), Blocks::render_callbacks()).
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
