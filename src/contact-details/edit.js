/**
 * voyager-open/contact-details: editor UI.
 *
 * Each part is a text field in the block settings, and the canvas previews
 * the block the way render.php prints it.
 *
 * When a part is bound, core hands this component the source's value in its
 * attribute, as it hands the facts block a bound list. With a value, the
 * field shows it read-only, since a change typed here would not reach the
 * source. With none, the block falls back to its own value, here and on the
 * front end, so the field edits that. Own values are read from the block
 * editor store, because core passes `undefined` for a binding with no value.
 */
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

const CLASS_NAME = 'wp-block-voyager-open-contact-details';

/**
 * The parts, in the order the sidebar lists them, each with its help line.
 */
const PARTS = [
	{
		name: 'address',
		label: __( 'Street address', 'voyager-open-blocks' ),
		help: __(
			'The first line of the address, such as 100 Main Street.',
			'voyager-open-blocks'
		),
	},
	{
		name: 'addressLocality',
		label: __( 'City, state, postal code', 'voyager-open-blocks' ),
		help: __(
			'The second line of the address, such as Springfield, ST 00000.',
			'voyager-open-blocks'
		),
	},
	{
		name: 'phone',
		label: __( 'Phone number', 'voyager-open-blocks' ),
		help: __( 'Shown as typed.', 'voyager-open-blocks' ),
	},
	{
		name: 'phoneLink',
		label: __( 'Phone link', 'voyager-open-blocks' ),
		help: __(
			'What the number dials when tapped, such as tel:5550100100. With no link, the number shows as text.',
			'voyager-open-blocks'
		),
	},
	{
		name: 'email',
		label: __( 'Email address', 'voyager-open-blocks' ),
		help: __( 'Shown as typed.', 'voyager-open-blocks' ),
	},
	{
		name: 'emailLink',
		label: __( 'Email link', 'voyager-open-blocks' ),
		help: __(
			'What the address opens when tapped, such as mailto:hello@example.com. With no link, the address shows as text.',
			'voyager-open-blocks'
		),
	},
	{
		name: 'hours',
		label: __( 'Business hours', 'voyager-open-blocks' ),
		help: __( 'Shown on a line after the address.', 'voyager-open-blocks' ),
	},
];

/**
 * The schemes esc_url() keeps on the front end: wp_allowed_protocols().
 */
const PROTOCOLS = [
	'http',
	'https',
	'ftp',
	'ftps',
	'mailto',
	'news',
	'irc',
	'irc6',
	'ircs',
	'gopher',
	'nntp',
	'feed',
	'telnet',
	'mms',
	'rtsp',
	'sms',
	'svn',
	'tel',
	'fax',
	'xmpp',
	'webcal',
	'urn',
];

/**
 * A value as render.php reads it: decoded once and trimmed.
 *
 * @param {string|undefined} value An attribute.
 * @return {string} The text.
 */
export function partText( value ) {
	return decodeEntities( String( value ?? '' ) ).trim();
}

/**
 * A link as the preview shows it: '' when its scheme is one esc_url() drops.
 *
 * @param {string|undefined} value A link attribute.
 * @return {string} The href, or ''.
 */
export function partHref( value ) {
	const href = partText( value );
	const scheme = href.match( /^([a-z][a-z0-9+.-]*):/i );
	return ! scheme || PROTOCOLS.includes( scheme[ 1 ].toLowerCase() )
		? href
		: '';
}

/**
 * One line of the preview: a link when it has an href, else text, or nothing.
 *
 * @param {Object} props      Props.
 * @param {string} props.part The part's name, for its class.
 * @param {string} props.text The text.
 * @param {string} props.href The link, '' for none.
 * @return {Element|null} The line.
 */
function Line( { part, text, href = '' } ) {
	if ( text === '' ) {
		return null;
	}
	const className = `${ CLASS_NAME }__${ part }`;
	if ( href === '' ) {
		return <span className={ className }>{ text }</span>;
	}
	return (
		<a
			className={ `${ className } ${ CLASS_NAME }__link` }
			href={ href }
			onClick={ ( event ) => event.preventDefault() }
		>
			{ text }
		</a>
	);
}

export default function Edit( { attributes, setAttributes, clientId } ) {
	const own = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockAttributes( clientId ) ?? {},
		[ clientId ]
	);
	const bindings = attributes?.metadata?.bindings ?? {};

	const fields = PARTS.map( ( part ) => {
		const isBound = !! bindings[ part.name ];
		const value = attributes[ part.name ];
		const fromBinding =
			isBound && typeof value === 'string' && value.trim() !== '';
		let help = part.help;
		if ( fromBinding ) {
			help = __(
				'Shows a connected data field. Change it where that field is set.',
				'voyager-open-blocks'
			);
		} else if ( isBound ) {
			help = __(
				'The connected data field is empty, so the block shows this value, here and to visitors.',
				'voyager-open-blocks'
			);
		}
		return {
			...part,
			help,
			fromBinding,
			shown: fromBinding ? value : String( own[ part.name ] ?? '' ),
		};
	} );
	const shown = Object.fromEntries(
		fields.map( ( field ) => [ field.name, partText( field.shown ) ] )
	);
	const lines = [
		[ 'street', shown.address, '' ],
		[ 'locality', shown.addressLocality, '' ],
		[ 'phone', shown.phone, partHref( shown.phoneLink ) ],
		[ 'email', shown.email, partHref( shown.emailLink ) ],
	].filter( ( [ , text ] ) => text !== '' );
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls group="content">
				<PanelBody
					title={ __( 'Contact details', 'voyager-open-blocks' ) }
				>
					{ fields.map( ( field ) => (
						<TextControl
							key={ field.name }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ field.label }
							help={ field.help }
							value={ field.shown }
							readOnly={ field.fromBinding }
							onChange={ ( value ) =>
								setAttributes( { [ field.name ]: value } )
							}
						/>
					) ) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ lines.length > 0 && (
					<address className={ `${ CLASS_NAME }__address` }>
						{ lines.map( ( [ part, text, href ] ) => (
							<Line
								key={ part }
								part={ part }
								text={ text }
								href={ href }
							/>
						) ) }
					</address>
				) }
				{ shown.hours !== '' && (
					<p className={ `${ CLASS_NAME }__hours` }>
						{ shown.hours }
					</p>
				) }
				{ lines.length === 0 && shown.hours === '' && (
					<p>
						{ __(
							'Add the address, phone, email and hours under Contact details in the block settings, or connect each to Site Data.',
							'voyager-open-blocks'
						) }
					</p>
				) }
			</div>
		</>
	);
}
