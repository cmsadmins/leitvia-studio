import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Dynamic: the links come from the address and title of the post when it renders (Share::render(),
// Blocks::render_callbacks()), nothing is saved.
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
