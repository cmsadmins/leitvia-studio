import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Dynamic block: the server renders link, toggle and panel (includes/blocks/class-mega-menu.php).
registerBlockType( metadata.name, { edit: Edit } );
