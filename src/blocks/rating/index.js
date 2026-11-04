import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Dynamic: the markup and the Review node come from Rating::render() (Blocks::render_callbacks()), nothing is saved.
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
