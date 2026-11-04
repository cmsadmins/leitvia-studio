import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import save from './save';
import './style.scss';

// Static markup; the FAQ structured data and the shared details name come from the server (Faq::render()).
registerBlockType( metadata.name, { edit: Edit, save } );
