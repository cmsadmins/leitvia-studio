import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Dynamic: the list comes from the headings of the post when it renders (Table_Of_Contents::render(), Blocks::render_callbacks()), nothing is saved.
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
