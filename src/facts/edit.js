/**
 * voyager-open/facts: editor UI.
 *
 * The items are edited in a textarea in the block settings and previewed as a
 * list, split the way render.php splits them.
 *
 * When `items` is bound, core hands this component the source's value in
 * attributes.items, as it hands the icon block a bound link. With a value,
 * the textarea shows it read-only, since a change typed here would not reach
 * the source. With none, the block falls back to its own items, here and on
 * the front end, so the textarea edits those. They are read from the block
 * editor store, because core passes `undefined` for a binding with no value.
 */
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * The items render.php prints: one per line, each trimmed, empty lines dropped.
 *
 * @param {string|undefined} value The `items` attribute.
 * @return {string[]} The items.
 */
export function factItems( value ) {
	return String( value ?? '' )
		.split( /\r\n|\r|\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line !== '' );
}

export default function Edit( { attributes, setAttributes, clientId } ) {
	const isBound = !! attributes?.metadata?.bindings?.items;
	const ownItems = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockAttributes( clientId )?.items ??
			'',
		[ clientId ]
	);
	const fromBinding =
		isBound &&
		typeof attributes.items === 'string' &&
		attributes.items.trim() !== '';
	const shown = fromBinding ? attributes.items : ownItems;
	const items = factItems( shown );
	const blockProps = useBlockProps();

	let help = __(
		'One item per line. Blank lines are skipped.',
		'voyager-open-blocks'
	);
	if ( fromBinding ) {
		help = __(
			'The list shows a connected data field, one item per line. Change the items where that field is set.',
			'voyager-open-blocks'
		);
	} else if ( isBound ) {
		help = __(
			'The connected data field is empty, so the list shows these items, here and to visitors. One item per line.',
			'voyager-open-blocks'
		);
	}

	return (
		<>
			<InspectorControls group="content">
				<PanelBody title={ __( 'Items', 'voyager-open-blocks' ) }>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Items', 'voyager-open-blocks' ) }
						help={ help }
						value={ shown }
						rows={ 6 }
						readOnly={ fromBinding }
						onChange={ ( value ) =>
							setAttributes( { items: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ items.length > 0 ? (
				<ul { ...blockProps }>
					{ items.map( ( item, index ) => (
						<li key={ `${ index }-${ item }` }>
							{ decodeEntities( item ) }
						</li>
					) ) }
				</ul>
			) : (
				<div { ...blockProps }>
					<p>
						{ __(
							'Add one item per line under Items in the block settings.',
							'voyager-open-blocks'
						) }
					</p>
				</div>
			) }
		</>
	);
}
