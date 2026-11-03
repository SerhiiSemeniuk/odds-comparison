import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.css';
import './editor.css';

// Dynamic block: the markup is rendered by PHP, so nothing is saved.
registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
