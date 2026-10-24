/**
 * voyager-open/icon: editor registration.
 *
 * Dynamic block. save() returns null, so saved content is the comment
 * delimiter with attributes and render.php owns the front-end markup.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
