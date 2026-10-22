import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Dynamic: the link target comes from the meta _leitvia_easy_language_of when the page renders (render.php).
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
