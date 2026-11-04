import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import itemMetadata from '../icon-list-item/block.json';
import Edit from './edit';
import save from './save';
import ItemEdit from '../icon-list-item/edit';
import itemSave from '../icon-list-item/save';
import './style.scss';

// The items register from this script too (icon-list-item/block.json has no editorScript), so the icon set ships in
// one editor bundle less. The server puts the icon in front of each saved item (Icon::render_item()).
registerBlockType( metadata.name, { edit: Edit, save } );
registerBlockType( itemMetadata.name, { edit: ItemEdit, save: itemSave } );
