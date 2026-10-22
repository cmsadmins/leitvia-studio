import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Dynamic block: the server renders it with live prices (includes/blocks/class-product-compare.php).
registerBlockType( metadata.name, { edit: Edit } );
